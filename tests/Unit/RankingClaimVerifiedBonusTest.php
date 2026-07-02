<?php

declare(strict_types=1);

namespace BCC\Core\Contracts {
    // ScoreReadService implements this bcc-core interface; the sibling
    // plugin isn't autoloaded in the unit context, so stub the contract
    // (the two methods ScoreReadService defines) to let the class load.
    if (!interface_exists(__NAMESPACE__ . '\\ScoreReadServiceInterface')) {
        interface ScoreReadServiceInterface
        {
            /**
             * @param int[] $pageIds
             * @return array<int, array{total_score: float, reputation_tier: string}>
             */
            public function getScoresForPageIds(array $pageIds): array;

            /**
             * @param int[] $pageIds
             * @return array<int, array<string, mixed>>
             */
            public function getEnrichedScoresForPageIds(array $pageIds): array;
        }
    }
}

namespace BCC\Trust\Core\Application {
    // Namespace-local apply_filters polyfill: computeRankingScore() calls the
    // unqualified apply_filters(), which PHP resolves to this namespace first.
    // Returns the passed-through default so the test exercises the shipped
    // BCC_RANK_CLAIM_VERIFIED_BONUS without WordPress loaded.
    if (!function_exists(__NAMESPACE__ . '\\apply_filters')) {
        /**
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value)
        {
            return $value;
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Application\ScoreReadService;
    use PHPUnit\Framework\TestCase;

    /**
     * Pins the claim-verified ranking bonus: the pure
     * ScoreReadService::computeRankingScore() must add
     * BCC_RANK_CLAIM_VERIFIED_BONUS iff has_verified_claim is true, and the
     * bonus must dominate the +3.0 owner-EMAIL verified term.
     *
     * A regression that drops the on-chain claim signal from ranking — or
     * that lets the email term outweigh it — turns this suite red.
     */
    final class RankingClaimVerifiedBonusTest extends TestCase
    {
        /**
         * Neutral inputs that zero out every OTHER ranking term so the test
         * isolates the claim-verified contribution:
         *   - confidence 0.0 → trust term = 0
         *   - onchain_bonus 0.0 → onchain term = 0
         *   - 0 endorsements → log term = 0
         *   - uniqueVoters 0 → voter-diversity term = 0
         * lastVoteAt "now" keeps freshness at 1.0 (irrelevant since the
         * trust term is already zeroed by confidence).
         */
        private function score(bool $isVerified, bool $hasVerifiedClaim): float
        {
            return ScoreReadService::computeRankingScore(
                50.0,   // trustScore
                0.0,    // confidence
                0.0,    // onchainBonus
                0,      // endorsementCount
                0,      // uniqueVoters
                1,      // voteCount
                $isVerified,
                $hasVerifiedClaim,
                'builder',
                gmdate('Y-m-d H:i:s')
            );
        }

        public function testNoClaimAddsNoBonus(): void
        {
            self::assertSame(0.0, $this->score(false, false));
        }

        public function testVerifiedClaimAddsTheBonus(): void
        {
            self::assertSame(
                (float) BCC_RANK_CLAIM_VERIFIED_BONUS,
                $this->score(false, true)
            );
        }

        public function testEmailVerifiedAloneAddsOnlyThreePoints(): void
        {
            self::assertSame(3.0, $this->score(true, false));
        }

        public function testClaimBonusDominatesEmailVerifiedTerm(): void
        {
            // Both terms stack; the claim term (default 10.0) must be the
            // dominant signal (> the 3.0 email term).
            self::assertGreaterThan(
                $this->score(true, false),   // email-only
                $this->score(false, true)    // claim-only
            );
            self::assertSame(
                3.0 + (float) BCC_RANK_CLAIM_VERIFIED_BONUS,
                $this->score(true, true)
            );
        }

        public function testBonusConstantDefault(): void
        {
            self::assertSame(10.0, (float) BCC_RANK_CLAIM_VERIFIED_BONUS);
        }

        /**
         * @param int|bool $hasVerifiedClaim
         */
        private function row($hasVerifiedClaim): object
        {
            // Mirrors the read-model row shape ScoreReadService::enrichRow
            // consumes (values arrive as numeric strings from $wpdb).
            return (object) [
                'trust_score'        => '50.00',
                'confidence_score'   => '0.00',
                'onchain_bonus'      => '0.00',
                'endorsement_count'  => '0',
                'unique_voters'      => '0',
                'vote_count'         => '1',
                'is_verified'        => '0',
                'has_verified_claim' => $hasVerifiedClaim,
                'page_type'          => 'builder',
                'last_vote_at'       => gmdate('Y-m-d H:i:s'),
                'reputation_tier'    => 'neutral',
                'follower_count'     => '0',
            ];
        }

        public function testEnrichRowCarriesIsClaimVerifiedTrue(): void
        {
            $enriched = ScoreReadService::enrichRow($this->row(1));

            self::assertArrayHasKey('is_claim_verified', $enriched);
            self::assertTrue($enriched['is_claim_verified']);
            // Ranking picks up the bonus for a claim-verified page.
            self::assertSame(
                round((float) BCC_RANK_CLAIM_VERIFIED_BONUS, 4),
                $enriched['ranking_score']
            );
        }

        public function testEnrichRowCarriesIsClaimVerifiedFalse(): void
        {
            $enriched = ScoreReadService::enrichRow($this->row(0));

            self::assertArrayHasKey('is_claim_verified', $enriched);
            self::assertFalse($enriched['is_claim_verified']);
            self::assertSame(0.0, $enriched['ranking_score']);
        }
    }
}
