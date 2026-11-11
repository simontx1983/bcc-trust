<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security\Tests;

use BCC\Trust\Core\Repositories\AuditLogRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\BehavioralAnalyzer;
use BCC\Trust\Core\Security\DeviceFingerprinter;
use BCC\Trust\Core\Security\DeviceSharingPenaltyLedger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-103 + F-128 — fraud-score inputs that penalised ordinary members.
 *
 * F-103: the shared-device penalty added to fraud_score on EVERY vote
 * (two accounts behind one laptop were blocked from voting after ~3 votes)
 * and ignored the admin whitelists. Now: once per (reason, device) per
 * window, capped in total, never for a whitelisted device or IP.
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

    // ── F-103: the ledger ────────────────────────────────────────────────

    public function testTheSameDevicePenalisesOncePerWindow(): void
    {
        $t = 1_800_000_000;
        self::assertSame(10, DeviceSharingPenaltyLedger::allowance(self::USER, 'device_sharing', 'fpA', 10, $t));
        DeviceSharingPenaltyLedger::record(self::USER, 'device_sharing', 'fpA', 10, $t);

        self::assertSame(0, DeviceSharingPenaltyLedger::allowance(self::USER, 'device_sharing', 'fpA', 10, $t + 3600), 'same device, same window: nothing more');

        $afterWindow = $t + (BCC_TRUST_DEVICE_SHARING_WINDOW_DAYS * 86400) + 1;
        self::assertSame(10, DeviceSharingPenaltyLedger::allowance(self::USER, 'device_sharing', 'fpA', 10, $afterWindow), 'a new window may penalise again');
    }

    public function testAllSharedDeviceSignalsTogetherAreCapped(): void
    {
        $t = 1_800_000_000;
        DeviceSharingPenaltyLedger::record(self::USER, 'device_sharing', 'fpA', 10, $t);

        self::assertSame(
            BCC_TRUST_DEVICE_SHARING_MAX_POINTS - 10,
            DeviceSharingPenaltyLedger::allowance(self::USER, 'server_fingerprint_sharing', 'srv_x', 30, $t),
            'a second device only gets what is left under the cap'
        );
    }

    // ── F-103: the fingerprinter's penalty path ──────────────────────────

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
        $p = $ref->getProperty('userInfoRepo');
        $p->setAccessible(true);
        $p->setValue($fp, $repo);
        return $fp;
    }

    private function penalise(DeviceFingerprinter $fp, string $reason, string $device, int $requested): int
    {
        $m = new \ReflectionMethod(DeviceFingerprinter::class, 'applySharingPenalty');
        $m->setAccessible(true);
        return (int) $m->invoke($fp, self::USER, $reason, $device, $requested);
    }

    public function testTenVotesFromASharedDeviceAddOnePenaltyNotTen(): void
    {
        $fp = $this->fingerprinter();
        for ($vote = 0; $vote < 10; $vote++) {
            $this->penalise($fp, 'device_sharing', 'family-laptop', 10);
        }

        self::assertSame([[self::USER, 10, 'device_sharing']], self::$increments, 'once per window, not per vote');
    }

    public function testTheCapHoldsAcrossBothSignals(): void
    {
        $fp = $this->fingerprinter();
        $this->penalise($fp, 'device_sharing', 'family-laptop', 20);
        $applied = $this->penalise($fp, 'server_fingerprint_sharing', 'srv_household', 30);

        self::assertSame(0, $applied, 'the cap is already reached');
        self::assertSame([[self::USER, 20, 'device_sharing']], self::$increments);
    }

    public function testAWhitelistedDeviceIsNeverPenalised(): void
    {
        $GLOBALS['__fi_options']['bcc_trust_whitelist_fingerprint'] = ['office-pc' => ['note' => 'front desk']];

        self::assertSame(0, $this->penalise($this->fingerprinter(), 'device_sharing', 'office-pc', 20));
        self::assertSame([], self::$increments);
    }

    public function testAWhitelistedIpIsNeverPenalised(): void
    {
        $GLOBALS['__fi_options']['bcc_trust_whitelist_ip'] = ['203.0.113.9' => ['note' => 'office NAT']];

        self::assertSame(0, $this->penalise($this->fingerprinter(), 'server_fingerprint_sharing', 'srv_any', 30));
        self::assertSame([], self::$increments);
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
