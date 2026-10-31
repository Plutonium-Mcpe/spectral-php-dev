<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;

final class ConnectionResponse extends Frame
{
    public const CONNECTION_RESPONSE_SUCCESS = 0;
    public const CONNECTION_RESPONSE_FAILED = 1;

    public int $connectionID;
    public int $response;

    public static function create(int $connectionID, int $response): ConnectionResponse
    {
        $fr = new ConnectionResponse();
        $fr->connectionID = $connectionID;
        $fr->response = $response;
        return $fr;
    }

    public function id(): int
    {
        return FrameIds::CONNECTION_RESPONSE;
    }

    public function encode(ByteBufferWriter $buf): void
    {
		LE::writeSignedLong($buf, $this->connectionID);
		Byte::writeUnsigned($buf, $this->response);
    }

    public function decode(ByteBufferReader $buf): void
    {
		$this->connectionID = LE::readSignedLong($buf);
		$this->response = Byte::readUnsigned($buf);
    }
}
