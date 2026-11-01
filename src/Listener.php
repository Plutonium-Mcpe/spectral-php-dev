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
     * Budget memoire de l'ensemble des connexions du listener.
     *
     * Le plafond par connexion de Connection ne peut pas tenir la limite du thread qui heberge
     * le listener : trois connexions a 130 Mio restent chacune sous leurs 128 Mio et tuent
     * pourtant un thread limite a 512 Mio. C'est ce qui s'est produit sur un banc a 550 bots
     * et trois proxies, ou la memoire du thread est montee a 508 Mio sans qu'aucun plafond par
     * connexion ne se declenche.
     *
     * La valeur par defaut vise un thread a 512 Mio et laisse de quoi decoder, journaliser et
     * tenir les sessions a cote des files. L'hote qui connait sa propre limite la remplace par
     * setMemoryBudget().
     */
    public const DEFAULT_MEMORY_BUDGET = 192 * 1024 * 1024;

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

    private int $memoryBudget = Listener::DEFAULT_MEMORY_BUDGET;

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
     * Fixe le budget memoire cumule des connexions. A regler en dessous de la limite memoire
     * du thread qui fait tourner ce listener, en gardant de quoi loger tout le reste.
     */
    public function setMemoryBudget(int $bytes): void
    {
        $this->memoryBudget = $bytes;
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
        $total = 0;
        $worstID = null;
        $worstBytes = 0;
        foreach ($this->connections as $connectionID => $connection) {
            if (!$connection->tick()) {
                $this->forget($connectionID);
                continue;
            }
            $buffered = $connection->getBufferedBytes();
            $total += $buffered;
            if ($buffered > $worstBytes) {
                $worstBytes = $buffered;
                $worstID = $connectionID;
            }
        }

        if ($total > $this->memoryBudget && $worstID !== null) {
            $connection = $this->connections[$worstID];
            $connection->logger->log("memory_budget_exceeded", "total", $total, "budget", $this->memoryBudget, "connection", $worstBytes);
            $connection->closeWithError(ConnectionClose::CONNECTION_CLOSE_TIMEOUT, "memory budget exceeded: " . $total . " bytes buffered across the listener");
            $this->forget($worstID);
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
