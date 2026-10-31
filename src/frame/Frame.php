<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;

abstract class Frame
{
    abstract public function id(): int;

    abstract public function encode(ByteBufferWriter $buf): void;

    abstract public function decode(ByteBufferReader $buf): void;
}
