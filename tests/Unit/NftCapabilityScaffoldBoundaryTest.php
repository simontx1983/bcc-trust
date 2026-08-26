<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * THE BOUNDARY OF PR 2 — asserted against the source, not against intent.
 *
 * ── WHY THESE ARE SOURCE ASSERTIONS ─────────────────────────────────────
 * Everything this file pins is a NEGATIVE: no chain is seeded, no hook is
 * created, no production surface reads the new permission. Negatives about
 * "what the system does not do" cannot be proven by exercising behaviour —
 * you can only observe the behaviour you thought to invoke. A grep over the
 * shipped files is the honest instrument, and it is the same one
 * {@see AutomaticNftDiscoveryRetiredTest} uses to pin PR 1's removals.
 *
 * These tests are deliberately annoying to change. Each one should fail the
 * moment a later PR legitimately crosses the boundary — at which point the
 * assertion is updated in that PR, visibly, rather than the boundary eroding
 * quietly.
 */
final class NftCapabilityScaffoldBoundaryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        self::assertFileExists($path, "expected {$relative} to exist");

        return (string) file_get_contents($path);
    }

    /**
     * Every tracked PHP file under app/ and includes/.
     *
     * @return list<string> absolute paths
     */
    private static function productionPhpFiles(): array
    {
        $files = [];
        foreach (['app', 'includes'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Files whose EXECUTABLE source contains $needle — comments stripped.
     *
     * ── WHY COMMENTS ARE STRIPPED ───────────────────────────────────────
     * Every assertion in this file is about what the code DOES. Prose that
     * names a withdrawn design in order to explain why it was withdrawn, or
     * a docblock that points at the class which consumes a value, is exactly
     * the documentation this codebase wants — and a raw `str_contains` would
     * punish it, pushing the next author to delete the explanation to get a
     * green build. That is the opposite of the intended effect.
     *
     * So the haystack is the token stream with `T_COMMENT` and
     * `T_DOC_COMMENT` removed: a mention in prose is free, a mention in code
     * is a finding.
     *
     * @return list<string> repo-relative paths whose code contains $needle
     */
    private static function filesContaining(string $needle): array
    {
        $hits = [];
        foreach (self::productionPhpFiles() as $path) {
            if (str_contains(self::codeWithoutComments($path), $needle)) {
                $hits[] = str_replace('\\', '/', substr($path, strlen(self::root()) + 1));
            }
        }

        return $hits;
    }

    /** The file's source with all comments and docblocks removed. */
    private static function codeWithoutComments(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    // ── The migration enables nothing ───────────────────────────────────

    /**
     * NO CHAIN IS SEEDED. The single most important assertion in this PR.
     *
     * The migration adds two columns at DEFAULT 0 and stops. An
     * `UPDATE ... SET bcc_supports_nft_collections = 1` for the chains the
     * architecture plan lists as NFT-capable would be a defensible-looking
     * change and an out-of-scope decision: it would enable Dungeon, and it
     * would author product support in a PR where nothing reads it.
     */
    public function testNoMigrationSetsEitherCapabilityColumnToOne(): void
    {
        $sources = [
            'includes/database/schema-chains.php',
            'includes/database/schema-chain-nft-capabilities.php',
            'bcc-trust.php',
        ];

        foreach ($sources as $relative) {
            $src = self::read($relative);

            foreach (['bcc_supports_nft_collections', 'manual_collection_discovery_enabled'] as $column) {
                self::assertDoesNotMatchRegularExpression(
                    '/UPDATE[^;]*' . preg_quote($column, '/') . '\s*=\s*1/is',
                    $src,
                    "{$relative} must not enable {$column} for any chain"
                );
                self::assertDoesNotMatchRegularExpression(
                    '/SET\s+' . preg_quote($column, '/') . '\s*=\s*1/i',
                    $src,
                    "{$relative} must not set {$column} to 1"
                );
            }
        }
    }

    public function testBothCapabilityColumnsAreDeclaredDefaultZero(): void
    {
        $src = self::read('includes/database/schema-chains.php');

        self::assertStringContainsString(
            'ADD COLUMN {$column} TINYINT(1) NOT NULL DEFAULT 0 AFTER {$after}',
            $src,
            'both capability columns must be added NOT NULL DEFAULT 0'
        );
    }

    /**
     * The override table's `enabled` also defaults to 0.
     *
     * An absent ROW means "registry default applies", so a row that exists
     * must default to the restrictive value — otherwise a blank row
     * accidentally inserted would re-enable something an operator turned off.
     */
    public function testOverrideRowsDefaultToDisabled(): void
    {
        self::assertStringContainsString(
            'enabled TINYINT(1) NOT NULL DEFAULT 0',
            self::read('includes/database/schema-chain-nft-capabilities.php')
        );
    }

    /**
     * There is no permanent "still zero" postcondition.
     *
     * Such a check is true only on the first run. Once a later PR
     * legitimately enables a chain, it would report correct configuration as
     * a migration failure — and the obvious "fix" would silently revert an
     * operator's decision on every deploy. Shape is the permanent invariant;
     * emptiness is pinned in the integration test that observes a fresh
     * install instead.
     */
    public function testMigrationDoesNotAssertAPermanentZeroCount(): void
    {
        foreach (['includes/database/schema-chains.php', 'includes/database/schema-chain-nft-capabilities.php'] as $relative) {
            self::assertDoesNotMatchRegularExpression(
                '/COUNT\(\*\)[^;]*WHERE[^;]*(bcc_supports_nft_collections|manual_collection_discovery_enabled)/is',
                self::read($relative),
                "{$relative} must not make a value-based postcondition on a capability column"
            );
        }
    }

    /** Shape IS verified — existence, type, nullability, default. */
    public function testMigrationVerifiesColumnShape(): void
    {
        $src = self::read('includes/database/schema-chains.php');

        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $src);
        self::assertStringContainsString('DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT', $src);
        self::assertStringContainsString('bcc_onchain_verify_tinyint_default_zero', $src);
    }

    /** The table's UNIQUE key is verified through INFORMATION_SCHEMA, not trusted to dbDelta. */
    public function testTableMigrationVerifiesItsUniqueKey(): void
    {
        $src = self::read('includes/database/schema-chain-nft-capabilities.php');

        self::assertStringContainsString('INFORMATION_SCHEMA.STATISTICS', $src);
        self::assertStringContainsString('uq_chain_op_driver', $src);
        // wpdb::query() returns 0 on successful DDL, so `=== false` is the
        // only correct failure test. A truthiness check would read every
        // successful ALTER as a failure.
        self::assertStringContainsString('$added === false', $src);
    }

    /** AF-6 — the projection cache is cleared after the columns land. */
    public function testMigrationClearsTheChainProjectionCache(): void
    {
        self::assertMatchesRegularExpression(
            '/bcc_onchain_add_chains_nft_capability_columns.*ChainRepository::clearCache/s',
            self::read('includes/database/schema-chains.php'),
            'a missed clearCache leaves every chain reading UNKNOWN for the cache TTL, silently'
        );
    }

    // ── No retired PR 1 hook is recreated ───────────────────────────────

    /**
     * PR 1 retired five recurring discovery hooks and deleted the
     * self-healing registration that used to resurrect them on every
     * request. Nothing in PR 2 may name them.
     */
    public function testNoPr2FileMentionsARetiredDiscoveryHook(): void
    {
        $retired = [
            'bcc_index_collections',
            'bcc_cosmwasm_backfill_tick',
            'bcc_cosmwasm_daily_discovery',
            'bcc_cosmwasm_weekly_retry',
            'bcc_cosmwasm_metadata_refresh',
        ];

        foreach (self::pr2Files() as $relative) {
            $src = self::read($relative);
            foreach ($retired as $hook) {
                self::assertStringNotContainsString(
                    $hook,
                    $src,
                    "{$relative} must not reference the retired hook {$hook}"
                );
            }
        }
    }

    /**
     * PR 2 schedules nothing. The capability model is read by humans
     * pressing buttons; it has no background component, and the job runner
     * that will have one is a later PR.
     */
    public function testNoPr2FileSchedulesAnything(): void
    {
        foreach (self::pr2Files() as $relative) {
            $src = self::read($relative);
            foreach (['wp_schedule_event', 'wp_schedule_single_event', 'wp_next_scheduled'] as $fn) {
                self::assertStringNotContainsString(
                    $fn,
                    $src,
                    "{$relative} must not touch the cron schedule"
                );
            }
        }
    }

    /** @return list<string> the files this PR adds or meaningfully changes */
    private static function pr2Files(): array
    {
        return [
            'app/Domain/Onchain/Support/AlchemyEndpoint.php',
            'app/Domain/Onchain/Support/HeliusEndpoint.php',
            'app/Domain/Onchain/Support/NftDriverRegistry.php',
            'app/Domain/Onchain/Support/NftProviderReadiness.php',
            'app/Domain/Onchain/Support/NftChainCapability.php',
            'app/Domain/Onchain/Repositories/ChainNftCapabilityRepository.php',
            'includes/database/schema-chain-nft-capabilities.php',
            'includes/database/schema-probe.php',
        ];
    }

    // ── The scaffold is unread ──────────────────────────────────────────

    /**
     * NO PRODUCTION SURFACE READS THE PERMISSION YET.
     *
     * PR 2 ships the model; PR 4/5 wire it. Until then the only places the
     * permission column may appear are the class that interprets it, the
     * projection that carries it, and the migration that creates it.
     *
     * The failure this guards against is a well-meaning "while I'm here"
     * edit that makes some existing worker consult the column — which would
     * turn an inert scaffold into live behaviour in a PR reviewed as inert.
     */
    public function testOnlyTheCapabilityModelReadsTheManualPermission(): void
    {
        self::assertSame(
            [
                'app/Domain/Onchain/Repositories/ChainRepository.php',
                'app/Domain/Onchain/Support/NftChainCapability.php',
                'includes/database/schema-chains.php',
            ],
            self::filesContaining('manual_collection_discovery_enabled')
        );
    }

    public function testOnlyTheCapabilityModelReadsTheProductSupportColumn(): void
    {
        self::assertSame(
            [
                'app/Domain/Onchain/Repositories/ChainRepository.php',
                'app/Domain/Onchain/Support/NftChainCapability.php',
                'includes/database/schema-chains.php',
            ],
            self::filesContaining('bcc_supports_nft_collections')
        );
    }

    /**
     * Nothing calls the verdict yet.
     *
     * `NftChainCapability` is referenced only by its own file and by the
     * classes it documents itself against. No admin page, REST endpoint,
     * controller, service or worker consults it — which is exactly what
     * "scaffold, unread" means and what makes this PR safe to ship at
     * DEFAULT 0.
     */
    public function testNoAdminRestOrWorkerCallsTheVerdictYet(): void
    {
        // `NftChainCapability::` — a static CALL or constant read, not the
        // bare class name, which also appears in `@see` docblocks pointing
        // readers at the consumer of a value.
        //
        // The expected answer today is NO FILES AT ALL: inside the class
        // itself every reference is `self::`. Asserted as an explicit
        // equality rather than a foreach, because a loop over an empty list
        // asserts nothing and would keep passing if the list later filled up
        // with something the loop no longer ran over.
        self::assertSame(
            [],
            self::filesContaining('NftChainCapability::'),
            'nothing in production may consult the verdict while PR 2 is a scaffold'
        );
    }

    // ── Withdrawn designs must not reappear ─────────────────────────────

    /**
     * Three earlier proposals were explicitly withdrawn. Each would be an
     * easy thing to reintroduce by copying an older draft, and each is
     * wrong for a recorded reason:
     *
     *   `automatic_collection_discovery_enabled` — automatic discovery was
     *       RETIRED in PR 1. A column implying it could be switched back on
     *       would misdescribe the system.
     *   `nft_collection_driver` / a `primary` driver — a scalar cannot hold
     *       six operations with ordered, multi-driver answers.
     *   `discovery_source` — one column cannot hold accumulating evidence
     *       from several sources.
     */
    public function testWithdrawnDesignsAreAbsent(): void
    {
        foreach (['automatic_collection_discovery_enabled', 'nft_collection_driver'] as $withdrawn) {
            self::assertSame(
                [],
                self::filesContaining($withdrawn),
                "the withdrawn '{$withdrawn}' design must not appear anywhere"
            );
        }
    }

    /**
     * The Alchemy regex and the Helius resolution exist EXACTLY ONCE each.
     *
     * Both were extracted from private fetcher methods so the readiness
     * derivation and the fetchers could not drift apart. A re-inlined copy
     * would restore the two-definitions bug class this codebase has already
     * been bitten by twice.
     */
    public function testProviderFactsAreDefinedExactlyOnce(): void
    {
        self::assertSame(
            ['app/Domain/Onchain/Support/AlchemyEndpoint.php'],
            self::filesContaining('g\\.alchemy\\.com)/v2/'),
            'the Alchemy URL regex must live in exactly one place'
        );

        self::assertSame(
            ['app/Domain/Onchain/Support/HeliusEndpoint.php'],
            self::filesContaining('mainnet.helius-rpc.com/?api-key='),
            'the Helius URL shape must live in exactly one place'
        );

        self::assertSame(
            ['app/Domain/Onchain/Support/HeliusEndpoint.php'],
            self::filesContaining("'bcc_onchain_das_unsupported_'"),
            'the DAS-unsupported option key must live in exactly one place'
        );
    }
}
