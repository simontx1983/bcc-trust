<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A validator page exists because the validator was EVER known, not because
 * it is in the set today.
 *
 * ── WHAT THIS CHANGE FIXED ──────────────────────────────────────────────
 * The automatic indexer scoped minting to the addresses a chain's fetch
 * returned in that run, so a validator the fetch did not contain could not
 * get a page however `status` labelled it. That made page existence a
 * function of CURRENT set membership.
 *
 * Measured on production 2026-11-04: 90 validator rows had no page; ZERO of
 * them appeared in their chain's last clean fetch; 51 were labelled
 * `inactive` and 39 still `active`. The pageless set and the out-of-set set
 * were the same population, and `status` did not separate them. Of those 90,
 * 86 sat on chains with a clean fetch and a fresh verified baseline and 4 sat
 * on THORChain, which has no baseline at all and must stay untouched.
 *
 * ── WHAT IS DELIBERATELY UNCHANGED ──────────────────────────────────────
 *   · the per-chain health gate — only a chain with a clean, non-partial
 *     fetch AND a fresh verified baseline is offered to the minter;
 *   · the fail-closed empty allowlist — [] still means mint none;
 *   · the 500-page run budget and the per-invocation cap;
 *   · idempotence — a row that already has a page is skipped;
 *   · dead marking, which this change must not arm, and statuses, which it
 *     must not write.
 *
 * ⚠ CLAIMABILITY WAS ALREADY INDEPENDENT OF MEMBERSHIP and section 4 pins
 * that, because it is the half that makes a page on an out-of-set validator
 * worth creating. It is asserted as a caller inventory over the real source:
 * `ClaimService` matches a wallet to `operator_address` by comparing
 * bech32-decoded bytes and consults neither `status` nor the bonded set.
 *
 * ⚠⚠ WHAT THESE TESTS DO NOT PROVE. The unit harness has no `$wpdb`, and the
 * integration harness's `wp_posts` carries five columns with no
 * `post_author`/`post_date_gmt` and no `wp_insert_post`, so an end-to-end
 * mint — and an end-to-end claim — cannot run here. The candidate SQL is
 * covered by ValidatorPageCandidateStatusIntegrationTest; a real claim
 * remains unproven and belongs on staging.
 */
#[CoversNothing]
final class ValidatorPageExistenceIndependentOfMembershipTest extends TestCase
{
    private const SERVICE = 'BCC\\Trust\\Onchain\\Services\\ChainRefreshService';

    private static function read(string $relative): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertNotFalse($src, "could not read {$relative}");
        self::assertNotSame('', $src, "{$relative} is empty");

        return (string) $src;
    }

    /** Strip comments so prose can never satisfy a code assertion. */
    private static function code(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    private static function service(): string
    {
        return self::read('app/Domain/Onchain/Services/ChainRefreshService.php');
    }

    private static function repository(): string
    {
        return self::read('app/Domain/Onchain/Repositories/ValidatorRepository.php');
    }

    /** @param list<int> $ids */
    private static function scopeFor(array $ids): ?array
    {
        $m = new ReflectionMethod(self::SERVICE, 'mintScopeFor');
        $m->setAccessible(true);

        /** @var list<int>|null $out */
        $out = $m->invoke(null, $ids);

        return $out;
    }

    // ── 0. anti-vacuity ────────────────────────────────────────────────

    public function testTheSubjectsUnderTestAreReallyLoaded(): void
    {
        self::assertTrue(class_exists(self::SERVICE), 'ChainRefreshService must autoload');
        self::assertTrue(
            (new ReflectionMethod(self::SERVICE, 'mintScopeFor'))->isPrivate(),
            'mintScopeFor must exist and stay private'
        );
        self::assertStringContainsString('mintForHealthyChains', self::service());
        self::assertStringContainsString('findMissingPlaceholderPage', self::repository());
    }

    public function testTheCommentStripperRemovesProseButKeepsCode(): void
    {
        $stripped = self::code("// status = 'active'\n\$x = 1; /* status */\n");

        self::assertStringNotContainsString('status', $stripped);
        self::assertStringContainsString('$x = 1;', $stripped);
    }

    // ── 1. the scope resolver, asserted BY VALUE ───────────────────────

    /**
     * ⭐ THE CORE OF THE CHANGE. A chain that proved healthy mints for every
     * pageless row it owns, which is what null means to
     * ValidatorPageMinter::mintMissing(). An inactive or out-of-set validator
     * is reachable only through this.
     */
    public function testAProvenHealthyChainWidensToEveryPagelessRow(): void
    {
        self::assertNull(self::scopeFor([101]));
        self::assertNull(self::scopeFor([101, 102, 103]));
    }

    /**
     * ⛔ THE FAIL-CLOSED CASE SURVIVES. An allowlist that resolved to nothing
     * is a chain whose returned addresses matched no stored row. Widening
     * THAT to the whole chain would mint off a fetch nothing corroborates.
     */
    public function testAnAllowlistThatResolvedToNothingStillMintsNone(): void
    {
        self::assertSame([], self::scopeFor([]));
    }

    /**
     * ⚠ The two cases are the whole domain, and they must not collapse into
     * one another: a resolver that always returned null would mint on the
     * fail-closed case, and one that always returned [] would mint nothing
     * at all.
     */
    public function testTheTwoOutcomesAreDistinctAndNeitherIsUniversal(): void
    {
        self::assertNotSame(self::scopeFor([]), self::scopeFor([1]));
        self::assertSame([], self::scopeFor([]));
        self::assertNull(self::scopeFor([1]));
    }

    // ── 2. inactive / out-of-set eligibility, by construction ──────────

    /**
     * ⭐ `status` IS NOT CONSULTED WHEN CHOOSING CANDIDATES, and never was —
     * fetch membership was the only gate and it was injected from
     * ChainRefreshService. With the allowlist lifted, an `inactive` row is
     * eligible by construction. Asserted on the comment-stripped SQL so the
     * docblocks that discuss `status` cannot satisfy it.
     */
    public function testTheCandidateQueryHasNoStatusPredicate(): void
    {
        $repo = self::code(self::repository());
        $from = strpos($repo, 'function findMissingPlaceholderPage');
        self::assertNotFalse($from, 'findMissingPlaceholderPage must be present');
        $body = substr($repo, $from, 3000);

        self::assertStringNotContainsString("v.status =", $body);
        self::assertStringNotContainsString("v.status IN", $body);
        self::assertStringNotContainsString("status = 'active'", $body);
        self::assertStringContainsString('v.status', $body, 'status is still SELECTed for the caller');
    }

    /**
     * The guards that are NOT about membership must survive, or widening the
     * allowlist would also start minting for claimed validators and for rows
     * whose chain has no bcc_chains entry.
     */
    public function testTheNonMembershipGuardsSurvive(): void
    {
        $repo = self::code(self::repository());

        self::assertStringContainsString("'v.wallet_link_id IS NULL'", $repo, 'claimed rows stay excluded');
        self::assertStringContainsString('JOIN {$chains} c ON c.id = v.chain_id', $repo, 'orphan chains stay excluded');
        self::assertStringContainsString('pm.meta_id IS NULL', $repo, 'rows with a page stay excluded');
        self::assertStringContainsString('LIMIT %d OFFSET %d', $repo, 'the read stays bounded (§2)');
    }

    // ── 3. withholding on a chain without a fresh verified baseline ────

    /**
     * ⭐ THE CHAIN GATE IS THE WHOLE SAFETY STORY, so it is pinned as a
     * CONJUNCTION. Production 2026-11-04: THORChain had 4 pageless rows and
     * no baseline at all, so it must mint nothing while the other six chains
     * mint 86 between them.
     */
    public function testTheHealthyListIsGatedOnAFreshVerifiedBaseline(): void
    {
        $code = self::code(self::service());

        self::assertStringContainsString(
            '$mintBaselineVerified = $baseline > 0 && $baselineFresh;',
            $code,
            'both conjuncts must be required: a present baseline AND a fresh one'
        );
        self::assertStringContainsString(
            'if ($mintBaselineVerified) {',
            $code,
            'the healthy list may only be populated behind that flag'
        );
        self::assertStringContainsString(
            '$healthyChainFetches[$chainId] =',
            $code,
            'and that is the only way a chain reaches the minter'
        );
    }

    /** A stale baseline must not count as verified. */
    public function testBaselineFreshnessIsBoundedBySevenDays(): void
    {
        $code = self::code(self::service());

        self::assertStringContainsString('private const BASELINE_MAX_AGE = 604800;', $code);
        self::assertStringContainsString('$baselineAge <= self::BASELINE_MAX_AGE', $code);
    }

    /**
     * ⚠ The widening must live INSIDE the healthy branch. If mintScopeFor
     * were reachable for a chain that failed the gate, an unverified chain
     * would mint from arbitrarily stale rows.
     */
    public function testTheMinterIsOnlyEverCalledForChainsOnTheHealthyList(): void
    {
        $code = self::code(self::service());

        self::assertStringContainsString('foreach ($chainFetches as $chainId => $allowlist) {', $code);
        self::assertStringContainsString('self::mintForHealthyChains($healthyChainFetches', $code);
        self::assertStringNotContainsString('mintMissing(null', $code, 'never an all-chain mint');
    }

    // ── 4. claimability does not depend on membership ──────────────────

    /**
     * ⭐ Why a page on an out-of-set validator is worth creating: the operator
     * can still claim it. Pinned so a later `status` gate cannot quietly make
     * these 90 pages unclaimable.
     */
    public function testTheClaimPathAppliesNoStatusOrBondedSetGate(): void
    {
        $claim = self::code(self::read('app/Domain/Onchain/Services/ClaimService.php'));

        self::assertStringNotContainsString("'active'", $claim);
        self::assertStringNotContainsString("'inactive'", $claim);
        self::assertStringNotContainsString('BOND_STATUS', $claim);
        self::assertStringContainsString('getByIdWithChain(', $claim, 'the entity still loads by id');
        self::assertStringContainsString('bech32DecodeToBytes(', $claim, 'matching is by decoded bytes');
    }

    /** The entity loader must not filter on status or chain activity either. */
    public function testTheClaimEntityLoaderHasNoStatusFilter(): void
    {
        $repo = self::code(self::repository());
        $from = strpos($repo, 'function getByIdWithChain');
        self::assertNotFalse($from, 'getByIdWithChain must be present');
        $body = substr($repo, $from, 1200);

        self::assertStringContainsString('WHERE v.id = %d', $body);
        self::assertStringNotContainsString('v.status =', $body);
        self::assertStringNotContainsString('c.is_active', $body);
    }

    // ── 5. the invariants this change must not disturb ─────────────────

    public function testTheRunBudgetAndPerInvocationCapAreUnchanged(): void
    {
        $code = self::code(self::service());
        $mint = self::code(self::read('app/Domain/Onchain/Services/ValidatorPageMinter.php'));

        self::assertStringContainsString('private const MINT_SCAN_BUDGET = 500;', $code);
        self::assertStringContainsString('$maxToMint = max(0, min($maxToMint, self::MAX_PER_INVOCATION));', $mint);
        self::assertStringContainsString('$budget -= max(0, (int) ($result[\'scanned\'] ?? 0));', $code);
    }

    public function testMintingStaysIdempotent(): void
    {
        $mint = self::code(self::read('app/Domain/Onchain/Services/ValidatorPageMinter.php'));

        self::assertStringContainsString('findPlaceholderPageId($row->id)', $mint);
        self::assertStringContainsString("\$result['skipped']++", $mint);
    }

    /**
     * ⛔ This change must not arm dead marking and must not write a status.
     * The mint path touches neither.
     */
    public function testTheMintPathNeitherMarksInactiveNorArmsDeadMarking(): void
    {
        $mint = self::code(self::read('app/Domain/Onchain/Services/ValidatorPageMinter.php'));
        $code = self::code(self::service());

        self::assertStringNotContainsString("'inactive'", $mint);
        self::assertStringNotContainsString('markDeadValidators', $mint);
        self::assertStringContainsString(
            'if (!$hasPartialFetch && self::deadMarkingEnabled()) {',
            $code,
            'dead marking keeps BOTH its run-wide flag and its switch'
        );
        self::assertStringNotContainsString(
            'bcc_onchain_dead_marking_enabled\', true',
            $code,
            'the switch must never be written here'
        );
    }

    /** Pages are never deleted or unpublished by this path. */
    public function testNothingInTheMintPathDeletesOrUnpublishesAPage(): void
    {
        $mint = self::code(self::read('app/Domain/Onchain/Services/ValidatorPageMinter.php'));

        self::assertStringNotContainsString('wp_delete_post', $mint);
        self::assertStringNotContainsString('wp_trash_post', $mint);
        self::assertStringNotContainsString("'draft'", $mint);
        self::assertStringContainsString("'post_status'  => 'publish'", $mint);
    }
}
