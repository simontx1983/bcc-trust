<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The bulk moderation action can SUSPEND ACCOUNTS, so it must require POST.
 *
 * ── ⚠ WHY ───────────────────────────────────────────────────────────────
 * wp-admin/admin-post.php dispatches on $_REQUEST, not $_POST, so
 * `admin_post_bcc_trust_bulk_action` fired for a GET exactly as it did for a
 * POST. A nonce in a URL is a normal WordPress pattern for a link — but this
 * action suspends users, and a destructive action must not be reachable by
 * anything a browser can prefetch, a user can bookmark or share, or a proxy
 * can record in a referrer header.
 *
 * ── ⚠ WHAT MUST NOT CHANGE ──────────────────────────────────────────────
 * The only submitter is the bulk-apply handler in
 * includes/admin/moderation-list.php. It already builds a form with
 * method="POST" and posts each id as `user_ids[]` — an ARRAY. That shape is
 * load-bearing: `(array) $_POST['user_ids']` parses every id from an array,
 * but collapses a comma-joined STRING to its first number. The file's own
 * comment records that an earlier version posted a string and silently
 * no-op'd.
 *
 * So the array path is asserted here as carefully as the GET refusal, because
 * breaking it would be a silent regression of exactly the kind this guards
 * against.
 *
 * ── WHAT THIS SUITE DOES NOT DO ─────────────────────────────────────────
 * It never invokes handleBulkAction(), and it suspends nobody. It asserts the
 * parse that feeds it, and the presence and shape of the gate.
 */
#[Group('integration')]
#[Group('mariadb')]
final class BulkActionPostOnlyIntegrationTest extends TestCase
{
    /** @return string the source of the bulk-action hook, bounded */
    private function hookSource(): string
    {
        $file = dirname(__DIR__, 2) . '/includes/admin/moderation.php';
        $src  = file_get_contents($file);
        self::assertIsString($src, 'moderation.php must be readable');

        $start = strpos($src, "add_action( 'admin_post_bcc_trust_bulk_action'");
        self::assertNotFalse($start, 'the bulk-action hook must exist');
        $end = strpos($src, 'add_action', $start + 10);
        self::assertNotFalse($end, 'and a following add_action to bound the slice');

        return substr($src, $start, $end - $start);
    }

    // ── 0. ⚠⚠ THE PARSE THE GATE PROTECTS
    // ═══════════════════════════════════════════════════════════════════

    public function testAnArrayOfIdsParsesInFull(): void
    {
        // What the JS actually posts: user_ids[] repeated.
        $posted = ['11', '12', '13'];

        $ids = array_filter(array_map('absint', (array) $posted));

        self::assertSame([11, 12, 13], array_values($ids), 'every id, not just the first');
    }

    public function testACommaJoinedStringWouldCollapseToItsFirstId(): void
    {
        // ⚠ The regression this suite exists to make loud. If the submitter
        // ever goes back to a joined string, `(array)` wraps it whole and
        // absint() returns its first number — so "suspend 3 users" would
        // suspend ONE, silently. This is the same defect the ring action had.
        $ids = array_filter(array_map('absint', (array) '11,12,13'));

        self::assertSame([11], array_values($ids), 'absint() of the whole CSV is its first number');
    }

    public function testJunkIdsAreDroppedRatherThanCoercedToZero(): void
    {
        $ids = array_filter(array_map('absint', (array) ['11', 'x', '0', '-4', '12']));

        self::assertSame([11, 4, 12], array_values($ids), 'absint() makes -4 into 4; 0 and x drop out');
    }

    // ── 1. THE SUBMITTER STILL POSTS AN ARRAY, AND STILL POSTS
    // ═══════════════════════════════════════════════════════════════════

    public function testTheOnlySubmitterUsesPostAndAnArrayOfIds(): void
    {
        // ⚠ Traced rather than assumed: one submitter, no other form, no
        // other PHP caller, nothing in assets/. If that ever stops being
        // true, the gate below starts breaking a working path.
        $list = file_get_contents(dirname(__DIR__, 2) . '/includes/admin/moderation-list.php');
        self::assertIsString($list);

        self::assertStringContainsString("form.method = 'POST'", $list, 'the submitter must POST');
        self::assertStringContainsString("'user_ids[]'", $list, 'and post ids as an ARRAY');
        self::assertStringContainsString("'bcc_trust_bulk_action'", $list);
        self::assertStringNotContainsString("form.method = 'GET'", $list);
    }

    public function testNoOtherSurfaceSubmitsThisAction(): void
    {
        // ⚠ Anti-vacuity for the claim above. A second submitter — a plain
        // link, say — would be a GET and would start failing, which is
        // exactly what should be noticed before it ships rather than after.
        $root  = dirname(__DIR__, 2);
        $hits  = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/includes'));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            if (strpos($src, 'bcc_trust_bulk_action') !== false) {
                $hits[] = basename($f->getPathname());
            }
        }
        sort($hits);

        self::assertSame(
            ['moderation-list.php', 'moderation.php'],
            $hits,
            'only the hook itself and its one submitter may mention this action'
        );
    }

    // ── 2. ⚠⚠ THE GATE
    // ═══════════════════════════════════════════════════════════════════

    public function testTheHookRefusesAnythingThatIsNotAPost(): void
    {
        $src = $this->hookSource();

        self::assertStringContainsString('REQUEST_METHOD', $src);
        self::assertStringContainsString("!== 'POST'", $src);
        self::assertStringContainsString('405', $src, 'and refuses with Method Not Allowed');
    }

    public function testTheGateComesBeforeAnythingThatCouldAct(): void
    {
        // ⚠ Order matters: a method check after the nonce check would still
        // be correct, but a method check after handleBulkAction() would not.
        // Pinned so a refactor cannot move it past the call.
        $src = $this->hookSource();

        $method = strpos($src, 'REQUEST_METHOD');
        $call   = strpos($src, 'handleBulkAction');

        self::assertNotFalse($method);
        self::assertNotFalse($call);
        self::assertLessThan($call, $method, 'the method gate must precede the action');
    }

    public function testTheCapabilityAndNonceGatesAreStillThere(): void
    {
        // ⚠ The POST gate is an ADDITION. Neither existing gate may have been
        // traded for it.
        $src = $this->hookSource();

        self::assertStringContainsString("current_user_can( 'manage_options' )", $src);
        self::assertStringContainsString("check_admin_referer( 'bcc_trust_bulk_action' )", $src);
    }

    public function testTheArrayParseSurvivedTheChange(): void
    {
        // ⚠ The whole point of tracing the submitter first: the gate must not
        // have disturbed the one thing that makes the valid path work.
        $src = $this->hookSource();

        self::assertStringContainsString("(array) ( \$_POST['user_ids'] ?? [] )", $src);
        self::assertStringContainsString("array_map( 'absint'", $src);
    }

    public function testTheRingActionGateIsIndependentOfThisOne(): void
    {
        // Both hooks live in this file and both are destructive. This change
        // is only about the bulk one; the ring action is hardened separately,
        // so this asserts the slice boundary rather than the other hook.
        $src = $this->hookSource();

        self::assertStringNotContainsString('bcc_trust_ring_action', $src, 'the slice must be the bulk hook alone');
        self::assertStringContainsString('handleBulkAction', $src);
    }
}
