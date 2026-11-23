<?php
/**
 * F-469: ReputationRepository's member-row reads, unit stubs. Loaded ONLY
 * from a #[RunTestsInSeparateProcesses] suite.
 *
 * The $wpdb double models what bcc-core's ProbesReads::trustedRows() checks:
 * query() returns the row count or false; a normal failure resets then sets
 * `last_error` (wpdb::flush()); the SILENT failure (`! $this->ready`) returns
 * false with `last_error` '' and `last_result` still holding the previous
 * query's rows.
 *
 * Rows are keyed by member id. A read by `page_id = %d` or `page_id IN (...)`
 * answers the members whose self-page ids were asked for.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Stubs {
    final class ReputationRowWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<object>|null */
        public ?array $last_result = [];
        /** @var array<int, array{tier: string, score: float}> member id => its row */
        public array $rows = [];
        public int $failNext = 0;
        public int $failSilentNext = 0;
        public int $queries = 0;
        /** @var list<int> */
        private array $args = [];

        public function prepare(string $sql, mixed ...$args): string
        {
            $this->args = array_map('intval', $args);
            return $sql;
        }

        public function query(string $sql): int|false
        {
            unset($sql);
            $this->queries++;
            if ($this->failSilentNext > 0) {
                $this->failSilentNext--;
                return false; // no flush(): last_error stays '', last_result is stale
            }
            $this->last_error = ''; // wpdb::flush()
            if ($this->failNext > 0) {
                $this->failNext--;
                $this->last_error  = 'MySQL server has gone away';
                $this->last_result = [];
                return false;
            }
            $out = [];
            foreach ($this->args as $pageId) {
                $memberId = $pageId - \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE;
                if (isset($this->rows[$memberId])) {
                    $out[] = (object) [
                        'page_owner_id'      => (string) $memberId,
                        'total_score'        => (string) $this->rows[$memberId]['score'],
                        'reputation_tier'    => $this->rows[$memberId]['tier'],
                        'contribution_bonus' => '0.00',
                        'vote_count'         => '0',
                        'last_calculated_at' => '2026-11-20 00:00:00',
                    ];
                }
            }
            $this->last_result = $out;
            return count($out);
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public static array $errors = [];

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$errors[] = [$m, $c];
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                unset($m, $c);
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                unset($m, $c);
            }
        }
    }
}
