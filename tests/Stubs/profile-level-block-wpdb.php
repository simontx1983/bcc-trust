<?php
/**
 * A recording $wpdb for ProfileLevelOnlyHiddenTest: just enough for bcc-core's
 * REAL PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown() and its
 * ProbesReads::runProbe() (query(), last_error, last_result).
 *
 *   blocks       list of [blocker, blocked] pairs the read returns
 *   fail         the query fails loudly (last_error set)
 *   failSilently query() returns false with no last_error and a STALE row
 *                left in last_result, as wpdb's pre-flush bails do
 *   queries      every statement run
 */

declare(strict_types=1);

if (!class_exists('BccProfileLevelBlockWpdb', false)) {
    final class BccProfileLevelBlockWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var mixed */
        public $last_result = [];
        /** @var list<string> */
        public array $queries = [];
        /** @var list<array{int, int}> */
        public array $blocks = [];
        public bool $fail = false;
        public bool $failSilently = false;

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $i = 0;
            return (string) preg_replace_callback('/%d/', static function () use (&$i, $args): string {
                return (string) (int) ($args[$i++] ?? 0);
            }, $sql);
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->queries[] = $sql;
            if ($this->fail) {
                $this->last_error = 'MySQL server has gone away';
                return false;
            }
            if ($this->failSilently) {
                $this->last_result = [(object) ['blk_user_id' => '1', 'blk_blocked_id' => '2']];
                return false;
            }

            $this->last_result = array_map(
                static fn (array $pair): object => (object) ['blk_user_id' => (string) $pair[0], 'blk_blocked_id' => (string) $pair[1]],
                $this->blocks
            );
            return count($this->last_result);
        }
    }
}
