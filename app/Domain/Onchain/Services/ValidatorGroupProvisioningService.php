<?php
/**
 * Validator/delegator community creation and its minimum delegation.
 *
 * Mirror of {@see GatedGroupProvisioningService} (the NFT holder-group sweep)
 * in what it creates: a closed PeepSo group titled `Delegators: {moniker}`,
 * OWNED BY THE OPERATOR (moderation has an owner; owners are exempt from the
 * gate + revoke by existing guards).
 *
 * Different in what starts it. A community exists because the page's operator
 * asked for one, through {@see \BCC\Trust\Onchain\REST\PageDelegatorGroupEndpoint},
 * and chose the minimum there. No claim event, cron tick or admin action calls
 * this class; `DelegatorGroupNotAutomaticTest` holds that in place.
 *
 * One community per validator PAGE. The gate still verifies one validator, so
 * on a page bound to several the operator picks which at creation. Both writes
 * here run under a per-page advisory lock: the "does one exist" read and the
 * create have to be one step, and an old→new audit needs the old value to still
 * be current when the new one lands.
 *
 * Authorization is the caller's job and is not repeated here.
 *
 * Group creation goes through PeepSoGroup's constructor so PeepSo's
 * member_owner assignment, peepso_action_group_create hook, and
 * default-meta seeding all run (same rationale as the NFT sweep).
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\ValidatorGroupRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorGroupProvisioningService {

    public const STATUS_CREATED   = 'created';
    public const STATUS_EXISTS    = 'exists';
    public const STATUS_UPDATED   = 'updated';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_NO_GROUP  = 'no_group';
    public const STATUS_BUSY      = 'busy';
    public const STATUS_ERROR     = 'error';

    private const TITLE_PREFIX = 'Delegators: ';

    /** Whole-token ceiling on a minimum. */
    private const MIN_STAKE_CEILING = '1000000000';

    /**
     * Seconds a second request waits for the page lock. Long enough to outlast
     * a group create, so the loser of a double-click gets the 409 naming the
     * group the winner just made rather than a retry prompt.
     */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * A minimum as we store it, or null when it isn't one we accept: a plain
     * positive decimal string of at most 6 decimal places, not above the ceiling.
     * Six is not arbitrary: `CosmosFetcher::tokensToDisplay()` reports stake at
     * 6 places, so a finer minimum could never be compared honestly.
     *
     * The comparison is on the digits, never a float, so the ceiling is exact
     * and `1000000000.000001` can't round its way under it. Trailing fractional
     * zeros are dropped so `1.50` and `1.5` are the same stored value.
     *
     * @param mixed $raw Anything a request body can carry.
     */
    public static function parseMinStake($raw): ?string {
        // \z, not $: `$` would accept a trailing newline.
        if (!is_string($raw) || preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?\z/', $raw, $m) !== 1) {
            return null;
        }

        $whole    = $m[1];
        $fraction = rtrim($m[2] ?? '', '0');

        if ($whole === '0' && $fraction === '') {
            return null;
        }

        $ceiling = self::MIN_STAKE_CEILING;
        if (strlen($whole) > strlen($ceiling)
            || (strlen($whole) === strlen($ceiling) && strcmp($whole, $ceiling) > 0)
            || ($whole === $ceiling && $fraction !== '')
        ) {
            return null;
        }

        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    /**
     * Create the page's community unless it already has one.
     *
     * @param int    $operatorUserId The acting operator (becomes group owner).
     * @param int    $pageId         The validator page.
     * @param int    $validatorId    A validator bound to that page.
     * @param string $minStake       Output of {@see parseMinStake()}.
     * @return array{status: string, group_id: int}
     *         status ∈ {created, exists, busy, error}.
     */
    public function createForPage(int $operatorUserId, int $pageId, int $validatorId, string $minStake): array {
        if (!class_exists('\\PeepSoGroup')) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return ['status' => self::STATUS_ERROR, 'group_id' => 0];
        }

        $lock = self::lockName($pageId);
        if (!self::acquire($lock)) {
            return ['status' => self::STATUS_BUSY, 'group_id' => 0];
        }

        // ⚠ EXACTLY ONE RELEASE FOR THE ONE ACQUIRE ABOVE. GET_LOCK is
        // reentrant per session, so an unmatched pair leaks the lock for the
        // rest of the request. The `finally` covers every return path below.
        try {
            $existing = self::groupIdForPage($pageId);
            if ($existing !== null) {
                return ['status' => self::STATUS_EXISTS, 'group_id' => $existing];
            }

            return $this->create($operatorUserId, $pageId, $validatorId, $minStake);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * Change the minimum on the page's community. Takes effect on the next join
     * and the next sweep; nothing is removed here.
     *
     * @param string $minStake Output of {@see parseMinStake()}.
     * @return array{status: string, group_id: int}
     *         status ∈ {updated, unchanged, no_group, busy, error}.
     */
    public function updateMinStake(int $actorUserId, int $pageId, string $minStake): array {
        $lock = self::lockName($pageId);
        if (!self::acquire($lock)) {
            return ['status' => self::STATUS_BUSY, 'group_id' => 0];
        }

        try {
            $groupId = self::groupIdForPage($pageId);
            if ($groupId === null) {
                return ['status' => self::STATUS_NO_GROUP, 'group_id' => 0];
            }

            $stored  = ValidatorGroupRepository::readMinStake($groupId);
            $current = self::parseMinStake($stored) ?? $stored;
            if ($current === $minStake) {
                return ['status' => self::STATUS_UNCHANGED, 'group_id' => $groupId];
            }

            if (!ValidatorGroupRepository::writeMinStake($groupId, $minStake)) {
                Logger::error('[bcc-trust] delegator community minimum was not stored', [
                    'group_id' => $groupId,
                    'page_id'  => $pageId,
                ]);
                return ['status' => self::STATUS_ERROR, 'group_id' => $groupId];
            }

            AuditLogger::log('validator_group_min_stake_changed', $groupId, [
                'page_id'       => $pageId,
                'old_min_stake' => $current,
                'new_min_stake' => $minStake,
            ], 'group', $actorUserId);

            return ['status' => self::STATUS_UPDATED, 'group_id' => $groupId];
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * The page's community, across every validator bound to it.
     */
    private static function groupIdForPage(int $pageId): ?int {
        $ids = [];
        foreach (ValidatorRepository::findAllByPageId($pageId) as $row) {
            $ids[] = $row->validator_id;
        }

        $groups = ValidatorGroupRepository::findGroupIdsForValidators($ids);

        return $groups === [] ? null : min($groups);
    }

    /**
     * @return array{status: string, group_id: int}
     */
    private function create(int $operatorUserId, int $pageId, int $validatorId, string $minStake): array {
        $validator = ValidatorRepository::getByIdWithChain($validatorId);
        if ($validator === null) {
            return ['status' => self::STATUS_ERROR, 'group_id' => 0];
        }

        $chainId  = (int) $validator->chain_id;
        $operator = strtolower((string) $validator->operator_address);

        $name = trim((string) ($validator->moniker ?? ''));
        if ($name === '') {
            $post = get_post($pageId);
            $name = $post instanceof \WP_Post ? trim($post->post_title) : '';
        }

        if ($chainId <= 0 || $operator === '' || $name === '') {
            Logger::error('[bcc-trust] delegator community cannot be created from this validator row', [
                'validator_id' => $validatorId,
                'page_id'      => $pageId,
            ]);
            return ['status' => self::STATUS_ERROR, 'group_id' => 0];
        }

        $groupId = $this->createPeepSoGroup($operatorUserId, $name);
        if ($groupId === 0) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
            return ['status' => self::STATUS_ERROR, 'group_id' => 0];
        }

        ValidatorGroupRepository::writeGateConfig($groupId, $chainId, $validatorId, $operator, $minStake);

        // Without its gate this would be an ordinary closed group the exists
        // check can't see, so a retry would make a second. Prove the gate
        // landed or take the group back out.
        wp_cache_delete($groupId, 'post_meta');
        $config = ValidatorGroupRepository::getGateConfig($groupId);
        if ($config === null || !ValidatorGroupRepository::minStakeIsStored($groupId, $minStake)) {
            $this->undoCreate($groupId, $operatorUserId, $validatorId);
            return ['status' => self::STATUS_ERROR, 'group_id' => 0];
        }

        AuditLogger::log('validator_group_created', $groupId, [
            'page_id'      => $pageId,
            'validator_id' => $validatorId,
            'chain_id'     => $chainId,
            'min_stake'    => $minStake,
        ], 'group', $operatorUserId);

        Logger::info('[bcc-trust] Created delegator community', [
            'group_id'     => $groupId,
            'page_id'      => $pageId,
            'validator_id' => $validatorId,
            'chain_id'     => $chainId,
            'owner_id'     => $operatorUserId,
        ]);

        return ['status' => self::STATUS_CREATED, 'group_id' => $groupId];
    }

    /**
     * Take back a group whose gate didn't land. Same order and reasons as
     * GatedGroupProvisioningService::compensate, for the one failure this class
     * can reach: PeepSo's group delete leaves the member row behind.
     */
    private function undoCreate(int $groupId, int $ownerId, int $validatorId): void {
        ValidatorGroupRepository::deleteGateConfig($groupId);

        if (class_exists('\\PeepSoGroupUser')) {
            try {
                (new \PeepSoGroupUser($groupId, $ownerId))->member_leave();
            } catch (\Throwable $e) {
                Logger::warning('[bcc-trust] delegator community owner row not removed', [
                    'group_id' => $groupId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $deleted = wp_delete_post($groupId, true);

        \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
        Logger::error('[bcc-trust] delegator community gate did not land; group removed', [
            'group_id'     => $groupId,
            'validator_id' => $validatorId,
            'deleted'      => (bool) $deleted,
        ]);
    }

    private static function lockName(int $pageId): string {
        return 'bcc_delegator_group:' . $pageId;
    }

    /**
     * Fails CLOSED when bcc-core's AdvisoryLock primitive is unavailable: the
     * check-then-create never runs unlocked. NULL-vs-0 handling lives inside
     * AdvisoryLock::acquire; both are false here.
     */
    private static function acquire(string $lock): bool {
        return class_exists('\\BCC\\Core\\DB\\AdvisoryLock')
            && \BCC\Core\DB\AdvisoryLock::acquire($lock, self::LOCK_WAIT_SECONDS);
    }

    /**
     * Returns 0 on failure. Going through PeepSoGroup's constructor
     * ensures member_owner assignment + peepso_action_group_create.
     * Deliberate mirror of GatedGroupProvisioningService::createPeepSoGroup
     * (private there; the title/description/owner semantics differ).
     */
    private function createPeepSoGroup(int $ownerId, string $name): int {
        $data = [
            'owner_id'    => $ownerId,
            'name'        => self::TITLE_PREFIX . $name,
            'description' => sprintf('Delegation-verified community for %s delegators.', $name),
            'meta' => [
                'privacy'                     => 1, // PeepSoGroupPrivacy::PRIVACY_CLOSED
                'is_joinable'                 => true,
                'is_invitable'                => false,
                'is_readonly'                 => false,
                'is_auto_accept_join_request' => false,
            ],
        ];

        try {
            /** @phpstan-ignore-next-line — PeepSo classes are runtime-only. */
            $group = new \PeepSoGroup(null, $data);
            /** @phpstan-ignore-next-line */
            $id = (int) $group->get('id');
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] PeepSoGroup constructor threw (delegator community)', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
