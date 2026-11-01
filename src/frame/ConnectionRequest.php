<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;

final class ConnectionRequest extends Frame
{
    public static function create(): ConnectionRequest
    {
        return new ConnectionRequest();
    }

    public function id(): int
    {
        return FrameIds::CONNECTION_REQUEST;
    }

    public function encode(ByteBufferWriter $buf): void {}

    public function decode(ByteBufferReader $buf): void {}
}
