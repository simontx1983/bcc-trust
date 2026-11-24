<?php

namespace BCC\Trust\Core\Integration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The parts of PeepSo's maintenance that BCC depends on, run by BCC.
 *
 * PeepSo's own maintenance bundle is not run on this site, and it must not
 * simply be switched back on: the bundle also purges old rows from
 * `peepso_notifications`, which BCC's notification centre is built on, and
 * deletes group and page posts whose group or page it cannot resolve. So BCC
 * runs only the three steps below, each by calling PeepSo's own method, so
 * PeepSo stays the only writer of its tables:
 *
 *  1. HASHTAGS. `peepso_hashtags` is what BCC's trending-hashtags surface
 *     reads (HashtagsEndpoint → bcc-core PeepSoHashtagRepository), and its
 *     only writer is this maintenance step:
 *       - hashtags_build_posts() scans posts not yet scanned and records
 *         their tags;
 *       - hashtags_build_hashtags() recounts tags not counted within
 *         PeepSo's interval and drops tags no post uses any more.
 *     Both take a batch size; each recount scans published posts, so the
 *     recount batch stays small.
 *  2. MAYFLY. PeepSo's expiring key/value store (`peepso_mayfly`). Expired
 *     rows are never read, only kept; PeepSo3_Mayfly::clr() deletes them.
 *  3. REGISTRATION-AVATAR TEMP FILES. Avatars uploaded during a registration
 *     that never finished. PeepSoRegisterAvatarAjax::purge_old() removes
 *     workspaces older than the TTL PeepSo's own maintenance uses
 *     (filter `peepso_registration_avatar_ttl`, two hours).
 *
 * ⛔ NEVER here: PeepSo's notification purge, its group/page post deletes,
 * any other part of the bundle, or PeepSo's public maintenance trigger.
 * PeepSoUpkeepTest pins that.
 *
 * Runs from WP-Cron (`bcc_trust_peepso_upkeep`, every five minutes, owned by
 * CronService). Each step is isolated: a step whose PeepSo class is missing
 * is skipped, and a step that throws (a missing method included) is logged
 * while the others still run. One run at a time: an overlapping run skips.
 */
final class PeepSoUpkeep
{
    public const CRON_HOOK = 'bcc_trust_peepso_upkeep';

    public const LOCK = 'bcc_trust_peepso_upkeep';

    /** Posts scanned for hashtags per run (PeepSo's own default is 5 per tick). */
    public const HASHTAG_POSTS_PER_RUN = 50;

    /** Tags recounted per run. Each recount scans published posts. */
    public const HASHTAG_RECOUNTS_PER_RUN = 20;

    /** PeepSo's registration-avatar TTL: two hours, in seconds. */
    public const AVATAR_TTL_SECONDS = 7200;

    public const STEP_DONE    = 'done';
    public const STEP_SKIPPED = 'skipped';
    public const STEP_FAILED  = 'failed';

    /** The WP-Cron entry point. */
    public static function runScheduled(): void
    {
        (new self())->run();
    }

    /**
     * @return array{status: string, steps: array<string, string>}
     */
    public function run(): array
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')
            || !\BCC\Core\DB\AdvisoryLock::acquire(self::LOCK, 0)
        ) {
            return ['status' => 'locked', 'steps' => []];
        }

        try {
            return [
                'status' => 'ok',
                'steps'  => [
                    'hashtag_posts'    => $this->step('hashtag_posts', fn (): bool => $this->hashtagPosts()),
                    'hashtag_recounts' => $this->step('hashtag_recounts', fn (): bool => $this->hashtagRecounts()),
                    'mayfly'           => $this->step('mayfly', fn (): bool => $this->expireMayfly()),
                    'avatar_temp'      => $this->step('avatar_temp', fn (): bool => $this->purgeAvatarTemp()),
                ],
            ];
        } finally {
            \BCC\Core\DB\AdvisoryLock::release(self::LOCK);
        }
    }

    /**
     * Run one step. The callable answers false when its PeepSo class is not
     * there (skipped); a throwable is logged as failed.
     *
     * @param callable(): bool $fn
     */
    private function step(string $name, callable $fn): string
    {
        try {
            return $fn() ? self::STEP_DONE : self::STEP_SKIPPED;
        } catch (\Throwable $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] PeepSo upkeep step failed', [
                    'step'  => $name,
                    'error' => $e->getMessage(),
                ]);
            }

            return self::STEP_FAILED;
        }
    }

    private function hashtagPosts(): bool
    {
        if (!class_exists('PeepSo', false)) {
            return false;
        }
        \PeepSo::get_instance()->hashtags_build_posts(self::HASHTAG_POSTS_PER_RUN);

        return true;
    }

    private function hashtagRecounts(): bool
    {
        if (!class_exists('PeepSo', false)) {
            return false;
        }
        \PeepSo::get_instance()->hashtags_build_hashtags(self::HASHTAG_RECOUNTS_PER_RUN);

        return true;
    }

    private function expireMayfly(): bool
    {
        if (!class_exists('PeepSo3_Mayfly')) {
            return false;
        }
        // The default arguments: expired entries only, never the whole store.
        \PeepSo3_Mayfly::clr();

        return true;
    }

    private function purgeAvatarTemp(): bool
    {
        if (!class_exists('PeepSoRegisterAvatarAjax')) {
            return false;
        }
        $ttl = (int) apply_filters('peepso_registration_avatar_ttl', self::AVATAR_TTL_SECONDS);
        if ($ttl <= 0) {
            return false;
        }
        \PeepSoRegisterAvatarAjax::purge_old($ttl);

        return true;
    }
}
