<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;

final class StreamResponse extends Frame
{
    public const STREAM_RESPONSE_SUCCESS = 0;
    public const STREAM_RESPONSE_FAILED = 1;

    public int $streamID;
    public int $response;

    public static function create(int $streamID, int $response): StreamResponse
    {
        $fr = new StreamResponse();
        $fr->streamID = $streamID;
        $fr->response = $response;
        return $fr;
    }

    public function id(): int
    {
        return FrameIds::STREAM_RESPONSE;
    }

    public function encode(ByteBufferWriter $buf): void
    {
		LE::writeSignedLong($buf, $this->streamID);
		Byte::writeUnsigned($buf, $this->response);
    }

    public function decode(ByteBufferReader $buf): void
    {
		$this->streamID = LE::readSignedLong($buf);
		$this->response = Byte::readUnsigned($buf);
    }
}
