<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the canonical-identity boundary against drift.
 *
 * ── WHAT DRIFT LOOKS LIKE ───────────────────────────────────────────────
 * Before PR 5a there were ~60 places that normalised a contract address,
 * and exactly ONE of them knew which chain it was dealing with — whose
 * answer was then discarded by a repository method that re-lowercased
 * unconditionally. Readers folded case; writers stored verbatim. The two
 * disagreed, and it went unnoticed for months because the column collation
 * is case-insensitive and hid the difference.
 *
 * The failure mode is therefore NOT "someone deletes the service". It is
 * "someone adds a `strtolower()` next to a query, in a method that also
 * calls the service", and the two silently diverge again.
 *
 * ── WHY AN EXPLICIT REGISTRY, NOT A PROXIMITY GREP ──────────────────────
 * A "no strtolower within N lines of a contract variable" scan is brittle
 * in both directions: it fires on unrelated cache keys and misses a fold
 * that happens two helpers away. Instead this pins the EXACT set of
 * methods that own collection-identity comparison. Each must go through
 * the shared service and must not case-fold on its own.
 *
 * The registry is a ratchet: entries may be REMOVED (when a path stops
 * dealing in collection identity) but adding a bypass means editing this
 * file, which is the point — it makes the drift deliberate and reviewable
 * rather than accidental.
 *
 * ── WHAT IS DELIBERATELY NOT REGISTERED ─────────────────────────────────
 * These still case-fold chain-agnostically. That is a KNOWN, deliberate
 * exclusion in PR 5a, not an oversight — listed here so a later reader does
 * not "fix" one and silently break the legacy rows it protects:
 *
 *   CollectionRepository::findLegacyByChainContractInsensitive
 *       The sanctioned legacy path for the 99 pre-PR-5a alias rows.
 *       Case-insensitive BY DESIGN, named so a caller must ask for it, and
 *       expected to be deleted by PR 5b.
 *
 *   CollectionRepository::verifiedMapForContracts
 *   CollectionRepository::getUserHoldings
 *       PHP array-key folds. Making these canonical would drop the 24
 *       VERIFIED legacy Solana rows out of the verified map, so their
 *       badges would vanish from the gallery and stance panel. Keeping
 *       legacy rows visible is an explicit PR 5a requirement.
 *
 *   GatedGroupRepository (findGroupForCollection, getGateConfig, the
 *   META_CONTRACT write) and GatedGroupProvisioningService
 *       Community gate authority. The 24 live Solana gates store lowercased
 *       ALIASES in `_bcc_gate_contract_address` — values the canonical
 *       service must refuse. Routing these through it would orphan 24
 *       existing communities, which PR 5a is forbidden from doing.
 *       Migrating them is gated on PR 5b resolving the aliases.
 *
 *   SolanaFetcher::count_holdings / fetch_collections
 *       Provider-side comparison against DAS `grouping[].group_value`.
 *       This is where the PR 5b defect lives (a stored symbol can never
 *       equal a mint, so the count is always 0 and the holder gate reads
 *       INELIGIBLE rather than UNKNOWN). Fixing it needs the alias repair.
 *
 *   VerifyCollectionsPage (admin listing join keys)
 *       Display-only dedupe keys for the CosmWasm scanner table.
 *
 *   Sibling tables — `wp_bcc_collection_signals`, `wp_bcc_nft_holdings`,
 *   `wp_bcc_nft_spam_contracts`, `wp_bcc_onchain_collection_pieces`,
 *   `wp_bcc_user_nft_selections`, `wp_bcc_cosmwasm_contracts`
 *       Eight tables re-carry `contract_address` rather than a surrogate
 *       FK, so the identity rule is duplicated across nine columns. PR 5a
 *       canonicalises the collections table only.
 *
 *   WalletRepository / ClaimService / auth controllers
 *       Wallet identity, not collection identity. Different rules.
 */
final class CanonicalIdentifierDriftGuardTest extends TestCase
{
    /** Case-folding calls that must not appear inside a registered method. */
    private const FOLDING_CALLS = ['strtolower(', 'mb_strtolower(', 'strtoupper(', 'mb_strtoupper('];

    /** Any one of these proves the method routed through the shared service. */
    private const SERVICE_MARKERS = ['canonicalIdentityFor(', 'NftCollectionIdentifier::'];

    /**
     * file (relative to plugin root) => list of methods that own
     * collection-identity comparison.
     *
     * @return array<string, list<string>>
     */
    private static function registry(): array
    {
        return [
            'app/Domain/Onchain/Repositories/CollectionRepository.php' => [
                // Writers — all four ON DUPLICATE KEY UPDATE paths.
                'upsert',
                'bulkUpsert',
                'addManual',
                'ensureExistsBatch',
                // Readers keyed on (chain, identifier).
                'findByChainContract',
                'findTokenStandard',
            ],
            'app/Domain/Onchain/Services/NftPieceViewModelBuilder.php' => [
                'build',
            ],
        ];
    }

    // ── The guard itself ────────────────────────────────────────────────

    /**
     * @return list<string> human-readable violations; empty when clean
     */
    public static function inspect(string $source, string $method, string $label): array
    {
        $rawBody = self::methodBody($source, $method);

        if ($rawBody === null) {
            return ["{$label}: method {$method}() not found — the registry is stale"];
        }

        // Scan EXECUTABLE CODE only. An earlier version of this guard
        // matched the raw body and fired on the explanatory comments that
        // say what the old `strtolower()` did and why it is gone — i.e. it
        // punished documenting the fix. A guard whose only escape is to
        // write worse comments is a guard that gets deleted.
        $body = self::codeOnly($rawBody);

        $violations = [];

        foreach (self::FOLDING_CALLS as $call) {
            if (str_contains($body, $call)) {
                $violations[] = "{$label}::{$method}() case-folds directly with {$call}) — "
                    . 'collection identity is chain-aware; use NftCollectionIdentifier';
            }
        }

        $usesService = false;
        foreach (self::SERVICE_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                $usesService = true;
                break;
            }
        }

        if (!$usesService) {
            $violations[] = "{$label}::{$method}() does not route through the shared "
                . 'normalization service — readers and writers will drift';
        }

        return $violations;
    }

    /**
     * Strip comments, string literals and whitespace, leaving only code.
     *
     * Uses the real PHP tokenizer rather than a regex, so a `strtolower(`
     * inside a docblock or an SQL string cannot be mistaken for a call, and
     * `strtolower (` with a space cannot be used to slip past.
     */
    private static function codeOnly(string $body): string
    {
        $tokens = @token_get_all('<?php ' . $body);
        $out    = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $out .= $token;
                continue;
            }

            switch ($token[0]) {
                case T_COMMENT:
                case T_DOC_COMMENT:
                case T_WHITESPACE:
                case T_CONSTANT_ENCAPSED_STRING:
                case T_ENCAPSED_AND_WHITESPACE:
                case T_INLINE_HTML:
                case T_OPEN_TAG:
                    break;
                default:
                    $out .= $token[1];
            }
        }

        return $out;
    }

    /**
     * Extract a method body by brace matching from its opening `{`.
     */
    private static function methodBody(string $source, string $method): ?string
    {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $open = strpos($source, '{', (int) $m[0][1]);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $len   = strlen($source);
        for ($i = $open; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return null;
    }

    private static function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    // ── Live assertions ─────────────────────────────────────────────────

    public function testEveryRegisteredIdentityPathUsesTheSharedService(): void
    {
        $violations = [];

        foreach (self::registry() as $relPath => $methods) {
            $path = self::pluginRoot() . '/' . $relPath;
            self::assertFileExists($path, "registry points at a missing file: {$relPath}");

            $source = (string) file_get_contents($path);
            $label  = basename($relPath, '.php');

            foreach ($methods as $method) {
                $violations = array_merge($violations, self::inspect($source, $method, $label));
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * The legacy escape hatch must stay exactly one method, and must stay
     * findable by name. If it grows callers, PR 5b's inventory is wrong.
     */
    public function testTheLegacyCompatibilityPathIsNamedAndIsolated(): void
    {
        $source = (string) file_get_contents(
            self::pluginRoot() . '/app/Domain/Onchain/Repositories/CollectionRepository.php'
        );

        self::assertStringContainsString(
            'public static function findLegacyByChainContractInsensitive(',
            $source,
            'the legacy alias lookup must remain explicitly named, never folded back into findByChainContract()'
        );

        // It must not be CALLED as a fallback by the strict lookup. Code
        // only — the strict method's comment legitimately names it, to tell
        // a reader where the legacy behaviour went.
        $strict = self::methodBody($source, 'findByChainContract');
        self::assertIsString($strict);
        self::assertStringNotContainsString(
            'findLegacyByChainContractInsensitive(',
            self::codeOnly($strict),
            'canonical lookup must never silently degrade to alias lookup'
        );
    }

    // ── Positive controls: prove the guard can actually fail ────────────

    /**
     * A guard that has never failed is not known to work. These plant the
     * two real bypass shapes and assert the inspector catches each.
     */
    public function testAPlantedCaseFoldIsCaught(): void
    {
        $planted = '<?php class X { public static function findByChainContract($a, $b) {
            $id = self::canonicalIdentityFor($a, $b);
            return $wpdb->get_var("... WHERE contract_address = " . strtolower($b));
        } }';

        $violations = self::inspect($planted, 'findByChainContract', 'Planted');

        self::assertNotSame([], $violations, 'the guard must catch a direct case-fold');
        self::assertStringContainsString('case-folds directly', $violations[0]);
    }

    public function testAPlantedServiceBypassIsCaught(): void
    {
        $planted = '<?php class X { public static function upsert($a, $b) {
            return $wpdb->query("INSERT ... VALUES (" . $b . ")");
        } }';

        $violations = self::inspect($planted, 'upsert', 'Planted');

        self::assertNotSame([], $violations, 'the guard must catch a method that skips the service');
        self::assertStringContainsString('does not route through the shared', $violations[0]);
    }

    public function testAStaleRegistryEntryIsCaught(): void
    {
        $violations = self::inspect('<?php class X {}', 'noSuchMethod', 'Planted');

        self::assertNotSame([], $violations);
        self::assertStringContainsString('registry is stale', $violations[0]);
    }

    public function testACleanMethodProducesNoViolations(): void
    {
        $clean = '<?php class X { public static function upsert($a, $b) {
            $identity = self::canonicalIdentityFor($a, $b);
            if (!$identity->isAccepted()) { return false; }
            return $wpdb->query("INSERT ...");
        } }';

        self::assertSame([], self::inspect($clean, 'upsert', 'Planted'));
    }
}
