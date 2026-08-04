<?php
/**
 * FindingsListTable — WP_List_Table over the misconduct finding store
 * (Rank Phase 8; DisputeListTable pattern).
 *
 * Reads exclusively through FindingsRepository (explicit columns, no
 * SELECT *). Filters: status (active/reversed) and the appeal queue
 * (appeal_status='requested' — rendered as its own view so pending
 * appeals are one click away and highlighted in the list).
 *
 * @package BCC\Trust\Rank\Admin
 * @since Rank redesign Phase 8 (2026-08-04)
 */

namespace BCC\Trust\Rank\Admin;

use WP_List_Table;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * @phpstan-import-type FindingRow from \BCC\Trust\Rank\Repositories\FindingsRepository
 */
class FindingsListTable extends WP_List_Table
{
    private const PER_PAGE = 20;

    public function __construct()
    {
        parent::__construct([
            'singular' => 'finding',
            'plural'   => 'findings',
            'ajax'     => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'id'            => __('ID', 'bcc-trust'),
            'subject'       => __('Subject', 'bcc-trust'),
            'finding_type'  => __('Type', 'bcc-trust'),
            'severity'      => __('Severity', 'bcc-trust'),
            'score_penalty' => __('Penalty', 'bcc-trust'),
            'status'        => __('Status', 'bcc-trust'),
            'appeal_status' => __('Appeal', 'bcc-trust'),
            'created_at'    => __('Issued', 'bcc-trust'),
            'actions'       => __('Actions', 'bcc-trust'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function get_views(): array
    {
        $status = self::statusFilter();
        $appeal = self::appealFilter();
        $base   = admin_url('admin.php?page=bcc-rank-findings');

        $repository = \BCC\Trust\Core\Plugin::instance()->findingsRepository();

        $views = [
            'all' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url($base),
                ($status === '' && $appeal === '') ? 'current' : '',
                __('All', 'bcc-trust'),
                $repository->countForAdmin(null, null)
            ),
            'active' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('finding_status', 'active', $base)),
                $status === 'active' ? 'current' : '',
                __('Active', 'bcc-trust'),
                $repository->countForAdmin('active', null)
            ),
            'reversed' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('finding_status', 'reversed', $base)),
                $status === 'reversed' ? 'current' : '',
                __('Reversed', 'bcc-trust'),
                $repository->countForAdmin('reversed', null)
            ),
            'appeal_requested' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('finding_appeal', 'requested', $base)),
                $appeal === 'requested' ? 'current' : '',
                __('Appeal requested', 'bcc-trust'),
                $repository->countForAdmin(null, 'requested')
            ),
        ];

        return $views;
    }

    public function prepare_items(): void
    {
        $status = self::statusFilter();
        $appeal = self::appealFilter();

        $repository = \BCC\Trust\Core\Plugin::instance()->findingsRepository();

        $total  = $repository->countForAdmin($status !== '' ? $status : null, $appeal !== '' ? $appeal : null);
        $paged  = $this->get_pagenum();
        $offset = ($paged - 1) * self::PER_PAGE;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);

        $this->items = $repository->listForAdmin(
            $status !== '' ? $status : null,
            $appeal !== '' ? $appeal : null,
            self::PER_PAGE,
            $offset
        );

        $this->_column_headers = [$this->get_columns(), [], []];
    }

    /**
     * Highlight rows with a pending appeal so the queue is visible even
     * in the unfiltered list.
     *
     * @param FindingRow $item
     */
    public function single_row($item): void
    {
        $pending = isset($item->appeal_status) && (string) $item->appeal_status === 'requested';
        echo $pending ? '<tr style="background:#fff8e5;">' : '<tr>';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    /**
     * @param FindingRow $item
     * @param string     $column_name
     */
    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'id':
                return (string) (int) $item->id;

            case 'subject':
                $subjectId = (int) $item->subject_user_id;
                $user      = get_userdata($subjectId);
                $name      = $user !== false ? $user->user_login : __('Unknown', 'bcc-trust');
                return sprintf('%s <span class="description">(#%d)</span>', esc_html($name), $subjectId);

            case 'finding_type':
                return esc_html((string) $item->finding_type);

            case 'severity':
                return esc_html((string) $item->severity);

            case 'score_penalty':
                return esc_html(number_format((float) $item->score_penalty, 2));

            case 'status':
                $status = (string) $item->status;
                $color  = $status === 'active' ? '#c62828' : '#2e7d32';
                return sprintf(
                    '<span style="font-weight:600;color:%s;">%s</span>',
                    esc_attr($color),
                    esc_html(ucfirst($status))
                );

            case 'appeal_status':
                $appeal = (string) $item->appeal_status;
                if ($appeal === '') {
                    return '—';
                }
                if ($appeal === 'requested') {
                    return '<strong style="color:#b26a00;">' . esc_html__('Requested', 'bcc-trust') . '</strong>';
                }
                return esc_html(ucfirst($appeal));

            case 'created_at':
                return esc_html((string) $item->created_at);

            case 'actions':
                $review_url = add_query_arg(
                    ['page' => 'bcc-rank-findings', 'finding_id' => (int) $item->id],
                    admin_url('admin.php')
                );
                return sprintf(
                    '<a href="%s" class="button button-small">%s</a>',
                    esc_url($review_url),
                    esc_html__('Review', 'bcc-trust')
                );

            default:
                return '';
        }
    }

    public function no_items(): void
    {
        esc_html_e('No findings recorded.', 'bcc-trust');
    }

    public static function statusFilter(): string
    {
        $status = isset($_GET['finding_status']) ? sanitize_key($_GET['finding_status']) : '';
        return in_array($status, ['active', 'reversed'], true) ? $status : '';
    }

    public static function appealFilter(): string
    {
        $appeal = isset($_GET['finding_appeal']) ? sanitize_key($_GET['finding_appeal']) : '';
        return in_array($appeal, ['requested', 'upheld', 'reduced', 'reversed', 'remanded'], true) ? $appeal : '';
    }
}
