<?php

namespace BCC\Trust\Onchain\Admin;

if (!defined('ABSPATH')) {
    exit;
}

// ── S4: FIFTEEN IMPORTS WENT WITH THE SURFACE ───────────────────────────
//
// This page previously imported ChainCheckpointRepository, ScannerFreeze,
// ChainRepository, CosmwasmCodeFamilyRepository, CosmwasmContractRepository,
// CosmwasmClassifier, CosmwasmDiscoveryHealthSnapshot, CosmwasmDiscoveryService,
// CosmosEndpointAuthorization, CosmosEndpointVerifier, CosmwasmDiscoveryGate,
// CosmwasmPassReport, CosmwasmPassStopReason, ProviderRequestBudget and
// CosmwasmDiscoveryWorker. Every one was reachable only from the withdrawn
// CosmWasm routes and their render family; none is referenced any more.
//
// The six that remain are the capability control plane and manual intake,
// which is the whole of what this page still does.
use BCC\Trust\Onchain\Admin\Views\NftCapabilityEditorPanel;
use BCC\Trust\Onchain\Services\NftCapabilityEditor;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Services\ManualCollectionIntakeService;

/**
 * Admin page: NFT Discovery — the per-chain control plane.
 *
 * ── WHAT THIS PAGE IS ───────────────────────────────────────────────────
 * The one place that answers, for every chain BCC knows about: what NFT
 * work can this chain actually do, which driver would do it, is that driver
 * enabled, is it configured, and if the answer is no — WHICH no.
 *
 * It is the first consumer of the capability model
 * ({@see NftChainCapability}, {@see NftDriverRegistry},
 * {@see \BCC\Trust\Onchain\Support\NftProviderReadiness}), which shipped
 * deliberately unread so that the surface reading it could be reviewed on
 * its own.
 *
 * ── IT ABSORBED THE CHAINS SUB-TAB; IT DID NOT DUPLICATE IT ─────────────
 * `?page=bcc-onchain-chains&subtab=nft-discovery` used to render the
 * CosmWasm/CW-721 engine section and owned six admin-post routes. All of it
 * moved HERE, and the old sub-tab is gone — a second "NFT Discovery"
 * surface beside the first would be a §11 violation and, worse, two places
 * an operator could read a different answer about one chain.
 *
 * Every moved ROUTE STRING is byte-identical to what it was
 * (`bcc_chain_cw_pause`, …). Nonce actions are still `<route>_<chainId>`,
 * the audit vocabulary is unchanged, and a bookmarked form still posts to a
 * route that exists. Only the destination of the PRG redirect changed, from
 * `subtab=nft-discovery` to `family=cosmos`, and
 * {@see maybe_redirect_legacy_url()} keeps the old URL working.
 *
 * ── WHAT IT CANNOT DO, AND WHY THAT IS THE POINT ────────────────────────
 * This page is READ-ONLY about capability. It has no writer for
 * `bcc_supports_nft_collections`, none for
 * `manual_collection_discovery_enabled`, and none for a driver override
 * row. It explains those values; it cannot change them, and it cannot seed
 * one. The editor that changes them is a later, separately reviewed change.
 *
 * A consequence worth stating plainly, because an operator will meet it
 * first: both capability columns are `DEFAULT 0` with no backfill and no
 * writer anywhere in this build, so on a stock install every chain reads
 * `no_bcc_support` and the backfill control is not offered. That is the
 * intended fail-closed state, not a defect, and the page names the exact
 * missing permission rather than showing a dead button.
 *
 * ── AND WHAT IT MUST NEVER GROW ─────────────────────────────────────────
 * No cron hook, no `wp_schedule_event`, no `register()` on
 * {@see CosmwasmDiscoveryWorker}, no REST or AJAX route that reaches a
 * discovery entry point. Automatic collection discovery was retired
 * deliberately; the only sanctioned way in is admin-post + capability +
 * POST-only + a route-and-chain-scoped nonce, which is what this file
 * implements and what its tests pin.
 */
class NftDiscoveryPage
{
    const PAGE_SLUG = 'bcc-onchain-nft-discovery';

    /**
     * Where this surface used to live.
     *
     * Kept as constants rather than literals so the compatibility redirect
     * and its test name the same thing, and so grepping for the old
     * location finds the one place that still knows about it.
     */
    public const LEGACY_PAGE_SLUG = 'bcc-onchain-chains';
    public const LEGACY_SUBTAB    = 'nft-discovery';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'NFT Discovery',
            'NFT Discovery',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /**
     * The CAPABILITY EDITOR routes — the only sanctioned way any of the
     * three capability values is written.
     *
     * ── EIGHT ROUTES, NOT THREE TOGGLES ─────────────────────────────────
     * Every one names its direction, for the reason this file has argued
     * since the CosmWasm opt-in was written: a toggle takes its direction
     * from the state the page held at RENDER time, so a tab left open across
     * somebody else's change applies the opposite of what its operator is
     * looking at. The route names the direction and the nonce is bound to
     * it, so a stale submit is a no-op instead of a reversal.
     *
     * The four driver routes bind their nonce to the DRIVER and OPERATION as
     * well as the chain, so a nonce minted to disable `cw721_lcd` for
     * `metadata` cannot authorise anything else — including the stale
     * removal, which is the one route whose strings are not registry-checked.
     *
     * There is deliberately no bulk route, no family-wide route and no
     * "enable everything" route. Capability is granted one decision at a
     * time or not at all.
     */
    public const ACTION_CAP_PRODUCT_ENABLE  = 'bcc_nft_cap_product_enable';
    public const ACTION_CAP_PRODUCT_DISABLE = 'bcc_nft_cap_product_disable';
    public const ACTION_CAP_MANUAL_ENABLE   = 'bcc_nft_cap_manual_enable';
    public const ACTION_CAP_MANUAL_DISABLE  = 'bcc_nft_cap_manual_disable';
    public const ACTION_CAP_DRIVER_DISABLE  = 'bcc_nft_cap_driver_disable';
    public const ACTION_CAP_DRIVER_ENABLE   = 'bcc_nft_cap_driver_enable';
    public const ACTION_CAP_DRIVER_INHERIT  = 'bcc_nft_cap_driver_inherit';
    public const ACTION_CAP_STALE_REMOVE    = 'bcc_nft_cap_stale_remove';

    /**
     * PR 6: the ONE manual collection-intake route.
     *
     * Replaces `bcc_vc_add_collection` and `bcc_vc_add_cosmos` on Verify
     * Collections, neither of which is registered any more. The nonce is
     * bound to the CHAIN (`<route>_<chainId>`), so an add authorised for
     * one chain cannot be replayed against another.
     */
    public const ACTION_ADD_COLLECTION = 'bcc_nftd_add_collection';

    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_CAP_PRODUCT_ENABLE,  [self::class, 'handle_cap_product_enable']);
        add_action('admin_post_' . self::ACTION_CAP_PRODUCT_DISABLE, [self::class, 'handle_cap_product_disable']);
        add_action('admin_post_' . self::ACTION_CAP_MANUAL_ENABLE,   [self::class, 'handle_cap_manual_enable']);
        add_action('admin_post_' . self::ACTION_CAP_MANUAL_DISABLE,  [self::class, 'handle_cap_manual_disable']);
        add_action('admin_post_' . self::ACTION_CAP_DRIVER_DISABLE,  [self::class, 'handle_cap_driver_disable']);
        add_action('admin_post_' . self::ACTION_CAP_DRIVER_ENABLE,   [self::class, 'handle_cap_driver_enable']);
        add_action('admin_post_' . self::ACTION_CAP_DRIVER_INHERIT,  [self::class, 'handle_cap_driver_inherit']);
        add_action('admin_post_' . self::ACTION_CAP_STALE_REMOVE,    [self::class, 'handle_cap_stale_remove']);
        add_action('admin_post_' . self::ACTION_ADD_COLLECTION,      [self::class, 'handle_add_collection']);

        // ── S4: THE SIX COSMWASM ROUTES ARE GONE, NOT FROZEN ────────────
        //
        // This page used to register six more admin-post routes, each behind
        // `if (!ScannerFreeze::frozen())`:
        //
        //   bcc_chain_cw_discovery_enable / _disable   per-chain scanner opt-in
        //   bcc_chain_cw_pause / _resume               operational hold
        //   bcc_chain_cw_backfill                      the ONLY provider-spending control
        //   bcc_chain_cw_retry                         requeue failed work
        //
        // The freeze meant none of them was ever registered in production, so
        // removing them changes nothing a request can reach. What it does
        // change is that the guarantee no longer rests on `frozen()` continuing
        // to return true: `apply_cw_backfill()` reached `prove_endpoint()` — a
        // live outbound request — before the worker's own `CW_STATE_UNSUPPORTED`
        // check, and S5 removes the capability rung that refused such a chain
        // earlier. Withdrawing the route first makes that ordering structural.
        //
        // The eight capability routes and Add Collection above are NOT affected:
        // they write `bcc_supports_nft_collections` and
        // `manual_collection_discovery_enabled`, different columns with their
        // own routes, and they start nothing.

        // Bookmarks, browser history and any link written before the move.
        add_action('admin_init', [self::class, 'maybe_redirect_legacy_url']);
    }

    /**
     * Send the retired Chains sub-tab URL here.
     *
     * ── WHY admin_init AND NOT THE PAGE CALLBACK ────────────────────────
     * A submenu page callback runs after wp-admin has already sent headers
     * and printed the chrome, so a redirect from there is too late.
     * `admin_init` fires before any of that.
     *
     * ── WHAT IT CARRIES, AND WHAT IT REFUSES TO ─────────────────────────
     * Only the three notice keys, so a PRG landing that was in flight when
     * this shipped — or a tab left open across the deploy — still shows its
     * result. Everything else is dropped: the old URL could carry a stale
     * `subtab`, and forwarding arbitrary query args would let this redirect
     * be used to smuggle values onto the new page.
     *
     * The capability check is not authorization — this changes nothing —
     * but there is no reason to hand a logged-out visitor a map of the
     * admin surface, and `wp_safe_redirect` refuses off-host targets.
     */
    public static function maybe_redirect_legacy_url(): void
    {
        $page   = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $subtab = isset($_GET['subtab']) ? sanitize_key((string) $_GET['subtab']) : '';

        if ($page !== self::LEGACY_PAGE_SLUG || $subtab !== self::LEGACY_SUBTAB) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $args = [
            'page'   => self::PAGE_SLUG,
            'family' => NftDiscoveryControlPlaneSnapshot::FAMILY_COSMOS,
        ];

        // A 302, not a 301. The old URL is retired, but a permanent
        // redirect is cached by the browser indefinitely and would be a
        // nuisance to undo if this move ever needed reverting.
        foreach (['bcc_cwd', 'bcc_cwo', 'bcc_ref'] as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                $args[$key] = sanitize_text_field((string) $_GET[$key]);
            }
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')), 302);
        exit;
    }

    /**
     * Shape-only validation of the target chain id.
     *
     * Returns a validated POSITIVE INTEGER or terminates with a real HTTP
     * 400. It deliberately does no repository work: the id feeds the nonce
     * action, so it must be read pre-CSRF, and a lookup here would let an
     * authorised-but-unverified request probe which chains exist.
     *
     * ── WHY IT REFUSES RATHER THAN REDIRECTS ────────────────────────────
     * An earlier draft redirected with an `invalid_chain` notice. That is a
     * 302 dressed up as a rejection: the request was malformed, so there is
     * no legitimate page to send the operator back to, and no nonce could
     * have been verified for a target that does not parse.
     *
     * ── AND WHY IT NEVER TOUCHES THE RAW VALUE ──────────────────────────
     * `$_POST['chain_id']` may be an array, a very long string, or contain
     * CR/LF. `is_scalar()` rejects the array before any cast (an array-to-int
     * cast raises a PHP warning and yields 1 — a valid-looking chain id from
     * pure garbage). Nothing derived from the raw value reaches the wp_die
     * message, so it cannot be reflected back.
     *
     * ── `\z` AND NOT `$` ────────────────────────────────────────────────
     * In PCRE, `$` matches at the end of the subject OR immediately before
     * a trailing newline. So `/^[1-9][0-9]*$/` ACCEPTS "4\n" — a value that
     * looks validated, casts to 4, and carries a control character that a
     * caller could rely on being stripped. `\z` matches only the true end
     * of the subject, so a trailing newline is what it is: not a chain id.
     * The same anchor is used by {@see require_key_shape()} and
     * {@see require_priority_shape()}, where the value is used as a STRING
     * and the newline would survive into the nonce action and the domain.
     */
    private static function require_chain_id_shape(): int
    {
        $raw = $_POST['chain_id'] ?? null;

        $valid = is_scalar($raw)
            && preg_match('/^[1-9][0-9]{0,17}\z/', (string) $raw) === 1;

        if (!$valid) {
            wp_die(
                esc_html__('Invalid chain.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return (int) $raw;
    }
    // ── The capability editor ───────────────────────────────────────────

    public static function handle_cap_product_enable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_PRODUCT_ENABLE);
    }

    public static function handle_cap_product_disable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_PRODUCT_DISABLE);
    }

    public static function handle_cap_manual_enable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_MANUAL_ENABLE);
    }

    public static function handle_cap_manual_disable(): void
    {
        self::handle_cap_flag(self::ACTION_CAP_MANUAL_DISABLE);
    }

    public static function handle_cap_driver_disable(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_DRIVER_DISABLE);
    }

    public static function handle_cap_driver_enable(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_DRIVER_ENABLE);
    }

    public static function handle_cap_driver_inherit(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_DRIVER_INHERIT);
    }

    public static function handle_cap_stale_remove(): void
    {
        self::handle_cap_driver(self::ACTION_CAP_STALE_REMOVE);
    }

    /**
     * Request boundary for the two chain-flag pairs.
     *
     * Order, and every step of it is load-bearing:
     *
     *   refusal trace (server-known values only)
     *   → capability          reachable via admin-post without the page
     *   → POST-only           admin-post dispatches GET too
     *   → chain-id SHAPE      no lookup: the nonce action is built from it
     *   → direction-and-chain nonce
     *   → the domain          which does the authoritative lookup itself
     *   → PRG
     *
     * Nothing touches a repository before the nonce has proven the request
     * authentic, and the shape check does no lookup — so an unauthenticated
     * POST cannot probe which chain ids exist.
     */
    private static function handle_cap_flag(string $route): never
    {
        // The trace carries NOTHING from the request. `chain_id` is
        // attacker-controlled and unvalidated at this point: echoing it
        // would let an unauthenticated caller write our log, and would
        // answer "does chain 41 exist?" for anyone who can POST.
        if (!current_user_can('manage_options')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] NFT capability change refused', [
                'action'   => 'nft_capability_edit_denied',
                'route'    => self::cap_route_slug($route),
                'operator' => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId = self::require_chain_id_shape();

        AdminActionSupport::requireNonce($route . '_' . $chainId);

        try {
            $result = match ($route) {
                self::ACTION_CAP_PRODUCT_ENABLE  => NftCapabilityEditor::enableProductSupport($chainId),
                self::ACTION_CAP_PRODUCT_DISABLE => NftCapabilityEditor::disableProductSupport($chainId),
                self::ACTION_CAP_MANUAL_ENABLE   => NftCapabilityEditor::enableManualDiscovery($chainId),
                self::ACTION_CAP_MANUAL_DISABLE  => NftCapabilityEditor::disableManualDiscovery($chainId),
                default                          => NftCapabilityEditor::RESULT_UNKNOWN_CHAIN,
            };
        } catch (\Throwable $e) {
            // The correlation id goes to the LOG ONLY. Unlike the scanner
            // routes above, the capability PRG carries no reference: see
            // redirect_capability().
            AdminActionSupport::failure(
                $e,
                'admin_nft_capability_error',
                'chain',
                $chainId
            );

            self::redirect_capability(self::CAP_RESULT_ERROR);
        }

        self::redirect_capability($result);
    }

    /**
     * Request boundary for the four driver-override routes.
     *
     * Same order as the flag routes, with the target widened: the nonce is
     * bound to `route + chain + operation + driver`, so a nonce minted for
     * one triple authorises exactly that triple and nothing else.
     *
     * ── SHAPE IS CHECKED BEFORE THE NONCE, AND SEPARATELY FROM MEANING ──
     * The operation and driver strings are part of the nonce ACTION, so
     * they must be read before the nonce can be verified. What is checked
     * here is only that they are plausible column values — a lowercase key
     * of at most 32 characters, which is what the storage holds. Whether
     * they name something this build implements is a DOMAIN question, and
     * it is answered by {@see NftCapabilityEditor} after the nonce passes,
     * because the stale-removal route deliberately accepts strings the
     * registry no longer recognises.
     */
    private static function handle_cap_driver(string $route): never
    {
        if (!current_user_can('manage_options')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] NFT driver override refused', [
                'action'   => 'nft_capability_edit_denied',
                'route'    => self::cap_route_slug($route),
                'operator' => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId   = self::require_chain_id_shape();
        $operation = self::require_key_shape('operation');
        $driverKey = self::require_key_shape('driver_key');

        // The priority is read for exactly one route, and BEFORE the nonce,
        // so a malformed value cannot reach the domain even with a valid
        // nonce. Shape only — the 0..1000 RANGE is the domain's to enforce,
        // and it refuses rather than clamping.
        $priority = $route === self::ACTION_CAP_DRIVER_ENABLE
            ? self::require_priority_shape()
            : 0;

        AdminActionSupport::requireNonce(
            $route . '_' . $chainId . '_' . $operation . '_' . $driverKey
        );

        try {
            $result = match ($route) {
                self::ACTION_CAP_DRIVER_DISABLE =>
                    NftCapabilityEditor::disableDriver($chainId, $operation, $driverKey),
                self::ACTION_CAP_DRIVER_ENABLE =>
                    NftCapabilityEditor::enableDriver($chainId, $operation, $driverKey, $priority),
                self::ACTION_CAP_DRIVER_INHERIT =>
                    NftCapabilityEditor::inheritDriver($chainId, $operation, $driverKey),
                self::ACTION_CAP_STALE_REMOVE =>
                    NftCapabilityEditor::removeStaleOverride($chainId, $operation, $driverKey),
                default => NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE,
            };
        } catch (\Throwable $e) {
            AdminActionSupport::failure(
                $e,
                'admin_nft_capability_error',
                'chain',
                $chainId
            );

            self::redirect_capability(self::CAP_RESULT_ERROR);
        }

        self::redirect_capability($result);
    }

    /** Short, fixed route name for a log line — derived from the ROUTE, never the request. */
    private static function cap_route_slug(string $route): string
    {
        return match ($route) {
            self::ACTION_CAP_PRODUCT_ENABLE  => 'product_enable',
            self::ACTION_CAP_PRODUCT_DISABLE => 'product_disable',
            self::ACTION_CAP_MANUAL_ENABLE   => 'manual_enable',
            self::ACTION_CAP_MANUAL_DISABLE  => 'manual_disable',
            self::ACTION_CAP_DRIVER_DISABLE  => 'driver_disable',
            self::ACTION_CAP_DRIVER_ENABLE   => 'driver_enable',
            self::ACTION_CAP_DRIVER_INHERIT  => 'driver_inherit',
            self::ACTION_CAP_STALE_REMOVE    => 'stale_remove',
            default                          => 'unknown',
        };
    }

    /**
     * The shape an operation or driver key must have to be looked at at all.
     *
     * Lowercase letters, digits and underscores, 1–32 characters — exactly
     * what `VARCHAR(32)` columns hold, and exactly the alphabet every real
     * operation and driver key uses.
     *
     * ── NOTHING IS SANITISED INTO VALIDITY ──────────────────────────────
     * A value is either already of this shape or the request is refused.
     * Running `sanitize_key()` over it first — which lowercases and strips
     * disallowed characters — would turn `Cw721_LCD!` into `cw721_lcd` and
     * accept a request nobody sent, and would turn a 200-character string
     * into a 200-character key that still fails but only at the database.
     * `is_scalar()` comes first so an ARRAY is rejected before any cast: an
     * array-to-string cast raises a warning and yields "Array", which is a
     * perfectly well-shaped-looking key.
     *
     * A real HTTP 400, not a redirect: a malformed target has no page to
     * send the operator back to, and no nonce could have been verified for
     * a triple that does not parse. Nothing derived from the raw value
     * reaches the response, so it cannot be reflected back.
     */
    private static function require_key_shape(string $field): string
    {
        $raw = $_POST[$field] ?? null;

        $valid = is_scalar($raw)
            && preg_match('/^[a-z0-9_]{1,32}\z/', (string) $raw) === 1;

        if (!$valid) {
            wp_die(
                esc_html__('Invalid capability target.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return (string) $raw;
    }

    /**
     * Shape-only validation of a driver priority.
     *
     * Up to four digits, no sign, no whitespace, no separators. That admits
     * 0–9999, which is deliberately WIDER than the accepted range: the
     * 0–1000 bound is a domain rule, and refusing 5000 with a bounded
     * `override_invalid_priority` notice tells an operator what the limit is,
     * where a 400 would only tell them the request was malformed.
     *
     * What this stops is the other thing: an array, a negative number, a
     * float, `1e3`, or a value long enough to overflow — none of which is a
     * priority anybody typed.
     */
    private static function require_priority_shape(): int
    {
        $raw = $_POST['priority'] ?? null;

        $valid = is_scalar($raw)
            && preg_match('/^[0-9]{1,4}\z/', (string) $raw) === 1;

        if (!$valid) {
            wp_die(
                esc_html__('Invalid priority.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return (int) $raw;
    }

    /**
     * PRG terminator for every capability edit.
     *
     * ── NARROWER THAN THE SCANNER ROUTES ABOVE, ON PURPOSE ──────────────
     * The destination carries three keys and no fourth:
     *
     *   page        fixed
     *   family      fixed
     *   bcc_nftcap  a bounded result code from a closed set that this
     *               codebase authors — see NftCapabilityEditor's constants
     *
     * No chain id under any name. No operation, no driver key, no priority,
     * no submitted value, no exception text, and — unlike
     * {@see redirect_cw_discovery()} — not even a correlation reference.
     * The scanner routes carry `bcc_ref` because they report on WORK that
     * ran; a capability edit reports on a CONFIGURATION CHANGE, its failure
     * modes are ours rather than a provider's, and the fewer things this URL
     * can carry the less there is to reason about. The correlation id is
     * still minted and still written to the file log under the durable audit
     * row; it simply does not travel in the browser.
     *
     * The cost is that the notice is generic and the editor closes. The
     * DURABLE AUDIT ROW carries the real chain target, which is where
     * "which chain was that?" is answered.
     *
     * @var list<string> CAPABILITY_REDIRECT_KEYS the only keys this destination may carry
     */
    public const CAPABILITY_REDIRECT_KEYS = ['page', 'family', 'bcc_nftcap'];

    /** The one result code that is the PAGE's rather than the editor's. */
    public const CAP_RESULT_ERROR = 'error';

    private static function redirect_capability(string $result): never
    {
        AdminActionSupport::redirect([
            'page'       => self::PAGE_SLUG,
            'family'     => self::current_family(),
            'bcc_nftcap' => $result,
        ]);
    }

    /**
     * The family tab to land on.
     *
     * Read from the SUBMITTED form, because the four families are a closed
     * set this class owns and an unrecognised value falls back to the
     * default — so the worst a hostile value achieves is landing the
     * operator on the Cosmos tab. It is navigation, not a target: the write
     * has already happened, and nothing downstream reads this.
     */
    private static function current_family(): string
    {
        $family = isset($_POST['family']) && is_scalar($_POST['family'])
            ? sanitize_key((string) $_POST['family'])
            : '';

        return NftDiscoveryControlPlaneSnapshot::isFamily($family)
            ? $family
            : NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY;
    }
    // ── Render ──────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        // Defense in depth: add_submenu_page() already gates on this
        // capability, but relying on menu registration alone is the gap
        // every sibling page has already closed.
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Sorry, you are not allowed to access this page.', 'bcc-trust'),
                esc_html__('Forbidden', 'bcc-trust'),
                ['response' => 403]
            );
        }

        $family = isset($_GET['family'])
            ? sanitize_key((string) $_GET['family'])
            : NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY;

        if (!NftDiscoveryControlPlaneSnapshot::isFamily($family)) {
            $family = NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY;
        }

        $snapshot = NftDiscoveryControlPlaneSnapshot::buildForFamily($family);

        // ── S4: ONE NOTICE SOURCE, NOT THREE ────────────────────────────
        //
        // The CosmWasm opt-in (`bcc_cwd`) and scanner-operation (`bcc_cwo`)
        // notice builders are gone with the routes that set those keys. A
        // stale bookmark carrying either key now simply shows no notice,
        // which is correct: the action it described cannot have run.
        $notice = self::capability_notice_from_query();

        // ── THE SELECTED CHAIN COMES FROM THE CANONICAL ROWS ────────────
        //
        // `?chain=` is a request value and is never trusted as an identity:
        // it selects among the rows the SNAPSHOT already built from
        // `ChainRepository::getAll()`, and an id that matches none of them
        // simply selects nothing. So the editor cannot be pointed at a chain
        // this family does not contain, at a chain that does not exist, or
        // at a row assembled from the query string.
        $selected = self::selected_chain($snapshot);

        // ── S4: THE RUN REPORT IS GONE ──────────────────────────────────
        //
        // `store_run_report()` had exactly one caller — `apply_cw_backfill()` —
        // so the whole mechanism (mint / store / take / render, the
        // `bcc_nftd_run_` transient and the `bcc_run` PRG key) existed solely
        // to report a backfill. The route is withdrawn, so the report has
        // nothing left to describe. A stale URL carrying `bcc_run` now reads
        // as an ordinary page load.
        ?>
        <div class="wrap">
            <h1>NFT Discovery</h1>

            <p style="max-width:900px;">
                What each chain can actually do for NFTs, which driver would do it, and — when it
                cannot — exactly which permission, driver or credential is missing. Select a chain
                to edit the two permissions BCC controls and to narrow or reorder the drivers the
                code already offers.
            </p>

            <p style="max-width:900px;color:#646970;">
                <strong>Nothing on this page starts work.</strong> Granting product support does not
                start a discovery. Granting the manual permission does not start a discovery — it
                only allows an administrator to start one later. A driver override can narrow or
                reorder what the code already declares; it can never add a capability the build does
                not have. Provider readiness is observed here, never edited. The backfill is a
                separate, explicit action and appears only when every gate passes. Nothing here
                verifies a collection or creates a community.
            </p>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px">
                <?php foreach (NftDiscoveryControlPlaneSnapshot::FAMILIES as $key): ?>
                    <a href="<?php echo esc_url(add_query_arg(
                        ['page' => self::PAGE_SLUG, 'family' => $key],
                        admin_url('admin.php')
                    )); ?>"
                       class="nav-tab <?php echo $family === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html(NftDiscoveryControlPlaneSnapshot::familyLabel($key)); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php self::render_capability_matrix(
                $snapshot,
                $selected === null ? null : (int) ($selected['chain_id'] ?? 0)
            ); ?>

            <?php NftCapabilityEditorPanel::render($snapshot, $selected); ?>

            <?php
            // PR 6: the one manual intake form, chain-locked to this family.
            // Rendered AFTER the capability editor deliberately — when it is
            // refused, the control that fixes it is the thing directly above.
            self::add_result_notice(
                isset($_GET['bcc_nftadd']) ? sanitize_key((string) $_GET['bcc_nftadd']) : ''
            );
            self::render_add_collection($family, $snapshot['chains']);
            ?>

            <?php
            // ── S4: BOTH ENUMERATION BRANCHES ARE GONE ──────────────────
            //
            // This was `if ($snapshot['supports_enumeration_engine'])` →
            // render the CosmWasm / CW-721 Discovery section, `else` → render
            // "this family has no enumeration engine".
            //
            // BOTH are deleted, not just the first. The `else` branch existed
            // to explain why EVM and Solana lacked a section Cosmos had; with
            // the section withdrawn no family has one, so a notice saying this
            // family is the exception would be false for every family. An
            // honest page says nothing here rather than explaining the absence
            // of something nothing else has.
            //
            // Manual Add Collection above is untouched and keeps its own
            // per-family labels and validation copy, which is where an
            // operator learns what a given chain will and will not check.
            ?>
            <?php self::render_wallet_refresh_method($snapshot); ?>
        </div>
        <?php
    }

    /**
     * The chain whose editor is open, chosen from the SNAPSHOT's own rows.
     *
     * ── A REQUEST VALUE SELECTS; IT NEVER IDENTIFIES ────────────────────
     * `?chain=` is compared against rows the snapshot already built from
     * `ChainRepository::getAll()`. It cannot introduce a chain, cannot reach
     * a chain of another family, and cannot produce a row of its own — an id
     * matching nothing selects nothing and the editor is simply not shown.
     *
     * This also means the editor and the matrix directly above it are the
     * same rows from the same read, so they cannot disagree about a chain
     * they are both describing.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|null
     */
    private static function selected_chain(array $snapshot): ?array
    {
        $raw = $_GET['chain'] ?? null;
        if (!is_scalar($raw) || preg_match('/^[1-9][0-9]{0,17}\z/', (string) $raw) !== 1) {
            return null;
        }

        $wanted = (int) $raw;
        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($snapshot['chains'] ?? null) ? $snapshot['chains'] : [];

        foreach ($chains as $chain) {
            if ((int) ($chain['chain_id'] ?? 0) === $wanted) {
                return $chain;
            }
        }

        return null;
    }

    /**
     * Rebuild the capability-editor notice from the PRG landing.
     *
     * Deliberately GENERIC about WHICH chain: the destination carries no
     * target (see {@see CAPABILITY_REDIRECT_KEYS}), so this cannot name one
     * and must not try. The durable audit row answers "which chain was
     * that?".
     *
     * Every sentence is written to be true of an action that CHANGED
     * CONFIGURATION and did nothing else. None of them may say a provider
     * ran, a collection was discovered, or a capability was proven ready —
     * because none of that happened, and a notice is the place an operator
     * is most likely to take our word for it.
     *
     * @return array{type: string, message: string}|null
     */
    private static function capability_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_nftcap']) ? sanitize_key((string) $_GET['bcc_nftcap']) : '';
        if ($result === '') {
            return null;
        }

        switch ($result) {
            // ── Product support ─────────────────────────────────────────
            case NftCapabilityEditor::RESULT_PRODUCT_ENABLED:
                return ['type' => 'success', 'message' =>
                    'NFT product support is now ON for that chain. This does NOT start a discovery and '
                    . 'does NOT permit one — the manual permission is a separate grant, and it was left '
                    . 'as it was. Nothing was contacted and no collection was touched.'];

            case NftCapabilityEditor::RESULT_PRODUCT_DISABLED:
                return ['type' => 'success', 'message' =>
                    'NFT product support is now OFF for that chain. Existing collections were kept and '
                    . 'nothing was unverified or removed; the chain simply reports no NFT capability.'];

            case NftCapabilityEditor::RESULT_PRODUCT_DISABLED_CASCADE:
                return ['type' => 'success', 'message' =>
                    'NFT product support is now OFF for that chain, AND the manual discovery permission '
                    . 'was cleared with it. That is deliberate: a permission left behind on an '
                    . 'unsupported chain is invisible until support is granted again, and would then '
                    . 'come back already permitted. Grant it again explicitly if you re-enable support. '
                    . 'Existing collections were kept.'];

            case NftCapabilityEditor::RESULT_PRODUCT_NOOP_ENABLED:
                return ['type' => 'info', 'message' =>
                    'NFT product support was already ON for that chain. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_PRODUCT_NOOP_DISABLED:
                return ['type' => 'info', 'message' =>
                    'NFT product support was already OFF for that chain, and no manual permission was '
                    . 'left set. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_PRODUCT_WRITE_FAILED:
                return ['type' => 'error', 'message' =>
                    'Product support could NOT be changed — the database write failed and nothing was '
                    . 'changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_PRODUCT_UNVERIFIED:
                return ['type' => 'error', 'message' =>
                    'The change was attempted but could NOT be confirmed: the stored value does not read '
                    . 'back as expected, so this is not being reported as done. Nothing else was touched '
                    . '— no discovery, no provider, no collection. Reload to see the current state and '
                    . 'check the bcc-trust error log.'];

            // ── Manual discovery permission ─────────────────────────────
            case NftCapabilityEditor::RESULT_MANUAL_ENABLED:
                return ['type' => 'success', 'message' =>
                    'An administrator may now SUBMIT ONE CONTRACT on that chain through manual '
                    . 'intake. This grants no chain-wide authority: nothing was started, nothing is '
                    . 'scheduled, and no enumeration becomes possible. Every other gate still '
                    . 'applies — the validation driver must be registered and enabled, its provider '
                    . 'configured, and on EVM the chain must be inside the approved launch scope.'];

            case NftCapabilityEditor::RESULT_MANUAL_DISABLED:
                return ['type' => 'success', 'message' =>
                    'The manual intake permission was withdrawn for that chain. No administrator can '
                    . 'submit a contract on it. Existing collections were kept.'];

            case NftCapabilityEditor::RESULT_MANUAL_NOOP_ENABLED:
                return ['type' => 'info', 'message' =>
                    'That chain already permitted manual collection intake. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_NOOP_DISABLED:
                return ['type' => 'info', 'message' =>
                    'That chain did not permit operator-started discovery. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_NO_PRODUCT:
                return ['type' => 'warning', 'message' =>
                    'The manual permission was refused because BCC product support for NFT collections '
                    . 'is currently OFF for that chain. Grant product support first — it is a separate '
                    . 'decision, and it starts nothing on its own. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_NO_STARTABLE:
                return ['type' => 'warning', 'message' =>
                    'The manual permission was refused: no driver in this build can perform an '
                    . 'administrator-started operation on that chain, so the permission could not '
                    . 'authorise anything. This is a structural limit, not a configuration gap — no '
                    . 'credential or setting adds chain-wide enumeration to EVM or Solana. Nothing was '
                    . 'changed.'];

            case NftCapabilityEditor::RESULT_MANUAL_WRITE_FAILED:
                return ['type' => 'error', 'message' =>
                    'The manual permission could NOT be changed — the database write failed and nothing '
                    . 'was changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_MANUAL_UNVERIFIED:
                return ['type' => 'error', 'message' =>
                    'The permission change was attempted but could NOT be confirmed: the stored value '
                    . 'does not read back as expected, so this is not being reported as done. Nothing '
                    . 'was started. Reload to see the current state and check the bcc-trust error log.'];

            // ── Driver overrides ────────────────────────────────────────
            case NftCapabilityEditor::RESULT_OVERRIDE_DISABLED:
                return ['type' => 'success', 'message' =>
                    'That driver is now switched OFF for that operation on that chain. The capability '
                    . 'table above has been rebuilt from the stored state — if it was the only driver, '
                    . 'the operation now reads Disabled.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_ENABLED:
                return ['type' => 'success', 'message' =>
                    'That driver is switched ON for that operation at the priority you set (lower runs '
                    . 'first). An override can only restore or reorder a driver the code already offers '
                    . '— it cannot add one, and it does not make an unconfigured provider ready.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_INHERITED:
                return ['type' => 'success', 'message' =>
                    'The override row was removed, so that driver follows the code registry again — '
                    . 'including its priority, now and after any future change to it.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_NOOP:
                return ['type' => 'info', 'message' =>
                    'That driver was already in the state you asked for. Nothing was changed and no '
                    . 'row was written.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_UNREADABLE:
                return ['type' => 'error', 'message' =>
                    'That chain\'s driver-override rows could not be established — the read failed, a '
                    . 'row is malformed, or there are more rows than can be read at once. No override '
                    . 'may be changed while the stored set is unknown, because a change applied to a '
                    . 'set we only partly read could silently drop another restriction. Nothing was '
                    . 'changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE:
                return ['type' => 'error', 'message' =>
                    'That combination of chain, operation and driver is not one this build offers, so '
                    . 'no override was written. Configuration can narrow or reorder what the code '
                    . 'declares; it can never add a capability. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_INVALID_PRIORITY:
                return ['type' => 'error', 'message' =>
                    'That priority is outside the accepted range of 0–1000, so nothing was written. It '
                    . 'was refused rather than adjusted — storing a number you did not choose would be '
                    . 'an ordering nobody decided on.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_WRITE_FAILED:
                return ['type' => 'error', 'message' =>
                    'The driver override could NOT be saved — the database write failed and nothing was '
                    . 'changed. Check the bcc-trust error log.'];

            case NftCapabilityEditor::RESULT_OVERRIDE_UNVERIFIED:
                return ['type' => 'error', 'message' =>
                    'The driver override was attempted but could NOT be confirmed: the stored rows do '
                    . 'not read back as expected, so this is not being reported as done. Caches were '
                    . 'invalidated in case the write did land. Reload to see the current state and '
                    . 'check the bcc-trust error log.'];

            // ── Stale rows ──────────────────────────────────────────────
            case NftCapabilityEditor::RESULT_STALE_REMOVED:
                return ['type' => 'success', 'message' =>
                    'That leftover override row was removed. It was already inert — this build discards '
                    . 'rows it does not recognise at every read — so nothing was enabled, nothing was '
                    . 'granted, and no capability changed. Only the row is gone.'];

            case NftCapabilityEditor::RESULT_STALE_NOT_FOUND:
                return ['type' => 'info', 'message' =>
                    'There is no such override row on that chain. Nothing was changed — it may already '
                    . 'have been removed.'];

            case NftCapabilityEditor::RESULT_STALE_STILL_VALID:
                return ['type' => 'warning', 'message' =>
                    'That row is NOT a leftover — this build still recognises that driver for that '
                    . 'operation on that chain, so it was not removed here. Use "Use code default" on '
                    . 'the driver itself to return it to the registry. Nothing was changed.'];

            // ── Shared ──────────────────────────────────────────────────
            case NftCapabilityEditor::RESULT_UNKNOWN_CHAIN:
                return ['type' => 'error', 'message' =>
                    'Capability: chain not found. Nothing was changed.'];

            case NftCapabilityEditor::RESULT_COLUMN_ABSENT:
                return ['type' => 'error', 'message' =>
                    'This install cannot store that capability value — the column is absent from the '
                    . 'chain projection, which means the migration has not run here. Nothing was '
                    . 'changed, and nothing was assumed about the chain.'];

            case self::CAP_RESULT_ERROR:
                // No reference in the URL by design — the correlation id is
                // in the file log beside the durable audit row.
                return ['type' => 'error', 'message' =>
                    'Capability: the change could not be completed, and nothing was started. The full '
                    . 'error is in the bcc-trust log.'];
        }

        return null;
    }

    /**
     * The capability matrix: one row per chain, one column per operation.
     *
     * ── A PURE PRINTER ──────────────────────────────────────────────────
     * Every status word, reason sentence and driver name below arrived in
     * `$snapshot`. This method consults no repository, no registry, no
     * readiness check and no environment. That is what makes the "one
     * status authority" claim testable rather than asserted: feed it rows
     * carrying distinctive values and they come out unchanged, and a
     * capability model wired to throw is never reached.
     *
     * @param array<string, mixed> $snapshot
     */
    private static function render_capability_matrix(array $snapshot, ?int $selectedId = null): void
    {
        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($snapshot['chains'] ?? null) ? $snapshot['chains'] : [];
        $operations = NftDriverRegistry::operations();
        $family     = (string) ($snapshot['family'] ?? NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY);
        ?>
        <h2>Capability by chain</h2>

        <?php if ($chains === []): ?>
            <p><em>No chains of this family are registered.</em></p>
            <p style="color:#646970;">
                This says nothing about capability — it means the chains table returned no row with
                this <code>chain_type</code>.
            </p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:170px;">Chain</th>
                    <?php foreach ($operations as $operation): ?>
                        <th><?php echo esc_html(self::operation_label($operation)); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chains as $chain): ?>
                    <?php self::render_capability_row($chain, $operations, $family, $selectedId); ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="color:#646970;margin-top:12px;">
            <strong>Ready</strong> means a registered driver for that operation is enabled and
            configured. It does not mean anything is running — nothing on this page runs on a
            schedule.
        </p>
        <?php
    }

    /**
     * One chain's row. Prints what it is handed; derives nothing.
     *
     * @param array<string, mixed> $chain
     * @param list<string>         $operations
     */
    private static function render_capability_row(
        array $chain,
        array $operations,
        string $family = NftDiscoveryControlPlaneSnapshot::DEFAULT_FAMILY,
        ?int $selectedId = null
    ): void {
        $chainId = (int) ($chain['chain_id'] ?? 0);
        $slug    = (string) ($chain['slug'] ?? '');
        $name    = (string) ($chain['name'] ?? $slug);
        $ops     = is_array($chain['operations'] ?? null) ? $chain['operations'] : [];
        $isOpen  = $selectedId !== null && $selectedId === $chainId;
        ?>
        <tr<?php echo $isOpen ? ' style="outline:2px solid #2271b1;"' : ''; ?>>
            <td>
                <strong><?php echo esc_html($name); ?></strong><br>
                <code><?php echo esc_html($slug); ?></code>
                <span style="color:#646970;font-size:11px;">#<?php echo $chainId; ?></span>
                <?php if (($chain['is_active'] ?? true) !== true): ?>
                    <div style="color:#dba617;font-size:11px;">deactivated</div>
                <?php endif; ?>
                <?php if (($chain['is_testnet'] ?? false) === true): ?>
                    <div style="color:#646970;font-size:11px;">testnet</div>
                <?php endif; ?>
                <?php if ($chainId > 0): ?>
                    <div style="margin-top:6px;">
                        <?php if ($isOpen): ?>
                            <strong style="font-size:11px;color:#2271b1;">editing below</strong>
                        <?php else: ?>
                            <a style="font-size:11px;" href="<?php echo esc_url(add_query_arg(
                                ['page' => self::PAGE_SLUG, 'family' => $family, 'chain' => $chainId],
                                admin_url('admin.php')
                            )); ?>">Edit capability</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
            <?php foreach ($operations as $operation): ?>
                <?php
                $op = is_array($ops[$operation] ?? null) ? $ops[$operation] : [];
                self::render_capability_cell($op);
                ?>
            <?php endforeach; ?>
        </tr>
        <?php
    }

    /**
     * One (chain, operation) cell: the status, why, and which driver.
     *
     * @param array<string, mixed> $op
     */
    private static function render_capability_cell(array $op): void
    {
        $status = is_string($op['status'] ?? null) ? (string) $op['status'] : NftChainCapability::OP_UNKNOWN;
        $reason = is_string($op['reason'] ?? null) ? (string) $op['reason'] : '';

        /** @var list<string> $drivers */
        $drivers = is_array($op['drivers'] ?? null) ? $op['drivers'] : [];
        /** @var list<string> $ready */
        $ready = is_array($op['ready'] ?? null) ? $op['ready'] : [];
        /** @var array<string, array<string, mixed>> $refusals */
        $refusals = is_array($op['endpoint_refusals'] ?? null) ? $op['endpoint_refusals'] : [];
        ?>
        <td style="vertical-align:top;">
            <span style="font-weight:600;color:<?php echo esc_attr(self::status_colour($status)); ?>;">
                <?php echo esc_html(self::status_label($status)); ?>
            </span>
            <div style="font-size:11px;color:#646970;margin-top:2px;">
                <?php echo esc_html(self::reason_sentence($status, $reason)); ?>
            </div>

            <?php if ($drivers !== []): ?>
                <div style="font-size:11px;margin-top:4px;">
                    <?php foreach ($drivers as $driver): ?>
                        <?php $isReady = in_array($driver, $ready, true); ?>
                        <div>
                            <code><?php echo esc_html($driver); ?></code>
                            <span style="color:<?php echo $isReady ? '#00a32a' : '#d63638'; ?>;">
                                <?php echo $isReady ? '✓' : '✗'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($refusals as $driver => $refusal): ?>
                <div style="font-size:11px;color:#d63638;margin-top:4px;">
                    <?php
                    // ⚠ `endpoint_display` is `scheme://host` — `endpointRefusal()`
                    // re-describes it on the way out and never exports the
                    // comparison identity, so there is no path or query here to
                    // carry a credential.
                    //
                    // The predecessor field, `rpc_url`, was a query-only
                    // redaction, and its comment here asserted that made it
                    // key-free. It did not: Alchemy-shaped endpoints keep the key
                    // in the PATH, and this line rendered it.
                    //
                    // The provider MESSAGE is upstream text and gets the
                    // operator-safe excerpt on the way out — esc_html() stops
                    // markup executing and does nothing about a credentialed URL
                    // or an absolute path.
                    $endpoint = isset($refusal['endpoint_display']) ? (string) $refusal['endpoint_display'] : '';
                    $message  = isset($refusal['message']) ? (string) $refusal['message'] : '';
                    ?>
                    <strong><?php echo esc_html((string) $driver); ?></strong>: this endpoint has already
                    answered that it cannot serve DAS.
                    <?php if ($endpoint !== ''): ?>
                        <div><code><?php echo esc_html($endpoint); ?></code></div>
                    <?php endif; ?>
                    <?php if ($message !== ''): ?>
                        <div><?php echo esc_html(AdminActionSupport::operatorSafeExcerpt($message)); ?></div>
                    <?php endif; ?>
                    <div style="color:#646970;">
                        Point this chain's RPC URL at a DAS-capable endpoint to clear it.
                    </div>
                </div>
            <?php endforeach; ?>
        </td>
        <?php
    }

    /** PURE. The column heading for one operation. */
    private static function operation_label(string $operation): string
    {
        switch ($operation) {
            case NftDriverRegistry::OP_ENUMERATION:
                return 'Chain enumeration';
            case NftDriverRegistry::OP_CURATED_FEED:
                return 'Curated feed';
            case NftDriverRegistry::OP_WALLET_DISCOVERY:
                return 'Wallet discovery';
            case NftDriverRegistry::OP_VALIDATION:
                return 'Validation';
            case NftDriverRegistry::OP_METADATA:
                return 'Metadata';
            case NftDriverRegistry::OP_OWNERSHIP:
                return 'Ownership';
        }

        return $operation;
    }

    /** PURE. The one-word status an operator reads first. */
    private static function status_label(string $status): string
    {
        switch ($status) {
            case NftChainCapability::OP_READY:
                return 'Ready';
            case NftChainCapability::OP_UNKNOWN:
                return 'Unknown';
            case NftChainCapability::OP_NO_BCC_SUPPORT:
                return 'Not supported';
            case NftChainCapability::OP_NO_DRIVER:
                return 'No driver';
            case NftChainCapability::OP_DISABLED:
                return 'Disabled';
            case NftChainCapability::OP_MANUAL_DISABLED:
                return 'Not permitted';
            case NftChainCapability::OP_PROVIDER_UNAVAILABLE:
                return 'Not configured';
        }

        // An unrecognised status is shown as Unknown rather than printed
        // raw: a value from a newer build must never read as a permission.
        return 'Unknown';
    }

    /** PURE. Colour is a hint; the words carry the meaning. */
    private static function status_colour(string $status): string
    {
        switch ($status) {
            case NftChainCapability::OP_READY:
                return '#00a32a';
            case NftChainCapability::OP_PROVIDER_UNAVAILABLE:
            case NftChainCapability::OP_MANUAL_DISABLED:
            case NftChainCapability::OP_DISABLED:
                return '#dba617';
            case NftChainCapability::OP_UNKNOWN:
                return '#d63638';
        }

        return '#646970';
    }

    /**
     * PURE. The sentence under the status.
     *
     * The load-bearing half of this page. "No driver" and "not configured"
     * look similar and send an operator to completely different places, so
     * each one says what would actually change it — including when the
     * honest answer is that nothing would.
     */
    private static function reason_sentence(string $status, string $reason): string
    {
        // The override-store reasons carry their own sub-code.
        if (str_starts_with($reason, NftChainCapability::REASON_OVERRIDES_UNAVAILABLE)) {
            $detail = substr($reason, strlen(NftChainCapability::REASON_OVERRIDES_UNAVAILABLE) + 1);

            switch ($detail) {
                case 'overflow':
                    return 'The driver-override table returned more rows than can be read at once for '
                        . 'this chain, so the set we have may be a subset. Applying part of it could '
                        . 'honour some restrictions and silently drop others, so nothing is claimed.';
                case 'read_failed':
                    return 'The driver-override table could not be read, so what the operator '
                        . 'configured is unknown and no capability is claimed.';
                case 'malformed':
                    return 'A driver-override row for this chain is malformed, so the override set '
                        . 'cannot be trusted and no capability is claimed.';
                case 'invalid_chain':
                    return 'This chain id could not be used to read driver overrides.';
            }

            return 'The driver overrides for this chain could not be established, so no capability '
                . 'is claimed.';
        }

        switch ($reason) {
            case NftChainCapability::REASON_PRODUCT_COLUMN_ABSENT:
                return 'This install cannot store whether BCC supports NFT collections on this chain '
                    . '(the column is absent from the projection), so nothing can be said yet.';
            case NftChainCapability::REASON_MANUAL_COLUMN_ABSENT:
                return 'This install cannot store the manual-discovery permission (the column is '
                    . 'absent from the projection), so nothing can be said yet.';
            case NftChainCapability::REASON_PRODUCT_SUPPORT_DISABLED:
                return 'BCC does not currently support NFT collections on this chain. This is a '
                    . 'product decision, not a technical limit, and it is not editable from this page.';
            case NftChainCapability::REASON_NO_REGISTERED_DRIVER:
                return 'No driver in this build performs this operation on this chain family, on any '
                    . 'configuration. Credentials would not change it.';
            case NftChainCapability::REASON_ALL_DRIVERS_DISABLED:
                return 'A driver exists for this operation and every one of them has been switched '
                    . 'off by a driver-override row.';
            case NftChainCapability::REASON_MANUAL_PERMISSION_DISABLED:
                return 'Operator-started discovery is not permitted on this chain. The permission is '
                    . 'read-only in this build.';
            case NftChainCapability::REASON_NO_READY_DRIVER:
                return 'A driver exists and is enabled, but nothing is configured to run it — a '
                    . 'missing endpoint or credential.';
            case NftChainCapability::REASON_READY:
                return 'A registered driver is enabled and configured.';
        }

        return 'No further detail is available.';
    }

    /**
     * Wallet-linked refresh, described and NOT offered as a control.
     *
     * ── READ-ONLY ON PURPOSE ────────────────────────────────────────────
     * This method exists and runs today on its own schedule. Putting a
     * button on it here would create a NEW discovery entry point, and every
     * gate the surrounding machinery applies upstream would have to be
     * re-established on it — which is exactly the mistake that had to be
     * fixed when the automatic collection sweep was retired and the admin
     * backfill silently became the only way in.
     *
     * So this section explains the method and stops. No form, no route, no
     * trigger.
     *
     * ── IT ALSO DOES NOT CLAIM A LAST-RUN TIME ──────────────────────────
     * Nothing here reads the scheduler. A "last run" field would need a
     * cron introspection call this page deliberately does not make, and a
     * field showing an unverified timestamp is worse than no field.
     *
     * @param array<string, mixed> $snapshot
     */
    private static function render_wallet_refresh_method(array $snapshot): void
    {
        ?>
        <h2>Wallet-linked refresh <span style="font-weight:400;color:#646970;">— a separate method</span></h2>

        <p style="color:#646970;max-width:900px;">
            When a member links a wallet, the collections that wallet holds can be read back from the
            provider and recorded. That is <strong>not</strong> chain discovery: it finds only what a
            linked wallet happens to hold, so a collection nobody on this platform owns is invisible
            to it.
        </p>

        <p style="color:#646970;max-width:900px;">
            It runs on its own existing schedule and is not started from this page. Anything it
            records arrives <strong>unverified</strong>, exactly like anything the CosmWasm engine
            finds, and it verifies nothing and creates no community.
        </p>
        <?php
    }
    // ── PR 6: the one manual Add Collection entry point ─────────────────

    /**
     * @var list<string> ADD_REDIRECT_KEYS the only keys this destination may carry
     *
     * Same allowlist discipline as the capability and operation redirects.
     * Deliberately carries NO chain id: the result copy is family-scoped, and
     * a chain id in the URL is a target an operator can edit by hand into an
     * action they were never shown.
     */
    public const ADD_REDIRECT_KEYS = ['page', 'family', 'bcc_nftadd'];

    /**
     * Request boundary for the manual Add Collection route.
     *
     * Ordering, identical to the capability routes: capability → POST →
     * shape → scoped nonce → domain. The chain id is part of the nonce
     * action, so it has to be READ before the nonce can be verified — but
     * nothing is looked up, contacted or written until the nonce has proven
     * the request authentic.
     */
    public static function handle_add_collection(): void
    {
        if (!current_user_can('manage_options')) {
            // The trace carries nothing from the request: `chain_id` is
            // attacker-controlled and unvalidated here, so echoing it would
            // let an unauthenticated caller write our log.
            \BCC\Core\Log\Logger::warning('[bcc-trust] manual collection add refused', [
                'action'   => 'nft_manual_add_denied',
                'operator' => get_current_user_id(),
            ]);
        }

        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId = self::require_chain_id_shape();

        AdminActionSupport::requireNonce(self::ACTION_ADD_COLLECTION . '_' . $chainId);

        $family = self::current_family();

        $identifier = isset($_POST['bcc_nftd_identifier']) && is_scalar($_POST['bcc_nftd_identifier'])
            ? trim(sanitize_text_field((string) $_POST['bcc_nftd_identifier']))
            : '';

        try {
            $result = (new ManualCollectionIntakeService())->add(
                $family,
                $chainId,
                $identifier,
                get_current_user_id()
            );
        } catch (\Throwable $e) {
            AdminActionSupport::failure($e, 'admin_nftd_collection_add_refused', 'chain', $chainId);
            self::redirect_add(ManualCollectionIntakeService::REFUSED_WRITE_FAILED);
        }

        self::redirect_add(
            $result['ok'] === true
                ? 'added'
                : (string) ($result['reason'] ?? ManualCollectionIntakeService::REFUSED_WRITE_FAILED)
        );
    }

    private static function redirect_add(string $result): never
    {
        AdminActionSupport::redirect([
            'page'       => self::PAGE_SLUG,
            'family'     => self::current_family(),
            'bcc_nftadd' => $result,
        ]);
    }

    /**
     * Render the chain-locked Add Collection form for one family.
     *
     * ── WHY THE FORM IS RENDERED EVEN WHEN IT CANNOT SUCCEED ────────────
     * Every chain on this install currently has both capability flags off,
     * so on most chains this control is refused. It still renders, with the
     * specific flag named and a link to the editor that sets it — because
     * an absent control tells an operator nothing, and a control that fails
     * with "not permitted" tells them exactly which switch to find.
     *
     * ── ZERO PROVIDER CALLS HERE ────────────────────────────────────────
     * Everything below is drawn from the snapshot the page already built.
     * Nothing is fetched, probed, or asked of a chain to draw this form.
     *
     * The bounded validation happens on SUBMIT, and since PR E it covers
     * Cosmos (CW-721 `contract_info`), the approved EVM launch chains
     * (ERC-165 `supportsInterface`) and Solana (a verified DAS collection
     * group, plus the cNFT exclusion). It is asked PER CHAIN, from
     * `manual_intake` on the snapshot row — an earlier version of this note
     * said Cosmos only, which stopped being true when PR E registered
     * OP_VALIDATION for `evm_rpc` and `das_helius`.
     *
     * @param list<array<string, mixed>> $chains snapshot rows of the current family
     */
    private static function render_add_collection(string $family, array $chains): void
    {
        // Chains that can actually take an add today. The form still renders
        // when this is empty — with an explanation, not silence.
        //
        // ⚠ `bcc_supports` and `manual_enabled` are bool|NULL. Null means the
        // column could not be read, which is NOT the same as false and must
        // not be treated as true: `=== true` is the only correct test, and it
        // is what makes an unreadable capability store fail closed here
        // rather than opening every chain in the family.
        $eligible = [];
        foreach ($chains as $row) {
            if (($row['is_active'] ?? false) !== true) {
                continue;
            }
            if (($row['bcc_supports'] ?? null) !== true) {
                continue;
            }
            if (($row['manual_enabled'] ?? null) !== true) {
                continue;
            }
            $eligible[] = $row;
        }

        // ⚠ ASKED PER CHAIN, NOT PER FAMILY. Validation is no longer a family
        // property: the EVM validator works on any EVM RPC, but DECISION 7
        // approves only Ethereum and Base, so two chains in the same family can
        // legitimately differ. A single family-wide answer would tell a Polygon
        // operator their submission is validated, or an Ethereum operator that
        // it is not.
        // `manual_intake` is put on the row by NftDiscoveryControlPlaneSnapshot
        // from NftChainCapability::canTakeManualIntake() — the same predicate
        // the capability editor grants on, so the banner and the grant cannot
        // disagree. ⚠ `!== true` because the key is bool|null and an
        // unreadable capability must not read as validated.
        $validatedCount = 0;
        foreach ($eligible as $row) {
            if (($row['manual_intake'] ?? null) === true) {
                $validatedCount++;
            }
        }
        $allValidated  = $eligible !== [] && $validatedCount === count($eligible);
        $noneValidated = $validatedCount === 0;

        ?>
        <h2 style="margin-top:32px;">Add a collection</h2>
        <p style="color:#646970;max-width:60em;">
            Manual intake for a collection that discovery cannot reach. The chain you
            pick is authoritative: the form is bound to it, and a submission whose
            chain does not belong to this family is refused.
            The new row lands <strong>unverified</strong>, with <strong>no community</strong>,
            and enabling neither. Verifying it later is a separate decision, and so is
            requesting its community.
        </p>

        <?php if ($allValidated): ?>
            <p style="color:#646970;max-width:60em;">
                The submitted contract is <strong>checked against the chain</strong> before the
                row is written — a CW-721 <code>contract_info</code> probe on Cosmos, an
                ERC-165 <code>supportsInterface</code> call on EVM, or a verified
                collection-group lookup on Solana. A contract that does not answer is
                refused as <em>could not confirm</em>, which is not the same as
                <em>not an NFT collection</em>, and nothing is written either way.
            </p>
        <?php elseif (!$noneValidated): ?>
            <p style="max-width:60em;padding:8px 12px;background:#fcf9e8;border-left:4px solid #dba617;">
                <strong>Validation differs by chain here.</strong>
                <?php echo (int) $validatedCount; ?> of <?php echo count($eligible); ?>
                chains below check the contract against the chain before writing the row;
                the rest accept the identifier on shape alone. On EVM only the approved
                launch chains are validated.
            </p>
        <?php else: ?>
            <p style="max-width:60em;padding:8px 12px;background:#fcf9e8;border-left:4px solid #dba617;">
                <strong>Not validated on the chains listed here.</strong> Nothing below proves the
                address is an NFT contract — no chain in this list has a validation driver
                BCC supports. The identifier is checked for shape and canonical form
                only. A valid address is not a verified collection.
            </p>
        <?php endif; ?>

        <?php if ($eligible === []): ?>
            <p style="max-width:60em;padding:8px 12px;background:#f6f7f7;border-left:4px solid #72aee6;">
                No chain in this family can take a manual collection yet. A chain needs
                <strong>product support</strong> and <strong>manual collection discovery</strong>
                both enabled. Set them per chain in the capability editor on this page.
            </p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="margin:8px 0 24px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADD_COLLECTION); ?>">
                <input type="hidden" name="family" value="<?php echo esc_attr($family); ?>">

                <label style="display:flex;flex-direction:column;font-size:12px;">
                    Chain
                    <select name="chain_id" id="bcc-nftd-add-chain" required
                            style="min-width:200px;"
                            onchange="(function(s){var f=s.form;var n=f.querySelector('input[name=_wpnonce]');if(n){n.value=s.options[s.selectedIndex].dataset.nonce||'';}})(this)">
                        <?php foreach ($eligible as $row): ?>
                            <option value="<?php echo (int) $row['chain_id']; ?>"
                                    data-nonce="<?php
                                        // ⚠ ONE NONCE PER CHAIN, minted here.
                                        //
                                        // The nonce action is bound to the chain
                                        // (`<route>_<chainId>`), so a single form-wide
                                        // nonce could not be verified for whichever
                                        // chain the operator picks. Each option carries
                                        // its own, and changing the select swaps the
                                        // hidden field. A nonce minted for chain 8
                                        // therefore authorises an add on chain 8 and
                                        // nothing else — including no other route.
                                        echo esc_attr(wp_create_nonce(self::ACTION_ADD_COLLECTION . '_' . (int) $row['chain_id']));
                                    ?>"
                                    >
                                <?php echo esc_html((string) ($row['name'] ?? $row['slug'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <input type="hidden" name="_wpnonce"
                       value="<?php echo esc_attr(wp_create_nonce(self::ACTION_ADD_COLLECTION . '_' . (int) $eligible[0]['chain_id'])); ?>">

                <label style="display:flex;flex-direction:column;font-size:12px;flex:1;min-width:300px;">
                    <?php echo esc_html(self::identifier_label($family)); ?>
                    <input type="text"
                           name="bcc_nftd_identifier"
                           required
                           spellcheck="false"
                           autocomplete="off"
                           placeholder="<?php echo esc_attr(self::identifier_placeholder($family)); ?>"
                           style="font-family:monospace;font-size:12px;">
                </label>

                <button type="submit" class="button button-primary">Add collection</button>
            </form>
        <?php endif; ?>
        <?php
    }

    private static function identifier_label(string $family): string
    {
        switch ($family) {
            case 'cosmos':
                return 'CW-721 contract address';
            case 'evm':
                return 'Contract address (0x…)';
            case 'solana':
                return 'Collection mint address';
            default:
                return 'Collection identifier';
        }
    }

    private static function identifier_placeholder(string $family): string
    {
        switch ($family) {
            case 'cosmos':
                return 'cosmos1… / inj1… / juno1…';
            case 'evm':
                return '0x0000000000000000000000000000000000000000';
            case 'solana':
                return 'base58 mint — case is preserved exactly';
            default:
                return '';
        }
    }

    /** Operator notice for the Add Collection PRG result. */
    private static function add_result_notice(string $result): void
    {
        if ($result === '') {
            return;
        }

        if ($result === 'added') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Collection added. It is <strong>unverified</strong> and has
                <strong>no community</strong> — both are separate decisions, taken on
                the Verify Collections page.</p>
            </div>
            <?php
            return;
        }

        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html(ManualCollectionIntakeService::refusalMessage($result)); ?></p>
        </div>
        <?php
    }

}
