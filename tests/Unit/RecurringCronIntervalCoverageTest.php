<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every hook declared `recurring` has an interval that something registers
 * AND that registrar is actually hooked.
 *
 * ── THE FAILURE CLASS ───────────────────────────────────────────────────
 * `wp_schedule_event()` refuses an unregistered recurrence, so a `recurring`
 * hook whose interval nothing declares can NEVER be scheduled. The event is
 * never created and the `bcc_expected_cron_hooks` drift detector reports it
 * MISSING for ever — silently, because nothing notices a cron job that
 * simply does not exist.
 *
 * Staging measured it once already: `expected=37, scheduled=36,
 * MISSING=bcc_discovery_run_maintenance`. That is the PR 7A failure.
 *
 * `DiscoveryMaintenanceScheduleTest::testEveryDeclaredRecurringHookCanBeScheduled`
 * asserted this for the single hook that file was about. S6 retired that hook
 * and the assertion went with it, leaving the invariant unowned. See #285.
 *
 * ── WHY SOURCE ANALYSIS, AND WHAT IT DOES NOT PROVE ─────────────────────
 * The issue proposes an integration test applying the real `cron_schedules`
 * filter chain. Right instinct, wrong instrument here: this repository's
 * integration harness provides `$wpdb`, not WordPress, so there is no real
 * chain to apply — and a test with a hand-written `wp_get_schedules()`
 * measures the STUB, which the issue says itself.
 *
 * So this reads both sides from source, and states plainly what that buys:
 * every interval a `recurring` hook names is declared by a registrar that is
 * hooked onto `cron_schedules`. It does not prove the filter executed at
 * runtime. It does catch the mistake that actually happened.
 *
 * ⚠⚠ ONE HOLE, FOUND BY A SURVIVING MUTANT AND LEFT OPEN DELIBERATELY.
 * Source analysis cannot see REACHABILITY. Wrapping a live registration in
 * `if (false) { ... }` leaves the declaration text in place, so this test
 * still counts the interval as declared and stays green while the interval
 * is no longer registered at runtime. Measured, not theorised: that exact
 * mutation survived.
 *
 * What is caught is every mutation anyone would plausibly make:
 *
 *   - a `recurring` entry naming an interval nothing declares  -> caught
 *   - a registration block DELETED                             -> caught,
 *     naming all three hooks that used it
 *   - a registrar left in place but never `add_filter`ed       -> caught
 *   - class-constant resolution broken                         -> caught
 *   - a registration made unreachable without deleting it      -> MISSED
 *
 * Closing the last one needs the real filter chain, which this harness does
 * not have. It is recorded here rather than hidden because a test whose
 * limits are unstated is the thing that produced #285 in the first place.
 *
 * Three things make it honest rather than decorative:
 *
 *   1. The `recurring` map is `require`d, not parsed. `includes/cron-hooks.php`
 *      is a pure literal array with no WordPress dependency, so the test reads
 *      the REAL map — 31 hooks, 8 intervals — and cannot drift from it.
 *   2. Registrars are DISCOVERED, not listed, so a new one anywhere under
 *      `app/`, `includes/` or `bcc-trust.php` is picked up and a
 *      hand-maintained list of five cannot rot.
 *   3. ⭐ A registrar only counts if it is WIRED. A `cron_schedules` callback
 *      that no `add_filter()` ever attaches declares nothing at runtime, and
 *      counting it would be the same false green one level down. The
 *      callbacks live in service classes that never mention the hook name —
 *      `bcc-trust.php` does the hooking — so the two halves have to be
 *      matched up rather than assumed.
 *
 * ⚠⚠ AND IT RESOLVES CLASS CONSTANTS, the specific trap recorded on the
 * issue. `bcc_minute` is registered as
 * `$schedules[WatchBatchAggregator::SWEEP_INTERVAL]`, so a grep for
 * `schedules['bcc_minute']` finds nothing and reports a working hook broken.
 * That mistake was made during S6 and retracted (`b8d876b`).
 * `testTheConstantKeyedRegistrarIsResolved` exists so this test can never be
 * the one making it.
 */
final class RecurringCronIntervalCoverageTest extends TestCase
{
    /**
     * WordPress core's own recurrences. `weekly` is listed because core
     * registers it on current versions; the repo registers `bcc_weekly`
     * anyway, precisely because core's arrived late and inconsistently.
     */
    private const CORE_INTERVALS = ['hourly', 'twicedaily', 'daily', 'weekly'];

    /**
     * Custom intervals known to be in use when this was written. An
     * anti-vacuity floor, not an allowlist — discovery is what the test
     * relies on; this only proves discovery found something.
     */
    private const KNOWN_CUSTOM = [
        'bcc_one_minute',
        'bcc_five_minutes',
        'bcc_weekly',
        'bcc_thirty_seconds',
        'bcc_minute',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, array{interval: string, description: string}> */
    private static function recurring(): array
    {
        $map = require self::root() . '/includes/cron-hooks.php';

        self::assertIsArray($map, 'cron-hooks.php must return an array');
        self::assertArrayHasKey('recurring', $map, 'cron-hooks.php must declare `recurring`');

        /** @var array<string, array{interval: string, description: string}> $recurring */
        $recurring = $map['recurring'];

        self::assertGreaterThan(
            25,
            count($recurring),
            'anti-vacuity: the recurring map must be populated (found '
                . count($recurring) . '). An empty map satisfies every assertion below.'
        );

        return $recurring;
    }

    /** @return list<string> every shipped .php, plus the entry file */
    private static function shippedFiles(): array
    {
        $files = [self::root() . '/bcc-trust.php'];

        foreach (['app', 'includes'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    self::root() . '/' . $dir,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Comments stripped with the tokenizer, not a regex: a commented-out
     * registrar must not count as registering anything, and an interval named
     * only in a docblock must not count either.
     */
    private static function withoutComments(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * `ClassName::CONST = 'literal'` across the whole tree in one pass — the
     * technique `scripts/contract-parity-guard.php` uses for cross-file
     * constants.
     *
     * @param  list<string>          $files
     * @return array<string, string>
     */
    private static function constantLiterals(array $files): array
    {
        $out = [];

        foreach ($files as $path) {
            preg_match_all(
                '/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*\'([^\']*)\'/',
                self::withoutComments((string) file_get_contents($path)),
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $out[$match[1]] = $match[2];
            }
        }

        return $out;
    }

    /**
     * Which files hold a registrar that is actually attached to
     * `cron_schedules`.
     *
     * Two shapes, both present in this repo:
     *   - the callback is in the SAME file as the `add_filter` — a closure
     *     (`Plugin::registerAsyncJobs`) or `[__CLASS__, 'method']`
     *     (`ChainRefreshService::register`);
     *   - the callback is in ANOTHER file, named by FQCN in `bcc-trust.php`
     *     (`CronService`, `PageReadModelSync`, `DisputeScheduler`,
     *     `ChainRefreshService`). Those classes never mention the hook name,
     *     so they can only be found through the `add_filter` that wires them.
     *
     * @param  list<string>  $files
     * @return array{files: list<string>, classes: list<string>}
     */
    private static function wiredRegistrars(array $files): array
    {
        $wiredFiles   = [];
        $wiredClasses = [];

        foreach ($files as $path) {
            $src = self::withoutComments((string) file_get_contents($path));

            preg_match_all(
                // ⚠ The window is NOT terminated by `);`. A closure body is longer
                // than any sane window, so requiring the closing paren missed
                // Plugin.php's closure entirely and reported bcc_minute as
                // unregistered. Over-capturing into the next statement is harmless:
                // a file only counts once it ALSO declares a schedule entry, so the
                // two conditions are ANDed.
                '/add_filter\s*\(\s*[\'"]cron_schedules[\'"]\s*,(.{0,200})/s',
                $src,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $callable = $match[1];

                // Closure, or a self-reference: this file holds the callback.
                if (str_contains($callable, 'function')
                    || str_contains($callable, '__CLASS__')
                    || str_contains($callable, 'self::class')
                    || str_contains($callable, 'static::class')
                ) {
                    $wiredFiles[] = $path;
                }

                // A class named explicitly — keep the BASENAME, which is the
                // file name under this repo's PSR-4 layout.
                if (preg_match_all(
                    '/([A-Za-z_][A-Za-z0-9_]*)\s*::\s*class/',
                    $callable,
                    $named,
                    PREG_PATTERN_ORDER
                )) {
                    foreach ($named[1] as $class) {
                        $wiredClasses[] = $class;
                    }
                }
            }
        }

        return [
            'files'   => array_values(array_unique($wiredFiles)),
            'classes' => array_values(array_unique($wiredClasses)),
        ];
    }

    /**
     * Interval keys declared by a WIRED registrar.
     *
     * @return array{keys: list<string>, constants_resolved: int, registrars: list<string>}
     */
    private static function declared(): array
    {
        $files     = self::shippedFiles();
        $constants = self::constantLiterals($files);
        $wired     = self::wiredRegistrars($files);

        $keys       = [];
        $registrars = [];
        $resolved   = 0;

        foreach ($files as $path) {
            $basename = basename($path, '.php');

            $isWired = in_array($path, $wired['files'], true)
                || in_array($basename, $wired['classes'], true);

            if (!$isWired) {
                continue;
            }

            $src = self::withoutComments((string) file_get_contents($path));

            // Only ASSIGNMENTS of a whole schedule entry count. A read such as
            // `$schedules[$slug]['interval']` (CronHealthSnapshot) declares
            // nothing, and a variable key cannot be resolved statically.
            if (!preg_match_all(
                '/\$schedules\s*\[\s*(?:\'([a-z0-9_]+)\'|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::([A-Z][A-Z0-9_]*))\s*\]\s*=/',
                $src,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            $found = false;

            foreach ($matches as $match) {
                // The assigned value must look like a WP schedule entry.
                // Without this, any unrelated `$schedules[...] =` would widen
                // the available set and could mask a genuinely missing
                // interval — a false negative in the direction that matters.
                $after = substr($src, (int) $match[0][1], 260);
                if (!str_contains($after, "'interval'")) {
                    continue;
                }

                if (($match[1][0] ?? '') !== '') {
                    $keys[] = $match[1][0];
                    $found  = true;
                    continue;
                }

                $name = $match[2][0] ?? '';
                if ($name !== '' && isset($constants[$name])) {
                    $keys[] = $constants[$name];
                    $resolved++;
                    $found = true;
                }
            }

            if ($found) {
                $registrars[] = substr($path, strlen(self::root()) + 1);
            }
        }

        return [
            'keys'               => array_values(array_unique($keys)),
            'constants_resolved' => $resolved,
            'registrars'         => array_values(array_unique($registrars)),
        ];
    }

    public function testTheRegistrarSweepFindsSomethingToMeasure(): void
    {
        $declared = self::declared();

        self::assertGreaterThanOrEqual(
            5,
            count($declared['registrars']),
            'anti-vacuity: the sweep must find the registrar files — found '
                . count($declared['registrars']) . ': '
                . implode(', ', $declared['registrars'])
        );

        foreach (self::KNOWN_CUSTOM as $interval) {
            self::assertContains(
                $interval,
                $declared['keys'],
                'the sweep must find the known custom interval ' . $interval
                    . '. If it does not, this test is measuring nothing and every '
                    . '"interval resolves" result below is meaningless. Found: '
                    . implode(', ', $declared['keys'])
            );
        }
    }

    /**
     * ⚠ The documented trap. `bcc_minute` is keyed by a class constant, so a
     * literal-only sweep reports it missing and declares a working hook broken.
     */
    public function testTheConstantKeyedRegistrarIsResolved(): void
    {
        $declared = self::declared();

        self::assertGreaterThan(
            0,
            $declared['constants_resolved'],
            'at least one interval key must be resolved THROUGH a class constant. '
                . 'bcc_minute is registered as '
                . '$schedules[WatchBatchAggregator::SWEEP_INTERVAL]; if constant '
                . 'resolution silently stops working, this test starts reporting that '
                . 'hook as broken when it is not — the S6 mistake, retracted in b8d876b'
        );

        self::assertContains(
            'bcc_minute',
            $declared['keys'],
            'the constant-keyed interval must resolve to its literal value'
        );
    }

    /** The invariant itself. */
    public function testEveryRecurringHookNamesAnIntervalSomethingRegisters(): void
    {
        $recurring = self::recurring();
        $declared  = self::declared();
        $available = array_merge(self::CORE_INTERVALS, $declared['keys']);

        $orphans = [];

        foreach ($recurring as $hook => $spec) {
            $interval = (string) ($spec['interval'] ?? '');

            if ($interval === '' || !in_array($interval, $available, true)) {
                $orphans[$hook] = $interval === '' ? '(none declared)' : $interval;
            }
        }

        self::assertSame(
            [],
            $orphans,
            'a recurring hook names an interval that no WIRED registrar declares, so '
                . "wp_schedule_event() will refuse it and the drift detector will "
                . "report it MISSING for ever:\n  "
                . implode(
                    "\n  ",
                    array_map(
                        static fn (string $h, string $i): string => $h . ' => ' . $i,
                        array_keys($orphans),
                        array_values($orphans)
                    )
                )
                . "\n  available: " . implode(', ', $available)
        );
    }

    /**
     * The negative control. Without it, a checker that treated every interval
     * as available would pass the case above on any input.
     */
    public function testAnUnregisteredIntervalWouldBeRejected(): void
    {
        $declared  = self::declared();
        $available = array_merge(self::CORE_INTERVALS, $declared['keys']);

        self::assertNotContains(
            'bcc_interval_that_nothing_registers',
            $available,
            'the available set must not accept an arbitrary name — if it does, the '
                . 'invariant test above cannot fail and is decorative'
        );
    }

    /**
     * A registered interval that no recurring hook names is NOT a defect —
     * `every_4_hours` is exactly that today. Stated so nobody reads this test
     * as asserting the reverse, and so a narrowing of the sweep is visible.
     */
    public function testAnIntervalRegisteredButUnusedIsTolerated(): void
    {
        $recurring = self::recurring();
        $declared  = self::declared();

        $used   = array_unique(array_column($recurring, 'interval'));
        $unused = array_values(array_diff($declared['keys'], $used));

        self::assertContains(
            'every_4_hours',
            $declared['keys'],
            'anti-vacuity: every_4_hours is registered by ChainRefreshService and used '
                . 'by no recurring hook; if it drops out of the declared set, the sweep '
                . 'has narrowed rather than the code having changed'
        );

        self::assertContains(
            'every_4_hours',
            $unused,
            'every_4_hours is expected to be registered-but-unused; if a recurring '
                . 'hook now uses it, say so here rather than letting this drift'
        );
    }
}
