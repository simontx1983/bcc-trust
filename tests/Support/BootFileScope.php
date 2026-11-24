<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Support;

/**
 * Where a boot file calls `\Fully\Qualified\Class::method(`: at file scope,
 * or inside a function, closure or class body.
 *
 * A call at file scope in bcc-trust.php runs when WordPress includes the
 * plugin, before `plugins_loaded` and `init`. A call wrapped in a hook
 * callback runs later, so a filter it adds can miss a read that comes
 * first. Tests use this to pin "registered at boot" (F-581).
 *
 * A token walk, so comments and strings never count as calls. Braces are
 * tracked as a stack: one opened right after `function`, `fn`, `class`,
 * `trait`, `interface` or `enum` is a scope; any other is a block.
 */
final class BootFileScope
{
    /**
     * One entry per call found, in source order: true when the call is at
     * file scope (inside no function, closure or class body).
     *
     * @param string $relPath path relative to the plugin root
     * @param string $class   fully qualified, with the leading backslash
     * @return list<bool>
     */
    public static function calls(string $relPath, string $class, string $method): array
    {
        $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/' . $relPath));

        $stack   = [];
        $pending = false;
        $found   = [];
        $count   = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t    = $tokens[$i];
            $id   = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;

            if (in_array($id, [T_FUNCTION, T_FN, T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                $pending = true;
            } elseif ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stack[] = ($pending && $text === '{') ? 'scope' : 'block';
                $pending = false;
            } elseif ($text === '}') {
                array_pop($stack);
            } elseif ($text === ';') {
                $pending = false;   // an abstract method or an `fn` arrow ends here
            }

            // PHP 8 reads a fully qualified class name as one token.
            if ($id === T_NAME_FULLY_QUALIFIED && $text === $class) {
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                $next  = $tokens[$j] ?? null;
                $after = $tokens[$j + 1] ?? null;
                if ((is_array($next) ? $next[1] : $next) === '::' && is_array($after) && $after[1] === $method) {
                    $found[] = !in_array('scope', $stack, true);
                }
            }
        }

        return $found;
    }
}
