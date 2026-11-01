<?php

declare(strict_types=1);

namespace cooldogedev\spectral;

use Closure;
use cooldogedev\spectral\frame\ConnectionClose;
use cooldogedev\spectral\frame\Frame;
use cooldogedev\spectral\frame\FrameIds;
use cooldogedev\spectral\frame\Pack;
use cooldogedev\spectral\util\Address;
use cooldogedev\spectral\util\OS;
use cooldogedev\spectral\util\UDP;
use RuntimeException;
use Socket;
use function count;
use function str_starts_with;
use function socket_bind;
use function socket_create;
use function socket_getsockname;
use function socket_recvfrom;
use function socket_select;
use function spl_object_id;
use const AF_INET;
use const MSG_DONTWAIT;
use const SOCK_DGRAM;
use const SOL_UDP;

final class Listener
{
    /**
     * @var array<int, ServerConnection>
     */
    private array $connections = [];

    /**
     * Connexions indexees par adresse distante, pour reconnaitre une demande de connexion
     * rejouee.
     *
     * Un client part avec l'identifiant -1 et n'apprend le sien qu'en recevant la reponse.
     * Or writeControl() range dans la file de retransmission le paquet deja serialise : une
     * demande rejouee porte donc toujours -1, meme apres que le client a appris son
     * identifiant. Sans cet index, le serveur n'y reconnait aucune connexion connue et en
     * ouvre une seconde ; pire, le client adopte l'identifiant de la nouvelle reponse et
     * abandonne la premiere connexion avec les flux deja ouverts dessus.
     *
     * @var array<string, int>
     */
    private array $connectionsByAddress = [];

    /**
     * Index inverse du precedent, pour oublier une connexion sans avoir a l'interroger.
     *
     * @var array<int, string>
     */
    private array $addressByConnection = [];
    private int $connectionId = 0;

    /**
     * @var null|Closure(ServerConnection $stream): void
     */
    private ?Closure $connectionAcceptor = null;

    /**
     * @var array<int, Socket>
     */
    private array $sockets = [];
    /**
     * @var array<int, Closure>
     */
    private array $socketHandlers = [];

    private Address $localAddress;

    private bool $closed = false;

    /**
     * Comptes de fermetures par cause, publies pour l'hote. Un garde-fou dont on ne peut pas
     * verifier qu'il s'est declenche ne vaut pas mieux qu'une absence de garde-fou.
     */
    private int $overflowClosures = 0;
    private int $timeoutClosures = 0;

    private function __construct(private readonly string $address, private readonly int $port)
    {
        $this->registerSockets();
    }

    private function registerSockets(): void
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        UDP::optimizeSocket($socket);
        if (!@socket_bind($socket, $this->address, $this->port)) {
            @socket_close($socket);
            throw new RuntimeException("failed to bind socket");
        }
        socket_getsockname($socket, $localAddress, $localPort);
        $this->localAddress = new Address($localAddress, $localPort);
        $this->registerSocket($socket, fn () => $this->readSocket($socket));
    }

    public static function listen(string $address, int $port = 0): Listener
    {
        return new Listener($address, $port);
    }

    /**
     * @param null|Closure(ServerConnection $stream): void $connectionAcceptor
     */
    public function setConnectionAcceptor(?Closure $connectionAcceptor): void
    {
        $this->connectionAcceptor = $connectionAcceptor;
    }

    /**
     * Octets retenus par l'ensemble des connexions. Publie pour que l'hote puisse comparer ce
     * chiffre a la memoire reelle de son thread : un ecart dit que la memoire s'accumule
     * ailleurs que dans les files du transport.
     */
    public function getBufferedBytesTotal(): int
    {
        $total = 0;
        foreach ($this->connections as $connection) {
            $total += $connection->getBufferedBytes();
        }
        return $total;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * @return array{overflow: int, timeout: int}
     */
    public function getClosureCounts(): array
    {
        return [
            "overflow" => $this->overflowClosures,
            "timeout" => $this->timeoutClosures,
        ];
    }

    public function registerSocket(Socket $socket, Closure $handler): void
    {
        $socketId = spl_object_id($socket);
        $this->sockets[$socketId] = $socket;
        $this->socketHandlers[$socketId] = $handler;
    }

    public function tick(): bool
    {
        if ($this->closed) {
            return false;
        }
        $this->selectSockets(50);
        $this->tickConnections();
        return true;
    }

    public function close(): void
    {
        if (!$this->closed) {
            foreach ($this->connections as $id => $connection) {
                $connection->closeWithError(ConnectionClose::CONNECTION_CLOSE_GRACEFUL, "listener closed");
                $this->forget($id);
            }

            foreach ($this->sockets as $socketId => $socket) {
                @socket_close($socket);
                unset($this->sockets[$socketId]);
            }
            $this->closed = true;
        }
    }

    private function selectSockets(int $timeout): void
    {
        $read = $this->sockets;
        $write = null;
        $except = null;
        $changed = @socket_select($read, $write, $except, 0, $timeout);
        if ($changed !== false && $changed > 0) {
            foreach ($read as $id => $socket) {
                $this->socketHandlers[$id]();
            }
        }
    }

    private function tickConnections(): void
    {
        foreach ($this->connections as $connectionID => $connection) {
            if (!$connection->tick()) {
                $raison = $connection->getCloseReason();
                if ($raison !== null && str_starts_with($raison, "buffer overflow")) {
                    $this->overflowClosures++;
                } elseif ($raison !== null) {
                    $this->timeoutClosures++;
                }
                $this->forget($connectionID);
            }
        }
    }

    private function forget(int $connectionID): void
    {
        unset($this->connections[$connectionID]);
        $cle = $this->addressByConnection[$connectionID] ?? null;
        if ($cle !== null) {
            unset($this->addressByConnection[$connectionID]);
            if (($this->connectionsByAddress[$cle] ?? null) === $connectionID) {
                unset($this->connectionsByAddress[$cle]);
            }
        }
    }

    public function readSocket(Socket $socket): void
    {
        while (($length = @socket_recvfrom($socket, $buffer, 1500, OS::getOS() !== OS::OS_WINDOWS ? MSG_DONTWAIT : 0, $address, $port)) !== false) {
            $packet = Pack::unpack($buffer);
            if ($packet === null) {
                return;
            }

            [$connectionID, $sequenceID, $frames] = $packet;
            $connection = $this->connections[$connectionID] ?? null;
            if ($connection === null && Listener::hasFrame(FrameIds::CONNECTION_REQUEST, $frames)) {
                $remote = new Address($address, $port);
                $known = $this->connectionsByAddress[$remote->toString()] ?? null;
                $connection = $known !== null ? ($this->connections[$known] ?? null) : null;
                if ($connection !== null) {
                    $connection->logger->log("connection_request_replay", "address", $address, "port", $port);
                    $connection->resendConnectionResponse();
                } else {
                    $connection = new ServerConnection(new Conn($socket, $this->localAddress, $remote, false), $this->connectionId);
                    $connection->logger->log("connection_accepted", "address", $address, "port", $port);
                    $this->connections[$this->connectionId] = $connection;
                    $this->connectionsByAddress[$remote->toString()] = $this->connectionId;
                    $this->addressByConnection[$this->connectionId] = $remote->toString();
                    $this->connectionId++;
                    if ($this->connectionAcceptor !== null) {
                        ($this->connectionAcceptor)($connection);
                    }
                }
            }

            $connection?->receive($sequenceID, $frames);
            if ($this->closed) {
                return;
            }
        }
    }

    /**
     * @param array<int, Frame> $frames
     */
    private static function hasFrame(int $frameID, array $frames): bool
    {
        foreach ($frames as $fr) {
            if ($fr->id() === $frameID) {
                return true;
            }
        }
        return false;
    }
}
