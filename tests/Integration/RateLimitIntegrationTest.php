<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\RateLimitRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The rate limiter is option-backed: incrementBucket() does an atomic
 * INSERT … ON DUPLICATE KEY UPDATE on wp_options with a LAST_INSERT_ID trick
 * to return the new count in one round-trip. Two invariants matter against a
 * real MySQL: (1) the count increments correctly and is independent per key,
 * and (2) it FAILS CLOSED — a DB error returns null (deny) rather than silently
 * allowing the request.
 */
#[Group('integration')]
#[CoversClass(RateLimitRepository::class)]
final class RateLimitIntegrationTest extends TestCase
{
    private const OPTIONS_DDL =
        "CREATE TABLE `wp_options` (
            option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            option_name VARCHAR(191) NOT NULL DEFAULT '',
            option_value LONGTEXT NOT NULL,
            autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
            PRIMARY KEY (option_id),
            UNIQUE KEY option_name (option_name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `wp_options`');
    }

    public function testIncrementBucketCountsAtomicallyPerKey(): void
    {
        $key   = 'bcc_rl_' . uniqid('', true);
        $fresh = '1|' . (time() + 3600);

        self::assertSame(1, RateLimitRepository::incrementBucket($key, $fresh), 'first hit');
        self::assertSame(2, RateLimitRepository::incrementBucket($key, $fresh), 'second hit');
        self::assertSame(3, RateLimitRepository::incrementBucket($key, $fresh), 'third hit');

        self::assertSame(3, RateLimitRepository::getBucketCount($key), 'count read-back matches');

        // A different key has its own independent counter.
        $other = 'bcc_rl_' . uniqid('', true);
        self::assertSame(1, RateLimitRepository::incrementBucket($other, $fresh));
    }

    public function testFailsClosedOnDbError(): void
    {
        // Force a DB error (table gone) → must return null (deny), never silently allow.
        $GLOBALS['wpdb']->query('DROP TABLE `wp_options`');
        try {
            self::assertNull(
                RateLimitRepository::incrementBucket('bcc_rl_' . uniqid('', true), '1|' . (time() + 3600)),
                'a DB error must fail closed (null), not allow'
            );
        } finally {
            // Restore for any subsequent test (order-independent).
            $GLOBALS['wpdb']->query(self::OPTIONS_DDL);
        }
    }
}
