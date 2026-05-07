<?php
declare(strict_types=1);

/**
 * MentionExtractor — parses PeepSo's @-mention wire format from raw
 * post content and returns the [user_id, byte-range] tuples used by:
 *
 *   - MentionPolicy (write-time strict-reject of policy violations)
 *   - MentionOverlayService (read-time §3.3.12 view-model overlay)
 *
 * Wire format (peepso/classes/tags.php#L77, get_tag_parser):
 *   `@peepso_user_<id>(<name>)`
 *
 * The regex is hardcoded here rather than read via
 * PeepSoTags::get_instance()->get_tag_parser() because:
 *   1. PeepSo gates that helper behind tags_enable=1; BCC's mention
 *      pipeline must work regardless of PeepSo's UI toggle.
 *   2. PeepSo's filter `peepso_tags_parser` could theoretically rewrite
 *      the regex; BCC owns its parsing surface, so we pin to the
 *      known-good shape PeepSo's after_save_post handler also pins to
 *      (its own filter call lives behind `apply_filters` but the BCC
 *      validator runs upstream of that — same regex, no double-shift).
 *
 * @package BCC\Trust\Core\Services\Mentions
 * @since v1.5 (Phase 1d — @-mention autocomplete)
 */

namespace BCC\Trust\Core\Services\Mentions;

if (!defined('ABSPATH')) {
    exit;
}

final class MentionExtractor
{
    public const TOKEN_REGEX = '/@peepso_user_([a-z]*\d+)(?:\(([^)]+)\))?/i';

    /**
     * Extract every well-formed mention token from $content.
     *
     * Range offsets are BYTE offsets into the input string — the
     * MentionOverlayService converts to UTF-16 code units before
     * emitting on the wire (frontends overlay via JS substring,
     * which is UTF-16 code unit indexed).
     *
     * @return list<array{user_id: int, range_start: int, range_end: int}>
     */
    public static function extract(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $count = preg_match_all(self::TOKEN_REGEX, $content, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return [];
        }

        $out   = [];
        $whole = $matches[0];
        $idCap = $matches[1];

        foreach ($whole as $i => $match) {
            $tokenText  = $match[0];
            $tokenStart = $match[1];
            if ($tokenText === '' || $tokenStart < 0) {
                continue;
            }

            $rawId = $idCap[$i][0] ?? '';
            // Strip any anonymous-prefix letters PeepSo allows (the regex
            // is `[a-z]*\d+`); only the trailing digits are the user_id.
            $digits = preg_replace('/^[a-z]+/i', '', $rawId);
            if (!is_string($digits) || $digits === '') {
                continue;
            }
            $userId = (int) $digits;
            if ($userId <= 0) {
                continue;
            }

            $out[] = [
                'user_id'     => $userId,
                'range_start' => $tokenStart,
                'range_end'   => $tokenStart + strlen($tokenText),
            ];
        }

        return $out;
    }

    /**
     * Convenience: just the unique user_ids referenced by any mention
     * token in $content. Order-preserved by first occurrence.
     *
     * @return list<int>
     */
    public static function extractUserIds(string $content): array
    {
        $out  = [];
        $seen = [];
        foreach (self::extract($content) as $m) {
            $uid = $m['user_id'];
            if (!isset($seen[$uid])) {
                $seen[$uid] = true;
                $out[]      = $uid;
            }
        }
        return $out;
    }
}
