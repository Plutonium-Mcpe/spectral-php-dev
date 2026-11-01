<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use function strlen;

final class StreamData extends Frame
{
    public int $streamID;
    public int $sequenceID;
    public string $payload;

    public static function create(int $streamID, int $sequenceID, string $payload): StreamData
    {
        $fr = new StreamData();
        $fr->streamID = $streamID;
        $fr->sequenceID = $sequenceID;
        $fr->payload = $payload;
        return $fr;
    }

    public function id(): int
    {
        return FrameIds::STREAM_DATA;
    }

    public function encode(ByteBufferWriter $buf): void
    {
		LE::writeSignedLong($buf, $this->streamID);
		LE::writeUnsignedInt($buf, $this->sequenceID);
		LE::writeUnsignedInt($buf, strlen($this->payload));
        $buf->writeByteArray($this->payload);
    }

    public function decode(ByteBufferReader $buf): void
    {
		$this->streamID = LE::readSignedLong($buf);
		$this->sequenceID = LE::readUnsignedInt($buf);
		$this->payload = $buf->readByteArray(LE::readUnsignedInt($buf));
    }
}
