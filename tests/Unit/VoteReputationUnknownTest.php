<?php
/**
 * F-469: the vote path's reputation reads fail CLOSED on unknown.
 *
 * - VoteEligibilityChecker::assertDownvoteEligibility(): a tier that could
 *   not be read throws RepositoryReadFailure, which the eligibility cache
 *   (it caches only VoteEligibilityException) never stores. A member with no
 *   row is still neutral, so refused a downvote as before.
 * - VoteService::getVoterReputation() (the vote's weight and the dispute
 *   ballot snapshot): throws on unknown and caches nothing.
 *
 * The routes' answers (PostsService → bcc_unavailable 503; disputes →
 * KIND_UNAVAILABLE 503) are pinned in DisputePanelRetirementTest and the
 * contract note.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Exceptions\VoteEligibilityException;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\Vote\VoteEligibilityChecker;
use BCC\Trust\Core\Services\VoteService;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(VoteEligibilityChecker::class)]
#[CoversClass(VoteService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VoteReputationUnknownTest extends TestCase
{
    private const VOTER = 41;

    public function testAnUnreadableTierFailsTheDownvoteCheckWithAReadFailureNotAnEligibilityAnswer(): void
    {
        $checker = $this->checker(false);

        $this->expectException(RepositoryReadFailure::class);
        (new \ReflectionMethod(VoteEligibilityChecker::class, 'assertDownvoteEligibility'))->invoke($checker, self::VOTER);
    }

    /** No row is neutral, and neutral may not downvote: the old answer, unchanged. */
    public function testAMemberWithNoRowIsStillNeutralForTheDownvoteCheck(): void
    {
        $checker = $this->checker(null);

        try {
            (new \ReflectionMethod(VoteEligibilityChecker::class, 'assertDownvoteEligibility'))->invoke($checker, self::VOTER);
            self::fail('a neutral member may not downvote');
        } catch (VoteEligibilityException $e) {
            self::assertSame('Only trusted or elite members can downvote.', $e->getMessage());
        }
    }

    public function testAnUnreadableReputationFailsTheVoteWeightAndIsNeverCached(): void
    {
        $service = $this->voteService(false);
        $read    = new \ReflectionMethod(VoteService::class, 'getVoterReputation');

        try {
            $read->invoke($service, self::VOTER);
            self::fail('an unreadable reputation must not weight a vote');
        } catch (RepositoryReadFailure) {
            // the route's 503
        }

        $cache = (new \ReflectionProperty(VoteService::class, 'requestCache'))->getValue();
        self::assertArrayNotHasKey('reputation:' . self::VOTER, $cache, 'nothing cached');
    }

    public function testAMemberWithNoRowWeighsAsBeforeAndIsCached(): void
    {
        $service = $this->voteService(null);

        self::assertNull((new \ReflectionMethod(VoteService::class, 'getVoterReputation'))->invoke($service, self::VOTER));
        $cache = (new \ReflectionProperty(VoteService::class, 'requestCache'))->getValue();
        self::assertArrayHasKey('reputation:' . self::VOTER, $cache);
    }

    /** A reputation double answering getByUserIdOrFail() with $answer. */
    private static function reputation(object|null|false $answer): ReputationRepository
    {
        $rep = new class extends ReputationRepository {
            public object|null|false $answer = null;

            public function __construct()
            {
            }

            public function getByUserIdOrFail(int $userId): object|null|false
            {
                unset($userId);

                return $this->answer;
            }
        };
        $rep->answer = $answer;

        return $rep;
    }

    private function checker(object|null|false $answer): VoteEligibilityChecker
    {
        $checker = (new \ReflectionClass(VoteEligibilityChecker::class))->newInstanceWithoutConstructor();
        $rep     = self::reputation($answer);
        // The property is readonly: initialise it from the class's own scope.
        \Closure::bind(function () use ($rep): void {
            $this->reputationRepo = $rep;
        }, $checker, VoteEligibilityChecker::class)();

        return $checker;
    }

    private function voteService(object|null|false $answer): VoteService
    {
        $service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(VoteService::class, 'reputationRepo'))->setValue($service, self::reputation($answer));
        (new \ReflectionProperty(VoteService::class, 'requestCache'))->setValue(null, []);

        return $service;
    }
}
