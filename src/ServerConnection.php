<?php

declare(strict_types=1);

namespace cooldogedev\spectral;

use Closure;
use cooldogedev\spectral\frame\ConnectionRequest;
use cooldogedev\spectral\frame\ConnectionResponse;
use cooldogedev\spectral\frame\Frame;
use cooldogedev\spectral\frame\StreamRequest;
use cooldogedev\spectral\frame\StreamResponse;
use cooldogedev\spectral\util\log\Perspective;

final class ServerConnection extends Connection
{
    /**
     * @var null|Closure(Stream $stream): void
     */
    private ?Closure $streamAcceptor = null;

    public function __construct(Conn $conn, int $connectionID)
    {
        parent::__construct($conn, $connectionID, Perspective::PERSPECTIVE_SERVER);
        $this->logger->setConnectionID($connectionID);
    }

    /**
     * @param null|Closure(Stream $stream): void $streamAcceptor
     */
    public function setStreamAcceptor(?Closure $streamAcceptor): void
    {
        $this->streamAcceptor = $streamAcceptor;
    }

    private function acceptStream(StreamRequest $request): void
    {
        $this->logger->log("stream_accept", "streamID", $request->streamID);
        $stream = $this->createStream($request->streamID);
        if ($stream === null) {
            $this->writeControl(StreamResponse::create($request->streamID, StreamResponse::STREAM_RESPONSE_FAILED), true);
            return;
        }

        $this->writeControl(StreamResponse::create($request->streamID, StreamResponse::STREAM_RESPONSE_SUCCESS), true);
        $this->logger->log("stream_accept_success", "streamID", $request->streamID);
        if ($this->streamAcceptor !== null) {
            ($this->streamAcceptor)($stream);
        }
    }

    /**
     * Repond a une demande de connexion rejouee.
     *
     * Le chemin normal ne suffit pas : la demande rejouee porte la meme sequence que
     * l'originale, donc la file de reception la rejette comme doublon et handle() n'est jamais
     * atteint. Le client cesserait alors de reessayer, acquitte, sans avoir recu la reponse
     * qu'il attend — et echouerait sur son delai de garde.
     */
    public function resendConnectionResponse(): void
    {
        $this->logger->log("connection_response_resend", "connectionID", $this->connectionID);
        $this->writeControl(ConnectionResponse::create($this->connectionID, ConnectionResponse::CONNECTION_RESPONSE_SUCCESS), true);
    }

    public function handle(int $now, Frame $fr): void
    {
        match (true) {
            $fr instanceof ConnectionRequest => $this->writeControl(ConnectionResponse::create($this->connectionID, ConnectionResponse::CONNECTION_RESPONSE_SUCCESS), true),
            $fr instanceof StreamRequest => $this->acceptStream($fr),
            default => null,
        };
        parent::handle($now, $fr);
    }
}
