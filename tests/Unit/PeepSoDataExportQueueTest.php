<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\PeepSoDataExportQueue;
use BCC\Trust\Tests\Support\BootFileScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-581 (private tracker): PeepSo's data-export queue stays closed; BCC
 * handles data requests.
 *
 * The queue screen's export tab is replaced by a closed tab, and a request
 * to run the queue is refused at `admin_init` for every caller. Each test
 * name says which half it holds, so loosening either fails a named case.
 */
#[CoversClass(PeepSoDataExportQueue::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoDataExportQueueTest extends TestCase
{
    /** PeepSo's own export-queue screen: what the closed tab must replace. */
    private const PEEPSO_QUEUE_SCREEN = ['PeepSoAdminRequestData', 'administration'];

    private const CLOSED = 'PeepSo&#039;s data-export queue stays closed; BCC handles data requests.';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/peepso-export-queue-stubs.php';
        $GLOBALS['__bcc_xq'] = ['hooks' => []];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    /** @return array<string, array{0: callable, 1: int}> hook => [callback, priority] */
    private static function hooks(): array
    {
        $out = [];
        foreach ($GLOBALS['__bcc_xq']['hooks'] as [$hook, $callback, $priority]) {
            $out[$hook] = [$callback, $priority];
        }
        return $out;
    }

    public function testTheTabIsReplacedLastAndTheQueueRunIsRefusedFirst(): void
    {
        PeepSoDataExportQueue::register();

        self::assertSame(
            [
                'peepso_admin_queue_tabs' => [[PeepSoDataExportQueue::class, 'closeExportTab'], PHP_INT_MAX],
                'admin_init'              => [[PeepSoDataExportQueue::class, 'refuseQueueRun'], 1],
            ],
            self::hooks()
        );
    }

    /**
     * bcc-trust.php registers this at boot: one call at file scope, so both
     * hooks exist before `admin_init` and before PeepSo builds its Queues
     * screen.
     */
    public function testItRegistersAtBoot(): void
    {
        self::assertSame(
            [true],
            BootFileScope::calls('bcc-trust.php', '\\BCC\\Trust\\Core\\Security\\PeepSoDataExportQueue', 'register'),
            'exactly one register() call, at file scope'
        );
    }

    // ── 1. the tab ──────────────────────────────────────────────────────

    /**
     * PeepSo's queue screen (PeepSoQueue::get_tabs(), PeepSo 9.0.5.5) runs
     * the filter on an EMPTY list, then merges its default tabs UNDER the
     * result: `array_merge($default_tabs, $filtered)`. Reproduced here,
     * defaults included, so the merged 'gdpr' entry is what PeepSo would
     * render: the closed tab, never PeepSo's own export-queue screen.
     */
    public function testTheMergedExportTabIsTheClosedTabNeverPeepSosQueueScreen(): void
    {
        PeepSoDataExportQueue::register();

        $defaults = [
            'email' => ['label' => 'Outgoing email', 'tab' => 'email', 'function' => ['PeepSoAdminMailQueue', 'administration'], 'cat' => 'foundation'],
            'gdpr'  => ['label' => 'GDPR requests', 'tab' => 'gdpr', 'function' => self::PEEPSO_QUEUE_SCREEN, 'cat' => 'foundation'],
        ];
        $earlier = static fn(array $tabs): array => $tabs + ['addon' => ['label' => 'An add-on', 'tab' => 'addon', 'function' => 'addon_screen']];

        $filtered = $earlier([]);
        foreach ($GLOBALS['__bcc_xq']['hooks'] as [$hook, $callback]) {
            if ($hook === 'peepso_admin_queue_tabs') {
                $filtered = $callback($filtered);
            }
        }
        $tabs = array_merge($defaults, $filtered);

        self::assertSame([PeepSoDataExportQueue::class, 'renderClosedTab'], $tabs['gdpr']['function']);
        self::assertNotSame(self::PEEPSO_QUEUE_SCREEN, $tabs['gdpr']['function'], 'PeepSo\'s export-queue screen is never rendered');
        self::assertSame('gdpr', $tabs['gdpr']['tab'], 'the tab keeps its key, so the screen still resolves it');
        self::assertSame(['PeepSoAdminMailQueue', 'administration'], $tabs['email']['function'], 'other default tabs untouched');
        self::assertSame('addon_screen', $tabs['addon']['function'], 'an add-on\'s tab passes through');
    }

    public function testANonListIsTreatedAsNoTabs(): void
    {
        $tabs = PeepSoDataExportQueue::closeExportTab(null);

        self::assertSame(['gdpr'], array_keys($tabs));
    }

    public function testTheClosedTabSaysSoAndNothingElse(): void
    {
        ob_start();
        PeepSoDataExportQueue::renderClosedTab();
        $html = (string) ob_get_clean();

        self::assertStringContainsString(self::CLOSED, $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('nonce', $html);
    }

    // ── 2. the queue run ────────────────────────────────────────────────

    /** @return array<string, array{array<string, string>}> */
    public static function queueRuns(): array
    {
        return [
            'the queue screen, export tab'   => [['page' => 'peepso-queue', 'tab' => 'gdpr', 'action' => 'process-request-data', '_wpnonce' => 'valid']],
            'the queue screen, any tab'      => [['page' => 'peepso-queue', 'action' => 'process-request-data']],
            'the request-data page'          => [['page' => 'peepso-gdpr-request-data', 'action' => 'process-request-data', '_wpnonce' => 'valid']],
            'no nonce at all'                => [['page' => 'peepso-gdpr-request-data', 'action' => 'process-request-data']],
        ];
    }

    /**
     * Refused for every caller, admins included (the caller here holds
     * every capability): keyed on the action and page alone, before any
     * nonce is checked, so a valid or missing nonce makes no difference.
     *
     * @param array<string, string> $query
     */
    #[DataProvider('queueRuns')]
    public function testARequestToRunTheQueueIsRefusedForEveryCaller(array $query): void
    {
        $_GET = $query;

        try {
            PeepSoDataExportQueue::refuseQueueRun();
            self::fail('a queue run must be refused');
        } catch (\ExportQueueHalt) {
        }

        [$message, , $args] = $GLOBALS['__bcc_xq']['die'];
        self::assertSame(self::CLOSED, $message);
        self::assertSame(['response' => 403], $args);
        self::assertArrayNotHasKey('nonce_checked', $GLOBALS['__bcc_xq'], 'refused before any nonce check');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function otherAdminRequests(): array
    {
        return [
            'the queue screen, no action'     => [['page' => 'peepso-queue', 'tab' => 'gdpr']],
            'the queue screen, another action' => [['page' => 'peepso-queue', 'action' => 'delete']],
            'another page, same action'        => [['page' => 'peepso-config', 'action' => 'process-request-data']],
            'the action without a page'        => [['action' => 'process-request-data']],
            'an array action'                  => [['page' => 'peepso-queue', 'action' => ['process-request-data']]],
            'no query'                         => [[]],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('otherAdminRequests')]
    public function testEveryOtherAdminRequestPassesThrough(array $query): void
    {
        $_GET = $query;

        try {
            PeepSoDataExportQueue::refuseQueueRun();
        } catch (\ExportQueueHalt) {
            self::fail('an admin request that does not run the queue must pass through');
        }

        self::assertArrayNotHasKey('die', $GLOBALS['__bcc_xq']);
    }
}
