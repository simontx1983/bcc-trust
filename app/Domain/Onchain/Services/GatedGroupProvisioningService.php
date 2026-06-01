<?php
/**
 * Holder-group provisioning sweep.
 *
 * Scans `wp_bcc_onchain_collections WHERE is_verified = 1` and creates
 * a closed PeepSo group for any collection that doesn't have one yet.
 * Idempotent — re-running creates no duplicates (existing groups are
 * detected via post-meta lookup).
 *
 * Group creation goes through PeepSoGroup's constructor so PeepSo's
 * member_owner assignment, peepso_action_group_create hook, and
 * default-meta seeding all run. Bypassing that path with
 * wp_insert_post() leaves orphaned groups.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class GatedGroupProvisioningService {

    private const DEFAULT_MIN_BALANCE = 1;
    private const TITLE_PREFIX        = 'Holders: ';

    /**
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function provisionAll(int $limit = 200): array {
        $created  = 0;
        $skipped  = 0;
        $errors   = [];

        if (!class_exists('\\PeepSoGroup')) {
            $errors[] = 'PeepSoGroup class not available; PeepSo Groups inactive?';
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return ['created' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        $ownerId = $this->resolveOwnerId();
        if ($ownerId === 0) {
            $errors[] = 'No administrator user found to own auto-provisioned groups.';
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'no_admin_owner');
            return ['created' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        $verified = CollectionRepository::listVerified($limit);
        if ($verified === []) {
            return ['created' => 0, 'skipped' => 0, 'errors' => []];
        }

        foreach ($verified as $row) {
            $chainId  = (int) $row->chain_id;
            $contract = strtolower((string) $row->contract_address);
            $name     = (string) ($row->collection_name ?? '');
            $colId    = (int) $row->id;

            if ($chainId <= 0 || $contract === '') {
                $skipped++;
                continue;
            }
            if ($name === '') {
                // Skip collections still awaiting metadata enrichment.
                $skipped++;
                continue;
            }

            $existing = GatedGroupRepository::findGroupForCollection($chainId, $contract);
            if ($existing !== null) {
                $skipped++;
                continue;
            }

            $groupId = $this->createPeepSoGroup($ownerId, $name);
            if ($groupId === 0) {
                $errors[] = sprintf(
                    'PeepSoGroup creation returned 0 for collection %d (%s)',
                    $colId,
                    $contract
                );
                // Covers both `new PeepSoGroup` returning a 0-id group
                // and the catch-block path inside createPeepSoGroup
                // (PeepSoGroup constructor threw). Either way the
                // collection stays unprovisioned and the sweep will
                // retry next tick.
                \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
                continue;
            }

            GatedGroupRepository::writeGateConfig(
                $groupId,
                $chainId,
                $contract,
                self::DEFAULT_MIN_BALANCE,
                $colId
            );

            Logger::info('[bcc-trust] Provisioned holder group', [
                'group_id'      => $groupId,
                'collection_id' => $colId,
                'chain_id'      => $chainId,
                'contract'      => $contract,
            ]);

            // Fan-out hook for downstream subscribers (admin emails,
            // member onboarding flows, analytics writers). Stable
            // signature: (groupId, collectionId, chainId, contractAddress).
            do_action('bcc_gated_group_provisioned', $groupId, $colId, $chainId, $contract);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Provision the holder community for a single collection by id.
     * Powers the per-row "Create now" action on the admin page. Shares
     * the exact create + gate-config path as provisionAll() so a row
     * created here is identical to one the daily sweep would create.
     *
     * @return array{status: string, group_id: int, message: string}
     *         status ∈ {created, exists, skipped, error}.
     */
    public function provisionOne(int $collectionId): array {
        if (!class_exists('\\PeepSoGroup')) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return ['status' => 'error', 'group_id' => 0, 'message' => 'PeepSo Groups inactive.'];
        }

        $collection = CollectionRepository::getByIdWithChain($collectionId);
        if ($collection === null) {
            return ['status' => 'error', 'group_id' => 0, 'message' => 'Collection not found.'];
        }

        if ((int) $collection->is_verified !== 1) {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Collection is not verified.'];
        }

        $chainId  = (int) $collection->chain_id;
        $contract = strtolower((string) $collection->contract_address);
        $name     = (string) ($collection->collection_name ?? '');

        if ($chainId <= 0 || $contract === '') {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Collection missing chain or contract.'];
        }
        if ($name === '') {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Collection is still awaiting a name; cannot name the community.'];
        }

        $existing = GatedGroupRepository::findGroupForCollection($chainId, $contract);
        if ($existing !== null) {
            return ['status' => 'exists', 'group_id' => (int) $existing, 'message' => 'Community already exists.'];
        }

        $ownerId = $this->resolveOwnerId();
        if ($ownerId === 0) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'no_admin_owner');
            return ['status' => 'error', 'group_id' => 0, 'message' => 'No administrator user to own the community.'];
        }

        $groupId = $this->createPeepSoGroup($ownerId, $name);
        if ($groupId === 0) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
            return ['status' => 'error', 'group_id' => 0, 'message' => 'Community creation failed; see the bcc-trust error log.'];
        }

        GatedGroupRepository::writeGateConfig(
            $groupId,
            $chainId,
            $contract,
            self::DEFAULT_MIN_BALANCE,
            $collectionId
        );

        Logger::info('[bcc-trust] Provisioned holder group (single)', [
            'group_id'      => $groupId,
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
            'contract'      => $contract,
        ]);

        do_action('bcc_gated_group_provisioned', $groupId, $collectionId, $chainId, $contract);

        return ['status' => 'created', 'group_id' => $groupId, 'message' => 'Community created.'];
    }

    /**
     * Find the user who'll own auto-provisioned groups. First admin
     * (lowest ID) is the canonical choice.
     */
    private function resolveOwnerId(): int {
        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => 'ID',
        ]);
        if (!is_array($admins) || $admins === []) {
            return 0;
        }
        return (int) $admins[0];
    }

    /**
     * Returns 0 on failure. Going through PeepSoGroup's constructor
     * ensures member_owner assignment + peepso_action_group_create.
     */
    private function createPeepSoGroup(int $ownerId, string $collectionName): int {
        $title = self::TITLE_PREFIX . $collectionName;

        $data = [
            'owner_id'    => $ownerId,
            'name'        => $title,
            'description' => sprintf(
                'On-chain verified holders of %s. Auto-managed.',
                $collectionName
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
            Logger::error('[bcc-trust] PeepSoGroup constructor threw', [
                'collection' => $collectionName,
                'error'      => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
