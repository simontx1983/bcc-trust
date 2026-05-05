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
            return ['created' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        $ownerId = $this->resolveOwnerId();
        if ($ownerId === 0) {
            $errors[] = 'No administrator user found to own auto-provisioned groups.';
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
