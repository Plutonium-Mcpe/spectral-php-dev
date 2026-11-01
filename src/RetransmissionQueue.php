<?php

declare(strict_types=1);

namespace cooldogedev\spectral;

use cooldogedev\spectral\util\Time;
use function array_key_first;
use function count;
use function min;
use function strlen;

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
     * Ordonnee par `sent` croissant, du plus ancien envoi au plus recent : `shift()` lit la tete
     * avec `array_key_first()`. L'ordre est tenu par construction plutot que par un tri — voir
     * `moveToTail()` — car un `uasort()` a chaque ajout et a chaque retrait rendait l'emission
     * quadratique, et cette file vit sur le thread reseau.
     *
     * @var array<int, RetransmissionEntry>
     */
    private array $queue = [];

    private int $queuedBytes = 0;

    public function add(int $now, int $sequenceID, string $data): void
    {
        unset($this->queue[$sequenceID]);
        $this->queue[$sequenceID] = new RetransmissionEntry($data, $now);
        $this->queuedBytes += strlen($data);
    }

    public function remove(int $sequenceID): ?RetransmissionEntry
    {
        $entry = $this->queue[$sequenceID] ?? null;
        if ($entry !== null) {
            unset($this->queue[$sequenceID]);
            $this->queuedBytes -= strlen($entry->payload);
            return $entry;
        }
        return null;
    }

    /**
     * Octets retenus en attente d'acquittement. L'appelant s'en sert comme garde-fou memoire :
     * une connexion dont le pair n'acquitte plus retient tout ce qu'elle a envoye jusqu'a
     * expiration des tentatives, et rien d'autre ne borne ce volume.
     */
    public function getQueuedBytes(): int
    {
        return $this->queuedBytes;
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
                $this->queuedBytes -= strlen($entry->payload);
                return [$sent, $entry->payload, true];
            }
            $this->moveToTail($sequenceID, $entry);
            return [$sent, $entry->payload, false];
        }
        return null;
    }

    public function clear(): void
    {
        $this->queue = [];
        $this->queuedBytes = 0;
    }

    /**
     * Replace l'entree en queue apres un renvoi. Son `sent` vient d'etre porte a `$now`, donc
     * elle est desormais la plus recente : la retirer puis la reinserer lui donne la derniere
     * place, et l'ordre par `sent` croissant tient sans retrier quoi que ce soit.
     */
    private function moveToTail(int $sequenceID, RetransmissionEntry $entry): void
    {
        unset($this->queue[$sequenceID]);
        $this->queue[$sequenceID] = $entry;
    }
}
