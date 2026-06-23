<?php
/**
 * Page Score Value Object
 * 
 * Immutable representation of a page's trust score with validation and business logic
 * 
 * @package BCC\Trust\Core\ValueObjects
 * @version 2.2.0
 */

namespace BCC\Trust\Core\ValueObjects;

use InvalidArgumentException;
use DateTimeImmutable;

if (!defined('ABSPATH')) {
    exit;
}

class PageScore {
    
    private int $pageId;
    private int $pageOwnerId;
    private float $totalScore;
    private float $positiveScore;
    private float $negativeScore;
    private int $voteCount;
    private int $uniqueVoters;
    private float $confidenceScore;
    private string $reputationTier;
    private int $endorsementCount;
    private float $endorsementBonus;
    private float $onchainBonus;
    private float $contributionBonus;
    private ?DateTimeImmutable $lastVoteAt;
    private DateTimeImmutable $lastCalculatedAt;
    /** @var array<string, mixed>|null */
    private ?array $fraudMetadata;

    /**
     * Valid tier values (matches config tiers)
     */
    private const VALID_TIERS = ['elite', 'trusted', 'neutral', 'caution', 'risky'];

    /**
     * Expose the tier whitelist to repositories that need to validate
     * caller-supplied tier strings before writing.
     *
     * @return list<string>
     */
    public static function validTiers(): array
    {
        return self::VALID_TIERS;
    }

    /**
     * Tolerance for floating point comparison.
     * Public so verification tooling (intent-guard, debug service, repair
     * service) uses the same epsilon the production validator does.
     */
    public const SCORE_TOLERANCE = 0.5;

    /**
     * Expected total score from components.
     *
     * Thin delegate over the canonical implementation in
     * \BCC\Trust\Core\Services\TrustScoreService::compute(). Kept as a
     * static method on this VO for backward compatibility with existing
     * callers (validateScores() below, debug panel's `formula_check`, the
     * repair service's reconciliation, and the intent-guard runtime
     * invariant) — but the formula itself lives in ONE place per §A4.
     *
     * @param float $positive          positive_score column
     * @param float $negative          negative_score column
     * @param float $endorsementBonus  endorsement_bonus column
     * @param float $onchainBonus      onchain_bonus column
     * @param float $contributionBonus contribution_bonus column (0 for entity pages)
     */
    public static function computeExpectedTotal(
        float $positive,
        float $negative,
        float $endorsementBonus,
        float $onchainBonus,
        float $contributionBonus = 0.0
    ): float {
        return \BCC\Trust\Core\Services\TrustScoreService::compute(
            $positive,
            $negative,
            $endorsementBonus,
            $onchainBonus,
            $contributionBonus
        );
    }


    /**
     * Constructor with validation.
     *
     * @param array<string, mixed>|null $fraudMetadata
     */
    public function __construct(
        int $pageId,
        int $pageOwnerId,
        float $totalScore,
        float $positiveScore,
        float $negativeScore,
        int $voteCount,
        int $uniqueVoters,
        float $confidenceScore,
        string $reputationTier,
        int $endorsementCount,
        ?DateTimeImmutable $lastVoteAt,
        ?DateTimeImmutable $lastCalculatedAt = null,
        ?array $fraudMetadata = null,
        float $endorsementBonus = 0.0,
        float $onchainBonus = 0.0,
        float $contributionBonus = 0.0
    ) {
        // Set pageId first (needed by validateCounts logging)
        $this->pageId = $pageId;

        // Validate all inputs
        $this->validatePageId($pageId);
        $this->validateOwnerId($pageOwnerId);
        $this->validateScores($totalScore, $positiveScore, $negativeScore, $voteCount, $endorsementBonus, $onchainBonus, $contributionBonus);
        $this->validateCounts($voteCount, $uniqueVoters);
        $this->validateConfidence($confidenceScore);
        $this->validateTier($reputationTier);
        $this->validateEndorsements($endorsementCount);
        $this->pageOwnerId = $pageOwnerId;
        $this->totalScore = $totalScore;
        $this->positiveScore = $positiveScore;
        $this->negativeScore = $negativeScore;
        $this->voteCount = $voteCount;
        $this->uniqueVoters = $uniqueVoters;
        $this->confidenceScore = $confidenceScore;
        $this->reputationTier = $reputationTier;
        $this->endorsementCount = $endorsementCount;
        $this->endorsementBonus = $endorsementBonus;
        $this->onchainBonus = $onchainBonus;
        $this->contributionBonus = $contributionBonus;
        $this->lastVoteAt = $lastVoteAt;
        $this->lastCalculatedAt = $lastCalculatedAt ?? new DateTimeImmutable();
        $this->fraudMetadata = $fraudMetadata;
    }

    /**
     * ======================================================
     * VALIDATION METHODS
     * ======================================================
     */
    
    private function validatePageId(int $pageId): void {
        if ($pageId <= 0) {
            throw new InvalidArgumentException(
                sprintf('Page ID must be positive, got: %d', $pageId)
            );
        }
    }
    
    private function validateOwnerId(int $ownerId): void {
        if ($ownerId < 0) {
            throw new InvalidArgumentException(
                sprintf('Owner ID cannot be negative, got: %d', $ownerId)
            );
        }
    }
    
    private function validateScores(float $total, float $positive, float $negative, int $voteCount, float $endorsementBonus = 0.0, float $onchainBonus = 0.0, float $contributionBonus = 0.0): void {
        if ($total < 0 || $total > 100) {
            throw new InvalidArgumentException(
                sprintf('Total score must be between 0 and 100, got: %f', $total)
            );
        }

        if ($positive < 0) {
            throw new InvalidArgumentException(
                sprintf('Positive score cannot be negative, got: %f', $positive)
            );
        }

        if ($negative < 0) {
            throw new InvalidArgumentException(
                sprintf('Negative score cannot be negative, got: %f', $negative)
            );
        }

        // Only validate the mathematical relationship if there are inputs
        if ($voteCount > 0 || $positive > 0 || $negative > 0 || $endorsementBonus > 0 || $onchainBonus > 0 || $contributionBonus > 0) {
            $expectedTotal = self::computeExpectedTotal($positive, $negative, $endorsementBonus, $onchainBonus, $contributionBonus);
            $difference    = abs($total - $expectedTotal);

            if ($difference > self::SCORE_TOLERANCE) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Total score %f does not match formula (expected ~%f, difference %f, endorsement_bonus=%f, onchain_bonus=%f, contribution_bonus=%f)',
                        $total,
                        $expectedTotal,
                        $difference,
                        $endorsementBonus,
                        $onchainBonus,
                        $contributionBonus
                    )
                );
            }
        }
    }
    
    private function validateCounts(int $voteCount, int $uniqueVoters): void {
        if ($voteCount < 0) {
            throw new InvalidArgumentException(
                sprintf('Vote count cannot be negative, got: %d', $voteCount)
            );
        }
        
        if ($uniqueVoters < 0) {
            throw new InvalidArgumentException(
                sprintf('Unique voters cannot be negative, got: %d', $uniqueVoters)
            );
        }
        
        if ($uniqueVoters > $voteCount && $voteCount > 0) {
            // This can happen in edge cases, so we'll log but not throw
            \BCC\Core\Log\Logger::error(sprintf(
                'Warning: Unique voters (%d) exceeds vote count (%d) for page %d',
                $uniqueVoters,
                $voteCount,
                $this->pageId
            ));
        }
    }
    
    private function validateConfidence(float $confidence): void {
        if ($confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException(
                sprintf('Confidence must be between 0 and 1, got: %f', $confidence)
            );
        }
    }
    
    private function validateTier(string $tier): void {
        if (!in_array($tier, self::VALID_TIERS, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid reputation tier: %s. Must be one of: %s', 
                    $tier, 
                    implode(', ', self::VALID_TIERS)
                )
            );
        }
    }
    
    private function validateEndorsements(int $endorsementCount): void {
        if ($endorsementCount < 0) {
            throw new InvalidArgumentException(
                sprintf('Endorsement count cannot be negative, got: %d', $endorsementCount)
            );
        }
    }

    /**
     * ======================================================
     * GETTERS
     * ======================================================
     */
    
    public function getPageId(): int {
        return $this->pageId;
    }
    
    public function getPageOwnerId(): int {
        return $this->pageOwnerId;
    }
    
    public function getTotalScore(): float {
        return $this->totalScore;
    }
    
    public function getPositiveScore(): float {
        return $this->positiveScore;
    }
    
    public function getNegativeScore(): float {
        return $this->negativeScore;
    }
    
    public function getVoteCount(): int {
        return $this->voteCount;
    }
    
    public function getUniqueVoters(): int {
        return $this->uniqueVoters;
    }
    
    public function getConfidenceScore(): float {
        return $this->confidenceScore;
    }
    
    public function getReputationTier(): string {
        return $this->reputationTier;
    }
    
    public function getEndorsementCount(): int {
        return $this->endorsementCount;
    }

    public function getEndorsementBonus(): float {
        return $this->endorsementBonus;
    }

    public function getOnchainBonus(): float {
        return $this->onchainBonus;
    }

    public function getContributionBonus(): float {
        return $this->contributionBonus;
    }

    public function getLastVoteAt(): ?DateTimeImmutable {
        return $this->lastVoteAt;
    }
    
    public function getLastCalculatedAt(): DateTimeImmutable {
        return $this->lastCalculatedAt;
    }
    
    /**
     * @return array<string, mixed>|null
     */
    public function getFraudMetadata(): ?array {
        return $this->fraudMetadata;
    }

    /**
     * ======================================================
     * BUSINESS LOGIC METHODS
     * ======================================================
     */
    
    /**
     * Check if page is highly trusted (elite or trusted tier)
     */
    public function isHighlyTrusted(): bool {
        return in_array($this->reputationTier, ['elite', 'trusted'], true);
    }
    
    /**
     * Check if page is risky (caution or risky tier)
     */
    public function isRisky(): bool {
        return in_array($this->reputationTier, ['caution', 'risky'], true);
    }
    
    /**
     * Check if page has enough data for reliable scoring using config threshold
     */
    public function hasSufficientData(): bool {
        return $this->voteCount >= BCC_TRUST_MIN_VOTES_RELIABLE && $this->confidenceScore >= 0.5;
    }
    
    /**
     * Get net score (positive - negative)
     */
    public function getNetScore(): float {
        return $this->positiveScore - $this->negativeScore;
    }
    
    /**
     * Get confidence as percentage
     */
    public function getConfidencePercentage(): int {
        return (int) round($this->confidenceScore * 100);
    }
    
    /**
     * Get human-readable score status
     */
    public function getScoreStatus(): string {
        if ($this->totalScore >= 80) return 'Excellent';
        if ($this->totalScore >= 65) return 'Good';
        if ($this->totalScore >= 45) return 'Average';
        if ($this->totalScore >= 30) return 'Poor';
        return 'Very Poor';
    }
    
    /**
     * Get voter diversity ratio
     */
    public function getVoterDiversity(): float {
        if ($this->voteCount === 0) {
            return 0;
        }
        return $this->uniqueVoters / $this->voteCount;
    }

    /**
     * Check if page has any fraud alerts
     */
    public function hasFraudAlerts(): bool {
        if (empty($this->fraudMetadata)) {
            return false;
        }
        
        return isset($this->fraudMetadata['alerts']) && count($this->fraudMetadata['alerts']) > 0;
    }

    /**
     * Get fraud alert count
     */
    public function getFraudAlertCount(): int {
        if (empty($this->fraudMetadata) || !isset($this->fraudMetadata['alerts'])) {
            return 0;
        }
        
        return count($this->fraudMetadata['alerts']);
    }

    /**
     * ======================================================
     * FACTORY METHODS
     * ======================================================
     */
    
    /**
     * Create from database row.
     *
     * Accepts any object that carries the bcc_trust_page_scores columns
     * (the raw ScoreRow from ScoreRepository::getRawRow, or a joined variant
     * from getWithPageData). All numeric fields may arrive as numeric-string
     * from $wpdb — callers cast as needed.
     *
     * @param object{
     *   page_id: int|numeric-string,
     *   page_owner_id: int|numeric-string,
     *   total_score: float|numeric-string,
     *   positive_score: float|numeric-string,
     *   negative_score: float|numeric-string,
     *   vote_count: int|numeric-string,
     *   unique_voters: int|numeric-string,
     *   confidence_score: float|numeric-string,
     *   reputation_tier: string,
     *   endorsement_count: int|numeric-string,
     *   last_vote_at: string|null,
     *   last_calculated_at: string|null,
     *   fraud_metadata?: string|null,
     *   endorsement_bonus?: float|numeric-string|null,
     *   onchain_bonus?: float|numeric-string|null,
     *   contribution_bonus?: float|numeric-string|null
     * } $row
     */
    public static function fromDatabaseRow(object $row): self {
        return new self(
            (int) $row->page_id,
            (int) $row->page_owner_id,
            (float) $row->total_score,
            (float) $row->positive_score,
            (float) $row->negative_score,
            (int) $row->vote_count,
            (int) $row->unique_voters,
            (float) $row->confidence_score,
            $row->reputation_tier,
            (int) $row->endorsement_count,
            $row->last_vote_at ? new DateTimeImmutable($row->last_vote_at) : null,
            $row->last_calculated_at ? new DateTimeImmutable($row->last_calculated_at) : null,
            !empty($row->fraud_metadata) ? json_decode($row->fraud_metadata, true) : null,
            (float) ($row->endorsement_bonus ?? 0.0),
            (float) ($row->onchain_bonus ?? 0.0),
            (float) ($row->contribution_bonus ?? 0.0)
        );
    }
    
    /**
     * Create default score for new page
     */
    public static function createDefault(int $pageId, int $ownerId): self {
        return new self(
            $pageId,
            $ownerId,
            (float) BCC_TRUST_NEUTRAL_SCORE,  // total_score
            0.0,   // positive_score
            0.0,   // negative_score
            0,     // vote_count
            0,     // unique_voters
            0.0,   // confidence_score
            'neutral',
            0,     // endorsement_count
            null,  // last_vote_at
            new DateTimeImmutable(),
            null,  // fraud_metadata
            0.0,   // endorsement_bonus
            0.0    // onchain_bonus
        );
    }

    /**
     * ======================================================
     * CONVERSION METHODS
     * ======================================================
     */
    
    /**
     * Convert to array for database storage
     *
     * @return array<string, mixed>
     */
    public function toDatabaseArray(): array {
        return [
            'page_id' => $this->pageId,
            'page_owner_id' => $this->pageOwnerId,
            'total_score' => $this->totalScore,
            'positive_score' => $this->positiveScore,
            'negative_score' => $this->negativeScore,
            'vote_count' => $this->voteCount,
            'unique_voters' => $this->uniqueVoters,
            'confidence_score' => $this->confidenceScore,
            'reputation_tier' => $this->reputationTier,
            'endorsement_count' => $this->endorsementCount,
            'endorsement_bonus' => $this->endorsementBonus,
            'onchain_bonus' => $this->onchainBonus,
            'last_vote_at' => $this->lastVoteAt?->format('Y-m-d H:i:s'),
            'last_calculated_at' => $this->lastCalculatedAt->format('Y-m-d H:i:s'),
            'fraud_metadata' => $this->fraudMetadata ? json_encode($this->fraudMetadata) : null
        ];
    }
    
    /**
     * Convert to API response array
     *
     * @return array<string, mixed>
     */
    public function toApiResponse(): array {
        return [
            'page_id' => $this->pageId,
            'total_score' => round($this->totalScore, 1),
            'grade' => \BCC\Trust\Core\Support\Formatting::scoreToGrade((float) $this->totalScore),
            'reputation_tier' => $this->reputationTier,
            'confidence_score' => round($this->confidenceScore, 2),
            'confidence_percentage' => $this->getConfidencePercentage(),
            'vote_count' => $this->voteCount,
            'unique_voters' => $this->uniqueVoters,
            'endorsement_count' => $this->endorsementCount,
            'positive_score' => round($this->positiveScore, 1),
            'negative_score' => round($this->negativeScore, 1),
            'status' => $this->getScoreStatus(),
            'has_sufficient_data' => $this->hasSufficientData(),
            'voter_diversity' => round($this->getVoterDiversity(), 2),
            'net_score' => round($this->getNetScore(), 1),
            'has_fraud_alerts' => $this->hasFraudAlerts(),
            'fraud_alert_count' => $this->getFraudAlertCount(),
            'is_highly_trusted' => $this->isHighlyTrusted(),
            'is_risky' => $this->isRisky(),
            'endorsement_bonus' => round($this->endorsementBonus, 2),
            'onchain_bonus' => round($this->onchainBonus, 2),
        ];
    }
    
}