<?php

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The administrators who receive "every admin" notices (validator
 * relationship changes, relationship-log health alerts). Bounded, ids only,
 * lowest id first.
 *
 * @package BCC\Trust\Core\Support
 */
final class AdminRecipients
{
    /** Most administrators one notice fans out to. */
    public const MAX = 50;

    /** @return list<int> */
    public static function ids(): array
    {
        $ids = get_users([
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => self::MAX,
            'fields'  => 'ID',
        ]);

        return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn (int $id): bool => $id > 0));
    }
}
