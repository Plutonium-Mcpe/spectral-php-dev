<?php

declare(strict_types=1);

/**
 * Format sur le fil, avant et apres le passage a l'API native de ext-encoding.
 *
 * Le portage remplace `pmmp\encoding\ByteBuffer` — absent de ext-encoding 1.0.0, donc emule en
 * PHP — par `ByteBufferReader`, `ByteBufferWriter` et les helpers `LE`, qui eux sont natifs.
 * C'est un gain de cout, mais le risque est ailleurs : si un seul octet change de place, le pair
 * Go ne comprend plus rien et plus personne ne se connecte.
 *
 * Ce script encode une batterie de trames avec des valeurs fixes et affiche leurs octets en
 * hexadecimal. Lance sur l'ancienne puis sur la nouvelle implementation, il doit produire
 * exactement la meme sortie. L'aller-retour encode/decode est verifie dans la foulee.
 *
 * Usage : php tests/format-sur-le-fil.php <racine-du-src>
 */

$racine = $argv[1] ?? __DIR__ . "/..";

// L'ancienne implementation a besoin du shim ByteBuffer, que la nouvelle ignore.
if (is_file($racine . "/ByteBuffer.php")) {
    require $racine . "/ByteBuffer.php";
}

spl_autoload_register(static function (string $classe) use ($racine): void {
    $prefixe = "cooldogedev\\spectral\\";
    if (!str_starts_with($classe, $prefixe)) {
        return;
    }
    $chemin = $racine . "/src/" . str_replace("\\", "/", substr($classe, strlen($prefixe))) . ".php";
    if (is_file($chemin)) {
        require $chemin;
    }
});

use cooldogedev\spectral\frame\Acknowledgement;
use cooldogedev\spectral\frame\ConnectionClose;
use cooldogedev\spectral\frame\ConnectionRequest;
use cooldogedev\spectral\frame\ConnectionResponse;
use cooldogedev\spectral\frame\MTURequest;
use cooldogedev\spectral\frame\MTUResponse;
use cooldogedev\spectral\frame\Pack;
use cooldogedev\spectral\frame\StreamClose;
use cooldogedev\spectral\frame\StreamData;
use cooldogedev\spectral\frame\StreamRequest;
use cooldogedev\spectral\frame\StreamResponse;

$trames = [
    "connection_request" => ConnectionRequest::create(),
    "connection_response" => ConnectionResponse::create(-9007199254740991, 1),
    "connection_close_vide" => ConnectionClose::create(0, ""),
    "connection_close" => ConnectionClose::create(7, "retransmission exhausted"),
    "stream_request" => StreamRequest::create(42),
    "stream_response" => StreamResponse::create(42, 1),
    "stream_close" => StreamClose::create(2147483647),
    "stream_data_vide" => StreamData::create(1, 0, ""),
    "stream_data" => StreamData::create(-5, 123456789, str_repeat("\x00\xff\x7f\x80", 64)),
    "mtu_request" => MTURequest::create(1452),
    "mtu_response" => MTUResponse::create(1200),
    "ack_vide" => Acknowledgement::create(0, 0, []),
    "ack" => Acknowledgement::create(1500, 98765, [[1, 10], [12, 12], [4000000000, 4294967295]]),
];

foreach ($trames as $nom => $trame) {
    printf("%-22s %s\n", $nom, bin2hex(Pack::packSingle($trame)));
}

// Une trame complete, en-tete de connexion compris.
$charge = Pack::packSingle(StreamData::create(9, 77, "charge utile"))
    . Pack::packSingle(Acknowledgement::create(12, 34, [[1, 2]]));
$paquet = Pack::pack(-123456789012345, 987654, $charge);
printf("%-22s %s\n", "paquet_complet", bin2hex($paquet));

// Aller-retour : le decodage doit rendre exactement ce qui a ete encode.
[$connectionID, $sequenceID, $trames] = Pack::unpack($paquet);
printf("%-22s connectionID=%d sequenceID=%d trames=%d\n", "aller_retour", $connectionID, $sequenceID, count($trames));
foreach ($trames as $i => $fr) {
    printf("%-22s %d:%s %s\n", "aller_retour_trame", $i, get_class($fr), bin2hex(Pack::packSingle($fr)));
}
