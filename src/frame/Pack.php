<?php

declare(strict_types=1);

namespace cooldogedev\spectral\frame;

use cooldogedev\spectral\Protocol;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use function strlen;

final class Pack
{
    private static ?ByteBufferWriter $writeBuf = null;

    public static function packSingle(Frame $fr): string
    {
        $buf = Pack::getWriter();
        LE::writeUnsignedInt($buf, $fr->id());
        $fr->encode($buf);
        return $buf->getData();
    }

    public static function pack(int $connectionID, int $sequenceID, string $frames): string
    {
        $buf = Pack::getWriter();
        $buf->writeByteArray(Protocol::MAGIC);
        LE::writeSignedLong($buf, $connectionID);
        LE::writeUnsignedInt($buf, $sequenceID);
        $buf->writeByteArray($frames);
        return $buf->getData();
    }

    public static function unpack(string $payload): ?array
    {
        if (strlen($payload) < Protocol::PACKET_HEADER_SIZE) {
            return null;
        }

		$buf = new ByteBufferReader($payload);
        if ($buf->readByteArray(4) !== Protocol::MAGIC) {
            return null;
        }

		$connectionID = LE::readSignedLong($buf);
		$sequenceID = LE::readUnsignedInt($buf);
        $frames = [];
        while ($buf->getUnreadLength() > 0) {
            $fr = Pool::getFrame(LE::readUnsignedInt($buf));
            if ($fr === null) {
                break;
            }
            $fr->decode($buf);
            $frames[] = $fr;
        }
        return [$connectionID, $sequenceID, $frames];
    }

    private static function getWriter(): ByteBufferWriter
    {
        if (Pack::$writeBuf === null) {
            Pack::$writeBuf = new ByteBufferWriter();
        }
        Pack::$writeBuf->setOffset(0);
        Pack::$writeBuf->clear();
        return Pack::$writeBuf;
    }
}
