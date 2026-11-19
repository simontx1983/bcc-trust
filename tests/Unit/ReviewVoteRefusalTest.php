<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Exceptions\VoteEligibilityException;
use BCC\Trust\Core\REST\PostsEndpoint;
use BCC\Trust\Core\Services\PostsService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-391: what a review refused by the vote gates answers.
 *
 * The daily downvote cap and the general vote limit are RATE limits. They
 * used to answer bcc_forbidden 403 like an eligibility rule, and the general
 * limit escaped as a plain \Exception that the route logged as an ERROR and
 * reported as "Review could not be cast." A rate-limit refusal now carries
 * VoteEligibilityException::RATE_LIMITED and answers bcc_rate_limited, which
 * POST /posts turns into a 429 (the Envelope then adds the time left). Every
 * other refusal is unchanged.
 *
 * DownvoteDailyCapIntegrationTest proves the gates throw that code against
 * the real limiter. createReview() itself is not driven here: it resolves the
 * author's write_review capability through the Plugin first, which needs a
 * full rank fixture. Its catch is a one-line hand-off to this mapping.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReviewVoteRefusalTest extends TestCase
{
    /** @return array{error: string, message: string} */
    private static function refusal(VoteEligibilityException $e): array
    {
        $result = (new \ReflectionMethod(PostsService::class, 'reviewVoteRefusal'))->invoke(null, $e);
        self::assertIsArray($result);
        return $result;
    }

    public function testARateLimitRefusalIsRateLimitedWithItsMessage(): void
    {
        self::assertSame(
            ['error' => 'bcc_rate_limited', 'message' => 'Daily downvote limit reached.'],
            self::refusal(new VoteEligibilityException('Daily downvote limit reached.', VoteEligibilityException::RATE_LIMITED))
        );
    }

    public function testAnEligibilityRefusalStaysForbidden(): void
    {
        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'Account must be at least 24 hours old before voting.'],
            self::refusal(new VoteEligibilityException('Account must be at least 24 hours old before voting.'))
        );
    }

    /** Only the RATE_LIMITED code is a rate limit; any other code is still eligibility. */
    public function testOtherCodesStayForbidden(): void
    {
        foreach ([403, 500, 428, 430] as $code) {
            self::assertSame('bcc_forbidden', self::refusal(new VoteEligibilityException('Vote blocked.', $code))['error'], (string) $code);
        }
    }

    public function testAnEmptyMessageGetsTheGenericOne(): void
    {
        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'Review could not be cast.'],
            self::refusal(new VoteEligibilityException(''))
        );
    }

    public function testTheRateLimitCodeIs429(): void
    {
        self::assertSame(429, VoteEligibilityException::RATE_LIMITED);
    }

    /** The endpoint turns the error code into the status: bcc_rate_limited is a 429. */
    public function testThePostsRouteAnswersBccRateLimitedWith429(): void
    {
        $status = (new \ReflectionMethod(PostsEndpoint::class, 'statusForCode'))->invoke(null, 'bcc_rate_limited');
        self::assertSame(429, $status);
        $status = (new \ReflectionMethod(PostsEndpoint::class, 'statusForCode'))->invoke(null, 'bcc_forbidden');
        self::assertSame(403, $status);
    }
}
