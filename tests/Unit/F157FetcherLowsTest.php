<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\CLI\BackfillValidatorPagesCommand;
use BCC\Trust\Onchain\Services\ChainRefreshService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-157 (fetcher Lows):
 *
 * (b) The validator-page backfill CLI takes the SAME advisory lock the
 *     chain-refresh cron holds while it indexes validators and mints
 *     their pages, so the two never mint concurrently. A live run refuses
 *     while the cron holds it; a dry run needs no lock.
 * (c) The X and GitHub OAuth callbacks rate-limit through
 *     RateLimiter::allow(), never a raw-IP allowByKey() key. They run
 *     before the session is resolved, so in practice it is allow()'s
 *     anonymous branch: per subnet (IPv4 /24, IPv6 /48), capped at 5/min.
 *
 * Separate processes: the CLI test defines class doubles (AdvisoryLock,
 * the repositories, the minter, WP_CLI) before anything autoloads them.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class F157FetcherLowsTest extends TestCase
{
    private static function defineCliDoubles(bool $lockFree): void
    {
        $GLOBALS['__f157'] = ['lockFree' => $lockFree, 'acquired' => [], 'released' => [], 'minted' => [], 'errors' => []];
        eval(<<<'PHP'
            namespace BCC\Core\DB {
                final class AdvisoryLock {
                    public static function acquire(string $key, int $timeout = 0): bool {
                        $GLOBALS['__f157']['acquired'][] = $key;
                        return $GLOBALS['__f157']['lockFree'];
                    }
                    public static function release(string $key): void {
                        $GLOBALS['__f157']['released'][] = $key;
                    }
                }
            }
            namespace BCC\Trust\Onchain\Repositories {
                final class ValidatorRepository {
                    public static function countMissingPlaceholderPage(?int $chainId): int { return 3; }
                }
                final class ChainRepository {
                    public static function getBySlug(string $slug): ?object { return null; }
                }
            }
            namespace BCC\Trust\Onchain\Services {
                final class ValidatorPageMinter {
                    /** @return array<string, mixed> */
                    public static function mintMissing(?int $chainId, bool $dryRun, int $max): array {
                        $GLOBALS['__f157']['minted'][] = $dryRun ? 'dry' : 'live';
                        return ['scanned' => 3, 'minted' => $dryRun ? 0 : 3, 'skipped' => 0, 'errors' => 0, 'error_log' => []];
                    }
                }
            }
            namespace {
                final class F157CliStop extends \RuntimeException {}
                final class WP_CLI {
                    public static function line(string $m = ''): void {}
                    public static function success(string $m): void {}
                    public static function warning(string $m): void {}
                    public static function error(string $m, bool $exit = true): void {
                        $GLOBALS['__f157']['errors'][] = $m;
                        if ($exit) { throw new F157CliStop($m); }
                    }
                }
            }
            PHP);
    }

    public function testTheCliLockIsTheCronsIndexLock(): void
    {
        self::assertSame('bcc_cron:index_validators', ChainRefreshService::INDEX_VALIDATORS_LOCK);
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ChainRefreshService.php');
        // The cron's index run (which mints in its tail) holds exactly that lock.
        self::assertStringContainsString("self::acquireLock('index_validators')", $src);
        self::assertStringContainsString("AdvisoryLock::acquire(self::LOCK_GROUP . ':' . \$job, 0)", $src);
    }

    public function testALiveRunRefusesWhileTheCronHoldsTheLock(): void
    {
        self::defineCliDoubles(false);
        try {
            (new BackfillValidatorPagesCommand())->backfill_pages([], []);
            self::fail('expected the CLI to stop');
        } catch (\F157CliStop $e) {
            // expected
        }
        self::assertSame(['bcc_cron:index_validators'], $GLOBALS['__f157']['acquired']);
        self::assertSame([], $GLOBALS['__f157']['minted'], 'nothing minted while the cron holds the lock');
        self::assertSame([], $GLOBALS['__f157']['released'], 'a lock it never got is not released');
    }

    public function testALiveRunTakesAndReleasesTheLock(): void
    {
        self::defineCliDoubles(true);
        (new BackfillValidatorPagesCommand())->backfill_pages([], []);
        self::assertSame(['live'], $GLOBALS['__f157']['minted']);
        self::assertSame(['bcc_cron:index_validators'], $GLOBALS['__f157']['acquired']);
        self::assertSame(['bcc_cron:index_validators'], $GLOBALS['__f157']['released']);
    }

    public function testADryRunNeedsNoLock(): void
    {
        self::defineCliDoubles(false);
        (new BackfillValidatorPagesCommand())->backfill_pages([], ['dry-run' => '1']);
        self::assertSame(['dry'], $GLOBALS['__f157']['minted']);
        self::assertSame([], $GLOBALS['__f157']['acquired']);
    }

    /** @return array<string, array{string, string}> */
    public static function callbacks(): array
    {
        return [
            'x'      => ['app/Domain/Core/Controllers/XController.php', 'x_callback'],
            'github' => ['app/Domain/Core/Controllers/GitHubController.php', 'github_callback'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('callbacks')]
    public function testOAuthCallbacksUseTheSubnetAwareLimiter(string $rel, string $action): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        self::assertStringContainsString("RateLimiter::allow('{$action}', 10, 60)", $src);
        self::assertStringNotContainsString('allowByKey(', $src);
    }

    public function testNoControllerKeysALimitByRawIp(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (glob($root . '/app/Domain/*/Controllers/*.php') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression(
                '/allowByKey\([^)]*getClientIp\(\)/',
                (string) file_get_contents($file),
                basename($file) . ' keys a rate limit by raw IP'
            );
        }
    }
}
