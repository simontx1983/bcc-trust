<?php
/**
 * NFT-gated group orchestration.
 *
 * Soft gate, suggest-don't-auto-join model:
 *   - joinIfEligible(): explicit user-initiated join. Verifies eligibility,
 *     calls PeepSoGroupWriter::join. An explicit join OVERRIDES (and on
 *     success clears) a prior opt-out (F-158); the opt-out only stops
 *     automatic re-adding.
 *   - findEligibleGroups(): read-side ("you qualify for these").
 *   - reconcileForUser(): batch auto-join — only invoked for users who
 *     opted in via `bcc_auto_join_eligible_groups` user_meta. Default
 *     users skip this entirely (deferred to PR 4).
 *
 * Opt-out is stored in user_meta `bcc_gated_groups_optout` as a
 * JSON-encoded `{<group_id>: <unix_ts>}` map. Default TTL 90 days,
 * filterable. Mod-initiated removals store `0` for permanent.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Repositories\UserMetaSwapRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use BCC\Trust\Onchain\ValueObjects\JoinResult;

if (!defined('ABSPATH')) {
    exit;
}

final class NftGroupGateService {

    public const USER_META_OPT_OUT  = 'bcc_gated_groups_optout';
    public const USER_META_AUTO_JOIN = 'bcc_auto_join_eligible_groups';

    private const PERMANENT_OPT_OUT_TIMESTAMP = 0;

    /**
     * Verify eligibility and join via PeepSoGroupWriter. This is the
     * EXPLICIT, user-initiated Join.
     *
     * ⚠ AN EXPLICIT JOIN OVERRIDES A VOLUNTARY OPT-OUT (F-158, product
     * decision 2026-11-10). The TTL'd opt-out recorded when a member LEAVES
     * exists to stop AUTOMATIC re-adding: reconcileForUser() →
     * findEligibleGroups() still skips opted-out groups. It must not stop
     * the member choosing to come back. This used to refuse with
     * optOutActive for the whole 90-day TTL, even on a deliberate click.
     * The user must still pass eligibility (hold the NFT). A successful join
     * clears the opt-out; a refused one leaves it.
     *
     * ⚠⚠ A PERMANENT opt-out still refuses. That is the moderator-eviction
     * entry (bcc-trust.php, peepso_action_group_user_delete "Path 3"), and
     * this refusal is what stops an evicted member re-joining the moment
     * they still qualify. Never relax it as part of "join overrides opt-out".
     *
     * Idempotent: if the user is already a member, returns
     * JoinResult::alreadyMember (success=true, no PeepSo write).
     */
    public function joinIfEligible(int $userId, int $groupId): JoinResult {
        if ($userId <= 0 || $groupId <= 0) {
            return JoinResult::notAHolderGroup();
        }

        $config = GatedGroupRepository::getGateConfig($groupId);
        if ($config === null) {
            return JoinResult::notAHolderGroup();
        }

        // H7: a TRUSTED read. Unreadable is the transient 503, never "no
        // opt-out".
        $permanent = $this->permanentOptOutState($userId, $groupId);
        if ($permanent === null) {
            return JoinResult::verifyUnavailable($config->minBalance);
        }
        if ($permanent) {
            return JoinResult::optOutActive($config->minBalance);
        }
        // A PeepSo ban is a moderator removal too (F-271): refuse it with the
        // same 403 "a moderator removed you" before any provider call, not a
        // 503 "try again" after the writer refuses it.
        if ($this->isBannedFrom($userId, $groupId)) {
            return JoinResult::optOutActive($config->minBalance);
        }

        $memberships = \BCC\Core\Repositories\PeepSoGroupRepository::findUserMemberships(
            $userId,
            [$groupId]
        );
        if (isset($memberships[$groupId])) {
            return JoinResult::alreadyMember($config->minBalance);
        }

        // PR 5b: resolve the gate's identity from the LINKED COLLECTION ROW
        // before anything else touches a provider. An unresolved identity
        // returns here, so no fetcher is constructed and no RPC is made —
        // the "zero provider calls" guarantee is structural, not a promise.
        //
        // `$config->contractAddress` is deliberately NOT used: on the eight
        // production Solana gates it holds a Magic Eden symbol.
        $identity = GateIdentityResolver::resolve($config);
        if (!$identity->isResolved()) {
            return JoinResult::identityUnresolved($config->minBalance);
        }

        // Three-outcome verdict. JOIN fails CLOSED: on UNKNOWN (provider
        // outage, unavailable chain, failed read, incomplete evidence, or
        // the join budget running out) we refuse to add the user — never
        // bring someone into a gated group without proof. They retry, and a
        // retry resumes with the wallets the previous attempt's budget did
        // not reach, so a holder whose qualifying wallet sits past the budget
        // is found in a later attempt instead of getting 503 forever.
        $verdict = HoldingsService::eligibilityVerdict(
            $userId,
            $identity->chainSlug(),
            $identity->canonical(),
            $config->minBalance,
            HoldingsService::verificationBudget(HoldingsService::SURFACE_JOIN),
            true
        );
        if ($verdict->isUnknown()) {
            return JoinResult::verifyUnavailable($config->minBalance);
        }
        if (!$verdict->isEligible()) {
            return JoinResult::notEligible(
                $config->minBalance,
                $verdict->bestKnownBalance ?? 0
            );
        }

        // Honor the writer's verdict: false = PeepSo absent OR an existing
        // banned membership row (the writer refuses to flip a group-level
        // ban back to member). Surface the same transient fail-closed 503
        // the UNKNOWN verdict uses, and leave the opt-out untouched —
        // nothing was written, so nothing may be reported as joined.
        if (!\BCC\Core\PeepSo\PeepSoGroupWriter::join($userId, $groupId)) {
            return JoinResult::verifyUnavailable($config->minBalance);
        }
        $this->clearOptOut($userId, $groupId);

        return JoinResult::ok($config->minBalance);
    }

    /**
     * All gated groups the user qualifies for (excluding opt-outs and
     * groups they're already in). Used by `eligible_to_join[]` in the
     * holder-groups endpoint.
     *
     * Uses HoldingsService::ownsAnyMany so chain lookups + per-chain
     * wallet fetches are amortized across all gated groups. The
     * per-(wallet, contract) RPC count_holdings remains unavoidable.
     *
     * PR 7.14: provider reads are bounded by `$budget` — the reconcile budget
     * when omitted. A group the budget could not reach is not eligible.
     *
     * @return list<GatedGroupConfig>
     */
    public function findEligibleGroups(int $userId, ?ProviderRequestBudget $budget = null): array {
        if ($userId <= 0) {
            return [];
        }

        $configs = GatedGroupRepository::listAllGatedGroupConfigs();
        if ($configs === []) {
            return [];
        }

        // H7: the opt-out map from ONE trusted read for the whole pass.
        // Unreadable means no auto-join this pass.
        $optOuts = $this->readOptOutMap($userId);
        if ($optOuts === null) {
            return [];
        }

        $groupIds = array_map(static fn(GatedGroupConfig $c): int => $c->groupId, $configs);
        $alreadyJoined = \BCC\Core\Repositories\PeepSoGroupRepository::findUserMemberships(
            $userId,
            $groupIds
        );

        // PR 5b: resolve each gate's identity from its linked collection
        // row. A gate whose identity is unresolved is DROPPED here, so it
        // never reaches the batched holdings call — no provider is asked
        // about it at all.
        //
        // Dropping is the right outcome for this method specifically: it
        // answers "which groups do you qualify for", and a gate we cannot
        // evaluate is not one we can claim you qualify for. It feeds
        // auto-join, which must fail CLOSED. The join endpoint reports the
        // unresolved reason explicitly; a suggestion list stays quiet.
        $candidates = [];
        $pairs      = [];
        foreach ($configs as $cfg) {
            if (isset($alreadyJoined[$cfg->groupId])) {
                continue;
            }
            if ($this->optOutActiveIn($optOuts['map'], $userId, $cfg->groupId)) {
                continue;
            }
            $identity = GateIdentityResolver::resolve($cfg);
            if (!$identity->isResolved()) {
                continue;
            }
            $slug         = $identity->chainSlug();
            $canonical    = $identity->canonical();
            $candidates[] = [$cfg, $slug, $canonical];
            $pairs[]      = [$slug, $canonical, $cfg->minBalance];
        }

        if ($candidates === []) {
            return [];
        }

        $balances = HoldingsService::ownsAnyMany(
            $userId,
            $pairs,
            $budget ?? HoldingsService::verificationBudget(HoldingsService::SURFACE_RECONCILE)
        );

        $eligible = [];
        foreach ($candidates as [$cfg, $slug, $canonical]) {
            $key = $slug . ':' . $canonical;
            // Use array_key_exists, NOT `?? 0`: a present value of null is
            // UNKNOWN (provider couldn't verify), and `??` would silently
            // collapse that null to 0 — re-introducing the exact fail-open
            // bug Part 1 exists to fix. findEligibleGroups feeds auto-join
            // which fails CLOSED: an unverifiable wallet is NOT eligible.
            $balance = array_key_exists($key, $balances) ? $balances[$key] : 0;
            if ($balance !== null && $balance >= $cfg->minBalance) {
                $eligible[] = $cfg;
            }
        }

        return $eligible;
    }

    /**
     * Auto-join all eligible groups for a user — only runs if the user
     * has explicitly opted into hands-off auto-join via the
     * `bcc_auto_join_eligible_groups` user_meta preference.
     *
     * Default users are skipped (returns zeros). The reconcile sweep
     * cron uses this method; default users never auto-join, preserving
     * the suggest-don't-auto-join model.
     *
     * Uses the batched HoldingsService path inside findEligibleGroups,
     * so a user with N gated groups costs ~one holdings call per chain.
     *
     * @return array{joined: int, skipped: int}
     */
    public function reconcileForUser(int $userId): array {
        if ($userId <= 0 || !$this->autoJoinEnabled($userId)) {
            return ['joined' => 0, 'skipped' => 0];
        }

        // Suspension gate lives HERE (not only in the REST layer) because
        // this method is also reached by the cron reconcile sweep — without
        // it, a suspended holder with auto_join on would be silently
        // re-added to gated groups by the server itself. Admin bypass off:
        // a suspended account is blocked regardless of role. Parity with
        // MyGroupsEndpoint::postJoin [audit M — group-rejoin].
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ['joined' => 0, 'skipped' => 0];
        }

        $eligible = $this->findEligibleGroups($userId);
        if ($eligible === []) {
            return ['joined' => 0, 'skipped' => 0];
        }

        $joined = 0;
        foreach ($eligible as $cfg) {
            // Capture the writer return value so a PeepSo-absence false
            // doesn't generate a "joined" audit row when no real transition
            // happened. findEligibleGroups already excluded already-joined
            // members, so a true return here is a real state transition
            // (modulo a benign concurrent-writer race that PeepSo's UNIQUE
            // KEY collapses to a no-op).
            $ok = \BCC\Core\PeepSo\PeepSoGroupWriter::join($userId, $cfg->groupId);
            if (!$ok) {
                continue;
            }
            $this->clearOptOut($userId, $cfg->groupId);

            // Per-group audit row so admin queries can answer "why was
            // user X added to group Y" for reconcile-driven joins (both
            // the explicit user toggle and the cron sweep flow through
            // here). The aggregate holder_group_auto_reconciled row in
            // HolderGroupsEndpoint::patchPreferences remains as a
            // separate analytical signal — these two rows are
            // complementary, not duplicate. Action name matches the
            // explicit-join path so historical queries on
            // 'holder_group_join' return both flows; 'via' meta lets
            // admin segment.
            AuditLogger::log('holder_group_join', $cfg->groupId, [
                'code' => 'ok',
                'via'  => 'reconcile',
            ], 'group', $userId);

            $joined++;
        }
        return ['joined' => $joined, 'skipped' => 0];
    }

    public function autoJoinEnabled(int $userId): bool {
        if ($userId <= 0) {
            return false;
        }
        $val = get_user_meta($userId, self::USER_META_AUTO_JOIN, true);
        return $val === '1' || $val === 1 || $val === true;
    }

    public function setAutoJoinEnabled(int $userId, bool $enabled): void {
        if ($userId <= 0) {
            return;
        }
        if ($enabled) {
            update_user_meta($userId, self::USER_META_AUTO_JOIN, '1');
        } else {
            delete_user_meta($userId, self::USER_META_AUTO_JOIN);
        }
    }

    /**
     * Record a voluntary opt-out (timestamped, TTL applies on read).
     *
     * Never downgrades a PERMANENT opt-out (F-267). A moderator-removed
     * member who then pressed Leave used to overwrite the permanent marker
     * with time(), which made it a voluntary leave that an explicit Join
     * overrides: the removal was undone by Leave then Join.
     *
     * H7: the permanent check runs on the SAME trusted read as the write
     * (mutateOptOutMap), so nothing can change between them.
     */
    public function recordOptOut(int $userId, int $groupId): void {
        $now = time();
        $this->mutateOptOutMap($userId, $groupId, 'record_opt_out', static function (array $map) use ($groupId, $now): array {
            if (($map[$groupId] ?? null) === self::PERMANENT_OPT_OUT_TIMESTAMP) {
                return $map;
            }
            $map[$groupId] = $now;
            return $map;
        });
    }

    /**
     * Is the user BANNED from this group (a PeepSo gm_user_status =
     * 'banned' row)? A ban reads as a moderator removal everywhere a user
     * is offered Join (F-271): the join door refuses it anyway, and before
     * this the refusal surfaced as a misleading 503 "try again".
     *
     * Display and UX only. The ban itself is enforced in bcc-core's
     * PeepSoGroupWriter::join(), which also refuses on a failed read.
     */
    public function isBannedFrom(int $userId, int $groupId): bool {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }
        return \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus($userId, $groupId) === 'banned';
    }

    /**
     * The subset of $groupIds the user is banned from, as a set. One
     * bounded read (bcc-core findUserBannedGroupIds, F-266 — DEPLOY ORDER:
     * bcc-core #108 must be live first). A failed read is [] — no group is
     * shown as removed, still safe: the join door refuses the ban.
     *
     * @param list<int> $groupIds
     * @return array<int, true>
     */
    public function bannedGroupIdSet(int $userId, array $groupIds): array {
        if ($userId <= 0 || $groupIds === []) {
            return [];
        }
        $set = [];
        foreach (\BCC\Core\Repositories\PeepSoGroupRepository::findUserBannedGroupIds($userId, $groupIds) as $gid) {
            $set[(int) $gid] = true;
        }
        return $set;
    }

    /**
     * Record a permanent opt-out (mod removals; never expires).
     *
     * H7: through mutateOptOutMap(), so it is only ever written over the
     * map it read. If the map cannot be read at all this eviction is not
     * recorded, and that is logged at ERROR.
     */
    public function recordPermanentOptOut(int $userId, int $groupId): void {
        $this->mutateOptOutMap($userId, $groupId, 'record_permanent_opt_out', static function (array $map) use ($groupId): array {
            $map[$groupId] = self::PERMANENT_OPT_OUT_TIMESTAMP;
            return $map;
        });
    }

    public function clearOptOut(int $userId, int $groupId): void {
        $this->mutateOptOutMap($userId, $groupId, 'clear_opt_out', static function (array $map) use ($groupId): array {
            unset($map[$groupId]);
            return $map;
        });
    }

    /**
     * H7: is there a PERMANENT opt-out (a moderator eviction) for this
     * group, from a TRUSTED read? null = the map could not be read. For the
     * JOIN gates (this class and ValidatorGroupGateService), which refuse
     * on null with the transient 503.
     */
    public function permanentOptOutState(int $userId, int $groupId): ?bool {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }
        $state = $this->readOptOutMap($userId);
        if ($state === null) {
            return null;
        }
        return ($state['map'][$groupId] ?? null) === self::PERMANENT_OPT_OUT_TIMESTAMP;
    }

    /**
     * TRUE only for a PERMANENT opt-out, i.e. a moderator eviction
     * (recordPermanentOptOut). A voluntary, TTL'd opt-out is FALSE here
     * even while active: it blocks automatic re-adding, not an explicit
     * Join (F-158).
     *
     * ⚠⚠ H7: DISPLAY ONLY (the holder and delegator listings' opt_out_kind).
     * It reads through WordPress's meta cache, which answers a failed read
     * as "no opt-out", and the listings call it once per group, so a trusted
     * (uncached) read here would cost a query per group. A gate must use
     * permanentOptOutState(), which can say "unknown".
     */
    public function isPermanentOptOut(int $userId, int $groupId): bool {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }
        $map = self::decodeOptOutMap((string) get_user_meta($userId, self::USER_META_OPT_OUT, true));
        return isset($map[$groupId]) && $map[$groupId] === self::PERMANENT_OPT_OUT_TIMESTAMP;
    }

    /**
     * Is any opt-out active for this group?
     *
     * ⚠⚠ H7: DISPLAY ONLY, for the same reason as isPermanentOptOut(). The
     * auto-join pass (findEligibleGroups) reads the map once through the
     * trusted read instead.
     */
    public function isOptOutActive(int $userId, int $groupId): bool {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }
        $map = self::decodeOptOutMap((string) get_user_meta($userId, self::USER_META_OPT_OUT, true));
        return $this->optOutActiveIn($map, $userId, $groupId);
    }

    /**
     * The TTL rule over an already-read map.
     *
     * @param array<int, int> $map
     */
    private function optOutActiveIn(array $map, int $userId, int $groupId): bool {
        if (!isset($map[$groupId])) {
            return false;
        }
        $timestamp = $map[$groupId];
        if ($timestamp === self::PERMANENT_OPT_OUT_TIMESTAMP) {
            return true;
        }

        $ttl = (int) apply_filters('bcc_gated_group_optout_ttl', 90 * DAY_IN_SECONDS);
        $active = (time() - $timestamp) < $ttl;

        // Allow override by trust-tier-based or other future heuristics.
        return (bool) apply_filters(
            'bcc_gated_group_optout_active',
            $active,
            $userId,
            $groupId,
            $timestamp
        );
    }

    /** Writes tried at most per change, each from a fresh read. */
    private const OPT_OUT_WRITE_ATTEMPTS = 3;

    /**
     * H7: change the opt-out map without ever writing over one that was not
     * read.
     *
     * The map is ONE user-meta value holding every group's opt-out, so each
     * change is a read-modify-write, and get_user_meta() answers a failed
     * read as '' (no map). So:
     *   - the read is TRUSTED and FRESH (bcc-core
     *     UserMetaRepository::firstValuesOrUnknown(…, true): null when it
     *     failed, including wpdb's pre-flush bail with stale rows; never the
     *     request memo), and a failed read writes NOTHING;
     *   - the write is a compare-and-swap on the exact raw value read
     *     (UserMetaSwapRepository: a guarded INSERT / UPDATE / DELETE, not
     *     update_user_meta() with a previous value, which is no CAS), so a
     *     change another request made between our read and our write is
     *     never overwritten: the swap matches nothing, and we read again;
     *   - each attempt starts from a fresh read, at most
     *     OPT_OUT_WRITE_ATTEMPTS. Giving up is logged at ERROR and leaves
     *     the stored map exactly as it was.
     *
     * @param callable(array<int, int>): array<int, int> $change
     */
    private function mutateOptOutMap(int $userId, int $groupId, string $operation, callable $change): bool {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }

        $failedReads = 0;
        for ($attempt = 0; $attempt < self::OPT_OUT_WRITE_ATTEMPTS; $attempt++) {
            $state = $this->readOptOutMap($userId);
            if ($state === null) {
                $failedReads++;
                continue;
            }

            $next = $change($state['map']);
            if ($next === $state['map']) {
                return true;
            }
            if (self::swapOptOutMap($userId, $state['raw'], $next)) {
                return true;
            }
        }

        \BCC\Core\Log\Logger::error('[NftGroupGateService] opt-out map not changed: it could not be read, or kept changing underneath', [
            'operation'    => $operation,
            'user_id'      => $userId,
            'group_id'     => $groupId,
            'attempts'     => self::OPT_OUT_WRITE_ATTEMPTS,
            'failed_reads' => $failedReads,
        ]);
        return false;
    }

    /**
     * Replace the stored map, but only if it is still exactly $expectedRaw
     * (null = there was no row). An empty map removes the key.
     *
     * @param array<int, int> $next
     */
    private static function swapOptOutMap(int $userId, ?string $expectedRaw, array $next): bool {
        return UserMetaSwapRepository::swap(
            $userId,
            self::USER_META_OPT_OUT,
            $expectedRaw,
            $next === [] ? null : (string) wp_json_encode($next)
        );
    }

    /**
     * H7: the opt-out map from a TRUSTED, FRESH read (never the request
     * memo), or null when it could not be read. raw is the stored string
     * exactly; '' may be an empty or a SQL-NULL value, which the swap
     * treats alike.
     *
     * ⚠ No per-instance memo (#409 / F-158). Since F-158 the join gate refuses
     * only a PERMANENT opt-out (a moderator eviction). An instance that had
     * memoized the map while the member had merely left would go on reading
     * "left" after another instance recorded the eviction, and would let the
     * evicted member rejoin.
     *
     * @return array{raw: string|null, map: array<int, int>}|null raw = the stored value as read (null = no row)
     */
    private function readOptOutMap(int $userId): ?array {
        $stored = UserMetaRepository::firstValuesOrUnknown([$userId], [self::USER_META_OPT_OUT], true);
        if ($stored === null) {
            return null;
        }
        $raw = $stored[$userId][self::USER_META_OPT_OUT] ?? null;

        return ['raw' => $raw, 'map' => self::decodeOptOutMap($raw ?? '')];
    }

    /**
     * @return array<int, int>
     */
    private static function decodeOptOutMap(string $raw): array {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            $gid = (int) $key;
            $ts  = (int) $value;
            if ($gid > 0) {
                $map[$gid] = $ts;
            }
        }
        return $map;
    }
}
