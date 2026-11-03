<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A validator profile shows the CURRENT moniker, falling back to the stored
 * post title.
 *
 * ── THE DEFECT THIS CLOSES, MEASURED ─────────────────────────────────────
 * `ValidatorPageMinter` writes `post_title` once, at mint, and nothing ever
 * resyncs it — there is no `wp_update_post` anywhere in `Domain/Onchain`. The
 * card then read `$name = (string) $post->post_title`, so a validator that
 * renamed on-chain kept its old name on BCC indefinitely.
 *
 * Measured on production: 61 of 2,906 validator pages were showing a stale
 * name, and some were actively misleading rather than merely dated —
 *
 *   page 3680  showed "Cosmostation"           for "[Shutting down] cosmostation please redelegate"
 *   page 3682  showed "Kraken01"               for "Kraken Decommissioning node, re-delegate to another node"
 *   page 3683  showed "Ledger by Chorus One"   for "Ledger by Bitwise"
 *
 * The first two render a healthy-looking name for an operator telling its
 * delegators to leave, which is the case that makes this a correctness bug
 * and not a cosmetic one. Those three are provider rows below.
 *
 * ── ⚠ WHAT IS DELIBERATELY NOT CHANGED ───────────────────────────────────
 * Page identity. `$handle` is still `post_name`, the slug is still frozen at
 * mint, and nothing here writes to `wp_posts`. Freezing the slug is exactly
 * what makes a shared `/v/<slug>` link survive a rename — only the DISPLAYED
 * name was wrong. A test below pins both halves of that.
 */
#[CoversClass(CardViewService::class)]
final class ValidatorProfileMonikerTest extends TestCase
{
    /**
     * The whole rule, by value.
     *
     * @param string|null $moniker
     */
    #[DataProvider('nameCases')]
    public function testTheDisplayedNamePrefersTheCurrentMoniker(
        string $label,
        ?string $moniker,
        string $storedTitle,
        string $expected
    ): void {
        self::assertSame($expected, self::prefer($moniker, $storedTitle), $label);
    }

    /** @return list<array{0:string,1:?string,2:string,3:string}> */
    public static function nameCases(): array
    {
        return [
            // ⛔ The required case: a stale title with a newer moniker.
            ['a renamed validator', 'Ledger by Bitwise', 'Ledger by Chorus One', 'Ledger by Bitwise'],
            [
                'a validator shutting down',
                '[Shutting down] cosmostation please redelegate',
                'Cosmostation',
                '[Shutting down] cosmostation please redelegate',
            ],
            [
                'a validator decommissioning',
                'Kraken Decommissioning node, re-delegate to another node',
                'Kraken01',
                'Kraken Decommissioning node, re-delegate to another node',
            ],

            // ⛔ The required fallback: no moniker available.
            ['no moniker at all (null)', null, 'Minted Title', 'Minted Title'],
            ['an empty moniker', '', 'Minted Title', 'Minted Title'],
            ['a whitespace-only moniker', "   \t  ", 'Minted Title', 'Minted Title'],

            // ⚠ Production carries monikers with real leading whitespace.
            ['a padded moniker is trimmed', ' polkachu.com', 'polkachu.com', 'polkachu.com'],
            ['a padded renamed moniker', '  Stakecito Labs  ', 'Stakecito', 'Stakecito Labs'],

            // ⚠ THE FALSY-STRING TRAP. '0' is a legal moniker and is falsy in
            // PHP, so an `empty()` or `?:` test would silently fall back to the
            // stale title. Only a `!== ''` comparison keeps it.
            ['the moniker "0"', '0', 'Zero', '0'],

            // Unchanged when they already agree.
            ['moniker equals title', 'Chorus One', 'Chorus One', 'Chorus One'],
        ];
    }

    /**
     * ⚠ The fallback is returned EXACTLY as stored, not trimmed. Trimming it
     * would quietly change what an un-enriched page and every non-validator
     * card renders, which is outside what this change is allowed to touch.
     */
    public function testTheFallbackTitleIsReturnedVerbatim(): void
    {
        self::assertSame('  Padded Title  ', self::prefer(null, '  Padded Title  '));
        self::assertSame('', self::prefer(null, ''), 'an empty title stays empty rather than becoming something');
    }

    /**
     * ⛔ PAGE IDENTITY IS UNTOUCHED, asserted rather than asserted-in-prose.
     * `handle` must still be the slug, and this service must not acquire the
     * ability to rename a page.
     */
    public function testPageIdentityIsNotTouched(): void
    {
        // ⚠ COMMENT-STRIPPED, and the first run of this case proved why:
        // the only `wp_update_post` in this file is the word inside the new
        // comment explaining that there is no wp_update_post. Asserting over
        // raw source let the file's own prose defeat the check.
        $code = self::codeOnly(self::source());

        self::assertStringContainsString(
            "\$handle = (string) \$post->post_name;",
            $code,
            'the handle must stay the stored slug'
        );
        self::assertStringNotContainsString(
            'wp_update_post',
            $code,
            'nothing in the card service may rewrite a page'
        );
        self::assertStringNotContainsString(
            "'post_name'",
            $code,
            'and it must not write a slug either'
        );
    }

    /**
     * ⚠⚠ THE OVERRIDE MUST BE KIND-GATED AND BUNDLE-FIRST, OR IT IS AN N+1.
     *
     * `CardsListEndpoint` renders the directory grid through
     * `getPageCardForList()` → `getPageCard()`, so an unconditional per-card
     * lookup would add one query per card. The prefetched
     * `findCardRowsByPageIds()` bundle now carries `moniker`, so a list
     * request must read it from there and never reach the repository; only a
     * single-card request may fall through. And a project or creator card must
     * not pay for a lookup that cannot apply to it.
     */
    public function testTheMonikerLookupIsKindGatedAndPrefersThePrefetchedBundle(): void
    {
        $code = self::source();

        $gate = strpos($code, "if (\$kind === 'validator') {");
        self::assertNotFalse($gate, 'the override must be gated on the validator kind');

        $bundle = strpos($code, 'isset($validatorRows[0]->moniker)');
        $query  = strpos($code, 'ValidatorRepository::findMonikerByPageId($pageId)');

        self::assertNotFalse($bundle, 'the prefetched bundle must be consulted');
        self::assertNotFalse($query, 'a single-card read must still resolve a moniker');

        self::assertGreaterThan($gate, $bundle, 'the bundle read must sit inside the kind gate');
        self::assertLessThan(
            $query,
            $bundle,
            'the bundle must be preferred OVER the query, or the directory grid pays one query per card'
        );

        self::assertSame(
            1,
            substr_count($code, 'findMonikerByPageId'),
            'exactly one call site, so no other path can reintroduce the per-card query'
        );
    }

    /**
     * The batch that already serves chains, signals and the logo now carries
     * the name too — that is what makes the list path free.
     */
    public function testTheBatchSelectCarriesTheMoniker(): void
    {
        $repo = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Repositories/ValidatorRepository.php'
        );
        self::assertNotSame('', $repo);

        // ⛔ ASSERT INSIDE THE BATCH SELECT, NOT ANYWHERE IN THE FILE.
        // A mutation control proved the weaker form worthless: `v.moniker`
        // appears EIGHT times here and six of those predate this change, so
        // deleting it from $selectCols left the file still containing the
        // string and the assertion still green. Scope it to the one select
        // that feeds the prefetched bundle.
        $fn = strpos($repo, 'public static function findCardRowsByPageIds');
        self::assertNotFalse($fn, 'the batch method must exist');

        $assign = strpos($repo, '$selectCols =', $fn);
        self::assertNotFalse($assign, 'the batch must build a column list');

        $end = strpos($repo, '";', $assign);
        self::assertNotFalse($end, 'the column list must terminate');

        $selectCols = substr($repo, $assign, $end - $assign);

        self::assertStringContainsString(
            'v.moniker',
            $selectCols,
            'the batch select itself must fetch the moniker, or the directory '
                . 'grid pays one query per card for the name'
        );
        self::assertStringNotContainsString(
            'SELECT *',
            $selectCols,
            'and it must stay an explicit column list'
        );
        // ⚠ NOT substr_count('moniker: string'). That also matches the
        // `moniker: string|null` already declared on six other row shapes in
        // this file, which is why the first run of this case read 12 and not 2.
        self::assertSame(
            2,
            preg_match_all('/moniker: string(?!\|)/', $repo),
            'both ValidatorCardRow and ValidatorCardPageRow must declare a '
                . 'non-null moniker, since one $selectCols feeds both'
        );
    }

    /**
     * ⚠ The single-card reader must be bounded and parameterised, per §2.
     */
    public function testTheSingleCardReaderIsBoundedAndParameterised(): void
    {
        $repo = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Repositories/ValidatorRepository.php'
        );

        $start = strpos($repo, 'public static function findMonikerByPageId');
        self::assertNotFalse($start);
        $body = substr($repo, $start, 1600);

        self::assertSame(2, substr_count($body, 'LIMIT 1'), 'both lookups must be bounded');
        self::assertSame(2, substr_count($body, '$wpdb->prepare('), 'both must be prepared');
        self::assertStringNotContainsString('SELECT *', $body, 'no SELECT *');
        self::assertStringContainsString('if ($pageId <= 0) {', $body, 'it must refuse a non-id');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** The pure chooser, invoked for real. */
    private static function prefer(?string $moniker, string $fallback): string
    {
        $m = new \ReflectionMethod(CardViewService::class, 'preferMoniker');
        $m->setAccessible(true);

        return (string) $m->invoke(null, $moniker, $fallback);
    }

    /**
     * Comments removed, so the file's own prose can neither satisfy nor
     * defeat a structural check.
     */
    private static function codeOnly(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    private static function source(): string
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/CardViewService.php'
        );
        self::assertNotFalse($src, 'could not read CardViewService.php');
        self::assertNotSame('', $src);

        return (string) $src;
    }
}
