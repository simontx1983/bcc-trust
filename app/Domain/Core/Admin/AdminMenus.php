<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

use BCC\Core\Admin\AdminMenu;
use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;

/**
 * bcc-trust's two top-level wp-admin menus and where every trust page sits.
 *
 *   BCC Moderation (pos 25) — Notifications · Moderation · Disputes ·
 *                             User Reports · Rank Findings · Trust Analytics
 *   BCC On-Chain   (pos 26) — Verify Collections · Chains · NFT Discovery ·
 *                             Holder Groups · Signals & Indexer · Webhooks
 *   BCC System     (pos 27, owned by bcc-core) — trust adds Audit Log, plus
 *                             Trust Debug and Repair to the Developer group.
 *
 * Page SLUGS never change (admin-post handlers and PRG redirects target
 * them); only parent, title and order do. A top-level menu's slug is its
 * landing page's slug, registered at priority 5 so every submenu naming
 * it as parent finds it in place.
 *
 * ORDER is not set per add_submenu_page() call: bcc-core's
 * AdminMenu::sortMenus() orders each menu by the slug lists contributed
 * here (bcc_admin_menu_order), and drops WP's auto-inserted duplicate
 * first item.
 *
 * Hidden pages (dev pages while BCC_DEV_TOOLS is off; the Wallets alias)
 * register through {@see parentFor()} — an empty parent keeps the URL
 * working without a menu entry.
 */
final class AdminMenus
{
    /** Top-level "BCC Moderation" — lands on the Notification Center. */
    public const MODERATION = NotificationCenter::PAGE_SLUG;

    /** Top-level "BCC On-Chain" — lands on Verify Collections. */
    public const ONCHAIN = VerifyCollectionsPage::PAGE_SLUG;

    /** bcc-core's "BCC System" top-level (BCC\Core\Admin\AdminMenu::SYSTEM). */
    public const SYSTEM = 'bcc-system-health';

    public const DEBUG_SLUG  = 'bcc-trust-debug';
    public const REPAIR_SLUG = 'bcc-system-repair';

    private const MODERATION_ORDER = [
        NotificationCenter::PAGE_SLUG,
        'bcc-trust-moderation',
        'bcc-disputes',
        'bcc-reports',
        'bcc-rank-findings',
        'bcc-trust-dashboard',
    ];

    private const ONCHAIN_ORDER = [
        VerifyCollectionsPage::PAGE_SLUG,
        'bcc-onchain-chains',
        'bcc-onchain-nft-discovery',
        'bcc-holder-groups',
        'bcc-onchain-signals',
        'bcc-onchain-webhooks',
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerTopLevel'], 5);
        add_filter('bcc_admin_dev_pages', [self::class, 'devPages']);
        add_filter('bcc_admin_hidden_pages', [self::class, 'hiddenPages']);
        add_filter('bcc_admin_menu_order', [self::class, 'menuOrder']);
    }

    public static function registerTopLevel(): void
    {
        add_menu_page(
            'BCC Moderation',
            'BCC Moderation',
            'manage_options',
            self::MODERATION,
            [NotificationCenter::class, 'render'],
            'dashicons-shield',
            25
        );

        add_menu_page(
            'BCC On-Chain',
            'BCC On-Chain',
            'manage_options',
            self::ONCHAIN,
            [VerifyCollectionsPage::class, 'render_page'],
            'dashicons-networking',
            26
        );
    }

    /**
     * BCC System parent for $slug, or '' (unlisted, URL still works) when
     * bcc-core marks it hidden.
     */
    public static function parentFor(string $slug): string
    {
        // Runs on admin_menu for EVERY wp-admin request: an older bcc-core
        // (no AdminMenu yet) must degrade to "listed under BCC System",
        // never fatal. Release order is still core → trust.
        if (!class_exists(AdminMenu::class)) {
            return self::SYSTEM;
        }
        return AdminMenu::parentFor(self::SYSTEM, $slug);
    }

    /**
     * @param mixed $slugs
     * @return list<string>
     */
    public static function devPages($slugs): array
    {
        $slugs   = is_array($slugs) ? array_values(array_map('strval', $slugs)) : [];
        $slugs[] = self::DEBUG_SLUG;
        $slugs[] = self::REPAIR_SLUG;
        return $slugs;
    }

    /**
     * Wallets merged into the Audit Log page as a tab; its old slug stays
     * registered (renders the same page on the Wallets tab) for old links.
     *
     * @param mixed $slugs
     * @return list<string>
     */
    public static function hiddenPages($slugs): array
    {
        $slugs   = is_array($slugs) ? array_values(array_map('strval', $slugs)) : [];
        $slugs[] = WalletAuditPage::PAGE_SLUG;
        // One-off tool: reachable by URL, kept out of the menu.
        $slugs[] = PeepSoLeftoversPage::PAGE_SLUG;
        return $slugs;
    }

    /**
     * @param mixed $orders
     * @return array<string, mixed>
     */
    public static function menuOrder($orders): array
    {
        $orders = is_array($orders) ? $orders : [];
        $orders[self::MODERATION] = self::MODERATION_ORDER;
        $orders[self::ONCHAIN]    = self::ONCHAIN_ORDER;
        return $orders;
    }
}
