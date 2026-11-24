<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Support\RelationshipLogAnchor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily health check of the validator relationship log (cron only). It reads;
 * it never writes the log or the anchor (only an appender, under the vrl
 * lock, moves the anchor) and never repairs anything.
 *
 * Re-walks the chain (RelationshipLogVerifier) and stores the result for the
 * admin health view, with one of four statuses:
 *
 *  - ok:         the chain verified and the anchor is in place.
 *  - warning:    the chain verified, but the log has rows and NO anchor (the
 *                option was deleted): rows removed from the end could no
 *                longer be seen. A metric, no email; the next append sets a
 *                new anchor.
 *  - incomplete: the walk could not finish (a read failed, or the row limit
 *                was reached). Nothing was proven broken: a metric only.
 *  - broken:     a metric, and every administrator is emailed an alert
 *                naming the first bad entry and the reason.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class RelationshipLogHealthCheck
{
    public const HOOK   = 'bcc_validator_relationship_log_health';
    public const RESULT = 'bcc_vrl_health';

    /** Verifier reasons that mean "could not finish", not "broken". */
    private const INCOMPLETE_REASONS = ['read_failed', 'row_limit_reached'];

    private const STATUSES = ['ok', 'warning', 'incomplete', 'broken'];

    /** @return array{status: 'ok'|'warning'|'incomplete'|'broken', chain_ok: bool, checked_at: string, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null} */
    public static function run(): array
    {
        $verdict = RelationshipLogVerifier::verify();
        $reason  = $verdict['reason'];
        if ($verdict['ok']) {
            $status = 'ok';
            if ($verdict['head_seq'] > 0 && RelationshipLogAnchor::get() === null) {
                $status = 'warning';
                $reason = 'anchor_missing';
            }
        } else {
            $status = in_array($reason, self::INCOMPLETE_REASONS, true) ? 'incomplete' : 'broken';
        }
        $result = [
            'status'        => $status,
            'chain_ok'      => $verdict['ok'],
            'checked_at'    => gmdate('Y-m-d H:i:s'),
            'rows_checked'  => $verdict['rows_checked'],
            'head_seq'      => $verdict['head_seq'],
            'first_bad_seq' => $verdict['first_bad_seq'],
            'reason'        => $reason,
        ];
        update_option(self::RESULT, $result, false);

        if ($status === 'warning') {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_anchor_missing');
            \BCC\Core\Log\Logger::warning('[bcc-trust] validator relationship log has rows but no anchor', $result);
        } elseif ($status === 'incomplete') {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_verify_incomplete');
            \BCC\Core\Log\Logger::warning('[bcc-trust] validator relationship log verification did not finish', $result);
        } elseif ($status === 'broken') {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_chain_broken');
            \BCC\Core\Log\Logger::error('[bcc-trust] validator relationship log failed verification', $result);
            self::mailAdmins(
                'ALERT: validator relationship log failed verification',
                "The validator relationship history failed its daily verification.\n\n"
                . 'First bad entry: ' . ($verdict['first_bad_seq'] ?? 'unknown') . "\n"
                . 'Reason: ' . ($reason ?? 'unknown') . "\n"
                . 'Entries verified before it: ' . $verdict['rows_checked'] . "\n\n"
                . 'Nothing was changed. Investigate before any further validator relationship change.'
            );
        }

        return $result;
    }

    /**
     * The last stored result, for the admin health view; null before the
     * first run.
     *
     * @return array{status: 'ok'|'warning'|'incomplete'|'broken', chain_ok: bool, checked_at: string, rows_checked: int, head_seq: int, first_bad_seq: int|null, reason: string|null}|null
     */
    public static function lastResult(): ?array
    {
        $stored = get_option(self::RESULT, null);
        if (!is_array($stored) || !isset($stored['chain_ok'], $stored['checked_at'])) {
            return null;
        }
        $status = $stored['status'] ?? null;
        if (!in_array($status, self::STATUSES, true)) {
            $status = ((bool) $stored['chain_ok']) ? 'ok' : 'broken';
        }

        return [
            'status'        => $status,
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
