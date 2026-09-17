<?php
/**
 * Opt-in fetcher capability: a holdings count that says whether it is complete.
 *
 * ── WHY OPT-IN RATHER THAN A CHANGE TO FetcherInterface ─────────────────
 * {@see FetcherInterface::count_holdings()} returns `?int`, where null already
 * means "could not verify". That contract has no way to say "here is a real
 * number, but it is only a lower bound" — the case a capped DAS walk or a
 * capped CW-721 walk actually produces. Widening the shared interface would
 * force every driver and every test double to change at once; an opt-in
 * interface lets the three drivers that can count NFTs declare the stronger
 * answer while everything else keeps compiling.
 *
 * A fetcher that does NOT implement this is treated fail-closed by
 * {@see \BCC\Trust\Onchain\Services\HoldingsService}: its integers may prove
 * ownership but never non-ownership, because nothing vouches for their
 * completeness. The same posture `fetchWalletHoldings()` already takes on a
 * `list_holdings()` payload with no `complete` flag.
 *
 * @package BCC\Trust\Onchain\Contracts
 */

namespace BCC\Trust\Onchain\Contracts;

use BCC\Trust\Onchain\ValueObjects\HoldingsCount;

if (!defined('ABSPATH')) {
    exit;
}

interface CountsHoldingsWithCompleteness
{
    /**
     * Count `$wallet`'s tokens under `$contract`, with completeness.
     *
     * @return HoldingsCount|null null when no trustworthy answer exists — the
     *         provider failed, refused, answered with something unreadable,
     *         or the question itself could not be asked. Never a zero.
     */
    public function count_holdings_evidence(string $wallet, string $contract): ?HoldingsCount;

    /**
     * Worst-case provider page requests ONE evidence read may issue, before
     * `ApiRetry`'s per-request retries. Callers charge this against a request
     * budget BEFORE asking, so a budget can never be overrun mid-read.
     */
    public function max_requests_per_evidence_read(): int;
}
