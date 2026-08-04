<?php
/**
 * FindingsAdmin — the wp-admin issuance + reconsideration surface for
 * misconduct findings (Rank Phase 8; spec §15; DisputeAdmin pattern).
 *
 * §15.1 — this page is the ONLY issuance path. No automated writer
 * exists; a dispute-sourced finding is an admin reading the closed
 * dispute and issuing here with source='dispute' + the dispute id in
 * the evidence references (§18.4). All mutations run through
 * FindingsService (per-record nonces, atomic claims in the repository,
 * append-only decision history, audit + notices downstream).
 *
 * This file is wp-admin PHP UI — the documented §8 exception (lives
 * under a `*\/Admin/` directory).
 *
 * @package BCC\Trust\Rank\Admin
 * @since Rank redesign Phase 8 (2026-08-04)
 */

namespace BCC\Trust\Rank\Admin;

use BCC\Trust\Rank\Services\FindingsService;

if (!defined('ABSPATH')) {
    exit;
}

class FindingsAdmin
{
    private const PAGE_SLUG = 'bcc-rank-findings';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 20);
        add_action('admin_init', [self::class, 'handle_actions']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'bcc-trust-dashboard',
            __('Rank Findings', 'bcc-trust'),
            __('Rank Findings', 'bcc-trust'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    // ── Router ──────────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $finding_id = isset($_GET['finding_id']) ? absint($_GET['finding_id']) : 0;

        if ($finding_id) {
            self::render_detail($finding_id);
        } else {
            self::render_list();
        }
    }

    // ── List view + issue form ──────────────────────────────────────────────

    private static function render_list(): void
    {
        $table = new FindingsListTable();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Rank Findings', 'bcc-trust') . '</h1>';

        self::render_notices();

        $table->views();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        if (FindingsListTable::statusFilter() !== '') {
            printf('<input type="hidden" name="finding_status" value="%s" />', esc_attr(FindingsListTable::statusFilter()));
        }
        if (FindingsListTable::appealFilter() !== '') {
            printf('<input type="hidden" name="finding_appeal" value="%s" />', esc_attr(FindingsListTable::appealFilter()));
        }
        $table->display();
        echo '</form>';

        self::render_issue_form();

        echo '</div>';
    }

    private static function render_issue_form(): void
    {
        $config = \BCC\Trust\Core\Plugin::instance()->rankScoringConfig();

        echo '<div class="card" style="max-width:640px;margin-top:24px;">';
        echo '<h2>' . esc_html__('Issue a Finding', 'bcc-trust') . '</h2>';
        echo '<p class="description">'
           . esc_html__('Issuance is a deliberate administrative decision (§15.1 — reports and downvotes never create findings automatically). Penalty, ceiling, and expiry are snapshot from the severity class at issuance. For a finding grounded in a closed dispute, choose source "dispute" and record the dispute id in the evidence references (§18.4).', 'bcc-trust')
           . '</p>';

        echo '<form method="post">';
        wp_nonce_field('bcc_finding_issue');
        echo '<input type="hidden" name="bcc_finding_action" value="issue" />';

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="bcc_finding_subject">' . esc_html__('Subject user ID', 'bcc-trust') . '</label></th>';
        echo '<td><input type="number" min="1" required name="subject_user_id" id="bcc_finding_subject" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="bcc_finding_type">' . esc_html__('Finding type', 'bcc-trust') . '</label></th>';
        echo '<td><input type="text" required maxlength="40" name="finding_type" id="bcc_finding_type" class="regular-text" placeholder="e.g. vote_manipulation" /></td></tr>';

        echo '<tr><th scope="row"><label for="bcc_finding_severity">' . esc_html__('Severity', 'bcc-trust') . '</label></th>';
        echo '<td><select name="severity" id="bcc_finding_severity">';
        foreach ($config->findingClasses as $slug => $class) {
            $summary = sprintf(
                '%s — penalty %s%s',
                $slug,
                rtrim(rtrim(number_format($class['score_penalty'], 2), '0'), '.'),
                $class['ceiling_rank'] !== null
                    ? sprintf(', ceiling %s%s', $class['ceiling_rank'], $class['immediate_demotion'] ? ', immediate demotion' : '')
                    : ''
            );
            printf('<option value="%s">%s</option>', esc_attr((string) $slug), esc_html($summary));
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="bcc_finding_source">' . esc_html__('Source', 'bcc-trust') . '</label></th>';
        echo '<td><select name="source" id="bcc_finding_source">';
        echo '<option value="admin">' . esc_html__('admin', 'bcc-trust') . '</option>';
        echo '<option value="dispute">' . esc_html__('dispute (manual, from a closed dispute)', 'bcc-trust') . '</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="bcc_finding_evidence">' . esc_html__('Evidence references', 'bcc-trust') . '</label></th>';
        echo '<td><textarea name="evidence_refs" id="bcc_finding_evidence" class="large-text" rows="2" placeholder="' . esc_attr__('URLs, dispute ids, report ids…', 'bcc-trust') . '"></textarea></td></tr>';

        echo '<tr><th scope="row"><label for="bcc_finding_reason">' . esc_html__('Reason (required)', 'bcc-trust') . '</label></th>';
        echo '<td><textarea name="reason" id="bcc_finding_reason" class="large-text" rows="3" required></textarea></td></tr>';

        echo '<tr><th scope="row"><label for="bcc_finding_restriction">' . esc_html__('Suspension / restriction note (optional)', 'bcc-trust') . '</label></th>';
        echo '<td><textarea name="restriction_note" id="bcc_finding_restriction" class="large-text" rows="2"></textarea>';
        echo '<p class="description">' . esc_html__('Free-text record only — an actual suspension is applied through the existing suspension tools, not here (§15.2).', 'bcc-trust') . '</p></td></tr>';

        echo '</table>';

        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Issue finding (effective now)', 'bcc-trust') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    // ── Detail view ─────────────────────────────────────────────────────────

    private static function render_detail(int $finding_id): void
    {
        $plugin  = \BCC\Trust\Core\Plugin::instance();
        $finding = $plugin->findingsRepository()->getById($finding_id);

        if ($finding === null) {
            echo '<div class="wrap"><h1>' . esc_html__('Finding Not Found', 'bcc-trust') . '</h1></div>';
            return;
        }

        $back_url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $subject  = get_userdata((int) $finding->subject_user_id);
        $issuer   = get_userdata((int) $finding->issued_by);

        echo '<div class="wrap">';
        printf(
            '<h1><a href="%s">← %s</a> &nbsp; %s #%d</h1>',
            esc_url($back_url),
            esc_html__('Rank Findings', 'bcc-trust'),
            esc_html__('Finding', 'bcc-trust'),
            (int) $finding->id
        );

        self::render_notices();

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;">';

        // ── Left column: finding record ─────────────────────────────
        echo '<div>';
        echo '<div class="card" style="max-width:none;">';
        echo '<h2>' . esc_html__('Finding Record', 'bcc-trust') . '</h2>';
        echo '<table class="widefat striped" style="border:none;">';
        self::detail_row(__('Subject', 'bcc-trust'), esc_html($subject !== false ? $subject->user_login : 'Unknown') . ' (#' . (int) $finding->subject_user_id . ')');
        self::detail_row(__('Type', 'bcc-trust'), esc_html((string) $finding->finding_type));
        self::detail_row(__('Severity', 'bcc-trust'), esc_html((string) $finding->severity));
        self::detail_row(__('Status', 'bcc-trust'), esc_html((string) $finding->status));
        self::detail_row(__('Appeal status', 'bcc-trust'), esc_html((string) $finding->appeal_status !== '' ? (string) $finding->appeal_status : '—'));
        self::detail_row(__('Current penalty', 'bcc-trust'), esc_html(number_format((float) $finding->score_penalty, 2)));
        self::detail_row(__('Penalty expires', 'bcc-trust'), esc_html((string) $finding->penalty_expires_at));
        self::detail_row(
            __('Ceiling', 'bcc-trust'),
            $finding->ceiling_rank_slug !== null
                ? esc_html((string) $finding->ceiling_rank_slug . ' until ' . (string) $finding->ceiling_expires_at)
                : '—'
        );
        self::detail_row(__('Source', 'bcc-trust'), esc_html((string) $finding->source));
        self::detail_row(__('Issued by', 'bcc-trust'), esc_html($issuer !== false ? $issuer->user_login : 'Unknown') . ' (#' . (int) $finding->issued_by . ')');
        self::detail_row(__('Created', 'bcc-trust'), esc_html((string) $finding->created_at));
        self::detail_row(__('Effective', 'bcc-trust'), esc_html((string) $finding->effective_at));
        self::detail_row(__('Evidence refs', 'bcc-trust'), esc_html($finding->evidence_refs !== null ? (string) $finding->evidence_refs : '—'));
        self::detail_row(__('Restriction note', 'bcc-trust'), esc_html($finding->restriction_note !== null ? (string) $finding->restriction_note : '—'));
        if ($finding->reversed_at !== null) {
            self::detail_row(__('Reversed', 'bcc-trust'), esc_html((string) $finding->reversed_at . ' — ' . (string) $finding->reversal_reason));
        }
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // ── Right column: decision history + reconsider form ────────
        echo '<div>';

        echo '<div class="card" style="max-width:none;">';
        echo '<h2>' . esc_html__('Decision History (append-only)', 'bcc-trust') . '</h2>';
        echo '<table class="widefat striped" style="border:none;">';
        echo '<thead><tr><th>' . esc_html__('Decision', 'bcc-trust') . '</th><th>' . esc_html__('By', 'bcc-trust') . '</th><th>' . esc_html__('Penalty after', 'bcc-trust') . '</th><th>' . esc_html__('When', 'bcc-trust') . '</th><th>' . esc_html__('Reason', 'bcc-trust') . '</th></tr></thead><tbody>';
        foreach ($plugin->findingDecisionsRepository()->listForFinding($finding_id) as $decision) {
            $decider = get_userdata((int) $decision->decided_by);
            printf(
                '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $decision->decision),
                esc_html(($decider !== false ? $decider->user_login : 'Unknown') . ' (#' . (int) $decision->decided_by . ')'),
                $decision->penalty_after !== null ? esc_html(number_format((float) $decision->penalty_after, 2)) : '—',
                esc_html((string) $decision->created_at),
                esc_html((string) $decision->reason)
            );
        }
        echo '</tbody></table>';
        echo '</div>';

        // §15.5 — reconsideration is available while the finding is
        // active (the same administrator who issued may reconsider).
        if ((string) $finding->status === 'active') {
            self::render_reconsider_form((int) $finding->id, (float) $finding->score_penalty);
        }

        echo '</div>'; // end right column
        echo '</div>'; // end grid
        echo '</div>'; // end wrap
    }

    private static function render_reconsider_form(int $finding_id, float $current_penalty): void
    {
        echo '<div class="card" style="max-width:none;margin-top:16px;">';
        echo '<h2>' . esc_html__('Reconsider', 'bcc-trust') . '</h2>';
        echo '<p class="description">' . esc_html__('Every reconsideration appends a decision row — the original issuance snapshot is never edited (§15.5). Reversal restores the member\'s score deterministically via re-evaluation.', 'bcc-trust') . '</p>';

        echo '<form method="post">';
        wp_nonce_field('bcc_finding_reconsider_' . $finding_id);
        echo '<input type="hidden" name="bcc_finding_action" value="reconsider" />';
        printf('<input type="hidden" name="finding_id" value="%d" />', $finding_id);

        echo '<fieldset style="margin:12px 0;">';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="decision" value="upheld" checked /> ' . esc_html__('Uphold — the finding stands as issued', 'bcc-trust') . '</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="decision" value="reduced" /> '
           . esc_html__('Reduce penalty to:', 'bcc-trust')
           . sprintf(
               ' <input type="number" name="new_penalty" step="0.01" min="0.01" max="%s" style="width:90px;" /> ',
               esc_attr(number_format(max(0.01, $current_penalty - 0.01), 2, '.', ''))
           )
           . esc_html(sprintf(__('(current: %s)', 'bcc-trust'), number_format($current_penalty, 2)))
           . '</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="decision" value="reversed" /> ' . esc_html__('Reverse — void the finding and restore the member', 'bcc-trust') . '</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="decision" value="remanded" /> ' . esc_html__('Remand — send back for further review', 'bcc-trust') . '</label>';
        echo '</fieldset>';

        echo '<p><label for="bcc_reconsider_reason"><strong>' . esc_html__('Reason (required)', 'bcc-trust') . '</strong></label><br />';
        echo '<textarea name="reason" id="bcc_reconsider_reason" class="large-text" rows="3" required></textarea></p>';

        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Record decision', 'bcc-trust') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    // ── Handle admin POST actions ───────────────────────────────────────────

    public static function handle_actions(): void
    {
        if (!isset($_POST['bcc_finding_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized.', 'bcc-trust'));
        }

        $action = sanitize_key($_POST['bcc_finding_action']);

        if ($action === 'issue') {
            self::handle_issue();
        } elseif ($action === 'reconsider') {
            self::handle_reconsider();
        }
    }

    private static function handle_issue(): void
    {
        check_admin_referer('bcc_finding_issue');

        $subject_id = absint($_POST['subject_user_id'] ?? 0);
        $type       = sanitize_text_field((string) ($_POST['finding_type'] ?? ''));
        $severity   = sanitize_key($_POST['severity'] ?? '');
        $source     = sanitize_key($_POST['source'] ?? 'admin');
        $evidence   = sanitize_textarea_field((string) ($_POST['evidence_refs'] ?? ''));
        $reason     = sanitize_textarea_field((string) ($_POST['reason'] ?? ''));
        $note       = sanitize_textarea_field((string) ($_POST['restriction_note'] ?? ''));

        // The wp-admin form offers admin|dispute only; 'fraud_detection'
        // is reserved with no writer (§15.1).
        if (!in_array($source, ['admin', 'dispute'], true)) {
            $source = 'admin';
        }

        if ($subject_id <= 0 || $type === '' || $reason === '' || get_userdata($subject_id) === false) {
            self::redirect(['finding_error' => 'invalid_issue']);
        }

        $finding_id = self::service()->issue(
            $subject_id,
            $type,
            $severity,
            $evidence !== '' ? $evidence : null,
            $source,
            get_current_user_id(),
            $reason,
            $note !== '' ? $note : null
        );

        if ($finding_id <= 0) {
            self::redirect(['finding_error' => 'issue_failed']);
        }

        self::redirect(['finding_id' => $finding_id, 'finding_notice' => 'issued']);
    }

    private static function handle_reconsider(): void
    {
        $finding_id = absint($_POST['finding_id'] ?? 0);
        if (!$finding_id) {
            return;
        }

        // Per-record nonce (DisputeAdmin pattern) — a nonce minted for
        // one finding cannot be replayed against another.
        check_admin_referer('bcc_finding_reconsider_' . $finding_id);

        $decision = sanitize_key($_POST['decision'] ?? '');
        $reason   = sanitize_textarea_field((string) ($_POST['reason'] ?? ''));

        $new_penalty = null;
        if ($decision === 'reduced') {
            $raw = isset($_POST['new_penalty']) && is_numeric($_POST['new_penalty']) ? (float) $_POST['new_penalty'] : 0.0;
            $new_penalty = $raw > 0.0 ? $raw : null;
        }

        $ok = self::service()->reconsider(
            $finding_id,
            get_current_user_id(),
            $decision,
            $reason,
            $new_penalty
        );

        self::redirect([
            'finding_id' => $finding_id,
            $ok ? 'finding_notice' : 'finding_error' => $ok ? $decision : 'reconsider_failed',
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function service(): FindingsService
    {
        return \BCC\Trust\Core\Plugin::instance()->findingsService();
    }

    /**
     * @param array<string, int|string> $args
     */
    private static function redirect(array $args): never
    {
        wp_safe_redirect(add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $args),
            admin_url('admin.php')
        ));
        exit;
    }

    private static function render_notices(): void
    {
        if (isset($_GET['finding_notice'])) {
            $notice   = sanitize_key($_GET['finding_notice']);
            $messages = [
                'issued'   => __('Finding issued. Score and rank were re-evaluated.', 'bcc-trust'),
                'upheld'   => __('Decision recorded: upheld.', 'bcc-trust'),
                'reduced'  => __('Decision recorded: penalty reduced. Score re-evaluated.', 'bcc-trust'),
                'reversed' => __('Decision recorded: finding reversed. Score and rank restored via re-evaluation.', 'bcc-trust'),
                'remanded' => __('Decision recorded: remanded for further review.', 'bcc-trust'),
            ];
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($messages[$notice] ?? __('Done.', 'bcc-trust'))
            );
        } elseif (isset($_GET['finding_error'])) {
            $error    = sanitize_key($_GET['finding_error']);
            $messages = [
                'invalid_issue'      => __('Issue failed — check the subject user id, type, and reason.', 'bcc-trust'),
                'issue_failed'       => __('Issue failed — the finding could not be written.', 'bcc-trust'),
                'reconsider_failed'  => __('Reconsideration failed — check the decision (a reduction must lower the penalty; a reversed finding cannot be changed again).', 'bcc-trust'),
            ];
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($messages[$error] ?? __('Action failed.', 'bcc-trust'))
            );
        }
    }

    /**
     * @param string|int|float|null $value
     */
    private static function detail_row(string $label, $value): void
    {
        printf(
            '<tr><th style="width:140px;">%s</th><td>%s</td></tr>',
            esc_html($label),
            wp_kses_post((string) $value)
        );
    }
}
