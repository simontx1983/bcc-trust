<?php
/**
 * Onboarding Endpoints — handles the post-signup wizard surface.
 *
 * Routes:
 *   - PATCH /me/handle              — change handle (§B6, 7-day cooldown)
 *   - GET   /onboarding/suggestions — admin-curated first-pull candidates (§B4)
 *   - POST  /me/onboarding/complete — flip wp_usermeta.bcc_onboarding_completed
 *   - GET   /me/onboarding/status   — fresh-read of the onboarding flag
 *                                     (used by the frontend's /onboarding
 *                                     gate to skip the wizard for already-
 *                                     onboarded users; cheap + always-fresh)
 *
 * All routes require authentication. Auth is checked inside each
 * handler so unauthenticated requests return the canonical
 * {error: {code, message, status}} envelope (per ApiResponse contract).
 *
 * Suggestions sourcing: PageDiscoveryService::query() ranks by trust
 * score (§F1 — same single-brain ranker that drives the Floor feed).
 * The endpoint requests four entries per type-bucket from
 * PageDiscoveryService, then re-hydrates each entry through
 * CardViewService::getCard() so the response matches the §L5
 * polymorphic card view-model exactly. No new ranking logic.
 *
 * The bucket→page_type mapping (validators, projects, creators) and the
 * card-kind it's rendered under is locked in this file's
 * `BUCKETS` constant. Add a new bucket here, not by inventing a
 * second mapping somewhere else.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, Phase 2 onboarding)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PageCardPrefetcher;
use BCC\Trust\Core\Support\RankCatalog;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class OnboardingEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** §B4 wizard step 2 — four cards per bucket × three buckets = 12 total. */
    private const SUGGESTIONS_LIMIT_PER_TYPE = 4;

    /** wp_usermeta keys for the onboarding-completion flag. */
    private const META_ONBOARDED      = 'bcc_onboarding_completed';
    private const META_ONBOARDED_AT   = 'bcc_onboarding_completed_at';

    /** wp_usermeta key for the user's chosen home chain (§B4 wizard step 1). */
    private const META_HOME_CHAIN     = 'bcc_home_chain';

    /**
     * Allowlist of home-chain identifiers accepted on POST /me/onboarding/complete.
     * Independent allowlist of home-chain slugs. The read side filters
     * Halls by the `_bcc_chain_tag` chain-id meta (ChainRepository), so
     * this list stands on its own. Values are lowercased and trimmed
     * before the allowlist check; stored verbatim.
     *
     * V1 wizard surfaces five (cosmos, osmosis, injective, ethereum, solana)
     * but the storage allowlist keeps the broader set so a settings-page
     * change can swap to polkadot/thorchain/near later without a backend
     * migration.
     *
     * @var list<string>
     */
    private const HOME_CHAINS = [
        'cosmos', 'osmosis', 'injective', 'ethereum', 'solana',
        'polkadot', 'thorchain', 'near',
    ];

    /**
     * Bucket layout for /onboarding/suggestions. Maps the response key
     * (the bucket name in `data.{bucket}`) to:
     *   - page_type — `_bcc_page_type` meta value used by
     *     PageDiscoveryService::query(['types' => …]) for filtering
     *   - card_kind — argument passed to CardViewService::getCard so
     *     the returned view-model carries the right `card_kind` field
     *
     * @var array<string, array{page_type: string, card_kind: string}>
     */
    private const BUCKETS = [
        'validators' => ['page_type' => 'validator', 'card_kind' => 'validator'],
        'projects'   => ['page_type' => 'builder',   'card_kind' => 'project'],
        'creators'   => ['page_type' => 'nft',       'card_kind' => 'creator'],
    ];

    public static function register(): void
    {
        $instance = new self();

        // PATCH /me/handle — also accept POST/PUT via WP_REST_Server::EDITABLE.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/handle',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'updateHandle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'handle' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/onboarding/suggestions',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'suggestions'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/onboarding/complete',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'completeOnboarding'],
                'permission_callback' => '__return_true',
                'args' => [
                    'home_chain' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/onboarding/status',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'onboardingStatus'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function updateHandle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $handleService = Plugin::instance()->handleService();

        // Cooldown check FIRST so a user mashing the rename button
        // can't probe handle availability via 422 responses while
        // they're locked out anyway.
        $expiresAt = $handleService->cooldownExpiresAt($userId);
        if ($expiresAt !== null) {
            $resp = ApiResponse::error(
                'bcc_rate_limited',
                'Handle can only be changed once every 7 days.',
                429
            );
            $resp->header('Retry-After', (string) max(0, $expiresAt - time()));
            return $resp;
        }

        $handle = strtolower(trim((string) $request->get_param('handle')));

        $err = $handleService->validate($handle);
        if ($err !== null) {
            return ApiResponse::error($err, self::handleErrorMessage($err), 422);
        }
        if (!$handleService->isAvailable($handle, $userId)) {
            return ApiResponse::error('bcc_conflict', 'That handle is already taken.', 409);
        }

        // No-op rename short-circuits: don't arm the cooldown when the
        // user submits their existing handle.
        $current = $handleService->getHandle($userId);
        if ($current === $handle) {
            $resp = ApiResponse::ok([
                'handle'         => $handle,
                'next_change_at' => null,
            ]);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        $now = time();
        update_user_meta($userId, HandleService::META_HANDLE, $handle);
        update_user_meta($userId, HandleService::META_HANDLE_LAST_CHANGED, $now);

        Logger::audit('handle_changed', [
            'user_id'        => $userId,
            'previous'       => $current,
            'handle'         => $handle,
        ]);

        do_action('bcc_handle_changed', $userId, $current, $handle);

        $nextChangeAt = $now + HandleService::COOLDOWN_SECONDS;

        $resp = ApiResponse::ok([
            'handle'         => $handle,
            'next_change_at' => gmdate('Y-m-d\TH:i:s\Z', $nextChangeAt),
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function suggestions(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $cardSvc   = Plugin::instance()->cardViewService();
        $discovery = Plugin::instance()->pageDiscoveryService();

        $out = [
            'validators' => [],
            'projects'   => [],
            'creators'   => [],
        ];

        // Two passes: collect every bucket's page ids first, then prime
        // ONE PageCardPrefetcher bundle across all 12 candidates so the
        // per-card hydration below doesn't N+1 (all three buckets are
        // page kinds — the bundle groups by target_kind internally).
        $bucketPageIds = [];
        foreach (self::BUCKETS as $bucket => $spec) {
            $bucketPageIds[$bucket] = [];

            $result = $discovery->query([
                'types'         => [$spec['page_type']],
                'sort'          => 'trust',
                'verified_only' => false,
                'limit'         => self::SUGGESTIONS_LIMIT_PER_TYPE,
                'page'          => 1,
            ]);

            $items = is_array($result['results'] ?? null) ? $result['results'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $pageId = (int) ($item['page_id'] ?? 0);
                if ($pageId <= 0) {
                    continue;
                }
                $bucketPageIds[$bucket][] = $pageId;
            }
        }

        $allPageIds = array_merge(...array_values($bucketPageIds));
        if ($allPageIds !== []) {
            $prefetched = PageCardPrefetcher::primeFor($allPageIds, $userId);
            foreach (self::BUCKETS as $bucket => $spec) {
                foreach ($bucketPageIds[$bucket] as $pageId) {
                    $card = $cardSvc->getPageCardForList($spec['card_kind'], $pageId, $userId, $prefetched);
                    if ($card !== null) {
                        $out[$bucket][] = $card;
                    }
                }
            }
        }

        $resp = ApiResponse::ok($out);
        // Suggestions are per-user (viewer state baked into card permissions)
        // but stable enough for short browser caching during the wizard.
        $resp->header('Cache-Control', 'private, max-age=60');
        return $resp;
    }

    public function onboardingStatus(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $onboarded = (string) get_user_meta($userId, self::META_ONBOARDED, true) === '1';

        $resp = ApiResponse::ok([
            'onboarded' => $onboarded,
        ]);
        // No-store: cheap to recompute, and the frontend's /onboarding
        // gate depends on a current value (stale would mean returning
        // users see the wizard one extra time after completing).
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function completeOnboarding(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Optional home-chain pick from wizard step 1. Skipping that step
        // sends no field; we treat null + empty string identically.
        $homeChainRaw = $request->get_param('home_chain');
        $homeChain    = null;
        if (is_string($homeChainRaw) && $homeChainRaw !== '') {
            $candidate = strtolower(trim($homeChainRaw));
            if (!in_array($candidate, self::HOME_CHAINS, true)) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'Unknown home chain.',
                    422
                );
            }
            $homeChain = $candidate;
        }

        $alreadyCompleted = (string) get_user_meta($userId, self::META_ONBOARDED, true) === '1';

        update_user_meta($userId, self::META_ONBOARDED, '1');
        if (!$alreadyCompleted) {
            update_user_meta($userId, self::META_ONBOARDED_AT, time());
        }
        if ($homeChain !== null) {
            // Persist on every completion call that carries one — the user
            // re-running the wizard is rare, but if they do, last-write-wins.
            update_user_meta($userId, self::META_HOME_CHAIN, $homeChain);
        }

        // Idempotent fire — subscribers (rank service, notification
        // dispatcher, etc.) MUST treat repeat events as no-ops. The
        // first-time gate above prevents duplicate `_at` timestamps but
        // we still emit the event so a subscriber recovering from a
        // failed first run can replay against `bcc_onboarding_completed`
        // without depending on the timestamp.
        do_action('bcc_onboarding_completed', $userId);

        if (!$alreadyCompleted) {
            Logger::audit('onboarding_completed', [
                'user_id'    => $userId,
                'home_chain' => $homeChain,
            ]);
        }

        // §A2 — surface the user's current rank label so the wizard's
        // dopamine step can render the "rank earned" line without
        // hardcoding "Apprentice". Reads the auto-derived rank (no
        // admin row possible at onboarding time); RankCatalog::getLabel
        // returns null only for unknown keys, which RankService never
        // produces — fall back to '' to keep the type stable.
        $rankKey   = Plugin::instance()->rankService()->autoDerivedRank($userId);
        $rankLabel = RankCatalog::getLabel($rankKey) ?? '';

        $resp = ApiResponse::ok([
            'completed'  => true,
            'home_chain' => $homeChain,
            'rank_label' => $rankLabel,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    private static function handleErrorMessage(string $code): string
    {
        return match ($code) {
            HandleService::ERR_RESERVED => 'That handle is reserved.',
            default                     => 'Handle must be 3–20 chars, lowercase letters, digits, or hyphens, '
                                            . 'with no leading, trailing, or consecutive hyphens.',
        };
    }
}
