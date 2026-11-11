<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\AttestationScoreSynthesis;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-93 — the daily attestation decay sweep visits every active target exactly
 * once, even while members cast and revoke during the run, and never runs
 * twice at once.
 *
 * It used to page the GROUP BY work-list with OFFSET, on the documented
 * assumption that the list is stable during a run. It is not: a revoke that
 * drops a target BEFORE the current window shifts every later page left (a
 * target is skipped), and a new cast before it shifts them right (a target is
 * processed twice). There was no lock, so overlapping wp-cron runs swept in
 * parallel.
 *
 * Runs the REAL repository query against the REAL attestations table; the
 * per-target recompute is a recording closure (the sweep's test seam), so no
 * score tables are involved.
 */
#[Group('integration')]
#[Group('mariadb')]
final class AttestationDecaySweepIntegrationTest extends TestCase
{
    /** @var list<string> "kind:id" in visit order */
    private array $visited = [];

    private static int $attestor = 9100;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $wpdb  = $GLOBALS['wpdb'];
        $table = TableRegistry::trustAttestations();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
            bcc_trust_create_trust_attestations_table();
        }
        self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $wpdb->query('TRUNCATE TABLE `' . TableRegistry::trustAttestations() . '`');
        delete_option(AttestationScoreSynthesis::DECAY_CURSOR_OPTION);
        $this->visited = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        delete_option(AttestationScoreSynthesis::DECAY_CURSOR_OPTION);
        parent::tearDown();
    }

    private function service(): AttestationScoreSynthesis
    {
        $s = (new \ReflectionClass(AttestationScoreSynthesis::class))->newInstanceWithoutConstructor();
        (new \ReflectionObject($s))->getProperty('attestationRepo')->setValue($s, new AttestationRepository());
        return $s;
    }

    private function cast(string $kind, int $targetId): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->insert(TableRegistry::trustAttestations(), [
            'attestor_user_id' => self::$attestor++,
            'target_kind'      => $kind,
            'target_id'        => $targetId,
            'kind'             => 'vouch',
        ]);
        self::assertSame('', $wpdb->last_error, 'fixture: attestation row');
    }

    private function revokeTarget(string $kind, int $targetId): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . TableRegistry::trustAttestations() . '` SET revoked_at = UTC_TIMESTAMP()'
            . ' WHERE target_kind = %s AND target_id = %d AND revoked_at IS NULL',
            $kind,
            $targetId
        ));
        self::assertSame('', $wpdb->last_error);
    }

    /** @param (callable(string, int): void)|null $during runs inside each recompute */
    private function recorder(?callable $during = null): callable
    {
        return function (string $kind, int $id) use ($during): void {
            $this->visited[] = $kind . ':' . $id;
            if ($during !== null) {
                $during($kind, $id);
            }
        };
    }

    private function seedCards(int ...$ids): void
    {
        foreach ($ids as $id) {
            $this->cast('validator_card', $id);
        }
    }

    public function testEveryActiveTargetIsVisitedExactlyOnceAcrossPages(): void
    {
        $this->seedCards(11, 12, 13, 14, 15, 16, 17);
        $this->cast('user_profile', 5);
        $this->revokeTarget('validator_card', 16); // revoked → not on the work-list

        $summary = $this->service()->runDecaySweep($this->recorder(), 3);

        self::assertSame('ok', $summary['status']);
        self::assertTrue($summary['reached_end']);
        self::assertSame(
            ['user_profile:5', 'validator_card:11', 'validator_card:12', 'validator_card:13',
             'validator_card:14', 'validator_card:15', 'validator_card:17'],
            $this->visited
        );
        self::assertFalse(get_option(AttestationScoreSynthesis::DECAY_CURSOR_OPTION), 'a full pass clears the cursor');
    }

    /** THE F-93 BUG, direction 1: a revoke earlier in the list must not skip a later target. */
    public function testARevokeDuringTheRunDoesNotSkipALaterTarget(): void
    {
        $this->seedCards(21, 22, 23, 24, 25, 26);

        $this->service()->runDecaySweep($this->recorder(function (string $kind, int $id): void {
            if ($id === 21) {
                // Both rows on the first page go inactive mid-run. OFFSET 2
                // would then start at 25 and never visit 23 and 24.
                $this->revokeTarget('validator_card', 21);
                $this->revokeTarget('validator_card', 22);
            }
        }), 2);

        foreach ([23, 24, 25, 26] as $id) {
            self::assertContains('validator_card:' . $id, $this->visited, "target {$id} was skipped");
        }
    }

    /** THE F-93 BUG, direction 2: a cast earlier in the list must not repeat a target. */
    public function testACastDuringTheRunDoesNotProcessATargetTwice(): void
    {
        $this->seedCards(32, 34, 36, 38);

        $this->service()->runDecaySweep($this->recorder(function (string $kind, int $id): void {
            if ($id === 32) {
                // A new target sorting BEFORE the cursor. OFFSET 2 would then
                // re-read 34 on the second page.
                $this->cast('validator_card', 31);
            }
        }), 2);

        self::assertSame(array_values(array_unique($this->visited)), $this->visited, 'a target was processed twice');
        foreach ([32, 34, 36, 38] as $id) {
            self::assertContains('validator_card:' . $id, $this->visited);
        }
    }

    public function testHittingTheBoundSavesTheCursorAndTheNextRunResumes(): void
    {
        $this->seedCards(41, 42, 43, 44, 45);

        $first = $this->service()->runDecaySweep($this->recorder(), 2, 1);
        self::assertFalse($first['reached_end']);
        self::assertSame(['validator_card:41', 'validator_card:42'], $this->visited);
        self::assertSame('validator_card|42', get_option(AttestationScoreSynthesis::DECAY_CURSOR_OPTION));

        $this->visited = [];
        $this->service()->runDecaySweep($this->recorder(), 2, 5);
        self::assertSame(['validator_card:43', 'validator_card:44', 'validator_card:45'], $this->visited);
        self::assertFalse(get_option(AttestationScoreSynthesis::DECAY_CURSOR_OPTION));
    }

    /** A failed read is not "the end": progress is kept, the cursor is not reset. */
    public function testAFailedReadKeepsProgressAndIsNotTheEnd(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $this->seedCards(51, 52, 53, 54);

        $summary = $this->service()->runDecaySweep($this->recorder(function (string $kind, int $id) use ($wpdb): void {
            if ($id === 52) {
                $wpdb->failQueriesMatching = '/SELECT target_kind, target_id\s+FROM/i';
            }
        }), 2);
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertSame('read_failed', $summary['status']);
        self::assertFalse($summary['reached_end']);
        self::assertSame('validator_card|52', get_option(AttestationScoreSynthesis::DECAY_CURSOR_OPTION));
    }

    /**
     * #481 review: wpdb::query() can fail WITHOUT setting last_error (the
     * silent !ready path after a lost connection), and get_results($sql) then
     * hands back the previous statement's last_result — here, empty. Taken at
     * face value that is "no more targets", and the sweep would CLEAR its
     * cursor. The read must report it as a failure (null), not as the end.
     */
    public function testASilentQueryFailureIsAFailureNotTheEnd(): void
    {
        $real = $GLOBALS['wpdb'];
        $fake = new class {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            /** @var list<object> stale rows left by an earlier statement */
            public array $last_result = [];

            /** @param mixed ...$a */
            public function prepare(string $q, ...$a): string
            {
                return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $q), $a);
            }

            /** The keyset SELECT fails silently: false, and no last_error. */
            public function query(string $sql)
            {
                return false;
            }

            /** What real wpdb does here: re-run via query(), then hand back last_result. */
            public function get_results(?string $sql = null)
            {
                if ($sql !== null) {
                    $this->query($sql);
                }
                return $this->last_result;
            }
        };

        $GLOBALS['wpdb'] = $fake;
        try {
            $result = (new AttestationRepository())->listTargetsWithActiveAttestationsAfter('', 0, 50);
        } finally {
            $GLOBALS['wpdb'] = $real;
        }

        self::assertNull($result, 'a silent query failure is a failed read, never an empty last page');
    }

    /** An overlapping run (another connection holds the lock) does nothing. */
    public function testAnOverlappingRunSkips(): void
    {
        $this->seedCards(61);
        $peer = mysqli_connect(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            '',
            (int) (getenv('BCC_TEST_DB_PORT') ?: 3306)
        );
        self::assertNotFalse($peer, 'fixture: peer connection');
        $name = mysqli_real_escape_string($peer, AttestationScoreSynthesis::DECAY_LOCK);
        $got  = mysqli_fetch_row(mysqli_query($peer, "SELECT GET_LOCK('{$name}', 0)"));
        self::assertSame('1', (string) $got[0], 'fixture: the peer holds the lock');

        try {
            $summary = $this->service()->runDecaySweep($this->recorder());
            self::assertSame('locked', $summary['status']);
            self::assertSame([], $this->visited);
        } finally {
            mysqli_query($peer, "SELECT RELEASE_LOCK('{$name}')");
            mysqli_close($peer);
        }

        // And once released, the same call runs.
        self::assertSame('ok', $this->service()->runDecaySweep($this->recorder())['status']);
        self::assertSame(['validator_card:61'], $this->visited);
    }
}
