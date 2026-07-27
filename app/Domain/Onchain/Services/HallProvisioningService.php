<?php
/**
 * Hall provisioning sweep.
 *
 * Iterates the active chain registry (ChainRepository::getActive) and
 * creates ONE open PeepSo group per chain that doesn't have a Hall yet:
 * "{Chain} Hall" (e.g. "Cosmos Hall"). Idempotent — re-running creates
 * no duplicates (existing Halls are detected via post-meta lookup).
 *
 * Halls are system-created civic spaces, the same shape as holder +
 * validator communities but OPEN (privacy = 0) and one-per-chain rather
 * than one-per-collection / one-per-validator. This replaced the retired
 * member-less numbered-chapter model.
 *
 * Group creation goes through PeepSoGroup's constructor so PeepSo's
 * member_owner assignment, peepso_action_group_create hook, and
 * default-meta seeding all run. Bypassing that path with
 * wp_insert_post() leaves orphaned groups. The provisioner NEVER lands a
 * third party as a member — it only creates the group (owner = first
 * admin) and writes the marker meta; joining is a member action gated by
 * HallsService.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HallRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class HallProvisioningService {

    /**
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function provisionAll(): array {
        $created = 0;
        $skipped = 0;
        $errors  = [];

        if (!class_exists('\\PeepSoGroup')) {
            $errors[] = 'PeepSoGroup class not available; PeepSo Groups inactive?';
            return ['created' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        $ownerId = $this->resolveOwnerId();
        if ($ownerId === 0) {
            $errors[] = 'No administrator user found to own auto-provisioned Halls.';
            return ['created' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        $chains = ChainRepository::getActive();
        if ($chains === []) {
            return ['created' => 0, 'skipped' => 0, 'errors' => []];
        }

        foreach ($chains as $chain) {
            $result = $this->provisionForChainRow($chain, $ownerId);
            if ($result === 'created') {
                $created++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } else {
                $errors[] = $result;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Provision the Hall for a single chain by id. Powers the per-chain
     * admin action. Shares the exact create + marker path as
     * provisionAll() so a Hall created here is identical to one the daily
     * sweep would create.
     *
     * @return array{status: string, group_id: int, message: string}
     *         status ∈ {created, exists, skipped, error}.
     */
    public function provisionOne(int $chainId): array {
        if (!class_exists('\\PeepSoGroup')) {
            return ['status' => 'error', 'group_id' => 0, 'message' => 'PeepSo Groups inactive.'];
        }
        if ($chainId <= 0) {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Invalid chain id.'];
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return ['status' => 'error', 'group_id' => 0, 'message' => 'Chain not found.'];
        }

        $name = trim((string) $chain->name);
        if ($name === '') {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Chain has no name; cannot name the Hall.'];
        }

        $existing = HallRepository::findHallForChain($chainId);
        if ($existing !== null) {
            return ['status' => 'exists', 'group_id' => (int) $existing, 'message' => 'Hall already exists.'];
        }

        $ownerId = $this->resolveOwnerId();
        if ($ownerId === 0) {
            return ['status' => 'error', 'group_id' => 0, 'message' => 'No administrator user to own the Hall.'];
        }

        $groupId = $this->createHallGroup($ownerId, $name);
        if ($groupId === 0) {
            return ['status' => 'error', 'group_id' => 0, 'message' => 'Hall creation failed; see the bcc-trust error log.'];
        }

        HallRepository::writeHallMeta($groupId, $chainId);

        Logger::info('[bcc-trust] Provisioned Hall (single)', [
            'group_id'   => $groupId,
            'chain_id'   => $chainId,
            'chain_slug' => (string) $chain->slug,
        ]);

        do_action('bcc_hall_provisioned', $groupId, $chainId, (string) $chain->slug);

        return ['status' => 'created', 'group_id' => $groupId, 'message' => 'Hall created.'];
    }

    /**
     * Provision one chain's Hall from a ChainRow. Returns 'created',
     * 'skipped', or an error string (the sweep buckets by return value).
     *
     * @param object{id: string, slug: string, name: string} $chain
     */
    private function provisionForChainRow(object $chain, int $ownerId): string {
        $chainId = (int) $chain->id;
        $name    = trim((string) $chain->name);

        if ($chainId <= 0) {
            return 'skipped';
        }
        if ($name === '') {
            // Skip chains still awaiting a display name.
            return 'skipped';
        }

        $existing = HallRepository::findHallForChain($chainId);
        if ($existing !== null) {
            return 'skipped';
        }

        $groupId = $this->createHallGroup($ownerId, $name);
        if ($groupId === 0) {
            return sprintf('PeepSoGroup creation returned 0 for chain %d (%s)', $chainId, (string) $chain->slug);
        }

        HallRepository::writeHallMeta($groupId, $chainId);

        Logger::info('[bcc-trust] Provisioned Hall', [
            'group_id'   => $groupId,
            'chain_id'   => $chainId,
            'chain_slug' => (string) $chain->slug,
        ]);

        // Fan-out hook for downstream subscribers. Stable signature:
        // (groupId, chainId, chainSlug).
        do_action('bcc_hall_provisioned', $groupId, $chainId, (string) $chain->slug);

        return 'created';
    }

    /**
     * Find the user who'll own auto-provisioned Halls. First admin
     * (lowest ID) is the canonical choice — groups cannot be ownerless.
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
     * privacy = 0 (OPEN) — a Hall is joinable by anyone.
     */
    private function createHallGroup(int $ownerId, string $chainName): int {
        $title = $chainName . ' Hall';

        $data = [
            'owner_id'    => $ownerId,
            'name'        => $title,
            'description' => sprintf(
                'The %s union hall — an open floor for everyone on %s. Auto-managed.',
                $chainName,
                $chainName
            ),
            'meta' => [
                'privacy'                     => 0, // PeepSoGroupPrivacy::PRIVACY_PUBLIC (OPEN)
                'is_joinable'                 => true,
                'is_invitable'                => false,
                'is_readonly'                 => false,
                'is_auto_accept_join_request' => true,
            ],
        ];

        try {
            /** @phpstan-ignore-next-line — PeepSo classes are runtime-only. */
            $group = new \PeepSoGroup(null, $data);
            /** @phpstan-ignore-next-line */
            $id = (int) $group->get('id');
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] PeepSoGroup constructor threw (Hall)', [
                'chain' => $chainName,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
