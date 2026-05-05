<?php
/**
 * Group identity value object.
 *
 * Canonical shape for any BCC code that reasons about a group. Resolve
 * via GroupContextResolver instead of reading group post meta directly.
 *
 * Holds BCC-side enrichment only (type, source, verification, privacy,
 * trust weighting, member count). Display fields (slug, name, avatar)
 * are PeepSo-owned and composed at the REST surface.
 *
 * @package BCC\Trust\Core\ValueObjects
 */

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupContext {

    /**
     * @param int                    $groupId               WP post ID of the peepso-group.
     * @param GroupType              $type                  System / NFT / Local / User.
     * @param PeepSoPrivacy          $privacy               Mirrors PeepSo's privacy flag.
     * @param bool                   $trustWeightingEnabled Whether trust calculations apply here.
     * @param string|null            $sourceKind            'collection' | 'page' | null.
     * @param int|null               $sourceId              FK into onchain_collections / peepso-page / null.
     * @param GroupVerification|null $verification          Public badge, null if unverified.
     * @param int|null               $memberCount           Lazy field; null until withMemberCount() is called.
     */
    public function __construct(
        public readonly int                $groupId,
        public readonly GroupType          $type,
        public readonly PeepSoPrivacy      $privacy,
        public readonly bool               $trustWeightingEnabled,
        public readonly ?string            $sourceKind,
        public readonly ?int               $sourceId,
        public readonly ?GroupVerification $verification,
        public readonly ?int               $memberCount = null,
    ) {}

    public function isVerified(): bool {
        return $this->verification !== null;
    }

    public function isHolderGroup(): bool {
        return $this->type === GroupType::Nft;
    }

    public function withMemberCount(int $count): self {
        return new self(
            $this->groupId,
            $this->type,
            $this->privacy,
            $this->trustWeightingEnabled,
            $this->sourceKind,
            $this->sourceId,
            $this->verification,
            $count,
        );
    }
}
