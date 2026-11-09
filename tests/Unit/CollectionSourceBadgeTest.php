<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Verify Collections source badge tells the operator the truth, and the
 * STORED value it keys on does not move.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * Rows written by an automated job carry `source = 'toplist'`, a label left
 * over from the pre-2026 Stargaze marketplace importer, and the admin badge
 * rendered that value as **"Top list"**. On production that is 218 rows, 190
 * of them still unverified — and this screen is the one place an operator
 * decides whether to verify them. "Top list" implies a curated marketplace
 * ranking; the row is the opposite, an automated write nobody has reviewed.
 * See #206.
 *
 * ── WHY THIS IS A LABEL CHANGE AND NOT A DATA CHANGE ────────────────────
 * The obvious "proper" fix — give CosmWasm emission its own `source` value —
 * is the one that breaks things, and that is what this test exists to stop:
 *
 *   - `SolanaGateIdentityManifest::EXPECTED_SOURCE` is `'toplist'`, asserted
 *     on every row BEFORE and AFTER the Solana gate repair. A renamed value
 *     makes that repair refuse.
 *   - `CollectionRepository::bulkUpsert()` writes the literal `'toplist'`.
 *   - the badge map KEYS on `'toplist'`. Rename the key without migrating
 *     the rows and all 218 fall through to the `??` default, silently
 *     mislabelled again — the same defect, one step further from view.
 *
 * So the value on disk is pinned here deliberately. Changing it is a
 * migration with its own review, not a copy fix.
 *
 * Source-reading rather than render-stubbing, matching
 * `CollectionUpsertWriteShapeTest`: no autoloader, no WordPress, so it runs
 * in the unit suite where it will be seen. Every assertion is two-sided.
 */
final class CollectionSourceBadgeTest extends TestCase
{
    private const ADMIN_PAGE = 'app/Domain/Onchain/Admin/VerifyCollectionsPage.php';
    private const REPOSITORY = 'app/Domain/Onchain/Repositories/CollectionRepository.php';
    private const MANIFEST   = 'app/Domain/Onchain/Repair/SolanaGateIdentityManifest.php';

    /** The value on disk. Not the label. */
    private const STORED_VALUE = 'toplist';

    private static function source(string $relative): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);

        self::assertIsString($src, $relative . ' must be readable');
        self::assertNotSame('', $src, 'anti-vacuity: ' . $relative . ' must not be empty');

        return $src;
    }

    public function testTheBadgeNoLongerClaimsACuratedTopList(): void
    {
        $src = self::source(self::ADMIN_PAGE);

        // Anti-vacuity: the badge map must still be in this file.
        self::assertStringContainsString(
            "'manual'    => ['Manual',",
            $src,
            'anti-vacuity: the source-badge map must still be present — if it is not, '
                . 'this test is asserting the absence of a string from a file that no '
                . 'longer renders badges at all'
        );

        self::assertStringNotContainsString(
            "'Top list'",
            $src,
            'the badge must not read "Top list": it implies a curated marketplace '
                . 'ranking for a row an automated job wrote and nobody reviewed (#206)'
        );

        self::assertStringContainsString(
            "'Auto-added'",
            $src,
            'the toplist arm must carry a label that names the row as automatic'
        );
    }

    /**
     * The label changed; the key did not. If the key moves without a data
     * migration, every stored row falls through to the default.
     */
    public function testTheBadgeStillKeysOnTheStoredValue(): void
    {
        $src = self::source(self::ADMIN_PAGE);

        self::assertMatchesRegularExpression(
            "/'" . self::STORED_VALUE . "'\s*=>\s*\['Auto-added'/",
            $src,
            'the badge arm must still be keyed by the STORED value '
                . "'" . self::STORED_VALUE . "' — renaming the key without migrating the "
                . 'rows drops all of them through to the `??` default and mislabels them again'
        );
    }

    /**
     * Every arm, and the fallback, must supply all three elements. The
     * fallback is the easy one to forget, and `$badge[2]` undefined is a
     * warning on every row with an unrecognised source.
     */
    public function testEveryBadgeArmSuppliesLabelColourAndTooltip(): void
    {
        $src = self::source(self::ADMIN_PAGE);

        $start = strpos($src, '$badge = [');
        self::assertIsInt($start, 'the badge map must be findable');

        $end = strpos($src, '][$source] ??', $start);
        self::assertIsInt($end, 'the badge map must end with a null-coalesced default');

        $map = substr($src, $start, $end - $start);

        preg_match_all("/=>\s*\[(.*?)\],/", $map, $arms);
        self::assertGreaterThanOrEqual(
            3,
            count($arms[1]),
            'anti-vacuity: the map must contain the three known arms (found '
                . count($arms[1]) . ')'
        );

        foreach ($arms[1] as $arm) {
            self::assertSame(
                3,
                substr_count($arm, "'") / 2,
                'each badge arm needs label, colour and tooltip: ' . $arm
            );
        }

        // The fallback, which the loop above deliberately does not cover.
        $tail = substr($src, $end, 220);
        self::assertSame(
            3,
            substr_count(substr($tail, 0, (int) strpos($tail, '];')), "'") / 2,
            'the `??` fallback needs all three elements too, or $badge[2] is undefined '
                . 'for any unrecognised source value: ' . $tail
        );

        self::assertStringContainsString(
            'title="<?php echo esc_attr($badge[2]); ?>"',
            $src,
            'the tooltip must actually be rendered, and escaped'
        );
    }

    /**
     * The two places that would break if the stored value were renamed as a
     * side-effect of a copy fix.
     */
    public function testTheStoredValueIsUnchangedInItsWriterAndItsAsserter(): void
    {
        $repository = self::source(self::REPOSITORY);
        $manifest   = self::source(self::MANIFEST);

        self::assertStringContainsString(
            "'" . self::STORED_VALUE . "'",
            $repository,
            'bulkUpsert() must still write the stored value unchanged — this fix is '
                . 'display-only and writes no rows'
        );

        self::assertMatchesRegularExpression(
            "/public const EXPECTED_SOURCE\s*=\s*'" . self::STORED_VALUE . "'/",
            $manifest,
            'SolanaGateIdentityManifest::EXPECTED_SOURCE asserts the stored value on '
                . 'every row before and after the Solana gate repair; renaming the value '
                . 'makes that repair refuse'
        );
    }
}
