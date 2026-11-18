<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\REST\MyPushSubscriptionEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `DELETE /me/push-subscriptions/{id}` must not reveal whether an id
 * exists.
 *
 * It answered 200 for a missing id and 403 for another member's, so any
 * member could walk ids and learn which ones are live subscriptions. Now
 * the delete is scoped to the caller in the DELETE itself
 * (PushSubscriptionRepository::deleteForUser), the row is never read
 * first, and the caller's own id, a missing id and someone else's id all
 * get the same 200. Another member's row is left alone.
 *
 * The real endpoint and repository run on an in-memory `$wpdb` (see
 * tests/Stubs/push-delete-and-list-cache-stubs.php). Real SQL:
 * PushEndpointHandoverIntegrationTest.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(MyPushSubscriptionEndpoint::class)]
#[CoversClass(PushSubscriptionRepository::class)]
final class PushSubscriptionDeleteNoOracleTest extends TestCase
{
    private const ME        = 7;
    private const SOMEONE   = 8;
    private const MY_ROW    = 60;
    private const THEIR_ROW = 55;
    private const NO_ROW    = 99;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/push-delete-and-list-cache-stubs.php';
        $GLOBALS['__bcc_pdl'] = [
            'user_id' => self::ME,
            'rows'    => [self::MY_ROW => self::ME, self::THEIR_ROW => self::SOMEONE],
            'fail'    => false,
            'deletes' => [],
            'reads'   => [],
            'errors'  => [],
        ];
        $GLOBALS['wpdb'] = new \BccPushDeleteFakeWpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_pdl'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** @return array{status: int, data: mixed, headers: array<string, string>} */
    private function revoke(int $id): array
    {
        $res = (new MyPushSubscriptionEndpoint())->revoke(new \WP_REST_Request(['id' => $id]));

        return ['status' => $res->get_status(), 'data' => $res->get_data(), 'headers' => $res->get_headers()];
    }

    // ── the bug ─────────────────────────────────────────────────────

    public function testSomeoneElsesIdGetsTheSameAnswerAsAMissingOne(): void
    {
        $theirs  = $this->revoke(self::THEIR_ROW);
        $missing = $this->revoke(self::NO_ROW);

        self::assertSame(200, $theirs['status']);
        self::assertSame($missing, $theirs);
    }

    public function testSomeoneElsesRowIsLeftAlone(): void
    {
        $this->revoke(self::THEIR_ROW);

        self::assertSame(self::SOMEONE, $GLOBALS['__bcc_pdl']['rows'][self::THEIR_ROW] ?? null);
    }

    public function testTheRowIsNeverReadBeforeTheDelete(): void
    {
        $this->revoke(self::THEIR_ROW);
        $this->revoke(self::MY_ROW);

        self::assertSame([], $GLOBALS['__bcc_pdl']['reads'], 'a read before the delete is what let the answer differ');
        self::assertSame(
            [['id' => self::THEIR_ROW, 'user_id' => self::ME], ['id' => self::MY_ROW, 'user_id' => self::ME]],
            $GLOBALS['__bcc_pdl']['deletes'],
            'every delete is scoped to the caller'
        );
    }

    // ── anti-vacuity: the route still works ─────────────────────────

    public function testMyOwnRowIsDeletedWithTheSameAnswer(): void
    {
        $mine = $this->revoke(self::MY_ROW);

        self::assertArrayNotHasKey(self::MY_ROW, $GLOBALS['__bcc_pdl']['rows']);
        self::assertSame($this->revoke(self::NO_ROW), $mine);
        self::assertSame(['ok' => true], $mine['data']['data'] ?? null);
        self::assertSame('no-store', $mine['headers']['Cache-Control'] ?? null);
    }

    // ── a failed write is not reported as success ───────────────────

    public function testADatabaseErrorAnswers503AndIsLogged(): void
    {
        $GLOBALS['__bcc_pdl']['fail'] = true;

        $res = $this->revoke(self::MY_ROW);

        self::assertSame(503, $res['status']);
        self::assertSame('bcc_unavailable', $res['data']['error']['code'] ?? null);
        self::assertSame(['[bcc-trust] push subscription not deleted'], $GLOBALS['__bcc_pdl']['errors']);
    }

    /** The 503 does not depend on whose row it is either. */
    public function testADatabaseErrorAnswersTheSameForAnyId(): void
    {
        $GLOBALS['__bcc_pdl']['fail'] = true;

        self::assertSame($this->revoke(self::THEIR_ROW), $this->revoke(self::MY_ROW));
    }
}
