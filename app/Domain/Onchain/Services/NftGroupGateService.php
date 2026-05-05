<?php
/**
 * NFT-gated group orchestration.
 *
 * Soft gate, suggest-don't-auto-join model:
 *   - joinIfEligible(): explicit user-initiated join. Verifies eligibility,
 *     respects opt-out, calls PeepSoGroupWriter::join.
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

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
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
     * Per-request memo of decoded opt-out maps, keyed by user_id.
     *
     * @var array<int, array<int, int>>
     */
    private array $optOutCache = [];

    /**
     * Verify eligibility, respect opt-out, and join via PeepSoGroupWriter.
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

        if ($this->isOptOutActive($userId, $groupId)) {
            return JoinResult::optOutActive($config->minBalance);
        }

        $memberships = \BCC\Core\Repositories\PeepSoGroupRepository::findUserMemberships(
            $userId,
            [$groupId]
        );
        if (isset($memberships[$groupId])) {
            return JoinResult::alreadyMember($config->minBalance);
        }

        $chain = ChainRepository::getById($config->chainId);
        if ($chain === null) {
            return JoinResult::chainUnsupported($config->minBalance);
        }

        $balance = HoldingsService::ownsAny(
            $userId,
            (string) $chain->slug,
            $config->contractAddress
        );
        if ($balance < $config->minBalance) {
            return JoinResult::notEligible($config->minBalance, $balance);
        }

        \BCC\Core\PeepSo\PeepSoGroupWriter::join($userId, $groupId);
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
     * @return list<GatedGroupConfig>
     */
    public function findEligibleGroups(int $userId): array {
        if ($userId <= 0) {
            return [];
        }

        $configs = GatedGroupRepository::listAllGatedGroupConfigs();
        if ($configs === []) {
            return [];
        }

        $groupIds = array_map(static fn(GatedGroupConfig $c): int => $c->groupId, $configs);
        $alreadyJoined = \BCC\Core\Repositories\PeepSoGroupRepository::findUserMemberships(
            $userId,
            $groupIds
        );

        // Resolve chain slug for each config (skip unsupported chains
        // upfront so we don't ask HoldingsService for unknown chains).
        $candidates = [];
        $pairs      = [];
        foreach ($configs as $cfg) {
            if (isset($alreadyJoined[$cfg->groupId])) {
                continue;
            }
            if ($this->isOptOutActive($userId, $cfg->groupId)) {
                continue;
            }
            $chain = ChainRepository::getById($cfg->chainId);
            if ($chain === null) {
                continue;
            }
            $slug = (string) $chain->slug;
            $candidates[] = [$cfg, $slug];
            $pairs[]      = [$slug, $cfg->contractAddress];
        }

        if ($candidates === []) {
            return [];
        }

        $balances = HoldingsService::ownsAnyMany($userId, $pairs);

        $eligible = [];
        foreach ($candidates as [$cfg, $slug]) {
            $key = $slug . ':' . $cfg->contractAddress;
            $balance = $balances[$key] ?? 0;
            if ($balance >= $cfg->minBalance) {
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

        $eligible = $this->findEligibleGroups($userId);
        if ($eligible === []) {
            return ['joined' => 0, 'skipped' => 0];
        }

        $joined = 0;
        foreach ($eligible as $cfg) {
            \BCC\Core\PeepSo\PeepSoGroupWriter::join($userId, $cfg->groupId);
            $this->clearOptOut($userId, $cfg->groupId);
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
     */
    public function recordOptOut(int $userId, int $groupId): void {
        $this->writeOptOutTimestamp($userId, $groupId, time());
    }

    /**
     * Record a permanent opt-out (mod removals; never expires).
     */
    public function recordPermanentOptOut(int $userId, int $groupId): void {
        $this->writeOptOutTimestamp($userId, $groupId, self::PERMANENT_OPT_OUT_TIMESTAMP);
    }

    public function clearOptOut(int $userId, int $groupId): void {
        if ($userId <= 0 || $groupId <= 0) {
            return;
        }
        $map = $this->loadOptOutMap($userId);
        if (!isset($map[$groupId])) {
            return;
        }
        unset($map[$groupId]);
        $this->persistOptOutMap($userId, $map);
    }

    public function isOptOutActive(int $userId, int $groupId): bool {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }
        $map = $this->loadOptOutMap($userId);
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

    private function writeOptOutTimestamp(int $userId, int $groupId, int $timestamp): void {
        if ($userId <= 0 || $groupId <= 0) {
            return;
        }
        $map = $this->loadOptOutMap($userId);
        $map[$groupId] = $timestamp;
        $this->persistOptOutMap($userId, $map);
    }

    /**
     * @return array<int, int>
     */
    private function loadOptOutMap(int $userId): array {
        if (isset($this->optOutCache[$userId])) {
            return $this->optOutCache[$userId];
        }

        $raw = (string) get_user_meta($userId, self::USER_META_OPT_OUT, true);
        if ($raw === '') {
            return $this->optOutCache[$userId] = [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->optOutCache[$userId] = [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            $gid = (int) $key;
            $ts  = (int) $value;
            if ($gid > 0) {
                $map[$gid] = $ts;
            }
        }
        return $this->optOutCache[$userId] = $map;
    }

    /**
     * @param array<int, int> $map
     */
    private function persistOptOutMap(int $userId, array $map): void {
        if ($map === []) {
            delete_user_meta($userId, self::USER_META_OPT_OUT);
            $this->optOutCache[$userId] = [];
            return;
        }
        update_user_meta(
            $userId,
            self::USER_META_OPT_OUT,
            wp_json_encode($map)
        );
        $this->optOutCache[$userId] = $map;
    }
}
