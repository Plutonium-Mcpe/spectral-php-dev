<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use function strlen;

final class ConnectionClose extends Frame
{
    public const CONNECTION_CLOSE_APPLICATION = 0;
    public const CONNECTION_CLOSE_GRACEFUL = 1;
    public const CONNECTION_CLOSE_TIMEOUT = 2;
    public const CONNECTION_CLOSE_INTERNAL = 3;

    public int $code;
    public string $message;

    public static function create(int $code, string $message): ConnectionClose
    {
        $fr = new ConnectionClose();
        $fr->code = $code;
        $fr->message = $message;
        return $fr;
    }

    public function id(): int
    {
        return FrameIds::CONNECTION_CLOSE;
    }

    public function encode(ByteBufferWriter $buf): void
    {
//        $buf->writeUnsignedByte($this->code);
//        $buf->writeUnsignedIntLE(strlen($this->message));
		Byte::writeUnsigned($buf, $this->code);
		LE::writeUnsignedInt($buf, strlen($this->message));
        $buf->writeByteArray($this->message);
    }

    public function decode(ByteBufferReader $buf): void
    {
		$this->code = Byte::readUnsigned($buf);
		$this->message = $buf->readByteArray(LE::readUnsignedInt($buf));
    }
}
