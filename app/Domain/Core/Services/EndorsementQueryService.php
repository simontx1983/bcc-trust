<?php
/**
 * Endorsement Query Service
 *
 * Read-only query methods for endorsement data.
 * Extracted from EndorsementService — all behavior, return types,
 * defaults, array structures, and repository usage preserved exactly.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementQueryService {

    private EndorsementRepository $endorsementRepo;
    private UserInfoRepository $userInfoRepo;

    public function __construct() {
        $this->endorsementRepo = new EndorsementRepository();
        $this->userInfoRepo = new UserInfoRepository();
    }

    /**
     * Check if user has endorsed page
     */
    public function hasEndorsedPage(int $pageId, ?int $endorserUserId = null, ?string $context = null): bool {
        $endorserUserId = $endorserUserId ?? get_current_user_id();

        if (!$endorserUserId) {
            return false;
        }

        return $this->endorsementRepo->hasEndorsed($endorserUserId, $pageId, $context);
    }

    /**
     * Get endorsements given by user with fraud data from user_info.
     *
     * Returns a list of associative arrays: each entry is the EndorsementWithPage
     * row columns plus `endorser_fraud_score`. Array shape (rather than mutating
     * the repository stdClass) lets the enriched field co-exist with the
     * declared repository row type without a shape-mismatch.
     *
     * @return list<array<string, mixed>>
     */
    public function getUserEndorsements(?int $endorserUserId = null, int $limit = 20): array {
        $endorserUserId = $endorserUserId ?? get_current_user_id();

        if (!$endorserUserId) {
            return [];
        }

        $endorsements = $this->endorsementRepo->getByEndorser($endorserUserId, $limit);

        // Get user info for fraud score
        $userInfo   = $this->userInfoRepo->getByUserId($endorserUserId);
        $fraudScore = $userInfo !== null ? $userInfo->fraud_score : 0;

        $enriched = [];
        foreach ($endorsements as $endorsement) {
            $row = (array) $endorsement;
            $row['endorser_fraud_score'] = $fraudScore;
            $enriched[] = $row;
        }

        return $enriched;
    }

    /**
     * Get endorsement statistics for a user
     *
     * @return array<string, mixed>
     */
    public function getUserEndorsementStats(int $userId): array {
        $totalGiven = $this->endorsementRepo->countByEndorser($userId);
        $recentEndorsements = $this->endorsementRepo->getByEndorser($userId, 10);

        $uniquePages = [];
        foreach ($recentEndorsements as $e) {
            $uniquePages[$e->page_id] = true;
        }

        return [
            'user_id' => $userId,
            'total_endorsements_given' => $totalGiven,
            'unique_pages_endorsed' => count($uniquePages),
            'recent_endorsements' => array_slice($recentEndorsements, 0, 5),
            'endorsement_weight_avg' => $this->getAverageEndorsementWeight($userId),
            'last_endorsement' => !empty($recentEndorsements) ? $recentEndorsements[0]->created_at : null
        ];
    }

    /**
     * Get average endorsement weight for a user
     */
    public function getAverageEndorsementWeight(int $userId): float {
        $endorseRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();
        $avg = $endorseRepo->getAverageWeightByEndorser($userId);

        return $avg > 0 ? round($avg, 2) : 1.0;
    }
}
