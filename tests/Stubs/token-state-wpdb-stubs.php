<?php
/**
 * F-462: a `$wpdb` double for the REAL TokenStateRepository, plus the
 * bcc-core trait it uses. For TokenStateRepositoryTest and
 * TokenStatePoisonTest, and (H6) for the same read generalised in
 * bcc-core's UserMetaRepository (loaded here, the REAL class):
 * PeepSoChatPrefsTest, ChatPrefsReadFailClosedTest, ChatPrefsQueuePathTest
 * and MessagesPrefsReadFailClosedTest.
 *
 * Load this BEFORE any stub file that brings in token-state-stubs.php (the
 * stand-in): it loads the real repository, so the stand-in's
 * class_exists(…, false) guard steps aside.
 *
 * TokenStateWpdb holds usermeta rows and answers the repository's one
 * SELECT the way wpdb::query() does in each of its outcomes ($mode):
 *   - 'ok'           rows in last_result, last_error '', returns the count
 *   - 'false_early'  returns false and touches NOTHING: no flush(), so
 *                    last_result keeps the PREVIOUS query's rows and
 *                    last_error stays as it was (core's `! $this->ready`
 *                    and empty-`query`-filter paths)
 *   - 'error'        last_error set, last_result emptied, returns false
 *   - 'error_rows'   rows AND last_error, returns the count (an error the
 *                    return value doesn't show)
 * $modes overrides $mode for single queries, by 0-based position (H6: a
 * batch whose SECOND chunk fails).
 * It records every SQL string in $queries.
 *
 * The SELECT may name one user (`user_id = N`) or several
 * (`user_id IN (a, b)`), and each row carries user_id, meta_key and
 * meta_value. get_results() is core's: query(), then last_result whatever
 * query() returned. Nothing calls it; it is there so a mutation control
 * can swap trustedRows() for it and meet the pre-flush trap.
 */

declare(strict_types=1);

namespace {
    if (!trait_exists('BCC\\Core\\Repositories\\ProbesReads', false)) {
        require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/ProbesReads.php');
    }
    // H6: bcc-core's guarded user-meta reader, the REAL one, over this
    // double. Required before anything asks for the class, so no test
    // double or autoloader mapping can stand in for it.
    if (!class_exists('BCC\\Core\\Repositories\\UserMetaRepository', false)) {
        // bcc-core #164 moved the read itself into the ReadsMetaRows trait
        // (shared with PostMetaRepository). Load it first when this core
        // has it; an older core keeps the read in the class.
        $readsMetaRows = \BCC\Trust\Tests\Support\CoreSibling::root() . '/src/Repositories/ReadsMetaRows.php';
        if (!trait_exists('BCC\\Core\\Repositories\\ReadsMetaRows', false) && is_file($readsMetaRows)) {
            require_once $readsMetaRows;
        }
        require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/UserMetaRepository.php');
    }
    // The real class, now, so the stand-in in token-state-stubs.php is skipped.
    class_exists(\BCC\Trust\Core\Repositories\TokenStateRepository::class);

    if (!function_exists('maybe_unserialize')) {
        /**
         * As core: unserialise a serialised string, anything else as given.
         *
         * @param mixed $data
         * @return mixed
         */
        function maybe_unserialize($data)
        {
            if (!is_string($data)) {
                return $data;
            }
            $trimmed = trim($data);
            if ($trimmed === 'b:0;') {
                return false;
            }
            $value = @unserialize($trimmed, ['allowed_classes' => false]);

            return $value === false ? $data : $value;
        }
    }

    if (!class_exists('TokenStateWpdb', false)) {
        final class TokenStateWpdb
        {
            public string $usermeta = 'wp_usermeta';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];
            public string $mode = 'ok';
            /** @var array<int, string> per-query mode, by 0-based position */
            public array $modes = [];
            /** @var list<string> */
            public array $queries = [];
            /** @var list<array{umeta_id: int, user_id: int, meta_key: string, meta_value: string}> */
            public array $rows = [];

            /** Store a value as WordPress would (serialised when not scalar). */
            public function put(int $userId, string $key, mixed $value): void
            {
                $this->rows[] = [
                    'umeta_id'   => count($this->rows) + 1,
                    'user_id'    => $userId,
                    'meta_key'   => $key,
                    'meta_value' => is_array($value) || is_object($value) ? serialize($value) : (string) $value,
                ];
            }

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                $i = 0;

                return (string) preg_replace_callback('/%[ds]/', static function (array $m) use (&$i, $args): string {
                    $v = $args[$i++] ?? '';

                    return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
                }, $query);
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $this->queries[] = $sql;
                $mode = $this->modes[count($this->queries) - 1] ?? $this->mode;
                if ($mode === 'false_early') {
                    return false;
                }
                if ($mode === 'error') {
                    $this->last_error  = 'Lost connection to MySQL server during query';
                    $this->last_result = [];

                    return false;
                }

                // flush(), as core's query() does before running a query.
                $this->last_error = '';

                $userIds = preg_match('/user_id = (\d+)/', $sql, $u) === 1
                    ? [(int) $u[1]]
                    : (preg_match('/user_id IN \(([^)]*)\)/', $sql, $u) === 1
                        ? array_map('intval', explode(',', $u[1]))
                        : []);
                $keys   = preg_match("/meta_key IN \(([^)]*)\)/", $sql, $k) === 1
                    ? array_map(static fn(string $x): string => trim($x, " '"), explode(',', $k[1]))
                    : [];
                $limit  = preg_match('/LIMIT (\d+)/', $sql, $l) === 1 ? (int) $l[1] : PHP_INT_MAX;

                $out = [];
                foreach ($this->rows as $row) {
                    if (in_array($row['user_id'], $userIds, true) && in_array($row['meta_key'], $keys, true)) {
                        $out[] = $row;
                    }
                }
                usort($out, static fn(array $a, array $b): int => $a['umeta_id'] <=> $b['umeta_id']);
                $out = array_slice($out, 0, $limit);

                $this->last_result = array_map(static fn(array $r): object => (object) [
                    'user_id'    => (string) $r['user_id'],
                    'meta_key'   => $r['meta_key'],
                    'meta_value' => $r['meta_value'],
                ], $out);
                if ($mode === 'error_rows') {
                    $this->last_error = 'Warning promoted to error';
                }

                return count($out);
            }

            /**
             * As core: run the query and hand back last_result, ignoring
             * what query() returned (H6 mutation controls only).
             *
             * @return mixed
             */
            public function get_results(string $sql)
            {
                $this->query($sql);

                return $this->last_result;
            }
        }
    }
}
