<?php
/**
 * A `$wpdb` that answers bcc-core's page-privacy reads for MANY pages, for
 * the tests of lists that show pages to one viewer (PageAccess::visibleAmong
 * and its callers: the watchlist, the watchers list, feed page labels).
 *
 * ⚠ Required from a test's setUp(), i.e. inside its separate process —
 * NEVER at file level. File-level code in a test class file runs in the
 * PARENT process during discovery, so a stub defined there becomes THE
 * class for the whole unit run.
 *
 * The REAL bcc-core PeepSoPagePrivacy is loaded (bcc_spl_load_core()), so the
 * SQL routed here is the SQL production sends. Fixture, all optional:
 *
 *   $GLOBALS['__bcc_spl'] = [
 *     'secret'       => [pageId => true],            // a privacy row reads 2
 *     'statuses'     => [pageId => [userId => list<string>]],
 *     'fail_batch'   => bool,  // PageDiscoveryRepository::findSecretOrUnknownIds
 *     'fail_single'  => bool,  // PeepSoPagePrivacy::isSecretOrUnknown
 *     'posts'        => [pageId => WP_Post],         // the global get_post()
 *   ];
 *
 * Every statement is recorded in `$queries`, with its values filled in. Any
 * statement that is not a privacy read goes to `$other` when a test sets it,
 * and otherwise finds nothing.
 */

declare(strict_types=1);

namespace {
    use BCC\Trust\Tests\Support\CoreSibling;

    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_name = '';
            public string $post_title = '';
            public int $post_author = 0;
        }
    }

    // PageAccess::viewerMaySeeId() looks the page up for its author.
    if (!function_exists('get_post')) {
        function get_post($id = null)
        {
            return $GLOBALS['__bcc_spl']['posts'][(int) $id] ?? null;
        }
    }

    if (!function_exists('bcc_spl_load_core')) {
        /** The real bcc-core rule. A missing sibling fails in CI and skips locally. */
        function bcc_spl_load_core(): void
        {
            require_once CoreSibling::require('src/Repositories/ProbesReads.php');
            require_once CoreSibling::require('src/Repositories/PeepSoPagePrivacy.php');
        }
    }

    if (!function_exists('bcc_spl_post')) {
        function bcc_spl_post(int $id, int $author = 0, string $type = 'peepso-page', string $status = 'publish'): WP_Post
        {
            $post              = new WP_Post();
            $post->ID          = $id;
            $post->post_type   = $type;
            $post->post_status = $status;
            $post->post_author = $author;
            $post->post_name   = 'page-' . $id;
            $post->post_title  = 'Page ' . $id;

            return $post;
        }
    }

    if (!class_exists('BccSecretPageListWpdb', false)) {
        final class BccSecretPageListWpdb
        {
            public string $prefix     = 'wp_';
            public string $posts      = 'wp_posts';
            public string $postmeta   = 'wp_postmeta';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];

            /** @var list<string> */
            public array $queries = [];

            /**
             * For every non-privacy statement: rows to answer with.
             *
             * @var (\Closure(string): list<object>)|null
             */
            public ?\Closure $other = null;

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }
                $sql = str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], $sql);

                return vsprintf($sql, $args);
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $this->queries[]  = $sql;
                $this->last_error = '';
                $fx               = $GLOBALS['__bcc_spl'] ?? [];

                if (self::isBatch($sql)) {
                    if (!empty($fx['fail_batch'])) {
                        $this->last_error = 'MySQL server has gone away';
                        return false;
                    }
                    $rows = [];
                    foreach (self::inList($sql) as $id) {
                        if (!empty($fx['secret'][$id])) {
                            $rows[] = (object) ['ID' => (string) $id];
                        }
                    }
                    $this->last_result = $rows;
                } elseif (preg_match('/pm_page_id = (\d+)\s+AND pm_user_id = (\d+)/', $sql, $m) === 1) {
                    $statuses          = $fx['statuses'][(int) $m[1]][(int) $m[2]] ?? [];
                    $this->last_result = array_map(
                        static fn (string $s): object => (object) ['pm_user_status' => $s],
                        $statuses
                    );
                } elseif (preg_match('/bcc_page_priv\.post_id = (\d+)/', $sql, $m) === 1) {
                    if (!empty($fx['fail_single'])) {
                        $this->last_error = 'MySQL server has gone away';
                        return false;
                    }
                    $this->last_result = !empty($fx['secret'][(int) $m[1]]) ? [(object) ['1' => '1']] : [];
                } else {
                    $this->last_result = $this->other !== null ? ($this->other)($sql) : [];
                }

                return count($this->last_result);
            }

            /** @return list<object> */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                $this->query($sql);

                return is_array($this->last_result) ? $this->last_result : [];
            }

            public function get_var(?string $sql = null): ?string
            {
                if ($sql !== null) {
                    $this->query($sql);
                }
                $row = is_array($this->last_result) ? ($this->last_result[0] ?? null) : null;
                if (!is_object($row)) {
                    return null;
                }
                $values = array_values(get_object_vars($row));

                return isset($values[0]) ? (string) $values[0] : null;
            }

            /** @return list<string> */
            public function get_col(?string $sql = null): array
            {
                if ($sql !== null) {
                    $this->query($sql);
                }
                $out = [];
                foreach (is_array($this->last_result) ? $this->last_result : [] as $row) {
                    $values = array_values(get_object_vars($row));
                    $out[]  = (string) ($values[0] ?? '');
                }

                return $out;
            }

            /** The batched read: the shared rule, negated, over an IN-list of posts. */
            public static function isBatch(string $sql): bool
            {
                return str_contains($sql, 'NOT (NOT EXISTS') && str_contains($sql, 'p.ID IN (');
            }

            /** @return list<int> */
            public static function inList(string $sql): array
            {
                if (preg_match('/p\.ID IN \(([\d,\s]+)\)/', $sql, $m) !== 1) {
                    return [];
                }

                return array_map('intval', array_map('trim', explode(',', $m[1])));
            }

            /** @return list<string> statements of one kind: batch | single | member */
            public function ofKind(string $kind): array
            {
                return array_values(array_filter($this->queries, static function (string $sql) use ($kind): bool {
                    $batch  = self::isBatch($sql);
                    $member = str_contains($sql, 'peepso_page_members');
                    return match ($kind) {
                        'batch'  => $batch,
                        'member' => $member,
                        'single' => !$batch && !$member && str_contains($sql, 'bcc_page_priv.post_id = '),
                        default  => false,
                    };
                }));
            }
        }
    }
}
