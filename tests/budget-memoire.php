<?php

declare(strict_types=1);

/**
 * Le budget memoire du listener doit se declencher la ou le plafond par connexion ne le peut
 * pas : plusieurs connexions chacune sous sa limite, dont la somme epuise le thread.
 *
 * C'est le scenario mesure sur le banc a 550 bots et trois proxies — memoire du thread a 508
 * Mio, zero fermeture pour depassement — et ce test echoue sur le code d'avant le budget.
 *
 * Usage : php tests/budget-memoire.php
 */

require __DIR__ . "/../src/Listener.php";
require __DIR__ . "/../src/frame/Frame.php";
require __DIR__ . "/../src/frame/ConnectionClose.php";

use cooldogedev\spectral\Listener;

final class JournalMuet
{
    /** @var array<int, string> */
    public array $evenements = [];

    public function log(string $evenement, mixed ...$champs): void
    {
        $this->evenements[] = $evenement;
    }
}

final class ConnexionFactice
{
    public JournalMuet $logger;
    public bool $fermee = false;
    public string $raison = "";

    public function __construct(private int $octets)
    {
        $this->logger = new JournalMuet();
    }

    public function tick(): bool
    {
        return !$this->fermee;
    }

    public function getBufferedBytes(): int
    {
        return $this->octets;
    }

    public function closeWithError(int $code, string $message): void
    {
        $this->fermee = true;
        $this->raison = $message;
    }
}

/**
 * @param array<int, int> $tailles
 * @return array{array<int, ConnexionFactice>, Listener}
 */
function preparer(array $tailles, int $budget): array
{
    $reflexion = new ReflectionClass(Listener::class);
    $listener = $reflexion->newInstanceWithoutConstructor();

    $connexions = [];
    foreach ($tailles as $id => $octets) {
        $connexions[$id] = new ConnexionFactice($octets);
    }

    $champ = $reflexion->getProperty("connections");
    $champ->setValue($listener, $connexions);
    $champ = $reflexion->getProperty("memoryBudget");
    $champ->setValue($listener, $budget);
    $champ = $reflexion->getProperty("closed");
    $champ->setValue($listener, false);
    foreach (["budgetClosures", "overflowClosures", "timeoutClosures"] as $compteur) {
        $reflexion->getProperty($compteur)->setValue($listener, 0);
    }

    return [$connexions, $listener];
}

function tick(Listener $listener): void
{
    $methode = new ReflectionMethod(Listener::class, "tickConnections");
    $methode->invoke($listener);
}

$echecs = 0;
$verifier = static function (string $nom, bool $condition) use (&$echecs): void {
    printf("%-58s %s\n", $nom, $condition ? "ok" : "ECHEC");
    if (!$condition) {
        $echecs++;
    }
};

$mio = 1024 * 1024;

[$connexions, $listener] = preparer([0 => 130 * $mio, 1 => 130 * $mio, 2 => 130 * $mio], 192 * $mio);
tick($listener);
$fermees = array_filter($connexions, static fn (ConnexionFactice $c): bool => $c->fermee);
$verifier("trois connexions sous leur plafond, somme au-dessus : une fermeture", count($fermees) === 1);
$verifier("la fermeture cite le budget du listener", str_contains(reset($fermees)->raison ?: "", "memory budget exceeded"));
$verifier("le declenchement est comptabilise et donc observable", $listener->getClosureCounts()["budget"] === 1);

[$connexions, $listener] = preparer([0 => 10 * $mio, 1 => 150 * $mio, 2 => 90 * $mio], 192 * $mio);
tick($listener);
$verifier("c'est la plus grosse qui tombe, pas une voisine", $connexions[1]->fermee && !$connexions[0]->fermee && !$connexions[2]->fermee);

[$connexions, $listener] = preparer([0 => 60 * $mio, 1 => 60 * $mio, 2 => 60 * $mio], 192 * $mio);
tick($listener);
$verifier("sous le budget, personne n'est ferme", count(array_filter($connexions, static fn (ConnexionFactice $c): bool => $c->fermee)) === 0);
$verifier("et rien n'est comptabilise", $listener->getClosureCounts()["budget"] === 0);

[$connexions, $listener] = preparer([0 => 500 * $mio], 192 * $mio);
tick($listener);
$verifier("une seule connexion peut depasser le budget a elle seule", $connexions[0]->fermee);

[$connexions, $listener] = preparer([], 192 * $mio);
tick($listener);
$verifier("aucune connexion : pas de division par zero", true);

echo $echecs === 0 ? "\nTout est vert.\n" : "\n$echecs echec(s).\n";
exit($echecs === 0 ? 0 : 1);
