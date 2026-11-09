<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * There is no unfiltered "top collections by chain type" reader, under any
 * spelling.
 *
 * ── WHAT WAS REMOVED, AND WHY A TEST GUARDS A DELETION ──────────────────
 * `CollectionRepository::getTopCollectionsByChainType()` selected EVERY
 * collection row of a given chain type:
 *
 *     WHERE ch.chain_type = %s
 *     ORDER BY c.{$orderBy} DESC
 *
 * No `is_verified` predicate, no `wallet_link_id` predicate, no
 * `show_on_profile` predicate. It had zero callers — not one, in bcc-trust,
 * bcc-core, bcc-search or bcc-frontend, tests included — so its removal
 * changed no behaviour at all. See #207.
 *
 * A deletion with no callers normally needs no test. This one gets one
 * because the risk was never that it RAN; it was that it READ as safe. A
 * method called `getTopCollectionsByChainType` returning
 * `{items, total, pages}` looks exactly like the curated list a future
 * "discover collections" surface would reach for, and #204 is the record of
 * what happens when a selector with no `is_verified` predicate gets wired
 * into a user-facing path: CosmWasm discovery writes unverified candidates
 * automatically, so every row an automated scanner produced became a public
 * gallery item. Discovery is an operator INTAKE QUEUE. Landing in it is not
 * permission to be displayed.
 *
 * ── WHY SOURCE READING AND NOT REFLECTION ───────────────────────────────
 * The same choice `CollectionUpsertWriteShapeTest` makes: reading the file
 * needs no autoloader and no WordPress, so this runs in the unit suite where
 * it will actually be seen. Reflection would prove the method is absent from
 * the class; it would say nothing about the SQL SHAPE, which is the part
 * worth freezing.
 *
 * Every assertion here is two-sided. A probe that finds nothing because it
 * looked in the wrong place reads exactly like a clean result, and this
 * repository has shipped that failure before.
 */
final class CollectionChainTypeReaderRemovedTest extends TestCase
{
    /** The repository the method was removed from. */
    private const REPOSITORY = 'app/Domain/Onchain/Repositories/CollectionRepository.php';

    /** A reader that IS present, and must be, for the anti-vacuity controls. */
    private const SURVIVING_READER = 'listVerifiedByChain';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        $src  = file_get_contents($path);

        self::assertIsString($src, $relative . ' must be readable');
        self::assertNotSame('', $src, 'anti-vacuity: ' . $relative . ' must not be empty');

        return $src;
    }

    public function testTheUnfilteredChainTypeReaderIsGoneFromTheRepository(): void
    {
        $src = self::source(self::REPOSITORY);

        // Anti-vacuity FIRST: prove this file is the one that holds the
        // collection readers, so an absence below means something.
        self::assertStringContainsString(
            'public static function ' . self::SURVIVING_READER . '(',
            $src,
            'anti-vacuity: ' . self::REPOSITORY . ' must still declare '
                . self::SURVIVING_READER . '() — if it does not, this test is '
                . 'reading the wrong file and every absence it reports is meaningless'
        );

        self::assertStringNotContainsString(
            'getTopCollectionsByChainType',
            $src,
            'the unfiltered chain-type reader must stay deleted (#207)'
        );
    }

    /**
     * The SQL shape, not just the name. A reader reintroduced under a
     * different name would defeat the assertion above and nothing else.
     */
    public function testNoReaderSelectsCollectionsByChainTypeAlone(): void
    {
        $src = self::source(self::REPOSITORY);

        // Anti-vacuity: the file must still contain prepared predicates at
        // all, or "no chain_type predicate" is true of an empty string.
        self::assertMatchesRegularExpression(
            '/\bis_verified\s*=\s*1\b/',
            $src,
            'anti-vacuity: the repository must still carry is_verified predicates'
        );

        self::assertDoesNotMatchRegularExpression(
            '/chain_type\s*=\s*%s/',
            $src,
            'a collections reader must not filter by chain_type alone: that returns '
                . 'every row of a chain family, including unverified rows an automated '
                . 'scanner wrote. Scope the read instead (is_verified / wallet_link_id / '
                . 'show_on_profile), as listVerifiedByChain() does. See #207 and #204.'
        );
    }

    /**
     * Gone from the plugin, not merely from one file — a leftover call site
     * would be a fatal, and a leftover re-declaration elsewhere would be the
     * same hazard wearing a different path.
     */
    public function testTheNameAppearsNowhereInTheShippedTree(): void
    {
        $roots = ['app', 'includes'];
        $files = [];

        foreach ($roots as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    self::root() . '/' . $dir,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        // Anti-vacuity: a sweep that walked nothing reports zero hits.
        self::assertGreaterThan(
            200,
            count($files),
            'anti-vacuity: the sweep must actually find the plugin sources '
                . '(found ' . count($files) . ')'
        );

        $offenders = [];
        $control   = 0;

        foreach ($files as $path) {
            $src = (string) file_get_contents($path);

            if (str_contains($src, 'getTopCollectionsByChainType')) {
                $offenders[] = substr($path, strlen(self::root()) + 1);
            }

            // The same read mechanism, on a string that must be found.
            if (str_contains($src, 'CollectionRepository')) {
                $control++;
            }
        }

        self::assertGreaterThan(
            0,
            $control,
            'anti-vacuity: the sweep must be able to find a string that IS present'
        );

        self::assertSame(
            [],
            $offenders,
            'getTopCollectionsByChainType reappeared in: ' . implode(', ', $offenders)
        );
    }
}
