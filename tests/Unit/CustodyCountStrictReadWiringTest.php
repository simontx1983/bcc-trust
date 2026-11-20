<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-503: the custody cap must read the owned-group count through the STRICT
 * twin, so a read that did not run arrives as null and the gate refuses.
 *
 * ⚠⚠ WHY THIS FILE EXISTS AT ALL, which is worth reading before trusting it.
 *
 * `CapabilityMatrixTest` proves the COMPARISON layer: given null,
 * `resolveCustodyAcquisition()` denies `unavailable` for both cap-gated
 * keys. But that suite deliberately OVERRIDES `custodyState()` to inject
 * facts — which is what makes its matrix readable, and also means it can
 * never see which repository method `custodyState()` actually calls. Swap
 * the strict twin back to the lenient one and every test over there still
 * passes, because the real `custodyState()` never runs.
 *
 * That is the stubbed-collaborator blind spot: a suite that fakes the
 * collaborator cannot catch the collaborator being wrong, however green it
 * is. So the choice of read gets its own pin here.
 *
 * ⚠ THIS PROVES THE CODE SAYS IT, NOT THAT IT DOES IT — the same honest
 * limit `FeedReadFailureWiringTest` states for F-275. The behavioural half
 * (a real failed count on a real database producing a refusal) has NO
 * integration coverage yet: there is no custody integration test in this
 * repo. That gap is recorded rather than papered over, and it is the reason
 * this file asserts on source text instead of pretending to more.
 */
final class CustodyCountStrictReadWiringTest extends TestCase
{
    private const RESOLVER = 'app/Domain/Core/Services/Capability/CapabilityResolver.php';

    private string $source;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 2) . '/' . self::RESOLVER;
        self::assertFileExists($path, 'the resolver moved; this pin is now looking at nothing');
        $this->source = (string) file_get_contents($path);
        $this->code   = self::withoutComments($this->source);
    }

    /**
     * The source with comments and docblocks REMOVED.
     *
     * ⚠⚠ Load-bearing, and I got this wrong first. The docblock in
     * `custodyState()` explains the defect by NAMING the lenient twin —
     * `countOwnedUserGroups()` — so a plain substring search over the raw
     * file reports the lenient call as present when only prose mentions it.
     * A comment that names a mechanism is not a use of it, and a test that
     * cannot tell the difference fails the moment someone documents their
     * reasoning.
     */
    private static function withoutComments(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * The strict twin is the one wired in.
     *
     * ⛔ Mutation control security asked for: swapping this call back to
     * `countOwnedUserGroups()` makes a failed read answer 0, the cap check
     * pass, and a durable write be admitted. That swap fails HERE.
     */
    public function testTheCustodyCountIsReadThroughTheStrictTwin(): void
    {
        self::assertStringContainsString(
            'PeepSoGroupRepository::countOwnedUserGroupsOrFail(',
            $this->code,
            'the custody count is not read through the strict twin'
        );
    }

    /**
     * And the lenient twin is NOT wired in anywhere in this file.
     *
     * ⚠ Asserted separately from the above, because adding the strict call
     * while leaving a lenient one behind would satisfy the first test while
     * the gate still had a permissive path through it.
     */
    public function testTheLenientTwinIsNotUsedByTheResolver(): void
    {
        // `countOwnedUserGroupsOrFail` CONTAINS `countOwnedUserGroups`, so
        // the strict calls are removed before looking for a lenient one.
        // Comments are already gone (see withoutComments) — the docblock
        // names the lenient twin while explaining why it is not used.
        $withoutStrict = str_replace('countOwnedUserGroupsOrFail(', '', $this->code);

        self::assertStringNotContainsString(
            'countOwnedUserGroups(',
            $withoutStrict,
            'the lenient twin is still reachable from the capability resolver'
        );
    }

    /**
     * ⭐⭐ THE UNKNOWN MUST LEAVE `custodyState()` AS NULL. Found by a
     * mutant that SURVIVED both suites: keep the strict read, keep the
     * caller's null check, and simply convert the null to 0 here —
     *
     *     if ($owned === null) { $owned = 0; }
     *
     * — and the fix is completely defeated while every other test stays
     * green. `CapabilityMatrixTest` cannot see it because it overrides this
     * method; the pins above cannot see it because the strict call and the
     * caller's null check are both still present.
     *
     * So this asserts the branch RETURNS rather than substitutes. Collapsing
     * "unknown" into a number at this layer is the one thing that makes it
     * unrecoverable — by the time it reaches the comparison it is
     * indistinguishable from a real count.
     */
    public function testAnUnreadableCountLeavesCustodyStateAsNull(): void
    {
        $at = strpos($this->code, '$owned === null');
        self::assertNotFalse($at, 'the unreadable-count branch in custodyState() is gone');

        $branch = substr($this->code, $at, 160);

        self::assertStringContainsString(
            'return null;',
            $branch,
            'custodyState() does not return null for an unreadable count'
        );
        self::assertStringNotContainsString(
            '$owned = 0',
            $branch,
            'custodyState() substitutes a zero count for an unreadable one'
        );
    }

    /**
     * The null it produces is REFUSED rather than compared.
     *
     * ⚠ A source pin on the ORDER of two statements, which is exactly as
     * weak as it sounds — the behavioural proof is
     * CapabilityMatrixTest::testAnUnreadableOwnedCountDeniesEveryCapGatedWrite.
     * It is here because the ordering is the part a refactor would break
     * silently: moving the cap comparison above the null check reintroduces
     * the defect while every matrix test still passes.
     */
    public function testTheNullCheckPrecedesTheCapComparison(): void
    {
        $nullCheck = strpos($this->code, '$custody === null');
        $capCheck  = strpos($this->code, "\$custody['owned'] >= \$custody['cap']");

        self::assertNotFalse($nullCheck, 'the unreadable-count check is gone');
        self::assertNotFalse($capCheck, 'the cap comparison moved; this pin needs updating');
        self::assertLessThan(
            $capCheck,
            $nullCheck,
            'the cap comparison runs before the unreadable-count check, so null is compared as a number'
        );
    }
}
