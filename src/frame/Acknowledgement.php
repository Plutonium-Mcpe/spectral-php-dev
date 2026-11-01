<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use function count;

final class Acknowledgement extends Frame
{
    public int $delay;
    public int $max;
    /**
     * @var array<0, array{0: int, 1: int}>
     */
    public array $ranges;

    public static function create(int $delay, int $max, array $ranges): Acknowledgement
    {
        $fr = new Acknowledgement();
        $fr->delay = $delay;
        $fr->max = $max;
        $fr->ranges = $ranges;
        return $fr;
    }

    public function id(): int
    {
        return FrameIds::ACKNOWLEDGEMENT;
    }

    public function encode(ByteBufferWriter $buf): void
    {
		LE::writeSignedLong($buf, $this->delay);
		LE::writeUnsignedInt($buf, $this->max);
		LE::writeUnsignedInt($buf, count($this->ranges));
		foreach ($this->ranges as $range) {
			LE::writeUnsignedInt($buf, $range[0]);
			LE::writeUnsignedInt($buf, $range[1]);
		}
    }

    public function decode(ByteBufferReader $buf): void
    {
		$this->delay = LE::readSignedLong($buf);
		$this->max = LE::readUnsignedInt($buf);
		$length = LE::readUnsignedInt($buf);
		for ($i = 0; $i < $length; $i++) {
			$this->ranges[$i] = [
				LE::readUnsignedInt($buf),
				LE::readUnsignedInt($buf)
			];
		}
    }
}
