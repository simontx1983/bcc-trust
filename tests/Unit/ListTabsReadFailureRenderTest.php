<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\ListRenderWorld;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What the list tabs TELL AN OPERATOR when a read did not run.
 *
 * Four sentences, measured against the pre-fix build in a disposable
 * authenticated browser with each table replaced by an invalid view:
 *
 *   Pages ▸ Top   "No pages scored yet."
 *   Pages ▸ All   "No pages found."
 *   Users ▸ Trust "No users found."
 *   Activity      "No activity found."
 *
 * Each is a claim about data nobody read. Each must still appear when the
 * read DID run and found nothing — the empty state is correct then, and
 * removing it would trade one wrong answer for another.
 *
 * ⚠ Separate processes are required; see the stub file for why.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ListTabsReadFailureRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Stubs/list-tabs-render-stubs.php';
        $tabs = __DIR__ . '/../../includes/admin/tabs/';
        require_once $tabs . 'tab-pages.php';
        require_once $tabs . 'tab-users.php';
        require_once $tabs . 'tab-activity.php';
    }

    protected function setUp(): void
    {
        ListRenderWorld::reset();
    }

    /** try/finally: a throwing render must not leak its buffer into the next test. */
    private function render(string $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    // ── 0. CONTROLS
    // ═══════════════════════════════════════════════════════════════════

    public function testTheHarnessRendersTheRealTabs(): void
    {
        self::assertStringContainsString('Fixture Page', $this->render('bcc_trust_render_pages_tab'));
        self::assertStringContainsString('List Fixture', $this->render('bcc_trust_render_users_tab'));
        self::assertStringContainsString('admin_list_fixture', $this->render('bcc_trust_render_activity_tab'));
    }

    public function testHealthyRendersCarryNoFailureNotice(): void
    {
        foreach ([
            'bcc_trust_render_pages_tab',
            'bcc_trust_render_all_pages_tab',
            'bcc_trust_render_users_tab',
            'bcc_trust_render_activity_tab',
        ] as $fn) {
            self::assertStringNotContainsString('could not be read', $this->render($fn), $fn);
        }
    }

    public function testAGenuineEmptyListStillShowsItsEmptyState(): void
    {
        // ⚠ THE CONTROL. The empty sentence is CORRECT when the read ran, and
        // this fix must not trade one wrong answer for another.
        ListRenderWorld::$pages['pages']       = [];
        ListRenderWorld::$users['users']       = [];
        ListRenderWorld::$activity['activity'] = [];

        self::assertStringContainsString('No pages scored yet', $this->render('bcc_trust_render_pages_tab'));
        self::assertStringContainsString('No users found', $this->render('bcc_trust_render_users_tab'));
        self::assertStringContainsString('No activity found', $this->render('bcc_trust_render_activity_tab'));
    }

    // ── 1. ⚠⚠ A FAILED READ CANNOT PRINT AN EMPTY STATE
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedPagesReadNeverClaimsNoPagesScored(): void
    {
        ListRenderWorld::$pages['ok']    = false;
        ListRenderWorld::$pages['pages'] = [];

        $html = $this->render('bcc_trust_render_pages_tab');

        self::assertStringNotContainsString('No pages scored yet', $html);
        self::assertStringContainsString('The scored pages could not be read', $html);
    }

    public function testAFailedAllPagesReadNeverClaimsNoPagesFound(): void
    {
        ListRenderWorld::$allPages['ok']    = false;
        ListRenderWorld::$allPages['pages'] = [];

        $html = $this->render('bcc_trust_render_all_pages_tab');

        self::assertStringNotContainsString('No pages found', $html);
        self::assertStringContainsString('The page list could not be read', $html);
    }

    public function testAFailedUsersReadNeverClaimsNoUsersFound(): void
    {
        ListRenderWorld::$users['ok']    = false;
        ListRenderWorld::$users['users'] = [];

        $html = $this->render('bcc_trust_render_users_tab');

        self::assertStringNotContainsString('No users found', $html);
        self::assertStringContainsString('The user list could not be read', $html);
    }

    public function testAFailedActivityReadNeverClaimsNoActivityFound(): void
    {
        ListRenderWorld::$activity['ok']       = false;
        ListRenderWorld::$activity['activity'] = [];

        $html = $this->render('bcc_trust_render_activity_tab');

        self::assertStringNotContainsString('No activity found', $html);
        self::assertStringContainsString('The activity log could not be read', $html);
    }

    public function testTheTwoPagesViewsDegradeIndependently(): void
    {
        // ⚠ Anti-vacuity, and it reflects a real difference: Top reads the
        // read model, All reads wp_posts LEFT JOIN scores. One failing says
        // nothing about the other.
        ListRenderWorld::$pages['ok'] = false;

        self::assertStringContainsString('could not be read', $this->render('bcc_trust_render_pages_tab'));
        self::assertStringNotContainsString('could not be read', $this->render('bcc_trust_render_all_pages_tab'));
    }

    public function testNoDegradedRenderEmitsPhpDiagnostics(): void
    {
        ListRenderWorld::$pages['ok']          = false;
        ListRenderWorld::$pages['pages']       = [];
        ListRenderWorld::$allPages['ok']       = false;
        ListRenderWorld::$allPages['pages']    = [];
        ListRenderWorld::$users['ok']          = false;
        ListRenderWorld::$users['users']       = [];
        ListRenderWorld::$activity['ok']       = false;
        ListRenderWorld::$activity['activity'] = [];

        foreach ([
            'bcc_trust_render_pages_tab',
            'bcc_trust_render_all_pages_tab',
            'bcc_trust_render_users_tab',
            'bcc_trust_render_activity_tab',
        ] as $fn) {
            $html = $this->render($fn);
            self::assertNotSame('', $html, "{$fn} still rendered");
            foreach (['Warning', 'TypeError', 'Deprecated', 'Fatal'] as $shape) {
                self::assertStringNotContainsString($shape, $html, "{$fn} emitted {$shape}");
            }
        }
    }

    public function testAPreVerdictPayloadRendersExactlyAsBefore(): void
    {
        // A cached payload from the previous build carries no 'ok' key.
        unset(
            ListRenderWorld::$pages['ok'],
            ListRenderWorld::$allPages['ok'],
            ListRenderWorld::$users['ok'],
            ListRenderWorld::$activity['ok']
        );

        foreach ([
            'bcc_trust_render_pages_tab',
            'bcc_trust_render_all_pages_tab',
            'bcc_trust_render_users_tab',
            'bcc_trust_render_activity_tab',
        ] as $fn) {
            self::assertStringNotContainsString('could not be read', $this->render($fn), $fn);
        }
    }
}
