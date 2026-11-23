<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\NotificationsEndpoint;
use BCC\Trust\Core\Repositories\NotificationRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-516: POST /me/notifications/mark-read takes `ids`, the bell rows a push
 * stood for, and marks exactly those of the viewer's own rows read.
 *
 *  - One bounded UPDATE, scoped to the viewer and to BCC's module, touching
 *    only unread rows; duplicates collapse.
 *  - `ids` is validated before anything is written. An empty, oversized or
 *    malformed list is a 400 and writes nothing: it must never fall through
 *    to "mark all".
 *  - Without `ids` the route behaves as before (`id` marks one, nothing
 *    marks all).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationMarkReadManyTest extends TestCase
{
    private const VIEWER = 4401;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/notification-mark-read-stubs.php';
        $GLOBALS['__bcc_mark_read'] = [
            'user_id'   => self::VIEWER,
            'throttled' => false,
            'affected'  => 0,
            'queries'   => [],
            'bumped'    => [],
        ];
        $GLOBALS['wpdb'] = new \BccMarkReadFakeWpdb();
    }

    /** @param array<string, mixed> $params */
    private function markRead(array $params): \WP_REST_Response
    {
        return (new NotificationsEndpoint())->markRead(new \WP_REST_Request($params));
    }

    /** @return list<array{sql: string, args: list<mixed>}> */
    private static function queries(): array
    {
        return $GLOBALS['__bcc_mark_read']['queries'];
    }

    // ── The repository ──────────────────────────────────────────────

    public function testOneBoundedUpdateScopedToTheUserAndModuleTouchingOnlyUnreadRows(): void
    {
        $GLOBALS['__bcc_mark_read']['affected'] = 2;

        $updated = (new NotificationRepository())->markReadMany(self::VIEWER, [11, 12, 13]);

        self::assertSame(2, $updated, 'the rows the UPDATE changed');
        $q = self::queries();
        self::assertCount(1, $q, 'one statement for the whole list');
        self::assertStringStartsWith('UPDATE `wp_peepso_notifications` SET `not_read` = 1', $q[0]['sql']);
        self::assertStringContainsString('WHERE `not_id` IN (%d, %d, %d)', $q[0]['sql']);
        self::assertStringContainsString('AND `not_user_id` = %d', $q[0]['sql']);
        self::assertStringContainsString('AND `not_module_id` = %d', $q[0]['sql']);
        self::assertStringEndsWith('AND `not_read` = 0', $q[0]['sql']);
        self::assertSame([11, 12, 13, self::VIEWER, BCC_NOTIFICATION_MODULE_ID], $q[0]['args']);
    }

    public function testDuplicatesCollapseAndNonPositiveIdsAreDropped(): void
    {
        (new NotificationRepository())->markReadMany(self::VIEWER, [7, 7, 0, -3, 8]);

        $q = self::queries();
        self::assertCount(1, $q);
        self::assertStringContainsString('IN (%d, %d)', $q[0]['sql']);
        self::assertSame([7, 8, self::VIEWER, BCC_NOTIFICATION_MODULE_ID], $q[0]['args']);
    }

    public function testNothingToMarkRunsNoStatement(): void
    {
        $repo = new NotificationRepository();

        self::assertSame(0, $repo->markReadMany(self::VIEWER, []));
        self::assertSame(0, $repo->markReadMany(self::VIEWER, [0, -1]));
        self::assertSame(0, $repo->markReadMany(0, [5]), 'no user, no write');
        self::assertSame([], self::queries());
    }

    public function testMoreIdsThanTheCapRunsNoStatement(): void
    {
        $ids = range(1, NotificationRepository::MARK_READ_MANY_MAX + 1);

        self::assertSame(0, (new NotificationRepository())->markReadMany(self::VIEWER, $ids));
        self::assertSame([], self::queries());
    }

    public function testExactlyTheCapIsAccepted(): void
    {
        (new NotificationRepository())->markReadMany(self::VIEWER, range(1, NotificationRepository::MARK_READ_MANY_MAX));

        self::assertCount(1, self::queries());
    }

    public function testAFailedUpdateCountsAsNothingChanged(): void
    {
        $GLOBALS['__bcc_mark_read']['affected'] = false;

        self::assertSame(0, (new NotificationRepository())->markReadMany(self::VIEWER, [3]));
    }

    public function testTheCapIsFifty(): void
    {
        self::assertSame(50, NotificationRepository::MARK_READ_MANY_MAX);
    }

    // ── The route ───────────────────────────────────────────────────

    public function testIdsMarkExactlyThoseRowsAndAnswerTheCount(): void
    {
        $GLOBALS['__bcc_mark_read']['affected'] = 2;

        $res = $this->markRead(['ids' => [21, '22']]);

        self::assertSame(200, $res->get_status());
        self::assertSame(['ok' => true, 'updated' => 2], $res->get_data()['data']);
        $q = self::queries();
        self::assertCount(1, $q);
        self::assertStringContainsString('`not_id` IN (%d, %d)', $q[0]['sql']);
        self::assertSame([21, 22, self::VIEWER, BCC_NOTIFICATION_MODULE_ID], $q[0]['args'], 'a digit string is an id too');
        self::assertSame([self::VIEWER], $GLOBALS['__bcc_mark_read']['bumped'], 'the badge refreshes');
    }

    public function testIdsWinOverId(): void
    {
        $this->markRead(['ids' => [31], 'id' => 99]);

        $q = self::queries();
        self::assertCount(1, $q);
        self::assertSame([31, self::VIEWER, BCC_NOTIFICATION_MODULE_ID], $q[0]['args']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badIds(): iterable
    {
        yield 'an explicit null'         => [null]; // sent, so refused: never read as "no ids" → mark all
        yield 'an empty list'            => [[]];
        yield 'one id too many'          => [range(1, NotificationRepository::MARK_READ_MANY_MAX + 1)];
        yield 'a zero'                   => [[5, 0]];
        yield 'a negative'               => [[5, -2]];
        yield 'a word'                   => [[5, 'abc']];
        yield 'a number with a sign'     => [['+5']];
        yield 'a leading zero'           => [['05']];
        yield 'a decimal'                => [[5.0]];
        yield 'a nested list'            => [[[5]]];
        yield 'a null'                   => [[null]];
        yield 'a boolean'                => [[true]];
        yield 'not a list'               => ['5,6'];
        yield 'a bare number'            => [5];
    }

    #[DataProvider('badIds')]
    public function testAnInvalidIdsListIsRefusedAndWritesNothing(mixed $ids): void
    {
        $res = $this->markRead(['ids' => $ids]);

        self::assertSame(400, $res->get_status());
        self::assertSame('bcc_invalid_request', $res->get_data()['error']['code'] ?? null);
        self::assertSame('`ids` must list 1 to 50 notification ids.', $res->get_data()['error']['message'] ?? null);
        self::assertSame([], self::queries(), 'never falls through to marking everything');
        self::assertSame([], $GLOBALS['__bcc_mark_read']['bumped']);
    }

    public function testExactlyTheCapIsAcceptedByTheRoute(): void
    {
        $res = $this->markRead(['ids' => range(1, NotificationRepository::MARK_READ_MANY_MAX)]);

        self::assertSame(200, $res->get_status());
        self::assertCount(1, self::queries());
    }

    public function testWithoutIdsOneIdStillMarksOne(): void
    {
        $GLOBALS['__bcc_mark_read']['affected'] = 1;

        $res = $this->markRead(['id' => 41]);

        self::assertSame(['ok' => true, 'updated' => 1], $res->get_data()['data']);
        $q = self::queries();
        self::assertCount(1, $q);
        self::assertStringContainsString('WHERE `not_id` = %d', $q[0]['sql']);
        self::assertSame([41, self::VIEWER, BCC_NOTIFICATION_MODULE_ID], $q[0]['args']);
    }

    public function testWithoutIdsNothingStillMarksAll(): void
    {
        $GLOBALS['__bcc_mark_read']['affected'] = 5;

        $res = $this->markRead([]);

        self::assertSame(['ok' => true, 'updated' => 5], $res->get_data()['data']);
        $q = self::queries();
        self::assertCount(1, $q);
        self::assertStringNotContainsString('not_id', $q[0]['sql']);
        self::assertSame([self::VIEWER, BCC_NOTIFICATION_MODULE_ID], $q[0]['args']);
    }

    public function testSignedOutIsRefusedBeforeAnyWrite(): void
    {
        $GLOBALS['__bcc_mark_read']['user_id'] = 0;

        $res = $this->markRead(['ids' => [1]]);

        self::assertSame(401, $res->get_status());
        self::assertSame([], self::queries());
    }

    public function testTheRateLimitStillAppliesToIds(): void
    {
        $GLOBALS['__bcc_mark_read']['throttled'] = true;

        $res = $this->markRead(['ids' => [1]]);

        self::assertSame(429, $res->get_status());
        self::assertSame([], self::queries());
    }
}
