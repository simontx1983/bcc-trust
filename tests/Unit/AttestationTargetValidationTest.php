<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\AttestationException;
use BCC\Trust\Core\Services\AttestationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * POST /me/attestations validates its TARGET, not just that it exists.
 *
 * It used to check only that a card target was SOME published post and a
 * user_profile target was SOME user. So a member could "vouch" for a feed
 * post, a secret-group post or an audience-limited post (which belled its
 * author and fed rank evidence), target a private, suspended or blocking
 * member, and read which post ids exist from 404 vs 201/422.
 *
 * Now:
 *   - a *_card target is a peepso-page whose page type maps to exactly that
 *     target_kind (the legacy /endorse rule; a page with no stored type is
 *     a legacy project page, as its card renders it);
 *   - a user_profile target is a member the attestor may view
 *     (ProfileAccess) with no block either way;
 *   - every refusal is the SAME 404 a missing target gets; an unreadable
 *     block table is a retryable 503, never an admit.
 *
 * Observable for "the target gate passed": the attestor here is below
 * Neutral, so the NEXT gate (tier) throws bcc_attestation_ineligible 403.
 *
 * A REAFFIRM renews a vouch (decay reset, owner belled), so it owes the
 * same target rules, or a row cast before them could be renewed forever.
 * Its "passed" observable is the per-attestor lock, stubbed busy (429).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(AttestationService::class)]
final class AttestationTargetValidationTest extends TestCase
{
    private const ATTESTOR = 40;
    private const MEMBER   = 41;
    private const OWNER    = 42;

    private const VALIDATOR_PAGE = 900;
    private const PROJECT_PAGE   = 901;
    private const LEGACY_PAGE    = 902;
    private const MEMBER_PAGE    = 903;
    private const FEED_POST      = 904;
    private const DRAFT_PAGE     = 905;
    private const POST_WITH_META = 906;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/attestation-target-stubs.php';
        \AttestTargetWorld::reset();
        \AttestTargetWorld::$users = [self::ATTESTOR => true, self::MEMBER => true, self::OWNER => true];

        $page = static fn(string $type, string $pageType, string $status = 'publish'): array
            => ['type' => $type, 'status' => $status, 'author' => self::OWNER, 'page_type' => $pageType];
        \AttestTargetWorld::$posts = [
            self::VALIDATOR_PAGE => $page('peepso-page', 'validator'),
            self::PROJECT_PAGE   => $page('peepso-page', 'builder'),
            self::LEGACY_PAGE    => $page('peepso-page', ''),
            self::MEMBER_PAGE    => $page('peepso-page', 'member'),
            self::FEED_POST      => $page('peepso-post', ''),
            self::DRAFT_PAGE     => $page('peepso-page', 'validator', 'draft'),
            // Not a page, yet carrying a page type: only the post_type check refuses it.
            self::POST_WITH_META => $page('peepso-post', 'validator'),
        ];
    }

    private static function service(): AttestationService
    {
        $svc = (new \ReflectionClass(AttestationService::class))->newInstanceWithoutConstructor();
        $rep = new class extends ReputationRepository {
            public function __construct() {}

            public function getTier(int $userId): string
            {
                return 'caution';
            }
        };
        (new \ReflectionProperty(AttestationService::class, 'reputationRepo'))->setValue($svc, $rep);
        // The real repository; its one read goes to the world's $wpdb.
        $repo = (new \ReflectionClass(AttestationRepository::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AttestationService::class, 'repo'))->setValue($svc, $repo);

        return $svc;
    }

    /** @return array{0: string, 1: int, 2: string} code, status, message */
    private static function reaffirmOutcome(string $targetKind, int $targetId): array
    {
        \AttestTargetWorld::$attestation = (object) [
            'id'               => 1,
            'attestor_user_id' => self::ATTESTOR,
            'target_kind'      => $targetKind,
            'target_id'        => $targetId,
            'kind'             => 'vouch',
            'revoked_at'       => null,
        ];
        try {
            self::service()->reaffirm(self::ATTESTOR, 1);
        } catch (AttestationException $e) {
            return [$e->errorCode, $e->status, $e->getMessage()];
        }
        self::fail('the busy lock should have thrown');
    }

    private static function reaffirmPassedTheTargetGate(string $targetKind, int $targetId): void
    {
        self::assertSame(AttestationException::CODE_RATE_LIMITED, self::reaffirmOutcome($targetKind, $targetId)[0]);
    }

    /** A refused reaffirm answers the cast's 404 and never reaches the renewal. */
    private static function reaffirmRefused(string $targetKind, int $targetId): void
    {
        $before = \AttestTargetWorld::$lockAttempts;
        self::assertSame(self::notFound(), self::reaffirmOutcome($targetKind, $targetId));
        self::assertSame($before, \AttestTargetWorld::$lockAttempts, 'refused before the lock, so nothing is renewed');
    }

    /** @return array{0: string, 1: int, 2: string} code, status, message */
    private static function outcome(string $targetKind, int $targetId): array
    {
        try {
            self::service()->checkCastEligibility(self::ATTESTOR, $targetKind, $targetId, 'vouch');
        } catch (AttestationException $e) {
            return [$e->errorCode, $e->status, $e->getMessage()];
        }
        self::fail('the tier gate should have thrown');
    }

    /** What a target that does not exist at all answers; every refusal must match it. */
    private static function notFound(): array
    {
        return self::outcome('user_profile', 999999);
    }

    private static function passedTheTargetGate(string $targetKind, int $targetId): void
    {
        self::assertSame(AttestationException::CODE_INELIGIBLE, self::outcome($targetKind, $targetId)[0]);
    }

    // ── card kinds ──────────────────────────────────────────────────────

    public function testAnEntityPageOfTheMatchingKindIsAccepted(): void
    {
        self::passedTheTargetGate('validator_card', self::VALIDATOR_PAGE);
        self::passedTheTargetGate('project_card', self::PROJECT_PAGE);
    }

    public function testAPageWithNoStoredTypeIsALegacyProjectPageAsOnItsCard(): void
    {
        self::passedTheTargetGate('project_card', self::LEGACY_PAGE);
        self::assertSame(self::notFound(), self::outcome('validator_card', self::LEGACY_PAGE));
    }

    public function testAFeedPostIsNotACard(): void
    {
        self::assertSame(self::notFound(), self::outcome('validator_card', self::FEED_POST));
        self::assertSame(self::notFound(), self::outcome('project_card', self::FEED_POST));
    }

    public function testANonPagePostIsRefusedEvenIfItCarriesAPageType(): void
    {
        self::assertSame(self::notFound(), self::outcome('validator_card', self::POST_WITH_META));
    }

    public function testThePageKindMustMatchTheTargetKind(): void
    {
        self::assertSame(self::notFound(), self::outcome('project_card', self::VALIDATOR_PAGE));
        self::assertSame(self::notFound(), self::outcome('creator_card', self::PROJECT_PAGE));
        self::assertSame(self::notFound(), self::outcome('validator_card', self::MEMBER_PAGE));
    }

    public function testAnUnpublishedPageStaysNotFound(): void
    {
        self::assertSame(self::notFound(), self::outcome('validator_card', self::DRAFT_PAGE));
    }

    // ── user_profile ────────────────────────────────────────────────────

    public function testAViewableUnblockedMemberIsAccepted(): void
    {
        self::passedTheTargetGate('user_profile', self::MEMBER);
    }

    public function testAMemberTheAttestorMayNotViewLooksLikeNobody(): void
    {
        \AttestTargetWorld::$viewable[self::MEMBER] = false;

        self::assertSame(self::notFound(), self::outcome('user_profile', self::MEMBER));
    }

    public function testABlockInEitherDirectionLooksLikeNobody(): void
    {
        \AttestTargetWorld::$blocked[self::MEMBER] = true;

        self::assertSame(self::notFound(), self::outcome('user_profile', self::MEMBER));
    }

    public function testAnUnreadableBlockTableIsARetryable503NeverAnAdmit(): void
    {
        \AttestTargetWorld::$blocked[self::MEMBER] = null;

        [$code, $status] = self::outcome('user_profile', self::MEMBER);
        self::assertSame(AttestationException::CODE_UNAVAILABLE, $code);
        self::assertSame(503, $status);
    }

    public function testSelfTargetIsStillTheStructural422(): void
    {
        self::assertSame(AttestationException::CODE_SELF, self::outcome('user_profile', self::ATTESTOR)[0]);
    }

    public function testTheNotFoundShapeIsTheExisting404(): void
    {
        self::assertSame([AttestationException::CODE_NOT_FOUND, 404, 'Target not found.'], self::notFound());
    }

    // ── reaffirm owes the same target rules ─────────────────────────────

    public function testAReaffirmOfAValidTargetStillProceeds(): void
    {
        self::reaffirmPassedTheTargetGate('validator_card', self::VALIDATOR_PAGE);
        self::reaffirmPassedTheTargetGate('project_card', self::LEGACY_PAGE);
        self::reaffirmPassedTheTargetGate('user_profile', self::MEMBER);
    }

    public function testAVouchCastOnANonCardCannotBeReaffirmed(): void
    {
        self::reaffirmRefused('validator_card', self::FEED_POST);
        self::reaffirmRefused('validator_card', self::POST_WITH_META);
        self::reaffirmRefused('project_card', self::VALIDATOR_PAGE);
    }

    public function testAVouchOnAGoneOrUnpublishedTargetCannotBeReaffirmed(): void
    {
        self::reaffirmRefused('validator_card', self::DRAFT_PAGE);
        self::reaffirmRefused('user_profile', 999999);
    }

    public function testAVouchOnAMemberNowHiddenOrBlockedCannotBeReaffirmed(): void
    {
        \AttestTargetWorld::$viewable[self::MEMBER] = false;
        self::reaffirmRefused('user_profile', self::MEMBER);

        \AttestTargetWorld::$viewable = [];
        \AttestTargetWorld::$blocked[self::MEMBER] = true;
        self::reaffirmRefused('user_profile', self::MEMBER);
    }

    public function testAReaffirmOnAnUnreadableBlockTableIsARetryable503(): void
    {
        \AttestTargetWorld::$blocked[self::MEMBER] = null;

        [$code, $status] = self::reaffirmOutcome('user_profile', self::MEMBER);
        self::assertSame(AttestationException::CODE_UNAVAILABLE, $code);
        self::assertSame(503, $status);
        self::assertSame(0, \AttestTargetWorld::$lockAttempts);
    }
}
