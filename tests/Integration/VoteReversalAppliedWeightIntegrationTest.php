<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Application\Disputes\DisputeAdjudicator;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AdjudicationClaimRepository;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\TrustScoreService;
use BCC\Trust\Core\Services\Vote\VoteWriter;
use BCC\Trust\Core\Services\VoteService;
use BCC\Trust\Core\ValueObjects\VoteWeight;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-83: every reversal must subtract what the vote actually APPLIED to the
 * score — the velocity-capped vested_weight — not the raw `weight` column.
 *
 * VoteWriter caps a vote against the page's daily velocity budget and
 * correctWeight() persists the capped amount onto vested_weight only. The
 * unvote, direction-flip and dispute-accept paths read `weight`, so after
 * any clamp they subtracted MORE than was added; GREATEST(0, …) hid the
 * excess by eating other voters' contribution.
 *
 * Same-direction weight change (found alongside, same class): the whole new
 * weight was charged and capped, so against a spent budget a weight DROP
 * zeroed the vote for good and a weight RISE could make the score fall.
 * Now only an increase is charged (and capped); a decrease is never blocked.
 *
 * Every case keeps a BYSTANDER's 2.0 already on the page's positive_score,
 * which is exactly what an over-reversal destroys.
 */
#[Group('integration')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(VoteWriter::class)]
#[CoversClass(VoteService::class)]
#[CoversClass(DisputeAdjudicator::class)]
#[CoversClass(VoteRepository::class)]
final class VoteReversalAppliedWeightIntegrationTest extends TestCase
{
    private const VOTER     = 9831;
    private const OWNER     = 9832;
    private const BYSTANDER = 2.0;

    private int $page;

    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            eval('function do_action(string $hook, ...$args): void {}');
        }
        if (!function_exists('wp_next_scheduled')) {
            // Post-commit job dispatch: report "already scheduled" so the
            // fallback enqueue path stops there (no wp-cron in the harness).
            eval('function wp_next_scheduled(string $hook, array $args = []) { return 1; }');
        }
        foreach (['BCC\\Trust\\Core\\Security', 'BCC\\Trust\\Core\\Services'] as $ns) {
            if (!function_exists($ns . '\\get_current_user_id')) {
                eval('namespace ' . $ns . '; function get_current_user_id(): int { return (int) ($GLOBALS[\'bcc_itest_current_user\'] ?? 0); }');
            }
        }
        $GLOBALS['bcc_itest_current_user'] = self::VOTER;
        $GLOBALS['wpdb']->clearFaultInjection();

        $this->page = MemberSelfPageService::ID_BASE + self::OWNER;

        $wpdb = $GLOBALS['wpdb'];
        foreach (['bcc_trust_votes', 'bcc_trust_page_scores', 'bcc_trust_user_info', 'bcc_trust_page_scores_velocity'] as $t) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $t . '`');
        }
        $GLOBALS['__bcc_test_object_cache'] = [];

        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'        => $this->page,
            'category_id'    => 0,
            'page_owner_id'  => self::OWNER,
            'positive_score' => self::BYSTANDER,
            'vote_count'     => 1,
            'unique_voters'  => 1,
        ]), $wpdb->last_error);
        // total_score must agree with the canonical formula, or PageScore
        // refuses to hydrate the row.
        self::assertNotFalse($wpdb->query($wpdb->prepare(
            'UPDATE `' . $wpdb->prefix . 'bcc_trust_page_scores` SET total_score = ' . TrustScoreService::formulaSql() . ' WHERE page_id = %d',
            $this->page
        )), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_user_info', [
            'user_id'      => self::VOTER,
            'user_login'   => 'voter',
            'user_email'   => 'voter@example.test',
            'display_name' => 'Voter',
        ]), $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bcc_itest_current_user']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function writer(): VoteWriter
    {
        return new VoteWriter(new VoteRepository(), new ScoreRepository(), new UserInfoRepository());
    }

    private function weight(float $w): VoteWeight
    {
        return new VoteWeight($w, 1.0, [], $w, $w, 'neutral');
    }

    private function cast(int $voteType, float $w): void
    {
        $this->writer()->write(self::VOTER, $this->page, 0, self::OWNER, $voteType, $this->weight($w), false, null);
    }

    /** Leave exactly $weightLeft of today's velocity budget (in weight units). */
    private function leaveBudget(float $weightLeft): void
    {
        $scale = TrustScoreService::weightScoreScale();
        $used  = ((float) BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY - $weightLeft * $scale) / $scale;
        $wpdb  = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . TableRegistry::scoreVelocity() . '` (page_id, track_date, score_delta) VALUES (%d, %s, %f)
             ON DUPLICATE KEY UPDATE score_delta = VALUES(score_delta)',
            $this->page,
            current_time('Y-m-d'),
            $used
        ));
    }

    private function budgetUsed(): float
    {
        $wpdb = $GLOBALS['wpdb'];
        return (float) $wpdb->get_var($wpdb->prepare(
            'SELECT score_delta FROM `' . TableRegistry::scoreVelocity() . '` WHERE page_id = %d AND track_date = %s',
            $this->page,
            current_time('Y-m-d')
        ));
    }

    /** @return array{positive: float, negative: float} */
    private function score(): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT positive_score, negative_score FROM `' . $wpdb->prefix . 'bcc_trust_page_scores` WHERE page_id = %d AND category_id = 0',
            $this->page
        ));
        self::assertNotNull($row, (string) $wpdb->last_error);
        return ['positive' => (float) $row->positive_score, 'negative' => (float) $row->negative_score];
    }

    private function vestedWeight(): float
    {
        $wpdb = $GLOBALS['wpdb'];
        return (float) $wpdb->get_var($wpdb->prepare(
            'SELECT vested_weight FROM `' . $wpdb->prefix . 'bcc_trust_votes` WHERE voter_user_id = %d AND page_id = %d',
            self::VOTER,
            $this->page
        ));
    }

    private function voteId(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM `' . $wpdb->prefix . 'bcc_trust_votes` WHERE voter_user_id = %d AND page_id = %d',
            self::VOTER,
            $this->page
        ));
    }

    /**
     * Cast a 0.5 upvote that the budget clamps to 0.1. (Raw vote weights are
     * bounded to [BCC_TRUST_MIN_VOTE_WEIGHT, BCC_TRUST_MAX_VOTE_WEIGHT] =
     * [0.1, 0.6], so fixtures stay inside that range.)
     */
    private function castClampedUpvote(): void
    {
        $this->leaveBudget(0.1);
        $this->cast(1, 0.5);
        self::assertEqualsWithDelta(0.1, $this->vestedWeight(), 0.0001, 'precondition: the vote was clamped to 0.1');
        self::assertEqualsWithDelta(self::BYSTANDER + 0.1, $this->score()['positive'], 0.0001, 'precondition: +0.1 applied');
    }

    // ── F-83 ────────────────────────────────────────────────────────────

    public function testFlippingAClampedVoteReversesOnlyWhatItApplied(): void
    {
        $this->castClampedUpvote();

        $this->cast(-1, 0.5); // budget now spent: the new direction gets 0

        self::assertEqualsWithDelta(self::BYSTANDER, $this->score()['positive'], 0.0001, 'the flip ate the bystander\'s contribution');
    }

    public function testRemovingAClampedVoteReversesOnlyWhatItApplied(): void
    {
        $this->castClampedUpvote();

        $this->voteService()->removePageVote($this->page);

        self::assertEqualsWithDelta(self::BYSTANDER, $this->score()['positive'], 0.0001, 'the unvote ate the bystander\'s contribution');
    }

    public function testAcceptingADisputeOnAClampedVoteReversesOnlyWhatItApplied(): void
    {
        $this->castClampedUpvote();

        // The adjudicator's post-commit synchronous recalc rebuilds the score
        // from vote rows and would mask the incremental reversal. Fail the
        // recalc's final save (wpdb->update of total_score, which throws and
        // rolls the recalc back); the adjudicator swallows a recalc failure
        // and leaves it to the cron, which is exactly the window the
        // incremental reversal governs. The reversal itself is a raw
        // `SET positive_score = …` query and does not match.
        $GLOBALS['wpdb']->failQueriesMatching = '/^UPDATE `[^`]*bcc_trust_page_scores` SET .*`total_score`/s';
        $ok = (new DisputeAdjudicator(new VoteRepository(), new ScoreRepository(), new UserInfoRepository(), new AdjudicationClaimRepository()))
            ->acceptVoteDispute(98301, $this->voteId(), $this->page, self::VOTER, 1);

        $recalcBroken = $GLOBALS['wpdb']->injectedFailures;
        $GLOBALS['wpdb']->clearFaultInjection();
        self::assertTrue($ok, 'precondition: the dispute was applied');
        self::assertGreaterThan(0, $recalcBroken, 'precondition: the masking recalc was stopped');
        self::assertEqualsWithDelta(self::BYSTANDER, $this->score()['positive'], 0.0001, 'the dispute removal ate the bystander\'s contribution');
    }

    // ── same-direction weight change ────────────────────────────────────

    public function testAWeightIncreaseChargesOnlyTheIncrease(): void
    {
        $this->cast(1, 0.2);            // unclamped: applied 0.2
        $this->leaveBudget(0.1);
        $before = $this->budgetUsed();

        $this->cast(1, 0.5);            // +0.3 wanted, 0.1 allowed

        self::assertEqualsWithDelta(0.3, $this->vestedWeight(), 0.0001, 'applied = previous 0.2 + capped increase 0.1');
        self::assertEqualsWithDelta(self::BYSTANDER + 0.3, $this->score()['positive'], 0.0001);
        self::assertEqualsWithDelta(0.1, $this->budgetUsed() - $before, 0.0001, 'only the increase is charged');
    }

    public function testAWeightIncreaseAgainstASpentBudgetDoesNotLowerTheScore(): void
    {
        $this->cast(1, 0.2);
        $this->leaveBudget(0.0);

        $this->cast(1, 0.4);

        self::assertEqualsWithDelta(0.2, $this->vestedWeight(), 0.0001, 'the vote keeps what it already had');
        self::assertEqualsWithDelta(self::BYSTANDER + 0.2, $this->score()['positive'], 0.0001);
    }

    public function testAWeightDecreaseIsNeverBlockedAndChargesNothing(): void
    {
        $this->cast(1, 0.5);
        $this->leaveBudget(0.0);
        $before = $this->budgetUsed();

        $this->cast(1, 0.2);

        self::assertEqualsWithDelta(0.2, $this->vestedWeight(), 0.0001, 'a spent budget zeroed the vote for good');
        self::assertEqualsWithDelta(self::BYSTANDER + 0.2, $this->score()['positive'], 0.0001);
        self::assertEqualsWithDelta(0.0, $this->budgetUsed() - $before, 0.0001, 'a decrease consumes no budget');
    }

    private function voteService(): VoteService
    {
        $ref = new \ReflectionClass(VoteService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        foreach ([
            'voteRepo'        => new VoteRepository(),
            'scoreRepo'       => new ScoreRepository(),
            'attestationRepo' => new AttestationRepository(),
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setValue($svc, $value);
        }
        return $svc;
    }
}
