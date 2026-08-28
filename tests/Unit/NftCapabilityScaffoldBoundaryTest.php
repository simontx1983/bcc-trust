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
            'app/Domain/Onchain/ValueObjects/ChainNftCapabilityOverrides.php',
            'includes/database/schema-chain-nft-capabilities.php',
            'includes/database/schema-probe.php',
        ];
    }

    // ── No convenience method may bypass operator overrides ─────────────

    /**
     * `NftDriverRegistry::enumerationDriversForChainId()` was removed.
     *
     * It took `array $overrides = []`, so every call that omitted the
     * argument silently meant "this chain has no overrides" — bypassing
     * every operator disable in the database and returning full registry
     * defaults, behind a name that promised a complete persisted answer.
     *
     * The rule generalises: no method on the registry may DEFAULT its
     * overrides. A caller either supplies them or does not use the registry.
     */
    public function testRegistryExposesNoOverrideDefaultingHelper(): void
    {
        $src = self::codeWithoutComments(
            self::root() . '/app/Domain/Onchain/Support/NftDriverRegistry.php'
        );

        self::assertStringNotContainsString('enumerationDriversForChainId', $src);

        // And the rule generalises: `driversFor()` takes its overrides as a
        // REQUIRED parameter. A default of `[]` would read as "this chain
        // has no overrides", so a caller that merely forgot the argument
        // would bypass every operator disable in the database.
        self::assertStringNotContainsString(
            'array $overrides = []',
            $src,
            'no registry method may default its overrides to "none"'
        );
        self::assertStringContainsString('array $overrides): array', $src);
    }

    /**
     * Nothing resolves a chain's overrides except through the repository,
     * and the repository's contract is a value that can say "unavailable".
     *
     * Only the CALL site appears here — the repository declares the method
     * rather than calling it.
     *
     * ── THE LIST WENT FROM ONE TO TWO WHEN THE EDITOR LANDED ────────────
     * The capability editor is the second caller, and it uses this SAME
     * whole-chain read rather than a narrower one. That was a deliberate
     * choice and this test is where it is recorded, because the tempting
     * alternative — a `findOverride(chain, operation, driver)` that fetches
     * only the row about to change — would have kept this assertion at one
     * file while WEAKENING the guarantee behind it.
     *
     * A single-row lookup answers "what does this row say". A write has to
     * answer a harder question first: "is this chain's override state
     * something we can read AT ALL". The narrow read returns a perfectly
     * clean row out of a set that is truncated at its ceiling or contains a
     * malformed sibling — and a write permitted on that basis is a write
     * made against a store {@see NftChainCapability} would refuse to draw
     * any conclusion from. So both callers take the whole set, and both fail
     * closed on the same value.
     *
     * A THIRD entry here is a review question. A narrower reader appearing
     * beside them is a review objection.
     */
    public function testOverridesAreOnlyEverSourcedFromTheRepository(): void
    {
        self::assertSame(
            [
                'app/Domain/Onchain/Services/NftCapabilityEditor.php',
                'app/Domain/Onchain/Support/NftChainCapability.php',
            ],
            self::filesContaining('ChainNftCapabilityRepository::getForChain')
        );
    }

    /**
     * And there is no narrower reader to bypass it with.
     *
     * Pinned by NAME because the shape is what matters, not the spelling: a
     * method that fetches one triple would be the obvious thing to add the
     * next time a caller wants "just this row", and its arrival would make
     * every fail-closed guarantee above conditional on which reader the
     * caller happened to pick.
     */
    public function testTheOverrideStoreHasNoSingleRowReader(): void
    {
        $src = self::codeWithoutComments(
            self::root() . '/app/Domain/Onchain/Repositories/ChainNftCapabilityRepository.php'
        );

        foreach (['findOverride', 'getOverride', 'getOverrideRow', 'findRowFor'] as $narrow) {
            self::assertStringNotContainsString(
                'function ' . $narrow,
                $src,
                'a single-row override reader cannot establish that the whole set is readable'
            );
        }

        // The one read there is, and the bound it enforces.
        self::assertStringContainsString('function getForChain', $src);
        self::assertStringContainsString('MAX_ROWS_PER_CHAIN + 1', $src);
    }

    /**
     * The verdict must never be handed override rows it has not confirmed
     * are complete. Pinned as source, because the alternative — a future
     * edit that drops the `isAvailable()` branch — reads as a simplification
     * and silently restores every disabled driver.
     */
    public function testTheComposedVerdictGatesOnOverrideAvailability(): void
    {
        $src = self::codeWithoutComments(
            self::root() . '/app/Domain/Onchain/Support/NftChainCapability.php'
        );

        self::assertStringContainsString('$overrides->isAvailable()', $src);
        self::assertStringContainsString('$overrides->rows()', $src);
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
     * ONLY the discovery control plane consults the verdict.
     *
     * ── THIS LIST WENT FROM EMPTY TO THREE, DELIBERATELY ────────────────
     * While the capability model was a scaffold the answer here was NO
     * FILES AT ALL, and that emptiness was what made shipping it safe at
     * `DEFAULT 0`: a model nothing reads cannot refuse anything it should
     * not, and cannot grant anything either.
     *
     * The admin control plane is the first consumer, and the capability
     * EDITOR is the second, so the list now names exactly the files that may
     * read it:
     *
     *   NftDiscoveryPage                  the surface — displays the
     *                                     statuses, and gates its one
     *                                     provider-consuming control on
     *                                     the enumeration operation
     *   NftCapabilityEditorPanel          the selected chain's editor; a
     *                                     pure printer, reads the state
     *                                     CONSTANTS to label controls
     *   NftCapabilityEditor               the write path — asks the model
     *                                     for the authoritative before-state
     *                                     and for whether an
     *                                     operator-started operation exists
     *   NftDiscoveryControlPlaneSnapshot  builds the finished rows the
     *                                     page prints
     *
     * The model's OWN file is absent, and that is not an oversight: inside
     * it every reference is `self::`, which this needle does not match.
     *
     * ── THE EDITOR READS THE MODEL RATHER THAN RE-DERIVING IT ───────────
     * Which is the point of it appearing here. The write path needs three
     * answers before it writes — is product support on, is the permission
     * on, could an operator-started operation ever run here — and every one
     * of them is the model's to give. An editor that read the columns itself
     * would be a second interpretation of the same three facts, free to
     * disagree with the matrix printed directly above its own controls.
     *
     * ── WHY IT IS STILL AN EXPLICIT LIST ────────────────────────────────
     * The point of this test never was "zero"; it was that the set of
     * consumers is small, known, and changes only on purpose. A FIFTH file
     * appearing here is a review question — especially a worker, a cron
     * callback or a REST endpoint, none of which may start a discovery.
     *
     * Asserted as an explicit equality rather than a foreach, because a
     * loop over a list asserts nothing about entries it never sees.
     */
    public function testOnlyTheDiscoveryControlPlaneConsultsTheVerdict(): void
    {
        // `NftChainCapability::` — a static CALL or constant read, not the
        // bare class name, which also appears in `@see` docblocks pointing
        // readers at the consumer of a value.
        self::assertSame(
            [
                'app/Domain/Onchain/Admin/NftDiscoveryPage.php',
                'app/Domain/Onchain/Admin/Views/NftCapabilityEditorPanel.php',
                'app/Domain/Onchain/Services/NftCapabilityEditor.php',
                'app/Domain/Onchain/Services/NftDiscoveryControlPlaneSnapshot.php',
            ],
            self::filesContaining('NftChainCapability::'),
            'only the NFT discovery control plane and its editor may consult the capability model'
        );
    }

    /**
     * And no WORKER, CRON CALLBACK or REST ENDPOINT is among them.
     *
     * The list above is reviewed by eye; this is the part of it that must
     * never change without a very deliberate argument. A discovery that can
     * be started by anything other than a logged-in administrator pressing
     * a button is the failure the automatic-discovery retirement removed,
     * and the capability model is exactly what such a caller would reach
     * for first.
     */
    public function testNothingOutsideAdminAndItsSnapshotConsultsTheVerdict(): void
    {
        foreach (self::filesContaining('NftChainCapability::') as $path) {
            $isAdminSurface = str_contains($path, '/Admin/')
                || str_contains($path, 'NftDiscoveryControlPlaneSnapshot.php')
                || str_contains($path, 'NftCapabilityEditor.php')
                || str_contains($path, 'Support/NftChainCapability.php');

            self::assertTrue(
                $isAdminSurface,
                $path . ' consults the capability model but is not the admin control plane'
            );
            self::assertStringNotContainsString('/Workers/', $path);
            self::assertStringNotContainsString('/REST/', $path);
            self::assertStringNotContainsString('/CLI/', $path);
        }
    }

    // ── The write path, and the shape of it ─────────────────────────────

    /**
     * EXACTLY ONE SANCTIONED CAPABILITY-EDITING PATH.
     *
     * ── THIS REPLACES A NEGATIVE, AND IS STRICTLY STRONGER ──────────────
     * PR 2 and PR 3 each asserted that NO writer existed. That was the right
     * assertion while it was true, and deleting it now would trade a real
     * guarantee for nothing. So it is REPLACED rather than removed: instead
     * of "nobody writes", the claim is "exactly these files write, and they
     * are the ones that were reviewed for it".
     *
     * A writer appearing anywhere else — a migration, an installer, a cron
     * callback, a REST endpoint, a CLI command, a provider callback — fails
     * here, which is the same protection the old test gave, aimed at the
     * only thing that can still go wrong.
     */
    public function testExactlyOneServiceOrchestratesCapabilityWrites(): void
    {
        foreach ([
            'ChainRepository::enableNftProductSupport',
            'ChainRepository::disableNftProductSupport',
            'ChainRepository::setManualCollectionDiscoveryEnabled',
            'ChainNftCapabilityRepository::upsertOverride',
            'ChainNftCapabilityRepository::deleteOverride',
            'ChainNftCapabilityRepository::bumpChainGeneration',
        ] as $writer) {
            self::assertSame(
                ['app/Domain/Onchain/Services/NftCapabilityEditor.php'],
                self::filesContaining($writer),
                $writer . ' may only be called by the capability editor service'
            );
        }
    }

    /**
     * And the SQL for those writes exists in exactly one file each.
     *
     * §1: `$wpdb` lives in a repository. The check is on the column and
     * table names rather than on `$wpdb`, because that is what a well-meant
     * "quick fix" would actually move — an `UPDATE` typed into a service, a
     * page or a migration.
     */
    public function testOnlyRepositoriesWriteTheCapabilityStorage(): void
    {
        self::assertSame(
            [
                'app/Domain/Onchain/Repositories/ChainNftCapabilityRepository.php',
                // The CREATE TABLE, which is the one other thing allowed to
                // name it — and which creates it EMPTY, with no seed.
                'includes/database/schema-chain-nft-capabilities.php',
            ],
            self::filesContaining("DB::table('chain_nft_capabilities')"),
            'the override table is addressed from its repository and the schema file only'
        );

        foreach (self::filesContaining('bcc_supports_nft_collections') as $path) {
            self::assertContains(
                $path,
                [
                    'app/Domain/Onchain/Repositories/ChainRepository.php',
                    'app/Domain/Onchain/Support/NftChainCapability.php',
                    'includes/database/schema-chains.php',
                ],
                $path . ' names a capability column but is not a repository, the model or the schema'
            );
        }
    }

    /**
     * NO MIGRATION, INSTALLER, CRON, REST, CLI OR PROVIDER CALLBACK WRITES
     * CAPABILITY — asserted over the whole tree, not a curated list.
     *
     * The curated-list version of this test can only fail for files somebody
     * remembered to add to it. This one walks every production file and
     * fails for any of them that both LIVES in one of those places and
     * CALLS a capability writer.
     */
    public function testNoUnattendedSurfaceWritesCapability(): void
    {
        $writers = [
            'enableNftProductSupport',
            'disableNftProductSupport',
            'setManualCollectionDiscoveryEnabled',
            'upsertOverride',
            'deleteOverride',
        ];

        foreach (self::productionPhpFiles() as $path) {
            $rel  = str_replace('\\', '/', substr($path, strlen(self::root()) + 1));
            $code = self::codeWithoutComments($path);

            $unattended = str_contains($rel, '/Workers/')
                || str_contains($rel, '/REST/')
                || str_contains($rel, '/CLI/')
                || str_contains($rel, 'includes/database/')
                || str_contains($rel, '/Fetchers/')
                || str_contains($rel, 'Cron');

            if (!$unattended) {
                continue;
            }

            foreach ($writers as $writer) {
                self::assertStringNotContainsString(
                    $writer . '(',
                    $code,
                    $rel . ' must never write a capability value — only an administrator may'
                );
            }
        }
    }

    /**
     * No SEED, no default and no upgrade step enables anything.
     *
     * The PR 2 version of this checked three files. The editor makes the
     * question bigger — a "helpful" backfill could now be written as a call
     * to the new repository methods rather than as raw SQL — so both shapes
     * are checked across the whole schema directory and the plugin
     * bootstrap.
     */
    public function testNothingSeedsACapabilityAtInstallOrUpgrade(): void
    {
        $sources = ['bcc-trust.php'];
        foreach (glob(self::root() . '/includes/database/*.php') ?: [] as $path) {
            $sources[] = 'includes/database/' . basename($path);
        }

        foreach ($sources as $relative) {
            $src = self::read($relative);

            foreach (['bcc_supports_nft_collections', 'manual_collection_discovery_enabled'] as $column) {
                self::assertDoesNotMatchRegularExpression(
                    '/UPDATE[^;]*' . preg_quote($column, '/') . '\s*=\s*1/is',
                    $src,
                    "{$relative} must not enable {$column} for any chain"
                );
            }

            foreach ([
                'enableNftProductSupport',
                'setManualCollectionDiscoveryEnabled',
                'upsertOverride',
            ] as $writer) {
                self::assertStringNotContainsString(
                    $writer . '(',
                    $src,
                    "{$relative} must not call a capability writer"
                );
            }

            self::assertDoesNotMatchRegularExpression(
                '/INSERT\s+INTO[^;]*chain_nft_capabilities/is',
                $src,
                "{$relative} must not seed an override row"
            );
        }
    }

    /**
     * The editor introduces NO SECOND REGISTRY and NO SECOND VERDICT.
     *
     * Both would look like reasonable code. A list of enumerable families in
     * the editor ("EVM and Solana can't") reads as defensive and is a second
     * answer to a question {@see NftDriverRegistry} already answers; a
     * readiness check in the editor reads as thorough and is a second answer
     * to {@see NftProviderReadiness}. Either one drifts the day a driver is
     * added.
     */
    public function testTheEditorHoldsNoSecondRegistryOrVerdict(): void
    {
        $src = self::codeWithoutComments(
            self::root() . '/app/Domain/Onchain/Services/NftCapabilityEditor.php'
        );

        // No family list of its own. The structural question is asked of the
        // capability model, which asks the registry.
        foreach (["'evm'", "'solana'", "'cosmos'", 'chain_type'] as $familyish) {
            self::assertStringNotContainsString(
                $familyish,
                $src,
                'which families can be enumerated is the registry\'s answer, not the editor\'s'
            );
        }

        // Readiness is observed by one class, and the editor is not it: a
        // capability may be granted while its provider is unconfigured.
        self::assertStringNotContainsString('NftProviderReadiness', $src);

        // And the structural refusal comes from the model.
        self::assertStringContainsString('hasOperatorStartableOperation', $src);
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
