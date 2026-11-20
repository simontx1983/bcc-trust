<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-361 — EVERY post write in bcc-trust goes through
 * `PeepSoAddPostGuard::pinOnly()`.
 *
 * ── WHY A MECHANICAL INVARIANT AND NOT A JUDGMENT ───────────────────
 *
 * F-361's first pass scoped itself to "writes reachable from a member's
 * request". That needs a call per site, and the call was wrong FIVE
 * times, each for a different reason:
 *
 *   wp_update_post in a review-reply publish  — not spelled wp_insert_post
 *   a comment TRASH                           — did not look like a write
 *   wp_insert_attachment (cover upload)        — not spelled wp_insert_post
 *   new PeepSoGroup(null, $data) ×3            — not a function call at all
 *   wp_save_post_revision ×2                   — a revision is a post
 *
 * ⚠⚠ The three provisioning services' own docblocks ALREADY said
 * "`new PeepSoGroup(null, $data)` runs `wp_insert_post()`". The codebase
 * knew; nothing connected that knowledge to a check. That is the whole
 * case for this test.
 *
 * "Every BCC post write is pinned" needs no judgment, so it can be
 * checked — and {@see ALLOWLIST} is EMPTY, because an allowlist naming
 * the sites a guard exists to catch is a green that means nothing.
 *
 * ── WHAT COUNTS AS A POST WRITE ─────────────────────────────────────
 *
 * Anything reaching the `wp_insert_post` ACTION, which third-party
 * listeners hook. The listener inventory itself lives in
 * `PeepSoAddPostGuard`'s own documentation in bcc-core, not here — this
 * repo is public. ⚠ Every entry in {@see WRITERS} was confirmed by
 * reading WordPress's source. Rationale: F-361 (private tracker).
 *
 * ── THE THREE WAYS A NAIVE GUARD IS WRONG ───────────────────────────
 *
 * All three were found by mutation controls or a runnable case, not by
 * reading the code:
 *
 *   1. TEXT, NOT TOKENS. `substr_count` of
 *      `PeepSoAddPostGuard::pinOnly(` read 3 for 2 calls, because the
 *      comment explaining the guard named it.
 *   2. ONE NAME TOKEN, NOT THREE. PHP 8 lexes `\wp_trash_post(` as a
 *      single T_NAME_FULLY_QUALIFIED, so a T_STRING-only walk missed
 *      every backslash-prefixed write.
 *   3. PAREN DEPTH IS NOT A PIN. `pinOnly([], wp_insert_post([...]))`
 *      nests inside pinOnly's parens, but PHP evaluates an ARGUMENT
 *      before the call — so the write runs BEFORE the request is pinned
 *      while a depth walk calls it protected.
 */
final class PostWritePinInventoryTest extends TestCase
{
    /**
     * Functions whose call reaches the `wp_insert_post` action, each
     * citing the WordPress source that proves it.
     */
    private const WRITERS = [
        // fires the action itself
        'wp_insert_post'                => 'post.php:5204 — do_action(\'wp_insert_post\')',
        'wp_publish_post'               => 'post.php:5370 — fires it after a direct $wpdb->update',
        // one hop
        'wp_update_post'                => 'calls wp_insert_post()',
        'wp_insert_attachment'          => 'post.php:6665 — returns wp_insert_post()',
        'wp_trash_post'                 => 'calls wp_update_post() in its body',
        'wp_untrash_post'               => 'calls wp_update_post() in its body',
        'media_handle_upload'           => 'media.php:133 — wp_insert_attachment()',
        'media_handle_sideload'         => 'media.php:64 — wp_insert_attachment()',
        'check_and_publish_future_post' => 'calls wp_publish_post()',
        '_wp_put_post_revision'         => 'revision.php:19 — wp_insert_post($post, true)',
        'wp_restore_post_revision'      => 'revision.php:25 — calls wp_update_post()',
        // two hops
        'media_sideload_image'          => 'media.php:46 — media_handle_sideload()',
        'wp_save_post_revision'         => 'revision.php:99 — calls _wp_put_post_revision()',
        // conditional — see CONDITIONAL_WRITERS
        'wp_delete_post'                => 'post.php:18-22 — returns wp_trash_post() when NOT forced',
        'wp_delete_attachment'          => 'its :16 — returns wp_trash_post() when NOT forced',
    ];

    /**
     * ⚠ These reach the action only when NOT forced, under a condition a
     * token walk cannot evaluate: `wp_delete_post` needs post-or-page,
     * a non-trash status and `EMPTY_TRASH_DAYS`; `wp_delete_attachment`
     * needs `EMPTY_TRASH_DAYS` AND `MEDIA_TRASH`. So the rule is
     * structural: an explicit `true` second argument is exempt, anything
     * else counts. That can only OVER-flag, which is the safe direction,
     * and the condition is written down because it — not the name — is
     * the reason.
     *
     * @var list<string>
     */
    private const CONDITIONAL_WRITERS = ['wp_delete_post', 'wp_delete_attachment'];

    /** Indirect dispatch: these take a callable NAME as a string. */
    private const DISPATCHERS = ['call_user_func', 'call_user_func_array'];

    /**
     * ⛔ DELIBERATELY EMPTY, and it must stay that way.
     *
     * Adding an entry here is the thing this test exists to prevent. If a
     * write genuinely cannot be pinned, that needs a decision and a
     * stated reason in review — not a quiet line in a test fixture.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [];

    /** Directories this guard polices. bcc-core's twin polices src/. */
    private const ROOTS = ['app', 'includes'];

    /** Single files outside those roots that still ship code. */
    private const EXTRA_FILES = ['bcc-trust.php'];

    /**
     * ⛔ VACUITY FLOOR. A worktree without the tree makes every scan pass
     * over nothing — the false green that let the umbrella guards "pass"
     * in a checkout with no plugin trees. Fewer files than this FAILS.
     */
    private const MIN_FILES = 50;

    private static function baseDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function fixtureDir(): string
    {
        // ⚠ CAPITAL F — `tests/Fixtures`, the directory this repo already
        // uses (CollectionStancePanelStatusTest reads one from it). A
        // lowercase spelling resolves on Windows and NOT on the Linux CI
        // runner, so the fixture tests below would fail only after push.
        return dirname(__DIR__) . '/Fixtures';
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $base  = self::baseDir();
        $files = [];

        foreach (self::ROOTS as $dir) {
            $root = $base . '/' . $dir;
            self::assertDirectoryExists($root, "guarded root {$dir} is missing");

            // ⚠ SKIP_DOTS is load-bearing: without it the iterator yields
            // `.` and recurses into it forever. Measured — the first
            // version of this walk hung.
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }

        foreach (self::EXTRA_FILES as $rel) {
            $path = $base . '/' . $rel;
            self::assertFileExists($path, "guarded file {$rel} is missing");
            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Every post-write call site, with whether a `pinOnly(` is genuinely
     * open around it.
     *
     * @param list<string> $files
     * @return list<array{site: string, fn: string, pinned: bool, note: string}>
     */
    private static function callSites(array $files): array
    {
        $base = self::baseDir();
        $rows = [];

        foreach ($files as $file) {
            $src    = (string) file_get_contents($file);
            $tokens = token_get_all($src);
            $count  = count($tokens);
            $rel    = str_replace('\\', '/', substr($file, strlen($base) + 1));

            // ── pre-pass: which variables hold a PeepSoGroup? ──────────
            //
            // ⚠ FILE-scoped, not function-scoped, and that is the
            // conservative direction: it can only ever flag MORE
            // `->update(` calls, never fewer. Measured before choosing it —
            // trust `app/` has 69 `->update(` sites of which 68 are
            // `$wpdb->update(`, so binding to a receiver is what keeps this
            // rule from producing 68 false positives against one true one.
            // A guard that noisy gets switched off.
            $groupVars = [];
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                    continue;
                }
                $j = self::nextSignificant($tokens, $i + 1, $count);
                if ($j === null || !is_array($tokens[$j]) || self::bare($tokens[$j][1]) !== 'PeepSoGroup') {
                    continue;
                }
                // walk back for `$var =`
                $k = $i - 1;
                while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    $k--;
                }
                if ($k >= 0 && $tokens[$k] === '=') {
                    $k--;
                    while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k--;
                    }
                    if ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE) {
                        $groupVars[$tokens[$k][1]] = true;
                    }
                }
            }

            // ── main walk ──────────────────────────────────────────────
            $depth     = 0;
            $pins      = [];   // list<array{depth:int}>  open pinOnly( calls
            $bodies    = [];   // list<array{kind:string, startDepth:int, brace:int}>
            $pendingFn = null; // 'fn'|'block' — a closure seen, body not yet open
            $pendingAt = 0;
            $line      = 1;

            for ($i = 0; $i < $count; $i++) {
                $t = $tokens[$i];

                if (is_array($t)) {
                    $line = $t[2];

                    // ⚠⚠ HARDENING 1 — a write is pinned ONLY while inside
                    // the BODY of a closure that is one of `pinOnly`'s
                    // arguments.
                    //
                    // `pinOnly([], wp_insert_post([...]))` is nested inside
                    // pinOnly's parens, so a paren-depth walk calls it
                    // pinned — but PHP evaluates an ARGUMENT before the
                    // call, so the write completes BEFORE the request is
                    // pinned. Proved with a runnable case.
                    //
                    // ⚠⚠ AND "A CLOSURE APPEARED SOMEWHERE IN THE ARGUMENTS"
                    // IS NOT ENOUGH EITHER. The first version of this
                    // hardening set one `armed` flag per PIN, so a decoy
                    // closure armed it and any write later in the argument
                    // list counted as protected:
                    //
                    //   pinOnly([static fn () => null], wp_insert_post([…]))
                    //
                    // Measured: that read `pinned=YES` while the write still
                    // ran first. So arming is tracked per closure BODY —
                    // a block closure from its `{` to the matching `}`, an
                    // arrow fn from its `=>` until the argument ends.
                    if (($t[0] === T_FUNCTION || $t[0] === T_FN) && $pins !== []
                        && $depth >= $pins[count($pins) - 1]['depth']) {
                        $pendingFn = $t[0] === T_FN ? 'fn' : 'block';
                        $pendingAt = $depth;
                    }

                    // An arrow fn's body begins at `=>`.
                    if ($t[0] === T_DOUBLE_ARROW && $pendingFn === 'fn') {
                        $bodies[] = ['kind' => 'fn', 'startDepth' => $pendingAt, 'brace' => 0];
                        $pendingFn = null;
                    }

                    // HARDENING 3 — `use function` importing a writer. An
                    // ALIASED import (`use function wp_insert_post as x;`)
                    // would let the call be spelled `x(`, which no
                    // name-based walk can see. Reported rather than silently
                    // tolerated.
                    if ($t[0] === T_STRING || $t[0] === T_NAME_FULLY_QUALIFIED || $t[0] === T_NAME_QUALIFIED) {
                        if (isset(self::WRITERS[self::bare($t[1])]) && self::isInUseFunction($tokens, $i)) {
                            $rows[] = [
                                'site'   => $rel . ':' . $line,
                                'fn'     => self::bare($t[1]),
                                'pinned' => false,
                                'note'   => 'imported with `use function` — an alias would hide every call from this guard',
                            ];
                        }
                    }

                    // call_user_func('wp_insert_post', …): the writer's name
                    // is a STRING, not a T_STRING.
                    //
                    // ⚠⚠ ONLY INSIDE A DISPATCHER, and that qualifier is not
                    // pedantry — without it this flagged
                    // `function_exists('wp_save_post_revision')`, which is a
                    // GUARD rather than a call. Measured: the first version
                    // reported two false positives on exactly that line, in
                    // code that was already careful. A guard that flags
                    // defensive code teaches people to disable it.
                    if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $literal = trim($t[1], "'\"\\");
                        if (isset(self::WRITERS[$literal])
                            && in_array(self::enclosingCallName($tokens, $i), self::DISPATCHERS, true)) {
                            $rows[] = [
                                'site'   => $rel . ':' . $line,
                                'fn'     => $literal . ' (as a string)',
                                'pinned' => self::inPinnedClosure($bodies),
                                'note'   => 'dispatched by name through call_user_func',
                            ];
                        }
                    }

                    // ── PeepSo MODEL write: `new PeepSoGroup($a, $b)` ──
                    //
                    // ⚠ TWO arguments CREATE (group.php:308 →
                    // wp_insert_post); ONE argument only LOADS. The three
                    // provisioning services each do both, so arg count is
                    // the whole distinction.
                    if ($t[0] === T_NEW) {
                        $j = self::nextSignificant($tokens, $i + 1, $count);
                        if ($j !== null && is_array($tokens[$j]) && self::bare($tokens[$j][1]) === 'PeepSoGroup') {
                            $open = self::nextSignificant($tokens, $j + 1, $count);
                            if ($open !== null && $tokens[$open] === '('
                                && self::topLevelArgs($tokens, $open, $count) >= 2) {
                                $rows[] = [
                                    'site'   => $rel . ':' . $line,
                                    'fn'     => 'new PeepSoGroup(…, …)',
                                    'pinned' => self::inPinnedClosure($bodies),
                                    'note'   => 'PeepSo model create — group.php:308 runs wp_insert_post()',
                                ];
                            }
                        }
                    }
                }

                // ── closure BODY bookkeeping ─────────────────────────
                //
                // A block closure's body runs from its `{` to the matching
                // `}`. An arrow fn's body runs from `=>` to the end of that
                // argument — so it ends at a `,` at its own paren depth, or
                // when the depth drops below it.
                if ($t === '{') {
                    if ($pendingFn === 'block') {
                        $bodies[]  = ['kind' => 'block', 'startDepth' => $pendingAt, 'brace' => 1];
                        $pendingFn = null;
                    } elseif ($bodies !== [] && $bodies[count($bodies) - 1]['kind'] === 'block') {
                        $bodies[count($bodies) - 1]['brace']++;
                    }
                    continue;
                }
                if ($t === '}') {
                    if ($bodies !== [] && $bodies[count($bodies) - 1]['kind'] === 'block') {
                        $bodies[count($bodies) - 1]['brace']--;
                        if ($bodies[count($bodies) - 1]['brace'] <= 0) {
                            array_pop($bodies);
                        }
                    }
                    continue;
                }
                if ($t === ',') {
                    // An arrow-fn body ends with its argument.
                    while ($bodies !== []
                        && $bodies[count($bodies) - 1]['kind'] === 'fn'
                        && $bodies[count($bodies) - 1]['startDepth'] >= $depth) {
                        array_pop($bodies);
                    }
                    continue;
                }

                if ($t === '(') {
                    $depth++;
                    continue;
                }
                if ($t === ')') {
                    // Closing this paren ends any arrow-fn body that opened
                    // inside it, and any pin whose own arg list it closes.
                    while ($bodies !== []
                        && $bodies[count($bodies) - 1]['kind'] === 'fn'
                        && $bodies[count($bodies) - 1]['startDepth'] >= $depth) {
                        array_pop($bodies);
                    }
                    while ($pins !== [] && $pins[count($pins) - 1]['depth'] >= $depth) {
                        array_pop($pins);
                    }
                    $depth--;
                    continue;
                }

                // ⚠⚠ THREE NAME TOKENS, NOT ONE. In PHP 8 a fully-qualified
                // call is a SINGLE token: `\wp_trash_post(` lexes as one
                // T_NAME_FULLY_QUALIFIED whose text includes the backslash,
                // NOT T_NS_SEPARATOR + T_STRING as in PHP 7.
                //
                // ⭐ Found by a mutation control, not by reading: the mutant
                // "a write hides behind a LEADING BACKSLASH" SURVIVED the
                // first version of this guard, which looked correct and was
                // green.
                if (!is_array($t) || !in_array(
                    $t[0],
                    [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED],
                    true
                )) {
                    continue;
                }

                $name = self::bare($t[1]);

                // a call needs a `(` next
                $j = self::nextSignificant($tokens, $i + 1, $count);
                if ($j === null || $tokens[$j] !== '(') {
                    continue;
                }

                // ── PeepSo MODEL write: `$group->update([...])` ─────────
                if ($name === 'update' && self::receiverIsGroupVar($tokens, $i, $groupVars)) {
                    $rows[] = [
                        'site'   => $rel . ':' . $line,
                        'fn'     => '$group->update(…)',
                        'pinned' => self::inPinnedClosure($bodies),
                        'note'   => 'PeepSo model update — group.php:355 runs wp_update_post()',
                    ];
                    continue;
                }

                // ⚠⚠ HARDENING 2 — only the REAL guard arms a pin. Any
                // method named `pinOnly` would otherwise do, including one
                // a future class invents with different semantics.
                if ($name === 'pinOnly') {
                    if (self::isRealGuardCall($tokens, $i, $src)) {
                        $pins[] = ['depth' => $depth + 1];
                    }
                    continue;
                }

                if (!isset(self::WRITERS[$name]) && !in_array($name, self::DISPATCHERS, true)) {
                    continue;
                }

                // `->wp_update_post(` or `Foo::wp_update_post(` is someone
                // else's method. A LEADING BACKSLASH is ours, which is why
                // T_NS_SEPARATOR is not in this list.
                $prev = self::prevSignificant($tokens, $i - 1);
                if ($prev !== null && is_array($tokens[$prev]) && in_array(
                    $tokens[$prev][0],
                    [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION],
                    true
                )) {
                    continue;
                }

                // A dispatcher writes nothing itself; its string argument was
                // recorded above.
                if (in_array($name, self::DISPATCHERS, true)) {
                    continue;
                }

                // ⚠ `wp_delete_post` / `wp_delete_attachment` only reach the
                // action when NOT forced: each returns `wp_trash_post()`
                // under a condition a token walk cannot evaluate
                // (post type, EMPTY_TRASH_DAYS, MEDIA_TRASH, current
                // status). So the rule is structural rather than semantic:
                // an explicit `true` second argument is exempt, anything
                // else counts as a write. That is the safe reading — it can
                // only over-flag — and it is written down because the
                // condition is the reason, not the name.
                if (in_array($name, self::CONDITIONAL_WRITERS, true)
                    && self::hasExplicitTrueSecondArg($tokens, $j, $count)) {
                    continue;
                }

                $rows[] = [
                    'site'   => $rel . ':' . $line,
                    'fn'     => $name,
                    'pinned' => self::inPinnedClosure($bodies),
                    'note'   => self::WRITERS[$name],
                ];
            }
        }

        return $rows;
    }

    /**
     * Are we inside the BODY of a closure that is one of `pinOnly`'s
     * arguments?
     *
     * ⚠ Not "is a pin open" and not "did a closure appear in its
     * arguments" — both of those count an evaluated argument as pinned.
     * Only a write inside the closure body runs after the request has
     * been neutralised.
     *
     * @param list<array{kind: string, startDepth: int, brace: int}> $bodies
     */
    private static function inPinnedClosure(array $bodies): bool
    {
        return $bodies !== [];
    }

    /**
     * The name of the call whose argument list this token sits in, or ''.
     *
     * Walks back balancing parens to the `(` that opened the current
     * argument list, then reads the name before it. Used to tell
     * `call_user_func('wp_insert_post', …)` — a real dispatch — from
     * `function_exists('wp_save_post_revision')`, which is a guard.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function enclosingCallName(array $tokens, int $i): string
    {
        $depth = 0;

        for ($k = $i - 1; $k >= 0; $k--) {
            $t = $tokens[$k];

            if ($t === ')' || $t === ']') {
                $depth++;
                continue;
            }
            if ($t === '(' || $t === '[') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                // this `(` opens our argument list
                $n = self::prevSignificant($tokens, $k - 1);
                if ($n !== null && is_array($tokens[$n]) && in_array(
                    $tokens[$n][0],
                    [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED],
                    true
                )) {
                    return self::bare($tokens[$n][1]);
                }

                return '';
            }
            if ($t === ';' || $t === '{' || $t === '}') {
                return '';
            }
        }

        return '';
    }

    /** `\Foo\Bar` and `Bar` both reduce to the trailing name. */
    private static function bare(string $name): string
    {
        $parts = explode('\\', $name);

        return (string) end($parts);
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private static function nextSignificant(array $tokens, int $from, int $count): ?int
    {
        for ($i = $from; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array(
                $tokens[$i][0],
                [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                true
            )) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private static function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array(
                $tokens[$i][0],
                [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                true
            )) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * ⚠⚠ HARDENING 2. A pin counts only when it is
     * `PeepSoAddPostGuard::pinOnly(` — or `self::`/`static::` INSIDE that
     * class, which only bcc-core's own file can be.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isRealGuardCall(array $tokens, int $i, string $src): bool
    {
        $colon = self::prevSignificant($tokens, $i - 1);
        if ($colon === null || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
            return false;
        }

        $cls = self::prevSignificant($tokens, $colon - 1);
        if ($cls === null || !is_array($tokens[$cls])) {
            return false;
        }

        $name = self::bare($tokens[$cls][1]);
        if ($name === 'PeepSoAddPostGuard') {
            return true;
        }

        // self:: / static:: only inside the guard class itself.
        return in_array(strtolower($name), ['self', 'static'], true)
            && str_contains($src, 'class PeepSoAddPostGuard');
    }

    /**
     * Is this name token the tail of a `use function …;` statement?
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isInUseFunction(array $tokens, int $i): bool
    {
        $seenFunction = false;
        for ($k = $i - 1; $k >= 0 && $k > $i - 12; $k--) {
            if (!is_array($tokens[$k])) {
                if ($tokens[$k] === ';' || $tokens[$k] === '{') {
                    return false;
                }
                continue;
            }
            if ($tokens[$k][0] === T_FUNCTION) {
                $seenFunction = true;
                continue;
            }
            if ($tokens[$k][0] === T_USE) {
                return $seenFunction;
            }
        }

        return false;
    }

    /**
     * Count TOP-LEVEL arguments in the call whose `(` sits at $open.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function topLevelArgs(array $tokens, int $open, int $count): int
    {
        $depth = 0;
        $args  = 0;
        $any   = false;

        for ($i = $open; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '(' || $t === '[') {
                $depth++;
                continue;
            }
            if ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    return $any ? $args + 1 : 0;
                }
                continue;
            }
            if ($depth === 1) {
                if ($t === ',') {
                    $args++;
                    continue;
                }
                if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $any = true;
                }
            }
        }

        return $any ? $args + 1 : 0;
    }

    /**
     * Was the receiver of this `->update(` assigned from a PeepSoGroup?
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, bool> $groupVars
     */
    private static function receiverIsGroupVar(array $tokens, int $i, array $groupVars): bool
    {
        $arrow = self::prevSignificant($tokens, $i - 1);
        if ($arrow === null || !is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_OBJECT_OPERATOR) {
            return false;
        }

        $recv = self::prevSignificant($tokens, $arrow - 1);
        if ($recv === null || !is_array($tokens[$recv]) || $tokens[$recv][0] !== T_VARIABLE) {
            return false;
        }

        return isset($groupVars[$tokens[$recv][1]]);
    }

    /**
     * Does this call pass a literal `true` as its SECOND argument?
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function hasExplicitTrueSecondArg(array $tokens, int $open, int $count): bool
    {
        $depth  = 0;
        $argIdx = 0;

        for ($i = $open; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '(' || $t === '[') {
                $depth++;
                continue;
            }
            if ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            if ($t === ',') {
                $argIdx++;
                continue;
            }
            if ($argIdx === 1 && is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'true') {
                return true;
            }
        }

        return false;
    }

    // ── the assertions ───────────────────────────────────────────────

    /** ⛔ The guard: nothing unpinned, and nothing allowlisted. */
    public function testEveryPostWriteIsPinned(): void
    {
        $files = self::phpFiles();

        self::assertGreaterThanOrEqual(
            self::MIN_FILES,
            count($files),
            'only ' . count($files) . ' PHP files scanned — too few to be the real tree, so a'
            . ' clean result would mean nothing. Check the roots are checked out.'
        );

        $unpinned = [];
        foreach (self::callSites($files) as $row) {
            if ($row['pinned'] || in_array($row['site'], self::ALLOWLIST, true)) {
                continue;
            }
            $unpinned[] = $row['site'] . '  (' . $row['fn'] . ' — ' . $row['note'] . ')';
        }

        self::assertSame(
            [],
            $unpinned,
            "These post writes are NOT inside PeepSoAddPostGuard::pinOnly():\n  "
            . implode("\n  ", $unpinned)
            . "\n\nEach reaches the `wp_insert_post` action, where third-party"
            . "\nlisteners run. Wrap the write in pinOnly(), with the write INSIDE"
            . "\nan arrow fn in pinOnly's argument list — a write passed as a"
            . "\nbare argument is evaluated BEFORE the pin and is not covered."
            . "\n\nDo NOT add it to this test's allowlist: that is the failure mode this"
            . "\nguard exists to prevent."
        );
    }

    /**
     * ⚠ ANTI-VACUITY, and this is what makes the assertion above worth
     * having. A walk that silently matched nothing would also report "no
     * unpinned writes". So the tree must contain writes, and at least one
     * must be recognised as PINNED — proving pin detection works rather
     * than that writer detection found nothing.
     */
    public function testTheWalkFindsWritesAndSeesPins(): void
    {
        $rows = self::callSites(self::phpFiles());

        self::assertNotEmpty($rows, 'the walk found NO post writes at all — it is not measuring anything');

        $pinned = array_filter($rows, static fn(array $r): bool => $r['pinned']);
        self::assertNotEmpty(
            $pinned,
            'the walk found writes but recognised NO pin — pin detection is broken,'
            . ' which would make the guard above vacuous'
        );
    }


    /**
     * ⚠⚠ COMMENTS ARE NOT CALL SITES — asserted, not assumed.
     *
     * The most load-bearing property of the walk, because THREE separate
     * incidents on F-361 had exactly this shape:
     *
     *   - `substr_count('…::pinOnly(')` read 3 for 2 calls, because the
     *     comment EXPLAINING the guard named it;
     *   - the guard flagged `function_exists('wp_save_post_revision')`,
     *     a defensive check, because the name appeared as a string;
     *   - a comment saying "not a PHPStan suppression comment" BECAME
     *     one, because that tool reads comments too.
     *
     * ⭐ And it is live here: `pinOnly()`'s own docblock carries the WRONG
     * form — `pinOnly([], wp_insert_post(...))` — as a worked example of
     * what not to write. A text-matching guard would report its own
     * documentation as an unpinned write, and the obvious "fix" would be
     * deleting the example that prevents the mistake.
     */
    public function testACommentMentionIsNotACallSite(): void
    {
        $fixture = self::fixture('comment-only-writes.php.txt');

        // Sanity: the fixture really does NAME writers, so a pass cannot
        // come from an empty file.
        $text = (string) file_get_contents($fixture);
        foreach (['wp_insert_post', 'wp_update_post', 'wp_trash_post', 'PeepSoGroup', 'call_user_func'] as $needle) {
            self::assertStringContainsString($needle, $text, "the fixture must mention {$needle}");
        }

        self::assertSame(
            [],
            self::callSites([$fixture]),
            'a writer named only in a COMMENT must not be reported as a call site'
        );
    }

    /**
     * ⛔⛔ THE DECOY: a closure in the argument list does NOT pin a write
     * that sits beside it.
     *
     * This is the hole the FIRST version of the hardening left, and it is
     * subtle enough to be worth spelling out. Hardening form A made a
     * write count as pinned only if a closure appeared inside
     * `pinOnly`'s arguments. But that flag was per PIN, so:
     *
     *     pinOnly([static fn () => null], wp_insert_post([…]))
     *
     * armed the pin with a decoy closure in the FIRST argument, and the
     * write in the second was then reported `pinned=YES` — while still
     * being an evaluated argument that runs BEFORE the pin. Measured on
     * the real guard before this fix.
     *
     * Arming is therefore tracked per closure BODY: a block closure from
     * its `{` to the matching `}`, an arrow fn from `=>` to the end of its
     * argument. Every write in this fixture is OUTSIDE any such body, so
     * every one must come back unpinned.
     */
    public function testADecoyClosureDoesNotPinAWriteBesideIt(): void
    {
        $fixture = self::fixture('decoy-closure-writes.php.txt');

        $rows = self::callSites([$fixture]);
        self::assertNotEmpty($rows, 'the decoy fixture must contain writes, or this proves nothing');

        $wronglyPinned = [];
        foreach ($rows as $row) {
            if ($row['pinned']) {
                $wronglyPinned[] = $row['site'] . ' (' . $row['fn'] . ')';
            }
        }

        self::assertSame(
            [],
            $wronglyPinned,
            "These writes are NOT inside a closure body, so they run BEFORE the pin,\n  "
            . implode("\n  ", $wronglyPinned)
            . "\nbut the guard reported them as pinned. That is the decoy hole."
        );
    }

    /** Fixtures live beside the guard, out of every scanned root. */
    private static function fixture(string $name): string
    {
        $path = self::fixtureDir() . '/' . $name;
        self::assertFileExists($path, "fixture {$name} is missing");

        return $path;
    }


    /**
     * ⛔ A VANISHING TEST MUST FAIL CI, not be noticed by someone counting.
     *
     * ⚠ This is here because it HAPPENED: regenerating this file dropped
     * `testACommentMentionIsNotACallSite` — 21 cases became 20 — and the
     * suite stayed GREEN. A deleted test is the one defect a passing run
     * cannot show you, and every other assertion in this class is only
     * worth what its continued existence is worth.
     *
     * ⭐ The SET is pinned, not the count: that names what disappeared and
     * catches a silent RENAME too, which a `>= 22` floor would not.
     *
     * ⚠ HONEST LIMIT, stated rather than hidden: this test cannot police
     * its own deletion. What it buys is that losing any OTHER case fails
     * loudly, so a quiet loss now requires deleting two things — which is
     * a deliberate act, not an accident of a build step.
     */
    public function testEveryNamedCaseStillExists(): void
    {
        $expected = [
            'testACommentMentionIsNotACallSite',
            'testADecoyClosureDoesNotPinAWriteBesideIt',
            'testEveryNamedCaseStillExists',
            'testEveryPostWriteIsPinned',
            'testEveryWriterCarriesItsEvidence',
            'testTheAllowlistIsEmpty',
            'testTheGroupConstructorFileSetIsReviewed',
            'testTheWalkFindsWritesAndSeesPins',
            'testTheWriterSetIsPinnedAgainstSilentShrinking',
        ];

        $actual = [];
        foreach ((new \ReflectionClass(self::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (str_starts_with($m->getName(), 'test')) {
                $actual[] = $m->getName();
            }
        }
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'a test case in this guard was added, removed or renamed. REMOVING one'
            . ' silently weakens the guard — the suite stays green while the check is'
            . ' gone, which is exactly how this file lost a case once already. Adding'
            . ' one is welcome: list it here too.'
        );
    }

    /** The allowlist is empty, and a test says so out loud. */
    public function testTheAllowlistIsEmpty(): void
    {
        self::assertSame([], self::ALLOWLIST, 'the allowlist must stay empty — see the constant\'s comment');
    }

    /**
     * ⚠⚠ THE GUARD'S OWN BLIND SPOT, made expensive.
     *
     * {@see WRITERS} is this guard's INPUT, so the guard cannot validate
     * it: delete an entry and every assertion still passes while the
     * guard goes blind to that whole class of write. That is not
     * hypothetical — a short writer list is exactly how the first pass
     * missed five sites.
     *
     * It cannot be fixed by a test (checking the list against itself
     * proves nothing), so the set is pinned here instead: shrinking it
     * means editing two places and saying why in review. A TRIPWIRE, not
     * a proof, and it is labelled as one rather than dressed up.
     */
    public function testTheWriterSetIsPinnedAgainstSilentShrinking(): void
    {
        $expected = [
            '_wp_put_post_revision',
            'check_and_publish_future_post',
            'media_handle_sideload',
            'media_handle_upload',
            'media_sideload_image',
            'wp_delete_attachment',
            'wp_delete_post',
            'wp_insert_attachment',
            'wp_insert_post',
            'wp_publish_post',
            'wp_restore_post_revision',
            'wp_save_post_revision',
            'wp_trash_post',
            'wp_untrash_post',
            'wp_update_post',
        ];

        $actual = array_keys(self::WRITERS);
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'the writer set changed. ADDING one is welcome — cite the WordPress source line'
            . ' that proves it reaches the action. REMOVING one makes this guard blind to a'
            . ' whole class of write, so it needs a reason in review, not a quiet edit.'
        );
    }

    /**
     * ⚠ THE PEEPSO-MODEL BACKSTOP, scoped so it stays maintainable.
     *
     * `->update(` cannot be flagged on its own: trust `app/` has 69
     * `->update(` call sites and 68 are `$wpdb->update(`. Measured, and
     * it is why this guard binds the method to a receiver assigned from
     * `new PeepSoGroup(` instead.
     *
     * The receiver binding is per FILE, so a group constructor appearing
     * in a NEW file is the case it could mis-scope. This assertion makes
     * that force a look: the set of files containing a 2-argument
     * `new PeepSoGroup(` is pinned to a reviewed list.
     *
     * ⛔ Scoped to CONSTRUCTOR files on purpose. ~60 files reference
     * `PeepSoGroup` at all, nearly all of them reads, and a 60-entry
     * fixture is one nobody maintains.
     */
    public function testTheGroupConstructorFileSetIsReviewed(): void
    {
        $base  = self::baseDir();
        $found = [];

        foreach (self::callSites(self::phpFiles()) as $row) {
            if ($row['fn'] === 'new PeepSoGroup(…, …)') {
                $found[] = explode(':', $row['site'])[0];
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        self::assertSame(
            [
                'app/Domain/Onchain/Services/GatedGroupProvisioningService.php',
                'app/Domain/Onchain/Services/HallProvisioningService.php',
                'app/Domain/Onchain/Services/ValidatorGroupProvisioningService.php',
            ],
            $found,
            'a 2-argument `new PeepSoGroup(` appeared in a file this list does not name'
            . ' (or left one). That constructor RUNS wp_insert_post (group.php:308), and the'
            . ' receiver binding for `->update(` is per file, so a new file needs a look'
            . ' rather than a silent pass.'
        );

        foreach ($found as $rel) {
            self::assertFileExists($base . '/' . $rel);
        }
    }

    /**
     * ⚠ Each writer is listed because WordPress's source says it reaches
     * the action. The citation is part of the entry so a reviewer can
     * check the claim instead of trusting the name.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function writerProvider(): array
    {
        $out = [];
        foreach (self::WRITERS as $fn => $why) {
            $out[] = [$fn, $why];
        }

        return $out;
    }

    #[DataProvider('writerProvider')]
    public function testEveryWriterCarriesItsEvidence(string $fn, string $why): void
    {
        self::assertNotSame('', $why, "{$fn} must cite why it reaches the action");
        self::assertMatchesRegularExpression(
            '/\b(post|media|revision)\.php:[\d-]+|its :\d+|calls \w+\(\)/',
            $why,
            "{$fn}'s evidence must name a source line or the function it calls"
        );
    }
}
