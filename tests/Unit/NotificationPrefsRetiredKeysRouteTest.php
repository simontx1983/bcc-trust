<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\MyNotificationPrefsEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Rollout compatibility for the v1.50 endorse-pref retirement: an older
 * frontend build may still PATCH the retired `bcc_endorse` (bell) /
 * `endorse` (push) keys against the new backend. The endpoint must
 * treat a retired-keys-only body as a no-op 200 (not 422), save the
 * recognized keys out of mixed bodies, never persist or echo the
 * retired keys, and keep 422 for bodies with no pref fields at all.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationPrefsRetiredKeysRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/route-handler-stubs.php';
        require_once __DIR__ . '/../Stubs/notification-prefs-stubs.php';
        $GLOBALS['__bcc_puball_fixture'] = ['groups' => [], 'admins' => [], 'current_user' => 7];
        $GLOBALS['__bcc_prefs_meta']     = [];
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): WP_REST_Response
    {
        return (new MyNotificationPrefsEndpoint())->patch(new WP_REST_Request($body));
    }

    /** @return array<string, mixed> */
    private function data(WP_REST_Response $r): array
    {
        $body = $r->get_data();
        $data = is_array($body) ? ($body['data'] ?? []) : [];
        return is_array($data) ? $data : [];
    }

    public function testRetiredKeysOnlyBodyIsNoOp200(): void
    {
        $r = $this->patch([
            'bell' => ['bcc_endorse' => false],
            'push' => ['events' => ['endorse' => false]],
        ]);

        self::assertSame(200, $r->get_status(), 'retired-keys-only PATCH must not 422 during rollout');
        $data = $this->data($r);
        self::assertArrayNotHasKey('bcc_endorse', $data['bell'] ?? [], 'retired bell key must not echo back');
        self::assertArrayNotHasKey('endorse', $data['push']['events'] ?? [], 'retired push key must not echo back');
        self::assertSame([], $GLOBALS['__bcc_prefs_meta'], 'retired keys must not persist');
    }

    public function testMixedBodySavesRecognizedAndIgnoresRetired(): void
    {
        $r = $this->patch(['bell' => ['bcc_endorse' => false, 'bcc_review' => false]]);

        self::assertSame(200, $r->get_status());
        self::assertFalse($this->data($r)['bell']['bcc_review'] ?? null, 'recognized key saved and echoed');

        $meta = $GLOBALS['__bcc_prefs_meta'][7] ?? [];
        self::assertSame('0', $meta['bcc_notif_pref_bell_bcc_review'] ?? null);
        self::assertArrayNotHasKey('bcc_notif_pref_bell_bcc_endorse', $meta);
    }

    public function testBodyWithNoPrefFieldsStays422(): void
    {
        $r = $this->patch([]);

        self::assertSame(422, $r->get_status());
        $body = $r->get_data();
        self::assertSame(
            'bcc_invalid_request',
            is_array($body) ? ($body['error']['code'] ?? null) : null
        );
    }

    public function testMalformedRetiredValueIsIgnoredNotFatal(): void
    {
        $r = $this->patch(['bell' => ['bcc_endorse' => ['nested' => 'garbage']]]);

        self::assertSame(200, $r->get_status());
        self::assertSame([], $GLOBALS['__bcc_prefs_meta']);
    }

    public function testStaleStoredRetiredMetaDoesNotLeakIntoReads(): void
    {
        // A user who toggled the endorse pref on an older build still has
        // the orphaned usermeta row; readAll iterates the live catalogue
        // only, so the orphan must never surface.
        $GLOBALS['__bcc_prefs_meta'][7]['bcc_notif_pref_bell_bcc_endorse'] = '0';

        $r = $this->patch(['bell' => ['bcc_review' => true]]);

        self::assertSame(200, $r->get_status());
        self::assertArrayNotHasKey('bcc_endorse', $this->data($r)['bell'] ?? []);
    }
}
