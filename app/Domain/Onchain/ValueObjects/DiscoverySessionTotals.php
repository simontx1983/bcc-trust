<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The cumulative totals of ONE administrator-authorized session, read back
 * from the row that actually persisted them.
 *
 * ── THE DEFECT THIS TYPE EXISTS TO MAKE IMPOSSIBLE ──────────────────────
 * The accumulation lives entirely in SQL: `releaseForNextChunk()` and
 * `markSucceeded()` both write `col = col + %d`, so after a confirmed
 * terminal write the ROW holds the session total and PHP never does. The
 * executor's `$counts` array is ONE CHUNK's telemetry and nothing more.
 *
 * `auditTerminal()` was handed that array. On the 2026-09-06 Cosmos Hub
 * session the ledger row correctly said 1136 requests / 371 families /
 * 2 collections, and the terminal audit row said **41 / 9 / 0** — the last
 * chunk. The ledger was right; the permanent record an operator reads back
 * was wrong.
 *
 * ── WHY A TYPE AND NOT A HELPER FUNCTION ────────────────────────────────
 * There is exactly one constructor and it takes a PERSISTED ROW. A caller
 * cannot assemble these totals out of a `$counts` delta even by accident,
 * because the delta has no row to offer. That is the whole point: the bug
 * was a plausible-looking array reaching the wrong parameter, and an array
 * cannot be mistaken for this.
 *
 * ⚠ IT IS ALSO A CONFIRMATION, NOT A CALCULATION. The numbers are whatever
 * the database ended up storing — not what PHP believed it wrote. An audit
 * row may never claim a cumulative value that was not successfully
 * persisted, and the only way to honour that is to read it back.
 *
 * ⚠ THE ONE OTHER PLACE THAT ADDS A DELTA TO A ROW is
 * `DiscoveryRunExecutor::continueSession()`, which projects
 * `row.requests_used + this chunk` to test the session request ceiling. That
 * is a pre-write DECISION, taken before the release has written anything, so
 * it cannot read a post-write row. It is deliberately not a reported total
 * and must stay that way — this type cannot be built there, which keeps the
 * two apart by construction.
 *
 * @phpstan-import-type DiscoveryRunRow from DiscoveryRunRepository
 */
final class DiscoverySessionTotals
{
    private function __construct(
        public readonly string $runUuid,
        public readonly int $chainId,
        public readonly string $scanMode,
        public readonly string $status,
        public readonly string $stopReason,
        public readonly int $chunksUsed,
        public readonly bool $partial,
        public readonly bool $auditDegraded,
        public readonly int $requestsUsed,
        public readonly int $pagesFetched,
        public readonly int $familiesSeen,
        public readonly int $contractsSeen,
        public readonly int $collectionsEmitted,
        public readonly int $collectionsDenied,
    ) {
    }

    /**
     * Build from a row re-read AFTER a confirmed terminal write.
     *
     * ⚠ NULL MEANS "NOT CONFIRMED", and the caller's response is the same
     * whichever cause it was. `findById()` returns null both for a missing
     * row and for a failed read; here those are genuinely interchangeable,
     * because in both cases we do not know what was persisted and must not
     * write totals into an audit row. They are therefore deliberately NOT
     * distinguished — the alternative would be two branches doing the
     * identical thing, which reads as though one of them mattered.
     *
     * @param DiscoveryRunRow|null $row
     */
    public static function fromPersistedRow(?object $row): ?self
    {
        if ($row === null) {
            return null;
        }

        // A row without the identity fields is not a run row we can vouch
        // for. Fail closed rather than audit an empty uuid.
        $uuid = isset($row->run_uuid) ? (string) $row->run_uuid : '';
        if ($uuid === '') {
            return null;
        }

        $int = static fn(string $field): int => max(0, (int) ($row->{$field} ?? 0));

        return new self(
            $uuid,
            $int('chain_id'),
            isset($row->scan_mode) ? (string) $row->scan_mode : '',
            isset($row->status) ? (string) $row->status : '',
            isset($row->stop_reason) ? (string) $row->stop_reason : '',
            $int('chunks_used'),
            (int) ($row->partial ?? 0) === 1,
            (int) ($row->audit_degraded ?? 0) === 1,
            $int('requests_used'),
            $int('pages_fetched'),
            $int('families_seen'),
            $int('contracts_seen'),
            $int('collections_emitted'),
            $int('collections_denied'),
        );
    }

    /**
     * Bounded audit metadata for a terminal session outcome.
     *
     * ⚠ EVERY VALUE IS A BOUNDED IDENTIFIER OR AN INTEGER. No provider
     * response, no URL, no SQL, no exception text, no wallet data, no free
     * text of any kind — the same rule PR 5b established when it removed
     * free text from durable storage.
     *
     * ⚠ `stop_reason` IS READ OFF THE ROW, NOT PASSED IN. The executor holds
     * a `$stopReason` variable at the terminal write and it is tempting to
     * hand it over here — but that is a second source for a value the row
     * already stores, and a second source is how this PR's defect happened.
     * The audit reports what the ledger says, whole.
     *
     * @return array<string, int|string>
     */
    public function toAuditMeta(): array
    {
        return [
            'run_uuid'            => $this->runUuid,
            'chain_id'            => $this->chainId,
            'scan_mode'           => $this->scanMode,
            'stop_reason'         => $this->stopReason,
            'status'              => $this->status,
            'partial'             => $this->partial ? 1 : 0,
            'audit_degraded'      => $this->auditDegraded ? 1 : 0,
            // ⚠ CUMULATIVE, for the WHOLE session — including the final
            // chunk, which `markSucceeded()` had already added before this
            // row was re-read.
            'chunks_used'         => $this->chunksUsed,
            'requests_used'       => $this->requestsUsed,
            'pages_fetched'       => $this->pagesFetched,
            'families_seen'       => $this->familiesSeen,
            'contracts_seen'      => $this->contractsSeen,
            'collections_emitted' => $this->collectionsEmitted,
            'collections_denied'  => $this->collectionsDenied,
        ];
    }
}
