<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which worker a run belongs to.
 *
 * ── WHY THIS EXISTS AS A COLUMN ─────────────────────────────────────────
 * `wp_bcc_chain_checkpoints` is a SHARED row per chain: the EVM indexer
 * owns `last_processed_block` and friends, the CosmWasm worker owns the
 * `cw_*` columns, and the two are isolated by column namespace alone. The
 * run ledger must not repeat that ambiguity — a run says explicitly which
 * worker it is for, and the active-run uniqueness is scoped by it, so a
 * CosmWasm run and a future EVM run on the same chain do not exclude each
 * other while two CosmWasm runs do.
 *
 * Closed set, validated before any row is written. A value from a newer
 * build is refused rather than stored.
 */
final class DiscoveryJobKind
{
    /** The CW-721 code-family walk and its incremental pass. */
    public const COSMWASM_DISCOVERY = 'cosmwasm_discovery';

    /**
     * Reserved. The EVM indexer does not use the ledger yet; the constant
     * exists so the vocabulary is closed from the first migration rather
     * than widened later, and so tests can prove job kinds do not collide.
     */
    public const EVM_INDEXER = 'evm_indexer';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::COSMWASM_DISCOVERY, self::EVM_INDEXER];
    }

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    /**
     * Which kinds an administrator may request today.
     *
     * Deliberately narrower than {@see all()}: the vocabulary is closed for
     * storage, but only CosmWasm discovery has a runnable executor. A
     * request for anything else is refused rather than queued forever.
     *
     * @return list<string>
     */
    public static function requestable(): array
    {
        return [self::COSMWASM_DISCOVERY];
    }

    public static function isRequestable(string $kind): bool
    {
        return in_array($kind, self::requestable(), true);
    }

    /** Longest value, proving VARCHAR(32) is sufficient. */
    public static function maxLength(): int
    {
        // Explicit rather than max(array_map(...)): the vocabulary is
        // closed, and PHPStan cannot know a runtime list is non-empty.
        return max(strlen(self::COSMWASM_DISCOVERY), strlen(self::EVM_INDEXER));
    }
}
