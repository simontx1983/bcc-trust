<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ONE OWNER FOR THE HALF-OPEN PROBE: {@see \BCC\Trust\Onchain\Support\ApiRetry}.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────
 * {@see OnchainCircuitBreaker::isOpen()} is not a read. In the HALF-OPEN
 * window it CLAIMS the per-chain recovery probe with a cluster-wide advisory
 * lock, and whoever wins it is expected to go and contact the provider.
 *
 * Four callers used it as an outer preflight — before they knew whether they
 * would make a request at all. Each of them can return afterwards for a
 * reason that never reaches the wire (exhausted budget, missing chain row,
 * missing driver, unsupported capability, misconfigured endpoint), and
 * nothing releases the lock: the chain then cannot be probed by any worker
 * until that PHP process's database session closes.
 *
 * So the rule is: **only the transport layer may claim, and it claims
 * immediately before the request it is about to make.** Outer checks use the
 * non-mutating {@see OnchainCircuitBreaker::isResting()}.
 *
 * ── WHY THIS IS A SOURCE-LEVEL TEST ─────────────────────────────────────
 * The property is "no OTHER call site does this", which is a statement about
 * the whole tree rather than about one execution. The behaviour of each
 * primitive is measured separately, against a real MySQL/MariaDB lock, by
 * {@see \BCC\Trust\Tests\Integration\HalfOpenProbeOwnershipIntegrationTest}.
 * This file is what stops a fifth caller appearing.
 */
#[CoversClass(OnchainCircuitBreaker::class)]
final class HalfOpenProbeOwnershipTest extends TestCase
{
    /** Executable (comment-stripped) source of every PHP file under app/. */
    private static function executableSources(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $root = dirname(__DIR__, 2) . '/app';
        self::assertDirectoryExists($root);

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            // ⚠ COMMENTS STRIPPED WITH token_get_all(), NEVER A REGEX. This
            // class's own docblocks reference `isOpen()` repeatedly, and a
            // grep counts every one of them as a call site.
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                        $code .= str_repeat("\n", substr_count($t[1], "\n"));
                        continue;
                    }
                    $code .= $t[1];
                } else {
                    $code .= $t;
                }
            }
            $out[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $code;
        }

        self::assertNotSame([], $out, 'anti-vacuity: some source must have been scanned');
        $cache = $out;

        return $out;
    }

    /** @return list<string> "relative/path.php:line" for each executable call */
    private static function callSites(string $needle): array
    {
        $sites = [];
        foreach (self::executableSources() as $rel => $code) {
            $offset = 0;
            while (($pos = strpos($code, $needle, $offset)) !== false) {
                $offset = $pos + strlen($needle);
                $sites[] = $rel . ':' . (substr_count($code, "\n", 0, $pos) + 1);
            }
        }
        sort($sites);

        return $sites;
    }

    // ── the ownership rule ──────────────────────────────────────────────

    /**
     * ⚠ EXACTLY TWO CLAIM SITES, BOTH IN ApiRetry: the single-request path
     * and the batch-wave path. A new one anywhere else fails here.
     */
    public function testOnlyApiRetryClaimsTheProbe(): void
    {
        $sites = self::callSites('OnchainCircuitBreaker::isOpen(');

        self::assertNotSame([], $sites, 'anti-vacuity: the mutating check must still exist somewhere');
        self::assertSame(
            [
                'Domain/Onchain/Support/ApiRetry.php:200',
                'Domain/Onchain/Support/ApiRetry.php:599',
            ],
            $sites,
            "the half-open probe has exactly one owner — ApiRetry. Found:\n  " . implode("\n  ", $sites)
        );
    }

    /**
     * The four outer preflights, named individually so that silently deleting
     * a breaker check (rather than making it non-mutating) also fails.
     *
     * @return array<string, array{0: string}>
     */
    public static function outerPreflights(): array
    {
        return [
            'EVM indexer tick'        => ['Domain/Onchain/Workers/NftEthIndexerWorker.php'],
            'validator index cron'    => ['Domain/Onchain/Services/ChainRefreshService.php'],
            'enrichment scheduler'    => ['Domain/Onchain/Services/EnrichmentScheduler.php'],
            // Retained but frozen since #261. It must be fixed in place, NOT
            // unfrozen and NOT deleted: the freeze is a separate guarantee.
            'cosmwasm discovery (frozen)' => ['Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php'],
        ];
    }

    #[DataProvider('outerPreflights')]
    public function testEveryOuterPreflightUsesTheNonMutatingCheck(string $relPath): void
    {
        $sources = self::executableSources();
        self::assertArrayHasKey($relPath, $sources, 'the caller must still exist');
        $code = $sources[$relPath];

        self::assertStringContainsString(
            'OnchainCircuitBreaker::isResting(',
            $code,
            $relPath . ' must inspect breaker state without claiming the probe'
        );
        self::assertStringNotContainsString(
            'OnchainCircuitBreaker::isOpen(',
            $code,
            $relPath . ' must NOT claim the probe before it knows it will contact a provider'
        );
    }

    /** The frozen scanner stays frozen — this PR moves a check, it does not open a door. */
    public function testTheScannerEntryPointsAreStillFrozen(): void
    {
        self::assertTrue(
            \BCC\Trust\Onchain\Support\ScannerFreeze::frozen(),
            'ScannerFreeze must still report frozen'
        );
        self::assertCount(
            13,
            \BCC\Trust\Onchain\Support\ScannerFreeze::FROZEN_ENTRY_POINTS,
            'the frozen entry-point inventory must be unchanged by this PR'
        );
    }

    // ── the reader is genuinely side-effect free ────────────────────────

    /**
     * `isResting()` must not touch the lock at all. Proven here structurally
     * — the method body may not mention AdvisoryLock — and behaviourally,
     * against a real lock, by the integration suite.
     */
    public function testIsRestingContainsNoLockCall(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Support/OnchainCircuitBreaker.php'
        );
        self::assertMatchesRegularExpression(
            '/function isResting\(int \$chainId\): bool\s*\{[^}]*\}/s',
            $src,
            'isResting() must exist with the expected signature'
        );
        preg_match('/function isResting\(int \$chainId\): bool\s*\{(.*?)\n    \}/s', $src, $m);
        self::assertNotEmpty($m, 'the isResting() body must be readable');
        self::assertStringNotContainsString(
            'AdvisoryLock',
            $m[1],
            'isResting() must never acquire or release the probe lock'
        );
    }
}
