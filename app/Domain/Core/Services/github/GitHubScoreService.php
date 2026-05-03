<?php
/**
 * GitHub Score Service
 *
 * Calculates trust scores and fraud reductions based on GitHub user data
 * Uses config constants for thresholds and weights
 *
 * @package BCC\Trust\Core
 * @subpackage GitHub
 * @version 2.3.0
 */

namespace BCC\Trust\Core\Services\github;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class GitHubScoreService {
    
    // Score thresholds from config
    private int $maxTrustBoost;
    private int $maxFraudReduction;
    
    // Weights for different factors (configurable)
    private float $weightAccountAge;
    private float $weightFollowers;
    private float $weightRepos;
    private float $weightOrgs;
    private float $weightEmail;
    private float $weightProfile;
    private float $weightActivity;
    private float $weightGists;
    private float $weightAccountType;
    
    // Thresholds from config
    private int $ageVeteranYears;
    private int $followersElite;
    private int $followersHigh;
    private int $followersMedium;
    private int $reposElite;
    private int $reposHigh;
    private int $reposMedium;
    private int $orgsElite;
    private int $orgsHigh;
    private int $orgsMedium;
    
    public function __construct() {
        $this->maxTrustBoost        = BCC_TRUST_GITHUB_MAX_BOOST;
        $this->maxFraudReduction    = BCC_TRUST_GITHUB_MAX_REDUCTION;
        $this->weightAccountAge     = BCC_TRUST_GITHUB_WEIGHT_AGE;
        $this->weightFollowers      = BCC_TRUST_GITHUB_WEIGHT_FOLLOWERS;
        $this->weightRepos          = BCC_TRUST_GITHUB_WEIGHT_REPOS;
        $this->weightOrgs           = BCC_TRUST_GITHUB_WEIGHT_ORGS;
        $this->weightEmail          = BCC_TRUST_GITHUB_WEIGHT_EMAIL;
        $this->weightProfile        = BCC_TRUST_GITHUB_WEIGHT_PROFILE;
        $this->weightActivity       = BCC_TRUST_GITHUB_WEIGHT_ACTIVITY;
        $this->weightGists          = BCC_TRUST_GITHUB_WEIGHT_GISTS;
        $this->weightAccountType    = BCC_TRUST_GITHUB_WEIGHT_TYPE;
        $this->ageVeteranYears      = BCC_TRUST_GITHUB_AGE_VETERAN_YEARS;
        $this->followersElite       = BCC_TRUST_GITHUB_FOLLOWERS_ELITE;
        $this->followersHigh        = BCC_TRUST_GITHUB_FOLLOWERS_HIGH;
        $this->followersMedium      = BCC_TRUST_GITHUB_FOLLOWERS_MEDIUM;
        $this->reposElite           = BCC_TRUST_GITHUB_REPOS_ELITE;
        $this->reposHigh            = BCC_TRUST_GITHUB_REPOS_HIGH;
        $this->reposMedium          = BCC_TRUST_GITHUB_REPOS_MEDIUM;
        $this->orgsElite            = BCC_TRUST_GITHUB_ORGS_ELITE;
        $this->orgsHigh             = BCC_TRUST_GITHUB_ORGS_HIGH;
        $this->orgsMedium           = BCC_TRUST_GITHUB_ORGS_MEDIUM;
    }
    
    /**
     * Calculate comprehensive trust boost from GitHub data
     *
     * @param array<string, mixed> $githubData Complete GitHub user data
     * @return float Trust boost value (0-50)
     */
    public function calculateTrustBoost(array $githubData): float {
        $scores = [];
        
        // Account age (max 20 points)
        $scores['account_age'] = $this->calculateAccountAgeScore($githubData);
        
        // Followers (max 15 points)
        $scores['followers'] = $this->calculateFollowersScore($githubData);
        
        // Repositories (max 10 points)
        $scores['repositories'] = $this->calculateRepositoriesScore($githubData);
        
        // Organizations (max 10 points)
        $scores['organizations'] = $this->calculateOrganizationsScore($githubData);
        
        // Email verification (max 5 points)
        $scores['email'] = $this->calculateEmailScore($githubData);
        
        // Profile completeness (max 5 points)
        $scores['profile'] = $this->calculateProfileScore($githubData);
        
        // Activity level (max 5 points)
        $scores['activity'] = $this->calculateActivityScore($githubData);
        
        // Gists (max 3 points)
        $scores['gists'] = $this->calculateGistsScore($githubData);
        
        // Account type (bonus)
        $scores['account_type'] = $this->calculateAccountTypeScore($githubData);
        
        // Calculate weighted total
        $totalScore = $this->calculateWeightedScore($scores);
        
        // Apply final caps
        $finalScore = min($this->maxTrustBoost, $totalScore);
        
        if (defined('WP_DEBUG') && \WP_DEBUG) {
            \BCC\Core\Log\Logger::error('BCC Trust GitHub: Trust boost calculation - ' . json_encode([
                'scores' => $scores,
                'total' => $totalScore,
                'final' => $finalScore,
                'max' => $this->maxTrustBoost
            ]));
        }
        
        return round($finalScore, 1);
    }
    
    /**
     * Calculate fraud score reduction based on GitHub data
     *
     * @param array<string, mixed> $githubData Complete GitHub user data
     * @param float $trustBoost Calculated trust boost
     * @return int Fraud reduction value (0-40)
     */
    public function calculateFraudReduction(array $githubData, float $trustBoost): int {
        $reduction = (int) floor($trustBoost * 0.6); // Base reduction from trust boost
        
        // Additional reductions for strong signals using config thresholds
        
        // High follower count
        $followers = $githubData['followers'] ?? 0;
        if ($followers > $this->followersElite) {
            $reduction += 10;
        } elseif ($followers > $this->followersHigh) {
            $reduction += 5;
        } elseif ($followers > $this->followersMedium) {
            $reduction += 1;
        }
        
        // Multiple organizations
        $orgCount = count($githubData['organizations'] ?? []);
        if ($orgCount > $this->orgsElite) {
            $reduction += 5;
        } elseif ($orgCount > $this->orgsHigh) {
            $reduction += 3;
        } elseif ($orgCount > $this->orgsMedium) {
            $reduction += 1;
        }
        
        // Very old account
        $accountAge = $this->getAccountAgeInDays($githubData);
        if ($accountAge > 365 * $this->ageVeteranYears) {
            $reduction += 5;
        } elseif ($accountAge > 365 * 5) { // 5+ years
            $reduction += 2;
        } elseif ($accountAge > 365 * 3) { // 3+ years
            $reduction += 1;
        } elseif ($accountAge > 365) { // 1+ year
            $reduction += 1;
        }
        
        // Verified email bonus
        if ($this->hasVerifiedEmail($githubData)) {
            $reduction += 2;
        }
        
        // Complete profile bonus
        $profileScore = $this->calculateProfileScore($githubData);
        if ($profileScore > 3) {
            $reduction += 2;
        }
        
        // Active developer bonus
        if ($this->isActiveDeveloper($githubData)) {
            $reduction += 3;
        }
        
        // Apply cap
        $finalReduction = min($this->maxFraudReduction, $reduction);
        
        if (defined('WP_DEBUG') && \WP_DEBUG) {
            \BCC\Core\Log\Logger::error('BCC Trust GitHub: Fraud reduction calculation - ' . json_encode([
                'base' => floor($trustBoost * 0.6),
                'bonuses' => $reduction - floor($trustBoost * 0.6),
                'total' => $reduction,
                'final' => $finalReduction,
                'max' => $this->maxFraudReduction
            ]));
        }
        
        return $finalReduction;
    }
    
    /**
     * Calculate account age score using config thresholds
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-20)
     */
    private function calculateAccountAgeScore(array $githubData): float {
        $days = $this->getAccountAgeInDays($githubData);
        $years = $days / 365;
        
        if ($years >= $this->ageVeteranYears) return 20;
        if ($years >= 8) return 19;
        if ($years >= 6) return 18;
        if ($years >= 5) return 17;
        if ($years >= 4) return 16;
        if ($years >= 3) return 15;
        if ($years >= 2) return 14;
        if ($years >= 1) return 12;
        if ($years >= 0.5) return 8;
        if ($years >= 0.25) return 5;
        if ($years >= 0.1) return 2;
        
        return 0;
    }
    
    /**
     * Calculate followers score using config thresholds
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-15)
     */
    private function calculateFollowersScore(array $githubData): float {
        $followers = $githubData['followers'] ?? 0;
        
        if ($followers >= $this->followersElite) return 15;
        if ($followers >= 5000) return 14;
        if ($followers >= 2000) return 13;
        if ($followers >= $this->followersHigh) return 12;
        if ($followers >= 500) return 11;
        if ($followers >= 200) return 10;
        if ($followers >= $this->followersMedium) return 9;
        if ($followers >= 50) return 8;
        if ($followers >= 20) return 7;
        if ($followers >= 10) return 6;
        if ($followers >= 5) return 5;
        if ($followers >= 2) return 3;
        if ($followers >= 1) return 1;
        
        return 0;
    }
    
    /**
     * Calculate repositories score using config thresholds
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-10)
     */
    private function calculateRepositoriesScore(array $githubData): float {
        $repos = $githubData['public_repos'] ?? 0;
        
        if ($repos >= $this->reposElite) return 10;
        if ($repos >= 50) return 9;
        if ($repos >= $this->reposHigh) return 8;
        if ($repos >= 20) return 7;
        if ($repos >= 15) return 6;
        if ($repos >= $this->reposMedium) return 5;
        if ($repos >= 7) return 4;
        if ($repos >= 5) return 3;
        if ($repos >= 3) return 2;
        if ($repos >= 1) return 1;
        
        return 0;
    }
    
    /**
     * Calculate organizations score using config thresholds
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-10)
     */
    private function calculateOrganizationsScore(array $githubData): float {
        $orgs = count($githubData['organizations'] ?? []);
        
        if ($orgs >= $this->orgsElite) return 10;
        if ($orgs >= 15) return 9;
        if ($orgs >= $this->orgsHigh) return 8;
        if ($orgs >= 7) return 7;
        if ($orgs >= $this->orgsMedium) return 6;
        if ($orgs >= 4) return 5;
        if ($orgs >= 3) return 4;
        if ($orgs >= 2) return 3;
        if ($orgs >= 1) return 2;
        
        return 0;
    }
    
    /**
     * Calculate email verification score
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-5)
     */
    private function calculateEmailScore(array $githubData): float {
        $score = 0;
        
        // Has any email
        if (!empty($githubData['email'])) {
            $score += 1;
        }
        
        // Has verified email
        if ($this->hasVerifiedEmail($githubData)) {
            $score += 2;
        }
        
        // Email matches domain or is professional
        $email = $githubData['email'] ?? '';
        if (!empty($email)) {
            // Bonus for non-consumer email (custom/corporate domain)
            $freeEmailDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com'];
            $domain = substr(strrchr($email, "@") ?: '', 1);
            if (!in_array($domain, $freeEmailDomains)) {
                $score += 1;
            }
        }
        
        return min(5, $score);
    }
    
    /**
     * Calculate profile completeness score
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-5)
     */
    private function calculateProfileScore(array $githubData): float {
        $score = 0;
        
        if (!empty($githubData['name'])) $score += 1;
        if (!empty($githubData['bio'])) $score += 1;
        if (!empty($githubData['location'])) $score += 0.5;
        if (!empty($githubData['company'])) $score += 0.5;
        if (!empty($githubData['blog'])) $score += 0.5;
        if (!empty($githubData['twitter_username'])) $score += 0.5;
        if (!empty($githubData['hireable'])) $score += 1;
        
        return min(5, $score);
    }
    
    /**
     * Calculate activity score
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-5)
     */
    private function calculateActivityScore(array $githubData): float {
        $score = 0;
        
        // Recent activity
        if (!empty($githubData['updated_at'])) {
            $lastUpdate = strtotime($githubData['updated_at']);
            $daysSinceUpdate = (time() - $lastUpdate) / \DAY_IN_SECONDS;
            
            if ($daysSinceUpdate < 7) {
                $score += 2;
            } elseif ($daysSinceUpdate < 30) {
                $score += 1.5;
            } elseif ($daysSinceUpdate < 90) {
                $score += 1;
            } elseif ($daysSinceUpdate < 365) {
                $score += 0.5;
            }
        }
        
        // Following ratio (engagement)
        $following = $githubData['following'] ?? 0;
        $followers = $githubData['followers'] ?? 0;
        if ($following > 0 && $followers > 0) {
            $ratio = min($following / $followers, 2);
            if ($ratio > 0.5) {
                $score += 1;
            }
        }
        
        // Starred repos (interest in community)
        $starred = $githubData['starred'] ?? 0;
        if ($starred > 50) {
            $score += 2;
        } elseif ($starred > 20) {
            $score += 1.5;
        } elseif ($starred > 10) {
            $score += 1;
        } elseif ($starred > 5) {
            $score += 0.5;
        }
        
        return min(5, $score);
    }
    
    /**
     * Calculate gists score
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-3)
     */
    private function calculateGistsScore(array $githubData): float {
        $gists = $githubData['public_gists'] ?? 0;
        
        if ($gists >= 20) return 3;
        if ($gists >= 10) return 2;
        if ($gists >= 5) return 1.5;
        if ($gists >= 2) return 1;
        if ($gists >= 1) return 0.5;
        
        return 0;
    }
    
    /**
     * Calculate account type score
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return float Score (0-2)
     */
    private function calculateAccountTypeScore(array $githubData): float {
        // Bonus for being a site admin (trusted by GitHub)
        if (!empty($githubData['site_admin'])) {
            return 2;
        }
        
        return 0;
    }
    
    /**
     * Calculate weighted score from individual components
     *
     * @param array<string, float> $scores Individual component scores
     * @return float Weighted total
     */
    private function calculateWeightedScore(array $scores): float {
        $weights = [
            'account_age' => $this->weightAccountAge,
            'followers' => $this->weightFollowers,
            'repositories' => $this->weightRepos,
            'organizations' => $this->weightOrgs,
            'email' => $this->weightEmail,
            'profile' => $this->weightProfile,
            'activity' => $this->weightActivity,
            'gists' => $this->weightGists,
            'account_type' => $this->weightAccountType,
        ];
        
        $total = 0;
        foreach ($scores as $key => $score) {
            $weight = $weights[$key] ?? 0.1;
            $total += $score * $weight * 4; // Scale factor to reach max 50
        }
        
        return $total;
    }
    
    /**
     * Check if user has verified email
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return bool
     */
    private function hasVerifiedEmail(array $githubData): bool {
        // Check emails array
        if (!empty($githubData['emails'])) {
            foreach ($githubData['emails'] as $email) {
                if (!empty($email['verified'])) {
                    return true;
                }
            }
        }
        
        // Profile email is always verified by GitHub
        return !empty($githubData['email']);
    }
    
    /**
     * Get account age in days
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return int
     */
    private function getAccountAgeInDays(array $githubData): int {
        if (empty($githubData['created_at'])) {
            return 0;
        }
        
        $created = strtotime($githubData['created_at']);
        return (int) ((time() - $created) / \DAY_IN_SECONDS);
    }
    
    /**
     * Check if user is an active developer
     *
     * @param array<string, mixed> $githubData GitHub user data
     * @return bool
     */
    private function isActiveDeveloper(array $githubData): bool {
        $conditions = 0;
        
        // Has recent activity
        if (!empty($githubData['updated_at'])) {
            $lastUpdate = strtotime($githubData['updated_at']);
            $daysSinceUpdate = (time() - $lastUpdate) / \DAY_IN_SECONDS;
            if ($daysSinceUpdate < 30) {
                $conditions++;
            }
        }
        
        // Has repositories
        if (($githubData['public_repos'] ?? 0) >= 5) {
            $conditions++;
        }
        
        // Has followers
        if (($githubData['followers'] ?? 0) >= 10) {
            $conditions++;
        }
        
        // Has organizations
        if (count($githubData['organizations'] ?? []) >= 2) {
            $conditions++;
        }
        
        return $conditions >= 2;
    }
    
}