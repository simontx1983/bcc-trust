<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Support;

/**
 * F-461: a `$wpdb` double for the unit worlds that reach
 * PostGroupFactsRepository::forPost(), i.e. every group gate (reports, the
 * comment routes, reactions, stoke, helpful-mark).
 *
 * It answers ONLY that repository's postmeta read, and refuses any other
 * statement loudly, so it cannot quietly stand in for a read a test did not
 * mean to allow.
 *
 * WHERE THE ROWS COME FROM. By default, from the world's own get_post_meta()
 * fixture (the `$meta` callable), so every pre-existing test keeps its
 * meaning: a post the fixture tags to a group is still that group's post.
 * `$dbRows[$postId]` overrides that with what the DATABASE holds, which is
 * how a test pins that the gates follow the database: get_post_meta() says
 * "no group" while the database says otherwise.
 *
 * Real wpdb shapes it keeps, because the repository depends on them:
 *   - query() returns the row count (0 for none), or false on failure, and
 *     fills the public `last_result`;
 *   - a failed read sets `last_error`;
 *   - a PRE-FLUSH bail returns false with NO `last_error` and leaves
 *     `last_result` holding the PREVIOUS query's rows; get_results() and
 *     get_row() then hand those stale rows back, exactly as wpdb does.
 */
final class PostGroupFactsWpdb
{
    /** The keys forPost() selects (GroupSyndicationGate::META_PRIVACY_AT_POST is the third). */
    public const KEYS = ['peepso_group_id', '_bcc_post_visibility', '_bcc_post_group_privacy_at_post'];

    public string $prefix   = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $last_error = '';

    /** @var mixed wpdb::$last_result */
    public $last_result = [];

    /** @var array<int, list<object>> the database's rows per post; wins over the meta fixture */
    public array $dbRows = [];

    /** The read fails: query() false, `last_error` set. */
    public bool $failRead = false;

    /** The read bails before flush(): query() false, no `last_error`, `last_result` untouched. */
    public bool $bailBeforeFlush = false;

    /** How many facts reads ran. */
    public int $factsReads = 0;

    /** The last statement prepared, and its arguments. */
    public string $lastSql = '';

    /** @var array<int, mixed> */
    public array $lastArgs = [];

    private int $preparedPostId = 0;

    /** @var callable(int, string): mixed */
    private $meta;

    /** @param callable(int, string): mixed $meta the world's get_post_meta($postId, $key) fixture */
    public function __construct(callable $meta)
    {
        $this->meta = $meta;
    }

    /** One row as the database returns it. */
    public static function row(string $key, string $value): object
    {
        return (object) ['meta_key' => $key, 'meta_value' => $value];
    }

    public static function isFactsRead(string $sql): bool
    {
        return str_contains($sql, 'SELECT meta_key, meta_value') && str_contains($sql, 'meta_key IN (');
    }

    /**
     * The rows the real statement returns for `$postId`, built from a meta
     * fixture: `$meta($postId, $key)` is a value or a list ('' = absent).
     *
     * @param callable(int, string): mixed $meta
     * @return list<object>
     */
    public static function rowsFromMeta(int $postId, callable $meta): array
    {
        $rows = [];
        foreach (self::KEYS as $key) {
            foreach ((array) $meta($postId, $key) as $value) {
                if ($value === '' || $value === null || $value === false) {
                    continue;
                }
                $rows[] = self::row($key, (string) $value);
            }
        }
        return $rows;
    }

    /** @param mixed ...$args */
    public function prepare(string $sql, ...$args): string
    {
        $this->preparedPostId = (int) ($args[0] ?? 0);
        $this->lastSql        = $sql;
        $this->lastArgs       = $args;
        return $sql;
    }

    public function query(string $sql): int|false
    {
        if (!self::isFactsRead($sql)) {
            throw new \LogicException('PostGroupFactsWpdb answers only the group-facts read; got: ' . $sql);
        }
        $this->factsReads++;
        if ($this->bailBeforeFlush) {
            return false;
        }
        if ($this->failRead) {
            $this->last_error  = 'MySQL server has gone away';
            $this->last_result = [];
            return false;
        }
        $this->last_error  = '';
        $this->last_result = $this->dbRows[$this->preparedPostId]
            ?? self::rowsFromMeta($this->preparedPostId, $this->meta);

        return count($this->last_result);
    }

    /**
     * Real wpdb: runs query() and hands back last_result, which after a
     * pre-flush bail is the PREVIOUS query's rows.
     *
     * @return list<object>
     */
    public function get_results(?string $sql = null, string $output = 'OBJECT'): array
    {
        if ($sql !== null) {
            $this->query($sql);
        }
        return is_array($this->last_result) ? array_values($this->last_result) : [];
    }

    public function get_row(?string $sql = null, string $output = 'OBJECT', int $y = 0): ?object
    {
        $rows = $this->get_results($sql);
        return $rows[$y] ?? null;
    }
}
