<?php

declare(strict_types=1);

/**
 * Doubles for driving the REAL FraudDetector::analyzeFraud() scoring pass.
 *
 * Only the data sources are faked. The arithmetic under test — every
 * `$results['score'] += N` — is production code, and the thresholds come
 * from the real `includes/config.php` that tests/bootstrap.php loads, so a
 * threshold change cannot ship untested behind a stubbed value.
 *
 * ⚠ The consuming test class MUST run in separate processes with
 * PreserveGlobalState(false). This file declares `BCC\Trust\Core\Plugin` and
 * doubles for several repositories; in the shared suite any of ~3,800 tests
 * that touches a production class calling `Plugin::instance()` autoloads the
 * REAL Plugin first, after which these doubles are skipped and every case
 * dies. That cost PR #300 thirteen CI errors from a file that passed alone.
 *
 * ⚠ The stub list was ENUMERATED from FraudDetector.php with a token walk
 * (every non-builtin global call, every qualified AND unqualified static
 * reference, every BCC_TRUST_* constant) rather than discovered one failing
 * case at a time. The walk initially missed unqualified `ClassName::`
 * references, which is how `AuditLogger` was nearly left out — it is
 * reachable only from detectFanInCoordination(), which no case here calls.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/var/www/html/');
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    // The cache is deliberately a NO-OP READER: analyzeFraud() returns early
    // on a cache hit, so a stub that remembered values would make the second
    // case in a process assert against the first case's result.
    if (!function_exists('wp_cache_get')) {
        function wp_cache_get($key, $group = '', $force = false, &$found = null)
        {
            $found = false;
            return false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        function wp_cache_set($key, $data, $group = '', $expire = 0): bool
        {
            return true;
        }
    }
    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete($key, $group = ''): bool
        {
            return true;
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient($key)
        {
            return \BCC\Trust\Core\FraudSignalWorld::$transients[$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient($key, $value, $expire = 0): bool
        {
            \BCC\Trust\Core\FraudSignalWorld::$transients[$key] = $value;
            return true;
        }
    }
    if (!function_exists('current_time')) {
        function current_time($type = 'mysql', $gmt = 0)
        {
            return $type === 'timestamp' ? 1760000000 : '2026-10-11 12:00:00';
        }
    }
    if (!function_exists('get_userdata')) {
        function get_userdata($userId)
        {
            $reg = \BCC\Trust\Core\FraudSignalWorld::$userRegistered;
            if ($reg === null) {
                return false;
            }
            return (object) ['ID' => $userId, 'user_registered' => $reg];
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value, ...$args)
        {
            return $value;
        }
    }
}

namespace BCC\Trust\Core {

    /**
     * Every input analyzeFraud() can read, and every write it can make.
     *
     * reset() is the CLEAN BASELINE: a long-established, verified account
     * with no signal of any kind. Its asserted score is 0, which is what
     * makes a single-signal case a measurement of that signal alone rather
     * than of the baseline plus that signal.
     */
    final class FraudSignalWorld
    {
        /** @var array<string, mixed> */
        public static array $transients = [];

        // ── inputs ────────────────────────────────────────────────────
        public static int $rapidVotes = 0;
        public static ?object $voteTypeStats = null;
        public static int $mutualVotePages = 0;
        public static int $voteChanges = 0;
        public static ?string $lastIpBinary = null;
        public static int $distinctUsersForIp = 0;
        /** @var array<string, mixed> */
        public static array $deviceData = [];
        /** @var array<string, mixed> */
        public static array $behavior = [];
        public static float $calculatedTrustRank = 0.0;
        /** @var array<int, array<string, mixed>> */
        public static array $rings = [];
        public static ?object $userInfo = null;
        public static ?string $userRegistered = '2020-01-01 00:00:00';

        // ── recorded writes ───────────────────────────────────────────
        /** @var list<array{user_id: int, increment: int, reason: string}> */
        public static array $increments = [];
        /** @var list<array{user_id: int, rank: float}> */
        public static array $trustRankWrites = [];
        /** @var list<array{type: string, data: array<string, mixed>}> */
        public static array $patterns = [];
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public static array $logs = [];

        // ── inputs for the REAL TrustGraph detectors ──────────────────
        /** @var list<object> rows shaped like getMutualVotePairs() output */
        public static array $mutualVotePairs = [];
        /** @var list<object> rows shaped like getVouchEdges() output */
        public static array $vouchEdges = [];

        // ── inputs for detectFanInCoordination() ──────────────────────
        /** @var list<object> rows shaped like getRecentVotersWithRegistration() */
        public static array $recentVoters = [];
        public static int $otherPageVotes = 0;

        /** @var list<array{action: string, target_id: ?int, meta: array<string, mixed>}> */
        public static array $auditLog = [];

        public static function reset(): void
        {
            self::$logs            = [];
            self::$mutualVotePairs = [];
            self::$vouchEdges      = [];
            self::$recentVoters    = [];
            self::$otherPageVotes  = 0;
            self::$auditLog        = [];
            self::$transients          = [];
            self::$rapidVotes          = 0;
            self::$voteTypeStats       = null;
            self::$mutualVotePages     = 0;
            self::$voteChanges         = 0;
            self::$lastIpBinary        = null;
            self::$distinctUsersForIp  = 0;
            self::$deviceData          = [
                'fraud_probability' => 0.0,
                'shared_device'     => false,
            ];
            self::$behavior            = ['behavior_score' => 0, 'flags' => []];
            self::$calculatedTrustRank = 0.0;
            self::$rings               = [];
            // trust_rank 0.9 keeps the account clear of both trust bands;
            // is_verified 1 keeps `unverified_email` off; a non-null row
            // keeps `user_info_missing` off.
            self::$userInfo            = (object) [
                'user_id'     => 7,
                'trust_rank'  => 0.9,
                'is_verified' => 1,
                'fraud_score' => 0,
            ];
            self::$userRegistered      = '2020-01-01 00:00:00';
            self::$increments          = [];
            self::$trustRankWrites     = [];
            self::$patterns            = [];
        }

        /** Total points written to fraud_score by DIRECT repository calls. */
        public static function incrementedTotal(): int
        {
            $total = 0;
            foreach (self::$increments as $entry) {
                $total += $entry['increment'];
            }
            return $total;
        }
    }

    if (!class_exists('BCC\Trust\Core\Plugin', false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function userInfoRepository(): Repositories\UserInfoRepository
            {
                return new Repositories\UserInfoRepository();
            }

            public function voteRepository(): Repositories\VoteRepository
            {
                return new Repositories\VoteRepository();
            }

            public function fraudAnalysisRepository(): Repositories\FraudAnalysisRepository
            {
                return new Repositories\FraudAnalysisRepository();
            }

            public function patternRepository(): Repositories\PatternRepository
            {
                return new Repositories\PatternRepository();
            }

            public function attestationRepository(): Repositories\AttestationRepository
            {
                return new Repositories\AttestationRepository();
            }

            public function deviceFingerprinter(): Security\DeviceFingerprinterDouble
            {
                return new Security\DeviceFingerprinterDouble();
            }

            public function behavioralAnalyzer(): Security\BehavioralAnalyzerDouble
            {
                return new Security\BehavioralAnalyzerDouble();
            }

            /**
             * analyzeFraud() asks for this. Returns the DOUBLE, because that
             * suite controls ring membership directly. The suite that
             * exercises the REAL TrustGraph constructs it itself rather than
             * going through the container.
             */
            public function trustGraph(): Security\TrustGraphDouble
            {
                return new Security\TrustGraphDouble();
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {

    use BCC\Trust\Core\FraudSignalWorld as W;

    if (!class_exists('BCC\Trust\Core\Repositories\UserInfoRepository', false)) {
        class UserInfoRepository
        {
            public function getByUserId(int $userId): ?object
            {
                return W::$userInfo;
            }

            public function incrementFraudScore(int $userId, int $increment, string $reason): void
            {
                W::$increments[] = [
                    'user_id'   => $userId,
                    'increment' => $increment,
                    'reason'    => $reason,
                ];
            }

            public function updateTrustRank(int $userId, float $rank): void
            {
                W::$trustRankWrites[] = ['user_id' => $userId, 'rank' => $rank];
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Repositories\VoteRepository', false)) {
        class VoteRepository
        {
            public function getVoteTypeStats(int $userId, int $days): ?object
            {
                return W::$voteTypeStats;
            }

            public function countMutualVotePages(int $userId): int
            {
                return W::$mutualVotePages;
            }

            public function countVoteChanges(int $userId, int $minutes): int
            {
                return W::$voteChanges;
            }

            /** @return list<object> */
            public function getMutualVotePairs(int $minSize, int $pageLimit = 500, int $offset = 0): array
            {
                return W::$mutualVotePairs;
            }

            /** @return list<object> rows carrying voter_user_id + registered */
            public function getRecentVotersWithRegistration(int $pageId, int $days = 1): array
            {
                return W::$recentVoters;
            }

            /** @param list<int> $voterIds */
            public function countOtherPagesVotedByUsers(array $voterIds, int $excludePageId): int
            {
                return W::$otherPageVotes;
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Repositories\AuditLogRepository', false)) {
        class AuditLogRepository
        {
            public function countRecentVoteActions(int $userId, int $minutes): int
            {
                return W::$rapidVotes;
            }

            public function countDistinctUsersForIp(string $ipBinary, int $userId, int $hours): int
            {
                return W::$distinctUsersForIp;
            }

            public function getLastIpForUser(int $userId): ?string
            {
                return W::$lastIpBinary;
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Repositories\FraudAnalysisRepository', false)) {
        class FraudAnalysisRepository
        {
            /**
             * @param array<int, string>   $triggers
             * @param array<string, mixed> $details
             */
            public function storeAnalysis(
                int $userId,
                int $score,
                string $risk,
                float $confidence,
                array $triggers,
                array $details,
                ?string $expires = null
            ): void {
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Repositories\PatternRepository', false)) {
        class PatternRepository
        {
            /** @param array<string, mixed> $data */
            public function storePattern(
                int $userId,
                string $type,
                array $data,
                float $confidence,
                ?string $expires = null
            ): void {
                W::$patterns[] = ['type' => $type, 'data' => $data];
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Repositories\AttestationRepository', false)) {
        class AttestationRepository
        {
            /** @return list<object> */
            public function getVouchEdges(int $limit = 10000): array
            {
                return W::$vouchEdges;
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Repositories\EdgeRepository', false)) {
        class EdgeRepository
        {
        }
    }
}

namespace BCC\Trust\Core\Security {

    use BCC\Trust\Core\FraudSignalWorld as W;

    // ⚠ These three are named `*Double` ON PURPOSE, so they do NOT shadow
    // the production classes of the same role. The real TrustGraph has to
    // stay autoloadable: its own detectors are covered against the REAL
    // class in RingDetectorsRecordWithoutScoringTest, which loads this same
    // stub file. A double registered under `BCC\Trust\Core\Security\
    // TrustGraph` would have made that suite silently test the double.
    //
    // analyzeFraud() never type-checks these collaborators — it only calls
    // methods on whatever Plugin hands back — so the rename costs nothing.
    // ⚠ AuditLogger shares FraudDetector's namespace and is referenced
    // unqualified, so a double must be registered under this exact FQN.
    // detectFanInCoordination() is the only path here that reaches it.
    if (!class_exists('BCC\Trust\Core\Security\AuditLogger', false)) {
        class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): void {
                W::$auditLog[] = [
                    'action'    => $action,
                    'target_id' => $targetId,
                    'meta'      => $meta,
                ];
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Security\DeviceFingerprinterDouble', false)) {
        class DeviceFingerprinterDouble
        {
            /** @return array<string, mixed> */
            public function getAggregatedFingerprintData(int $userId): array
            {
                return W::$deviceData;
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Security\BehavioralAnalyzerDouble', false)) {
        class BehavioralAnalyzerDouble
        {
            /** @return array<string, mixed> */
            public function analyzeUserBehavior(int $userId): array
            {
                return W::$behavior;
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Security\TrustGraphDouble', false)) {
        class TrustGraphDouble
        {
            public function calculateTrustRank(int $userId, int $iterations = 10, float $damping = 0.85): float
            {
                return W::$calculatedTrustRank;
            }

            /** @return array<int, array<string, mixed>> */
            public function detectVoteRings(?int $minSize = null): array
            {
                return W::$rings;
            }
        }
    }
}

namespace BCC\Core\DB {

    if (!class_exists('BCC\Core\DB\AdvisoryLock', false)) {
        class AdvisoryLock
        {
            public static function acquire(string $name, int $timeout = 0): bool
            {
                // Granted, so detectVoteRings() takes the cursor-advancing
                // branch — the path production runs, not the fall-through.
                return true;
            }

            public static function release(string $name): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\Log {

    use BCC\Trust\Core\FraudSignalWorld as W;

    if (!class_exists('BCC\Core\Log\Logger', false)) {
        class Logger
        {
            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                W::$logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                W::$logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                W::$logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }
        }
    }
}
