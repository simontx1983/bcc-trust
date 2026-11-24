<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ValidatorRelationshipLogRepository as Log;
use BCC\Trust\Onchain\Support\RelationshipLogAnchor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily health check of the validator relationship log (cron only).
 *
 *  - Re-walks the chain (RelationshipLogVerifier) and stores the result for
 *    the admin health view.
 *  - Clean: advances the anchor to the head and emails every administrator
 *    the anchored (seq, row_hash) — a copy outside the database, so a
 *    rewritten history cannot also rewrite the record of what it was.
 *  - Broken: records a metric and emails every administrator an alert
 *    naming the first bad seq and the reason. It never repairs anything.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class RelationshipLogHealthCheck
{
    public const HOOK   = 'bcc_validator_relationship_log_health';
    public const RESULT = 'bcc_vrl_health';

    /** @return array{chain_ok: bool, checked_at: string, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null} */
    public static function run(): array
    {
        $verdict = RelationshipLogVerifier::verify();
        $result  = [
            'chain_ok'      => $verdict['ok'],
            'checked_at'    => gmdate('Y-m-d H:i:s'),
            'rows_checked'  => $verdict['rows_checked'],
            'head_seq'      => $verdict['head_seq'],
            'first_bad_seq' => $verdict['first_bad_seq'],
            'reason'        => $verdict['reason'],
        ];
        update_option(self::RESULT, $result, false);

        if (!$verdict['ok']) {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_chain_broken');
            \BCC\Core\Log\Logger::error('[bcc-trust] validator relationship log failed verification', $result);
            self::mailAdmins(
                'ALERT: validator relationship log failed verification',
                "The validator relationship history failed its daily verification.\n\n"
                . 'First bad entry: ' . ($verdict['first_bad_seq'] ?? 'unknown') . "\n"
                . 'Reason: ' . ($verdict['reason'] ?? 'unknown') . "\n"
                . 'Entries verified before it: ' . $verdict['rows_checked'] . "\n\n"
                . 'Nothing was changed. Investigate before any further validator relationship change.'
            );

            return $result;
        }

        try {
            $head = Log::head();
        } catch (\Throwable $e) {
            return $result;
        }
        if ($head['seq'] > 0) {
            RelationshipLogAnchor::advance($head['seq'], $head['row_hash']);
            self::mailAdmins(
                'Validator relationship log: daily anchor',
                "The validator relationship history verified cleanly.\n\n"
                . 'Entries: ' . $head['seq'] . "\n"
                . 'Latest entry hash: ' . $head['row_hash'] . "\n\n"
                . 'Keep this email: it is the record of the history as it stood today.'
            );
        }

        return $result;
    }

    /**
     * The last stored result, for the admin health view; null before the
     * first run.
     *
     * @return array{chain_ok: bool, checked_at: string, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null}|null
     */
    public static function lastResult(): ?array
    {
        $stored = get_option(self::RESULT, null);
        if (!is_array($stored) || !isset($stored['chain_ok'], $stored['checked_at'])) {
            return null;
        }

        return [
            'chain_ok'      => (bool) $stored['chain_ok'],
            'checked_at'    => (string) $stored['checked_at'],
            'rows_checked'  => (int) ($stored['rows_checked'] ?? 0),
            'head_seq'      => (int) ($stored['head_seq'] ?? 0),
            'first_bad_seq' => isset($stored['first_bad_seq']) ? (int) $stored['first_bad_seq'] : null,
            'reason'        => isset($stored['reason']) ? (string) $stored['reason'] : null,
        ];
    }

    private static function mailAdmins(string $subject, string $body): void
    {
        $site = get_bloginfo('name') ?: 'BCC';
        foreach (\BCC\Trust\Core\Support\AdminRecipients::ids() as $adminId) {
            $user = get_userdata($adminId);
            if (!$user instanceof \WP_User || (string) $user->user_email === '') {
                continue;
            }
            \BCC\Core\Mail\BccMailer::send(
                (string) $user->user_email,
                '[' . $site . '] ' . $subject,
                nl2br(esc_html($body)),
                'validator_log_mail_failed'
            );
        }
    }
}
