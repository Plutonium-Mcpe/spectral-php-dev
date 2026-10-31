<?php

declare(strict_types=1);

/**
 * Equivalence de la file de retransmission avant et apres la suppression du tri.
 *
 * La file doit rester ordonnee par `sent` croissant : `shift()` lit sa tete avec
 * `array_key_first()`, donc tout ecart d'ordre change le paquet renvoye. L'ancienne version
 * garantissait cet ordre par un `uasort()` a chaque ajout, chaque retrait et chaque renvoi,
 * ce qui rendait l'emission quadratique sur le thread reseau. La nouvelle le tient par
 * construction. Ce script execute la meme sequence aleatoire d'operations sur les deux et
 * exige des sorties identiques, operation par operation.
 *
 * Usage : php tests/retransmission-order.php
 */

require __DIR__ . "/../src/RetransmissionEntry.php";
require __DIR__ . "/../src/util/Time.php";
require __DIR__ . "/../src/RetransmissionQueue.php";

use cooldogedev\spectral\RetransmissionEntry;
use cooldogedev\spectral\RetransmissionQueue;
use cooldogedev\spectral\util\Time;

/**
 * Reproduction fidele de l'implementation d'avant, tri compris, pour servir de temoin.
 */
final class TriParUasort
{
    private const RETRANSMISSION_ATTEMPTS = 20;
    private const MAX_BACKOFF = Time::SECOND;

    /** @var array<int, RetransmissionEntry> */
    private array $queue = [];

    public function add(int $now, int $sequenceID, string $data): void
    {
        $this->queue[$sequenceID] = new RetransmissionEntry($data, $now);
        $this->sort();
    }

    public function remove(int $sequenceID): ?RetransmissionEntry
    {
        $entry = $this->queue[$sequenceID] ?? null;
        if ($entry !== null) {
            unset($this->queue[$sequenceID]);
            $this->sort();
            return $entry;
        }
        return null;
    }

    /** @return null|array{int, string, bool} */
    public function shift(int $now, int $rto): ?array
    {
        if (count($this->queue) === 0) {
            return null;
        }
        $sequenceID = array_key_first($this->queue);
        $entry = $this->queue[$sequenceID];
        $waitFor = $entry->backoff !== 0 ? $entry->backoff : $rto;
        if ($now - $entry->sent >= $waitFor) {
            $sent = $entry->sent;
            $entry->sent = $now;
            $entry->attempts++;
            $entry->backoff = min($waitFor * 2, TriParUasort::MAX_BACKOFF);
            if ($entry->attempts >= TriParUasort::RETRANSMISSION_ATTEMPTS) {
                unset($this->queue[$sequenceID]);
                return [$sent, $entry->payload, true];
            }
            $this->sort();
            return [$sent, $entry->payload, false];
        }
        return null;
    }

    private function sort(): void
    {
        uasort($this->queue, static fn(RetransmissionEntry $a, RetransmissionEntry $b): int => $a->sent <=> $b->sent);
    }
}

$echecs = 0;
$operations = 0;

for ($graine = 1; $graine <= 50; $graine++) {
    mt_srand($graine);
    $temoin = new TriParUasort();
    $nouvelle = new RetransmissionQueue();
    $now = 0;
    $vivants = [];
    $prochainID = 1;

    for ($i = 0; $i < 400; $i++) {
        // Le temps n'avance jamais a rebours : c'est l'hypothese sur laquelle repose
        // l'ordre par construction, et elle tient parce que l'appelant passe une horloge
        // monotone prise sur le meme thread.
        $now += mt_rand(0, Time::MILLISECOND * 5);
        $rto = Time::MILLISECOND * 100;

        $tirage = mt_rand(1, 100);
        if ($tirage <= 45) {
            $id = $prochainID++;
            $charge = str_repeat("x", mt_rand(1, 1400));
            $temoin->add($now, $id, $charge);
            $nouvelle->add($now, $id, $charge);
            $vivants[$id] = true;
            $operations++;
        } elseif ($tirage <= 70 && count($vivants) > 0) {
            $id = array_rand($vivants);
            $a = $temoin->remove($id);
            $b = $nouvelle->remove($id);
            unset($vivants[$id]);
            $operations++;
            if (($a?->payload) !== ($b?->payload) || ($a?->sent) !== ($b?->sent)) {
                echo "graine $graine op $i : remove($id) diverge\n";
                $echecs++;
            }
        } else {
            $a = $temoin->shift($now, $rto);
            $b = $nouvelle->shift($now, $rto);
            $operations++;
            if ($a !== $b) {
                printf(
                    "graine %d op %d : shift diverge — temoin=%s nouvelle=%s\n",
                    $graine,
                    $i,
                    $a === null ? "null" : "[{$a[0]}, " . strlen($a[1]) . "o, " . var_export($a[2], true) . "]",
                    $b === null ? "null" : "[{$b[0]}, " . strlen($b[1]) . "o, " . var_export($b[2], true) . "]"
                );
                $echecs++;
            }
        }
    }
}

// Le compteur d'octets doit retomber a zero quand tout a ete acquitte, sans quoi le
// garde-fou memoire de Connection fermerait des connexions saines a la longue.
$file = new RetransmissionQueue();
$file->add(1, 1, str_repeat("a", 500));
$file->add(2, 2, str_repeat("b", 300));
if ($file->getQueuedBytes() !== 800) {
    echo "comptage apres deux ajouts : attendu 800, obtenu " . $file->getQueuedBytes() . "\n";
    $echecs++;
}
$file->remove(1);
if ($file->getQueuedBytes() !== 300) {
    echo "comptage apres un retrait : attendu 300, obtenu " . $file->getQueuedBytes() . "\n";
    $echecs++;
}
$file->clear();
if ($file->getQueuedBytes() !== 0) {
    echo "comptage apres clear : attendu 0, obtenu " . $file->getQueuedBytes() . "\n";
    $echecs++;
}

if ($echecs === 0) {
    echo "ok — $operations operations sur 50 sequences, comportement identique au tri par uasort\n";
    exit(0);
}

echo "$echecs divergence(s)\n";
exit(1);
