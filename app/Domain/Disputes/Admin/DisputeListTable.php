<?php

namespace BCC\Trust\Disputes\Admin;

use BCC\Trust\Disputes\DTO\AdminDisputeRowDTO;
use BCC\Trust\Disputes\Repositories\DisputeAdminRepository;
use WP_List_Table;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class DisputeListTable extends WP_List_Table
{
    /**
     * Vote type enrichment captured during prepare_items(), keyed by dispute id.
     * Stored separately from $this->items so the DTOs stay immutable.
     *
     * @var array<int, int|null>
     */
    private array $voteTypeByDisputeId = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'dispute',
            'plural'   => 'disputes',
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
            'page_title'    => __('Page', 'bcc-disputes'),
            'reporter_name' => __('Reporter', 'bcc-disputes'),
            'voter_name'    => __('Accused Voter', 'bcc-disputes'),
            'vote_type'     => __('Vote', 'bcc-disputes'),
            'reason'        => __('Reason', 'bcc-disputes'),
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
        $current = isset($_GET['dispute_status']) ? sanitize_key($_GET['dispute_status']) : 'all';
        $base    = admin_url('admin.php?page=bcc-disputes');

        $counts = DisputeAdminRepository::getDisputeStatusCounts();
        $total  = array_sum($counts);

        $views = [];
        $views['all'] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url($base),
            $current === 'all' ? 'current' : '',
            __('All', 'bcc-disputes'),
            $total
        );

        $filterLabels = [
            'reviewing'         => 'Reviewing',
            'accepted'          => 'Accepted',
            'rejected'          => 'Rejected',
            'timeout_no_quorum' => 'Expired (no quorum)',
        ];
        foreach ($filterLabels as $s => $label) {
            $c = $counts[$s] ?? 0;
            $views[$s] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('dispute_status', $s, $base)),
                $current === $s ? 'current' : '',
                esc_html($label),
                $c
            );
        }

        return $views;
    }

    public function prepare_items(): void
    {
        // Filters
        $status_filter = isset($_GET['dispute_status']) ? sanitize_key($_GET['dispute_status']) : '';
        if (!in_array($status_filter, ['reviewing', 'accepted', 'rejected', 'timeout_no_quorum'], true)) {
            $status_filter = '';
        }

        // Sorting
        $allowed_orderby = ['id', 'status', 'created_at'];
        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], $allowed_orderby, true)
            ? sanitize_key($_GET['orderby'])
            : 'id';
        $order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Count
        $total = DisputeAdminRepository::countDisputesForAdminList($status_filter ?: null);

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
        $items = DisputeAdminRepository::getDisputesForAdminList(
            $status_filter ?: null,
            $orderby,
            $order,
            $per_page,
            $offset
        );
        $this->items = $items;

        // Batch-enrich with vote data from trust-engine via interface.
        // Enrichment lives in a side-map (voteTypeByDisputeId), not on the DTO,
        // because the DTO is immutable (readonly) and because vote_type is a
        // cross-plugin projection distinct from the DB row's concern.
        $vote_ids = array_map(static fn(AdminDisputeRowDTO $item): int => $item->vote_id, $items);

        // NullTrustReadService::getVotesByIds() returns [] — admin sees empty vote_type column.
        $votes_by_id = [];
        if (!empty($vote_ids)) {
            $votes_by_id = \BCC\Core\ServiceLocator::resolveTrustReadService()->getVotesByIds($vote_ids);
        }

        $this->voteTypeByDisputeId = [];
        foreach ($items as $item) {
            $voteRow = $votes_by_id[$item->vote_id] ?? null;
            $voteType = null;
            if (is_array($voteRow) && isset($voteRow['vote_type']) && is_numeric($voteRow['vote_type'])) {
                $voteType = (int) $voteRow['vote_type'];
            }
            $this->voteTypeByDisputeId[$item->id] = $voteType;
        }

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    /**
     * @param AdminDisputeRowDTO $item
     * @param string             $column_name
     * @return string|int
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'id':
                return $item->id;

            case 'page_title':
                $title = $item->page_title ?: __('(no title)', 'bcc-disputes');
                return sprintf(
                    '%s <span class="description">(#%d)</span>',
                    esc_html($title),
                    $item->page_id
                );

            case 'reporter_name':
                return esc_html($item->reporter_name ?: __('Unknown', 'bcc-disputes'));

            case 'voter_name':
                return esc_html($item->voter_name ?: __('Unknown', 'bcc-disputes'));

            case 'vote_type':
                $voteType = $this->voteTypeByDisputeId[$item->id] ?? null;
                if ($voteType === null) {
                    return '—';
                }
                return $voteType > 0
                    ? '<span style="color:#2e7d32;">&#9650; Upvote</span>'
                    : '<span style="color:#c62828;">&#9660; Downvote</span>';

            case 'reason':
                return esc_html(mb_strimwidth($item->reason, 0, 80, '…'));

            case 'status':
                $colors = [
                    'reviewing'         => '#0288d1',
                    'accepted'          => '#2e7d32',
                    'rejected'          => '#c62828',
                    'timeout_no_quorum' => '#9e9e9e',
                ];
                $labels = [
                    'reviewing'         => 'Reviewing',
                    'accepted'          => 'Accepted',
                    'rejected'          => 'Rejected',
                    'timeout_no_quorum' => 'Expired',
                ];
                $color = $colors[$item->status] ?? '#666';
                $label = $labels[$item->status] ?? ucfirst($item->status);
                return sprintf(
                    '<span style="font-weight:600;color:%s;">%s</span>',
                    esc_attr($color),
                    esc_html($label)
                );

            case 'created_at':
                return esc_html($item->created_at);

            case 'actions':
                $review_url = add_query_arg(
                    ['page' => 'bcc-disputes', 'dispute_id' => $item->id],
                    admin_url('admin.php')
                );
                return sprintf(
                    '<a href="%s" class="button button-small">%s</a>',
                    esc_url($review_url),
                    esc_html__('Review', 'bcc-disputes')
                );

            default:
                return '';
        }
    }

    public function no_items(): void
    {
        esc_html_e('No disputes found.', 'bcc-disputes');
    }
}
