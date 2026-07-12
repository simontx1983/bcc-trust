<?php
/**
 * One-shot canonical-handle backfill — assign bcc_handle usermeta to
 * every legacy account that has none.
 *
 * Why: the feed author block (bcc-core ActivityFeedService::hydrateAuthors
 * and the trust-side reactor/blog hydrators) emits `bcc_handle` when set
 * and silently falls back to `user_login`. The fallback renders fine but
 * is NOT route-resolvable — `/u/{login}` 404s because the profile
 * resolvers key on the bcc_handle meta. With the new
 * `/u/{handle}/post/{shortcode}` permalinks every author's handle must
 * resolve, so this migration closes the gap for accounts created before
 * signup started writing the meta. No pipeline change needed afterward:
 * once every user has bcc_handle, the user_login fallback is dead code
 * on real data.
 *
 * Derivation (per user missing the meta):
 *   1. candidate = sanitize_title(display_name); if that yields fewer
 *      than 3 usable chars, fall back to user_login — stripping a
 *      leading 'u-'/'u_' the login-derived form may carry.
 *   2. collapse to [a-z0-9-] (underscores → hyphens, strip the rest,
 *      no consecutive/leading/trailing hyphens), clamp to 20 chars
 *      (re-trimming a trailing hyphen).
 *   3. still under 3 chars → deterministic 'member-{user_id}'.
 *   4. validate via HandleService (single source of §B6 truth:
 *      format + reserved + uniqueness — NOT reimplemented here); on
 *      collision or reserved hit, append '-2', '-3', … re-clamping the
 *      base so the total stays within 20.
 *
 * Persists via HandleService::META_HANDLE (the same key signup + PATCH
 * write). Deliberately does NOT write META_HANDLE_LAST_CHANGED — initial
 * assignment must not arm the §B6 rename cooldown, matching signup.
 *
 * Idempotent: option-guarded (bcc_trust_canonical_handles_backfilled)
 * AND the per-user query only selects users still missing the meta.
 * Registration mirrors rename-pull-to-watch.php — required from
 * bcc-trust.php, invoked inside bcc_trust_create_tables().
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 1.2.23 (post-shortcode permalinks)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_backfill_canonical_handles')) {

    function bcc_trust_backfill_canonical_handles(): void
    {
        if (get_option('bcc_trust_canonical_handles_backfilled')) {
            return;
        }

        $handleService = new \BCC\Trust\Core\Services\HandleService();

        $batchSize     = 200;
        $maxIterations = 50; // hard cap: 10k users per migration request
        $assignedCount = 0;
        /** @var list<int> $skipped user_ids we failed to assign — excluded
         *                 from re-selection so the loop always progresses */
        $skipped = [];

        for ($i = 0; $i < $maxIterations; $i++) {
            /** @var list<int|string> $userIds */
            $userIds = get_users([
                'fields'     => 'ID',
                'number'     => $batchSize,
                'orderby'    => 'ID',
                'order'      => 'ASC',
                'exclude'    => $skipped,
                // Missing OR empty meta both count as "no handle" —
                // an empty string is just as route-dead as absence.
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key'     => \BCC\Trust\Core\Services\HandleService::META_HANDLE,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => \BCC\Trust\Core\Services\HandleService::META_HANDLE,
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
            ]);

            if ($userIds === []) {
                break;
            }

            foreach ($userIds as $rawId) {
                $userId = (int) $rawId;
                if ($userId <= 0) {
                    continue;
                }

                $handle = bcc_trust_bch_assign_handle($userId, $handleService);
                if ($handle === null) {
                    $skipped[] = $userId;
                    \BCC\Core\Log\Logger::warning('[bcc-trust] canonical-handle backfill could not assign a handle', [
                        'user_id' => $userId,
                    ]);
                    continue;
                }
                $assignedCount++;
            }

            if (count($userIds) < $batchSize) {
                break;
            }
        }

        update_option('bcc_trust_canonical_handles_backfilled', time(), false);

        \BCC\Core\Log\Logger::info('[bcc-trust] Canonical-handle backfill complete', [
            'assigned' => $assignedCount,
            'skipped'  => count($skipped),
        ]);
    }

    /**
     * Derive + persist a handle for one user. Returns the assigned
     * handle, or null when no valid handle could be found within the
     * suffix budget.
     */
    function bcc_trust_bch_assign_handle(int $userId, \BCC\Trust\Core\Services\HandleService $handleService): ?string
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }

        $base = bcc_trust_bch_derive_base(
            (string) $user->display_name,
            (string) $user->user_login,
            $userId
        );

        $handle = bcc_trust_bch_find_free_variant($base, $handleService);
        if ($handle === null) {
            // Suffix budget exhausted on the name-derived base (dense
            // collision cluster) — retry once on the deterministic
            // member-{id} base, which is unique by construction.
            $handle = bcc_trust_bch_find_free_variant('member-' . $userId, $handleService);
        }
        if ($handle === null) {
            return null;
        }

        update_user_meta($userId, \BCC\Trust\Core\Services\HandleService::META_HANDLE, $handle);
        // NB: no META_HANDLE_LAST_CHANGED write — initial assignment
        // must not arm the rename cooldown (matches the signup path).

        \BCC\Core\Log\Logger::info('[bcc-trust] canonical handle assigned', [
            'user_id' => $userId,
            'handle'  => $handle,
        ]);

        return $handle;
    }

    /**
     * Candidate base per the derivation rules in the file docblock.
     * Always returns a normalized string of >= 3 chars.
     */
    function bcc_trust_bch_derive_base(string $displayName, string $userLogin, int $userId): string
    {
        $fromDisplay = bcc_trust_bch_normalize(sanitize_title($displayName));
        if (strlen($fromDisplay) >= \BCC\Trust\Core\Services\HandleService::MIN_LENGTH) {
            return $fromDisplay;
        }

        // Login-derived form: strip a leading 'u-'/'u_' first (wallet
        // signup logins carry that prefix; '/u/u-xyz' reads broken).
        $login = $userLogin;
        if (str_starts_with($login, 'u-') || str_starts_with($login, 'u_')) {
            $login = substr($login, 2);
        }
        $fromLogin = bcc_trust_bch_normalize(sanitize_title($login));
        if (strlen($fromLogin) >= \BCC\Trust\Core\Services\HandleService::MIN_LENGTH) {
            return $fromLogin;
        }

        // Deterministic last resort — always valid, unique-leaning.
        return 'member-' . $userId;
    }

    /**
     * Collapse to §B6 shape: lowercase [a-z0-9-] only, no consecutive /
     * leading / trailing hyphens, clamped to 20 chars (re-trimming a
     * trailing hyphen the clamp may expose). Length >= 3 is the
     * CALLER's check — this only normalizes.
     */
    function bcc_trust_bch_normalize(string $candidate): string
    {
        $candidate = strtolower($candidate);
        $candidate = str_replace('_', '-', $candidate);
        $candidate = (string) preg_replace('/[^a-z0-9-]+/', '', $candidate);
        $candidate = (string) preg_replace('/-{2,}/', '-', $candidate);
        $candidate = trim($candidate, '-');
        if (strlen($candidate) > \BCC\Trust\Core\Services\HandleService::MAX_LENGTH) {
            $candidate = substr($candidate, 0, \BCC\Trust\Core\Services\HandleService::MAX_LENGTH);
            $candidate = rtrim($candidate, '-');
        }
        return $candidate;
    }

    /**
     * First valid + available variant of $base: the base itself, then
     * '-2', '-3', … (re-clamping the base so base+suffix stays within
     * 20 chars). Validation + uniqueness go through HandleService —
     * the single source of §B6 truth. Returns null when the suffix
     * budget is exhausted.
     */
    function bcc_trust_bch_find_free_variant(string $base, \BCC\Trust\Core\Services\HandleService $handleService): ?string
    {
        $maxLen = \BCC\Trust\Core\Services\HandleService::MAX_LENGTH;

        for ($n = 1; $n <= 50; $n++) {
            if ($n === 1) {
                $candidate = $base;
            } else {
                $suffix    = '-' . $n;
                $stem      = substr($base, 0, $maxLen - strlen($suffix));
                $stem      = rtrim($stem, '-');
                $candidate = $stem . $suffix;
            }

            if (strlen($candidate) < \BCC\Trust\Core\Services\HandleService::MIN_LENGTH) {
                continue;
            }
            if ($handleService->validate($candidate) !== null) {
                // Reserved or format-invalid — a suffix changes the
                // string, so keep trying.
                continue;
            }
            if (!$handleService->isAvailable($candidate)) {
                continue;
            }
            return $candidate;
        }

        return null;
    }
}
