<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security\Tests;

use BCC\Trust\Core\Repositories\AuditLogRepository;
use BCC\Trust\Core\Repositories\DeviceFingerprintRepository;
use BCC\Trust\Core\Repositories\FraudAnalysisRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\BehavioralAnalyzer;
use BCC\Trust\Core\Security\DeviceFingerprinter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-103/F-184 + F-108 + F-128 — fraud-score inputs that penalised ordinary
 * members.
 *
 * Shared devices: the penalty added to fraud_score on EVERY vote (two
 * accounts behind one laptop were blocked after ~3 votes). F-103 capped it;
 * F-184 (user decision) removed it: a shared device — client or server
 * fingerprint — is recorded for admins and adds nothing.
 *
 * F-128: the night-owl check judged everyone in UTC (no timezone is ever
 * recorded) → now dormant unless a timezone is known; a throttled analysis
 * read as "clean" (score 0) → now returns the last stored score.
 *
 * ── Why separate processes, and stubs declared in setUp()
 * The code under test calls get_user_meta / update_user_meta / get_option /
 * wp_cache_* unqualified in BCC\Trust\Core\Security. DeviceFingerprintConsent
 * Test shadows update_user_meta in that same namespace at FILE level. PHPUnit
 * loads every test file in the parent process to discover tests, so a
 * file-level guarded stub here would silently replace (or be replaced by)
 * theirs. Declaring ours via eval() inside setUp() means they exist only in
 * this suite's child processes.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FraudInputFixesTest extends TestCase
{
    private const USER = 4401;

    /** @var list<array{0:int,1:int,2:string}> */
    public static array $increments = [];

    protected function setUp(): void
    {
        $GLOBALS['__fi_usermeta'] = [];
        $GLOBALS['__fi_options']  = [];
        $GLOBALS['__fi_cache']    = ['add' => true];
        self::$increments         = [];
        $_SERVER['REMOTE_ADDR']   = '203.0.113.9';

        $ns = 'BCC\\Trust\\Core\\Security';
        $stubs = [
            'get_user_meta'    => 'function get_user_meta(int $u, string $k = "", bool $s = false) { return $GLOBALS["__fi_usermeta"][$u][$k] ?? ($s ? "" : []); }',
            'update_user_meta' => 'function update_user_meta(int $u, string $k, $v): bool { $GLOBALS["__fi_usermeta"][$u][$k] = $v; return true; }',
            'delete_user_meta' => 'function delete_user_meta(int $u, string $k): bool { unset($GLOBALS["__fi_usermeta"][$u][$k]); return true; }',
            'get_option'       => 'function get_option(string $k, $d = false) { return $GLOBALS["__fi_options"][$k] ?? $d; }',
            'wp_cache_get'     => 'function wp_cache_get($k, string $g = "", bool $f = false, &$found = null) { return false; }',
            'wp_cache_add'     => 'function wp_cache_add($k, $v, string $g = "", int $e = 0): bool { return (bool) $GLOBALS["__fi_cache"]["add"]; }',
            'wp_cache_set'     => 'function wp_cache_set($k, $v, string $g = "", int $e = 0): bool { return true; }',
        ];
        foreach ($stubs as $name => $code) {
            if (!function_exists($ns . '\\' . $name)) {
                eval('namespace ' . $ns . '; ' . $code);
            }
        }
    }

    // ── F-184: shared devices are recorded, never penalised ─────────────

    private function fingerprinter(): DeviceFingerprinter
    {
        $ref = new \ReflectionClass(DeviceFingerprinter::class);
        $fp  = $ref->newInstanceWithoutConstructor();

        $repo = new class extends UserInfoRepository {
            public function __construct() {}
            public function incrementFraudScore(int $userId, int $increment, string $reason): void
            {
                FraudInputFixesTest::$increments[] = [$userId, $increment, $reason];
            }
        };
        $ref->getProperty('userInfoRepo')->setValue($fp, $repo);
        $ref->getProperty('automationHigh')->setValue($fp, BCC_TRUST_AUTOMATION_HIGH);
        $ref->getProperty('automationMedium')->setValue($fp, BCC_TRUST_AUTOMATION_MEDIUM);
        $ref->getProperty('ringMinSize')->setValue($fp, BCC_TRUST_RING_MIN_SIZE);
        return $fp;
    }

    private function stubStorePathFunctions(): void
    {
        $ns    = 'BCC\\Trust\\Core\\Security';
        $stubs = [
            'wp_salt'       => 'function wp_salt(string $s = "auth"): string { return "salt"; }',
            'wp_list_pluck' => 'function wp_list_pluck(array $l, string $f): array { return array_map(static fn ($o) => is_object($o) ? $o->$f : $o[$f], $l); }',
            'current_time'  => 'function current_time(string $t, $g = 0) { return gmdate("Y-m-d H:i:s"); }',
        ];
        foreach ($stubs as $name => $code) {
            if (!function_exists($ns . '\\' . $name)) {
                eval('namespace ' . $ns . '; ' . $code);
            }
        }
        // hasConsent() calls these fully qualified (global namespace).
        if (!function_exists('get_option')) {
            eval('function get_option(string $k, $d = false) { return $GLOBALS["__fi_options"][$k] ?? $d; }');
        }
        if (!function_exists('get_user_meta')) {
            eval('function get_user_meta(int $u, string $k = "", bool $s = false) { return $GLOBALS["__fi_usermeta"][$u][$k] ?? ($s ? "" : []); }');
        }
        // AuditLogger::log() returns early when its table is absent.
        (new \ReflectionProperty(AuditLogger::class, 'tableExists'))->setValue(null, false);
    }

    public function testADeviceSharedByFourAccountsAddsNoFraudPoints(): void
    {
        $this->stubStorePathFunctions();
        $GLOBALS['__fi_usermeta'][self::USER]['_bcc_fingerprint_consent'] = '1';

        $fp   = $this->fingerprinter();
        $repo = new class extends DeviceFingerprintRepository {
            /** @var list<array<string, mixed>> */
            public array $inserted = [];
            public function __construct() {}
            public function getOtherUsersForFingerprint(string $fingerprint, int $excludeUserId): array
            {
                return [(object) ['user_id' => 11], (object) ['user_id' => 12], (object) ['user_id' => 13]];
            }
            public function findByUserAndFingerprint(int $userId, string $fingerprint): ?object
            {
                // First visit inserts; every later one updates the same row.
                return $this->inserted === [] ? null : (object) ['id' => 77];
            }
            public function insert(array $data, array $formats): int|false { $this->inserted[] = $data; return 77; }
            public function updateById(int $id, array $data, array $formats): int|false { return 1; }
            public function getFingerprintUserCount(string $fingerprint): int { return 1; }
            public function upsertServerFingerprint(int $userId, string $fingerprint, string $ipHash, string $userAgent): void {}
        };
        $analysis = new class extends FraudAnalysisRepository {
            public int $stored = 0;
            public function __construct() {}
            public function storeAnalysis(int $userId, int $fraudScore, string $riskLevel, float $confidence, array $triggers, array $details = [], ?string $expiresAt = null): int
            {
                $this->stored++;
                return 1;
            }
        };
        $ref = new \ReflectionClass(DeviceFingerprinter::class);
        $ref->getProperty('fpRepo')->setValue($fp, $repo);
        $ref->getProperty('fraudAnalysisRepo')->setValue($fp, $analysis);

        // Ten votes from the family laptop: the first inserts the row, the
        // other nine take the update branch. Neither branch may add points.
        for ($vote = 0; $vote < 10; $vote++) {
            self::assertSame(77, $fp->storeFingerprint(self::USER, 'family-laptop', ['confidence' => 0, 'signals' => []]));
        }

        self::assertCount(1, $repo->inserted, 'precondition: one insert, then updates');
        self::assertSame([], self::$increments, 'four accounts on one device: no fraud points (F-184)');
        self::assertSame('medium', $repo->inserted[0]['risk_level'], 'still flagged for admins');
        self::assertSame(1, $analysis->stored, 'still recorded for admin review');
    }

    public function testSharingNoLongerRaisesDeviceFraudProbability(): void
    {
        // A shared device's stored risk_level is 'medium' (the admin flag);
        // with no automation evidence the probability must stay 0.
        $fp  = $this->fingerprinter();
        $row = (object) ['id' => 1, 'user_id' => self::USER, 'fingerprint' => 'family-laptop', 'automation_score' => 0, 'risk_level' => 'medium', 'last_seen' => '2026-11-11 00:00:00'];

        self::assertSame(0.0, (float) $fp->calculateDeviceFraudProbability(self::USER, [$row]));

        // Automation evidence still counts, exactly as before.
        $bot = (object) ['id' => 2, 'user_id' => self::USER, 'fingerprint' => 'x', 'automation_score' => BCC_TRUST_AUTOMATION_HIGH, 'risk_level' => 'high', 'last_seen' => '2026-11-11 00:00:00'];
        $expected = ((BCC_TRUST_AUTOMATION_HIGH / 100 * 0.4) + 0.3) * 0.7 + (BCC_TRUST_AUTOMATION_HIGH / 100) * 0.3;
        self::assertEqualsWithDelta($expected, $fp->calculateDeviceFraudProbability(self::USER, [$bot]), 1e-9);
    }

    // ── F-108: a shared SERVER fingerprint is logged, not penalised ─────

    public function testASharedServerFingerprintAddsNoFraudPoints(): void
    {
        $this->stubStorePathFunctions();

        $fp   = $this->fingerprinter();
        $repo = new class extends DeviceFingerprintRepository {
            public int $upserts = 0;
            public function __construct() {}
            public function getFingerprintUserCount(string $fingerprint): int { return 4; }
            public function upsertServerFingerprint(int $userId, string $fingerprint, string $ipHash, string $userAgent): void { $this->upserts++; }
        };
        (new \ReflectionProperty(DeviceFingerprinter::class, 'fpRepo'))->setValue($fp, $repo);

        (new \ReflectionMethod(DeviceFingerprinter::class, 'storeServerSideFingerprint'))->invoke($fp, self::USER);

        self::assertSame([], self::$increments, 'four accounts on one server fingerprint: recorded, no points (F-108)');
        self::assertSame(1, $repo->upserts, 'the fingerprint is still stored for review');
    }

    // ── F-128: night owl only with a known timezone ──────────────────────

    /** @return list<string> */
    private function temporalReasons(): array
    {
        $ref = new \ReflectionClass(BehavioralAnalyzer::class);
        $ba  = $ref->newInstanceWithoutConstructor();

        // Twelve activity buckets, all at 02:00 — "asleep" in UTC.
        $audit = new class extends AuditLogRepository {
            public function __construct() {}
            public function getActivityBreakdown(int $userId, int $days = 30): array
            {
                $rows = [];
                for ($d = 1; $d <= 12; $d++) {
                    $rows[] = (object) ['hour' => 2, 'count' => 3, 'day_of_week' => ($d % 7) + 1, 'date' => sprintf('2026-10-%02d', $d)];
                }
                return $rows;
            }
        };
        $p = $ref->getProperty('auditLogRepo');
        $p->setAccessible(true);
        $p->setValue($ba, $audit);

        $m = new \ReflectionMethod(BehavioralAnalyzer::class, 'analyzeTemporalPattern');
        $m->setAccessible(true);
        /** @var array{reasons: list<string>} $r */
        $r = $m->invoke($ba, self::USER);
        return $r['reasons'];
    }

    public function testNightOwlDoesNotJudgeAnUnknownTimezone(): void
    {
        self::assertNotContains('night_owl_activity', $this->temporalReasons(), 'no timezone on record → no judgement');
    }

    public function testNightOwlStillWorksWhenTheTimezoneIsKnown(): void
    {
        $GLOBALS['__fi_usermeta'][self::USER]['bcc_timezone'] = 'UTC';

        self::assertContains('night_owl_activity', $this->temporalReasons(), 'control: the check itself is intact');
    }

    // ── F-128: a throttled analysis is not "clean" ───────────────────────

    public function testAThrottledAnalysisReturnsTheLastStoredScoreNotZero(): void
    {
        $GLOBALS['__fi_cache']['add'] = false; // another request holds the throttle

        $ref = new \ReflectionClass(BehavioralAnalyzer::class);
        $ba  = $ref->newInstanceWithoutConstructor();
        $repo = new class extends UserInfoRepository {
            public function __construct() {}
            public function getByUserId(int $userId): ?object
            {
                return (object) ['behavior_score' => 64];
            }
        };
        $p = $ref->getProperty('userInfoRepo');
        $p->setAccessible(true);
        $p->setValue($ba, $repo);

        $r = $ba->analyzeUserBehavior(self::USER);

        self::assertTrue($r['cooling_down']);
        self::assertSame(64, $r['behavior_score'], 'the last stored score, not a "clean" 0');
    }
}
