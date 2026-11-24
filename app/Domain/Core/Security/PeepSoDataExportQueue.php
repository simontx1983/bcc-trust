<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PeepSo's data-export queue stays closed; BCC handles data requests.
 * Rationale: F-581 (private tracker).
 *
 * Two admin-side pieces, both for every caller, admins included:
 *
 * 1. `peepso_admin_queue_tabs`, last: the queue screen's `gdpr` tab is
 *    REPLACED by a closed tab that only says so. PeepSo applies the filter
 *    to an empty list and then merges its own default tabs under it
 *    (`array_merge($default_tabs, $filtered)`), so a filter cannot remove a
 *    default tab, but an entry under the same key replaces it. PeepSo's own
 *    queue screen for the tab is then never called.
 * 2. `admin_init`, first: a request to run the queue
 *    (`action=process-request-data` on PeepSo's queue pages) is refused
 *    with a 403 before any admin page renders.
 *
 * PeepSo's `gdpr_enable` is pinned off in WordPressSignInGate, and members
 * never reach PeepSo's ajax router (WordPressSignInGate, door 2).
 */
final class PeepSoDataExportQueue
{
    /** PeepSo's key for the data-export tab on its Queues screen. */
    public const TAB = 'gdpr';

    /** The `action` value that runs PeepSo's data-export queue. */
    public const PROCESS_ACTION = 'process-request-data';

    /** PeepSo's admin pages that can carry the process action. */
    public const QUEUE_PAGES = ['peepso-queue', 'peepso-gdpr-request-data'];

    public static function register(): void
    {
        add_filter('peepso_admin_queue_tabs', [self::class, 'closeExportTab'], PHP_INT_MAX);
        add_action('admin_init', [self::class, 'refuseQueueRun'], 1);
    }

    /**
     * `peepso_admin_queue_tabs`, last: the data-export tab becomes a closed
     * tab. Every other tab passes through untouched.
     *
     * @param mixed $tabs
     * @return array<array-key, mixed>
     */
    public static function closeExportTab($tabs = []): array
    {
        $tabs = is_array($tabs) ? $tabs : [];
        $tabs[self::TAB] = [
            'label'       => __('Data export (closed)', 'bcc-trust'),
            'icon'        => '',
            'tab'         => self::TAB,
            'description' => '',
            'count'       => 0,
            'function'    => [self::class, 'renderClosedTab'],
            'cat'         => 'foundation',
        ];

        return $tabs;
    }

    /** The closed tab's screen: a notice, nothing else. */
    public static function renderClosedTab(): void
    {
        echo '<div class="notice notice-info"><p>'
            . esc_html__('PeepSo\'s data-export queue stays closed; BCC handles data requests.', 'bcc-trust')
            . '</p></div>';
    }

    /** `admin_init`, first: a request to run the data-export queue gets a 403. */
    public static function refuseQueueRun(): void
    {
        if (!self::isQueueRun($_GET)) {
            return;
        }

        wp_die(
            esc_html__('PeepSo\'s data-export queue stays closed; BCC handles data requests.', 'bcc-trust'),
            '',
            ['response' => 403]
        );
    }

    /**
     * Is this a request to run the data-export queue? The process action on
     * one of PeepSo's queue pages, matched as PeepSo reads them (`$_GET`).
     *
     * @param array<array-key, mixed> $query
     */
    public static function isQueueRun(array $query): bool
    {
        $action = $query['action'] ?? null;
        $page   = $query['page'] ?? null;

        return is_string($action) && is_string($page)
            && $action === self::PROCESS_ACTION
            && in_array($page, self::QUEUE_PAGES, true);
    }
}
