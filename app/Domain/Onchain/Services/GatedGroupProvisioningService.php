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
            $chainId     = (int) $row->chain_id;
            $chainFamily = (string) ($row->chain_type ?? '');
            $name        = (string) ($row->collection_name ?? '');
            $colId       = (int) $row->id;

            // PR 5b: the gate is built on the collection's CANONICAL
            // identity, not on `contract_address` (which stays the legacy
            // display alias). This used to be
            // `strtolower($row->contract_address)`.
            $canonical = $row->canonical_identifier ?? null;

            if ($chainId <= 0 || $chainFamily === '') {
                $skipped++;
                continue;
            }

            // An unresolved identity is skipped BEFORE any PeepSo group is
            // created. Order is load-bearing: creating the community first
            // and then refusing the gate would leave an orphan community
            // with no gate config — worse than not provisioning at all.
            //
            // Logged, deliberately WITHOUT a DegradationMetrics event:
            // bcc-core's canonical `gated_group_provision` map declares
            // exactly three (peepso_absent, no_admin_owner,
            // group_create_failed) and none of them means this. Adding a
            // fourth would need a bcc-core change plus pattern-registry.md
            // and GOLDEN_PATHS.md, and subsystem-count-guard.php would hold
            // umbrella CI red until all three landed. Out of scope here.
            if (!is_string($canonical) || $canonical === '') {
                Logger::warning(
                    '[bcc-trust] skipping holder-group provisioning: collection has no canonical identity',
                    [
                        'collection_id' => $colId,
                        'chain_id'      => $chainId,
                        'chain_family'  => $chainFamily,
                    ]
                );
                $skipped++;
                continue;
            }

            if ($name === '') {
                // Skip collections still awaiting metadata enrichment.
                $skipped++;
                continue;
            }

            $existing = GatedGroupRepository::findGroupForCollection($chainId, $canonical);
            if ($existing !== null) {
                $skipped++;
                continue;
            }

            $groupId = $this->createPeepSoGroup($ownerId, $name);
            if ($groupId === 0) {
                $errors[] = sprintf(
                    'PeepSoGroup creation returned 0 for collection %d',
                    $colId
                );
                // Covers both `new PeepSoGroup` returning a 0-id group
                // and the catch-block path inside createPeepSoGroup
                // (PeepSoGroup constructor threw). Either way the
                // collection stays unprovisioned and the sweep will
                // retry next tick.
                \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
                continue;
            }

            $wrote = GatedGroupRepository::writeGateConfig(
                $groupId,
                $chainId,
                $chainFamily,
                $canonical,
                self::DEFAULT_MIN_BALANCE,
                $colId
            );

            if (!$wrote) {
                // The pre-check above already passed, so reaching here means
                // the stored canonical identity does not validate for its own
                // chain — a corrupted row, not an expected legacy one. Report
                // it rather than leaving a silently ungated community.
                $errors[] = sprintf(
                    'Gate config refused for collection %d; community %d has no gate',
                    $colId,
                    $groupId
                );
                continue;
            }

            Logger::info('[bcc-trust] Provisioned holder group', [
                'group_id'      => $groupId,
                'collection_id' => $colId,
                'chain_id'      => $chainId,
                'contract'      => $canonical,
            ]);

            // Fan-out hook for downstream subscribers (admin emails,
            // member onboarding flows, analytics writers). Stable
            // signature: (groupId, collectionId, chainId, contractAddress).
            // PR 5b: the 4th arg is now the CANONICAL identity, byte-exact,
            // rather than a lower-cased `contract_address`. Same position,
            // same type — a more correct value.
            do_action('bcc_gated_group_provisioned', $groupId, $colId, $chainId, $canonical);

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

        $chainId     = (int) $collection->chain_id;
        $chainFamily = (string) ($collection->chain_type ?? '');
        $name        = (string) ($collection->collection_name ?? '');

        // PR 5b: gate on the CANONICAL identity, never on `contract_address`
        // (the legacy display alias). Was `strtolower($contract_address)`.
        $canonical = $collection->canonical_identifier ?? null;

        if ($chainId <= 0 || $chainFamily === '') {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Collection missing chain.'];
        }

        // Refused BEFORE any community is created — see provisionAll() for
        // why the ordering is load-bearing and why no degradation metric is
        // recorded here.
        if (!is_string($canonical) || $canonical === '') {
            Logger::warning(
                '[bcc-trust] refusing single provisioning: collection has no canonical identity',
                [
                    'collection_id' => $collectionId,
                    'chain_id'      => $chainId,
                    'chain_family'  => $chainFamily,
                ]
            );

            return [
                'status'   => 'skipped',
                'group_id' => 0,
                'message'  => 'Collection has no resolved on-chain identity yet; a gate built on it could never be satisfied.',
            ];
        }

        if ($name === '') {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Collection is still awaiting a name; cannot name the community.'];
        }

        $existing = GatedGroupRepository::findGroupForCollection($chainId, $canonical);
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

        $wrote = GatedGroupRepository::writeGateConfig(
            $groupId,
            $chainId,
            $chainFamily,
            $canonical,
            self::DEFAULT_MIN_BALANCE,
            $collectionId
        );

        if (!$wrote) {
            return [
                'status'   => 'error',
                'group_id' => $groupId,
                'message'  => 'Community was created but its gate config was refused; the stored identity does not validate for its chain.',
            ];
        }

        Logger::info('[bcc-trust] Provisioned holder group (single)', [
            'group_id'      => $groupId,
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
            'contract'      => $canonical,
        ]);

        do_action('bcc_gated_group_provisioned', $groupId, $collectionId, $chainId, $canonical);

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
