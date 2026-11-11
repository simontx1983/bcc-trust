<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allow/deny rules for NFT contracts at the indexer write path.
 *
 * Owns `wp_bcc_nft_spam_contracts`. Read-hot path: getRule() is called
 * once per (chain, contract) pair in NftSpamFilter; backed by a
 * request-level cache to avoid re-querying for the same contract
 * across an indexer batch.
 *
 * @phpstan-type SpamContractRow object{
 *     id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     rule: string,
 *     reason: string|null,
 *     created_at: string
 * }
 */
final class NftSpamContractRepository
{
    // ⚠ The read-failure guard. `getRule()` used to swallow a failed query
    // as "no rule" AND cache it; the trait supplies the detection, the
    // logging and the fail-closed throw that `getRuleOrThrow()` needs.
    use GuardsReadFailures;

    public const RULE_DENY  = 'deny';
    public const RULE_ALLOW = 'allow';

    private const COLUMNS = 'id, chain_id, contract_address, rule, reason, created_at';

    /**
     * Per-request rule cache keyed `chain_id:contract`. Value is the
     * rule string ('deny' / 'allow') or null when no rule exists.
     *
     * @var array<string, string|null>
     */
    private static array $cache = [];

    public static function table(): string
    {
        return DB::table('nft_spam_contracts');
    }

    /**
     * The deny/allow rule for a (chain, contract) pair, or null.
     *
     * ⚠⚠ NULL IS AMBIGUOUS HERE, AND DELIBERATELY SO — SEE
     * {@see getRuleOrThrow()} FOR THE ARM THAT IS NOT.
     *
     * Null means "no explicit rule" to every existing caller, and three of
     * them treat that as "not denied": `NftSpamFilter::isSpam()` falls
     * through to heuristics, `CollectionStanceService` surfaces the row,
     * and `EvmFetcher` accepts the collection. That is the SUPPRESSION
     * policy and this change does not touch it — flipping those to
     * fail-closed would let one transient database fault blank a user's
     * collections, which is a worse failure than the one being fixed.
     *
     * What DID change: a failed read is no longer silent, and no longer
     * poisons the cache.
     *
     * A caller that must not confuse "no rule" with "could not look" —
     * anything that DISPLAYS hide state, or that would authorise an action
     * on the strength of an assumed absence — calls `getRuleOrThrow()`
     * instead. Same query, honest answer.
     */
    public static function getRule(int $chainId, string $contract): ?string
    {
        return self::readRule($chainId, $contract, false);
    }

    /**
     * FAIL-CLOSED sibling of {@see getRule()} — one query, two policies.
     *
     * Same house idiom as the `…OrThrow` siblings on CollectionRepository,
     * NftHoldingsRepository and WalletRepository: the caller that cannot
     * survive an ambiguous answer asks for the arm that throws.
     *
     * ⚠ Those sibling names are deliberately NOT written here in
     * `::method(` form. LegacyAliasRouteCompatibilityTest builds a CALLER
     * INVENTORY by grepping production for that exact text, so naming one
     * in prose registers this file as a caller of it — which it is not.
     * The guard is right to be a plain grep; the comment is what has to
     * stay out of its way.
     *
     * ⚠ This is what makes "unreadable" renderable as itself. The admin
     * detail view used `getRule()` and printed "not hidden" for a database
     * fault — stating, about a contract an operator may have deliberately
     * suppressed, the exact opposite of what was known.
     *
     * @throws RepositoryReadFailure when the read did not run
     */
    public static function getRuleOrThrow(int $chainId, string $contract): ?string
    {
        return self::readRule($chainId, $contract, true);
    }

    /**
     * The single read behind both arms.
     *
     * @param bool $failClosed throw instead of returning an ambiguous null
     * @throws RepositoryReadFailure when `$failClosed` and the read failed
     */
    private static function readRule(int $chainId, string $contract, bool $failClosed): ?string
    {
        // Not a lookup at all: a malformed pair matches nothing and runs no
        // query, so it is genuinely absent rather than unreadable.
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        $contractLc = strtolower($contract);
        $key        = $chainId . ':' . $contractLc;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        global $wpdb;
        $table = self::table();

        $rule = $wpdb->get_var($wpdb->prepare(
            "SELECT rule
               FROM {$table}
              WHERE chain_id = %d
                AND contract_address = %s
              LIMIT 1",
            $chainId,
            $contractLc
        ));

        // ⚠⚠ A FAILED READ MUST NOT BE CACHED.
        //
        // `get_var()` returns null for no-such-row AND for a failed query,
        // and the old code cached that null — so ONE transient fault
        // poisoned the request-scoped cache for that key, and every later
        // caller in the request got "no rule" WITHOUT RUNNING A QUERY.
        // They could not have detected the failure even if they checked,
        // because no query of theirs ever ran. That is the amplification
        // this guard removes: detect first, cache only a real answer.
        if ($failClosed) {
            // Logs and throws. Nothing is cached, so the next caller in
            // this request gets a fresh attempt rather than a stale null.
            self::guardReadOrThrow('getRuleOrThrow');
        } elseif (self::readFailed('getRule')) {
            // Logged by the guard. The ambiguous null is still returned,
            // because changing that is a suppression-policy decision — but
            // it is NOT cached, so the fault stays visible to the next
            // caller instead of being silently frozen in for the request.
            return null;
        }

        $resolved = is_string($rule) ? $rule : null;
        self::$cache[$key] = $resolved;

        return $resolved;
    }

    public static function addRule(int $chainId, string $contract, string $rule, string $reason = ''): bool
    {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        if (!in_array($rule, [self::RULE_DENY, self::RULE_ALLOW], true)) {
            return false;
        }

        global $wpdb;
        $table      = self::table();
        $contractLc = strtolower($contract);
        $now        = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (chain_id, contract_address, rule, reason, created_at)
             VALUES (%d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                rule = VALUES(rule),
                reason = VALUES(reason)",
            $chainId,
            $contractLc,
            $rule,
            $reason !== '' ? mb_substr($reason, 0, 255) : '',
            $now
        ));

        // Invalidate request-level cache so the new rule applies immediately.
        unset(self::$cache[$chainId . ':' . $contractLc]);

        return $result !== false;
    }

    public static function removeRule(int $chainId, string $contract): bool
    {
        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table      = self::table();
        $contractLc = strtolower($contract);

        $deleted = $wpdb->delete(
            $table,
            ['chain_id' => $chainId, 'contract_address' => $contractLc],
            ['%d', '%s']
        );

        unset(self::$cache[$chainId . ':' . $contractLc]);

        return is_int($deleted) && $deleted > 0;
    }

    /**
     * Admin-page list with pagination.
     *
     * @return list<SpamContractRow>
     */
    public static function findAll(int $limit = 100, int $offset = 0): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var list<SpamContractRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              ORDER BY chain_id ASC, contract_address ASC
              LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
        return $rows ?: [];
    }

    /**
     * Test-only / admin-tool reset of the per-request cache.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
