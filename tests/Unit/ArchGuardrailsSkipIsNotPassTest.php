<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `arch-guardrails.sh` must not be able to report PASS after scanning
 * nothing.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * Run from a git worktree, the script printed:
 *
 *     SKIP bcc-trust (not found)
 *     PASS: All guardrails satisfied        (exit 0)
 *
 * Two separate faults, and the issue is right to separate them (#203):
 *
 *   1. RESOLUTION. `PLUGINS_DIR` is `$SCRIPT_DIR/../..`, so the script looks
 *      for a sibling directory named after the plugin. In a worktree the
 *      checkout root IS the plugin and is named after the worktree
 *      (`wt-main`, `bcc-trust-s8`), so that path does not exist.
 *   2. REPORTING. Independently of (1), every check returned early, the
 *      violation count stayed at zero, and zero violations was rendered as
 *      PASS. A scan that matched no files is indistinguishable from a clean
 *      scan if the only thing counted is violations.
 *
 * Fault 2 is the one worth a permanent test. It is the recurring false-green
 * class in this repository: a guard that looks in the wrong place reports
 * exactly what a guard that found nothing wrong reports.
 *
 * ── WHAT THIS TEST CAN AND CANNOT DO ────────────────────────────────────
 * It asserts the SOURCE of the script, not its behaviour: no subprocess, no
 * `bash`, no synthetic plugin layout, so it is deterministic and runs in the
 * unit suite on every PR. That is a deliberate trade. It will catch the
 * logic being reverted or weakened; it cannot catch the logic being correct
 * and the script still mis-scanning for some third reason.
 *
 * The behavioural evidence belongs in the PR that introduces the fix, where
 * the before/after exit codes were measured from a real worktree. This test
 * is the ratchet that keeps it.
 */
final class ArchGuardrailsSkipIsNotPassTest extends TestCase
{
    private const SCRIPT = 'scripts/arch-guardrails.sh';

    private static function script(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::SCRIPT;
        $src  = file_get_contents($path);

        self::assertIsString($src, self::SCRIPT . ' must be readable');
        self::assertStringContainsString(
            'TOTAL_VIOLATIONS',
            (string) $src,
            'anti-vacuity: ' . self::SCRIPT . ' must be the guard script — if this '
                . 'string is missing, every assertion below is about the wrong file'
        );

        return (string) $src;
    }

    /** Zero violations out of zero plugins must not exit 0. */
    public function testTheExitCodeAccountsForHavingScannedNothing(): void
    {
        $src = self::script();

        self::assertMatchesRegularExpression(
            '/exit \$\(\s*\[\[\s*\$TOTAL_VIOLATIONS -eq 0 && \$TOTAL_SCANNED -gt 0\s*\]\]/',
            $src,
            'the exit code must require BOTH no violations AND at least one plugin '
                . 'scanned. Dropping the second condition restores the #203 false green: '
                . 'a run that resolved no plugin exits 0.'
        );
    }

    /**
     * The order of the summary branches is load-bearing: "no violations" is
     * true of a run that scanned nothing, so it must be tested last.
     */
    public function testScannedNothingIsReportedBeforeTheViolationCount(): void
    {
        $src = self::script();

        $nothing   = strpos($src, 'TOTAL_SCANNED -eq 0');
        $violation = strpos($src, 'TOTAL_VIOLATIONS -gt 0');

        self::assertIsInt($nothing, 'the summary must have a scanned-nothing branch');
        self::assertIsInt($violation, 'the summary must still report violations');

        self::assertLessThan(
            $violation,
            $nothing,
            'the scanned-nothing branch must come FIRST. Behind the violation branch '
                . 'it is unreachable for the case it exists to catch, because a run '
                . 'that scanned nothing also has zero violations.'
        );

        self::assertMatchesRegularExpression(
            '/scanned NOTHING/',
            $src,
            'the scanned-nothing outcome must be named in the output, not merely '
                . 'encoded in the exit code — an operator reading the terminal has to '
                . 'be able to tell this apart from a clean run'
        );
    }

    /** Fault 1: the worktree must resolve, and by the plugin's own entry file. */
    public function testThePluginIsResolvedFromAWorktreeRootByItsEntryFile(): void
    {
        $src = self::script();

        self::assertStringContainsString(
            'plugin_root()',
            $src,
            'plugin resolution must go through one resolver, so the worktree case is '
                . 'handled in a single place rather than at each of the nine check sites'
        );

        self::assertMatchesRegularExpression(
            '/-f "\$self_root\/\$plugin\.php"/',
            $src,
            'the worktree case must be identified by the plugin\'s own entry file. '
                . 'Matching on the directory NAME cannot work: a worktree is named '
                . 'after the worktree, not after the plugin.'
        );

        self::assertSame(
            0,
            preg_match_all('/local plugin_dir="\$PLUGINS_DIR\/\$plugin"/', $src),
            'no check may still build the plugin path by hand — each one that does is '
                . 'a site that silently keeps the old worktree-blind behaviour'
        );

        self::assertGreaterThanOrEqual(
            9,
            preg_match_all('/plugin_root "\$plugin"/', $src),
            'every check site must route through the resolver'
        );
    }

    /**
     * A skip that is NOT the whole run stays non-fatal, and must still be
     * visible. An all-plugins run legitimately skips
     * `blue-collar-crypto-peepso-integration`, which is not present in this
     * repository, and turning that into a failure would break the only
     * invocation that scans everything.
     */
    public function testAPartialSkipIsReportedWithoutFailingTheRun(): void
    {
        $src = self::script();

        self::assertStringContainsString(
            'SKIPPED_PLUGINS+=(',
            $src,
            'skipped plugins must be collected, not just printed and forgotten'
        );

        self::assertStringContainsString(
            'skipped_note',
            $src,
            'the summary must carry the skip count, so a partial scan cannot read as '
                . 'a full one'
        );

        self::assertMatchesRegularExpression(
            '/PASS\$\{NC\}: All guardrails satisfied \(\$TOTAL_SCANNED scanned\)/',
            $src,
            'the PASS line must state how many plugins were scanned — a bare "PASS" '
                . 'is what made the original defect invisible'
        );
    }
}
