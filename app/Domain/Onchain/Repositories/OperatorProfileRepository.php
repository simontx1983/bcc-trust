<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores one shared operator profile per WordPress user plus explicit
 * per-validator-page overrides. Authorization is never inferred from
 * the denormalized user_id in the overrides table; write callers must
 * re-resolve the verified operator claim immediately before writing.
 *
 * The tables are deliberately uncached. Profile writes therefore cannot
 * leave a stale public card or an authorization decision in object cache.
 */
final class OperatorProfileRepository
{
    /** @var list<string> */
    public const PROFILE_FIELDS = [
        'brand_name',
        'bio',
        'website_url',
        'x_url',
        'discord_url',
        'telegram_url',
    ];

    /** @var list<string> */
    private const PROFILE_COLUMNS = [
        'user_id',
        'brand_name',
        'bio',
        'website_url',
        'x_url',
        'discord_url',
        'telegram_url',
        'logo_attachment_id',
        'updated_at',
    ];

    /** @var list<string> */
    private const OVERRIDE_COLUMNS = [
        'page_id',
        'user_id',
        'brand_name',
        'bio',
        'website_url',
        'x_url',
        'discord_url',
        'telegram_url',
    ];

    /**
     * @return array{
     *   user_id: int,
     *   brand_name: string,
     *   bio: string,
     *   website_url: string,
     *   x_url: string,
     *   discord_url: string,
     *   telegram_url: string,
     *   logo_attachment_id: int|null,
     *   updated_at: string
     * }|null
     */
    public function findProfileForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;
        $table = TableRegistry::operatorProfiles();
        if (!TableRegistry::exists($table)) {
            return null;
        }
        $cols  = implode(', ', self::PROFILE_COLUMNS);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$cols} FROM {$table} WHERE user_id = %d LIMIT 1",
            $userId
        ));

        return $row === null ? null : self::shapeProfile($row);
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, array<string, string|null>> keyed by page id
     */
    public function findOverridesForUser(int $userId, array $pageIds): array
    {
        if ($userId <= 0) {
            return [];
        }

        $ids = self::positiveIds($pageIds, 100);
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table        = TableRegistry::operatorProfileOverrides();
        if (!TableRegistry::exists($table)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $columns      = implode(', ', self::OVERRIDE_COLUMNS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$columns}
               FROM {$table}
              WHERE user_id = %d AND page_id IN ({$placeholders})
              ORDER BY page_id ASC
              LIMIT %d",
            ...array_merge([$userId], $ids, [count($ids)])
        ));

        $byPage = [];
        foreach ($rows ?: [] as $row) {
            $pageId = (int) ($row->page_id ?? 0);
            if ($pageId > 0 && (int) ($row->user_id ?? 0) === $userId) {
                $byPage[$pageId] = self::shapeOverride($row);
            }
        }

        return $byPage;
    }

    /**
     * Resolve public profile data for pages whose single verified operator
     * has already been established by ClaimRepository. Ambiguous and
     * unclaimed pages are omitted. The page/user pairs are bounded by the
     * page-card caller (at most 50) and rechecked in the SQL predicates.
     *
     * @param array<int, array{state: string, user_id: int|null}> $operators
     * @return array<int, array{
     *   brand_name: string,
     *   bio: string,
     *   website_url: string,
     *   x_url: string,
     *   discord_url: string,
     *   telegram_url: string,
     *   logo_attachment_id: int|null
     * }> keyed by page id
     */
    public function findPublicForResolvedPages(array $operators): array
    {
        $pairs = [];
        $userIds = [];
        foreach ($operators as $rawPageId => $operator) {
            $pageId = (int) $rawPageId;
            $userId = $operator['user_id'] ?? null;
            if ($pageId <= 0
                || ($operator['state'] ?? '') !== ClaimRepository::OPERATOR_RESOLVED
                || !is_int($userId)
                || $userId <= 0
            ) {
                continue;
            }
            $pairs[$pageId] = $userId;
            $userIds[$userId] = true;
        }
        if ($pairs === []) {
            return [];
        }

        global $wpdb;
        $profilesTable  = TableRegistry::operatorProfiles();
        $overridesTable = TableRegistry::operatorProfileOverrides();

        $profiles = [];
        if (TableRegistry::exists($profilesTable)) {
            $profileIds  = array_keys($userIds);
            $profilePh   = implode(',', array_fill(0, count($profileIds), '%d'));
            $profileCols = implode(', ', self::PROFILE_COLUMNS);
            $profileRows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$profileCols}
                   FROM {$profilesTable}
                  WHERE user_id IN ({$profilePh})
                  LIMIT %d",
                ...array_merge($profileIds, [count($profileIds)])
            ));

            foreach ($profileRows ?: [] as $row) {
                $profile = self::shapeProfile($row);
                $profiles[$profile['user_id']] = $profile;
            }
        }

        $overrides = [];
        if (TableRegistry::exists($overridesTable)) {
            $conditions = [];
            $arguments  = [];
            foreach ($pairs as $pageId => $userId) {
                $conditions[] = '(page_id = %d AND user_id = %d)';
                $arguments[]  = $pageId;
                $arguments[]  = $userId;
            }
            $overrideCols = implode(', ', self::OVERRIDE_COLUMNS);
            $overrideRows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$overrideCols}
                   FROM {$overridesTable}
                  WHERE " . implode(' OR ', $conditions) . '
                  ORDER BY page_id ASC
                  LIMIT %d',
                ...array_merge($arguments, [count($pairs)])
            ));

            foreach ($overrideRows ?: [] as $row) {
                $pageId = (int) ($row->page_id ?? 0);
                $userId = (int) ($row->user_id ?? 0);
                if ($pageId > 0 && ($pairs[$pageId] ?? 0) === $userId) {
                    $overrides[$pageId] = self::shapeOverride($row);
                }
            }
        }

        $resolved = [];
        foreach ($pairs as $pageId => $userId) {
            $profile = $profiles[$userId] ?? null;
            $override = $overrides[$pageId] ?? null;
            if ($profile === null && $override === null) {
                continue;
            }

            $resolved[$pageId] = [
                'brand_name'         => self::pick($override, $profile, 'brand_name'),
                'bio'                => self::pick($override, $profile, 'bio'),
                'website_url'        => self::pick($override, $profile, 'website_url'),
                'x_url'              => self::pick($override, $profile, 'x_url'),
                'discord_url'        => self::pick($override, $profile, 'discord_url'),
                'telegram_url'       => self::pick($override, $profile, 'telegram_url'),
                // No per-page logo override exists (the overrides table has
                // no logo column), so branding art is always the shared one.
                'logo_attachment_id' => $profile['logo_attachment_id'] ?? null,
            ];
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $fields validated field names and values
     */
    public function updateProfile(int $userId, array $fields): bool
    {
        if ($userId <= 0 || $fields === []) {
            return false;
        }

        $fields = self::allowedTextFields($fields);
        if ($fields === []) {
            return false;
        }

        global $wpdb;
        $table   = TableRegistry::operatorProfiles();
        if (!TableRegistry::exists($table)) {
            return false;
        }
        $columns = ['user_id'];
        $values  = ['%d'];
        $args    = [$userId];
        $updates = [];

        foreach (self::PROFILE_FIELDS as $field) {
            $columns[] = $field;
            $values[]  = '%s';
            $args[]    = $fields[$field] ?? '';
            if (array_key_exists($field, $fields)) {
                $updates[] = "{$field} = VALUES({$field})";
            }
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[]  = 'UTC_TIMESTAMP()';
        $values[]  = 'UTC_TIMESTAMP()';
        $updates[] = 'updated_at = UTC_TIMESTAMP()';

        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            ...$args
        ));

        return $result !== false;
    }

    /**
     * @param array<string, string|null> $fields null means inherit shared value
     */
    public function updatePageOverrides(int $userId, int $pageId, array $fields): bool
    {
        if ($userId <= 0 || $pageId <= 0 || $fields === []) {
            return false;
        }

        $fields = self::allowedOverrideFields($fields);
        if ($fields === []) {
            return false;
        }

        global $wpdb;
        $table   = TableRegistry::operatorProfileOverrides();
        if (!TableRegistry::exists($table)) {
            return false;
        }
        $columns = ['page_id', 'user_id'];
        $values  = ['%d', '%d'];
        $args    = [$pageId, $userId];
        $updates = [];

        foreach (self::PROFILE_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                // If a page's verified operator changed, the new owner's
                // first write must not inherit the former operator's
                // private override values. user_id is updated last below,
                // so this comparison still sees the previous owner.
                $updates[] = "{$field} = IF(user_id = VALUES(user_id), {$field}, NULL)";
                continue;
            }

            $value = $fields[$field];
            $columns[] = $field;
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                $values[] = '%s';
                $args[]   = $value;
            }
            $updates[] = "{$field} = VALUES({$field})";
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[]  = 'UTC_TIMESTAMP()';
        $values[]  = 'UTC_TIMESTAMP()';
        $updates[] = 'updated_at = UTC_TIMESTAMP()';
        $updates[] = 'user_id = VALUES(user_id)';

        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            ...$args
        ));

        return $result !== false;
    }

    public function setLogoAttachment(int $userId, ?int $attachmentId): bool
    {
        if ($userId <= 0 || ($attachmentId !== null && $attachmentId <= 0)) {
            return false;
        }

        global $wpdb;
        $table = TableRegistry::operatorProfiles();
        if (!TableRegistry::exists($table)) {
            return false;
        }
        $columns = ['user_id'];
        $values  = ['%d'];
        $args    = [$userId];
        foreach (self::PROFILE_FIELDS as $field) {
            $columns[] = $field;
            $values[]  = '%s';
            $args[]    = '';
        }
        $columns[] = 'logo_attachment_id';
        $values[]  = $attachmentId === null ? 'NULL' : '%d';
        if ($attachmentId !== null) {
            $args[] = $attachmentId;
        }
        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[]  = 'UTC_TIMESTAMP()';
        $values[]  = 'UTC_TIMESTAMP()';

        if ($attachmentId === null) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                    SET logo_attachment_id = NULL, updated_at = UTC_TIMESTAMP()
                  WHERE user_id = %d",
                $userId
            ));
            return $result !== false;
        }

        $updates = 'logo_attachment_id = VALUES(logo_attachment_id), updated_at = UTC_TIMESTAMP()';

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (" . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')
             ON DUPLICATE KEY UPDATE ' . $updates,
            ...$args
        );

        return $wpdb->query($sql) !== false;
    }

    public function deletePageOverrides(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        global $wpdb;
        $table = TableRegistry::operatorProfileOverrides();
        if (!TableRegistry::exists($table)) {
            return false;
        }
        return $wpdb->delete(
            $table,
            ['page_id' => $pageId],
            ['%d']
        ) !== false;
    }

    /** @return array{user_id: int, brand_name: string, bio: string, website_url: string, x_url: string, discord_url: string, telegram_url: string, logo_attachment_id: int|null, updated_at: string} */
    /**
     * Per-page override wins when set; a null override inherits the shared
     * value; absent shared value is the empty string.
     *
     * @param array<string, string|null>|null $override
     * @param array<string, mixed>|null       $profile
     */
    private static function pick(?array $override, ?array $profile, string $field): string
    {
        $overrideValue = $override[$field] ?? null;
        if ($overrideValue !== null) {
            return $overrideValue;
        }

        $shared = $profile[$field] ?? '';

        return is_string($shared) ? $shared : '';
    }

    /**
     * @return array{
     *     user_id: int,
     *     brand_name: string,
     *     bio: string,
     *     website_url: string,
     *     x_url: string,
     *     discord_url: string,
     *     telegram_url: string,
     *     logo_attachment_id: int|null,
     *     updated_at: string
     * }
     */
    private static function shapeProfile(object $row): array
    {
        $attachmentId = isset($row->logo_attachment_id) && (int) $row->logo_attachment_id > 0
            ? (int) $row->logo_attachment_id
            : null;

        return [
            'user_id'            => (int) ($row->user_id ?? 0),
            'brand_name'         => (string) ($row->brand_name ?? ''),
            'bio'                => (string) ($row->bio ?? ''),
            'website_url'        => (string) ($row->website_url ?? ''),
            'x_url'              => (string) ($row->x_url ?? ''),
            'discord_url'        => (string) ($row->discord_url ?? ''),
            'telegram_url'       => (string) ($row->telegram_url ?? ''),
            'logo_attachment_id' => $attachmentId,
            'updated_at'         => (string) ($row->updated_at ?? ''),
        ];
    }

    /** @return array<string, string|null> */
    /**
     * Null means "inherit the shared value", never "blank".
     *
     * @return array<string, string|null>
     */
    private static function shapeOverride(object $row): array
    {
        $override = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $raw = $row->{$field} ?? null;
            $override[$field] = $raw === null ? null : (string) $raw;
        }
        return $override;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private static function allowedTextFields(array $fields): array
    {
        $safe = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $safe[$field] = $fields[$field];
            }
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string|null>
     */
    private static function allowedOverrideFields(array $fields): array
    {
        $safe = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            if ($fields[$field] === null || is_string($fields[$field])) {
                $safe[$field] = $fields[$field];
            }
        }
        return $safe;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function positiveIds(array $ids, int $limit): array
    {
        $unique = [];
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $unique[$id] = true;
            }
        }
        return array_slice(array_keys($unique), 0, $limit);
    }
}
