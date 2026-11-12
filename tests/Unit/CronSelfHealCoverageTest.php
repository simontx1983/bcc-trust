<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-116 — every RECURRING cron that bcc_trust_activate() schedules is also
 * self-healed on plugins_loaded.
 *
 * Activation runs once. A recurring event that is ONLY scheduled there is
 * gone for good once its cron entry is lost (a cron-array reset, a database
 * migration, an update without reactivation), and nothing reports it:
 * `bcc_onchain_daily_refresh` (wallet signals) and `bcc_onchain_retry_bonus`
 * (failed bonus applications) had exactly that shape. The plugins_loaded
 * self-heal block re-schedules a missing hook on the next request.
 *
 * Structural, because the property is "the source contains the guard": a new
 * activation-only recurring cron fails here instead of silently drifting.
 */
final class CronSelfHealCoverageTest extends TestCase
{
    /**
     * Hooks self-healed OUTSIDE the inline block, by design — each entry
     * names where. Adding one requires pointing at the code that heals it.
     */
    private const HEALED_ELSEWHERE = [
        '\\BCC\\Trust\\Onchain\\Workers\\NftEthIndexerWorker::CRON_HOOK'
            => 'NftEthIndexerWorker::register(), called from the self-heal block',
        '\\BCC\\Trust\\Core\\Services\\WatchBatchAggregator::SWEEP_HOOK'
            => 'Plugin::registerAsyncJobs() (app/Domain/Core/Plugin.php)',
    ];

    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
    }

    /** @return list<string> hook expressions guarded by wp_next_scheduled() and scheduled with wp_schedule_event() */
    private static function recurringGuards(string $code): array
    {
        preg_match_all(
            '/wp_next_scheduled\(\s*([^)]+?)\s*\)\s*\)\s*\{\s*wp_schedule_event\(/',
            $code,
            $m
        );
        return array_values(array_unique(array_map('trim', $m[1])));
    }

    private static function activationBody(string $src): string
    {
        $start = strpos($src, 'function bcc_trust_activate');
        self::assertNotFalse($start, 'bcc_trust_activate() must exist');
        $end = strpos($src, "\n}", (int) $start);
        return substr($src, (int) $start, (int) $end - (int) $start);
    }

    private static function selfHealBlock(string $src): string
    {
        $start = strpos($src, 'V2 Phase 1 hardening: self-heal cron registration');
        self::assertNotFalse($start, 'the plugins_loaded self-heal block must exist');
        $end = strpos($src, '}, 5);', (int) $start);
        self::assertNotFalse($end);
        return substr($src, (int) $start, (int) $end - (int) $start);
    }

    public function testEveryRecurringActivationCronIsSelfHealed(): void
    {
        $src      = self::source();
        $activate = self::recurringGuards(self::activationBody($src));
        $healed   = self::recurringGuards(self::selfHealBlock($src));

        self::assertNotEmpty($activate, 'fixture: activation schedules recurring crons');

        $missing = [];
        foreach ($activate as $hook) {
            if (in_array($hook, $healed, true) || isset(self::HEALED_ELSEWHERE[$hook])) {
                continue;
            }
            $missing[] = $hook;
        }

        self::assertSame([], $missing, 'recurring crons scheduled only at activation never come back once lost');
    }

    /** The two F-116 hooks, explicitly — with the same interval as activation. */
    public function testTheSignalRefreshAndBonusRetryCronsSelfHealWithTheirActivationIntervals(): void
    {
        $block = self::selfHealBlock(self::source());

        self::assertMatchesRegularExpression(
            "/wp_next_scheduled\\('bcc_onchain_daily_refresh'\\)\\)\\s*\\{\\s*wp_schedule_event\\(time\\(\\), 'daily', 'bcc_onchain_daily_refresh'\\)/",
            $block
        );
        self::assertMatchesRegularExpression(
            "/wp_next_scheduled\\('bcc_onchain_retry_bonus'\\)\\)\\s*\\{\\s*wp_schedule_event\\(time\\(\\), 'hourly', 'bcc_onchain_retry_bonus'\\)/",
            $block
        );
    }

    /** The allowlist must stay honest: each "healed elsewhere" hook really is. */
    public function testTheHealedElsewhereAllowlistIsReal(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringContainsString(
            '\\BCC\\Trust\\Onchain\\Workers\\NftEthIndexerWorker::register();',
            self::selfHealBlock(self::source())
        );
        self::assertMatchesRegularExpression(
            '/if \(!wp_next_scheduled\(self::CRON_HOOK\)\)\s*\{\s*wp_schedule_event\(/',
            (string) file_get_contents($root . '/app/Domain/Onchain/Workers/NftEthIndexerWorker.php')
        );
        self::assertMatchesRegularExpression(
            '/if \(!wp_next_scheduled\(Services\\\\WatchBatchAggregator::SWEEP_HOOK\)\)/',
            (string) file_get_contents($root . '/app/Domain/Core/Plugin.php')
        );
    }
}
