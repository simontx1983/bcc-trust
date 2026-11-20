<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Support;

/**
 * A `$wpdb` double for unit worlds that reach bcc-core's REAL
 * UserMetaRepository (loaded from the sibling checkout with
 * {@see self::loadCore()}). It answers only that repository's usermeta read,
 * from `$meta` (user id => meta key => every stored value, lowest umeta_id
 * first), and refuses any other statement loudly.
 *
 * Real wpdb shapes the repository depends on:
 *   - query() returns the row count (0 for none) or false, and fills the
 *     public `last_result`;
 *   - a failed read sets `last_error`;
 *   - a PRE-FLUSH bail returns false with NO `last_error` and leaves
 *     `last_result` holding the PREVIOUS query's rows.
 *
 * `last_error` stays a plain public property, so a world that simulates its
 * own queries (setting `$wpdb->last_error` itself) keeps working.
 */
final class UserMetaWpdb
{
    public string $prefix   = 'wp_';
    public string $usermeta = 'wp_usermeta';
    public string $last_error = '';

    /** @var mixed wpdb::$last_result */
    public $last_result = [];

    /** @var array<int, array<string, list<string>>> */
    public array $meta = [];

    public bool $failRead = false;

    public bool $bailBeforeFlush = false;

    /** How many usermeta reads ran. */
    public int $reads = 0;

    /** @var array<int, mixed> */
    private array $args = [];

    /** bcc-core's reader and the two traits it is built from. */
    public static function loadCore(): void
    {
        require_once CoreSibling::require('src/Repositories/ProbesReads.php');
        require_once CoreSibling::require('src/Repositories/ReadsMetaRows.php');
        require_once CoreSibling::require('src/Repositories/UserMetaRepository.php');
    }

    /** @param mixed ...$args */
    public function prepare(string $sql, ...$args): string
    {
        $this->args = $args;
        return $sql;
    }

    public function query(string $sql): int|false
    {
        if (!str_contains($sql, 'SELECT user_id, meta_key, meta_value') || !str_contains($sql, 'FROM wp_usermeta')) {
            throw new \LogicException('UserMetaWpdb answers only the user-meta read; got: ' . $sql);
        }
        $this->reads++;
        if ($this->bailBeforeFlush) {
            return false;
        }
        if ($this->failRead) {
            $this->last_error  = 'Lost connection to MySQL server during query';
            $this->last_result = [];
            return false;
        }

        // The statement binds every user id (ints), then every key
        // (strings), then the LIMIT (the last int).
        $args = $this->args;
        array_pop($args);
        $ids  = array_values(array_filter($args, 'is_int'));
        $keys = array_values(array_filter($args, 'is_string'));

        $rows = [];
        foreach ($ids as $id) {
            foreach ($keys as $key) {
                foreach ($this->meta[$id][$key] ?? [] as $value) {
                    $rows[] = (object) ['user_id' => (string) $id, 'meta_key' => $key, 'meta_value' => $value];
                }
            }
        }
        $this->last_error  = '';
        $this->last_result = $rows;

        return count($rows);
    }
}
