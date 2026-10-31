<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;

final class StreamRequest extends Frame
{
    public int $streamID;

    public static function create(int $streamID): StreamRequest
    {
        $fr = new StreamRequest();
        $fr->streamID = $streamID;
        return $fr;
    }

    public function id(): int
    {
        return FrameIds::STREAM_REQUEST;
    }

    public function encode(ByteBufferWriter $buf): void
    {
		LE::writeSignedLong($buf, $this->streamID);
    }

    public function decode(ByteBufferReader $buf): void
    {
        $this->streamID = LE::readSignedLong($buf);
    }
}
