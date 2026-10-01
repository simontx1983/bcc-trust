<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\CosmosEndpointReview;
use BCC\Trust\Onchain\Services\CosmosEndpointTransition;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/endpoint-switch-integration-stubs.php';

/**
 * The endpoint switch under REAL contention, from a SECOND DATABASE CONNECTION.
 *
 * ── WHY A SECOND CONNECTION IS THE WHOLE POINT ──────────────────────────
 * `GET_LOCK` is scoped to a SESSION and is REENTRANT within one. A test that
 * takes the lock on the suite's own connection and then calls the switch proves
 * nothing: the switch's `acquire()` runs on that same connection, succeeds by
 * reentrancy, and sails straight through the guard it was meant to hit. The
 * unit suite's lock double is not reentrant either, so under it the opposite
 * happens — a second claim fails — and neither double behaves like production.
 *
 * Reentrancy is measured, not assumed; see the table in
 * {@see HalfOpenProbeOwnershipIntegrationTest}, taken on MySQL 8.0.35 and
 * MariaDB 11.8.9. This file follows that file's pattern: a peer `mysqli`
 * standing in for another administrator's request on another PHP worker.
 *
 * ── WHAT THIS FILE PROVES, AND WHAT IT CANNOT ───────────────────────────
 * It drives the REAL `CosmosEndpointTransition::execute()` against the REAL
 * advisory lock, the REAL chains row and the REAL single-use review.
 *
 * ⚠ IT CANNOT DRIVE A SUCCESSFUL SWITCH, and that is deliberate. Completing one
 * requires `CosmosEndpointVerifier` to prove the destination live, and the
 * integration harness forbids outbound traffic — `wp_remote_get()` is stubbed to
 * a `WP_Error` precisely so a test run can never repoint a chain at a host it
 * actually contacted. So every arrival that gets as far as the proof refuses
 * with `target_unreachable`, and NO arrival here reaches the write.
 *
 * That splits the two halves of "at most one verification and one write" across
 * two files, honestly:
 *   - **at most one verification** is proved HERE, end to end, by counting the
 *     arrivals that reach the proof at all;
 *   - **at most one write** is proved by the CAS, and
 *     {@see testTwoConnectionsOfferingTheSameIncumbentProduceExactlyOneWrite}
 *     does it below on two real connections, where the arbitration is the
 *     server's rather than a PHP cache's.
 *
 * ── HOW AN ARRIVAL'S FATE IS READ ───────────────────────────────────────
 * By reason, which is DNS-independent (see the stub file's note):
 *   `lock_contended`       — refused by the lock; never saw the review
 *   `review_token_invalid` — got through the lock, review already spent
 *   `target_*`             — got through the lock AND spent the review AND
 *                            attempted the proof. This is a VERIFICATION.
 */
#[CoversClass(CosmosEndpointTransition::class)]
#[Group('integration')]
#[Group('mariadb')]
final class CosmosEndpointSwitchLockContentionIntegrationTest extends TestCase
{
    /** The only slug `CosmosEndpointPolicy` governs. */
    private const SLUG = 'cosmos';

    private const INCUMBENT = 'https://rest.cosmos.directory/cosmoshub';

    private const TARGET = 'https://cosmos-api.polkachu.com';

    private const OPERATOR = 9001;

    private static ?\mysqli $peerConn = null;

    private static ?MysqliWpdb $peerWpdb = null;

    private int $chainId = 0;

    /** @var array{rest_url: ?string, is_active: int} */
    private array $snapshot = ['rest_url' => null, 'is_active' => 1];

    // ── The peer: another worker, on its own connection ──────────────────

    /**
     * ⚠ IT MUST NOT BE THE SUITE'S OWN CONNECTION. A session asking whether it
     * may take a lock it already holds is always told yes.
     */
    private function peerConnection(): \mysqli
    {
        if (self::$peerConn instanceof \mysqli) {
            return self::$peerConn;
        }

        $host = getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('BCC_TEST_DB_PORT') ?: 10005);
        $user = getenv('BCC_TEST_DB_USER') ?: 'root';
        $pass = getenv('BCC_TEST_DB_PASS') ?: 'root';
        $name = getenv('BCC_TEST_DB_NAME') ?: 'bcc_test';

        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new \mysqli($host, $user, $pass, $name, $port);
        self::assertSame(0, $conn->connect_errno, 'the peer connection must succeed');

        self::$peerConn = $conn;

        return $conn;
    }

    /** A second `$wpdb` on the peer connection: another request's view of the DB. */
    private function peerWpdb(): MysqliWpdb
    {
        if (!self::$peerWpdb instanceof MysqliWpdb) {
            self::$peerWpdb = new MysqliWpdb($this->peerConnection(), 'wp_');
        }

        return self::$peerWpdb;
    }

    private function endpointLockName(): string
    {
        return 'bcc_cosmos_endpoint_' . $this->chainId;
    }

    /** Take a named lock ON THE PEER, so the switch's own session cannot have it. */
    private function peerTakes(string $lock): void
    {
        $conn = $this->peerConnection();
        $res  = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lock) . "', 0)");
        self::assertNotFalse($res, 'the peer must be able to ask for a lock');
        self::assertSame(1, (int) ($res->fetch_row()[0] ?? 0), "the peer must hold {$lock}");
    }

    private function peerReleases(string $lock): void
    {
        $conn = $this->peerConnection();
        $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock) . "')");
    }

    /**
     * Could ANOTHER session take this lock right now? Asked from the suite's
     * connection, which is a different session from the peer's.
     *
     * ⚠ It puts back anything it takes. Leaving it held would make the next
     * `execute()` succeed by reentrancy on the suite's own session.
     */
    private function lockIsFree(string $lock): bool
    {
        $wpdb = $GLOBALS['wpdb'];
        $got  = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));

        if ($got === 1) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }

        return $got === 1;
    }

    // ── The row under test is the bootstrap's own `cosmos` chain ─────────

    /**
     * ⚠ NOT A FIXTURE OF ITS OWN. `CosmosEndpointPolicy` governs exactly one
     * slug, `cosmos`, and `wp_bcc_chains` carries `UNIQUE KEY slug`, so a second
     * row with that slug cannot exist. This takes the seeded row, records what
     * it found and puts it back in `tearDown()`; PHPUnit runs the suite
     * sequentially in one process, so no other test can observe the window.
     */
    private function resolveChain(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, rest_url, is_active FROM `{$table}`
              WHERE CAST(slug AS BINARY) = CAST(%s AS BINARY) LIMIT 1",
            self::SLUG
        ));

        self::assertIsObject(
            $row,
            'the bootstrap must have seeded a `cosmos` chain for the policy to govern'
        );

        $this->chainId  = (int) $row->id;
        $this->snapshot = [
            'rest_url'  => $row->rest_url === null ? null : (string) $row->rest_url,
            'is_active' => (int) $row->is_active,
        ];

        self::assertGreaterThan(0, $this->chainId, 'anti-vacuity: a real chain id');
    }

    private function setRestUrl(?string $value, int $isActive = 1): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $sql = $value === null
            ? $wpdb->prepare("UPDATE `{$table}` SET rest_url = NULL, is_active = %d WHERE id = %d", $isActive, $this->chainId)
            : $wpdb->prepare("UPDATE `{$table}` SET rest_url = %s, is_active = %d WHERE id = %d", $value, $isActive, $this->chainId);

        $wpdb->query($sql);
        ChainRepository::clearCache();
    }

    private function storedRestUrl(): ?string
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT rest_url FROM `{$table}` WHERE id = %d",
            $this->chainId
        ));

        return $value === null ? null : (string) $value;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveChain();

        // A clean starting world: the approved incumbent, active, no pending review.
        $this->setRestUrl(self::INCUMBENT, 1);
        CosmosEndpointReview::forget(self::OPERATOR);
        \BccEndpointTransportSpy::reset();

        // Nothing may be holding either lock when a test begins.
        $this->peerReleases($this->endpointLockName());
        $this->peerReleases(CosmwasmDiscoveryWorker::ADVISORY_LOCK_PREFIX . $this->chainId);

        self::assertTrue(
            $this->lockIsFree($this->endpointLockName()),
            'test precondition: the endpoint lock must start free'
        );
    }

    protected function tearDown(): void
    {
        $this->peerReleases($this->endpointLockName());
        $this->peerReleases(CosmwasmDiscoveryWorker::ADVISORY_LOCK_PREFIX . $this->chainId);

        CosmosEndpointReview::forget(self::OPERATOR);
        $this->setRestUrl($this->snapshot['rest_url'], $this->snapshot['is_active']);

        parent::tearDown();
    }

    /** Mint a review the way the operator's first POST does. */
    private function review(): string
    {
        $r = CosmosEndpointTransition::review($this->chainId, self::TARGET, self::OPERATOR);

        self::assertTrue($r['ok'], 'test precondition: the review must be accepted — got ' . $r['reason']);
        self::assertNotSame('', $r['review_id']);

        return (string) $r['review_id'];
    }

    /** @return array{ok: bool, reason: string, verified_network: string|null, failed_followups: list<string>} */
    private function submit(string $reviewId): array
    {
        return CosmosEndpointTransition::execute($this->chainId, $reviewId, self::OPERATOR);
    }

    /**
     * Did this arrival attempt the live proof against the destination?
     *
     * ⚠ EXACT, NOT A `target_` PREFIX MATCH. The verifier also answers
     * `target_not_governed`, `target_malformed_url` and
     * `target_host_not_approved`, and every one of those is decided from the
     * policy table WITHOUT touching the destination. Accepting the whole
     * family would let a fixture that quietly stopped being policy-valid keep
     * reporting "one verification happened" while nothing was ever proved.
     *
     * With a governed slug and an approved target — both asserted by
     * `review()` succeeding — the only remaining outcome in this harness is
     * `unreachable`: `SafeHttpClient` returns a `WP_Error` either from its own
     * resolver gate or from the transport stub, and `verify()` maps both to
     * the same reason. That makes this deterministic and resolver-independent.
     */
    private function attemptedTheLiveProof(string $reason): bool
    {
        return $reason === 'target_unreachable';
    }

    // ── 1. A peer holding the lock refuses the switch ────────────────────

    public function testASubmissionIsRefusedWhileAPeerConnectionHoldsTheEndpointLock(): void
    {
        $reviewId = $this->review();
        $this->peerTakes($this->endpointLockName());

        $result = $this->submit($reviewId);

        self::assertFalse($result['ok']);
        self::assertSame(
            'lock_contended',
            $result['reason'],
            'a lock held by another session must refuse this switch'
        );
    }

    /**
     * ANTI-VACUITY for the test above. With the peer NOT holding the lock, the
     * identical submission gets through — so `lock_contended` is a measurement
     * of the lock and not of something else being wrong with the fixture.
     */
    public function testTheIdenticalSubmissionIsNotRefusedWhenNoPeerHoldsTheLock(): void
    {
        $reviewId = $this->review();

        $result = $this->submit($reviewId);

        self::assertNotSame('lock_contended', $result['reason']);
        self::assertTrue(
            $this->attemptedTheLiveProof($result['reason']),
            'with the lock free the submission must reach the proof — got ' . $result['reason']
        );
    }

    // ── 2. The refusal costs nothing ─────────────────────────────────────

    /**
     * ⚠ A REFUSAL MUST SPEND NOTHING. The review survives, no provider is
     * contacted, and the column does not move — otherwise a lost race would
     * burn the operator's confirmation and they could not simply press again.
     */
    public function testTheRefusedSubmissionSpendsNoReviewMakesNoProofAndWritesNothing(): void
    {
        $reviewId = $this->review();
        $this->peerTakes($this->endpointLockName());

        $result = $this->submit($reviewId);
        self::assertSame('lock_contended', $result['reason']);

        $pending = CosmosEndpointReview::peek(self::OPERATOR);
        self::assertIsArray($pending, 'the review must survive a lost race');
        self::assertSame(
            $reviewId,
            (string) $pending['review_id'],
            'and it must be the SAME review, not a replacement'
        );

        self::assertFalse(
            $this->attemptedTheLiveProof($result['reason']),
            'the refusal must happen before the proof is attempted'
        );

        self::assertSame(
            self::INCUMBENT,
            $this->storedRestUrl(),
            'and the endpoint must not have moved, byte for byte'
        );
    }

    /**
     * ⚠⚠ THE REFUSAL MUST NOT RELEASE A LOCK IT NEVER TOOK.
     *
     * `execute()` refuses BEFORE entering the `try`, so the `finally` that
     * releases cannot run on this path. Were the release unconditional — the
     * obvious shape, and a `finally` wrapped one line higher would produce it —
     * a losing arrival would hand the winner's lock to the next caller, and the
     * serialisation would be gone while every test that only reads the refusal
     * still passed.
     */
    public function testTheRefusalLeavesThePeersLockHeld(): void
    {
        $reviewId = $this->review();
        $this->peerTakes($this->endpointLockName());

        self::assertSame('lock_contended', $this->submit($reviewId)['reason']);

        self::assertFalse(
            $this->lockIsFree($this->endpointLockName()),
            'the peer must STILL hold its lock after another arrival was refused'
        );

        // And once the holder is done, it is genuinely free again.
        $this->peerReleases($this->endpointLockName());
        self::assertTrue(
            $this->lockIsFree($this->endpointLockName()),
            'anti-vacuity: the check above can see a free lock when there is one'
        );
    }

    // ── 3. The review survives the race ──────────────────────────────────

    public function testAfterTheHolderReleasesTheSameReviewIsStillUsable(): void
    {
        $reviewId = $this->review();

        $this->peerTakes($this->endpointLockName());
        self::assertSame('lock_contended', $this->submit($reviewId)['reason']);
        $this->peerReleases($this->endpointLockName());

        $second = $this->submit($reviewId);

        self::assertTrue(
            $this->attemptedTheLiveProof($second['reason']),
            'the same review must still be usable once the lock is free — got ' . $second['reason']
        );
        self::assertNull(
            CosmosEndpointReview::peek(self::OPERATOR),
            'and using it must spend it'
        );
    }

    // ── 4. Racing submissions of one review ──────────────────────────────

    /**
     * ⚠ AT MOST ONE VERIFICATION, across every arrival of one review.
     *
     * Three arrivals, all carrying the same `review_id`: one while a peer holds
     * the lock, one once it is free, one after that. Exactly one may reach the
     * proof. The other two are refused by the two independent gates — the lock,
     * then the spent review — and the totals are what is asserted, not which
     * arrival won.
     */
    public function testRacingSubmissionsOfOneReviewReachVerificationExactlyOnce(): void
    {
        $reviewId = $this->review();

        $this->peerTakes($this->endpointLockName());
        $reasons = [$this->submit($reviewId)['reason']];
        $this->peerReleases($this->endpointLockName());

        $reasons[] = $this->submit($reviewId)['reason'];
        $reasons[] = $this->submit($reviewId)['reason'];

        $verifications = 0;
        foreach ($reasons as $reason) {
            if ($this->attemptedTheLiveProof($reason)) {
                $verifications++;
            }
        }

        self::assertSame(
            1,
            $verifications,
            'exactly one arrival may reach the proof — reasons were ' . implode(', ', $reasons)
        );
        self::assertSame(
            ['lock_contended', 'review_token_invalid'],
            [$reasons[0], $reasons[2]],
            'the two refusals must come from the lock and from the spent review, in that order'
        );
        self::assertSame(
            self::INCUMBENT,
            $this->storedRestUrl(),
            'and no arrival wrote, because none completed a proof'
        );
    }

    /**
     * A REPLACEMENT review does not multiply the verifications either: the
     * older confirmation is dead even though its chain and target are identical.
     */
    public function testAReplacementReviewDoesNotAddASecondVerification(): void
    {
        $first  = $this->review();
        $second = $this->review();
        self::assertNotSame($first, $second, 'a replacement must be a different review');

        $reasons = [
            $this->submit($first)['reason'],
            $this->submit($second)['reason'],
            $this->submit($first)['reason'],
        ];

        $verifications = 0;
        foreach ($reasons as $reason) {
            if ($this->attemptedTheLiveProof($reason)) {
                $verifications++;
            }
        }

        self::assertSame(
            1,
            $verifications,
            'only the live review may reach the proof — reasons were ' . implode(', ', $reasons)
        );
        self::assertSame('review_token_invalid', $reasons[0], 'the superseded confirmation is refused');
    }

    // ── 5. At most one write, arbitrated by the server ───────────────────

    /**
     * ⚠ TWO CONNECTIONS, ONE WRITE.
     *
     * Both offer the SAME incumbent, as two operators who read the same screen
     * would. The second must match nothing — and it must be the SERVER that
     * says so: the peer has its own `$wpdb`, and the chains cache is dropped in
     * between, so no PHP-side memory of the first write can be what refuses it.
     */
    public function testTwoConnectionsOfferingTheSameIncumbentProduceExactlyOneWrite(): void
    {
        $expected = [
            'rest_url'  => self::INCUMBENT,
            'slug'      => self::SLUG,
            'is_active' => 1,
        ];

        $first = ChainRepository::updateRestUrl($this->chainId, self::TARGET, $expected);
        self::assertSame(1, $first, 'the first connection matched and wrote');

        $original = $GLOBALS['wpdb'];
        ChainRepository::clearCache();

        try {
            $GLOBALS['wpdb'] = $this->peerWpdb();

            $second = ChainRepository::updateRestUrl($this->chainId, self::TARGET, $expected);
            self::assertSame(
                0,
                $second,
                'the second connection must match nothing — the row has already moved'
            );

            // ANTI-VACUITY: the peer connection CAN write, so the zero above is
            // the predicate refusing and not a broken second `$wpdb`.
            $repaired = ChainRepository::updateRestUrl($this->chainId, self::INCUMBENT, [
                'rest_url'  => self::TARGET,
                'slug'      => self::SLUG,
                'is_active' => 1,
            ]);
            self::assertSame(1, $repaired, 'anti-vacuity: the peer connection is able to write');
        } finally {
            $GLOBALS['wpdb'] = $original;
            ChainRepository::clearCache();
        }

        self::assertSame(
            self::INCUMBENT,
            $this->storedRestUrl(),
            'exactly two writes landed in total, the second undoing the first'
        );
    }

    // ── 6. The lock is the endpoint's own ────────────────────────────────

    /**
     * ⚠ DECOUPLING, AT THE REAL LOCK. A peer holding the SCANNER's chain lock
     * must not block an endpoint switch. The unit suite asserts the lock's NAME;
     * this asserts the consequence against a real `GET_LOCK`, which is the thing
     * an operator would actually feel.
     */
    public function testAPeerHoldingTheScannerLockDoesNotBlockTheSwitch(): void
    {
        $reviewId = $this->review();
        $this->peerTakes(CosmwasmDiscoveryWorker::ADVISORY_LOCK_PREFIX . $this->chainId);

        $result = $this->submit($reviewId);

        self::assertNotSame(
            'lock_contended',
            $result['reason'],
            'the scanner lock and the endpoint lock are different names'
        );
        self::assertTrue(
            $this->attemptedTheLiveProof($result['reason']),
            'and the switch proceeded to its proof — got ' . $result['reason']
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$peerConn instanceof \mysqli) {
            self::$peerConn->close();
        }

        self::$peerConn = null;
        self::$peerWpdb = null;
    }
}
