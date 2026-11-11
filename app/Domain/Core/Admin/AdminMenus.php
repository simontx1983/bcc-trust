<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

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
 * landing page's slug, registered at priority 5 so every submenu that
 * names it as parent (priority 10–30) finds it already in place.
 *
 * Developer pages are contributed to bcc-core's `bcc_admin_dev_pages`
 * filter, which drops them from the menu unless BCC_DEV_TOOLS === true.
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

    /** Submenu order. */
    public const ORDER_NOTIFICATIONS   = 0;
    public const ORDER_MODERATION      = 10;
    public const ORDER_DISPUTES        = 20;
    public const ORDER_REPORTS         = 30;
    public const ORDER_FINDINGS        = 40;
    public const ORDER_ANALYTICS       = 50;

    public const ORDER_VERIFY          = 0;
    public const ORDER_CHAINS          = 10;
    public const ORDER_NFT_DISCOVERY   = 20;
    public const ORDER_HOLDER_GROUPS   = 30;
    public const ORDER_SIGNALS         = 40;
    public const ORDER_WEBHOOKS        = 50;

    /** Inside BCC System (bcc-core uses 0/10/20 and 80 for Developer). */
    public const ORDER_AUDIT_LOG       = 30;
    public const ORDER_DEBUG           = 81;
    public const ORDER_REPAIR          = 82;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerTopLevel'], 5);
        add_filter('bcc_admin_dev_pages', [self::class, 'devPages']);
        add_filter('bcc_admin_hidden_pages', [self::class, 'hiddenPages']);
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
        return $slugs;
    }
}
