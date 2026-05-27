<?php
/**
 * Chain Registry Schema
 *
 * Normalized chain lookup table — every on-chain table references chain_id
 * instead of storing chain strings. Provides RPC, explorer, and type metadata.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_chains_table(): string {
    return \BCC\Core\DB\DB::table('chains');
}

/**
 * Create the chains table and seed default chains.
 */
function bcc_onchain_create_chains_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_chains_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        chain_type VARCHAR(20) NOT NULL,
        chain_id_hex VARCHAR(20) DEFAULT NULL,
        rpc_url VARCHAR(500) DEFAULT NULL,
        rest_url VARCHAR(500) DEFAULT NULL,
        explorer_url VARCHAR(500) DEFAULT NULL,
        native_token VARCHAR(20) DEFAULT NULL,
        decimals TINYINT UNSIGNED NOT NULL DEFAULT 6,
        bech32_prefix VARCHAR(20) DEFAULT NULL,
        icon_url VARCHAR(500) DEFAULT NULL,
        marketplace_template TEXT DEFAULT NULL,
        is_testnet TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY chain_type (chain_type),
        KEY is_active (is_active)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Seed default chains (idempotent via INSERT IGNORE on unique slug)
    $defaults = [
        // EVM chains
        [
            'slug'         => 'ethereum',
            'name'         => 'Ethereum',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x1',
            'rpc_url'      => 'https://eth-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://etherscan.io',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'polygon',
            'name'         => 'Polygon',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x89',
            'rpc_url'      => 'https://polygon-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://polygonscan.com',
            'native_token' => 'MATIC',
        ],
        [
            'slug'         => 'arbitrum',
            'name'         => 'Arbitrum One',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0xa4b1',
            'rpc_url'      => 'https://arb-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://arbiscan.io',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'optimism',
            'name'         => 'Optimism',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0xa',
            'rpc_url'      => 'https://opt-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://optimistic.etherscan.io',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'base',
            'name'         => 'Base',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x2105',
            'rpc_url'      => 'https://base-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://basescan.org',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'avalanche',
            'name'         => 'Avalanche C-Chain',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0xa86a',
            'rpc_url'      => 'https://api.avax.network/ext/bc/C/rpc',
            'explorer_url' => 'https://snowtrace.io',
            'native_token' => 'AVAX',
        ],
        [
            'slug'         => 'bsc',
            'name'         => 'BNB Smart Chain',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x38',
            'rpc_url'      => 'https://bsc-dataseed.binance.org',
            'explorer_url' => 'https://bscscan.com',
            'native_token' => 'BNB',
        ],

        // Cosmos chains (decimals + bech32_prefix for multi-chain support)
        [
            'slug'           => 'cosmos',
            'name'           => 'Cosmos Hub',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/cosmoshub',
            'rpc_url'        => 'https://rpc.cosmos.directory/cosmoshub',
            'explorer_url'   => 'https://www.mintscan.io/cosmos',
            'native_token'   => 'ATOM',
            'decimals'       => 6,
            'bech32_prefix'  => 'cosmos',
        ],
        [
            'slug'           => 'osmosis',
            'name'           => 'Osmosis',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/osmosis',
            'rpc_url'        => 'https://rpc.cosmos.directory/osmosis',
            'explorer_url'   => 'https://www.mintscan.io/osmosis',
            'native_token'   => 'OSMO',
            'decimals'       => 6,
            'bech32_prefix'  => 'osmo',
        ],
        [
            'slug'           => 'akash',
            'name'           => 'Akash',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/akash',
            'rpc_url'        => 'https://rpc.cosmos.directory/akash',
            'explorer_url'   => 'https://www.mintscan.io/akash',
            'native_token'   => 'AKT',
            'decimals'       => 6,
            'bech32_prefix'  => 'akash',
        ],
        [
            'slug'           => 'juno',
            'name'           => 'Juno',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/juno',
            'rpc_url'        => 'https://rpc.cosmos.directory/juno',
            'explorer_url'   => 'https://www.mintscan.io/juno',
            'native_token'   => 'JUNO',
            'decimals'       => 6,
            'bech32_prefix'  => 'juno',
        ],
        [
            'slug'           => 'stargaze',
            'name'           => 'Stargaze',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/stargaze',
            'rpc_url'        => 'https://rpc.cosmos.directory/stargaze',
            'explorer_url'   => 'https://www.mintscan.io/stargaze',
            'native_token'   => 'STARS',
            'decimals'       => 6,
            'bech32_prefix'  => 'stars',
        ],

        [
            'slug'           => 'injective',
            'name'           => 'Injective',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/injective',
            'rpc_url'        => 'https://rpc.cosmos.directory/injective',
            'explorer_url'   => 'https://www.mintscan.io/injective',
            'native_token'   => 'INJ',
            'decimals'       => 18,
            'bech32_prefix'  => 'inj',
        ],
        [
            'slug'           => 'cryptoorgchain',
            'name'           => 'Cronos POS',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/cryptoorgchain',
            'rpc_url'        => 'https://rpc.cosmos.directory/cryptoorgchain',
            'explorer_url'   => 'https://www.mintscan.io/crypto-org',
            'native_token'   => 'CRO',
            'decimals'       => 8,
            'bech32_prefix'  => 'cro',
        ],
        [
            'slug'           => 'jackal',
            'name'           => 'Jackal',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/jackal',
            'rpc_url'        => 'https://rpc.cosmos.directory/jackal',
            'explorer_url'   => 'https://ping.pub/jackal',
            'native_token'   => 'JKL',
            'decimals'       => 6,
            'bech32_prefix'  => 'jkl',
        ],
        [
            'slug'           => 'kujira',
            'name'           => 'Kujira',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://rest.cosmos.directory/kujira',
            'rpc_url'        => 'https://rpc.cosmos.directory/kujira',
            'explorer_url'   => 'https://www.mintscan.io/kujira',
            'native_token'   => 'KUJI',
            'decimals'       => 6,
            'bech32_prefix'  => 'kujira',
        ],

        [
            'slug'           => 'dungeon',
            'name'           => 'Dungeon Chain',
            'chain_type'     => 'cosmos',
            'rest_url'       => 'https://api.dungeongames.io',
            'rpc_url'        => 'https://rpc.dungeongames.io',
            'explorer_url'   => 'https://ping.pub/Dungeonchain',
            'native_token'   => 'DGN',
            'decimals'       => 6,
            'bech32_prefix'  => 'dungeon',
        ],

        // THORChain (custom API — uses ThorchainFetcher, not CosmosFetcher).
        // Endpoint history: thornode.ninerealms.com was the historical default
        // until ~2025 when NineRealms retired the public thornode subdomain.
        // Liquify (community-run) is the current canonical replacement; same
        // /thorchain/nodes shape as the original.
        [
            'slug'           => 'thorchain',
            'name'           => 'THORChain',
            'chain_type'     => 'thorchain',
            'rest_url'       => 'https://thornode.thorchain.liquify.com',
            'explorer_url'   => 'https://runescan.io',
            'native_token'   => 'RUNE',
            'decimals'       => 8,
            'bech32_prefix'  => 'thor',
        ],

        // Polkadot (Subscan API — requires BCC_SUBSCAN_API_KEY in wp-config.php)
        [
            'slug'           => 'polkadot',
            'name'           => 'Polkadot',
            'chain_type'     => 'polkadot',
            'rest_url'       => 'https://polkadot.api.subscan.io',
            'explorer_url'   => 'https://polkadot.subscan.io',
            'native_token'   => 'DOT',
            'decimals'       => 10,
        ],

        // Solana
        [
            'slug'         => 'solana',
            'name'         => 'Solana',
            'chain_type'   => 'solana',
            'rpc_url'      => 'https://api.mainnet-beta.solana.com',
            'explorer_url' => 'https://solscan.io',
            'native_token' => 'SOL',
        ],

        // NEAR
        [
            'slug'         => 'near',
            'name'         => 'NEAR Protocol',
            'chain_type'   => 'near',
            'rpc_url'      => 'https://rpc.mainnet.near.org',
            'explorer_url' => 'https://nearblocks.io',
            'native_token' => 'NEAR',
        ],
    ];

    foreach ($defaults as $chain) {
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
                (slug, name, chain_type, chain_id_hex, rpc_url, rest_url, explorer_url, native_token, decimals, bech32_prefix, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d, %s, NOW())",
            $chain['slug'],
            $chain['name'],
            $chain['chain_type'],
            $chain['chain_id_hex'] ?? null,
            $chain['rpc_url'] ?? null,
            $chain['rest_url'] ?? null,
            $chain['explorer_url'] ?? null,
            $chain['native_token'] ?? null,
            $chain['decimals'] ?? 6,
            $chain['bech32_prefix'] ?? null
        ));
    }

    // V2 Phase 6 (§H1): back-fill marketplace_template for the three
    // chains the §4.17 NFT piece endpoint serves. Uses
    // `WHERE marketplace_template IS NULL` so an admin-edited override
    // is never clobbered on re-run. New chains landing later can carry
    // their own template via a future seed extension or per-row admin
    // edit. Stargaze takes the `cosmos` slot since the contract spec's
    // chainSlug enum collapses Cosmos NFT chains under "cosmos" for
    // V2 Phase 6.
    $marketplace_defaults = [
        'ethereum' => 'https://opensea.io/assets/ethereum/{contract}/{token_id}',
        'solana'   => 'https://magiceden.io/item-details/{contract}',
        'cosmos'   => 'https://www.stargaze.zone/m/{contract}/{token_id}',
    ];
    foreach ($marketplace_defaults as $slug => $template) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET marketplace_template = %s
              WHERE slug = %s
                AND marketplace_template IS NULL",
            $template,
            $slug
        ));
    }

    // Clear chain cache so newly seeded chains appear immediately.
    if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')) {
        \BCC\Trust\Onchain\Repositories\ChainRepository::clearCache();
    }
}

