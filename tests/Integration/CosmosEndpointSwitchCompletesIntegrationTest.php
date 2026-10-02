<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\CosmosEndpointReview;
use BCC\Trust\Onchain\Services\CosmosEndpointTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/endpoint-switch-integration-stubs.php';

/**
 * THE COMPLETE SWITCH, end to end, through the real service.
 *
 * Verification -> CAS -> read-back -> breaker -> audit, against a real database
 * on BOTH ENGINES, with one write and the review consumed.
 *
 * ── WHY THIS CAN EXIST, HAVING EARLIER BEEN REPORTED AS IMPOSSIBLE ──────
 * It was reported that the harness could not complete a switch because
 * outbound traffic is forbidden. That conflated two different things. Forbidding
 * a REQUEST does not forbid supplying a RESPONSE, and the two obstacles that
 * actually stood in the way both have seams that need no production change:
 *
 *   1. the transport. `wp_remote_get()` is undefined in this harness, so it is
 *      defined in `endpoint-switch-integration-stubs.php` as a DEFAULT-DENY
 *      scripted stub: it never opens a socket, and it refuses unless a test has
 *      explicitly scripted a response. Nothing can reach a provider.
 *
 *   2. the resolver. `SafeHttpClient::prepareArgs()` calls `gethostbyname()`
 *      before pinning an IP, so even a scripted 200 would have cost a real
 *      lookup. It consults its own per-request resolver cache first, so the
 *      cache is seeded with a sentinel address from TEST-NET-3.
 *
 * ⚠ AND THE STUBBED RESOLVER IS PROVED, NOT ASSUMED.
 * `prepareArgs()` records what it pinned in `SafeHttpClient::$pinnedResolves`,
 * so {@see testTheProofIsMadeWithoutResolvingTheHost} reads the sentinel back
 * out. If the resolver had been consulted the pinned address would be a real
 * one, and that test would fail.
 *
 * So the REAL `CosmosEndpointVerifier` runs, against the REAL
 * `CosmosEndpointPolicy`, and its answer is decided by a scripted body — while
 * no socket is opened and no name is resolved.
 *
 * ── THE ROW UNDER TEST ──────────────────────────────────────────────────
 * `CosmosEndpointPolicy` governs exactly one slug, `cosmos`, and
 * `wp_bcc_chains` carries `UNIQUE KEY slug`, so this takes the bootstrap's own
 * seeded row, records what it found and puts it back. PHPUnit runs the suite
 * sequentially in one process, so no other test can observe the window.
 */
#[CoversClass(CosmosEndpointTransition::class)]
#[Group('integration')]
#[Group('mariadb')]
final class CosmosEndpointSwitchCompletesIntegrationTest extends TestCase
{
    private const SLUG = 'cosmos';

    private const INCUMBENT = 'https://rest.cosmos.directory/cosmoshub';

    private const TARGET = 'https://cosmos-api.polkachu.com';

    private const TARGET_HOST = 'cosmos-api.polkachu.com';

    private const NETWORK = 'cosmoshub-4';

    private const OPERATOR = 9101;

    /** TEST-NET-3, reserved for documentation. Never contacted. */
    private const SENTINEL_IP = '203.0.113.10';

    private const REVIEW_PREFIX = 'bcc_cosmos_ep_review_';

    private static ?\mysqli $peerConn = null;

    private static ?MysqliWpdb $peerWpdb = null;

    private int $chainId = 0;

    /** @var array{rest_url: ?string, is_active: int} */
    private array $snapshot = ['rest_url' => null, 'is_active' => 1];

    // ── Harness ─────────────────────────────────────────────────────────

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

    private function peerWpdb(): MysqliWpdb
    {
        if (!self::$peerWpdb instanceof MysqliWpdb) {
            self::$peerWpdb = new MysqliWpdb($this->peerConnection(), 'wp_');
        }

        return self::$peerWpdb;
    }

    private function lockName(): string
    {
        return 'bcc_cosmos_endpoint_' . $this->chainId;
    }

    private function releaseLockEverywhere(): void
    {
        $lock = $this->lockName();

        $conn = $this->peerConnection();
        $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock) . "')");

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }

    private function resolveChain(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, rest_url, is_active FROM `{$table}`
              WHERE CAST(slug AS BINARY) = CAST(%s AS BINARY) LIMIT 1",
            self::SLUG
        ));

        self::assertIsObject($row, 'the bootstrap must have seeded a `cosmos` chain');

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

    private function deleteAuditRows(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . TableRegistry::activity() . '` WHERE action = %s',
            CosmosEndpointTransition::AUDIT_ACTION
        ));
    }

    /** @return list<object> */
    private function auditRows(): array
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, action, target_type, target_id, meta
               FROM `' . TableRegistry::activity() . '`
              WHERE action = %s ORDER BY id ASC',
            CosmosEndpointTransition::AUDIT_ACTION
        ));

        return $rows;
    }

    private function probeLockName(): string
    {
        return \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::PROBE_LOCK_PREFIX . $this->chainId;
    }

    private function breakerStateKey(): string
    {
        return 'bcc_cb_' . $this->chainId;
    }

    /**
     * ⚠ NOT `delete_option()` — this harness does not define it, and its
     * options live in an in-memory array while
     * `OnchainCircuitBreakerRepository::deleteCounter()` runs raw SQL against the
     * options TABLE. The two do not see each other, which is why nothing below
     * asserts on the counter option: the assertions are on the state the breaker
     * genuinely clears here — the transient and the probe lock.
     */
    private function clearBreakerState(): void
    {
        unset($GLOBALS['__bcc_test_options']['_bcc_cb_counter_' . $this->chainId]);
        delete_transient($this->breakerStateKey());
        wp_cache_delete(
            'cb_' . $this->chainId,
            \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::CACHE_GROUP
        );

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->probeLockName()));

        $conn = $this->peerConnection();
        $conn->query(
            "SELECT RELEASE_LOCK('" . $conn->real_escape_string($this->probeLockName()) . "')"
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveChain();
        $this->setRestUrl(self::INCUMBENT, 1);

        CosmosEndpointReview::forget(self::OPERATOR);
        \BccEndpointTransportSpy::reset();
        \BccEndpointTransportSpy::pinHost(self::TARGET_HOST, self::SENTINEL_IP);

        $GLOBALS['bcc_itest_current_user'] = self::OPERATOR;

        $this->deleteAuditRows();
        $this->clearBreakerState();
        $this->releaseLockEverywhere();
    }

    protected function tearDown(): void
    {
        $this->releaseLockEverywhere();

        CosmosEndpointReview::forget(self::OPERATOR);
        \BccEndpointTransportSpy::reset();
        $this->deleteAuditRows();
        $this->clearBreakerState();
        unset($GLOBALS['bcc_itest_current_user']);

        $this->setRestUrl($this->snapshot['rest_url'], $this->snapshot['is_active']);

        parent::tearDown();
    }

    private function review(): string
    {
        $r = CosmosEndpointTransition::review($this->chainId, self::TARGET, self::OPERATOR);
        self::assertTrue($r['ok'], 'test precondition: review accepted — got ' . $r['reason']);

        return (string) $r['review_id'];
    }

    /** @return array{ok: bool, reason: string, verified_network: string|null, failed_followups: list<string>} */
    private function submit(string $reviewId): array
    {
        return CosmosEndpointTransition::execute($this->chainId, $reviewId, self::OPERATOR);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE COMPLETE SUCCESSFUL SWITCH
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ THE WHOLE OPERATION, IN ONE PASS, AGAINST A REAL DATABASE.
     *
     * Every leg of the design's flow is observed: the proof was made, the CAS
     * matched exactly one row, the read-back confirmed it, the breaker was
     * handled, the audit row was written, and the review was spent.
     */
    public function testTheCompleteSwitchSucceedsEndToEnd(): void
    {
        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);

        $result = $this->submit($this->review());

        self::assertTrue($result['ok'], 'the switch must succeed');
        self::assertSame('switched', $result['reason'], 'and say so plainly');
        self::assertSame([], $result['failed_followups'], 'with no follow-up failures');
        self::assertSame(self::NETWORK, $result['verified_network']);

        self::assertSame(self::TARGET, $this->storedRestUrl(), 'the column moved');
        self::assertNull(CosmosEndpointReview::peek(self::OPERATOR), 'the review was spent');
        self::assertCount(1, \BccEndpointTransportSpy::$calls, 'exactly one proof');
        self::assertCount(1, $this->auditRows(), 'exactly one audit row');
    }

    /**
     * ⚠ ONE WRITE. Not "a write happened" — exactly one column of exactly one
     * row differs afterwards, which an `id` predicate plus `LIMIT 1` should
     * guarantee but only a whole-table comparison can show.
     *
     * Snapshotted in PHP rather than with `MD5(GROUP_CONCAT(...))`:
     * `group_concat_max_len` defaults to 1024 bytes, and 20 seeded chains
     * overflow it, so a digest could silently omit the very row under test.
     */
    public function testExactlyOneColumnOfOneRowChanges(): void
    {
        $before = $this->allChains();

        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);
        self::assertSame('switched', $this->submit($this->review())['reason']);

        $after = $this->allChains();

        self::assertGreaterThan(1, count($before), 'anti-vacuity: many chains exist');
        self::assertSame(array_keys($before), array_keys($after), 'no row added or removed');

        $differing = [];
        foreach ($before as $id => $row) {
            foreach ($row as $column => $value) {
                if ($after[$id][$column] !== $value) {
                    $differing[] = $id . '.' . $column;
                }
            }
        }

        self::assertSame(
            [$this->chainId . '.rest_url'],
            $differing,
            'exactly one column of exactly one row may differ'
        );
    }

    /**
     * Every chain row, keyed by id. Explicit columns, no `SELECT *`.
     *
     * @return array<int, array<string, string|null>>
     */
    private function allChains(): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $rows = $wpdb->get_results(
            "SELECT id, slug, rest_url, is_active FROM `{$table}` ORDER BY id ASC"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'slug'      => (string) $row->slug,
                'rest_url'  => $row->rest_url === null ? null : (string) $row->rest_url,
                'is_active' => (string) $row->is_active,
            ];
        }

        return $out;
    }
    /**
     * ⚠ NO NAME WAS RESOLVED. The sentinel address is read back out of
     * `SafeHttpClient::$pinnedResolves`, so the address came from the seeded
     * resolver cache and not from the OS resolver. A real lookup would have
     * pinned a real IP and this would fail.
     *
     * ⚠⚠ CAPTURED DURING THE REQUEST, NOT AFTER IT. `SafeHttpClient::get()`
     * clears the pin in a `finally`, so that a later exception or retry cannot
     * leak a stale IP binding into an unrelated call on the same worker. Reading
     * it afterwards therefore finds nothing — the only moment it exists is
     * while the transport is being called, which is exactly when this hook runs.
     */
    public function testTheProofIsMadeWithoutResolvingTheHost(): void
    {
        $pinnedDuringProof = [];

        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);
        \BccEndpointTransportSpy::$onFirstCall = static function () use (&$pinnedDuringProof): void {
            $pinnedDuringProof = \BccEndpointTransportSpy::pinnedResolves();
        };

        self::assertSame('switched', $this->submit($this->review())['reason']);

        self::assertArrayHasKey(
            self::TARGET_HOST,
            $pinnedDuringProof,
            'the host must have been pinned when the request was made'
        );
        self::assertSame(
            self::TARGET_HOST . ':443:' . self::SENTINEL_IP,
            $pinnedDuringProof[self::TARGET_HOST],
            'the pinned address must be the seeded sentinel, not a resolved one'
        );

        self::assertSame(
            self::TARGET . '/cosmos/base/tendermint/v1beta1/node_info',
            \BccEndpointTransportSpy::$calls[0]['url'],
            'and the proof asked the destination for its node_info'
        );

        // And the pin does not outlive the request.
        self::assertArrayNotHasKey(
            self::TARGET_HOST,
            \BccEndpointTransportSpy::pinnedResolves(),
            'the pin is released once the call returns'
        );
    }
    /**
     * ⚠ THE PROOF PRECEDES THE WRITE, OBSERVED AT THE DATABASE.
     *
     * Read the column from inside the transport callback — which runs within
     * the lock, between the consume and the CAS. It must still say the
     * incumbent. The unit suite asserts this ordering against a fake; this
     * asserts it against the row itself.
     */
    public function testAtTheMomentOfTheProofTheColumnHasNotMovedYet(): void
    {
        $seenDuringProof = 'not-observed';

        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);
        \BccEndpointTransportSpy::$onFirstCall = function () use (&$seenDuringProof): void {
            $seenDuringProof = (string) $this->storedRestUrl();
        };

        self::assertSame('switched', $this->submit($this->review())['reason']);

        self::assertSame(
            self::INCUMBENT,
            $seenDuringProof,
            'the endpoint must still be the incumbent while the proof is in flight'
        );
        self::assertSame(self::TARGET, $this->storedRestUrl(), 'and the new host afterwards');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. BREAKER HANDLING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ "NOTHING TO CLEAR" IS A FACT, NOT A FAILURE — against the REAL breaker.
     *
     * A healthy chain has no breaker state, so `forgetForEndpointChange()`
     * answers false. Treating that as a failed follow-up would warn the operator
     * on every ordinary switch.
     */
    public function testAHealthyChainReportsNothingToClearWithoutCallingItAFailure(): void
    {
        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);

        $result = $this->submit($this->review());

        self::assertSame('switched', $result['reason']);
        self::assertSame([], $result['failed_followups']);

        $meta = $this->metaOfTheOnlyAuditRow();
        self::assertFalse($meta['breaker_cleared'], 'recorded as a fact: there was nothing to clear');
    }

    /**
     * ⚠ AND REAL STATE IS REALLY CLEARED, INCLUDING THE PROBE LOCK.
     *
     * Breaker state is CHAIN-keyed, so it was earned by whichever host was
     * configured when it was recorded. Carried onto a replacement, the new
     * endpoint inherits failures it never caused and can be refused for them.
     *
     * The half-open probe matters most: it is a real `GET_LOCK`, so a stranded
     * one is invisible to the session holding it and blocks every OTHER worker
     * from probing the chain. It is taken here on the suite's session and its
     * release is observed FROM THE PEER — the only vantage point from which
     * "is this probe available to anyone else" is a fact rather than reentrancy.
     */
    public function testRealBreakerStateIsClearedAndTheProbeIsReleased(): void
    {
        set_transient($this->breakerStateKey(), ['failures' => 3, 'opened_at' => time()], 0);

        $wpdb = $GLOBALS['wpdb'];
        self::assertSame(
            '1',
            (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->probeLockName())),
            'test precondition: this session holds the half-open probe'
        );

        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);
        $result = $this->submit($this->review());

        self::assertSame('switched', $result['reason']);
        self::assertSame([], $result['failed_followups']);

        self::assertFalse(
            get_transient($this->breakerStateKey()),
            'the replaced host\'s breaker state must be gone'
        );
        self::assertTrue(
            $this->metaOfTheOnlyAuditRow()['breaker_cleared'],
            'and the audit row must record that there WAS something to clear'
        );

        $conn = $this->peerConnection();
        $got  = $conn->query(
            "SELECT GET_LOCK('" . $conn->real_escape_string($this->probeLockName()) . "', 0)"
        );
        self::assertSame(
            1,
            (int) ($got->fetch_row()[0] ?? 0),
            'another worker must be able to probe the chain again'
        );
    }
    // ═══════════════════════════════════════════════════════════════════
    //  3. THE AUDIT ROW
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function metaOfTheOnlyAuditRow(): array
    {
        $rows = $this->auditRows();
        self::assertCount(1, $rows, 'exactly one audit row');

        $meta = json_decode((string) $rows[0]->meta, true);
        self::assertIsArray($meta, 'the audit meta must be readable JSON');

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    public function testTheAuditRowNamesTheChainTheActorAndBothHosts(): void
    {
        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);
        $this->submit($this->review());

        $row = $this->auditRows()[0];
        self::assertSame((string) self::OPERATOR, (string) $row->user_id);
        self::assertSame('chain', (string) $row->target_type);
        self::assertSame((string) $this->chainId, (string) $row->target_id);

        $meta = $this->metaOfTheOnlyAuditRow();
        self::assertSame(self::INCUMBENT, $meta['from']);
        self::assertSame(self::TARGET, $meta['to']);
        self::assertSame('primary', $meta['to_role']);
        self::assertSame(self::NETWORK, $meta['verified_network']);
        self::assertSame('switched', $meta['outcome']);
        self::assertSame(self::OPERATOR, $meta['actor']);
        self::assertSame([], $meta['failed_followups']);
        self::assertNotSame('', (string) $meta['endpoint_fp']);
    }

    /**
     * ⚠ THE AUDIT ROW IS DURABLE AND WIDELY READABLE, so it carries hosts,
     * roles and counts and nothing else — no scanner cursor fields, and nothing
     * derived from a credential.
     */
    public function testTheAuditRowCarriesNoCursorFieldsAndNoFingerprintOfTheIncumbent(): void
    {
        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);
        $this->submit($this->review());

        $raw  = (string) $this->auditRows()[0]->meta;
        $meta = $this->metaOfTheOnlyAuditRow();

        // Anti-vacuity: the row really does describe this switch, so the
        // absences below are read off a populated row.
        self::assertStringContainsString(self::TARGET, $raw);

        foreach (['cleared_families', 'code_cursor_cleared', 'watermark_kept', 'cursor'] as $retired) {
            self::assertArrayNotHasKey($retired, $meta, "the retired field '{$retired}' must not appear");
            self::assertStringNotContainsString($retired, $raw);
        }

        self::assertStringNotContainsString(
            CosmosEndpointReview::fingerprint(self::INCUMBENT),
            $raw,
            'the review fingerprint is a comparison token and belongs in no durable row'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. A REFUSED PROOF, WITH A REAL RESPONSE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The host answers, and answers as the WRONG CHAIN. This is the case the
     * whole proof exists for: a reachable, well-formed endpoint serving a
     * different network.
     */
    public function testAHostServingAnotherNetworkIsRefusedAndNothingIsWritten(): void
    {
        \BccEndpointTransportSpy::scriptNodeInfo('osmosis-1');

        $result = $this->submit($this->review());

        self::assertFalse($result['ok']);
        self::assertSame('target_network_mismatch', $result['reason']);
        self::assertSame(self::INCUMBENT, $this->storedRestUrl(), 'the column must not move');
        self::assertSame([], $this->auditRows(), 'and a pre-write refusal writes no audit row');
        self::assertCount(1, \BccEndpointTransportSpy::$calls, 'exactly one proof was attempted');
    }

    public function testAnUnreadableBodyIsRefusedAndNothingIsWritten(): void
    {
        \BccEndpointTransportSpy::scriptRaw(200, 'this is not json');

        $result = $this->submit($this->review());

        self::assertSame('target_identity_unreadable', $result['reason']);
        self::assertSame(self::INCUMBENT, $this->storedRestUrl());
        self::assertSame([], $this->auditRows());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. GENUINELY OVERLAPPING SUBMISSIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠⚠ THE SUBMISSIONS GENUINELY OVERLAP. This is not a deterministic
     * sequence of whole submissions: the second one is launched from INSIDE the
     * first one's proof, which runs inside the first one's advisory lock, and it
     * runs on a SECOND DATABASE CONNECTION so `GET_LOCK` really contends.
     *
     * `GET_LOCK` is reentrant per session, so running the inner submission on
     * the suite's own connection would acquire the lock a second time and prove
     * the opposite of production. Swapping `$GLOBALS['wpdb']` is what moves it
     * to another session, because `AdvisoryLock` and the repositories both read
     * the global.
     *
     * At most one verification and one write, whichever arrives first.
     */
    public function testGenuinelyOverlappingSubmissionsPermitOneVerificationAndOneWrite(): void
    {
        $reviewId = $this->review();
        $inner    = ['reason' => 'not-run'];

        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);
        \BccEndpointTransportSpy::$onFirstCall = function () use ($reviewId, &$inner): void {
            $original        = $GLOBALS['wpdb'];
            $GLOBALS['wpdb'] = $this->peerWpdb();

            try {
                $inner = CosmosEndpointTransition::execute($this->chainId, $reviewId, self::OPERATOR);
            } finally {
                $GLOBALS['wpdb'] = $original;
                ChainRepository::clearCache();
            }
        };

        $outer = $this->submit($reviewId);

        self::assertSame('switched', $outer['reason'], 'the first submission completes');
        self::assertSame(
            'lock_contended',
            $inner['reason'],
            'the overlapping submission must be refused by the lock the first one holds'
        );

        self::assertCount(
            1,
            \BccEndpointTransportSpy::$calls,
            'ONE verification: the refused submission never reached a proof'
        );
        self::assertSame(self::TARGET, $this->storedRestUrl());
        self::assertCount(1, $this->auditRows(), 'ONE write, so one audit row');
    }

    /**
     * ANTI-VACUITY for the overlap: the inner submission is a well-formed one
     * that WOULD succeed on its own. Run the same submission with nothing
     * holding the lock and it completes — so `lock_contended` above is the lock
     * refusing it, not the submission being malformed.
     */
    public function testTheInnerSubmissionWouldSucceedOnItsOwnConnection(): void
    {
        $reviewId = $this->review();

        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);

        $original        = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->peerWpdb();

        try {
            $result = CosmosEndpointTransition::execute($this->chainId, $reviewId, self::OPERATOR);
        } finally {
            $GLOBALS['wpdb'] = $original;
            ChainRepository::clearCache();
        }

        self::assertSame(
            'switched',
            $result['reason'],
            'the peer connection can run a complete switch when the lock is free'
        );
        self::assertSame(self::TARGET, $this->storedRestUrl());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  6. REVIEW EXPIRY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The one pending review record, located by prefix rather than by rebuilding
     * the private key format.
     */
    private function reviewRecordKey(): string
    {
        $keys = [];
        foreach (array_keys($GLOBALS['__bcc_test_transients'] ?? []) as $key) {
            if (str_starts_with((string) $key, self::REVIEW_PREFIX)) {
                $keys[] = (string) $key;
            }
        }

        self::assertCount(1, $keys, 'exactly one pending review record must exist');

        return $keys[0];
    }

    /** ⚠ The TTL is the window an operator has, so it is worth pinning. */
    public function testTheReviewRecordExpiresTenMinutesAfterItIsMinted(): void
    {
        $before = time();
        $this->review();
        $after = time();

        [, $expiresAt] = $GLOBALS['__bcc_test_transients'][$this->reviewRecordKey()];

        self::assertGreaterThanOrEqual($before + 600, (int) $expiresAt);
        self::assertLessThanOrEqual($after + 600, (int) $expiresAt);
    }

    /**
     * ⚠ AN EXPIRED CONFIRMATION BUYS NOTHING.
     *
     * The record is backdated rather than waited out, so the REAL expiry check
     * in `get_transient()` is what refuses it — the same check production runs.
     * A confirmation an operator left open over lunch must cost zero provider
     * calls and zero writes.
     */
    public function testAnExpiredReviewIsRefusedWithNoProofAndNoWrite(): void
    {
        $reviewId = $this->review();
        $key      = $this->reviewRecordKey();

        [$record] = $GLOBALS['__bcc_test_transients'][$key];
        $GLOBALS['__bcc_test_transients'][$key] = [$record, time() - 1];

        // Script a response anyway: if anything reached the proof, it would
        // have SUCCEEDED and written. Nothing may.
        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);

        $result = $this->submit($reviewId);

        self::assertFalse($result['ok']);
        self::assertSame('review_token_invalid', $result['reason']);
        self::assertSame([], \BccEndpointTransportSpy::$calls, 'ZERO provider calls after expiry');
        self::assertSame(self::INCUMBENT, $this->storedRestUrl(), 'and ZERO writes');
        self::assertSame([], $this->auditRows());
        self::assertNull(CosmosEndpointReview::peek(self::OPERATOR), 'the expired record is gone');
    }

    /**
     * ANTI-VACUITY for expiry. The SAME record, backdated to one second in the
     * FUTURE instead of the past, is accepted and completes — so the refusal
     * above is the expiry and not the backdating itself.
     */
    public function testTheSameRecordStillInsideItsWindowIsAccepted(): void
    {
        $reviewId = $this->review();
        $key      = $this->reviewRecordKey();

        [$record] = $GLOBALS['__bcc_test_transients'][$key];
        $GLOBALS['__bcc_test_transients'][$key] = [$record, time() + 30];

        \BccEndpointTransportSpy::scriptNodeInfo(self::NETWORK);

        $result = $this->submit($reviewId);

        self::assertSame('switched', $result['reason'], 'an unexpired record still works');
        self::assertSame(self::TARGET, $this->storedRestUrl());
        self::assertCount(1, \BccEndpointTransportSpy::$calls);
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
