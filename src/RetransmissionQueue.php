<?php

declare(strict_types=1);

namespace cooldogedev\spectral;

use cooldogedev\spectral\util\Time;
use function array_key_first;
use function count;
use function min;
use function uasort;

final class RetransmissionQueue
{
    /**
     * Aligné sur le pair Go (retransmissionAttempts = 20, maxBackoff = 1s).
     * A 3 tentatives sans backoff, un paquet fiable était abandonné en ~3x RTO :
     * une microcoupure suffisait. Le pair attend alors ce sequenceID pour
     * toujours, sa fenêtre de réception ne peut plus avancer, et la connexion
     * devient sourde sans que personne ne la ferme.
     */
    private const RETRANSMISSION_ATTEMPTS = 20;

    private const MAX_BACKOFF = Time::SECOND;

    /**
     * @var array<int, RetransmissionEntry>
     */
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

    /**
     * @return null|array{int, string, bool} [instant d'envoi, payload, abandon]
     *
     * Le troisième élément signale qu'un paquet fiable a épuisé ses tentatives.
     * L'appelant doit fermer la connexion : le pair a un trou définitif dans son
     * flux et tout ce qui suit resterait bloqué dans sa file de réordonnancement.
     * L'ancien code faisait un unset() muet et laissait la connexion « saine ».
     */
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
            $entry->backoff = min($waitFor * 2, RetransmissionQueue::MAX_BACKOFF);
            if ($entry->attempts >= RetransmissionQueue::RETRANSMISSION_ATTEMPTS) {
                unset($this->queue[$sequenceID]);
                return [$sent, $entry->payload, true];
            }
            $this->sort();
            return [$sent, $entry->payload, false];
        }
        return null;
    }

    public function clear(): void
    {
        $this->queue = [];
    }

    private function sort(): void
    {
        uasort($this->queue, static fn(RetransmissionEntry $a, RetransmissionEntry $b): int => $a->sent <=> $b->sent);
    }
}
