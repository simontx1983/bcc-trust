<?php
/**
 * Group context resolver.
 *
 * Single seam for "what kind of group is this?" questions. Reads
 * post meta on the peepso-group post and produces a GroupContext.
 * Privacy is the exception: it comes from bcc-core's probed table read
 * (`PeepSoGroupPrivacy`, F-460), which answers null when it cannot be
 * trusted; see PeepSoPrivacy.
 *
 * Domain isolation: the resolver reads only post meta on the group
 * post itself. It never reaches into Onchain repositories. The
 * `_bcc_gate_collection_id` meta key is the FK for sourceId when
 * type === 'nft'; Onchain code (the provisioning service) is
 * responsible for setting it.
 *
 * Per-request memoization via a static map. Invalidated by request
 * end. Cross-request caching is not done here — read paths in
 * endpoints handle their own cache (per CLAUDE.md §5).
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\GroupVerification;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupContextResolver {

    private const POST_TYPE = 'peepso-group';

    /** @var array<int, GroupContext|null> */
    private array $cache = [];

    /**
     * Group privacy primed by forManyGroups(): the answer of bcc-core's
     * `PeepSoGroupPrivacy::privacyForGroups()` per group, null where that
     * read could not be trusted. forGroup() uses an entry when there is one,
     * and otherwise reads the single group itself.
     *
     * @var array<int, int|null>
     */
    private array $privacy = [];

    public function forGroup(int $groupId): ?GroupContext {
        if ($groupId <= 0) {
            return null;
        }

        if (array_key_exists($groupId, $this->cache)) {
            return $this->cache[$groupId];
        }

        $post = get_post($groupId);
        if (!($post instanceof \WP_Post) || $post->post_type !== self::POST_TYPE) {
            return $this->cache[$groupId] = null;
        }

        $kind = (string) get_post_meta($groupId, '_bcc_group_kind', true);
        $type = $this->resolveType($kind);

        [$sourceKind, $sourceId] = $this->resolveSource($groupId, $type);
        $verification = $this->resolveVerification($type);
        // F-460: from the table (bcc-core's probed read), never the meta
        // cache; unknown is Secret. See PeepSoPrivacy.
        $privacy      = array_key_exists($groupId, $this->privacy)
            ? PeepSoPrivacy::fromStoredValue($this->privacy[$groupId])
            : PeepSoPrivacy::fromGroupPostId($groupId);

        $trustWeighting = (int) get_post_meta($groupId, '_bcc_trust_weighting_enabled', true) === 1;

        return $this->cache[$groupId] = new GroupContext(
            $groupId,
            $type,
            $privacy,
            $trustWeighting,
            $sourceKind,
            $sourceId,
            $verification,
        );
    }

    /**
     * Batch resolve. Primes WP's post-OBJECT cache AND postmeta cache
     * once via _prime_post_caches() (term cache skipped — the resolver
     * never reads terms), then loops calling forGroup() (warm). This is
     * load-bearing: forGroup() calls get_post($groupId), so without the
     * object-cache prime each distinct group on a hot feed page would
     * issue its own SELECT * FROM wp_posts. Priming the object cache
     * collapses those to a single batched read.
     *
     * Privacy is primed the same way: one bcc-core
     * `PeepSoGroupPrivacy::privacyForGroups()` read for the page (F-460),
     * through ProbesReads rather than get_post_meta().
     *
     * @param int[] $groupIds
     * @return array<int, GroupContext>
     */
    public function forManyGroups(array $groupIds): array {
        if ($groupIds === []) {
            return [];
        }

        $missing = [];
        foreach ($groupIds as $id) {
            if (!array_key_exists($id, $this->cache) && $id > 0) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            _prime_post_caches($missing, false, true);
            $this->primePrivacy($missing);
        }

        $out = [];
        foreach ($groupIds as $id) {
            $ctx = $this->forGroup($id);
            if ($ctx !== null) {
                $out[$id] = $ctx;
            }
        }
        return $out;
    }

    /**
     * Read the privacy of many groups in one query per BATCH_MAX ids, so a
     * feed page is not one privacy query per group.
     *
     * ⚠ A chunk whose read cannot be trusted primes NULL (unknown, so
     * Secret) for each of its groups. It does not leave them unprimed, which
     * would fall back to one query per group against a database that is
     * already failing.
     *
     * @param list<int> $groupIds
     */
    private function primePrivacy(array $groupIds): void {
        foreach (array_chunk($groupIds, \BCC\Core\Repositories\PeepSoGroupPrivacy::BATCH_MAX) as $chunk) {
            $byGroup = \BCC\Core\Repositories\PeepSoGroupPrivacy::privacyForGroups($chunk);
            foreach ($chunk as $groupId) {
                $this->privacy[$groupId] = $byGroup === null ? null : ($byGroup[$groupId] ?? null);
            }
        }
    }

    private function resolveType(string $kind): GroupType {
        return match ($kind) {
            'holders'    => GroupType::Nft,
            'delegators' => GroupType::Validator,
            'hall'       => GroupType::Hall,
            'system'     => GroupType::System,
            default      => GroupType::User,
        };
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveSource(int $groupId, GroupType $type): array {
        if ($type === GroupType::Nft) {
            $collectionId = (int) get_post_meta($groupId, '_bcc_gate_collection_id', true);
            return $collectionId > 0 ? ['collection', $collectionId] : ['collection', null];
        }
        if ($type === GroupType::Validator) {
            // FK into wp_bcc_onchain_validators.id — written by
            // ValidatorGroupProvisioningService alongside the gate meta.
            $validatorId = (int) get_post_meta($groupId, '_bcc_gate_validator_id', true);
            return $validatorId > 0 ? ['validator', $validatorId] : ['validator', null];
        }
        // Halls / System / User: no formal source pointer. A Hall's
        // chain association lives in the `_bcc_chain_tag` meta, resolved
        // on demand via ChainRepository rather than carried here.
        return [null, null];
    }

    private function resolveVerification(GroupType $type): ?GroupVerification {
        // Validator/delegator communities reuse the on_chain badge — the
        // gate is delegation-verified against the live LCD, same trust
        // grammar as the NFT holder gate.
        return ($type === GroupType::Nft || $type === GroupType::Validator)
            ? GroupVerification::onChain()
            : null;
    }
}
