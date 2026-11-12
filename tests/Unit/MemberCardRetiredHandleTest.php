<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The member-card resolver (used by both the CardsEndpoint gate and the
 * payload) must find a member through a handle retired by the
 * login-handle rotation, and through nothing else: no login lookup.
 */
#[CoversMethod(CardViewService::class, 'resolveMemberCardHandle')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MemberCardRetiredHandleTest extends TestCase
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
                    $ids = $GLOBALS['__bcc_card_meta'][$key][$val] ?? [];
                    return array_map('strval', $ids);
                }
                PHP);
        }
        $GLOBALS['__bcc_card_meta'] = [
            'bcc_handle'         => ['jane-smith' => [7]],
            'bcc_handle_retired' => ['jsmith77' => [7]],
        ];
    }

    public function testCurrentHandleResolves(): void
    {
        self::assertSame(7, CardViewService::resolveMemberCardHandle('jane-smith'));
    }

    public function testRetiredHandleResolvesToItsMember(): void
    {
        self::assertSame(7, CardViewService::resolveMemberCardHandle('jsmith77'));
        self::assertSame(7, CardViewService::resolveMemberCardHandle('JSmith77'));
    }

    public function testUnknownSlugDoesNotResolve(): void
    {
        self::assertSame(0, CardViewService::resolveMemberCardHandle('somelogin'));
    }
}
