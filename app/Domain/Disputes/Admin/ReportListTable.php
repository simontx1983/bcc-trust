<?php

namespace BCC\Trust\Disputes\Admin;

use BCC\Trust\Disputes\DTO\AdminReportRowDTO;
use BCC\Trust\Disputes\Repositories\UserReportRepository;
use WP_List_Table;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ReportListTable extends WP_List_Table
{
    /**
     * F-308: per-row POST forms, printed by renderRowForms() after the
     * outer GET form closes.
     *
     * @var list<string>
     */
    private array $rowForms = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'report',
            'plural'   => 'reports',
            'ajax'     => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'id'            => __('ID', 'bcc-disputes'),
            'reported_name' => __('Reported User', 'bcc-disputes'),
            'reporter_name' => __('Reporter', 'bcc-disputes'),
            'reason_key'    => __('Reason', 'bcc-disputes'),
            'reason_detail' => __('Details', 'bcc-disputes'),
            'status'        => __('Status', 'bcc-disputes'),
            'created_at'    => __('Created', 'bcc-disputes'),
            'actions'       => __('Actions', 'bcc-disputes'),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function get_sortable_columns(): array
    {
        return [
            'id'         => ['id', true],
            'status'     => ['status', false],
            'created_at' => ['created_at', false],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function get_views(): array
    {
        $current = isset($_GET['report_status']) ? sanitize_key($_GET['report_status']) : 'all';
        $base    = admin_url('admin.php?page=bcc-reports');

        $counts = UserReportRepository::getReportStatusCounts();
        $total  = array_sum($counts);

        $views = [];
        $views['all'] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url($base),
            $current === 'all' ? 'current' : '',
            __('All', 'bcc-disputes'),
            $total
        );

        foreach (['open', 'reviewed', 'penalized', 'dismissed'] as $s) {
            $c = $counts[$s] ?? 0;
            $views[$s] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('report_status', $s, $base)),
                $current === $s ? 'current' : '',
                ucfirst($s),
                $c
            );
        }

        return $views;
    }

    public function prepare_items(): void
    {
        // Filters
        $status_filter = isset($_GET['report_status']) ? sanitize_key($_GET['report_status']) : '';
        if (!in_array($status_filter, ['open', 'reviewed', 'penalized', 'dismissed'], true)) {
            $status_filter = '';
        }

        // Sorting
        $allowed_orderby = ['id', 'status', 'created_at'];
        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], $allowed_orderby, true)
            ? sanitize_key($_GET['orderby'])
            : 'id';
        $order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Count
        $total = UserReportRepository::countReportsForAdminList($status_filter ?: null);

        // Pagination
        $per_page = 20;
        $paged    = $this->get_pagenum();
        $offset   = ($paged - 1) * $per_page;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);

        // Query via repository — explicit columns, no SELECT *.
        $this->items = UserReportRepository::getReportsForAdminList(
            $status_filter ?: null,
            $orderby,
            $order,
            $per_page,
            $offset
        );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function reason_labels(): array
    {
        return [
            'spam'            => __('Spam or unsolicited content', 'bcc-disputes'),
            'harassment'      => __('Harassment or bullying', 'bcc-disputes'),
            'fraud'           => __('Fraudulent activity or scam', 'bcc-disputes'),
            'misinformation'  => __('False or misleading information', 'bcc-disputes'),
            'inappropriate'   => __('Inappropriate content', 'bcc-disputes'),
            'impersonation'   => __('Impersonating another person', 'bcc-disputes'),
            'other'           => __('Other', 'bcc-disputes'),
        ];
    }

    /**
     * @param AdminReportRowDTO $item
     * @param string            $column_name
     * @return string|int
     */
    public function column_default($item, $column_name)
    {
        // Narrow to DTO: prepare_items() only assigns AdminReportRowDTO rows,
        // but WP_List_Table's untyped parent signature blocks a native hint.
        if (!$item instanceof AdminReportRowDTO) {
            return '';
        }

        $labels = self::reason_labels();

        switch ($column_name) {
            case 'id':
                return $item->id;

            // F-311 (M14): both people link to their wp-admin moderation
            // page (history, suspension, whitelist), so the admin can act on
            // a report without hunting for the member by id.
            case 'reported_name':
                return self::memberLink($item->reported_id, $item->reported_name);

            case 'reporter_name':
                return self::memberLink($item->reporter_id, $item->reporter_name);

            case 'reason_key':
                return esc_html($labels[$item->reason_key] ?? $item->reason_key);

            case 'reason_detail':
                // F-311 (M14): up to 1000 characters are accepted, and the
                // full text used to exist only in the admin email. Long
                // details now expand in place; still plain escaped text.
                if (mb_strlen($item->reason_detail) <= 80) {
                    return esc_html($item->reason_detail);
                }
                return sprintf(
                    '<details><summary>%s</summary><div style="white-space:pre-wrap;max-width:40em;">%s</div></details>',
                    esc_html(mb_strimwidth($item->reason_detail, 0, 80, '…')),
                    esc_html($item->reason_detail)
                );

            case 'status':
                $colors = [
                    'open'      => '#ed6c02',
                    'reviewed'  => '#2e7d32',
                    'penalized' => '#d63638',
                    'dismissed' => '#666',
                ];
                $color = $colors[$item->status] ?? '#666';
                return sprintf(
                    '<span style="font-weight:600;color:%s;">%s</span>',
                    esc_attr($color),
                    esc_html(ucfirst($item->status))
                );

            case 'created_at':
                return esc_html($item->created_at);

            case 'actions':
                $is_open = $item->status === 'open';
                if (!$is_open) {
                    $label = $item->status === 'penalized'
                        ? __('Penalized', 'bcc-disputes')
                        : ucfirst($item->status);
                    return '<em>' . esc_html($label) . '</em>';
                }

                $reportId = (int) $item->id;

                // F-308: this table renders INSIDE DisputeAdmin's outer
                // <form method="get">. The HTML parser drops a <form> nested in
                // a form, and the dropped form's </form> then closes the OUTER
                // one, so the first row's Reviewed button submitted the GET
                // filter form instead: no confirm, no action, report still open.
                // The cell now holds only the controls, bound with the form=""
                // attribute to POST forms that renderRowForms() prints AFTER the
                // outer form closes. Same pattern as the Verify Collections
                // description review (renderDescriptionReviewForms).
                //
                // SECURITY: POST forms, never GET anchors, so a nonce cannot be
                // lifted from cached or prefetched page source.
                $reviewedId  = 'bcc-report-reviewed-' . $reportId;
                $dismissedId = 'bcc-report-dismissed-' . $reportId;
                $penalizeId  = 'bcc-report-penalize-' . $reportId;

                $this->rowForms[] = self::actionForm(
                    $reviewedId,
                    $reportId,
                    'reviewed',
                    wp_nonce_field('bcc_report_action_' . $reportId, '_wpnonce', true, false),
                    __('Mark this report as reviewed?', 'bcc-disputes')
                );
                $this->rowForms[] = self::actionForm(
                    $dismissedId,
                    $reportId,
                    'dismissed',
                    wp_nonce_field('bcc_report_action_' . $reportId, '_wpnonce', true, false),
                    __('Dismiss this report?', 'bcc-disputes')
                );
                $this->rowForms[] = self::actionForm(
                    $penalizeId,
                    $reportId,
                    'penalize',
                    sprintf(
                        '<input type="hidden" name="_wpnonce" value="%s" />',
                        esc_attr(wp_create_nonce('bcc_report_penalize_' . $reportId))
                    ),
                    __("Reduce this user's reputation score?", 'bcc-disputes')
                );

                return sprintf(
                    '<button type="submit" form="%s" class="button button-small">%s</button> ',
                    esc_attr($reviewedId),
                    esc_html__('Reviewed', 'bcc-disputes')
                ) . sprintf(
                    '<button type="submit" form="%s" class="button button-small">%s</button>',
                    esc_attr($dismissedId),
                    esc_html__('Dismiss', 'bcc-disputes')
                ) . sprintf(
                    '<span style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;">'
                    . '<label style="font-size:12px;white-space:nowrap;">Penalize:</label>'
                    . '<input type="number" form="%1$s" name="penalty_points" min="1" max="20" value="5" '
                    .   'style="width:55px;height:28px;padding:2px 4px;" title="Points to deduct (1-20)" />'
                    . '<input type="text" form="%1$s" name="penalty_reason" placeholder="Reason..." '
                    .   'style="width:120px;height:28px;padding:2px 4px;font-size:12px;" />'
                    . '<button type="submit" form="%1$s" class="button button-small" style="color:var(--bcc-danger);">Apply</button>'
                    . '</span>',
                    esc_attr($penalizeId)
                );

            default:
                return '';
        }
    }

    /**
     * Print the per-row POST forms collected while the table rendered.
     *
     * F-308: call this AFTER the list's outer GET form has closed. The row
     * controls point at these forms by id (form=""), so they submit here
     * with their own confirm, never through the outer form.
     */
    public function renderRowForms(): void
    {
        echo implode("\n", $this->rowForms);
        $this->rowForms = [];
    }

    /**
     * One empty POST form: hidden fields only, with the confirm prompt on
     * submit. Its visible controls live in the table row.
     */
    private static function actionForm(string $id, int $reportId, string $action, string $nonceHtml, string $confirm): string
    {
        return sprintf(
            '<form id="%s" method="post" action="%s" onsubmit="return confirm(\'%s\');">'
            . '<input type="hidden" name="page" value="bcc-reports">'
            . '<input type="hidden" name="report_id" value="%d">'
            . '<input type="hidden" name="report_action" value="%s">'
            . '%s'
            . '</form>',
            esc_attr($id),
            esc_url(admin_url('admin.php')),
            esc_js($confirm),
            $reportId,
            esc_attr($action),
            $nonceHtml
        );
    }

    /** F-311 (M14): a member's name linked to their wp-admin moderation page. */
    private static function memberLink(int $userId, ?string $name): string
    {
        return sprintf(
            '<a href="%s">%s</a> <span class="description">(#%d)</span>',
            esc_url(add_query_arg(
                ['page' => 'bcc-trust-moderation', 'user_id' => $userId],
                admin_url('admin.php')
            )),
            esc_html($name ?: __('Unknown', 'bcc-disputes')),
            $userId
        );
    }

    public function no_items(): void
    {
        esc_html_e('No user reports found.', 'bcc-disputes');
    }
}
