<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\ModerationQueueService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The admin moderation queue's reporter-handle filter must find a real
 * handle.
 *
 * resolveHandleToUserIds() used to wrap the admin's text in '%…%' before
 * handing it to get_users(meta_compare=LIKE). WP_Meta_Query's LIKE branch
 * ALREADY does `'%' . $wpdb->esc_like($meta_value) . '%'`
 * (wp-includes/class-wp-meta-query.php, case 'LIKE'), so the SQL pattern
 * became '%\%alice\%%': our wildcards were escaped into literal percent
 * signs, and the filter matched nothing.
 *
 * The get_users() double below reproduces that core branch exactly
 * (esc_like = addcslashes($v, '_%\\'), then the surrounding wildcards,
 * then MySQL LIKE matching, case-insensitive like the default collation)
 * over an in-memory set of bcc_handle values. So the pre-fix wrapping
 * reproduces the real failure here, and these tests fail on it.
 */
#[CoversMethod(ModerationQueueService::class, 'resolveHandleToUserIds')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ModerationReporterHandleFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('get_users')) {
            eval(<<<'PHP'
                function get_users(array $args = []): array
                {
                    $GLOBALS['__bcc_get_users_args'][] = $args;
                    // WP_Meta_Query, case 'LIKE': '%' . esc_like(value) . '%'
                    $like = '%' . addcslashes((string) ($args['meta_value'] ?? ''), '_%\\') . '%';
                    // MySQL LIKE -> regex: unescaped % = .*, _ = ., \x = literal x.
                    $re = '';
                    $len = strlen($like);
                    for ($i = 0; $i < $len; $i++) {
                        $c = $like[$i];
                        if ($c === '\\' && $i + 1 < $len) {
                            $re .= preg_quote($like[++$i], '/');
                        } elseif ($c === '%') {
                            $re .= '.*';
                        } elseif ($c === '_') {
                            $re .= '.';
                        } else {
                            $re .= preg_quote($c, '/');
                        }
                    }
                    $out = [];
                    foreach ($GLOBALS['__bcc_handles'] as $id => $handle) {
                        if (preg_match('/^' . $re . '$/is', $handle) === 1) {
                            $out[] = (string) $id;
                        }
                    }
                    $limit = (int) ($args['number'] ?? 0);
                    return $limit > 0 ? array_slice($out, 0, $limit) : $out;
                }
                PHP);
        }
        $GLOBALS['__bcc_get_users_args'] = [];
        $GLOBALS['__bcc_handles'] = [
            11 => 'alice-builds',
            12 => 'bob',
            13 => 'malice',
            14 => 'carol_99',
        ];
    }

    /** @return list<int> */
    private static function resolve(string $handle): array
    {
        $m = new ReflectionMethod(ModerationQueueService::class, 'resolveHandleToUserIds');
        /** @var list<int> $ids */
        $ids = $m->invoke(null, $handle);
        return $ids;
    }

    public function testExactHandleMatches(): void
    {
        self::assertSame([12], self::resolve('bob'));
    }

    public function testFragmentMatchesEveryHandleContainingIt(): void
    {
        self::assertSame([11, 13], self::resolve('alice'));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        self::assertSame([12], self::resolve('BOB'));
    }

    public function testLeadingAtSignIsIgnored(): void
    {
        self::assertSame([11], self::resolve('@alice-b'));
    }

    public function testBareAtSignResolvesToNothingWithoutQuerying(): void
    {
        self::assertSame([], self::resolve('@'));
        self::assertSame([], $GLOBALS['__bcc_get_users_args']);
    }

    public function testUnknownHandleResolvesToNothing(): void
    {
        self::assertSame([], self::resolve('nobody'));
    }

    public function testUnderscoreIsLiteralNotAWildcard(): void
    {
        // esc_like keeps '_' literal, so 'l_ce' must not match 'alice'.
        self::assertSame([], self::resolve('l_ce'));
        self::assertSame([14], self::resolve('carol_'));
    }

    public function testRawFragmentIsPassedForCoreToWrap(): void
    {
        self::resolve('@alice');
        $args = $GLOBALS['__bcc_get_users_args'][0] ?? [];
        self::assertSame('bcc_handle', $args['meta_key'] ?? null);
        self::assertSame('LIKE', $args['meta_compare'] ?? null);
        self::assertSame('alice', $args['meta_value'] ?? null);
        self::assertSame('ID', $args['fields'] ?? null);
    }
}
