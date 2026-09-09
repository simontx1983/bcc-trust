<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\SettingsPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\ProviderFailureKind;
use BCC\Trust\Onchain\ValueObjects\ProviderRequestClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A STATUS PAGE MUST OBSERVE WITHOUT CHANGING WHAT IT OBSERVES.
 *
 * ── THE DEFECT THIS PINS ────────────────────────────────────────────────
 * `OnchainCircuitBreaker::isOpen()` has a side effect: in the HALF-OPEN
 * window it atomically claims a cluster-wide advisory lock, and whoever wins
 * it is expected to go and make a request. The breaker admin tab called it
 * ONCE PER CHAIN, so an administrator opening that tab could claim the single
 * probe slot for EVERY chain at once — silently turning away the worker that
 * had waited out the cooldown. Looking at a dashboard is the last thing
 * anyone suspects of causing an outage.
 *
 * `phase()` was added in PR 7.6 exactly so observing is not probing, and
 * until now nothing in production used it.
 *
 * ⚠ THE BREAKER AND THE PAGE ARE BOTH REAL HERE. Only the advisory lock is a
 * double, and it is a SPY: every acquire attempt is recorded, so "no lock was
 * requested" is observed rather than inferred from a render that happened to
 * finish.
 */
#[CoversClass(SettingsPage::class)]
#[CoversClass(ProviderFailureKind::class)]
#[CoversClass(ProviderRequestClass::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreakerAdminObservationTest extends TestCase
{
    private const CHAIN = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/breaker-admin-stubs.php';

        \BccBreakerStore::reset();
        ChainRepository::reset();
        ChainRepository::seed([['id' => self::CHAIN, 'slug' => 'cosmos']]);
    }

    /** Render the real private tab and capture its markup. */
    private function render(): string
    {
        $m = new \ReflectionMethod(SettingsPage::class, 'render_rpc_breakers_tab');
        $m->setAccessible(true);
        ob_start();
        $m->invoke(null);

        return (string) ob_get_clean();
    }

    /**
     * Just the table body — the row cells, without the legend or the footnote.
     *
     * ⚠ ASSERT ON THE ROW, NOT THE PAGE. The footnote below the table
     * EXPLAINS the phrase “Not recorded”, so a whole-page
     * `assertStringContainsString('Not recorded', …)` passes even when the
     * cell says something else entirely, and the matching absence assertion
     * can never pass at all. Both were live in an earlier version of this
     * file: one vacuous, one impossible.
     */
    private function renderRows(): string
    {
        $html  = $this->render();
        $start = strpos($html, '<tbody>');
        $end   = strpos($html, '</tbody>');
        self::assertNotFalse($start, 'the table body must be present');
        self::assertNotFalse($end, 'the table body must be closed');

        return substr($html, $start, $end - $start);
    }

    /** Put the breaker into HALF-OPEN: at threshold, cooldown elapsed. */
    private function seedHalfOpen(?string $kind = null, ?string $class = null): void
    {
        $openedAt = time() - (OnchainCircuitBreaker::COOLDOWN_SECONDS + 60);
        \BccBreakerStore::seedOpen(self::CHAIN, 8, $openedAt);
        if ($kind !== null || $class !== null) {
            foreach (['bcc_cb_' . self::CHAIN => 'transients', 'bcc_circuit:cb_' . self::CHAIN => 'cache'] as $k => $store) {
                if ($store === 'transients') {
                    \BccBreakerStore::$transients[$k]['kind']          = $kind;
                    \BccBreakerStore::$transients[$k]['request_class'] = $class;
                } else {
                    \BccBreakerStore::$cache[$k]['kind']          = $kind;
                    \BccBreakerStore::$cache[$k]['request_class'] = $class;
                }
            }
        }
    }

    // ── 1/2: rendering takes no lock, ever, however often ───────────────

    public function testRenderingDuringHalfOpenRequestsNoAdvisoryLock(): void
    {
        $this->seedHalfOpen();
        self::assertSame(
            OnchainCircuitBreaker::PHASE_HALF_OPEN,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'anti-vacuity: the breaker must actually be half-open'
        );
        \BccBreakerStore::$lockAttempts = [];

        $html = $this->render();

        self::assertNotSame('', $html, 'anti-vacuity: the page must have rendered');
        self::assertSame(
            [],
            \BccBreakerStore::$lockAttempts,
            'rendering a status page must not request the half-open probe lock'
        );
        self::assertSame([], \BccBreakerStore::$locks, 'and must hold no lock afterwards');
    }

    public function testRenderingRepeatedlyStillConsumesNoProbe(): void
    {
        $this->seedHalfOpen();
        \BccBreakerStore::$lockAttempts = [];

        for ($i = 0; $i < 5; $i++) {
            $this->render();
        }

        self::assertSame([], \BccBreakerStore::$lockAttempts, 'five renders, zero lock attempts');
        self::assertSame(
            OnchainCircuitBreaker::PHASE_HALF_OPEN,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'the phase must be exactly as it was before rendering'
        );
    }

    /** Rendering changes no breaker state at all — counter, state or clock. */
    public function testRenderingChangesNoBreakerState(): void
    {
        $this->seedHalfOpen(ProviderFailureKind::HTTP_5XX, ProviderRequestClass::SMART_QUERY);
        $before = [
            'options'    => \BccBreakerStore::$options,
            'transients' => \BccBreakerStore::$transients,
            'cache'      => \BccBreakerStore::$cache,
        ];

        $this->render();

        self::assertSame($before['options'], \BccBreakerStore::$options);
        self::assertSame($before['transients'], \BccBreakerStore::$transients);
        self::assertSame($before['cache'], \BccBreakerStore::$cache);
    }

    // ── 3/4: the probe is still there for the caller entitled to it ─────

    /**
     * ⚠ THE POINT OF THE WHOLE CHANGE. After any number of renders the
     * operational caller must still be able to claim the ONE probe — and the
     * next operational caller must be refused, proving the slot is genuinely
     * exclusive and that the page simply never touched it.
     */
    public function testAfterRenderingAnOperationalCallerStillClaimsExactlyOneProbe(): void
    {
        $this->seedHalfOpen();
        $this->render();
        $this->render();
        \BccBreakerStore::$lockAttempts = [];

        // First operational caller: admitted (isOpen() === false means "go").
        self::assertFalse(
            OnchainCircuitBreaker::isOpen(self::CHAIN),
            'the probe slot must still be available to a real worker'
        );
        self::assertSame(
            ['bcc_cb_probe_' . self::CHAIN],
            \BccBreakerStore::$lockAttempts,
            'exactly one lock attempt, made by the operational caller'
        );

        // Second operational caller: refused while the probe is held.
        self::assertTrue(
            OnchainCircuitBreaker::isOpen(self::CHAIN),
            'a second worker must be blocked while the probe is claimed'
        );
    }

    // ── 5: every phase renders, with honest wording ─────────────────────

    /**
     * @return array<string, array{0: int, 1: int|null, 2: string}>
     */
    public static function phases(): array
    {
        return [
            'closed'    => [0, null, 'CLOSED'],
            'open'      => [8, -10, 'OPEN / cooldown'],
            'half-open' => [8, -(OnchainCircuitBreaker::COOLDOWN_SECONDS + 60), 'HALF-OPEN'],
        ];
    }

    #[DataProvider('phases')]
    public function testEachPhaseRendersItsLabel(int $failures, ?int $offset, string $expected): void
    {
        if ($offset !== null) {
            \BccBreakerStore::seedOpen(self::CHAIN, $failures, time() + $offset);
        }

        $html = $this->render();

        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString('cosmos', $html);
        self::assertSame([], \BccBreakerStore::$lockAttempts, 'no phase may cost a lock');
    }

    /**
     * ⚠ HALF-OPEN MUST NOT READ AS RECOVERY. The legend has to say a cautious
     * probe MAY be attempted — Run 8 sat half-open for ~100 minutes having
     * recovered from nothing.
     */
    public function testHalfOpenWordingDoesNotImplyRecovery(): void
    {
        $html = $this->render();

        self::assertStringContainsString('one cautious probe may be attempted', $html);
        foreach (['recovered', 'healthy again', 'back to normal', 'provider is fine'] as $claim) {
            self::assertStringNotContainsStringIgnoringCase($claim, $html);
        }
    }

    // ── 6/7: bounded attribution renders as operator wording ────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failureReasons(): array
    {
        return [
            'rate limited' => [ProviderFailureKind::RATE_LIMITED, 'Provider rate limit'],
            'server error' => [ProviderFailureKind::HTTP_5XX, 'Provider server error'],
            'transport'    => [ProviderFailureKind::TRANSPORT, 'Network connection failure'],
        ];
    }

    #[DataProvider('failureReasons')]
    public function testEachBoundedReasonRendersAsOperatorWording(string $kind, string $label): void
    {
        $this->seedHalfOpen($kind, ProviderRequestClass::STANDARD_REQUEST);

        $rows = $this->renderRows();

        self::assertStringContainsString($label, $rows);
        // ⚠ THE RAW TOKEN MUST NOT APPEAR. This page already speaks in
        // sentences ("CLOSED"), so it has a presentation boundary to honour.
        self::assertStringNotContainsString($kind, $rows, "the raw token '{$kind}' must not be rendered");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requestClasses(): array
    {
        return [
            'smart query' => [ProviderRequestClass::SMART_QUERY, 'Smart contract query'],
            'standard'    => [ProviderRequestClass::STANDARD_REQUEST, 'Standard provider request'],
            'batch'       => [ProviderRequestClass::BATCH_REQUEST, 'Batch provider request'],
        ];
    }

    #[DataProvider('requestClasses')]
    public function testEachRequestClassRendersSafely(string $class, string $label): void
    {
        $this->seedHalfOpen(ProviderFailureKind::TRANSPORT, $class);

        $rows = $this->renderRows();

        self::assertStringContainsString($label, $rows);
        self::assertStringNotContainsString($class, $rows, "the raw value '{$class}' must not be rendered");
    }

    // ── 8: legacy records render safely ─────────────────────────────────

    /**
     * ⚠ A PRE-PR-7.8 RECORD HAS NO ATTRIBUTION AT ALL. It must read as
     * "not recorded" — never as a success (the chain IS broken) and never as
     * a specific provider fault (nobody measured one).
     */
    public function testLegacyRecordsWithoutAttributionRenderAsNotRecorded(): void
    {
        // Written exactly the way the previous version wrote it: two keys.
        \BccBreakerStore::$transients['bcc_cb_' . self::CHAIN] = ['failures' => 8, 'opened_at' => time() - 10];
        \BccBreakerStore::$options['_bcc_cb_counter_' . self::CHAIN] = 8;

        $rows = $this->renderRows();

        self::assertStringContainsString('OPEN / cooldown', $rows, 'the phase must still be right');
        self::assertStringContainsString('Not recorded', $rows);
        foreach (['Provider rate limit', 'Provider server error', 'Network connection failure'] as $blame) {
            self::assertStringNotContainsString($blame, $rows, 'a legacy row must not be blamed on a provider');
        }
    }

    /** A never-failed chain explains nothing, and is not "Not recorded" either. */
    public function testAHealthyChainShowsNoFailureReason(): void
    {
        $rows = $this->renderRows();

        self::assertStringContainsString('CLOSED', $rows);
        self::assertStringNotContainsString(
            'Not recorded',
            $rows,
            'a chain that never failed has nothing to explain, so the cell is a dash'
        );
        self::assertStringNotContainsString('Provider server error', $rows);
    }

    // ── 9: hostile stored values can never be echoed ────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileValues(): array
    {
        return [
            'script tag'   => ['<script>alert(1)</script>'],
            'sql'          => ["'; DROP TABLE wp_options; --"],
            'credential'   => ['https://user:secret@node.example/path'],
            'exception'    => ['rpc error: code = Unavailable desc = connection refused'],
            'long'         => [str_repeat('X', 400)],
        ];
    }

    #[DataProvider('hostileValues')]
    public function testAHostileStoredValueIsNeverEchoed(string $hostile): void
    {
        $this->seedHalfOpen($hostile, $hostile);

        $rows = $this->renderRows();

        foreach (['script', 'DROP TABLE', 'secret', 'rpc error', 'XXXXXXXXXX'] as $fragment) {
            self::assertStringNotContainsStringIgnoringCase($fragment, $rows);
        }
        self::assertStringContainsString('Not recorded', $rows, 'an unrecognised value reads as not recorded');
    }

    // ── 12: every isOpen() caller is intentionally classified ───────────

    /**
     * ⚠ THE CLASSIFICATION, MADE EXECUTABLE.
     *
     * Every `isOpen()` call site is either OPERATIONAL ADMISSION — it is
     * about to make a provider request and is entitled to claim the probe —
     * or READ-ONLY OBSERVATION, which must use `phase()` / `getAllStatus()`.
     * This test fails when a new call site appears anywhere, so the question
     * "is this caller allowed to consume a probe?" can never again be
     * answered by nobody.
     */
    public function testEveryIsOpenCallerIsIntentionallyClassified(): void
    {
        $root = dirname(__DIR__, 2) . '/app';
        self::assertDirectoryExists($root);

        // The complete OPERATIONAL ADMISSION allow-list. Each one gates work
        // that immediately goes on to contact a provider.
        $operational = [
            'Domain/Onchain/Services/ChainRefreshService.php',  // gates a validator-index fetch
            'Domain/Onchain/Services/EnrichmentScheduler.php',  // gates an enrichment call
            'Domain/Onchain/Support/ApiRetry.php',              // the transport itself
            'Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php',
            'Domain/Onchain/Workers/NftEthIndexerWorker.php',
        ];
        sort($operational);

        $found = [];
        $it    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            // ⚠ COMMENTS STRIPPED WITH token_get_all(), NEVER A REGEX — this
            // file tree is dense with `{@see …::isOpen()}` references, and a
            // raw grep would classify documentation as a call site.
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
            if (strpos($code, 'OnchainCircuitBreaker::isOpen(') === false) {
                continue;
            }
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
        sort($found);

        self::assertNotSame([], $found, 'anti-vacuity: some caller must exist');
        self::assertSame(
            $operational,
            $found,
            "every isOpen() caller must be an approved OPERATIONAL ADMISSION site.\n"
                . "An unexpected file here is a status/read-only surface that would\n"
                . "consume the half-open probe merely by being read."
        );

        // …and no admin surface may appear among them.
        foreach ($found as $file) {
            self::assertStringNotContainsString('/Admin/', $file, 'no admin surface may call isOpen()');
            self::assertStringNotContainsString('/REST/', $file, 'no REST reader may call isOpen()');
        }
    }
}
