<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OPTIONAL, WRITE-ONLY TELEMETRY for one CosmWasm discovery pass.
 *
 * ── WHAT IT IS FOR ──────────────────────────────────────────────────────
 * A supervised operator run has to be able to answer "what did that
 * actually do?" without reading the database twice and guessing. Counts
 * like "how many code-listing pages were walked" exist only INSIDE the
 * pass — the service methods return them, the worker consumes them, and
 * by the time control comes back to a caller they are gone. This object
 * is where the worker parks them on the way past.
 *
 * ── IT MUST NEVER CHANGE BEHAVIOUR ──────────────────────────────────────
 * Every field is a counter or a message. Nothing here is read by the
 * discovery logic, nothing here gates a branch, and every worker
 * parameter that accepts one is `?CosmwasmPassReport $report = null` — so
 * the four SCHEDULED passes pass nothing and run byte-identically to the
 * way they ran before this class existed. That is the property the CLI
 * parity test pins: the same fixtures through the scheduled pass and
 * through the supervised pass produce the same request sequence and the
 * same rows, and the only difference is that one of them counted.
 *
 * Deliberately a mutable bag of public properties rather than an
 * immutable value object: it is written from four call sites inside a
 * single pass and read once at the end, and threading a return value back
 * out of `classifyAndEnumerate()` would have changed the shared
 * `callable(int, CosmosFetcher, CosmwasmTickBudget): void` step contract
 * that all four passes are typed against.
 */
final class CosmwasmPassReport
{
    /** Code-listing pages read by the incremental reverse tail walk. */
    public int $codePagesFetched = 0;

    /** Contract-listing pages read across every family enumerated. */
    public int $contractPagesFetched = 0;

    /** Code families handed to the classifier this pass. */
    public int $familiesClassified = 0;

    /** Individual contracts handed to the classifier this pass. */
    public int $contractsClassified = 0;

    /** Collection rows emitted (contracts whose `collection_row_written` flipped). */
    public int $collectionsEmitted = 0;

    /** Contracts refused at emit time by an operator deny rule. */
    public int $collectionsDenied = 0;

    /**
     * Non-fatal problems worth surfacing to a watching operator.
     *
     * Node errors, an unreachable LCD, a walk that did not reach its
     * watermark. NEVER a credential, a header or a URL with a query
     * string — these are excerpts of messages the chain sent back, and
     * the worker sanitises them before they reach a durable column.
     *
     * @var list<string>
     */
    public array $errors = [];

    // ── Adders ──────────────────────────────────────────────────────────
    //
    // Methods rather than direct property writes because every call site
    // is `$report?->addX(...)` — and PHP refuses the nullsafe operator in
    // a WRITE context, so `$report?->codePagesFetched += $n` is a parse
    // error. Keeping the accumulation behind methods is what lets the
    // scheduled passes hand in null and the supervised pass hand in an
    // object, at the same call site, with no branch.

    public function addCodePages(int $pages): void
    {
        $this->codePagesFetched += max(0, $pages);
    }

    public function addContractPages(int $pages): void
    {
        $this->contractPagesFetched += max(0, $pages);
    }

    public function countFamilyClassified(): void
    {
        $this->familiesClassified++;
    }

    public function countContractClassified(): void
    {
        $this->contractsClassified++;
    }

    public function addEmitted(int $emitted, int $denied): void
    {
        $this->collectionsEmitted += max(0, $emitted);
        $this->collectionsDenied  += max(0, $denied);
    }

    public function addError(string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }
        // Bounded so a pathological node response cannot turn a summary
        // into a log dump.
        if (count($this->errors) >= 20) {
            return;
        }
        $this->errors[] = mb_substr($message, 0, 255);
    }

    /** Total pages walked, both listings. */
    public function pagesFetched(): int
    {
        return $this->codePagesFetched + $this->contractPagesFetched;
    }
}
