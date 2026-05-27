<?php

namespace BCC\Trust\Core\Services\Admin;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\PeepSoPageResolver;
use BCC\Trust\Core\ValueObjects\PageScore;
use BCC\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin repair and maintenance service.
 *
 * Migrated from includes/admin/dashboard-action.php. Each public method
 * corresponds to one of the original bcc_trust_* handler functions.
 *
 * All database access is delegated to repository classes.
 *
 * @package BCC\Trust\Core\Services\Admin
 */
final class RepairService
{
    /**
     * Register all admin_post_* and admin_init hooks.
     *
     * Delegates to instance methods via Plugin::instance().
     */
    public static function registerActions(): void
    {
        $self = function (): self {
            static $instance;
            return $instance ??= new self();
        };

        add_action('admin_post_bcc_trust_repair_owners', function () use ($self) {
            $self()->repairOwners();
        });

        add_action('admin_post_bcc_trust_recalc_scores', function () use ($self) {
            $self()->recalculateScores();
        });

        add_action('admin_post_bcc_trust_clean_devices', function () use ($self) {
            $self()->cleanDevices();
        });

        add_action('admin_post_bcc_trust_complete_page_repair', function () use ($self) {
            $self()->repairPages();
        });

        add_action('admin_post_bcc_trust_sync_users', function () use ($self) {
            $self()->syncUsers();
        });

    }

    // ── Security ────────────────────────────────────────────────────────

    /**
     * Verify the current user has manage_options, the environment opts
     * into repair operations, and the nonce is valid.
     *
     * @param string $action Nonce action name.
     */
    public function securityCheck(string $action = 'bcc_trust_admin_action'): void
    {
        // Operator OS v1 Phase 1: every destructive Repair handler bails
        // out unless wp-config explicitly opts in. See tab-repair.php
        // for operator-facing instructions.
        if (!(defined('BCC_REPAIR_ENABLED') && BCC_REPAIR_ENABLED === true)) {
            wp_die('Repair is disabled in this environment. Set BCC_REPAIR_ENABLED in wp-config.php to enable.');
        }
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $action)) {
            wp_die('Security check failed.');
        }
    }

    // ── Repair Owners ───────────────────────────────────────────────────

    /**
     * Repair page owners in the scores table.
     */
    public function repairOwners(): void
    {
        $this->securityCheck();

        $scoreRepo = Plugin::instance()->scoreRepository();

        if (!$scoreRepo->tableExists()) {
            wp_die('Trust scores table not found.');
        }

        // Check PeepSo page members table exists
        if (!\BCC\Trust\Core\Repositories\PeepSoQueryRepository::tableExists($GLOBALS['wpdb']->prefix . 'peepso_page_members')) {
            wp_die('PeepSo page members table not found.');
        }

        $results = [
            'action'            => 'owner_repair',
            'pages_fixed'       => 0,
            'owners_reassigned' => 0,
            'missing_created'   => 0,
            'users_updated'     => 0,
            'details'           => [],
        ];

        // Process PeepSo pages in batches to avoid memory exhaustion.
        $batch_size  = 100;
        $offset      = 0;
        $total_found = 0;

        do {
            $batch = get_posts([
                'post_type'      => 'peepso-page',
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'post_status'    => 'any',
                'fields'         => 'all',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]);

            foreach ($batch as $page) {
                $page_id       = $page->ID;
                $correct_owner = 0;

                // Try PeepSo page resolver
                $owner = PeepSoPageResolver::getOwnerId($page_id);
                if ($owner && $owner > 0) {
                    $correct_owner = $owner;
                }

                // Fallback to post author
                if (!$correct_owner && $page->post_author > 0) {
                    $correct_owner = (int) $page->post_author;
                }

                if (!$correct_owner) {
                    $results['details'][] = "Page {$page_id} - No owner found";
                    continue;
                }

                // Check if page exists in scores table
                $existing = $scoreRepo->existsForPage($page_id);

                if (!$existing) {
                    // Create new score entry
                    $scoreRepo->insertDefaultScore($page_id, $correct_owner);
                    $results['missing_created']++;
                } else {
                    $currentOwner = $scoreRepo->getPageOwnerId($page_id);
                    if ($currentOwner != $correct_owner) {
                        $scoreRepo->updateOwner($page_id, $correct_owner);
                        $results['owners_reassigned']++;
                    }
                }

                $results['pages_fixed']++;
            }

            $total_found += count($batch);
            $offset      += $batch_size;
        } while (count($batch) === $batch_size);

        $results['details'][] = "Found {$total_found} total PeepSo pages";

        // Clean up orphaned score entries
        $orphaned = $scoreRepo->deleteOrphaned();
        if ($orphaned > 0) {
            $results['details'][] = "Removed {$orphaned} orphaned score entries";
        }

        set_transient('bcc_trust_repair_results', $results, 60);
        Logger::info('[bcc-trust] Repair action complete', [
            'action'   => $results['action'] ?? 'unknown',
            'operator' => get_current_user_id(),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=bcc-system-repair'));
        exit;
    }

    // ── Recalculate Scores ──────────────────────────────────────────────

    /**
     * Recalculate trust scores for all pages.
     */
    public function recalculateScores(): void
    {
        $this->securityCheck();

        $scoreRepo      = Plugin::instance()->scoreRepository();
        $voteRepo       = Plugin::instance()->voteRepository();
        $endorseRepo    = Plugin::instance()->endorsementRepository();

        if (!$scoreRepo->tableExists()) {
            wp_die('Trust scores table missing.');
        }

        $results = [
            'action'              => 'score_recalc',
            'pages_recalculated'  => 0,
            'users_updated'       => 0,
        ];

        $maxConfidenceVotes = BCC_TRUST_MAX_CONFIDENCE_VOTES;
        $eliteThreshold     = BCC_TRUST_TIER_ELITE;
        $trustedThreshold   = BCC_TRUST_TIER_TRUSTED;
        $neutralThreshold   = BCC_TRUST_TIER_NEUTRAL;
        $cautionThreshold   = BCC_TRUST_TIER_CAUTION;

        $offset = 0;
        $batchSize = 1000;
        do {
            $pageIds = $scoreRepo->getAllPageIds($batchSize, $offset);

            foreach ($pageIds as $page_id) {
                $vote_stats = $voteRepo->getPageStats($page_id);

                $endorsementBonus = $endorseRepo->sumActiveWeight($page_id);

                $existing = $scoreRepo->getRawRow($page_id);
                $onchainBonus = (float) ($existing->onchain_bonus ?? 0.0);

                $voteCount     = (int)   ($vote_stats->total_votes ?? 0);
                $positiveScore = (float) ($vote_stats->total_positive_weight ?? 0);
                $negativeScore = (float) ($vote_stats->total_negative_weight ?? 0);
                $uniqueVoters  = (int)   ($vote_stats->unique_voters ?? 0);

                // Canonical formula — single source of truth in PageScore.
                // When voteCount is 0 the vote terms are zero, so computeExpectedTotal
                // degenerates to NEUTRAL + bonuses (same as the prior branch).
                $total_score = PageScore::computeExpectedTotal(
                    $voteCount > 0 ? $positiveScore : 0.0,
                    $voteCount > 0 ? $negativeScore : 0.0,
                    $endorsementBonus,
                    $onchainBonus
                );

                $volumeConfidence    = min(1, $voteCount / $maxConfidenceVotes);
                $diversityConfidence = $uniqueVoters / max(1, $voteCount);
                $confidence          = ($volumeConfidence * 0.6) + ($diversityConfidence * 0.4);

                $tier = $this->determineTier($total_score, $eliteThreshold, $trustedThreshold, $neutralThreshold, $cautionThreshold);

                $scoreRepo->updateCalculatedScore($page_id, [
                    'total_score'        => $total_score,
                    'positive_score'     => $positiveScore,
                    'negative_score'     => $negativeScore,
                    'vote_count'         => $voteCount,
                    'unique_voters'      => $uniqueVoters,
                    'confidence_score'   => $confidence,
                    'reputation_tier'    => $tier,
                    'endorsement_bonus'  => $endorsementBonus,
                ]);

                $results['pages_recalculated']++;
            }

            $offset += $batchSize;
        } while (count($pageIds) === $batchSize);

        // Sync read model after bulk score recalculation.
        Plugin::instance()->pageReadModelRepository()->syncAll();

        set_transient('bcc_trust_repair_results', $results, 60);
        Logger::info('[bcc-trust] Repair action complete', [
            'action'   => $results['action'] ?? 'unknown',
            'operator' => get_current_user_id(),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=bcc-system-repair'));
        exit;
    }

    // ── Clean Devices ───────────────────────────────────────────────────

    /**
     * Clean stale device fingerprints and expired patterns.
     */
    public function cleanDevices(): void
    {
        $this->securityCheck();

        $fingerprintRepo = new \BCC\Trust\Core\Repositories\DeviceFingerprintRepository();
        $patternRepo     = Plugin::instance()->patternRepository();

        if (!$fingerprintRepo->tableExists()) {
            wp_die('Device fingerprint table missing.');
        }

        $results = [
            'action'                => 'device_cleanup',
            'fingerprints_removed'  => 0,
            'patterns_removed'      => 0,
        ];

        $fingerprintDays = BCC_TRUST_CLEANUP_FINGERPRINTS;
        $patternDays     = BCC_TRUST_CLEANUP_PATTERNS;
        $automationHigh  = BCC_TRUST_AUTOMATION_HIGH;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$fingerprintDays} days"));
        $results['fingerprints_removed'] = $fingerprintRepo->deleteOlderThan($cutoff);

        $patternCutoff = date('Y-m-d H:i:s', strtotime("-{$patternDays} days"));
        $results['patterns_removed'] = $patternRepo->deleteExpiredWithCutoff($patternCutoff);

        // Also clean up high-automation fingerprints older than 30 days
        $automationCutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $fingerprintRepo->deleteHighAutomationOlderThan($automationHigh, $automationCutoff);

        set_transient('bcc_trust_repair_results', $results, 60);
        Logger::info('[bcc-trust] Repair action complete', [
            'action'   => $results['action'] ?? 'unknown',
            'operator' => get_current_user_id(),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=bcc-system-repair'));
        exit;
    }

    // ── Database Check ──────────────────────────────────────────────────

    /**
     * Check all trust engine tables for existence and orphaned records.
     */
    public function runDbCheck(): void
    {
        $this->securityCheck();

        $scoreRepo      = Plugin::instance()->scoreRepository();
        $userInfoRepo   = Plugin::instance()->userInfoRepository();
        $fraudRepo      = Plugin::instance()->fraudAnalysisRepository();

        $results = [
            'action'         => 'db_check',
            'tables_checked' => [],
            'issues_found'   => [],
            'issues_fixed'   => 0,
        ];

        $tables = [
            'votes'              => TableRegistry::votes(),
            'scores'             => TableRegistry::scores(),
            'endorsements'       => TableRegistry::endorsements(),
            'user_info'          => TableRegistry::userInfo(),
            'fingerprints'       => TableRegistry::fingerprints(),
            'patterns'           => TableRegistry::patterns(),
            'activity'           => TableRegistry::activity(),
            'fraud_analysis'     => TableRegistry::fraudAnalysis(),
            'suspensions'        => TableRegistry::suspensions(),
            'user_verifications' => TableRegistry::userVerifications(),
        ];

        $fraudRetention    = BCC_TRUST_CLEANUP_FRAUD_ANALYSIS;
        $activityRetention = BCC_TRUST_CLEANUP_ACTIVITY;

        $auditLogRepo = new \BCC\Trust\Core\Repositories\AuditLogRepository();

        foreach ($tables as $name => $table) {
            $exists = \BCC\Trust\Core\Repositories\AuditLogRepository::rawTableExists($table);
            $results['tables_checked'][$name] = $exists ? 'OK' : 'MISSING';

            if (!$exists) {
                $results['issues_found'][] = "Table {$name} is missing";
            } else {
                if ($name === 'scores') {
                    $orphaned = $scoreRepo->countOrphaned();
                    if ($orphaned > 0) {
                        $results['issues_found'][] = "Found {$orphaned} orphaned score records";
                        $scoreRepo->deleteOrphaned();
                        $results['issues_fixed'] += $orphaned;
                    }
                }

                if ($name === 'user_info') {
                    $orphaned = $userInfoRepo->countOrphaned();
                    if ($orphaned > 0) {
                        $results['issues_found'][] = "Found {$orphaned} orphaned user records";
                        $userInfoRepo->deleteOrphaned();
                        $results['issues_fixed'] += $orphaned;
                    }
                }

                if ($name === 'fraud_analysis') {
                    $oldRecords = $fraudRepo->countOld($fraudRetention);
                    if ($oldRecords > 0) {
                        $results['issues_found'][] = "Found {$oldRecords} fraud analyses older than {$fraudRetention} days";
                    }
                }

                if ($name === 'activity') {
                    $activityCutoff = date('Y-m-d H:i:s', strtotime("-{$activityRetention} days"));
                    $oldActivity = $auditLogRepo->countOlderThan($activityCutoff);
                    if ($oldActivity > 0) {
                        $results['issues_found'][] = "Found {$oldActivity} activity logs older than {$activityRetention} days";
                    }
                }
            }
        }

        set_transient('bcc_trust_repair_results', $results, 60);
        Logger::info('[bcc-trust] Repair action complete', [
            'action'   => $results['action'] ?? 'unknown',
            'operator' => get_current_user_id(),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=bcc-system-repair'));
        exit;
    }

    // ── Fraud Cleanup ───────────────────────────────────────────────────

    /**
     * Clean up expired fraud analysis records and archive old suspensions.
     */
    public function cleanupFraud(): void
    {
        $this->securityCheck();

        $fraudRepo      = Plugin::instance()->fraudAnalysisRepository();
        $suspensionRepo = Plugin::instance()->suspensionRepository();

        $results = [
            'action'                  => 'fraud_cleanup',
            'fraud_analyses_removed'  => 0,
            'suspensions_archived'    => 0,
        ];

        $results['fraud_analyses_removed'] = $fraudRepo->deleteExpired();
        $results['suspensions_archived']   = $suspensionRepo->archiveOldClosed(BCC_TRUST_CLEANUP_SUSPENSIONS);

        set_transient('bcc_trust_repair_results', $results, 60);
        Logger::info('[bcc-trust] Repair action complete', [
            'action'   => $results['action'] ?? 'unknown',
            'operator' => get_current_user_id(),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=bcc-system-repair'));
        exit;
    }

    // ── Repair Pages ────────────────────────────────────────────────────

    /**
     * Complete page repair: create missing scores, fix orphaned fraud
     * records, then recalculate all page scores.
     */
    public function repairPages(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('bcc_trust_admin_action');

        $scoreRepo      = Plugin::instance()->scoreRepository();
        $voteRepo       = Plugin::instance()->voteRepository();
        $endorseRepo    = Plugin::instance()->endorsementRepository();
        $fraudRepo      = Plugin::instance()->fraudAnalysisRepository();

        $results = [
            'action'              => 'complete_page_repair',
            'pages_created'       => 0,
            'fraud_deleted'       => 0,
            'pages_recalculated'  => 0,
            'details'             => [],
        ];

        $maxConfidenceVotes = BCC_TRUST_MAX_CONFIDENCE_VOTES;
        $eliteThreshold     = BCC_TRUST_TIER_ELITE;
        $trustedThreshold   = BCC_TRUST_TIER_TRUSTED;
        $neutralThreshold   = BCC_TRUST_TIER_NEUTRAL;
        $cautionThreshold   = BCC_TRUST_TIER_CAUTION;

        // STEP 1: Create missing page scores
        $missing_pages = $scoreRepo->getMissingPages();
        $results['details'][] = 'Found ' . count($missing_pages) . ' pages missing score entries';

        foreach ($missing_pages as $page) {
            // getMissingPages returns rows whose numeric columns are the
            // int|numeric-string union that $wpdb always emits; cast at the
            // boundary so all downstream calls receive int, not the union.
            $page_id  = (int) $page->ID;
            $owner_id = (int) $page->post_author;

            // Try to get correct owner from PeepSo
            $correct_owner = PeepSoPageResolver::getOwnerId($page_id);
            if ($correct_owner && $correct_owner > 0) {
                $owner_id = $correct_owner;
            }

            if ($scoreRepo->existsForPage($page_id)) {
                continue;
            }

            if ($scoreRepo->insertDefaultScore($page_id, $owner_id)) {
                $results['pages_created']++;
                $results['details'][] = "Created score for page #{$page_id} - {$page->post_title}";
            }
        }

        // STEP 2: Fix orphaned fraud analysis records
        $orphaned_fraud = $fraudRepo->getOrphaned();
        $results['details'][] = 'Found ' . count($orphaned_fraud) . ' orphaned fraud analysis records';

        foreach ($orphaned_fraud as $fraud) {
            if ($fraudRepo->deleteById((int) $fraud->id)) {
                $results['fraud_deleted']++;
                $results['details'][] = "Deleted orphaned fraud analysis #{$fraud->id} for non-existent user #{$fraud->user_id}";
            }
        }

        // STEP 3: Run enhanced score recalculation on ALL pages
        $offset = 0;
        $batchSize = 1000;
        do {
            $pageIds = $scoreRepo->getAllPageIds($batchSize, $offset);

            foreach ($pageIds as $page_id) {
                $vote_stats = $voteRepo->getPageStats($page_id);

                $endorsementBonus = $endorseRepo->sumActiveWeight($page_id);

                $existing = $scoreRepo->getRawRow($page_id);
                $onchainBonus = (float) ($existing->onchain_bonus ?? 0.0);

                $voteCount     = (int)   ($vote_stats->total_votes ?? 0);
                $positiveScore = (float) ($vote_stats->total_positive_weight ?? 0);
                $negativeScore = (float) ($vote_stats->total_negative_weight ?? 0);
                $uniqueVoters  = (int)   ($vote_stats->unique_voters ?? 0);

                // Canonical formula — single source of truth in PageScore.
                $total_score = PageScore::computeExpectedTotal(
                    $voteCount > 0 ? $positiveScore : 0.0,
                    $voteCount > 0 ? $negativeScore : 0.0,
                    $endorsementBonus,
                    $onchainBonus
                );

                $volumeConfidence    = min(1, $voteCount / $maxConfidenceVotes);
                $diversityConfidence = $uniqueVoters / max(1, $voteCount);
                $confidence          = ($volumeConfidence * 0.6) + ($diversityConfidence * 0.4);

                $tier = $this->determineTier($total_score, $eliteThreshold, $trustedThreshold, $neutralThreshold, $cautionThreshold);

                $scoreRepo->updateCalculatedScore($page_id, [
                    'total_score'        => $total_score,
                    'positive_score'     => $positiveScore,
                    'negative_score'     => $negativeScore,
                    'vote_count'         => $voteCount,
                    'unique_voters'      => $uniqueVoters,
                    'confidence_score'   => $confidence,
                    'reputation_tier'    => $tier,
                    'endorsement_bonus'  => $endorsementBonus,
                ]);

                $results['pages_recalculated']++;
            }

            $offset += $batchSize;
        } while (count($pageIds) === $batchSize);

        // Sync read model + store results and redirect
        Plugin::instance()->pageReadModelRepository()->syncAll();

        set_transient('bcc_trust_repair_results', $results, 120);
        Logger::info('[bcc-trust] Repair action complete', [
            'action'        => $results['action'] ?? 'complete_page_repair',
            'pages_created' => (int) ($results['pages_created'] ?? 0),
            'operator'      => get_current_user_id(),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=bcc-system-repair&fixed=' . (int) ($results['pages_created'] ?? 0)));
        exit;
    }

    // ── Sync Users ──────────────────────────────────────────────────────

    /**
     * Sync missing users into the user_info table.
     */
    public function syncUsers(): void
    {
        $this->securityCheck();

        $synced = (int) Plugin::instance()->userSyncService()->sync();

        Logger::info('[bcc-trust] Repair action complete', [
            'action'   => 'sync_users',
            'synced'   => $synced,
            'operator' => get_current_user_id(),
        ]);

        wp_safe_redirect(
            admin_url('admin.php?page=bcc-system-repair&sync_done=' . $synced)
        );
        exit;
    }

    // ── Diagnostics ─────────────────────────────────────────────────────

    /**
     * Gather repair diagnostics data.
     *
     * @return array<string, mixed>
     */
    public function getDiagnostics(): array
    {
        $scoreRepo      = Plugin::instance()->scoreRepository();
        $userInfoRepo   = Plugin::instance()->userInfoRepository();
        $fraudRepo      = Plugin::instance()->fraudAnalysisRepository();
        $suspensionRepo = Plugin::instance()->suspensionRepository();

        $decayDays        = BCC_TRUST_DECAY_DAYS;
        $minVotesReliable = BCC_TRUST_MIN_VOTES_RELIABLE;
        $fraudMedium      = BCC_TRUST_FRAUD_MEDIUM;

        // Pages with mismatched owners
        $mismatches = $scoreRepo->getMismatchedOwners();

        // Pages missing from scores table
        $missing = $scoreRepo->getMissingPages();

        // Users missing from user_info table
        $missing_users = $userInfoRepo->getMissingUsers(10);

        // Fraud analysis orphaned
        $orphaned_fraud = $fraudRepo->countOrphaned();

        // Suspensions orphaned
        $orphaned_suspensions = $suspensionRepo->countOrphaned();

        $issues_found = !empty($mismatches)
            || !empty($missing)
            || !empty($missing_users)
            || $orphaned_fraud > 0
            || $orphaned_suspensions > 0;

        return [
            'mismatches'            => $mismatches ?: [],
            'missing'               => $missing ?: [],
            'missing_users'         => $missing_users ?: [],
            'orphaned_fraud'        => $orphaned_fraud,
            'orphaned_suspensions'  => $orphaned_suspensions,
            'config'                => [
                'decay_days'          => (int) $decayDays,
                'min_votes_reliable'  => (int) $minVotesReliable,
                'fraud_medium'        => (int) $fraudMedium,
            ],
            'issues_found'          => $issues_found,
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────────

    /**
     * Determine reputation tier from a total score.
     */
    private function determineTier(float $score, int $elite, int $trusted, int $neutral, int $caution): string
    {
        if ($score >= $elite) {
            return 'elite';
        }
        if ($score >= $trusted) {
            return 'trusted';
        }
        if ($score >= $neutral) {
            return 'neutral';
        }
        if ($score >= $caution) {
            return 'caution';
        }
        return 'risky';
    }
}
