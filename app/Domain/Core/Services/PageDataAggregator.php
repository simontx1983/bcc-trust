<?php
/**
 * Page Data Aggregator
 *
 * Collects all trust-related data for a page in one pass.
 * Shared dependencies (user data, wallet connections, owner info)
 * are loaded once and reused across sections.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\WalletSignalRepository;
use Exception;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type UserInfoRow from UserInfoRepository
 */
class PageDataAggregator {

    /** @var ScoreRepository */
    private $scores;

    /** @var UserInfoRepository */
    private $users;

    public function __construct(
        ScoreRepository $scores,
        UserInfoRepository $users
    ) {
        $this->scores  = $scores;
        $this->users   = $users;
    }

    /**
     * Build the full page data payload.
     *
     * Read model (bcc_page_read_model) is the PRIMARY source of truth for
     * trust, verification, and social-profile data. Individual table queries
     * are only used as a fallback for pages that haven't been synced yet.
     *
     * @param WP_Post      $post
     * @param int          $viewer_id Current user ID (0 if logged out).
     * @param list<string> $sections  Optional section filter; empty = all.
     * @return array<string, mixed>
     */
    public function build(WP_Post $post, int $viewer_id = 0, array $sections = []) {
        $post_id = $post->ID;

        // --- Read model is primary source ---
        $rm = Plugin::instance()->pageReadModelRepository()->getByPageId($post_id);

        // --- Resolve page owner once ---
        $owner_id = $rm ? (int) $rm->owner_id : (int) PeepSoPageResolver::getOwnerId($post_id);

        // If read model exists, use fast path for most sections.
        // Only load individual tables as fallback when read model is empty.
        $has_rm = ($rm !== null);

        // If no read model row exists, try to create the score + read model
        // on-the-fly. This catches pages created before the eager-sync fix,
        // pages imported via WP admin, or any path that skipped the PeepSo
        // page creation hooks. The cost is one INSERT + one syncPage() call
        // per page, amortized to zero after the first load.
        if (!$has_rm && $post->post_type === 'peepso-page' && $post->post_status === 'publish') {
            try {
                $this->scores->createIfNotExists($post_id, $owner_id ?: (int) $post->post_author);
                Plugin::instance()->pageReadModelRepository()->syncPage($post_id);
                $rm     = Plugin::instance()->pageReadModelRepository()->getByPageId($post_id);
                $has_rm = ($rm !== null);
                if ($rm) {
                    $owner_id = (int) $rm->owner_id;
                }
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('read_model_create_failed', [
                    'post_id'   => $post_id,
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]);
                // Non-fatal — fall through to source-table path
            }
        }

        // Top-level degradation flag. True when any section of this payload
        // was not served from the authoritative read model. Consumers MUST
        // key UI decisions (badges, gating, "data may be stale" banners)
        // on this flag rather than on the numeric shape of individual
        // fields — a synthetic neutral baseline (score=50, tier='neutral')
        // looks like real data to a naïve `if (score > 0)` check.
        $system_degraded = !$has_rm;

        if (!$has_rm) {
            self::trackFallback($post_id);
        }

        // --- Shared lookups (lazy — only resolved when needed) ---
        $owner_wp   = $owner_id ? get_userdata($owner_id) : false;
        $wallets    = $this->loadWalletConnections($owner_id);

        // When read model exists, derive verification/profile from it directly
        // instead of querying VerificationRepository + UserInfoRepository.
        if ($has_rm) {
            $owner_info = null; // Not needed — read model has is_verified
            $gh         = [
                'connected'   => !empty($rm->github_username),
                'username'    => $rm->github_username,
                'followers'   => (int) ($rm->github_followers ?? 0),
                'public_repos' => 0,
                'org_count'   => 0,
                'trust_boost' => 0.0,
                'fraud_reduction' => 0,
            ];
            $x_data     = [
                'connected' => !empty($rm->x_username),
                'username'  => $rm->x_username,
            ];
            $identities = $this->deriveIdentitiesFromReadModel($rm, $wallets);
        } else {
            $owner_info = $this->loadOwnerInfo($owner_id);
            $gh         = function_exists('bcc_trust_get_github_data')
                ? \BCC\Trust\Core\Plugin::instance()->verificationRepository()->getGithubData($owner_id)
                : $this->emptyGithub();
            $x_data     = function_exists('bcc_trust_get_x_data')
                ? \BCC\Trust\Core\Plugin::instance()->verificationRepository()->getXData($owner_id)
                : ['connected' => false];
            $identities = function_exists('bcc_trust_get_verified_identity_count')
                ? \BCC\Trust\Core\Plugin::instance()->verificationRepository()->getVerifiedIdentityCount($owner_id)
                : ['count' => 0, 'types' => []];
        }

        // Section filter: empty array means all sections.
        $all = empty($sections);

        $result = [];

        if ($all || in_array('project', $sections, true)) {
            $result['project'] = $this->pageSection($post);
        }
        if ($all || in_array('builder', $sections, true)) {
            $result['builder'] = $this->builderSection($owner_id, $owner_wp);
        }
        if ($all || in_array('trust', $sections, true)) {
            $result['trust'] = $has_rm
                ? $this->trustSectionFromReadModel($rm)
                : $this->trustSectionFromSourceTables($post_id);
            // Tag the payload so upstream consumers (blocks, REST callers,
            // admin dashboards) can distinguish "authoritative read-model
            // data" from "source-table fallback" — earlier the two were
            // indistinguishable, so a synthesized legacy-shape payload
            // could be served from cache for up to 5 minutes with no
            // visible degradation indicator.
            $result['trust']['source'] = $has_rm ? 'read_model' : 'source_fallback';
        }
        if ($all || in_array('verification', $sections, true)) {
            $result['verification'] = $has_rm
                ? $this->verificationSectionFromReadModel($rm)
                : $this->verificationSection($owner_info, $gh, $wallets, $x_data);
        }
        if ($all || in_array('profiles', $sections, true)) {
            $result['profiles'] = $this->profilesSection($gh, $x_data);
        }
        if ($all || in_array('onchain', $sections, true)) {
            $result['onchain'] = $this->onchainSection($wallets, $post_id);
        }
        if ($all || in_array('wallets', $sections, true)) {
            $result['wallets'] = $this->walletsSection($wallets);
        }
        // wallet_detail included in the default payload — the data is already
        // loaded by loadWalletConnections() above, so building the detail
        // section is pure array mapping (no extra DB query). Empty array
        // when no wallets connected, bounded to 3 chains max otherwise.
        if ($all || in_array('wallet_detail', $sections, true)) {
            $result['wallet_detail'] = $this->walletDetailSection($wallets);
        }
        if ($all || in_array('reputation', $sections, true)) {
            $result['reputation'] = $this->reputationSection($post, $owner_id, $has_rm ? null : $owner_info, $identities);
        }
        if ($all || in_array('categories', $sections, true)) {
            $result['categories'] = $this->categoriesSection($post_id);
        }
        if ($all || in_array('flags', $sections, true)) {
            $result['flags'] = ['count' => FlagService::getFlagCount($post_id)];
        }
        if ($all || in_array('viewer', $sections, true)) {
            $result['viewer'] = $this->buildViewerSection($viewer_id, $post_id, $owner_id);
            if (!empty($result['viewer']['viewer_data_degraded'])) {
                $system_degraded = true;
            }
        }

        $result['system_degraded'] = $system_degraded;

        return $result;
    }

    /* =================================================================
       Section builders
    ================================================================= */

    /**
     * @return array<string, mixed>
     */
    private function pageSection(WP_Post $post) {
        $avatar = '';
        $cover  = '';

        if (class_exists('PeepSoPagePhoto')) {
            $photo = \PeepSoPagePhoto::get_instance();
            if ($photo) {
                $avatar = $photo->get_page_avatar_url($post->ID) ?: '';
                $cover  = $photo->get_page_cover_url($post->ID) ?: '';
            }
        }

        if (!$avatar) {
            $avatar = get_the_post_thumbnail_url($post->ID, 'thumbnail') ?: '';
        }

        return [
            'id'     => (int) $post->ID,
            'name'   => sanitize_text_field($post->post_title),
            'slug'   => sanitize_title($post->post_name),
            'avatar' => esc_url_raw($avatar),
            'cover'  => esc_url_raw($cover),
            'url'    => esc_url_raw(get_permalink($post->ID) ?: ''),
        ];
    }

    /**
     * @param int            $owner_id
     * @param \WP_User|false $owner_wp
     * @return array<string, mixed>|null
     */
    private function builderSection(int $owner_id, $owner_wp) {
        if (!$owner_id || !$owner_wp) {
            return null;
        }

        // Public-safe username: nicename (URL slug), never user_login
        $username = $owner_wp->user_nicename;

        // PeepSo profile URL
        $profile_url = '';
        if (class_exists('PeepSoUser')) {
            $psu = \PeepSoUser::get_instance($owner_id);
            $profile_url = $psu->get_profileurl();
        }
        if (!$profile_url) {
            $profile_url = get_author_posts_url($owner_id);
        }

        return [
            'name'        => sanitize_text_field($owner_wp->display_name),
            'username'    => sanitize_text_field($username),
            'avatar'      => esc_url_raw(get_avatar_url($owner_id, ['size' => 96]) ?: ''),
            'profile_url' => esc_url_raw($profile_url),
        ];
    }

    /**
     * Build trust section from the read model (PRIMARY path — zero additional queries).
     *
     * @param object $rm Read model row from PageReadModelRepository.
     *
     * @phpstan-param object{
     *   trust_score: float|numeric-string,
     *   reputation_tier?: string,
     *   confidence_score?: float|numeric-string,
     *   positive_score?: float|numeric-string,
     *   negative_score?: float|numeric-string,
     *   onchain_bonus?: float|numeric-string,
     *   unique_voters?: int|numeric-string,
     *   endorsement_count?: int|numeric-string,
     *   last_vote_at?: string|null
     * } $rm
     *
     * @return array<string, mixed>
     */
    private function trustSectionFromReadModel(object $rm): array {
        $positive = (float) ($rm->positive_score ?? 0);
        $negative = (float) ($rm->negative_score ?? 0);
        $net      = round($positive - $negative, 2);
        $onchain  = (float) ($rm->onchain_bonus ?? 0);

        $total             = (float) $rm->trust_score;

        return [
            'score'         => (int) round($total),
            'tier'          => sanitize_key($rm->reputation_tier ?? 'neutral'),
            'confidence'    => round((float) ($rm->confidence_score ?? 0), 2),
            'votes_up'      => (int) max(0, round($positive)),
            'votes_down'    => (int) max(0, round(abs($negative))),
            'unique_voters' => (int) ($rm->unique_voters ?? 0),
            'endorsements'  => (int) ($rm->endorsement_count ?? 0),
            'last_vote_at'  => $rm->last_vote_at ?? null,
            'score_breakdown' => array_merge([
                'positive'          => round($positive, 2),
                'negative'          => round($negative, 2),
                'net'               => $net,
                'onchain_bonus'     => round($onchain, 2),
            ], $this->computeWeights($positive, $onchain)),
        ];
    }

    /**
     * Build trust section from source tables (FALLBACK — only for unsync'd pages).
     *
     * @param int $pageId
     * @return array<string, mixed>
     */
    private function trustSectionFromSourceTables(int $pageId): array {
        $score = $this->loadScore($pageId);

        if (!$score) {
            // No score row — return neutral baseline so the trust gauge shows
            // 50/neutral instead of empty/"unavailable". New pages start at
            // the neutral score; the gauge should reflect that, not look broken.
            return [
                'score'         => (int) BCC_TRUST_NEUTRAL_SCORE,
                'tier'          => 'neutral',
                'confidence'    => 0.0,
                'votes_up'      => 0,
                'votes_down'    => 0,
                'unique_voters' => 0,
                'endorsements'  => 0,
                'last_vote_at'  => null,
                'score_breakdown' => [
                    'positive'          => 0.0,
                    'negative'          => 0.0,
                    'net'               => 0.0,
                    'onchain_bonus'     => 0.0,
                    'community_weight'  => 0.5,
                    'identity_weight'   => 0.5,
                ],
            ];
        }

        // Fallback path: query vote counts from source table.
        $voteCounts   = Plugin::instance()->voteRepository()->getVoteCountsByType($pageId);
        $last_vote_at = $score->getLastVoteAt();

        return [
            'score'         => (int) round($score->getTotalScore()),
            'tier'          => sanitize_key($score->getReputationTier()),
            'confidence'    => round((float) $score->getConfidenceScore(), 2),
            'votes_up'      => (int) ($voteCounts['upvotes'] ?? 0),
            'votes_down'    => (int) ($voteCounts['downvotes'] ?? 0),
            'unique_voters' => (int) $score->getUniqueVoters(),
            'endorsements'  => (int) $score->getEndorsementCount(),
            'last_vote_at'  => $last_vote_at ? $last_vote_at->format('Y-m-d H:i:s') : null,
            'score_breakdown' => array_merge([
                'positive'          => round($score->getPositiveScore(), 2),
                'negative'          => round($score->getNegativeScore(), 2),
                'net'               => round($score->getNetScore(), 2),
                'onchain_bonus'     => round($score->getOnchainBonus(), 2),
            ], $this->computeWeights(
                $score->getPositiveScore(),
                $score->getOnchainBonus()
            )),
        ];
    }

    /**
     * Derive verification section from read model (zero additional queries).
     *
     * @param object $rm Read model row.
     * @return array<string, bool>
     */
    private function verificationSectionFromReadModel(object $rm): array {
        return [
            'email'  => (bool) ($rm->is_verified ?? false),
            'wallet' => (bool) ($rm->has_wallet ?? false),
            'github' => !empty($rm->github_username),
            'x'      => !empty($rm->x_username),
        ];
    }

    /**
     * Derive identity counts from read model fields (no extra queries).
     *
     * @param object               $rm
     * @param array<string, mixed> $wallets
     * @return array{count: int, types: string[]}
     */
    private function deriveIdentitiesFromReadModel(object $rm, array $wallets): array {
        $types = [];

        if (!empty($rm->github_username)) {
            $types[] = 'github';
        }
        if (!empty($rm->x_username)) {
            $types[] = 'x';
        }
        foreach (array_keys($wallets) as $chain) {
            $types[] = 'wallet_' . $chain;
        }

        return ['count' => count($types), 'types' => $types];
    }

    /**
     * Per-chain wallet detail for blocks that need address/role/NFT data.
     *
     * @param array<string, mixed> $wallets Wallet connections keyed by chain.
     * @return array<string, mixed>
     */
    private function walletDetailSection(array $wallets): array {
        $detail = [];

        foreach ($wallets as $chain => $connection) {
            $conn = is_array($connection) ? $connection : (array) $connection;
            $detail[$chain] = [
                'wallet_address' => $conn['wallet_address'] ?? '',
                'wallet_role'    => $conn['wallet_role'] ?? 'none',
                'trust_boost'    => (float) ($conn['trust_boost'] ?? 0),
                'nft_collections' => $conn['nft_collections'] ?? [],
            ];
        }

        return $detail;
    }

    /**
     * @param object|null $owner_info  Row from UserInfoRepository
     * @param array<string, mixed>  $gh          GitHub data array
     * @param array<string, mixed>  $wallets     Wallet connections keyed by chain
     * @param array<string, mixed>  $x_data      X (Twitter) data array
     * @return array<string, bool>
     *
     * @phpstan-param object{is_verified: int|numeric-string}|null $owner_info
     */
    private function verificationSection($owner_info, array $gh, array $wallets, array $x_data) {
        return [
            'email'  => $owner_info ? (bool) $owner_info->is_verified : false,
            'wallet' => !empty($wallets),
            'github' => (bool) $gh['connected'],
            'x'      => (bool) ($x_data['connected'] ?? false),
        ];
    }

    /**
     * Verified social profile data for tooltip display.
     *
     * Only includes profile details when the provider is connected.
     * Null per-provider when not verified — consumers show the unverified state.
     *
     * @param array<string, mixed> $gh      GitHub data from VerificationRepository
     * @param array<string, mixed> $x_data  X data from VerificationRepository
     * @return array<string, mixed>
     */
    private function profilesSection(array $gh, array $x_data) {
        $github = null;
        if (!empty($gh['connected']) && !empty($gh['username'])) {
            $github = [
                'username'  => sanitize_text_field($gh['username']),
                'followers' => (int) ($gh['followers'] ?? 0),
                'repos'     => (int) ($gh['public_repos'] ?? 0),
            ];
        }

        $x = null;
        if (!empty($x_data['connected']) && !empty($x_data['username'])) {
            $x = [
                'username' => sanitize_text_field($x_data['username']),
            ];
        }

        return [
            'github' => $github,
            'x'      => $x,
        ];
    }

    /**
     * On-chain metrics.
     *
     * Pulls wallet_age / transactions / contracts from bcc-trust's Onchain
     * indexer (SignalRepository::get_for_page) when available. Falls back to
     * null when the indexer hasn't run or bcc-trust is inactive.
     *
     * total_boost and nft_collections are derived from wallet connection metadata.
     *
     * @param array<string, mixed> $wallets Wallet connections keyed by chain
     * @param int                  $postId
     * @return array<string, mixed>
     */
    private function onchainSection(array $wallets, int $postId = 0) {
        $total_boost      = 0.0;
        $nft_collections  = [];

        foreach ($wallets as $chain => $connection) {
            if (!is_array($connection) && !is_object($connection)) {
                continue;
            }
            $conn = (array) $connection;

            if (isset($conn['trust_boost'])) {
                $total_boost += (float) $conn['trust_boost'];
            }

            if (!empty($conn['nft_collections']) && is_array($conn['nft_collections'])) {
                foreach ($conn['nft_collections'] as $nft) {
                    $nft_collections[] = array_merge(
                        ['chain' => $chain],
                        is_array($nft) ? $nft : (array) $nft
                    );
                }
            }
        }

        // ── Chain indexer data (wallet age, transactions, contracts) ──
        // Aggregated across all wallets for this page. The indexer runs
        // daily and stores per-wallet data in bcc_onchain_signals.
        $wallet_age   = null;
        $transactions = null;
        $contracts    = null;

        if ($postId > 0) {
            // Fail-loud: Onchain is a hard in-plugin dependency. A missing class here is a
            // deployment bug, not a fallback case — class_exists guards would silently blank
            // out signal data in production.
            if (!class_exists(\BCC\Trust\Onchain\Repositories\SignalRepository::class)) {
                throw new \RuntimeException('Onchain domain classes not autoloaded');
            }
            try {
                $signals = \BCC\Trust\Onchain\Repositories\SignalRepository::get_for_page($postId);
                if (!empty($signals)) {
                    $max_age_days  = 0;
                    $total_tx      = 0;
                    $total_contracts = 0;
                    $has_data      = false;

                    foreach ($signals as $signal) {
                        $sig = is_array($signal) ? $signal : (array) $signal;
                        $age = (int) ($sig['wallet_age_days'] ?? 0);
                        $tx  = (int) ($sig['tx_count'] ?? 0);
                        $con = (int) ($sig['contract_count'] ?? 0);

                        if ($age > 0 || $tx > 0 || $con > 0) {
                            $has_data = true;
                        }
                        if ($age > $max_age_days) {
                            $max_age_days = $age;
                        }
                        $total_tx       += $tx;
                        $total_contracts += $con;
                    }

                    if ($has_data) {
                        $wallet_age   = round($max_age_days / 365.25, 1);
                        $transactions = $total_tx;
                        $contracts    = $total_contracts;
                    }
                }
            } catch (Exception $e) {
                \BCC\Core\Log\Logger::warning('onchain_indexer_unavailable', [
                    'post_id'   => $postId,
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]);
                // Indexer unavailable — fields stay null.
            }
        }

        // Enrich with on-chain collection data from the Onchain domain.
        // Build new stdClass objects rather than mutating the repo's return
        // value in place — the repo response is cached, and mutating it would
        // poison subsequent cache hits from this request.
        $collections = [];
        try {
            $result = \BCC\Trust\Onchain\Services\CollectionService::getAllForProject($postId);
            foreach ($result['items'] as $c) {
                $data               = (array) $c;
                $data['is_creator'] = true;
                $collections[]      = (object) $data;
            }
        } catch (Exception $e) {
            \BCC\Core\Log\Logger::warning('collection_enrichment_failed', [
                'post_id'   => $postId,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            $collections = [];
        }

        return [
            'wallet_age'      => $wallet_age,
            'transactions'    => $transactions,
            'contracts'       => $contracts,
            'total_boost'     => round($total_boost, 2),
            'nft_collections' => $nft_collections,
            'collections'     => $collections,
        ];
    }

    /**
     * @param array<string, mixed> $connections Wallet connections keyed by chain
     * @return array<string, bool>
     */
    private function walletsSection(array $connections) {
        return [
            'ethereum' => isset($connections['ethereum']),
            'solana'   => isset($connections['solana']),
            'cosmos'   => isset($connections['cosmos']),
        ];
    }

    /**
     * @param WP_Post     $post
     * @param int         $owner_id
     * @param object|null $owner_info
     * @param array{count: int, types: string[]}  $identities
     * @return array<string, mixed>
     */
    private function reputationSection(WP_Post $post, int $owner_id, $owner_info, array $identities) {
        // Account age + raw registration date
        $account_age        = null;
        $account_registered = null;
        if ($owner_info && !empty($owner_info->registered)) {
            $registered = date_create($owner_info->registered);
            if ($registered) {
                $account_registered = $registered->format('c'); // ISO 8601
                $diff               = date_diff($registered, date_create('now'));
                $account_age        = round($diff->y + $diff->m / 12, 1);
            }
        }

        // Project age + raw creation date
        $project_age     = null;
        $project_created = null;
        if ($post->post_date) {
            $created = date_create($post->post_date);
            if ($created) {
                $project_created = $created->format('c'); // ISO 8601
                $diff            = date_diff($created, date_create('now'));
                $project_age     = round($diff->y + $diff->m / 12, 1);
            }
        }

        // Followers
        $followers = 0;
        if (function_exists('peepso') && $owner_id) {
            $followers = (int) get_user_meta($owner_id, 'peepso_followers_count', true);
        }

        return [
            'account_age'        => $account_age,
            'account_registered' => $account_registered,
            'project_age'        => $project_age,
            'project_created'    => $project_created,
            'followers'          => $followers,
            'identity_count'     => $identities['count'],
            'identity_types'     => $identities['types'],
        ];
    }

    /**
     * Viewer-specific context (only populated when logged in).
     *
     * Public so the endpoint can call it separately from the cached payload.
     *
     * @param int $viewer_id
     * @param int $post_id
     * @param int $owner_id
     * @return array<string, mixed>|null
     */
    public function buildViewerSection(int $viewer_id, int $post_id, int $owner_id) {
        if (!$viewer_id) {
            return null;
        }

        $vote_type    = 0;
        $has_endorsed = false;
        // True if ANY viewer-specific lookup failed and its field is now
        // a fallback default rather than the authoritative user state.
        // The frontend MUST disable vote / endorse / wallet-dependent
        // controls when this is true — defaulting vote_type to 0 and
        // wallet_connected to false silently reverts authenticated
        // actions and would allow double-votes if the user retries.
        $viewer_data_degraded = false;

        try {
            $voteService = \BCC\Trust\Core\Plugin::instance()->voteService();
            $vote        = $voteService->getUserPageVote($post_id, $viewer_id);
            $vote_type   = $vote ? (int) $vote->vote_type : 0;
        } catch (Exception $e) {
            self::logViewerError('vote_lookup', $viewer_id, $post_id, $e);
            $viewer_data_degraded = true;
        }

        try {
            $endorseService = \BCC\Trust\Core\Plugin::instance()->endorsementService();
            $has_endorsed   = $endorseService->hasEndorsedPage($post_id, $viewer_id);
        } catch (Exception $e) {
            self::logViewerError('endorsement_lookup', $viewer_id, $post_id, $e);
            $viewer_data_degraded = true;
        }

        // Wallet connection status
        $wallet_connected = false;
        try {
            $connections = WalletSignalRepository::getAllForUser($viewer_id);
            $wallet_connected = !empty($connections);
        } catch (Exception $e) {
            self::logViewerError('wallet_lookup', $viewer_id, $post_id, $e);
            $viewer_data_degraded = true;
        }

        // Collection holdings: which of this page's collections does the viewer hold?
        $holds_collections = [];
        if ($viewer_id !== $owner_id) {
            try {
                $result    = \BCC\Trust\Onchain\Services\CollectionService::getAllForProject($post_id);
                $contracts = array_map(fn($c) => $c->contract_address ?? '', $result['items']);
                $contracts = array_filter($contracts);
                if (!empty($contracts)) {
                    $holds_collections = \BCC\Trust\Onchain\Repositories\CollectionRepository::getUserHoldings($viewer_id, $contracts);
                }
            } catch (Exception $e) {
                self::logViewerError('collection_holdings', $viewer_id, $post_id, $e);
                $viewer_data_degraded = true;
            }
        }

        // Quest progress (derived, not cached — cheap object cache read)
        $quest_progress = null;
        try {
            $quest_progress = \BCC\Trust\Core\Plugin::instance()->questProgressService()->getProgress($viewer_id);
        } catch (Exception $e) {
            self::logViewerError('quest_progress', $viewer_id, $post_id, $e);
            $viewer_data_degraded = true;
        }

        return [
            'is_owner'              => ($viewer_id === $owner_id),
            'vote_type'             => $vote_type,
            'has_endorsed'          => (bool) $has_endorsed,
            'has_flagged'           => FlagService::hasUserFlagged($post_id, $viewer_id),
            'wallet_connected'      => $wallet_connected,
            'holds_collections'     => $holds_collections,
            'quest_progress'        => $quest_progress,
            'viewer_data_degraded'  => $viewer_data_degraded,
        ];
    }

    /**
     * Page categories from PeepSo, mapped via bcc_get_category_map().
     *
     * @param int $post_id
     * @return array<int, array{id: int, slug: string, label: string}>
     */
    private function categoriesSection(int $post_id): array {
        $rows = \BCC\Trust\Core\Repositories\PeepSoQueryRepository::getPageCategories($post_id);
        $cat_ids = array_map(fn($r) => (int) $r->term_id, $rows);

        if (empty($cat_ids)) {
            return [];
        }

        $cat_map = function_exists('bcc_get_category_map') ? bcc_get_category_map() : [];
        $result  = [];

        foreach ($cat_ids as $cat_id) {
            $cat_id = (int) $cat_id;
            if (isset($cat_map[$cat_id])) {
                $entry = $cat_map[$cat_id];
                $result[] = [
                    'id'    => $cat_id,
                    'slug'  => sanitize_title($entry['cpt']),
                    'label' => sanitize_text_field($entry['label']),
                ];
            }
        }

        return $result;
    }

    /**
     * Compute community vs identity weight percentages.
     *
     * community = votes (positive)
     * identity  = on-chain bonus + verification signals
     *
     * Clamped: identity_weight = 1 − community_weight (no float drift).
     * Falls back to 50/50 when no data.
     *
     * @param float $positive
     * @param float $onchain_bonus
     * @return array{community_weight: float, identity_weight: float}
     */
    private function computeWeights(float $positive, float $onchain_bonus): array {
        $community_raw = abs($positive);
        $identity_raw  = abs($onchain_bonus);
        $total         = $community_raw + $identity_raw;

        if ($total > 0) {
            $community_weight = round($community_raw / $total, 2);
            $identity_weight  = round(1.0 - $community_weight, 2);
        } else {
            $community_weight = 0.5;
            $identity_weight  = 0.5;
        }

        return [
            'community_weight' => $community_weight,
            'identity_weight'  => $identity_weight,
        ];
    }

    /* =================================================================
       Shared data loaders
    ================================================================= */

    /**
     * @param int $owner_id
     * @phpstan-return UserInfoRow|null
     */
    private function loadOwnerInfo(int $owner_id): ?object {
        if (!$owner_id) {
            return null;
        }

        try {
            return $this->users->getByUserId($owner_id);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param int $owner_id
     * @return array<string, mixed> Keyed by chain name
     */
    private function loadWalletConnections(int $owner_id) {
        if (!$owner_id) {
            return [];
        }

        try {
            return WalletSignalRepository::getAllForUser($owner_id);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @param int $post_id
     * @return \BCC\Trust\Core\ValueObjects\PageScore|null
     */
    private function loadScore(int $post_id) {
        try {
            return $this->scores->getByPageId($post_id);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGithub() {
        return [
            'connected'       => false,
            'username'        => null,
            'verified_at'     => null,
            'github_id'       => null,
            'followers'       => 0,
            'public_repos'    => 0,
            'org_count'       => 0,
            'trust_boost'     => 0.0,
            'fraud_reduction' => 0,
        ];
    }

    /* =================================================================
       Read-model monitoring
    ================================================================= */

    /**
     * Log and count read-model fallback usage.
     *
     * In production the read model should be populated for every published
     * page. If this fires often, something upstream (PageReadModelSync
     * hooks, cron) is broken.
     *
     * Counter stored in object cache (no DB hit). Exposed via
     * ReadModelHealthEndpoint and wp-admin health dashboard.
     *
     * @param int $pageId
     */
    /**
     * Log a viewer section service failure (was previously swallowed silently).
     *
     * Rate-limited to 1 log per service per request to avoid flooding.
     */
    private static function logViewerError(string $service, int $viewerId, int $pageId, \Exception $e): void {
        static $logged = [];
        $key = $service . ':' . $viewerId . ':' . $pageId;
        if (isset($logged[$key])) {
            return;
        }
        $logged[$key] = true;

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[ViewerSection] Service failure — returning default', [
                'service'   => $service,
                'viewer_id' => $viewerId,
                'page_id'   => $pageId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private static function trackFallback( int $pageId ): void {
        // Log once per page per request (avoid flooding on multi-block pages).
        static $logged = [];
        if ( isset( $logged[ $pageId ] ) ) {
            return;
        }
        $logged[ $pageId ] = true;

        // Structured warning — grep-able, dashboardable.
        if ( class_exists( '\\BCC\\Core\\Log\\Logger' ) ) {
            \BCC\Core\Log\Logger::warning(
                '[ReadModel] Fallback to source tables — read model row missing',
                [ 'page_id' => $pageId ]
            );
        }

        // Bump a running counter in object cache (lightweight, no DB).
        $count = (int) \wp_cache_get( 'rm_fallback_count', 'bcc_page_rm' );
        \wp_cache_set( 'rm_fallback_count', $count + 1, 'bcc_page_rm', 3600 );
    }
}
