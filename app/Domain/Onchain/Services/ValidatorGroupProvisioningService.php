<?php
/**
 * Validator/delegator community provisioning.
 *
 * Mirror of {@see GatedGroupProvisioningService} (the NFT holder-group
 * sweep) for validator communities: one auto-provisioned closed PeepSo
 * group per CLAIMED validator (verified operator claim), titled
 * `Delegators: {moniker}`, OWNED BY THE OPERATOR (moderation has an
 * owner; owners are exempt from the gate + revoke by existing guards).
 *
 * Two entry points, both idempotent:
 *   - provisionForClaim() — fired from the `bcc_page_claimed` event
 *     (entityType='validator', role='operator'); the claim is the
 *     arming act (wallet-signature proven), no delegator-count floor.
 *   - provisionAll()      — daily backfill folded into the EXISTING
 *     `bcc_gated_group_provision` cron handler; walks verified
 *     validator-operator claims so claims that predate this feature
 *     (or an event miss) still get their community.
 *
 * V1 cut: non-Cosmos chains are skipped (the join gate requires
 * chain_type='cosmos'); claim revocation does NOT delete the community.
 *
 * Group creation goes through PeepSoGroup's constructor so PeepSo's
 * member_owner assignment, peepso_action_group_create hook, and
 * default-meta seeding all run (same rationale as the NFT sweep).
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\ValidatorGroupRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorGroupProvisioningService {

    private const TITLE_PREFIX = 'Delegators: ';

    /** Default min-stake gate in display units — dust-attack gate. */
    private const DEFAULT_MIN_STAKE = '1';

    /**
     * Backfill sweep: provision communities for every verified
     * validator-operator claim that doesn't have one yet. Bounded by
     * $limit claims per tick; idempotent — re-running creates no
     * duplicates.
     *
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function provisionAll(int $limit = 200): array {
        $created = 0;
        $skipped = 0;
        $errors  = [];

        $claims = ClaimRepository::listVerifiedValidatorOperatorClaims($limit);
        if ($claims === []) {
            return ['created' => 0, 'skipped' => 0, 'errors' => []];
        }

        foreach ($claims as $claim) {
            $result = $this->provisionForClaim((int) $claim->user_id, (int) $claim->entity_id);
            if ($result['status'] === 'created') {
                $created++;
                continue;
            }
            if ($result['status'] === 'error') {
                $errors[] = $result['message'];
                continue;
            }
            // exists / skipped
            $skipped++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Provision the delegator community for one verified operator claim.
     * Shared by the bcc_page_claimed subscriber, the backfill sweep, and
     * the admin provision-now action — a community created by any path is
     * identical.
     *
     * @param int $operatorUserId The claiming operator (becomes group owner).
     * @param int $validatorId    FK into wp_bcc_onchain_validators.id.
     * @return array{status: string, group_id: int, message: string}
     *         status ∈ {created, exists, skipped, error}.
     */
    public function provisionForClaim(int $operatorUserId, int $validatorId): array {
        if ($operatorUserId <= 0 || $validatorId <= 0) {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Invalid operator or validator id.'];
        }

        if (!class_exists('\\PeepSoGroup')) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return ['status' => 'error', 'group_id' => 0, 'message' => 'PeepSo Groups inactive.'];
        }

        $validator = ValidatorRepository::getByIdWithChain($validatorId);
        if ($validator === null) {
            return ['status' => 'error', 'group_id' => 0, 'message' => sprintf('Validator %d not found.', $validatorId)];
        }

        // V1 cut: the delegation gate requires a Cosmos LCD, so only
        // cosmos-type chains get a community. Others are skipped, not
        // errored — the claim itself is fine.
        if ((string) $validator->chain_type !== 'cosmos') {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Non-Cosmos chain; delegator communities are Cosmos-only in V1.'];
        }

        $moniker = trim((string) ($validator->moniker ?? ''));
        if ($moniker === '') {
            // Skip until enrichment supplies a moniker — the backfill
            // sweep retries next tick.
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Validator has no moniker yet; cannot name the community.'];
        }

        $chainId  = (int) $validator->chain_id;
        $operator = strtolower((string) $validator->operator_address);
        if ($chainId <= 0 || $operator === '') {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Validator missing chain or operator address.'];
        }

        $existing = ValidatorGroupRepository::findGroupForValidator($chainId, $validatorId);
        if ($existing !== null) {
            return ['status' => 'exists', 'group_id' => $existing, 'message' => 'Community already exists.'];
        }

        // The claiming operator must still be a real user — a deleted
        // account can't own a group.
        if (!get_userdata($operatorUserId)) {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => sprintf('Operator user %d no longer exists.', $operatorUserId)];
        }

        $groupId = $this->createPeepSoGroup($operatorUserId, $moniker);
        if ($groupId === 0) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
            return ['status' => 'error', 'group_id' => 0, 'message' => sprintf('PeepSoGroup creation returned 0 for validator %d (%s).', $validatorId, $operator)];
        }

        /**
         * Dust-attack gate: minimum delegated stake in display units.
         * Filterable so a chain-specific threshold can be tuned without
         * a release; positional args (validatorId, chainId).
         *
         * @var string $minStake
         */
        $minStake = apply_filters(
            'bcc_validator_group_min_stake',
            self::DEFAULT_MIN_STAKE,
            $validatorId,
            $chainId
        );

        ValidatorGroupRepository::writeGateConfig(
            $groupId,
            $chainId,
            $validatorId,
            $operator,
            (string) $minStake
        );

        Logger::info('[bcc-trust] Provisioned delegator community', [
            'group_id'     => $groupId,
            'validator_id' => $validatorId,
            'chain_id'     => $chainId,
            'operator'     => $operator,
            'owner_id'     => $operatorUserId,
        ]);

        return ['status' => 'created', 'group_id' => $groupId, 'message' => 'Community created.'];
    }

    /**
     * Returns 0 on failure. Going through PeepSoGroup's constructor
     * ensures member_owner assignment + peepso_action_group_create.
     * Deliberate mirror of GatedGroupProvisioningService::createPeepSoGroup
     * (private there; the title/description/owner semantics differ).
     */
    private function createPeepSoGroup(int $ownerId, string $moniker): int {
        $title = self::TITLE_PREFIX . $moniker;

        $data = [
            'owner_id'    => $ownerId,
            'name'        => $title,
            'description' => sprintf(
                'Delegation-verified community for %s delegators. Auto-managed.',
                $moniker
            ),
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
                'moniker' => $moniker,
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
