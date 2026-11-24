<?php

declare(strict_types=1);

// ── Collaborator stubs at their production FQNs (guarded — first wins) ───────
namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $errors = [];

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$errors[] = $m;
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {
    use BCC\Core\Log\Logger;
    use BCC\Trust\Core\Repositories\AttestationRepository;
    use BCC\Trust\Core\Repositories\ReputationRepository;
    use BCC\Trust\Core\Repositories\ScoreRepository;
    use BCC\Trust\Core\Services\AttestationScoreSynthesis;
    use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionObject;

    /**
     * F-469: AttestationRepository::listActiveForSynthesis() fails CLOSED.
     *
     * `[]` is an answer there (no active attestations: bonus 0). It used to be
     * the answer to a FAILED read too, through get_results(), so
     * recomputeFor() wrote a zero attestation_bonus. A read that did not run
     * now throws (runRead(), which also catches wpdb's silent early return),
     * so recomputeFor() writes nothing and its callers' metric + the daily
     * decay sweep take over.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class AttestationSynthesisReadFailureTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Logger::$errors = [];
            $GLOBALS['wpdb'] = new AttestationSynthesisFakeWpdb();
        }

        public function testAnEmptyAnswerIsNoAttestationsAndABonusOfZero(): void
        {
            self::wpdb()->next = ['ok' => 0, 'error' => '', 'rows' => []];

            self::assertSame([], self::repo()->listActiveForSynthesis('validator_card', 2015));

            self::wpdb()->next = ['ok' => 0, 'error' => '', 'rows' => []];
            self::assertSame(0.0, self::synthesis(self::scoreRepo())->synthesize('validator_card', 2015));
            self::assertSame([], Logger::$errors, 'an empty answer is not a failure');
        }

        public function testRowsAreMapped(): void
        {
            self::wpdb()->next = ['ok' => 1, 'error' => '', 'rows' => [(object) [
                'attestor_user_id' => '5',
                'weight_at_time'   => '1.50',
                'created_at'       => '2026-11-01 00:00:00',
                'reaffirmed_at'    => null,
            ]]];

            self::assertEquals(
                [(object) ['attestor_user_id' => 5, 'weight_at_time' => 1.5, 'created_at' => '2026-11-01 00:00:00', 'reaffirmed_at' => null]],
                self::repo()->listActiveForSynthesis('validator_card', 2015)
            );
        }

        public function testAFailedReadThrowsAndRecomputeWritesNothing(): void
        {
            self::wpdb()->next = ['ok' => false, 'error' => 'MySQL server has gone away', 'rows' => []];
            $scores = self::scoreRepo();

            // Any Throwable is captured, so a path that wrote and then broke
            // somewhere else fails the assertions, not as an error.
            $thrown = null;
            try {
                self::synthesis($scores)->recomputeFor('validator_card', 2015);
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(RepositoryReadFailure::class, $thrown, 'a failed read is not "no attestations"');
            self::assertSame([], $scores->writes, 'no zero bonus written');
            self::assertNotSame([], Logger::$errors, 'logged');
        }

        public function testASilentFailureIsNotTheStaleRows(): void
        {
            // The previous query left a row behind; this one returns false
            // with no error and no flush (wpdb's early return).
            self::wpdb()->last_result = [(object) ['attestor_user_id' => '9', 'weight_at_time' => '1', 'created_at' => '2026-01-01 00:00:00', 'reaffirmed_at' => null]];
            self::wpdb()->next = ['ok' => false, 'error' => '', 'rows' => null];

            $this->expectException(RepositoryReadFailure::class);
            self::repo()->listActiveForSynthesis('validator_card', 2015);
        }

        // ── Builders ────────────────────────────────────────────────────

        private static function wpdb(): AttestationSynthesisFakeWpdb
        {
            /** @var AttestationSynthesisFakeWpdb $db */
            $db = $GLOBALS['wpdb'];

            return $db;
        }

        private static function repo(): AttestationRepository
        {
            return (new ReflectionClass(AttestationRepository::class))->newInstanceWithoutConstructor();
        }

        /** @return ScoreRepository&object{writes: list<string>} */
        private static function scoreRepo(): ScoreRepository
        {
            return new class extends ScoreRepository {
                /** @var list<string> */
                public array $writes = [];

                public function __construct()
                {
                }

                public function applyAttestationBonus(int $pageId, float $bonus): void
                {
                    $this->writes[] = "bonus:{$pageId}={$bonus}";
                }

                public function updateEndorsementCount(int $pageId, int $count): void
                {
                    $this->writes[] = "endorsements:{$pageId}";
                }
            };
        }

        private static function synthesis(ScoreRepository $scores): AttestationScoreSynthesis
        {
            // Every attestor is neutral: the tier read is not under test here.
            $reputation = new class extends ReputationRepository {
                public function __construct()
                {
                }

                public function getTiersForUsers(array $userIds): array
                {
                    return [];
                }

                /** @return array<int, string>|null */
                public function getTiersForUsersOrUnknown(array $userIds): ?array
                {
                    return [];
                }
            };

            $s = (new ReflectionClass(AttestationScoreSynthesis::class))->newInstanceWithoutConstructor();
            $o = new ReflectionObject($s);
            $o->getProperty('attestationRepo')->setValue($s, self::repo());
            $o->getProperty('reputationRepo')->setValue($s, $reputation);
            $o->getProperty('scoreRepo')->setValue($s, $scores);

            return $s;
        }
    }

    /**
     * Models wpdb::query(): on success it flushes and fills last_result; on a
     * normal failure it flushes and sets last_error; on the silent early-return
     * path it returns false and touches NOTHING. get_results() runs query()
     * and answers last_result, as real wpdb does.
     */
    final class AttestationSynthesisFakeWpdb
    {
        public string $prefix = 'wp_';

        public string $last_error = '';

        /** @var list<object> */
        public array $last_result = [];

        /** @var array{ok: int|false, error: string, rows: list<object>|null} */
        public array $next = ['ok' => 0, 'error' => '', 'rows' => []];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            return $sql;
        }

        /** @return int|false */
        public function query(string $sql)
        {
            if ($this->next['ok'] === false && $this->next['error'] === '') {
                return false; // early return: no flush, no error
            }
            $this->last_error  = $this->next['error'];
            $this->last_result = $this->next['rows'] ?? [];

            return $this->next['ok'];
        }

        /** @return list<object> */
        public function get_results(?string $sql = null): array
        {
            if ($sql !== null) {
                $this->query($sql);
            }

            return $this->last_result;
        }
    }
}
