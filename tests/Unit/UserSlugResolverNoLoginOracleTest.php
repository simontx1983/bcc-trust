<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Support\UserSlugResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * UserSlugResolver is the one resolver behind every per-user route
 * (/users/{slug}, followers/following, albums, groups, and the member
 * card via CardViewService). It must NOT be a login oracle: a login, or
 * WordPress's nicename slug of it, resolves to nothing, even though
 * get_user_by() WOULD find that user. That is what made /users/<x> a
 * login-existence and login->handle probe.
 *
 * It resolves exactly: a stored handle; a retired handle; member-{id}
 * for a member with no stored handle.
 */
#[CoversClass(UserSlugResolver::class)]
#[CoversMethod(CardViewService::class, 'resolveMemberCardHandle')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSlugResolverNoLoginOracleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('get_users')) {
            eval(<<<'PHP'
                function get_users(array $args = []): array
                {
                    $key = (string) ($args['meta_key'] ?? '');
                    $val = (string) ($args['meta_value'] ?? '');
                    return array_map('strval', $GLOBALS['__r_meta'][$key][$val] ?? []);
                }
                // A real WordPress WOULD find these users by login / nicename.
                // The resolver must never ask.
                function get_user_by(string $field, $value)
                {
                    $GLOBALS['__r_get_user_by'][] = $field;
                    $u = $GLOBALS['__r_by'][$field][strtolower((string) $value)] ?? null;
                    return $u === null ? false : (object) ['ID' => $u];
                }
                function get_userdata(int $id)
                {
                    return isset($GLOBALS['__r_users'][$id]) ? (object) ['ID' => $id] : false;
                }
                function get_user_meta(int $id, string $key = '', bool $single = false)
                {
                    return $GLOBALS['__r_usermeta'][$id][$key] ?? '';
                }
                function sanitize_title(string $s): string
                {
                    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
                }
                PHP);
        }
        $GLOBALS['__r_users']    = [3 => true, 4 => true, 5 => true];
        $GLOBALS['__r_usermeta'] = [3 => ['bcc_handle' => 'carol'], 4 => [], 5 => ['bcc_handle' => 'eve']];
        $GLOBALS['__r_meta']     = [
            'bcc_handle'         => ['carol' => [3], 'eve' => [5]],
            'bcc_handle_retired' => ['oldcarol' => [3]],
        ];
        $GLOBALS['__r_by'] = [
            'login' => ['carollogin' => 3, 'dlogin' => 4],
            'slug'  => ['carol-login' => 3, 'd-login' => 4],
        ];
        $GLOBALS['__r_get_user_by'] = [];
    }

    public function testStoredHandleResolves(): void
    {
        self::assertSame(3, UserSlugResolver::resolve('carol'));
        self::assertSame(3, UserSlugResolver::resolve('  CAROL '));
    }

    public function testRetiredHandleResolves(): void
    {
        self::assertSame(3, UserSlugResolver::resolve('oldcarol'));
    }

    public function testALoginDoesNotResolve(): void
    {
        self::assertSame(0, UserSlugResolver::resolve('carollogin'));
        self::assertSame(0, UserSlugResolver::resolve('dlogin'));
    }

    public function testANicenameDoesNotResolve(): void
    {
        self::assertSame(0, UserSlugResolver::resolve('Carol Login'));
        self::assertSame(0, UserSlugResolver::resolve('d-login'));
    }

    public function testTheResolverNeverLooksUsersUpByLoginOrSlug(): void
    {
        foreach (['carol', 'oldcarol', 'carollogin', 'Carol Login', 'member-4', 'nobody'] as $slug) {
            UserSlugResolver::resolve($slug);
        }
        self::assertSame([], $GLOBALS['__r_get_user_by']);
    }

    public function testMemberIdResolvesOnlyForAHandlelessMember(): void
    {
        self::assertSame(4, UserSlugResolver::resolve('member-4'), 'no stored handle: the fallback link works');
        self::assertSame(0, UserSlugResolver::resolve('member-3'), 'has a handle: reached by it, not member-{id}');
        self::assertSame(0, UserSlugResolver::resolve('member-99'), 'no such member');
        self::assertSame(0, UserSlugResolver::resolve('member-04'), 'not the canonical form');
        self::assertSame(0, UserSlugResolver::resolve('member-0'));
    }

    public function testTheMemberCardResolverIsTheSameResolver(): void
    {
        foreach (['carol', 'oldcarol', 'carollogin', 'd-login', 'member-4', 'member-3'] as $slug) {
            self::assertSame(
                UserSlugResolver::resolve($slug),
                CardViewService::resolveMemberCardHandle($slug),
                "gate and /users/* disagree on '$slug'"
            );
        }
    }
}
