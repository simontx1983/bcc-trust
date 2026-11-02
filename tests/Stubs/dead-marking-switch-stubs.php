<?php

declare(strict_types=1);

/**
 * A namespaced get_option() so ChainRefreshService::deadMarkingEnabled() can be
 * EXECUTED by the unit suite rather than only read.
 *
 * ⚠⚠ WHY A NAMESPACED FUNCTION AND NOT A GLOBAL ONE.
 * The unit bootstrap defines no get_option at all, so the helper could not be
 * called - and the switch, the one control standing between a clean run and a
 * destructive global sweep, had no behavioural coverage at all. A mutant making
 * it `return true;` changed bytes, armed the sweep, and passed the whole suite.
 *
 * PHP resolves an unqualified call inside a namespace against that namespace
 * FIRST, so declaring it here intercepts the service's own call without touching
 * the global function table that every other suite shares.
 *
 * ⚠ IT MUST STAY TRANSPARENT WHEN NOT IN USE. Once declared, this wins for
 * EVERY unqualified get_option() reached from BCC\Trust\Onchain\Services,
 * including the bcc_onchain_indexer_stats read in the same file. So an
 * unregistered name is delegated to a real global get_option when one exists,
 * and otherwise returns the caller's own default - never a value of this stub's
 * invention.
 *
 * ⛔ AND A TEST MUST SET $strict, because delegation is ORDER-DEPENDENT.
 * The unit suite runs in one process and several other Stubs files declare a
 * GLOBAL get_option behind function_exists(). Whether one has been loaded by the
 * time a given test runs depends on test order, so a case asserting "the option
 * is ABSENT" would be answered by whichever stub happened to be in scope. With
 * $strict = true an unregistered name always returns the caller's default, which
 * is what "absent" has to mean. Pair it with reset() in the same test.
 */

namespace BCC\Trust\Onchain\Services;

final class DeadMarkingOptionStub
{
    /**
     * Option values this stub answers for. An absent key is not an answer.
     *
     * @var array<string, mixed>
     */
    public static array $values = [];

    /** When true an unregistered name returns the caller's default, never delegating. */
    public static bool $strict = false;

    /** Reads served from $values, so a test can prove the stub was consulted. */
    public static int $reads = 0;

    public static function reset(): void
    {
        self::$values = [];
        self::$strict = false;
        self::$reads  = 0;
    }

    /**
     * Register one option value.
     *
     * @param mixed $value
     */
    public static function set(string $name, $value): void
    {
        self::$values[$name] = $value;
    }
}

if (!function_exists(__NAMESPACE__ . '\\get_option')) {
    /**
     * @param  mixed $default
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        if (array_key_exists($option, DeadMarkingOptionStub::$values)) {
            ++DeadMarkingOptionStub::$reads;

            return DeadMarkingOptionStub::$values[$option];
        }

        if (!DeadMarkingOptionStub::$strict && function_exists('\\get_option')) {
            return \get_option($option, $default);
        }

        return $default;
    }
}
