<?php
declare(strict_types=1);

/**
 * MentionOverlayService — read-side §3.3.12 Mention[] view-model
 * builder.
 *
 * Extracts wire-format @-mention tokens from a raw stored body
 * (status text, photo/gif caption, comment body) and returns the
 * front-end overlay shape:
 *
 *   [{ user_id, handle, display_name, avatar_url, range: [start, end] }]
 *
 * §3.3.12 INVARIANT: range offsets reference the RAW stored content
 * (not post-render, not post-sanitization). Future formatting layers
 * (markdown, emoji, embeds, contentEditable) MUST overlay AROUND these
 * ranges, never through them.
 *
 * **Offset units: UTF-16 code units** (matches JS String.prototype
 * indexing). preg_match_all returns BYTE offsets in PHP; we convert
 * before emitting to avoid an off-by-N footgun any time the caption
 * contains multi-byte UTF-8 characters BEFORE the mention.
 *
 * Privacy posture: the overlay does NOT enforce viewer-side privacy.
 * Mentions on already-published posts surface to all viewers who can
 * see the post itself — write-time MentionPolicy already filtered them
 * at publish, so any token in stored content is by-construction
 * mentionable. (Edge cases: a user becomes hidden AFTER the post is
 * published. The mention link still resolves — frontend may decline to
 * render it as clickable if `/u/:handle` 404s. V1d does not retro-strip
 * mentions on privacy state changes; that's V2 moderation tooling.)
 *
 * Output shape (`Mention`):
 *
 *   array{
 *     user_id:      int,
 *     handle:       string,
 *     display_name: string,
 *     avatar_url:   string,
 *     range:        array{int, int}    // [start, end] in UTF-16 code units
 *   }
 *
 * @package BCC\Trust\Core\Services\Mentions
 * @since v1.5 (Phase 1d)
 */

namespace BCC\Trust\Core\Services\Mentions;

if (!defined('ABSPATH')) {
    exit;
}

final class MentionOverlayService
{
    /**
     * Build the §3.3.12 overlay for a single body string.
     * Empty on no mentions.
     *
     * @return list<array{user_id: int, handle: string, display_name: string, avatar_url: string, range: array{int, int}}>
     */
    public function buildOverlay(string $content): array
    {
        $batch = $this->buildOverlayBatch([0 => $content]);
        return $batch[0] ?? [];
    }

    /**
     * Batch variant for feed-page hydration. One pass over a
     * `[postId => content]` map; resolves each user once, primes
     * usermeta cache once. Returns `[postId => Mention[]]` for IDs
     * that have at least one mention; absent for IDs with none.
     *
     * @param array<int, string> $contentByPostId
     * @return array<int, list<array{user_id: int, handle: string, display_name: string, avatar_url: string, range: array{int, int}}>>
     */
    public function buildOverlayBatch(array $contentByPostId): array
    {
        if ($contentByPostId === []) {
            return [];
        }

        $rawByPost = [];
        $allUserIds = [];
        foreach ($contentByPostId as $postId => $content) {
            if (!is_string($content) || $content === '') {
                continue;
            }
            $rows = MentionExtractor::extract($content);
            if ($rows === []) {
                continue;
            }
            $rawByPost[(int) $postId] = $rows;
            foreach ($rows as $r) {
                $allUserIds[$r['user_id']] = true;
            }
        }
        if ($rawByPost === []) {
            return [];
        }

        // Prime usermeta cache once for `bcc_handle` reads — turns the
        // per-user resolve into an in-memory lookup on the second call.
        $userIdList = array_keys($allUserIds);
        if ($userIdList !== []) {
            update_meta_cache('user', $userIdList);
        }

        $resolved = [];
        $out      = [];
        foreach ($rawByPost as $postId => $rows) {
            $content = $contentByPostId[$postId];
            $list    = [];
            foreach ($rows as $m) {
                $uid = $m['user_id'];
                if (!array_key_exists($uid, $resolved)) {
                    $resolved[$uid] = self::resolveUser($uid);
                }
                $u = $resolved[$uid];
                if ($u === null) {
                    // User row vanished after the mention was published
                    // (account deletion). Drop the mention silently —
                    // the wire token in body is already harmless because
                    // the link target won't resolve.
                    continue;
                }

                $startU16 = self::byteOffsetToUtf16Offset($content, $m['range_start']);
                $endU16   = self::byteOffsetToUtf16Offset($content, $m['range_end']);

                $list[] = [
                    'user_id'      => $u['user_id'],
                    'handle'       => $u['handle'],
                    'display_name' => $u['display_name'],
                    'avatar_url'   => $u['avatar_url'],
                    'range'        => [$startU16, $endU16],
                ];
            }
            if ($list !== []) {
                $out[$postId] = $list;
            }
        }
        return $out;
    }

    /**
     * Resolve a user_id to the §3.3.12 view-model fields. Reads handle
     * from `bcc_handle` user-meta (canonical) with a `user_login`
     * fallback for legacy accounts (mirrors UsersEndpoint::resolveHandle's
     * inverse direction).
     *
     * @return array{user_id: int, handle: string, display_name: string, avatar_url: string}|null
     */
    private static function resolveUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = get_user_by('id', $userId);
        if (!$user instanceof \WP_User) {
            return null;
        }

        $handleRaw = get_user_meta($userId, 'bcc_handle', true);
        $handle = is_string($handleRaw) && $handleRaw !== ''
            ? $handleRaw
            : (string) $user->user_login;

        $displayName = (string) $user->display_name;
        if ($displayName === '') {
            $displayName = (string) $user->user_login;
        }

        // Cached seam (honors the avatar-change bust hook; the media
        // cache also collapses repeat mentions of the same user within
        // the request).
        $avatarUrl = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrl($userId);

        return [
            'user_id'      => $userId,
            'handle'       => $handle,
            'display_name' => $displayName,
            'avatar_url'   => $avatarUrl,
        ];
    }

    /**
     * Convert a byte offset in $content to a UTF-16 code-unit offset.
     *
     * Frontends overlay mentions[] via JS String.prototype.substring
     * which is UTF-16 code-unit indexed. Emitting byte offsets would
     * drift any time the prefix contains multi-byte UTF-8 (CJK, emoji,
     * accented Latin) — silent off-by-N. JS-native offsets keep the
     * frontend's substring slice exact.
     *
     * For BMP-only content (ASCII + most modern Latin), 1 code point
     * = 1 code unit, so this collapses to mb_strlen. Non-BMP chars
     * (e.g. some emoji) take 2 code units (surrogate pair), which is
     * what mb_convert_encoding to UTF-16 captures for free.
     */
    private static function byteOffsetToUtf16Offset(string $content, int $byteOffset): int
    {
        if ($byteOffset <= 0) {
            return 0;
        }
        $prefix = substr($content, 0, $byteOffset);
        if ($prefix === '') {
            return 0;
        }
        $utf16 = mb_convert_encoding($prefix, 'UTF-16', 'UTF-8');
        if (!is_string($utf16)) {
            // Fallback: code-point count (off by 1 per non-BMP char,
            // which is rare enough we accept the soft drift).
            return mb_strlen($prefix, 'UTF-8');
        }
        return intdiv(strlen($utf16), 2);
    }
}
