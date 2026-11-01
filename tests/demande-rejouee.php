<?php

declare(strict_types=1);

/**
 * Une demande de connexion rejouee ne doit pas ouvrir une seconde connexion.
 *
 * Un client part avec l'identifiant -1 et ne connait le sien qu'apres la reponse du serveur.
 * Comme writeControl() range dans la file de retransmission le paquet deja serialise, une
 * demande rejouee — reponse perdue, ack perdu — porte encore -1. Le serveur n'y reconnaissait
 * aucune connexion et en ouvrait une seconde : memoire retenue en double, et surtout le client
 * adoptait l'identifiant de la nouvelle reponse, abandonnant la premiere connexion et les flux
 * deja ouverts dessus.
 *
 * Usage : php tests/demande-rejouee.php
 */

$racine = __DIR__ . "/..";
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

use cooldogedev\spectral\frame\ConnectionRequest;
use cooldogedev\spectral\frame\Pack;
use cooldogedev\spectral\Listener;

$echecs = 0;
$verifier = static function (string $nom, bool $condition) use (&$echecs): void {
    printf("%-58s %s\n", $nom, $condition ? "ok" : "ECHEC");
    if (!$condition) {
        $echecs++;
    }
};

$listener = Listener::listen("127.0.0.1", 0);
$reflexion = new ReflectionProperty(Listener::class, "localAddress");
$port = $reflexion->getValue($listener)->port;

$demande = static fn (int $sequenceID): string => Pack::pack(-1, $sequenceID, Pack::packSingle(ConnectionRequest::create()));

$client = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_bind($client, "127.0.0.1", 0);

$pomper = static function () use ($listener): void {
    for ($i = 0; $i < 20; $i++) {
        $listener->tick();
    }
};

socket_sendto($client, $demande(1), strlen($demande(1)), 0, "127.0.0.1", $port);
$pomper();
$verifier("une demande ouvre une connexion", $listener->getConnectionCount() === 1);

$vider = static function (Socket $socket): int {
    $recus = 0;
    while (@socket_recvfrom($socket, $tampon, 1500, MSG_DONTWAIT, $de, $dePort) !== false) {
        $recus++;
    }
    return $recus;
};
$verifier("la premiere demande recoit une reponse", $vider($client) > 0);

socket_sendto($client, $demande(1), strlen($demande(1)), 0, "127.0.0.1", $port);
$pomper();
$verifier("la demande rejouee n'ouvre pas de seconde connexion", $listener->getConnectionCount() === 1);
$verifier("la demande rejouee recoit quand meme une reponse", $vider($client) > 0);

socket_sendto($client, $demande(3), strlen($demande(3)), 0, "127.0.0.1", $port);
$pomper();
$verifier("un troisieme rejeu n'en ouvre pas davantage", $listener->getConnectionCount() === 1);
$vider($client);

$autre = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_bind($autre, "127.0.0.1", 0);
socket_sendto($autre, $demande(1), strlen($demande(1)), 0, "127.0.0.1", $port);
$pomper();
$verifier("un autre pair ouvre bien une connexion distincte", $listener->getConnectionCount() === 2);

$listener->close();
socket_close($client);
socket_close($autre);

echo $echecs === 0 ? "\nTout est vert.\n" : "\n$echecs echec(s).\n";
exit($echecs === 0 ? 0 : 1);
