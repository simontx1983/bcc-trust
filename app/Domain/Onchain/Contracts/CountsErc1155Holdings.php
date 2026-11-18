<?php
/**
 * Opt-in fetcher capability: a complete per-contract ERC-1155 ownership read
 * (F-278).
 *
 * ── WHY ──────────────────────────────────────────────────────────────────
 * The ERC-1155 transfer index cannot prove a wallet owns NONE of a contract.
 * It starts at chain head with no backfill, and it stores balances as deltas:
 * a transfer-out of a token the index never saw arrive is clamped to a
 * balance-0 row. So "sold their only token", "held 3 before linking and sent
 * 1" and "holds #5 from before linking, sent #7" all read as zero there.
 *
 * A driver that can ask its provider for the wallet's CURRENT holdings of one
 * contract, independent of when the wallet was linked, implements this, and
 * {@see \BCC\Trust\Onchain\Services\HoldingsService} uses it only when the
 * index says zero. A driver that cannot keeps the old fail-closed answer
 * (absence proves nothing).
 *
 * @package BCC\Trust\Onchain\Contracts
 */

namespace BCC\Trust\Onchain\Contracts;

use BCC\Trust\Onchain\ValueObjects\HoldingsCount;

if (!defined('ABSPATH')) {
    exit;
}

interface CountsErc1155Holdings
{
    /**
     * `$wallet`'s current holdings under ERC-1155 `$contract`, in ONE provider
     * request (callers charge one unit of their budget before asking).
     *
     * @return HoldingsCount|null `exact(0)` only when the provider's complete
     *         read says the wallet holds no token of the contract; otherwise a
     *         lower bound. null when there is no trustworthy answer: never a zero.
     */
    public function count_erc1155_holdings(string $wallet, string $contract): ?HoldingsCount;
}
