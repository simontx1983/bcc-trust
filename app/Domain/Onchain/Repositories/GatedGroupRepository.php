<?php
/**
 * Gate-config storage for NFT-gated peepso-groups.
 *
 * Five post-meta keys per gated group:
 *   _bcc_group_kind          = 'holders'
 *   _bcc_gate_chain_id       (numeric FK to onchain_chains)
 *   _bcc_gate_contract_address (chain-aware canonical identity, BYTE-EXACT)
 *   _bcc_gate_min_balance    (default 1)
 *   _bcc_gate_collection_id  (FK to onchain_collections.id) — THE AUTHORITY
 *
 * No parallel ledger — membership stays in PeepSo's peepso_group_members.
 *
 * ── WHICH KEY IS THE IDENTITY (PR 5b) ───────────────────────────────────
 * `_bcc_gate_collection_id` is. `_bcc_gate_contract_address` is kept for
 * backward compatibility and display, and is never allowed to override the
 * linked collection row — see {@see \BCC\Trust\Onchain\Services\GateIdentityResolver}.
 *
 * This docblock previously described `_bcc_gate_contract_address` as
 * "lowercase canonical". It was neither: lower-casing a base58 Solana mint
 * produces a different key, and on the eight production Solana gates the
 * value stored there was a Magic Eden SYMBOL, not an address at all. Both
 * the reader and the writer below now treat the value as byte-exact and
 * defer to the collection row for identity.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class GatedGroupRepository {

    use GuardsReadFailures;

    public const META_KIND       = '_bcc_group_kind';
    public const META_CHAIN_ID   = '_bcc_gate_chain_id';
    public const META_CONTRACT   = '_bcc_gate_contract_address';
    public const META_MIN_BAL    = '_bcc_gate_min_balance';
    public const META_COLLECTION = '_bcc_gate_collection_id';

    public const KIND_HOLDERS = 'holders';

    /**
     * Defensive bound for {@see findGroupsForCollections()}. The only
     * caller pages at 100; past this the answer is truncated rather than
     * the query being unbounded.
     */
    public const MAX_COLLECTION_LOOKUP = 200;

    /**
     * Reverse lookup: find the gated group for a (chain, contract) pair.
     * Returns the WP post ID or null. Bounded LIMIT 1.
     */
    public static function findGroupForCollection(int $chainId, string $contract): ?int {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        // PR 5b: this is an identity lookup, so the value it matches on
        // goes through the one chain-aware rule.
        //
        // It used to be `strtolower($contract)` — right for EVM and Cosmos,
        // wrong for Solana, where base58 is case-sensitive and a folded
        // mint is a DIFFERENT key. `writeGateConfig()` now stores the
        // canonical form byte-exact, so folding here would stop the lookup
        // ever finding a Solana gate.
        //
        // A value that is not a valid identity for this chain matches
        // nothing, so it returns null rather than running a query whose
        // answer could only be misleading.
        //
        // ⚠ The comparison below is forced to `utf8mb4_bin`, and that is
        // NOT cosmetic. `wp_postmeta.meta_value` is a WordPress core column
        // with a case-INSENSITIVE collation, so removing the PHP
        // `strtolower()` is not by itself enough: MySQL would still match a
        // case-folded mint against the stored one, and this lookup would
        // keep resolving two different Solana keys to the same gate. That
        // is the same class of defect as the PHP fold, one layer down, and
        // it is invisible until something asserts it (an integration test
        // caught exactly this).
        //
        // Safe for every family: EVM and Cosmos canonical forms are
        // lowercase and are stored canonical, so a binary comparison
        // returns the same rows it did before.
        $chain  = ChainRepository::getById($chainId);
        $family = $chain === null ? '' : (string) ($chain->chain_type ?? '');

        $identity = \BCC\Trust\Onchain\Support\NftCollectionIdentifier::canonicalize($family, $contract);
        if (!$identity->isAccepted()) {
            return null;
        }

        $contractCanonical = $identity->canonical();

        global $wpdb;

        // ⚠ PR 6: the post itself is now joined and filtered.
        //
        // This lookup used to match on post meta ALONE, which meant a
        // trashed or draft group still counted as "a community exists".
        // Two consequences, both bad in opposite directions: a collection
        // whose community had been trashed was reported as provisioned and
        // lost its "Request community" affordance; and compensation after a
        // failed provisioning could not prove the group was gone, because a
        // trashed group with surviving meta still answered this query.
        //
        // Only a PUBLISHED `peepso-group` is a live community. Every one of
        // the 28 production gates is published, so the measured effect of
        // the change is nil — but it is what makes "no live ungated
        // community remains" a statement this code can actually verify.
        // `listAllGatedGroupIds()` has always filtered this way; the two are
        // now consistent.
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT pm_chain.post_id
               FROM {$wpdb->postmeta} pm_chain
          INNER JOIN {$wpdb->postmeta} pm_kind     ON pm_kind.post_id     = pm_chain.post_id
          INNER JOIN {$wpdb->postmeta} pm_contract ON pm_contract.post_id = pm_chain.post_id
          INNER JOIN {$wpdb->posts}    p           ON p.ID                = pm_chain.post_id
              WHERE pm_chain.meta_key    = %s
                AND pm_chain.meta_value  = %d
                AND pm_kind.meta_key     = %s
                AND pm_kind.meta_value   = %s
                AND pm_contract.meta_key = %s
                AND pm_contract.meta_value COLLATE utf8mb4_bin = %s
                AND p.post_type          = %s
                AND p.post_status        = %s
              LIMIT 1",
            self::META_CHAIN_ID,
            $chainId,
            self::META_KIND,
            self::KIND_HOLDERS,
            self::META_CONTRACT,
            $contractCanonical,
            'peepso-group',
            'publish'
        ));

        return $row !== null ? (int) $row : null;
    }

    /**
     * SET-BASED form of {@see findGroupForCollection()}: resolve MANY
     * (chain, contract) pairs in ONE query instead of one query each.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     * `VerifyCollectionsPage` renders up to 100 rows and called
     * `findGroupForCollection()` inside the loop, so a full page issued up
     * to 100 of these — plus a member count and a permalink each. The
     * comment above that loop claimed the N+1 "is gone", which was true
     * only of community EXISTENCE (projected by `listForAdminState()`);
     * resolving the group, its member count and its permalink stayed
     * per-row.
     *
     * ⚠⚠ THE IDENTITY RULE IS THE SAME ONE, DELIBERATELY. This matches on
     * `_bcc_gate_contract_address` after `NftCollectionIdentifier` has
     * canonicalised the value per chain family, and compares it under
     * `utf8mb4_bin`. Both halves are load-bearing and are explained at
     * length on `findGroupForCollection()`: folding a Solana mint, or
     * letting `wp_postmeta`'s case-insensitive collation do the comparison,
     * resolves two different base58 keys to one gate.
     *
     * ⚠ It would be tempting to batch on `_bcc_gate_collection_id`, the
     * authoritative link, which is a plain integer `IN (...)`. That is NOT
     * done here: a legacy gate carrying a contract address but no
     * collection-id meta resolves today and would stop resolving, which
     * changes what the page displays. The authoritative key has its own
     * accessor for callers that need it.
     *
     * Keyed by the CALLER'S array key so the caller never has to
     * canonicalise anything itself — the rule stays in one place.
     *
     * A read failure returns an empty map, which renders exactly as a
     * single failed lookup already did: the caller shows "community
     * present, identity unresolved" rather than claiming none exists.
     *
     * @param array<array-key, array{chain_id:int, contract:string}> $pairs
     * @return array<array-key, int> caller key => group post ID; absent when unresolved
     */
    public static function findGroupsForCollections(array $pairs): array {
        if ($pairs === []) {
            return [];
        }

        // Bounded: the only caller pages at 100. The cap is defensive, and
        // a caller that exceeds it gets a truncated answer rather than an
        // unbounded query.
        if (count($pairs) > self::MAX_COLLECTION_LOOKUP) {
            $pairs = array_slice($pairs, 0, self::MAX_COLLECTION_LOOKUP, true);
        }

        // ── Resolve families for DISTINCT chains, not per pair ──────────
        // This is the step that would otherwise reintroduce the N+1 one
        // layer down: canonicalisation needs the chain family, and
        // `getById()` is per chain. Deduping first bounds it by the number
        // of distinct chains on the page, which is bounded by the chains
        // table and independent of the row count.
        $families = [];
        foreach ($pairs as $pair) {
            $chainId = (int) ($pair['chain_id'] ?? 0);
            if ($chainId > 0 && !array_key_exists($chainId, $families)) {
                $chain = ChainRepository::getById($chainId);
                $families[$chainId] = $chain === null ? '' : (string) ($chain->chain_type ?? '');
            }
        }

        // ── Canonicalise, and remember which caller keys want each pair ──
        /** @var array<string, list<array-key>> $keysByPair */
        $keysByPair = [];
        /** @var list<array{chain_id:int, canonical:string}> $lookups */
        $lookups    = [];
        foreach ($pairs as $callerKey => $pair) {
            $chainId  = (int) ($pair['chain_id'] ?? 0);
            $contract = (string) ($pair['contract'] ?? '');
            if ($chainId <= 0 || $contract === '') {
                continue;
            }

            $identity = \BCC\Trust\Onchain\Support\NftCollectionIdentifier::canonicalize(
                $families[$chainId] ?? '',
                $contract
            );
            if (!$identity->isAccepted()) {
                // Same as the single lookup: a value that is not a valid
                // identity on this chain matches nothing, so it is omitted
                // rather than queried.
                continue;
            }

            $canonical = $identity->canonical();
            $pairKey   = $chainId . '|' . $canonical;
            if (!isset($keysByPair[$pairKey])) {
                $keysByPair[$pairKey] = [];
                $lookups[] = ['chain_id' => $chainId, 'canonical' => $canonical];
            }
            $keysByPair[$pairKey][] = $callerKey;
        }

        if ($lookups === []) {
            return [];
        }

        global $wpdb;

        $ors  = [];
        $args = [
            self::META_CHAIN_ID,
            self::META_KIND,
            self::KIND_HOLDERS,
            self::META_CONTRACT,
            'peepso-group',
            'publish',
        ];
        foreach ($lookups as $lookup) {
            $ors[]  = '(pm_chain.meta_value = %d AND pm_contract.meta_value COLLATE utf8mb4_bin = %s)';
            $args[] = $lookup['chain_id'];
            $args[] = $lookup['canonical'];
        }
        $args[] = count($lookups);

        // ⚠ `ORDER BY pm_chain.post_id ASC` plus first-wins makes this at
        // least as deterministic as the single lookup's bare `LIMIT 1`,
        // which had no ordering at all. Every production gate is unique per
        // collection, so no row's displayed value depends on the choice.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_chain.post_id       AS group_id,
                    pm_chain.meta_value    AS chain_id,
                    pm_contract.meta_value AS contract
               FROM {$wpdb->postmeta} pm_chain
          INNER JOIN {$wpdb->postmeta} pm_kind     ON pm_kind.post_id     = pm_chain.post_id
          INNER JOIN {$wpdb->postmeta} pm_contract ON pm_contract.post_id = pm_chain.post_id
          INNER JOIN {$wpdb->posts}    p           ON p.ID                = pm_chain.post_id
              WHERE pm_chain.meta_key    = %s
                AND pm_kind.meta_key     = %s
                AND pm_kind.meta_value   = %s
                AND pm_contract.meta_key = %s
                AND p.post_type          = %s
                AND p.post_status        = %s
                AND (" . implode(' OR ', $ors) . ")
           ORDER BY pm_chain.post_id ASC
              LIMIT %d",
            ...$args
        ));

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $pairKey = (int) $row->chain_id . '|' . (string) $row->contract;
            foreach ($keysByPair[$pairKey] ?? [] as $callerKey) {
                if (!isset($out[$callerKey])) {
                    $out[$callerKey] = (int) $row->group_id;
                }
            }
        }

        return $out;
    }

    /**
     * Reverse lookup on the AUTHORITATIVE link: find the published gated
     * group for a collection ROW ID. Returns the WP post ID, or null only
     * when there provably is no such group. Bounded LIMIT 1.
     *
     * ── WHY THIS EXISTS ALONGSIDE findGroupForCollection() ──────────────
     * `_bcc_gate_collection_id` is the identity (see the class docblock).
     * `findGroupForCollection()` matches on `_bcc_gate_contract_address`,
     * which is legacy/display, and to do that it has to canonicalise a
     * string first — so it answers null for a value that is not a valid
     * identity on its chain. For a DISPLAY caller that is harmless. For a
     * caller asking "is it safe to destroy this row?" it is the wrong
     * answer in the dangerous direction: the eight production Solana gates
     * stored a marketplace SYMBOL there, which canonicalises to nothing, so
     * the lookup returns null WITHOUT RUNNING A QUERY and a live community
     * reads as absent.
     *
     * Keying on the row id removes the string from the question entirely —
     * no family lookup, no canonicalisation, nothing to fail.
     *
     * ── IT IS THE SAME RULE AS THE ADMIN LISTING ────────────────────────
     * The predicate below is {@see CollectionStateClassifier::sqlHasCommunity()}
     * narrowed to one id: `_bcc_gate_collection_id` = the row id, joined to
     * `_bcc_group_kind = 'holders'` and to a PUBLISHED `peepso-group`. A
     * trashed or draft group is not a live community, exactly as PR 6
     * decided. They are deliberately two expressions of one rule, so an
     * integration test cross-checks them rather than trusting the comment.
     *
     * ── AND IT FAILS CLOSED ─────────────────────────────────────────────
     * `get_var()` hands back null for "no row" AND for "the query did not
     * run", and this method's answer gates a delete. So the read is
     * guarded: a failed read throws instead of returning a null that the
     * caller would read as permission. Null from here means ONE thing.
     *
     * @throws RepositoryReadFailure when the read did not run
     */
    public static function findPublishedGroupIdForCollectionId(int $collectionId): ?int {
        if ($collectionId <= 0) {
            // A bad id is a substantive answer, not a fault: no row can
            // have it, so no group can point at it.
            return null;
        }

        global $wpdb;

        // `meta_value` is a string column holding a decimal integer, and a
        // `%d` bind compares '79' = 79 correctly. This mirrors how
        // sqlHasCommunity() compares `pm_coll.meta_value = c.id` and how
        // findGroupForCollection() above binds the chain id, so all three
        // agree. No COLLATE clause is needed or wanted here — unlike the
        // contract lookup, this comparison is numeric, so the case
        // sensitivity of wp_postmeta's collation cannot affect it.
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT pm_coll.post_id
               FROM {$wpdb->postmeta} pm_coll
          INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = pm_coll.post_id
          INNER JOIN {$wpdb->posts}    p       ON p.ID            = pm_coll.post_id
              WHERE pm_coll.meta_key   = %s
                AND pm_coll.meta_value = %d
                AND pm_kind.meta_key   = %s
                AND pm_kind.meta_value = %s
                AND p.post_type        = %s
                AND p.post_status      = %s
              LIMIT 1",
            self::META_COLLECTION,
            $collectionId,
            self::META_KIND,
            self::KIND_HOLDERS,
            'peepso-group',
            'publish'
        ));

        // ⚠ Order matters: guard BEFORE interpreting the result, so a
        // failed read can never be cast to null and handed back.
        self::guardReadOrThrow(__FUNCTION__);

        return $row !== null ? (int) $row : null;
    }

    /**
     * Read the gate config for a group. Returns null if not a holders group.
     * Uses WP's post-meta cache (call update_meta_cache for batches first).
     */
    public static function getGateConfig(int $groupId): ?GatedGroupConfig {
        if ($groupId <= 0) {
            return null;
        }

        $kind = (string) get_post_meta($groupId, self::META_KIND, true);
        if ($kind !== self::KIND_HOLDERS) {
            return null;
        }

        $chainId  = (int) get_post_meta($groupId, self::META_CHAIN_ID, true);
        $contract = (string) get_post_meta($groupId, self::META_CONTRACT, true);
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        $minBalance = (int) get_post_meta($groupId, self::META_MIN_BAL, true);
        if ($minBalance < 1) {
            $minBalance = 1;
        }

        $collectionMeta = get_post_meta($groupId, self::META_COLLECTION, true);
        $collectionId   = $collectionMeta !== '' ? (int) $collectionMeta : null;

        // PR 5b: the stored meta value is returned BYTE-EXACT. It used to be
        // `strtolower($contract)`, which silently corrupted every Solana
        // identity it touched.
        //
        // This value is now legacy/display only — `GateIdentityResolver`
        // derives the identity a provider is actually asked about from the
        // linked collection row's `canonical_identifier`. Handing back the
        // raw stored value keeps that distinction honest: a caller reading
        // `contractAddress` gets what is stored, not a normalised fiction.
        return new GatedGroupConfig(
            $groupId,
            $chainId,
            $contract,
            $minBalance,
            $collectionId
        );
    }

    /**
     * All gated groups, hydrated to GatedGroupConfig. One SELECT for IDs,
     * one update_meta_cache, then per-group hydration off the warm cache.
     *
     * @return list<GatedGroupConfig>
     */
    public static function listAllGatedGroupConfigs(int $limit = 500): array {
        $ids = self::listAllGatedGroupIds($limit);
        if ($ids === []) {
            return [];
        }

        return array_values(self::findManyByGroupIds($ids));
    }

    /**
     * Bulk-fetch GatedGroupConfig for a caller-supplied set of group_ids.
     * One `update_meta_cache` warms post_meta for the whole batch; each
     * subsequent `getGateConfig` call hits the warm cache instead of the
     * DB. Non-holder group_ids in the input set are silently dropped.
     *
     * Used by the Profile Groups Tab + Holder-Groups REST surface to
     * resolve viewer eligibility across N gated groups in one DB
     * round-trip rather than N.
     *
     * @param int[] $groupIds
     * @return array<int, GatedGroupConfig> map keyed by group_id
     */
    public static function findManyByGroupIds(array $groupIds): array {
        if ($groupIds === []) {
            return [];
        }

        update_meta_cache('post', $groupIds);

        $map = [];
        foreach ($groupIds as $groupId) {
            $cfg = self::getGateConfig($groupId);
            if ($cfg !== null) {
                $map[$cfg->groupId] = $cfg;
            }
        }
        return $map;
    }

    /**
     * @return list<int>
     */
    public static function listAllGatedGroupIds(int $limit = 500): array {
        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = %s
                AND pm.meta_value = %s
                AND p.post_type   = %s
                AND p.post_status = %s
              ORDER BY p.ID ASC
              LIMIT %d",
            self::META_KIND,
            self::KIND_HOLDERS,
            'peepso-group',
            'publish',
            $limit
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * Opted-in auto-join user IDs, cursor-paged by user_id ASC.
     *
     * Drives the twicedaily reconcile sweep's rotation: callers pass the
     * last-seen user_id as `$afterId` so every opted-in user is eventually
     * processed instead of the query re-hitting the first N forever. A
     * short result (< $limit) means the cursor reached the end → caller
     * wraps to 0.
     *
     * Bounded (§4): `LIMIT %d`, unique-key meta filter, cursor bound.
     * Explicit single column. The meta_key/meta_value pair matches
     * NftGroupGateService::USER_META_AUTO_JOIN = '1' (the only opted-in
     * marker).
     *
     * @return list<int>
     */
    public static function listAutoJoinUserIdsAfter(int $afterId, int $limit = 20): array {
        if ($limit <= 0) {
            return [];
        }
        if ($afterId < 0) {
            $afterId = 0;
        }

        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id
               FROM {$wpdb->usermeta}
              WHERE meta_key   = %s
                AND meta_value = %s
                AND user_id    > %d
              ORDER BY user_id ASC
              LIMIT %d",
            \BCC\Trust\Onchain\Services\NftGroupGateService::USER_META_AUTO_JOIN,
            '1',
            $afterId,
            $limit
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * Write the five gate post-meta keys (PeepSo group post must exist).
     *
     * ── PR 5b: THIS CAN NOW REFUSE ──────────────────────────────────────
     * It used to store `strtolower($contractAddress)` unconditionally and
     * return void. Two things were wrong with that:
     *
     *  1. Folding case corrupts a Solana identity outright — base58 is
     *     case-sensitive, so the stored value became a different key.
     *  2. It would happily create a gate for a collection with no resolved
     *     identity. Such a gate is unsatisfiable BY CONSTRUCTION: no
     *     provider can ever return holdings for a marketplace alias, the
     *     count is permanently 0, and a real 0 reads as INELIGIBLE. That
     *     is the exact defect this PR removes — so manufacturing new
     *     instances of it is refused rather than merely fixed afterwards.
     *
     * The value written is now validated for the chain's family and stored
     * BYTE-EXACT. A refusal writes NOTHING — not a partial gate, not four
     * of five keys — and returns false for the caller to report.
     *
     * @param int    $groupId          Existing peepso-group post ID.
     * @param int    $chainId          FK to onchain_chains.
     * @param string $chainFamily      `wp_bcc_chains.chain_type` — NOT inferred.
     * @param string $canonicalAddress The collection's canonical identifier.
     *                                 Stored verbatim when accepted.
     * @param int    $minBalance       Default 1.
     * @param int    $collectionId     FK to onchain_collections.id.
     *
     * @return bool true when all five keys were written; false when the
     *              identity was refused and nothing was written.
     */
    public static function writeGateConfig(
        int $groupId,
        int $chainId,
        string $chainFamily,
        string $canonicalAddress,
        int $minBalance,
        int $collectionId
    ): bool {
        $identity = \BCC\Trust\Onchain\Support\NftCollectionIdentifier::canonicalize(
            $chainFamily,
            $canonicalAddress
        );

        if (!$identity->isAccepted()) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] refusing to write a holder gate with an unresolved collection identity',
                [
                    'group_id'      => $groupId,
                    'collection_id' => $collectionId,
                    'chain_id'      => $chainId,
                    'chain_family'  => $chainFamily,
                    'reason'        => $identity->reason(),
                ]
            );

            return false;
        }

        update_post_meta($groupId, self::META_KIND,       self::KIND_HOLDERS);
        update_post_meta($groupId, self::META_CHAIN_ID,   $chainId);
        update_post_meta($groupId, self::META_CONTRACT,   $identity->canonical());
        update_post_meta($groupId, self::META_MIN_BAL,    max(1, $minBalance));
        update_post_meta($groupId, self::META_COLLECTION, $collectionId);

        return true;
    }

    /**
     * What is LEFT of a group after a compensating delete.
     *
     * -- WHY THIS LIVES IN A REPOSITORY -----------------------------------
     * It is three raw reads, and raw `$wpdb` belongs in a repository (arch
     * guardrail 1). It sits HERE rather than in a new class because the gate
     * meta it checks is this repository's own, and the caller needs one
     * answer covering all three tables, not three lookups it has to combine.
     *
     * -- WHY IT RETURNS MARKERS AND NOT A BOOL ---------------------------
     * A bare false says "something is wrong" and leaves an operator with
     * nowhere to start. Each marker names WHICH postcondition failed, and
     * distinguishes "the check could not be read" from "the thing is still
     * there" -- an unreadable check is not proof of cleanliness, and
     * reporting it as such is how a live ungated community would be recorded
     * as removed.
     *
     * @return list<string> empty when the group is provably gone
     */
    public static function compensationResidue(int $groupId): array
    {
        global $wpdb;

        if ($groupId <= 0) {
            return ['invalid_group_id'];
        }

        $residue = [];

        $post = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
            $groupId
        ));
        if ($post === null) {
            $residue[] = 'post_check_unreadable';
        } elseif ((int) $post > 0) {
            $residue[] = 'post_remains';
        }

        $meta = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
              WHERE post_id = %d AND meta_key IN (%s, %s, %s, %s, %s)",
            $groupId,
            self::META_KIND,
            self::META_CHAIN_ID,
            self::META_CONTRACT,
            self::META_MIN_BAL,
            self::META_COLLECTION
        ));
        if ($meta === null) {
            $residue[] = 'gate_meta_check_unreadable';
        } elseif ((int) $meta > 0) {
            $residue[] = 'gate_meta_remains';
        }

        // PeepSo's own delete cascade does NOT clean the membership row -- it
        // leaves it for a later `deleteMembersForDeletedGroups()` maintenance
        // sweep, which is an unbounded global DELETE. So this is checked
        // explicitly rather than assumed. The table is guarded because PeepSo
        // Groups may not be installed at all.
        $membersTable = $wpdb->prefix . 'peepso_group_members';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $membersTable));
        if (is_string($exists) && $exists !== '') {
            $members = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$membersTable}` WHERE gm_group_id = %d",
                $groupId
            ));
            if ($members === null) {
                $residue[] = 'member_check_unreadable';
            } elseif ((int) $members > 0) {
                $residue[] = 'members_remain';
            }
        }

        return $residue;
    }

}
