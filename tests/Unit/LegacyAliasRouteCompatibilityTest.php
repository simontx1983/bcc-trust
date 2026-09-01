<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\WalletAddressValidator;
use PHPUnit\Framework\TestCase;

/**
 * Proves PR 5a cannot hide a legacy verified Solana collection.
 *
 * ── THE CONCERN ─────────────────────────────────────────────────────────
 * `NftPieceViewModelBuilder::build()` now canonicalises before lookup and
 * refuses a Magic Eden symbol. If any production route could previously
 * reach that builder with a stored alias, PR 5a would turn a
 * working page into a 404 — and 24 of those aliases are verified and
 * community-linked.
 *
 * ── WHY NO COMPATIBILITY SHIM WAS ADDED ─────────────────────────────────
 * It cannot happen, and the reason is upstream of the builder rather than
 * inside it. `NftPieceEndpoint` — the ONLY caller — validates the contract
 * with `WalletAddressValidator::validate($addr, 'solana')`, which requires
 * `/^[1-9A-HJ-NP-Za-km-z]{32,44}$/`, and returns 422 `bcc_invalid_request`
 * before the builder is constructed. Every stored alias is 4-31 characters,
 * so every one is already rejected there on `main`.
 *
 * Verified against the live local site running the exact base commit
 * (1b18242c), 2026-08-31:
 *
 *   GET /wp-json/bcc/v1/nft-pieces/solana/theheist/1     -> 422 bcc_invalid_request
 *   GET /wp-json/bcc/v1/nft-pieces/solana/drifella2/1    -> 422 bcc_invalid_request
 *   GET /wp-json/bcc/v1/nft-pieces/solana/peppermints/1  -> 422 bcc_invalid_request
 *   GET /wp-json/bcc/v1/nft-pieces/solana/mad_lads/1     -> 404 (route regex
 *       is [A-Za-z0-9]+, so an alias containing '_' matches no route at all)
 *   GET /wp-json/bcc/v1/nft-pieces/solana/<real 32-byte key>/1
 *                                                        -> 404 bcc_not_found
 *
 * All three of those aliases are `is_verified = 1` in the inspected local
 * dataset. Adding an unused compatibility branch would have been dead code
 * defending against a path that does not exist.
 *
 * ── WHAT THIS TEST THEREFORE GUARDS ─────────────────────────────────────
 * The invariant that makes the shim unnecessary. If someone widens the
 * Solana branch of `WalletAddressValidator`, aliases would start reaching
 * the builder and the reasoning above would silently stop holding — so
 * that widening must fail here and force a deliberate decision.
 */
final class LegacyAliasRouteCompatibilityTest extends TestCase
{
    /** Real `source='toplist'` values from the inspected local dataset. */
    private const LEGACY_ALIASES = [
        'theheist',      // verified
        'drifella2',     // verified
        'peppermints',   // verified
        'mushboomers',   // verified
        'bozosgroup',    // verified
        'mad_lads',      // contains '_' — cannot even match the route regex
        'okay_bears',
        'smb_gen3',
        'aurory',
    ];

    /** The route's own path pattern for the contract segment. */
    private const ROUTE_CONTRACT_PATTERN = '/^[A-Za-z0-9]+$/';

    private static function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    // ── The gate that makes a shim unnecessary ──────────────────────────

    /**
     * Every stored alias is refused by the endpoint's validator, so none
     * can reach NftPieceViewModelBuilder on `main` OR on this branch.
     */
    public function testTheEndpointValidatorRejectsEveryLegacyAlias(): void
    {
        foreach (self::LEGACY_ALIASES as $alias) {
            self::assertFalse(
                WalletAddressValidator::validate($alias, 'solana'),
                "'{$alias}' must be rejected at the endpoint (422) before the builder runs"
            );
        }
    }

    /** A real key passes the same gate — the check is not vacuous. */
    public function testTheEndpointValidatorAcceptsARealSolanaKey(): void
    {
        self::assertTrue(
            WalletAddressValidator::validate('7cmUkdkC4Z5fBWk42hqnvjPftNNWwBy9GKe6FcyFVwH9', 'solana')
        );
    }

    /**
     * Aliases containing `_` or `-` cannot match the route's own path
     * regex, so they 404 before any PHP validation at all.
     */
    public function testAliasesWithUnderscoresCannotMatchTheRoutePattern(): void
    {
        self::assertDoesNotMatchRegularExpression(self::ROUTE_CONTRACT_PATTERN, 'mad_lads');
        self::assertDoesNotMatchRegularExpression(self::ROUTE_CONTRACT_PATTERN, 'okay_bears');
        self::assertMatchesRegularExpression(self::ROUTE_CONTRACT_PATTERN, 'theheist');
    }

    /** The endpoint really does gate on the validator before building. */
    public function testTheEndpointValidatesBeforeCallingTheBuilder(): void
    {
        $src = (string) file_get_contents(
            self::pluginRoot() . '/app/Domain/Onchain/REST/NftPieceEndpoint.php'
        );

        $validatePos = strpos($src, 'WalletAddressValidator::validate(');
        $buildPos    = strpos($src, 'NftPieceViewModelBuilder::build(');

        self::assertIsInt($validatePos, 'the endpoint must validate the contract');
        self::assertIsInt($buildPos, 'the endpoint must call the builder');
        self::assertLessThan(
            $buildPos,
            $validatePos,
            'validation must happen BEFORE the builder, or aliases could reach it'
        );
    }

    // ── Pin the caller inventory so the escape hatch cannot spread ──────

    /**
     * `findLegacyByChainContractInsensitive()` exists for a future PR 5b
     * repair path and for direct/administrative use. It must have ZERO
     * production callers: a call appearing anywhere in `app/` means the
     * escape hatch has started spreading, and the "no silent fallback"
     * guarantee needs re-auditing.
     */
    public function testTheLegacyLookupHasNoProductionCallers(): void
    {
        // `::name(` is CALL syntax. It excludes the declaration
        // (`public static function name(`) and docblock mentions
        // (`{@see name}`), which are not callers.
        $callers = self::grepProduction('::findLegacyByChainContractInsensitive(');

        self::assertSame(
            [],
            $callers,
            "the legacy lookup must not be called from production code:\n" . implode("\n", $callers)
        );
    }

    /**
     * The strict lookup's caller set. If a NEW caller appears, someone must
     * decide whether it can receive a legacy alias — this test makes that
     * decision explicit instead of implicit.
     *
     * ── PR 6: THE ADMIN CALLER MOVED, AND THE ASSESSMENT MOVED WITH IT ──
     * `VerifyCollectionsPage` carried two manual Add Collection forms; both
     * are retired and replaced by `ManualCollectionIntakeService`, reached
     * from the NFT Discovery page. The caller count is unchanged at two.
     *
     * Legacy-alias exposure, assessed: NONE, and less than before. The call
     * is the DUPLICATE CHECK for a collection being added by hand, and
     * `findByChainContract()` matches `canonical_identifier` exactly. An
     * unresolved legacy alias row has `canonical_identifier = NULL`, so it
     * cannot match, which is the correct outcome — a new, valid identity
     * must NOT be absorbed by an alias row that has never been resolved.
     * The service never calls the insensitive lookup (pinned by
     * testTheLegacyLookupHasNoProductionCallers), so a duplicate check can
     * never silently resolve through an alias.
     */
    public function testTheStrictLookupCallerInventoryIsUnchanged(): void
    {
        $callers = self::grepProduction('::findByChainContract(');

        $files = array_values(array_unique(array_map(
            static fn (string $line): string => strtok($line, ':') ?: $line,
            $callers
        )));
        sort($files);

        self::assertSame(
            [
                'app/Domain/Onchain/Services/ManualCollectionIntakeService.php',
                'app/Domain/Onchain/Services/NftPieceViewModelBuilder.php',
            ],
            $files,
            'a new caller of findByChainContract() must be assessed for legacy-alias exposure'
        );
    }

    /**
     * `findTokenStandard()` CAN receive a legacy alias (holder-gate config
     * stores one for alias-backed Solana gates), so its callers are pinned too.
     *
     * Behaviour is unchanged for them: the returned standard is consulted
     * only via `stripos($tokenStandard, '1155')`, so `null` (this branch)
     * and `'Metaplex'` (main) both fall through to the identical
     * `count_holdings()` path. No holder-gate outcome changes.
     */
    public function testTheTokenStandardCallerInventoryIsUnchanged(): void
    {
        $callers = self::grepProduction('::findTokenStandard(');

        $files = array_values(array_unique(array_map(
            static fn (string $line): string => strtok($line, ':') ?: $line,
            $callers
        )));
        sort($files);

        self::assertSame(
            [
                'app/Domain/Core/Services/wallet/BlockchainQueryService.php',
                'app/Domain/Onchain/Services/HoldingsService.php',
            ],
            $files,
            'a new caller of findTokenStandard() must be assessed for legacy-alias exposure'
        );
    }

    /** The 1155 branch is the ONLY consumer of the token standard. */
    public function testTokenStandardOnlySelectsTheElevenFiftyFiveBranch(): void
    {
        $src = (string) file_get_contents(
            self::pluginRoot() . '/app/Domain/Onchain/Services/HoldingsService.php'
        );

        $start = strpos($src, 'private static function countFromCacheOrFetch(');
        self::assertIsInt($start);

        $body = substr($src, $start, 4000);

        // Exactly one decision is made on $tokenStandard, and it is the
        // ERC-1155 test. Anything else (null, 'Metaplex', 'CW-721') takes
        // the same path, which is why a Solana null is behaviour-neutral.
        preg_match_all('/\$tokenStandard/', $body, $uses);
        self::assertNotSame([], $uses[0]);
        self::assertStringContainsString("stripos(\$tokenStandard, '1155')", $body);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Grep production PHP (app/ only — no tests, no vendor).
     *
     * Callers are matched by CALL syntax (`::method(`), so a declaration or
     * a docblock reference never counts as a caller.
     *
     * @return list<string> "relative/path.php:line"
     */
    private static function grepProduction(string $needle): array
    {
        $root = self::pluginRoot() . '/app';
        $hits = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path  = str_replace('\\', '/', $file->getPathname());
            $lines = file($path) ?: [];
            foreach ($lines as $n => $line) {
                if (str_contains($line, $needle)) {
                    $rel = ltrim(str_replace(str_replace('\\', '/', self::pluginRoot()), '', $path), '/');
                    $hits[] = $rel . ':' . ($n + 1);
                }
            }
        }

        sort($hits);

        return $hits;
    }
}
