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
        color CHAR(7) DEFAULT NULL,
        marketplace_template TEXT DEFAULT NULL,
        description TEXT DEFAULT NULL,
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
        // Stargaze retired 2026-06: the stargaze-1 L1 halted after the
        // Prop-1017 migration; its CW-721 collections were re-instantiated
        // on the Cosmos Hub (`cosmos` slug above). See
        // includes/database/retire-stargaze-chain.php for the data cleanup.

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
    // edit. stargaze.zone stays the `cosmos` template target: the
    // marketplace app survived the Stargaze→Hub migration and now serves
    // the Hub-instantiated collections; the contract spec's chainSlug
    // enum collapses Cosmos NFT chains under "cosmos" for V2 Phase 6.
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

    // Chain-identity content backfill (Hall chain_profile, contract §4.7):
    // seed a community-first "About this chain" description + a brand accent
    // color for each chain, so a fresh Hall opens with real identity instead
    // of a bare name. `WHERE ... IS NULL` guards operator edits made in
    // wp-admin ▸ Chains ▸ Identity — an authored value is never clobbered on
    // re-run. Icons are operator asset uploads (wp-content-hosted), not seeded
    // here. Descriptions are factual + community-oriented: no price, no
    // financial framing (owner Halls policy — "learn about the chain").
    // @var array<string, array{0: string, 1: string}> slug => [hex color, description]
    $identity_defaults = [
        'ethereum'       => ['#627EEA', "Ethereum is the original smart-contract platform — the settlement layer where much of DeFi, NFTs, and on-chain identity first took shape. If something lives on-chain, there is a good chance it started here. This Hall is home for the builders, holders, and validators who make Ethereum their base."],
        'polygon'        => ['#8247E5', "Polygon scales Ethereum with fast, low-cost transactions, making everyday on-chain activity — payments, games, NFTs — practical at scale. It has long been a landing pad for builders who want Ethereum compatibility without the fees. This Hall gathers the people building and collecting across Polygon."],
        'arbitrum'       => ['#28A0F0', "Arbitrum One is one of Ethereum's leading layer-2 rollups: it inherits Ethereum's security while settling transactions faster and cheaper. It has become a major hub for DeFi and on-chain apps that want Ethereum's trust at lower cost. This Hall is home for the Arbitrum community."],
        'optimism'       => ['#FF0420', "Optimism is an Ethereum layer-2 built around a simple idea — public goods should be funded by the people who benefit from them. Alongside fast, cheap transactions, it is known for retroactively funding the builders who improve the ecosystem. This Hall is home for the Optimism collective."],
        'base'           => ['#0052FF', "Base is an Ethereum layer-2 incubated by Coinbase, built to bring the next wave of people and apps on-chain. It pairs Ethereum-grade security with low fees and a focus on consumer-friendly, creator-driven apps. This Hall is home for the Base community."],
        'avalanche'      => ['#E84142', "Avalanche is a high-throughput smart-contract platform designed for speed and near-instant finality. Its EVM-compatible C-Chain runs Ethereum tooling out of the box, while its subnet design lets projects launch custom chains of their own. This Hall gathers the Avalanche community."],
        'bsc'            => ['#F3BA2F', "BNB Smart Chain is a fast, low-fee EVM chain at the center of the BNB ecosystem — one of the most widely used networks for everyday trading, DeFi, and gaming. Low costs and deep liquidity have made it a common on-ramp for newcomers. This Hall is home for the BNB Chain community."],
        'cosmos'         => ['#6F7390', "The Cosmos Hub is the heart of the Internet of Blockchains — the original chain behind the Cosmos SDK and IBC, the standard that lets sovereign chains talk to one another. ATOM secures the Hub and anchors a whole ecosystem of interconnected app-chains. This Hall is for the people who build and stake across Cosmos."],
        'osmosis'        => ['#750BBB', "Osmosis is the largest decentralized exchange in the Cosmos ecosystem — the trading crossroads where IBC-connected chains meet. Beyond swaps, it is a full DeFi hub with liquidity, staking, and governance run by its community. This Hall gathers the Osmosis community."],
        'akash'          => ['#FF414C', "Akash is a decentralized cloud — an open marketplace where anyone can rent computing power, including GPUs for AI, at a fraction of traditional cloud cost. Instead of renting from one big provider, you rent from a global network of independent operators. This Hall is for the Akash community."],
        'juno'           => ['#F0827D', "Juno is a Cosmos smart-contract platform built for CosmWasm — an open, interoperable home for developers deploying decentralized apps across the interchain. It is a community-governed chain that grew out of the Cosmos ecosystem itself. This Hall gathers Juno's builders and community."],
        'solana'         => ['#9945FF', "Solana is a high-performance blockchain built for speed — fast, low-cost transactions that have made it a home for consumer apps, NFTs, DePIN, and high-frequency DeFi. Its single global state makes on-chain activity feel instant. This Hall is home for the Solana community."],
        'near'           => ['#00EC97', "NEAR is a layer-1 designed to be simple to use — human-readable account names, low fees, and a focus on onboarding people who are not already crypto-native. It has leaned increasingly into AI and user-owned data. This Hall is for the NEAR community."],
        'injective'      => ['#0082FA', "Injective is a Cosmos layer-1 built specifically for finance — an interoperable chain with native on-chain orderbooks and infrastructure tuned for trading, derivatives, and real-world assets. It is fast, IBC-connected, and finance-first by design. This Hall gathers the Injective community."],
        'cryptoorgchain' => ['#002D74', "Cronos POS, the Crypto.org Chain, is a Cosmos-based network focused on payments and everyday use, part of the wider Crypto.com ecosystem. CRO is its native token, used to stake and help secure the chain. This Hall is for the Cronos POS community."],
        'jackal'         => ['#5D3FD3', "Jackal is a decentralized storage network on Cosmos — a private, self-custodial alternative to big-tech cloud storage. Files are encrypted and spread across independent storage providers, so only you hold the keys to your own data. This Hall is for the Jackal community."],
        'kujira'         => ['#E63946', "Kujira is a sovereign Cosmos layer-1 built for sustainable DeFi — a semi-permissioned chain where new apps are voted in through governance to keep quality high. It grew a tight-knit community around practical tools for everyday DeFi users. This Hall is for the Kujira community."],
        'thorchain'      => ['#23DCC8', "THORChain is a decentralized network for swapping native assets across chains — trade real BTC for real ETH with no wrapped tokens and no central custodian. RUNE is the settlement asset that ties the whole network together. This Hall is home for the THORChain community."],
        'polkadot'       => ['#E6007A', "Polkadot is a network of specialized blockchains — parachains that share security and interoperate, a layer-0 designed for many chains to work as one. DOT secures the relay chain and drives its on-chain governance. This Hall gathers the Polkadot community."],
        'dungeon'        => ['#C9A227', "Dungeon Chain is a Cosmos chain built for gaming — home to an on-chain idle RPG, a DEX, and games settled natively in DGN, with its own Bitcoin bridge. It is a purpose-built playground for on-chain gamers. This Hall is for the Dungeon Chain community."],
        'bitcoin'        => ['#F7931A', "Bitcoin is the original blockchain — the first and most secure decentralized money, and the reference point every other chain is measured against. It is less about apps and more about sound, censorship-resistant value anyone can hold. This Hall is home for the Bitcoin community."],
    ];
    foreach ($identity_defaults as $identitySlug => $identity) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET description = %s WHERE slug = %s AND description IS NULL",
            $identity[1],
            $identitySlug
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET color = %s WHERE slug = %s AND color IS NULL",
            $identity[0],
            $identitySlug
        ));
    }

    // Clear chain cache so newly seeded chains appear immediately.
    if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')) {
        \BCC\Trust\Onchain\Repositories\ChainRepository::clearCache();
    }
}

/**
 * Add the `description` column to bcc_onchain_chains (idempotent).
 *
 * The chains registry gained a per-chain "About this chain" description so
 * a Hall detail view can surface chain-identity context — see the
 * `chain_profile` block on HallsService::getHall (contract §4.7). Fresh
 * installs get the column from the CREATE TABLE above; existing DBs get it
 * here.
 *
 * Mirrors the idempotent ALTER pattern schema-blog-chain-tags.php uses for
 * the `color` column: an INFORMATION_SCHEMA existence probe gates the ADD
 * because `ADD COLUMN IF NOT EXISTS` is unavailable on MariaDB < 10.0 /
 * MySQL < 8.0.29. TEXT NULL, so existing seeded rows need no backfill — the
 * projection returns null when unset and operators author copy via the
 * wp-admin Chains ▸ Identity editor. Never touches existing row values.
 */
function bcc_onchain_add_chains_description_column(): void {
    global $wpdb;

    $chains_table = \BCC\Core\DB\DB::table('chains');

    $columnExists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = %s
            AND COLUMN_NAME  = %s
          LIMIT 1",
        $chains_table,
        'description'
    ));

    if ($columnExists === 0) {
        $wpdb->query("ALTER TABLE {$chains_table} ADD COLUMN description TEXT NULL AFTER marketplace_template");
    }

    // Clear the chains cache so the new column is visible to the Hall
    // chain_profile projection immediately.
    if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')) {
        \BCC\Trust\Onchain\Repositories\ChainRepository::clearCache();
    }
}

