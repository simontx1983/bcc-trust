<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-76: a push endpoint (one browser profile) belongs to exactly one user,
 * the one who registered it last. Real SQL on the real push_subscriptions
 * schema.
 *
 * Before: UNIQUE(user_id, endpoint_hash) let the same endpoint sit under
 * two accounts. When the previous account's logout cleanup failed or never
 * ran, the next person on that browser registered the same endpoint, and
 * the previous account's pushes reached their device.
 *
 * The suite only deletes rows for its own user ids.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(PushSubscriptionRepository::class)]
final class PushEndpointHandoverIntegrationTest extends TestCase
{
    private const ALICE = 988001;
    private const BOB   = 988002;

    private const SHARED_BROWSER = 'https://fcm.googleapis.com/fcm/send/shared-browser-endpoint-0001';
    private const ALICE_PHONE    = 'https://updates.push.services.mozilla.com/wpush/v2/alice-phone-0002';

    private PushSubscriptionRepository $repo;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/schema-push-subscriptions.php';
        bcc_trust_create_push_subscriptions_table();
        $this->repo = new PushSubscriptionRepository();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testRegisteringAnEndpointHandsItOverFromThePreviousUser(): void
    {
        self::assertGreaterThan(0, $this->repo->upsert(self::ALICE, self::SHARED_BROWSER, 'pA', 'aA', 'Chrome'));
        self::assertGreaterThan(0, $this->repo->upsert(self::ALICE, self::ALICE_PHONE, 'pP', 'aP', 'Firefox'));

        // Alice's logout cleanup never ran; Bob signs in on the same browser.
        self::assertGreaterThan(0, $this->repo->upsert(self::BOB, self::SHARED_BROWSER, 'pB', 'aB', 'Chrome'));

        self::assertSame([self::SHARED_BROWSER], $this->endpointsOf(self::BOB));
        self::assertSame(
            [self::ALICE_PHONE],
            $this->endpointsOf(self::ALICE),
            'Alice no longer reaches the shared browser, but keeps her other devices'
        );
    }

    public function testTheSameUserReRegisteringIsARefreshNotAHandover(): void
    {
        $id1 = $this->repo->upsert(self::ALICE, self::SHARED_BROWSER, 'p1', 'a1', 'Chrome');
        $id2 = $this->repo->upsert(self::ALICE, self::SHARED_BROWSER, 'p2', 'a2', 'Chrome');

        self::assertSame($id1, $id2, 'pushsubscriptionchange key rotation updates the same row');
        $rows = $this->repo->findAllForUser(self::ALICE);
        self::assertCount(1, $rows);
        self::assertSame('p2', $rows[0]->p256dh_key);
    }

    public function testHandingBackAndForthNeverLeavesTwoOwners(): void
    {
        $this->repo->upsert(self::ALICE, self::SHARED_BROWSER, 'pA', 'aA', null);
        $this->repo->upsert(self::BOB, self::SHARED_BROWSER, 'pB', 'aB', null);
        $this->repo->upsert(self::ALICE, self::SHARED_BROWSER, 'pA2', 'aA2', null);

        global $wpdb;
        $owners = $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . $wpdb->prefix . 'bcc_push_subscriptions WHERE endpoint_hash = %s',
            PushSubscriptionRepository::hashEndpoint(self::SHARED_BROWSER)
        ));
        self::assertSame([(string) self::ALICE], array_map('strval', $owners));
    }

    public function testDeleteAllForUserIsTheCredentialRevocationCall(): void
    {
        $this->repo->upsert(self::ALICE, self::SHARED_BROWSER, 'pA', 'aA', null);
        $this->repo->upsert(self::ALICE, self::ALICE_PHONE, 'pP', 'aP', null);
        $this->repo->upsert(self::BOB, 'https://fcm.googleapis.com/fcm/send/bob-only-0003', 'pB', 'aB', null);

        self::assertSame(2, $this->repo->deleteAllForUser(self::ALICE));
        self::assertSame([], $this->endpointsOf(self::ALICE));
        self::assertCount(1, $this->endpointsOf(self::BOB), 'other users untouched');
    }

    /**
     * Registrations of the same endpoint are serialised: unlocked, two
     * accounts registering at the same instant each deleted the other's
     * not-yet-written row and both inserted, leaving two owners. While
     * another session holds the endpoint's lock, a registration waits, then
     * refuses and writes nothing; once the lock is free it proceeds, and it
     * never leaves the lock held.
     */
    public function testARegistrationRefusesWhileAnotherHoldsTheEndpointLock(): void
    {
        global $wpdb;
        self::assertGreaterThan(0, $this->repo->upsert(self::ALICE, self::SHARED_BROWSER, 'pA', 'aA', null));

        $lock = PushSubscriptionRepository::lockKey(PushSubscriptionRepository::hashEndpoint(self::SHARED_BROWSER));
        self::assertLessThanOrEqual(64, strlen($lock), 'GET_LOCK names are capped at 64 characters');

        $peer = mysqli_connect(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            (string) $wpdb->get_var('SELECT DATABASE()'),
            (int) (getenv('BCC_TEST_DB_PORT') ?: 10005)
        );
        self::assertInstanceOf(\mysqli::class, $peer);
        $held = $peer->query("SELECT GET_LOCK('" . $peer->real_escape_string($lock) . "', 0)");
        self::assertSame('1', $held instanceof \mysqli_result ? (string) $held->fetch_row()[0] : null, 'control: the peer holds the lock');

        try {
            self::assertSame(0, $this->repo->upsert(self::BOB, self::SHARED_BROWSER, 'pB', 'aB', null), 'refused while locked');
            self::assertSame([], $this->endpointsOf(self::BOB), 'and nothing written');
            self::assertSame([self::SHARED_BROWSER], $this->endpointsOf(self::ALICE), 'nor anything deleted');
        } finally {
            $peer->query("SELECT RELEASE_LOCK('" . $peer->real_escape_string($lock) . "')");
            $peer->close();
        }

        self::assertGreaterThan(0, $this->repo->upsert(self::BOB, self::SHARED_BROWSER, 'pB', 'aB', null), 'proceeds once free');
        self::assertSame([self::SHARED_BROWSER], $this->endpointsOf(self::BOB));
        self::assertSame('1', (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $lock)), 'the lock is released after a registration');
    }

    /** @return list<string> */
    private function endpointsOf(int $userId): array
    {
        $out = array_map(static fn (object $r): string => (string) $r->endpoint, $this->repo->findAllForUser($userId));
        sort($out);
        return $out;
    }

    private function cleanup(): void
    {
        global $wpdb;
        foreach ([self::ALICE, self::BOB] as $uid) {
            $wpdb->delete($wpdb->prefix . 'bcc_push_subscriptions', ['user_id' => $uid]);
        }
    }
}
