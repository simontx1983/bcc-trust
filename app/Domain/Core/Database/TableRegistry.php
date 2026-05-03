<?php

namespace BCC\Trust\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for all trust engine table names.
 *
 * Every table name accessor is a static method. All tables are defined
 * here — nowhere else. All callers reference this class directly.
 *
 * This class is intentionally static: table names are derived from
 * $wpdb->prefix which is a global singleton, and the methods are pure
 * getters with no side effects.
 */
final class TableRegistry
{
    public static function votes(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_votes';
    }

    public static function scores(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_page_scores';
    }

    public static function endorsements(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_endorsements';
    }

    public static function activity(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_activity';
    }

    public static function activityArchive(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_activity_archive';
    }

    public static function flags(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_flags';
    }

    public static function reputation(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_reputation';
    }

    public static function fingerprints(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_device_fingerprints';
    }

    public static function patterns(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_patterns';
    }

    public static function userInfo(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_user_info';
    }

    public static function fraudAnalysis(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_fraud_analysis';
    }

    public static function suspensions(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_suspensions';
    }

    public static function userVerifications(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_user_verifications';
    }

    public static function edges(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_edges';
    }

    public static function pageReadModel(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_page_read_model';
    }

    public static function pageFlags(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_page_flags';
    }

    public static function scoreEvents(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_score_events';
    }

    public static function scoreVelocity(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_page_scores_velocity';
    }

    public static function questLog(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_quest_log';
    }

    public static function dirtyQueue(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_rm_dirty_queue';
    }

    // V1 frontend support tables (per docs/api-contract-v1.md §6.5)

    public static function pullMeta(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_pull_meta';
    }

    public static function userRanks(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_user_ranks';
    }

    public static function pullBatches(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_pull_batches';
    }

    public static function reputationEvents(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_reputation_events';
    }

    public static function contentReports(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_content_reports';
    }

    public static function hiddenActivities(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_hidden_activities';
    }

    public static function disputeParticipations(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_dispute_participations';
    }

    // V2 Phase 1: §I1 push notifications

    public static function pushSubscriptions(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_push_subscriptions';
    }

    // NOTE: bcc_user_locals removed — Locals membership ledger is PeepSo's
    // peepso_group_members; primary-Local pointer is wp_usermeta.bcc_primary_local_group_id.
    // NOTE: bcc_page_claims removed — page claims merged into bcc_onchain_claims
    // (entity_type='page'); recovery_pending lives there.

    /**
     * All table names as an associative array.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'votes'              => self::votes(),
            'scores'             => self::scores(),
            'endorsements'       => self::endorsements(),
            'user_verifications' => self::userVerifications(),
            'activity'           => self::activity(),
            'activity_archive'   => self::activityArchive(),
            'flags'              => self::flags(),
            'reputation'         => self::reputation(),
            'fingerprints'       => self::fingerprints(),
            'patterns'           => self::patterns(),
            'user_info'          => self::userInfo(),
            'fraud_analysis'     => self::fraudAnalysis(),
            'suspensions'        => self::suspensions(),
            'edges'              => self::edges(),
            'page_read_model'    => self::pageReadModel(),
            'page_flags'         => self::pageFlags(),
            'score_events'       => self::scoreEvents(),
            'score_velocity'     => self::scoreVelocity(),
            'dirty_queue'        => self::dirtyQueue(),
            // V1 frontend support tables
            'pull_meta'          => self::pullMeta(),
            'user_ranks'         => self::userRanks(),
            'pull_batches'       => self::pullBatches(),
            'reputation_events'  => self::reputationEvents(),
            'content_reports'    => self::contentReports(),
            'hidden_activities'  => self::hiddenActivities(),
            'dispute_participations' => self::disputeParticipations(),
            // V2 Phase 1 push notifications
            'push_subscriptions' => self::pushSubscriptions(),
        ];
    }
}
