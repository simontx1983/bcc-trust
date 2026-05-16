<?php
/**
 * Blog Chain-Tags Table Schema
 *
 * §D6 crypto-blog composer (PR-A) — post ↔ chain join.
 *
 * A blog post (wp_posts row with `_bcc_activity_module = 'blog'`) may
 * be tagged with up to BLOG_CHAIN_TAGS_MAX chains drawn from the
 * existing `bcc_onchain_chains` registry. Storing the join here (NOT
 * in WP post_meta) keeps the surface relational so the blog tab can
 * filter "show me Bitcoin posts by this author" without a postmeta
 * JOIN-and-LIKE scan.
 *
 * Why a join table (not a JSON column on the post-meta side):
 *   - `bcc_onchain_chains.id` is already an FK from
 *     `wp_bcc_onchain_collections.chain_id` (schema-collections.php:26).
 *     Reusing the FK shape keeps the chain registry as single source
 *     of truth — a chain rename / icon swap propagates without
 *     hunting through serialized post_meta blobs.
 *   - Bounded LIMITs are easy: `LIMIT 3` per post is a hard cap that
 *     the repository enforces on every read.
 *
 * Idempotency: PRIMARY KEY (post_id, chain_id) means re-tagging the
 * same chain is a no-op. Re-tagging a post with a different set is
 * implemented as DELETE-then-INSERT inside a single transaction.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1.5 (2026-05, §D6 crypto-blog composer PR-A)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_blog_chain_tags_table(): string {
    return \BCC\Core\DB\DB::table('blog_chain_tags');
}

function bcc_blog_create_chain_tags_table(): void {
    global $wpdb;

    $table   = bcc_blog_chain_tags_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        post_id BIGINT UNSIGNED NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (post_id, chain_id),
        KEY idx_chain (chain_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Extend bcc_onchain_chains with a `color` column + seed Bitcoin.
 *
 * Both operations are idempotent:
 *   - The ALTER uses INFORMATION_SCHEMA.COLUMNS to detect the column
 *     before issuing the ADD (no `ADD COLUMN IF NOT EXISTS` on MariaDB
 *     < 10.0 / MySQL < 8.0.29).
 *   - The seed uses `INSERT IGNORE` against the existing UNIQUE KEY
 *     `slug`, matching how schema-chains.php seeds the other rows.
 *
 * Bitcoin's `chain_type` is `'utxo'` — not present in the existing seed
 * (which only has 'evm' / 'cosmos' / 'thorchain' / 'polkadot' /
 * 'solana' / 'near'). The chain_type column is free-form VARCHAR(20)
 * so the new value is accepted without schema churn. Downstream
 * fetcher dispatch (ChainRefreshService) will not select Bitcoin
 * automatically — there's no UTXO fetcher in PR-A, and that's
 * intentional: the row exists to surface "Bitcoin" in the blog
 * composer's chain-tag picker, not to enable on-chain reads against
 * Bitcoin in V1.5.
 */
function bcc_blog_extend_chains_table(): void {
    global $wpdb;

    $chains_table = \BCC\Core\DB\DB::table('chains');

    // ── 1A: add `color` column (idempotent) ─────────────────────────
    //
    // INFORMATION_SCHEMA lookup is cheaper than wrapping the ALTER in
    // a try/catch — and the lookup uses a covering index on
    // (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME) so it's near-free.
    $columnExists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = %s
            AND COLUMN_NAME  = %s
          LIMIT 1",
        $chains_table,
        'color'
    ));

    if ($columnExists === 0) {
        // CHAR(7) holds '#RRGGBB'. NULL-able so existing seeded rows
        // (which have no color today) don't need a backfill on first
        // migration; admins can fill in later via an admin edit page,
        // and the hydrator returns null when unset.
        $wpdb->query("ALTER TABLE {$chains_table} ADD COLUMN color CHAR(7) NULL AFTER icon_url");
    }

    // ── 1B: seed Bitcoin (idempotent on UNIQUE KEY slug) ────────────
    //
    // Native token 'BTC', decimals 8 (satoshis). No RPC URL — Bitcoin
    // is read-side only in V1.5 (tag display); a full RPC integration
    // is deferred to the UTXO-fetcher slice. is_active=1 so the chain
    // appears in the blog composer's picker; chain_type='utxo' keeps
    // it discoverable for any future UTXO-fetcher dispatch without
    // colliding with the existing EVM/Cosmos/etc. slots.
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$chains_table}
            (slug, name, chain_type, chain_id_hex, rpc_url, rest_url,
             explorer_url, native_token, decimals, bech32_prefix, color, created_at)
         VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, NOW())",
        'bitcoin',
        'Bitcoin',
        'utxo',
        null,
        null,
        null,
        'https://mempool.space',
        'BTC',
        8,
        null,
        '#F7931A'
    ));

    // Clear chain cache so the new row appears immediately to the
    // composer's chain-tag picker.
    if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')) {
        \BCC\Trust\Onchain\Repositories\ChainRepository::clearCache();
    }
}
