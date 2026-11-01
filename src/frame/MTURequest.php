<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use function str_repeat;

final class MTURequest extends Frame
{
    public int $mtu;

    public static function create(int $mtu): MTURequest
    {
        $fr = new MTURequest();
        $fr->mtu = $mtu;
        return $fr;
    }

    public function id(): int
    {
        return FrameIds::MTU_REQUEST;
    }

    public function encode(ByteBufferWriter $buf): void
    {
		LE::writeSignedLong($buf, $this->mtu);
        $buf->writeByteArray(str_repeat("\x00", $this->mtu - 8));
    }

    public function decode(ByteBufferReader $buf): void
    {
		$this->mtu = LE::readSignedLong($buf);
        $buf->readByteArray($this->mtu - 8);
    }
}
