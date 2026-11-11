<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-65 part B review: the tombstone prune must be hooked so that a plain
 * do_action('bcc_onchain_daily_refresh') cannot pass an argument into it.
 *
 * WordPress's do_action() with no extra arguments still hands ONE argument,
 * '', to a callback registered with the default accepted_args = 1. Registered
 * as [NftHoldingsRepository::class, 'pruneTombstones'], that '' lands in
 * `int $limit` — a TypeError that takes down every later callback on the
 * daily refresh. (PHP 8 rejects a non-numeric string for an int parameter
 * even in coercive mode.) bcc-trust.php cannot be loaded by the unit suite,
 * so the wiring is pinned at source level, comments stripped so prose cannot
 * satisfy or break it.
 */
final class TombstonePruneHookWiringTest extends TestCase
{
    private static function code(): string
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
        $code = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($tok) ? $tok[1] : $tok;
        }
        return $code;
    }

    public function testThePruneIsNeverRegisteredAsAnArgumentTakingCallable(): void
    {
        self::assertDoesNotMatchRegularExpression(
            "/add_action\\(\\s*'bcc_onchain_daily_refresh'\\s*,\\s*\\[[^\\]]*NftHoldingsRepository::class\\s*,\\s*'pruneTombstones'\\s*\\]/",
            self::code(),
            'a [class, method] callable would receive do_action\'s \'\' argument as $limit'
        );
    }

    public function testThePruneIsRegisteredAsAZeroArgumentClosureThatCatches(): void
    {
        $code = self::code();
        $pos  = strpos($code, 'NftHoldingsRepository::pruneTombstones(');
        self::assertNotFalse($pos, 'the daily refresh still prunes tombstones');

        $before = substr($code, 0, $pos);
        $hook   = strrpos($before, "add_action('bcc_onchain_daily_refresh'");
        self::assertNotFalse($hook);

        $end = strpos($code, '}, 30);', $pos);
        self::assertNotFalse($end, 'the registration closes with priority 30');
        $registration = substr($code, $hook, $end - $hook);
        self::assertMatchesRegularExpression(
            "/add_action\\('bcc_onchain_daily_refresh',\\s*static function \\(\\): void \\{/",
            $registration,
            'a closure taking no parameters'
        );
        self::assertStringContainsString('catch (\\Throwable', $registration, 'a prune failure must not reach sibling callbacks');
        self::assertStringContainsString('holdings tombstones pruned', $registration, 'rows pruned per run are logged');
    }
}
