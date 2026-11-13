<?php
/**
 * ProfileVouchLineResolver — the profile trust header's vouch line (F-340,
 * Figma profile redesign, dev notes 23 + 24).
 *
 *   - Signed in, someone else's profile: "Vouched for by N people you
 *     watch" → `vouched_by_watched_count` (0 hides the line).
 *   - Signed out: "Top vouched by [avatars]" → `top_vouchers`, at most 3,
 *     highest trust score first, then the newest vouch (empty hides it).
 *
 * Both count only what the public attestation roster would show, and less:
 * see AttestationRepository::eligibleProfileVouchSql (vouch only, active,
 * attestor exists, not suspended). The signed-in count also skips
 * attestors mutually blocked with the viewer. A voucher who hid their real
 * name counts, and appears in top_vouchers as @handle (PublicIdentity, the
 * roster's rendering), exactly as the roster shows them.
 *
 * A failed read is UNKNOWN, never a wrong number: the count stays null and
 * the avatar list empty (the line hides), the failure is logged, and the
 * profile never fails for it (the F-255 rule). No wallet data, ever.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Support\PublicIdentity;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileVouchLineResolver
{
    /** "Top vouched by" avatars on a signed-out profile. */
    public const TOP_VOUCHERS = 3;

    public function __construct(private readonly AttestationRepository $attestations)
    {
    }

    /**
     * @return array{
     *   vouched_by_watched_count: int|null,
     *   top_vouchers: list<array{handle: string, display_name: string, avatar_url: string}>
     * }
     */
    public function forProfile(int $ownerId, int $viewerId): array
    {
        $out = ['vouched_by_watched_count' => null, 'top_vouchers' => []];
        if ($ownerId <= 0) {
            return $out;
        }

        try {
            if ($viewerId > 0) {
                // Your own profile has no "people you watch" line.
                if ($viewerId !== $ownerId) {
                    $out['vouched_by_watched_count'] = $this->attestations->countProfileVouchesByWatched($ownerId, $viewerId);
                }

                return $out;
            }

            foreach ($this->attestations->topProfileVoucherIds($ownerId, self::TOP_VOUCHERS) as $voucherId) {
                $user = get_userdata($voucherId);
                if (!$user instanceof \WP_User) {
                    continue;
                }
                $identity              = PublicIdentity::forUser($user, 0);
                $out['top_vouchers'][] = [
                    'handle'       => $identity['handle'],
                    'display_name' => $identity['display_name'],
                    'avatar_url'   => \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrl($voucherId),
                ];
            }
        } catch (RepositoryReadFailure $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] profile vouch line unknown: read failed', [
                'owner_id'  => $ownerId,
                'viewer_id' => $viewerId,
                'error'     => $e->getMessage(),
            ]);

            return ['vouched_by_watched_count' => null, 'top_vouchers' => []];
        }

        return $out;
    }
}
