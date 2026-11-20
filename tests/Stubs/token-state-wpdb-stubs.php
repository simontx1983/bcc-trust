<?php
/**
 * F-462: a `$wpdb` double for the REAL TokenStateRepository, plus the
 * bcc-core trait it uses. For TokenStateRepositoryTest and
 * TokenStatePoisonTest.
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
 * It records every SQL string in $queries.
 */

declare(strict_types=1);

namespace {
    if (!trait_exists('BCC\\Core\\Repositories\\ProbesReads', false)) {
        require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/ProbesReads.php');
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
                if ($this->mode === 'false_early') {
                    return false;
                }
                if ($this->mode === 'error') {
                    $this->last_error  = 'Lost connection to MySQL server during query';
                    $this->last_result = [];

                    return false;
                }

                // flush(), as core's query() does before running a query.
                $this->last_error = '';

                $userId = preg_match('/user_id = (\d+)/', $sql, $u) === 1 ? (int) $u[1] : -1;
                $keys   = preg_match("/meta_key IN \(([^)]*)\)/", $sql, $k) === 1
                    ? array_map(static fn(string $x): string => trim($x, " '"), explode(',', $k[1]))
                    : [];
                $limit  = preg_match('/LIMIT (\d+)/', $sql, $l) === 1 ? (int) $l[1] : PHP_INT_MAX;

                $out = [];
                foreach ($this->rows as $row) {
                    if ($row['user_id'] === $userId && in_array($row['meta_key'], $keys, true)) {
                        $out[] = $row;
                    }
                }
                usort($out, static fn(array $a, array $b): int => $a['umeta_id'] <=> $b['umeta_id']);
                $out = array_slice($out, 0, $limit);

                $this->last_result = array_map(static fn(array $r): object => (object) [
                    'meta_key'   => $r['meta_key'],
                    'meta_value' => $r['meta_value'],
                ], $out);
                if ($this->mode === 'error_rows') {
                    $this->last_error = 'Warning promoted to error';
                }

                return count($out);
            }
        }
    }
}
