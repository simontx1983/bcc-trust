<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-275: every REST route that reads a feed turns a read that did not run
 * into 503 `bcc_unavailable`, never an empty page or a 404.
 *
 * Plugin::feedRankingService() builds bcc-core's ActivityFeedService to THROW
 * ActivityReadFailedException on a failed read. The profile Blog tab
 * (BlogService) and the cold-start panel's `recent_action` line
 * (FeedColdStartService) read bcc-core's repository directly and pass the
 * same flag. That is only safe if every
 * caller catches it: an uncaught exception in a REST callback is a PHP fatal
 * (a 500, and WordPress's recovery-mode email to the admin). So this pins:
 *
 *   1. the inventory: every REST method that calls the feed services, so a
 *      NEW route that forgets the catch fails here, not in production;
 *   2. the shape: each such call sits inside `try { … }` whose catch is
 *      exactly ActivityReadFailedException and returns FeedUnavailable.
 *
 * ⚠ This proves the code SAYS it. That it DOES it, against a real database
 * and the real bcc-core read, is PagePostsIntegrationTest's F-275 section.
 *
 * F-438: the ranking service's `viewerExclusions()` probe counts as a feed
 * read too. It throws the same exception, and a route calls it on its own
 * before a lookup (the shortcode permalink), so a route that probes without
 * the catch would be the same 500.
 *
 * Not REST, so not here: CronService::warmHotFeed() already catches
 * \Throwable around the warm, and the failed build throws before its cache
 * write (HotFeed tests).
 */
final class FeedReadFailureWiringTest extends TestCase
{
    /** file (under app/Domain/Core/REST) => methods that read a feed */
    private const GUARDED = [
        'FeedEndpoint.php'          => ['hot', 'tagFeed', 'feed', 'feedItemByShortcode', 'respondWithFeedItem'],
        'GroupsDetailEndpoint.php'  => ['feed'],
        'PagesEndpoint.php'         => ['feed'],
        // F-346: the repost/quote routes re-gate the ROOT per viewer through
        // the permalink's own seam, so they read a feed too.
        'RepostEndpoint.php'        => ['viewerCanReadRoot'],
        'UsersEndpoint.php'         => ['blog', 'activity'],
        'FeedColdStartEndpoint.php' => ['handle'],
    ];

    /**
     * A feed read: the ranking service's getters and its viewerExclusions()
     * probe (F-438), the cold-start panel, or the profile Blog tab. Only
     * `getUserBlog` on the blog service: its other getter (`getBlogForEdit`,
     * the editor's single-draft read) does not read the activity stream and
     * does not throw.
     */
    private const CALL = '/(?:(?:feedRankingService\(\)|feedColdStartService\(\))\s*->\s*(?:get\w*|viewerExclusions)|blogService\(\)\s*->\s*getUserBlog)\s*\(/';

    /**
     * `try { … } catch (ActivityReadFailedException) { … }`, capturing both
     * bodies. Neither may contain a brace, which holds for every guarded
     * call today; a call wrapped in a nested block fails loudly here rather
     * than passing on a guess.
     */
    private const TRY_CATCH = '/try\s*\{([^{}]*)\}\s*catch\s*\(\s*\\\\?(?:BCC\\\\Core\\\\Contracts\\\\)?ActivityReadFailedException(?:\s+\$\w+)?\s*\)\s*\{([^{}]*)\}/';

    public function testEveryRestMethodThatReadsAFeedIsInTheInventory(): void
    {
        $found = [];
        foreach (glob(self::appRoot() . '/Domain/*/REST/*.php') ?: [] as $path) {
            foreach (self::methodBodies((string) file_get_contents($path)) as $method => $body) {
                if (preg_match(self::CALL, $body) === 1) {
                    $found[basename($path)][] = $method;
                }
            }
        }
        ksort($found);
        $expected = self::GUARDED;
        ksort($expected);

        self::assertSame(
            $expected,
            $found,
            'A REST method reads a feed but is not in GUARDED: give it the F-275 catch and list it.'
        );
    }

    public function testEveryFeedReadIsCaughtAndAnswersFeedUnavailable(): void
    {
        foreach (self::GUARDED as $file => $methods) {
            $bodies = self::methodBodies((string) file_get_contents(self::appRoot() . '/Domain/Core/REST/' . $file));
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $bodies, "{$file}::{$method}() not found");
                self::assertSame([], self::unguardedCalls($bodies[$method]), "{$file}::{$method}()");
            }
        }
    }

    // ── the matcher's own controls ──────────────────────────────────────

    public function testTheMatcherReportsAnUnguardedCall(): void
    {
        $body = '{ $p = Plugin::instance()->feedRankingService()->getFeed($v, $s); return ApiResponse::ok($p); }';

        self::assertCount(1, self::unguardedCalls($body));
    }

    /** A catch-all would also swallow real bugs; it is not the F-275 guard. */
    public function testTheMatcherRejectsACatchAll(): void
    {
        $body = '{ try { $p = Plugin::instance()->feedRankingService()->getFeed($v, $s); } catch (\Throwable) { return FeedUnavailable::response(); } }';

        self::assertCount(1, self::unguardedCalls($body));
    }

    /** Caught, but answered with an empty page: the defect, restated. */
    public function testTheMatcherRejectsACatchThatAnswersSomethingElse(): void
    {
        $body = '{ try { $p = Plugin::instance()->feedRankingService()->getFeed($v, $s); } catch (ActivityReadFailedException) { return ApiResponse::ok([\'items\' => []]); } }';

        self::assertCount(1, self::unguardedCalls($body));
    }

    /** The Blog tab's read is a feed read; the editor's draft read is not. */
    public function testTheMatcherSeesTheBlogTabReadButNotTheDraftRead(): void
    {
        $tab   = '{ $p = Plugin::instance()->blogService()->getUserBlog($u, $v, $c, $l); return ApiResponse::ok($p); }';
        $draft = '{ $r = Plugin::instance()->blogService()->getBlogForEdit($id, $v); return ApiResponse::ok($r); }';

        self::assertCount(1, self::unguardedCalls($tab));
        self::assertSame([], self::unguardedCalls($draft));
    }

    /** F-438: the exclusion probe is a feed read, and needs the same guard. */
    public function testTheMatcherSeesTheExclusionProbe(): void
    {
        $bare    = '{ $x = Plugin::instance()->feedRankingService()->viewerExclusions($v); return ApiResponse::ok([]); }';
        $guarded = '{ try { $x = Plugin::instance()->feedRankingService()->viewerExclusions($v); } catch (ActivityReadFailedException) { return FeedUnavailable::response(); } return ApiResponse::ok([]); }';

        self::assertCount(1, self::unguardedCalls($bare));
        self::assertSame([], self::unguardedCalls($guarded));
    }

    public function testTheMatcherAcceptsTheGuard(): void
    {
        $body = '{ try { $p = Plugin::instance()->feedRankingService()->getFeed($v, $s); } catch (ActivityReadFailedException) { return FeedUnavailable::response(); } return ApiResponse::ok($p); }';

        self::assertSame([], self::unguardedCalls($body));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private static function appRoot(): string
    {
        return dirname(__DIR__, 2) . '/app';
    }

    /**
     * Offsets of feed reads in `$body` that are NOT inside a guarded try.
     *
     * @return list<int>
     */
    private static function unguardedCalls(string $body): array
    {
        $guarded = [];
        preg_match_all(self::TRY_CATCH, $body, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($blocks as $block) {
            if (!str_contains($block[2][0], 'return FeedUnavailable::response(')) {
                continue;
            }
            $guarded[] = [$block[1][1], $block[1][1] + strlen($block[1][0])];
        }

        preg_match_all(self::CALL, $body, $calls, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($calls[0] as [, $offset]) {
            $inside = false;
            foreach ($guarded as [$from, $to]) {
                if ($offset >= $from && $offset < $to) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                $out[] = $offset;
            }
        }

        return $out;
    }

    /**
     * Method name => its body source, by a token walk (not a regex, so
     * braces in strings and comments cannot shift a boundary).
     *
     * @return array<string, string>
     */
    private static function methodBodies(string $source): array
    {
        $tokens = token_get_all($source);
        $count  = count($tokens);
        $out    = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = self::declaredName($tokens, $i);
            if ($name === null) {
                continue; // a closure
            }

            // The body opens at the first `{` (an abstract method has none).
            $j = $i + 1;
            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }
            if ($j >= $count || $tokens[$j] === ';') {
                continue;
            }

            $depth = 0;
            $body  = '';
            for (; $j < $count; $j++) {
                $t    = $tokens[$j];
                $text = is_array($t) ? $t[1] : $t;
                $body .= $text;
                if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $out[$name] = $body;
            $i          = $j;
        }

        return $out;
    }

    /**
     * The declared name after `function`, or null for a closure. Accepts a
     * reserved word as the name (`function list(` is T_LIST, not T_STRING),
     * as ProfileGateWiringTest learned.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function declaredName(array $tokens, int $i): ?string
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($t === '&' || (is_array($t) && $t[1] === '&')) {
                continue;
            }
            if (is_array($t) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $t[1]) === 1) {
                return $t[1];
            }

            return null;
        }

        return null;
    }
}
