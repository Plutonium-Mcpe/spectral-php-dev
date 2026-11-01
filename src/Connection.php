<?php

declare(strict_types=1);

namespace cooldogedev\spectral;

use cooldogedev\spectral\congestion\RTT;
use cooldogedev\spectral\congestion\Sender;
use cooldogedev\spectral\frame\Acknowledgement;
use cooldogedev\spectral\frame\ConnectionClose;
use cooldogedev\spectral\frame\Frame;
use cooldogedev\spectral\frame\MTURequest;
use cooldogedev\spectral\frame\MTUResponse;
use cooldogedev\spectral\frame\Pack;
use cooldogedev\spectral\frame\StreamClose;
use cooldogedev\spectral\frame\StreamData;
use cooldogedev\spectral\util\Address;
use cooldogedev\spectral\util\log\Logger;
use cooldogedev\spectral\util\Time;
use function floor;
use function strlen;

abstract class Connection
{
    private const CONNECTION_ACTIVITY_TIMEOUT = Time::SECOND * 30;

    /**
     * Plafond memoire d'une seule connexion, somme de ce qui attend d'etre emis et de ce qui
     * attend d'etre acquitte. Aucune des deux files n'etait bornee : un pair qui cesse de lire
     * ou d'acquitter les faisait croitre sans limite.
     *
     * Attention a l'echelle : une connexion ne porte pas un joueur mais un pair entier, les
     * sessions etant des flux multiplexes dessus. Un banc a 550 bots y a fait transiter 9 Mio
     * tout a fait legitimes pendant une rafale d'arrivees, et un premier essai a 8 Mio fermait
     * la connexion en pleine arrivee.
     *
     * Ce plafond ne protege PAS la memoire du thread, seulement les autres connexions contre
     * une voisine qui s'emballe : un banc a 550 bots sur trois proxies a tue le thread a 512
     * Mio sans qu'aucune des trois connexions n'atteigne jamais ses 128 Mio. La limite du
     * thread est tenue par le budget global du listener, voir Listener::setMemoryBudget().
     */
    private const MAX_BUFFERED_BYTES = 128 * 1024 * 1024;

    protected readonly AckQueue $ack;
    protected readonly Sender $sender;
    protected readonly ReceiveQueue $receiveQueue;
    protected readonly RetransmissionQueue $retransmission;
    protected readonly SendQueue $sendQueue;
    protected readonly StreamMap $streams;
    protected readonly RTT $rtt;
    protected readonly MTUDiscovery $discovery;

    public readonly Logger $logger;

    protected int $sequenceID = 0;
    protected int $idle = 0;

    protected bool $closed = false;

    /**
     * Raison de la fermeture, pour que le listener puisse la comptabiliser.
     *
     * Le journal de spectral est un NopLogger tant que SLOG_DIR n'est pas defini : personne
     * n'active cette variable en production, donc aucune fermeture n'y laissait de trace et
     * l'hote ne pouvait pas savoir si ses garde-fous servaient a quelque chose.
     */
    protected ?string $closeReason = null;

    public function __construct(protected readonly Conn $conn, public int $connectionID, int $perspective)
    {
        $now = Time::unixNano();
        $this->logger = Logger::create($perspective);
        $this->ack = new AckQueue();
        $this->sender = new Sender($this->logger, $now, Protocol::MIN_PACKET_SIZE);
        $this->receiveQueue = new ReceiveQueue();
        $this->retransmission = new RetransmissionQueue();
        $this->sendQueue = new SendQueue();
        $this->streams = new StreamMap();
        $this->rtt = new RTT();
        $this->discovery = new MTUDiscovery(function (int $mtu): void {
            $this->sender->setMSS($mtu);
            $this->sendQueue->setMSS($mtu);
            $this->logger->log("mtu_update", "new", $mtu);
        });
        $this->idle = $now + Connection::CONNECTION_ACTIVITY_TIMEOUT;
    }

    /**
     * Octets retenus par cette connexion : ce qui attend d'etre emis et ce qui attend d'etre
     * acquitte. Le listener en fait la somme sur toutes ses connexions, parce que c'est cette
     * somme, et non le chiffre d'une seule, qui epuise la memoire du thread.
     */
    public function getCloseReason(): ?string
    {
        return $this->closeReason;
    }

    public function getBufferedBytes(): int
    {
        return $this->sendQueue->getQueuedBytes() + $this->retransmission->getQueuedBytes();
    }

    public function getLocalAddress(): Address
    {
        return $this->conn->localAddress;
    }

    public function getRemoteAddress(): Address
    {
        return $this->conn->remoteAddress;
    }

    public function closeWithError(int $code, string $message): void
    {
        $this->closeReason = $message;
        $this->logger->log("connection_close_err", "code", $code, "message", $message);
        $this->writeControl(ConnectionClose::create($code, $message), true);
        $this->internalClose();
    }

    public function tick(): bool
    {
        if ($this->closed) {
            return false;
        }

        $now = Time::unixNano();
        if ($now >= $this->idle) {
            $this->closeWithError(ConnectionClose::CONNECTION_CLOSE_TIMEOUT, "network inactivity");
            return false;
        }
        $buffered = $this->getBufferedBytes();
        if ($buffered > Connection::MAX_BUFFERED_BYTES) {
            $this->closeWithError(ConnectionClose::CONNECTION_CLOSE_TIMEOUT, "buffer overflow: " . $buffered . " bytes");
            return false;
        }
        $this->retransmit($now);
        $this->maybeSend($now);
        return true;
    }

    /**
     * @internal
     * @param array<int, Frame> $frames
     */
    public function receive(int $sequenceID, array $frames): void
    {
        $now = Time::unixNano();
        if ($sequenceID !== 0) {
            $this->ack->add($now, $sequenceID);
            if (!$this->receiveQueue->add($sequenceID)) {
                $this->logger->log("duplicate_receive", "sequenceID", $sequenceID);
                return;
            }
        }

        foreach ($frames as $frame) {
            $this->handle($now, $frame);
        }
        $this->idle = $now + Connection::CONNECTION_ACTIVITY_TIMEOUT;
    }

    /**
     * @internal
     */
    public function handle(int $now, Frame $fr): void
    {
        if ($fr instanceof Acknowledgement) {
            foreach ($fr->ranges as [$start, $end]) {
                for ($i = $start; $i <= $end; $i++) {
                    $entry = $this->retransmission->remove($i);
                    if ($entry !== null) {
                        if ($i === $fr->max) {
                            $this->rtt->add($now - $entry->sent, Time::MICROSECOND * $fr->delay);
                        }
                        $this->sender->onAck($now, $entry->sent, $this->rtt, strlen($entry->payload) - Protocol::PACKET_HEADER_SIZE);
                    }
                }
            }
        }

        if ($fr instanceof StreamClose) {
            $this->logger->log("stream_close_request", "streamID", $fr->streamID);
            $this->streams->get($fr->streamID)?->internalClose();
        }

        match (true) {
            $fr instanceof ConnectionClose => $this->internalClose(),
            $fr instanceof StreamData => $this->streams->get($fr->streamID)?->receive($fr),
            $fr instanceof MTURequest => $this->writeControl(MTUResponse::create($fr->mtu)),
            $fr instanceof MTUResponse => $this->discovery->onAck($fr->mtu),
            default => null,
        };
    }

    private function maybeSend(int $now): void
    {
        if (!$this->discovery->discovered && $this->discovery->sendProbe($now, $this->rtt->getSRTT())) {
            $this->writeControl(MTURequest::create($this->discovery->current));
            $this->logger->log("mtu_probe", "new", $this->discovery->current);
        }

        while ($this->sendQueue->available()) {
            if (!$this->transmit($now)) {
                break;
            }
        }
        $this->acknowledge($now);
    }

    private function transmit(int $now): bool
    {
        $available = $this->sender->getAvailable();
        if ($available === 0) {
            $this->logger->log("congestion_block", "window", $available);
            return false;
        }

        $payload = $this->sendQueue->pack($available);
        $length = $payload !== null ? strlen($payload) : 0;
        if ($length === 0) {
            $this->logger->log("congestion_block", "len", $length, "window", $available);
            return false;
        }

        if ($this->sender->getTimeUntilSend($now, $this->rtt, $length) > $now) {
            $this->logger->log("pacer_block", "len", $length, "window", $available);
            return false;
        }
        $this->sequenceID++;
        $pk = Pack::pack($this->connectionID, $this->sequenceID, $payload);
        $this->conn->write($this->appendAcknowledgements($now, $pk));
        $this->sendQueue->flush();
        $this->sender->onSend($length);
        $this->retransmission->add($now, $this->sequenceID, $pk);
        return true;
    }

    private function appendAcknowledgements(int $now, string $p): string
    {
        $total = (int)floor(($this->sendQueue->getMSS() - strlen($p) - 16) / 8);
        $ack = $this->ack->flush($now, $total, true);
        if ($ack !== null) {
            [$ranges, $max, $delay] = $ack;
            return $p . Pack::packSingle(Acknowledgement::create($delay, $max, $ranges));
        }
        return $p;
    }

    private function acknowledge(int $now): void
    {
        while (($ack = $this->ack->flush($now, Protocol::MAX_ACK_RANGES, false)) !== null) {
            [$ranges, $max, $delay] = $ack;
            $this->writeControl(Acknowledgement::create($delay, $max, $ranges));
        }
    }

    private function retransmit(int $now): void
    {
        $entry = $this->retransmission->shift($now, $this->rtt->getRTO());
        if ($entry !== null) {
            [$sentTime, $payload, $gaveUp] = $entry;
            // Echouer bruyamment. Le paquet a epuise ses tentatives : le pair a
            // un trou definitif et ne pourra plus jamais avancer sa fenetre.
            // Fermer permet au proxy de redialer ; le silence d'avant laissait
            // la connexion dans le pool a servir des transferts condamnes.
            if ($gaveUp) {
                $this->closeWithError(ConnectionClose::CONNECTION_CLOSE_TIMEOUT, "retransmission exhausted");
                return;
            }
            $this->sender->onCongestionEvent($now, $sentTime);
            $this->conn->write($payload);
        }
    }

    /**
     * @internal
     */
    public function writeControl(Frame $fr, bool $needsAck = false): void
    {
        $payload = Pack::packSingle($fr);
        $sequenceID = 0;
        if ($needsAck) {
            $this->sequenceID++;
            $sequenceID = $this->sequenceID;
        }

        $pk = Pack::pack($this->connectionID, $sequenceID, $payload);
        if ($needsAck) {
            $this->retransmission->add(Time::unixNano(), $sequenceID, $pk);
        }
        $this->conn->write($pk);
    }

    protected function createStream(int $streamID): ?Stream
    {
        if ($this->streams->get($streamID) !== null) {
            $this->logger->log("duplicate_stream", "streamID", $streamID);
            return null;
        }
        $stream = new Stream($this->sendQueue, $this->logger, $streamID);
        $stream->registerCloseHandler(function () use ($streamID): void {
            // $this->sequenceID est le compteur de paquets de la connexion, pas
            // un identifiant de stream : remove() ne touchait donc jamais rien
            // et aucun stream ferme n'etait retire de la map.
            $this->streams->remove($streamID);
            $this->writeControl(StreamClose::create($streamID), true);
        });
        $this->streams->add($stream, $streamID);
        return $stream;
    }

    private function internalClose(): void
    {
        if ($this->closed) {
            return;
        }

        foreach ($this->streams->all() as $stream) {
            $stream->internalClose();
        }
        $this->discovery->mtuIncrease = null;
        $this->logger->log("connection_close");
        $this->logger->close();
        $this->closed = true;
        $this->ack->clear();
        $this->receiveQueue->clear();
        $this->retransmission->clear();
        $this->sendQueue->clear();
        $this->conn->close();
    }
}
