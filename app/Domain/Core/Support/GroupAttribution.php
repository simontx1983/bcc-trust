<?php

/**
 * GroupAttribution — the "Posted in …" block, and the secret-group rule
 * that decides whether a viewer may learn the group exists at all.
 *
 * This was `private static groupAttribution()` on FeedHydrationPipeline,
 * where only the feed could reach it. The profile Blog tab needs the same
 * block once a blog post can live inside a group, and this gate is a
 * PRIVACY decision — the one thing that must not exist in two copies, because
 * two copies drift and only one of them gets fixed. Extracted rather than
 * duplicated (§11); the pipeline's method now delegates here, so its eight
 * existing tests pin this body through that seam unchanged.
 *
 * @package BCC\Trust\Core\Support
 * @since §D6 group blogs
 */

namespace BCC\Trust\Core\Support;

use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupAttribution
{
    /**
     * Compose one item's `group` block.
     *
     * `{id, type, verification}` ship ALWAYS — they already shipped and are
     * opaque (a bare int, a category, a public badge). `name` + `link` are
     * added ONLY when the viewer is allowed to know the group exists AND a
     * display row resolved:
     *
     *     allowed  ⇔  privacy !== Secret  OR  viewer is an active member
     *
     *   - secret + non-member (anonymous included, passed as
     *     `$viewerIsMember = false`) → NO name/link: the group's identity
     *     never leaks, and the response stays byte-identical in shape to a
     *     permitted one so its absence is not itself a signal.
     *   - resolved-but-empty name/slug (the deleted-group edge, where the
     *     display row dropped out of the batched read) → NO name/link,
     *     defensively; never a broken `/communities/` link.
     *
     * Link composition routes through the single `CardUrlMap::groupUrl`
     * composer — hall → `/halls/{slug}`, else → `/communities/{slug}`.
     *
     * Pure: no database, no session. Every input is resolved by the caller,
     * which is what makes the rule unit-pinnable without a live WordPress.
     *
     * @return array<string, mixed>
     */
    public static function compose(
        GroupContext $ctx,
        string $name,
        string $slug,
        bool $viewerIsMember
    ): array {
        $block = [
            'id'           => $ctx->groupId,
            'type'         => $ctx->type->value,
            'verification' => $ctx->verification?->toApiResponse(),
        ];

        $mayKnow = $ctx->privacy !== PeepSoPrivacy::Secret || $viewerIsMember;
        if (!$mayKnow || $name === '' || $slug === '') {
            return $block;
        }

        $block['name'] = $name;
        $block['link'] = CardUrlMap::groupUrl($ctx->type->value, $slug);

        return $block;
    }
}
