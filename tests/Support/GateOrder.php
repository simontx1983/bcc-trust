<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * F-96: assert a gate comes BEFORE the work it protects, in source.
 *
 * Many wiring tests pin that a gate token is PRESENT in a route. Presence
 * is not protection: a refactor that moves the check below the write, or
 * below the success response, keeps the token and the test green while the
 * route is open. These helpers pin the ORDER, inside one method body, with
 * comments removed so a docblock mention can never satisfy them.
 *
 * Kept to string positions on purpose. A token walk would be more exact,
 * but every caller here pins short, distinctive needles, and the failure
 * message names the method and the needle, so a false result is cheap to
 * diagnose.
 */
final class GateOrder
{
    /** Source of a plugin file (path relative to the plugin root), comments removed. */
    public static function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
        $src  = file_get_contents($path);
        Assert::assertIsString($src, "could not read {$relative}");
        Assert::assertNotSame('', $src, "{$relative} is empty");

        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** The brace-balanced body of `function $method(` in $code. */
    public static function body(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method . '(');
        Assert::assertNotFalse($start, "{$method}() not found");

        $open = strpos($code, '{', $start);
        Assert::assertNotFalse($open, "{$method}() has no body");

        $depth = 0;
        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        Assert::fail("{$method}() body is unbalanced");
    }

    /**
     * Each needle is present, and their FIRST occurrences appear in the
     * given order.
     *
     * @param list<string> $needles
     */
    public static function assertOrder(string $body, array $needles, string $label): void
    {
        $previous = -1;
        $prevName = '(start)';
        foreach ($needles as $needle) {
            $pos = strpos($body, $needle);
            Assert::assertIsInt($pos, "{$label}: `{$needle}` is missing");
            Assert::assertGreaterThan(
                $previous,
                $pos,
                "{$label}: `{$needle}` must come after `{$prevName}`"
            );
            $previous = $pos;
            $prevName = $needle;
        }
    }

    /**
     * $gate is present, at least one $work needle is present, and NO
     * occurrence of any $work needle comes before the gate.
     *
     * @param list<string> $work
     */
    public static function assertNoWorkBefore(string $body, string $gate, array $work, string $label): void
    {
        $gatePos = strpos($body, $gate);
        Assert::assertIsInt($gatePos, "{$label}: the gate `{$gate}` is missing");

        $seen   = false;
        $before = substr($body, 0, $gatePos);
        foreach ($work as $needle) {
            if (str_contains($body, $needle)) {
                $seen = true;
            }
            Assert::assertStringNotContainsString(
                $needle,
                $before,
                "{$label}: `{$needle}` runs before the gate `{$gate}`"
            );
        }
        Assert::assertTrue($seen, "{$label}: none of the work needles is present, so the order check would be vacuous");
    }
}
