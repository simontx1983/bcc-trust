<?php
/**
 * Admin "Verify Collections" page.
 *
 * Submenu under bcc-trust-dashboard. Lists collections from
 * wp_bcc_onchain_collections (paginated, ordered by holder count) with
 * an `is_verified` checkbox per row. Submitting the form persists the
 * flag changes, then the daily provisioning sweep auto-creates a
 * closed PeepSo group for each newly-verified collection.
 *
 * "Provision now" button triggers GatedGroupProvisioningService::provisionAll
 * immediately for admin testing without waiting for cron.
 *
 * @package BCC\Trust\Onchain\Admin
 */

namespace BCC\Trust\Onchain\Admin;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Onchain\OnchainPlugin;
// ── S4: SIX IMPORTS WENT WITH THE SCANNER PANELS ────────────────────────
//
// CosmwasmScannerPanel, DiscoveryScanPanel, DiscoveryReadiness,
// CosmwasmContractRepository, CosmwasmCodeFamilyRepository and
// CosmwasmDiscoveryHealthSnapshot were all reachable only from the two
// withdrawn panels and the data gathering that fed them. This page no longer
// names the scanner in executable code at all.
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\CollectionDemandService;
use BCC\Trust\Onchain\Services\CollectionStateClassifier;
use BCC\Trust\Onchain\Services\CommunityRequestService;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\ValueObjects\ChainDescriptionState;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;

if (!defined('ABSPATH')) {
    exit;
}

final class VerifyCollectionsPage
{
    public const PAGE_SLUG  = 'bcc-verify-collections';

    /*
     * `bcc_verify_collections_nonce` / `_bcc_vc_nonce` USED TO LIVE HERE.
     *
     * One nonce action authorised fourteen different operations, from a
     * read-only CW-721 probe to a hard delete to a chain-wide backfill.
     * VC-A, VC-B1, VC-B2 and VC-B3b moved every one of those onto its own
     * admin-post route with its own action-scoped nonce, and VC-B3b removed
     * the last thing that verified the shared token. The constants are gone
     * with it — leaving them would let a future control reach for a
     * ready-made nonce whose whole history is excessive authority.
     *
     * The string is still named in the test suite, only ever as the token a
     * route must REFUSE.
     */

    /**
     * Separate nonce for the inline AJAX actions (instant verify toggle,
     * per-row community create). Kept distinct from the form-POST nonce
     * so the two flows don't interfere — mirrors ChainsPage's pattern.
     */
    public const AJAX_NONCE_KEY    = 'bcc_vc_ajax_nonce';
    public const AJAX_ACTION_TOGGLE  = 'bcc_vc_toggle_verified';
    public const AJAX_ACTION_PROVISION = 'bcc_vc_provision_one';

    /**
     * VC-A admin-post routes (batch: Verify Collections handler hardening).
     *
     * Each of these was previously an action BRANCH inside handlePost(),
     * reached after ONE `bcc_verify_collections_nonce` check that covered
     * fourteen different operations — so a nonce minted by the read-only
     * "Test CW-721" button also authorised a hard delete. Each is now its
     * own admin-post route with its own nonce action, and the per-row ones
     * bind that nonce to the collection id as well.
     *
     * The nonce action string IS the route name (per-target routes append
     * `_<collectionId>`), so a route can never verify a nonce minted for a
     * different operation.
     */
    public const ACTION_SAVE       = 'bcc_vc_save';
    public const ACTION_PROVISION  = 'bcc_vc_provision';
    public const ACTION_DELETE     = 'bcc_vc_delete';
    public const ACTION_TESTQUERY  = 'bcc_vc_testquery';

    /**
     * PR 6 per-row community intent.
     *
     * Request and Withdraw are two routes for the same reason Hide and
     * Unhide are: a single "toggle" decides its direction from the state the
     * page had at RENDER time, so a stale tab or a double-submit applies the
     * opposite of what the operator is looking at. Here that would mean
     * withdrawing a request by clicking a button labelled "Request".
     *
     * RETRY of a failed provisioning is ACTION_REQUEST_COMMUNITY, not a third
     * route — `failed -> requested` is the same transition with the same
     * guards, and a second mutation path is a second place for those guards
     * to drift.
     */
    public const ACTION_REQUEST_COMMUNITY  = 'bcc_vc_request_community';
    public const ACTION_WITHDRAW_REQUEST   = 'bcc_vc_withdraw_request';

    /**
     * RETIRED in PR 6: `bcc_vc_add_collection` and `bcc_vc_add_cosmos`.
     *
     * They were two divergent manual-intake forms on this page — one over
     * every active chain with NO on-chain validation for EVM or Solana, one
     * cosmos-only that always validated; one writing `source='manual'` via
     * `addManual()`, the other writing a discovery-sourced row via
     * `bulkUpsert()` so the "Manual" badge never appeared. Both are replaced
     * by ONE chain-locked entry point in the NFT Discovery control plane
     * ({@see \BCC\Trust\Onchain\Admin\NftDiscoveryPage}). Neither action is
     * registered any more, so a stale bookmark or replayed form POST reaches
     * no handler at all.
     */

    /**
     * VC-B1 admin-post routes: per-row Hide / Unhide.
     *
     * Hide and Unhide are DELIBERATELY two routes rather than one "toggle",
     * for the same reason the CosmWasm discovery control is two actions: a
     * toggle decides its direction from the state the page had at RENDER
     * time, so a stale tab or a double-submit silently applies the opposite
     * of what the operator is looking at. Here that would mean un-hiding a
     * scam contract by clicking a button labelled "Hide".
     *
     * Two routes also make the nonce meaningful. Because the nonce action is
     * `<route>_<collectionId>`, a Hide nonce for collection 7 cannot drive
     * Unhide on 7, Hide on 8, any VC-A route, or any remaining `cw_*` branch.
     */
    public const ACTION_HIDE   = 'bcc_vc_hide';
    public const ACTION_UNHIDE = 'bcc_vc_unhide';

    /**
     * PR E — NFT Collection Description review.
     *
     * ⚠ THIS IS THE MISSING REVIEW CONTROL. `importChainDescription()` writes
     * a description as `pending` and `setChainDescriptionState()` can move it
     * out of review, but until PR E **nothing in production called the
     * latter** — so an imported description could be stored and could never be
     * reviewed. A review state with no reviewer is a queue that only fills up.
     *
     * ── ⚠⚠⚠ APPROVAL DOES NOT PUBLISH ANYTHING (DECISION 17, settled) ───
     * "Approved" is a REVIEW OUTCOME, not publication. During scanner
     * retirement the NFT Collection Description is **stored and reviewed, not
     * published**: it gets no public REST field, no view-model field, and no
     * place in any picker, stance panel or Hall preview. **The admin review
     * interface is the reader.**
     *
     * `findApprovedChainDescription()` therefore has no public production
     * consumer ON PURPOSE. Public display waits for a dedicated
     * collection-detail surface where the text can be attributed to the
     * project or on-chain provider and carry a clear statement that BCC does
     * not endorse it. The plan is explicit that a public reader is not to be
     * built merely to stop the field looking unused.
     *
     * ⚠⚠ THIS IS NOT THE COMMUNITY DESCRIPTION, and never becomes one. That
     * text is BCC's own voice and belongs to PR G; nothing here writes a
     * PeepSo group description.
     */
    public const ACTION_DESC_APPROVE = 'bcc_vc_desc_approve';
    public const ACTION_DESC_REJECT  = 'bcc_vc_desc_reject';

    /** Audit actions for the description review decision. */
    public const AUDIT_DESC_APPROVED = 'admin_vc_chain_description_approved';
    public const AUDIT_DESC_REJECTED = 'admin_vc_chain_description_rejected';

    /**
     * Maximum collection ids accepted from one bulk-save submission.
     *
     * Not an arbitrary limit: listForAdminVerification() is called with a
     * per-page of 50, so 50 is exactly how many `known[]` checkboxes one
     * rendered page can legitimately submit. An oversized payload is
     * REJECTED rather than truncated — silently dropping ids would leave
     * the operator believing rows were saved that were not.
     */
    public const MAX_BULK_IDS = 50;

    /** Operator-scoped, short-lived carrier for PRG result notices. */
    private const NOTICE_TRANSIENT_PREFIX = 'bcc_vc_notices_';
    private const NOTICE_TTL              = 60;

    /**
     * Chain slugs surfaced as quick-filter pills above the dropdown.
     * Pills only render if the slug also appears in the active chains
     * registry — a missing/disabled chain silently drops its pill
     * rather than producing a broken link.
     *
     * Filterable via `bcc_verify_collections_pill_chains` for future
     * tuning without code changes.
     *
     * @var list<string>
     */
    private const PILL_CHAIN_SLUGS = ['ethereum', 'solana', 'cosmos'];

    /**
     * Column count of the verification table.
     *
     * The CosmWasm candidate detail renders as a full-width sub-row under
     * its collection, so it needs the same colspan the "no rows" cell
     * uses. Naming it once stops the two drifting apart the next time a
     * column is added.
     */
    private const TABLE_COLSPAN = 12;

    /*
     * FORCE_RETRY_LIMIT, ADMIN_BACKFILL_REQUESTS and ADMIN_BACKFILL_SECONDS
     * moved to ChainsPage in VC-B3b, together with the four scanner controls
     * that were the only things reading them. Their reasoning moved with
     * them — a bound documented next to a page that can no longer apply it
     * is worse than no comment at all.
     */

    public static function register_page(): void
    {
        // Audit follow-up: relocated under BCC System alongside the
        // other onchain admin pages. Page slug unchanged.
        add_submenu_page(
            'bcc-system-health',
            'Verify Collections',
            'Verify Collections',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Register the inline AJAX handlers. Called from the plugin
     * bootstrap alongside ChainsPage::register_ajax().
     */
    public static function register_ajax(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION_TOGGLE, [__CLASS__, 'ajax_toggle_verified']);
        add_action('wp_ajax_' . self::AJAX_ACTION_PROVISION, [__CLASS__, 'ajax_provision_one']);
    }

    /**
     * Register the VC-A admin-post routes.
     *
     * These replace six action branches that previously posted back to the
     * rendering page, where a browser refresh re-submitted them. Each now
     * ends in a redirect (PRG), so a reload re-issues an inert GET.
     */
    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_SAVE,       [__CLASS__, 'handleSavePost']);
        add_action('admin_post_' . self::ACTION_PROVISION,  [__CLASS__, 'handleProvisionPost']);
        add_action('admin_post_' . self::ACTION_DELETE,     [__CLASS__, 'handleDeletePost']);
        add_action('admin_post_' . self::ACTION_TESTQUERY,  [__CLASS__, 'handleTestQueryPost']);

        // VC-B1.
        add_action('admin_post_' . self::ACTION_HIDE,       [__CLASS__, 'handleHidePost']);
        add_action('admin_post_' . self::ACTION_UNHIDE,     [__CLASS__, 'handleUnhidePost']);

        // PR 6 community intent.
        add_action('admin_post_' . self::ACTION_REQUEST_COMMUNITY, [__CLASS__, 'handleRequestCommunityPost']);
        add_action('admin_post_' . self::ACTION_WITHDRAW_REQUEST,  [__CLASS__, 'handleWithdrawRequestPost']);

        // PR E — NFT Collection Description review. Registered
        // unconditionally: this reviews provider-authored text on a manually
        // added collection and has nothing to do with the frozen scanner.
        add_action('admin_post_' . self::ACTION_DESC_APPROVE, [__CLASS__, 'handleDescriptionApprovePost']);
        add_action('admin_post_' . self::ACTION_DESC_REJECT,  [__CLASS__, 'handleDescriptionRejectPost']);

        // NOT registered, deliberately: ACTION_ADD / ACTION_ADD_COSMOS. See
        // the constant block above — Add Collection now lives in one
        // chain-locked form on the NFT Discovery page.
    }

    // ────────────────────────────────────────────────────────────────────
    // VC-A admin-post handlers
    //
    // Ordering in every one of them is: capability → input shape →
    // scoped nonce → authoritative lookup → mutation. No repository,
    // provider or PeepSo work happens before CSRF validation.
    //
    // Each delegates the actual domain work to the SAME private handler
    // the page used before, so verification semantics, collection
    // creation, delete protection and provisioning behaviour are
    // unchanged — only the request boundary moved.
    // ────────────────────────────────────────────────────────────────────

    public static function handleSavePost(): void
    {
        AdminActionSupport::requireCapability();
        // PR 6: every mutation route this PR retains or adds enforces POST.
        // admin-post.php dispatches on $_REQUEST['action'] and
        // check_admin_referer() reads $_REQUEST['_wpnonce'], so without this
        // the route is reachable by GET with a valid nonce — which makes it
        // reachable from an <img> tag.
        AdminActionSupport::requirePost();
        AdminActionSupport::requireNonce(self::ACTION_SAVE, '_vc_save_nonce');

        self::finish(self::handleSave(), 'save');
    }

    /**
     * Create holder communities for collections that are ALREADY verified
     * in the database.
     *
     * This deliberately no longer calls handleSave() first. The button
     * used to persist every checkbox on the page as a hidden side effect,
     * so "Create Communities" quietly did "Save Verification Changes"'s
     * job — an operator who ticked a box to look at it, then clicked
     * Create Communities, silently committed that tick. Provisioning now
     * reads persisted state only; unsaved ticks are ignored and revert
     * visibly on the redirect, and the button copy says so.
     */
    public static function handleProvisionPost(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();
        AdminActionSupport::requireNonce(self::ACTION_PROVISION, '_vc_provision_nonce');

        self::finish(self::handleProvision(), 'provision');
    }

    /**
     * Record an administrator's request for a holder community.
     *
     * Also the RETRY path for a failed provisioning — same route, same
     * nonce, same guards.
     */
    public static function handleRequestCommunityPost(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        // Shape first: the nonce action is derived from the id, so it must be
        // read before the nonce can be checked. Nothing touches the database
        // until the nonce has proven the request authentic.
        $collectionId = self::requireCollectionIdShape();
        AdminActionSupport::requireNonce(self::ACTION_REQUEST_COMMUNITY . '_' . $collectionId);

        self::finish(self::handleCommunityIntent($collectionId, true), 'request');
    }

    /** Withdraw a PENDING request. Never touches a provisioned community. */
    public static function handleWithdrawRequestPost(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $collectionId = self::requireCollectionIdShape();
        AdminActionSupport::requireNonce(self::ACTION_WITHDRAW_REQUEST . '_' . $collectionId);

        self::finish(self::handleCommunityIntent($collectionId, false), 'withdraw');
    }

    public static function handleDeletePost(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        // Shape first — the nonce action is derived from this id, so it has
        // to be read before the nonce can be checked. Nothing touches the
        // database until the nonce has proven the request authentic.
        $collectionId = self::requireCollectionIdShape();
        AdminActionSupport::requireNonce(self::ACTION_DELETE . '_' . $collectionId);

        self::finish(self::handleDeleteCollection($collectionId), 'delete');
    }

    /**
     * Read-only CW-721 probe.
     *
     * Kept as POST + PRG rather than converted to AJAX: it makes an
     * outbound Cosmos LCD request, so replay resistance matters more than
     * avoiding a page reload — under the old inline-POST shape a refresh
     * re-issued the LCD call. The result is carried across the redirect in
     * the same short-lived operator-scoped transient the other actions use,
     * so the probe runs exactly once per click.
     */
    public static function handleTestQueryPost(): void
    {
        AdminActionSupport::requireCapability();

        // ⚠ The docblock below has always said this route is "kept as POST +
        // PRG rather than converted to AJAX" BECAUSE it makes an outbound
        // Cosmos LCD request and replay resistance matters. It never enforced
        // that, so a GET carrying a valid nonce reached the provider — and a
        // nonce that leaked through a referrer header, browser history or a
        // server log became a replayable outbound request against somebody
        // else's endpoint, one per `<img>` tag render.
        //
        // Read-only is not the same as free: the cost and the rate limit are
        // the chain's, not ours. Checked before the id shape so a GET is
        // refused on its METHOD (405) rather than on its arguments.
        AdminActionSupport::requirePost();

        $collectionId = self::requireCollectionIdShape();
        AdminActionSupport::requireNonce(self::ACTION_TESTQUERY . '_' . $collectionId);

        // FAILURE POLICY (read-only probe), two cases, deliberately different:
        //
        //  1. An expected negative result — wrong chain type, contract is not
        //     a CW-721, LCD returns an error shape. handleTestQuery() returns
        //     a notice; technical detail goes to the provider path's own log.
        //     NO durable audit row: nothing changed, and recording that an
        //     operator looked at something is noise.
        //
        //  2. An UNEXPECTED exception escaping the probe. That is not a
        //     verdict about the contract, it is a fault in our code or
        //     transport, and it must be traceable. AdminActionSupport::
        //     failure() is the one path that mints a correlation ID, and it
        //     writes a durable row as part of that contract — so rather than
        //     leave an unnamed event outside the vocabulary, the event is
        //     named `admin_vc_testquery_failed` and declared with the rest.
        //     Read-only or not, an authorized operation that crashed is worth
        //     one row.
        try {
            $notices = self::handleTestQuery($collectionId);
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure(
                $e,
                'admin_vc_testquery_failed',
                'collection',
                $collectionId
            );
            $notices = [[
                'type'    => 'error',
                'message' => AdminActionSupport::failureMessage($ref),
            ]];
        }

        self::finish($notices, 'testquery');
    }

    // ────────────────────────────────────────────────────────────────────
    // VC-B1 admin-post handlers: Hide / Unhide
    // ────────────────────────────────────────────────────────────────────

    /**
     * Mark an imported NFT Collection Description as reviewed and accepted.
     *
     * ⚠ NOT a publication step. Per DECISION 17 the text is stored and
     * reviewed but not published during scanner retirement; the admin review
     * screen is its only reader. Approving one here writes nothing to any
     * group, any REST payload or any view-model.
     */
    public static function handleDescriptionApprovePost(): void
    {
        self::handleDescriptionReview(true);
    }

    /** Reject an imported NFT Collection Description. */
    public static function handleDescriptionRejectPost(): void
    {
        self::handleDescriptionReview(false);
    }

    /**
     * The shared review path.
     *
     * Same guard shape as the hide/unhide toggle: capability, POST, an id of
     * the right shape, and a per-row nonce bound to BOTH the route and the id,
     * so a nonce minted for one collection cannot approve another's text.
     *
     * The transition is a compare-and-swap from `pending`
     * ({@see CollectionRepository::setChainDescriptionState()}), so two
     * administrators reviewing the same row concurrently cannot both succeed —
     * the loser matches zero rows and is reported as not applied.
     */
    private static function handleDescriptionReview(bool $approve): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $collectionId = self::requireCollectionIdShape();
        $route        = $approve ? self::ACTION_DESC_APPROVE : self::ACTION_DESC_REJECT;

        AdminActionSupport::requireNonce($route . '_' . $collectionId);

        $target = $approve
            ? ChainDescriptionState::APPROVED
            : ChainDescriptionState::REJECTED;

        try {
            // ⚠⚠⚠ THE TRANSITION AND ITS AUDIT ARE ONE UNIT.
            // Before this, the state was changed and THEN the audit written —
            // so a failed audit left the description approved or rejected with
            // no record of who decided it or when. An unattributable review
            // decision is exactly what the checked-audit contract exists to
            // prevent, and the same contract already governs manual intake.
            //
            // Throwing inside the transaction rolls the state change back, so
            // the description stays `pending` and can be reviewed again.
            $applied = TransactionManager::run(function () use ($collectionId, $target, $approve) {
                // ⚠ FROM `pending`, ALWAYS. A description may only move out of
                // review — never straight from `none`, which would decide text
                // nobody looked at. Compare-and-swap, so two administrators
                // acting at once cannot both win.
                $moved = CollectionRepository::setChainDescriptionState(
                    $collectionId,
                    ChainDescriptionState::PENDING,
                    $target
                );

                if (!$moved) {
                    // An EXPECTED negative: nothing pending, or somebody else
                    // reviewed it first. Not an error, and nothing to roll
                    // back — return without writing an audit row, because a
                    // record saying a decision happened would be
                    // indistinguishable later from one that did.
                    return false;
                }

                $auditId = AuditLogger::logChecked(
                    $approve ? self::AUDIT_DESC_APPROVED : self::AUDIT_DESC_REJECTED,
                    $collectionId,
                    [
                        'collection_id' => $collectionId,
                        'from_state'    => ChainDescriptionState::PENDING,
                        'to_state'      => $target,
                    ],
                    'collection',
                    get_current_user_id()
                );

                if ($auditId === null) {
                    throw new \RuntimeException(
                        'checked audit write failed; rolling back the description state change'
                    );
                }

                return true;
            });

            if (!$applied) {
                $notices = [[
                    'type'    => 'warning',
                    'message' => 'No pending description was found for that collection, so nothing changed. '
                        . 'It may already have been reviewed.',
                ]];
            } else {
                $notices = [[
                    'type'    => 'success',
                    'message' => $approve
                        // ⚠ "Approved" is a REVIEW OUTCOME, not publication.
                        // DECISION 17 keeps the NFT Collection Description
                        // stored and reviewed but NOT published during scanner
                        // retirement: no REST field, no picker, no stance
                        // panel, no Hall preview, no group description. The
                        // admin review screen is the only reader.
                        ? 'Description approved. It stays on the admin review screen and is not published anywhere '
                            . 'yet (DECISION 17). It is never used as a community description.'
                        : 'Description rejected. It stays stored for reference and is not shown.',
                ]];
            }
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure(
                $e,
                $approve ? self::AUDIT_DESC_APPROVED . '_failed' : self::AUDIT_DESC_REJECTED . '_failed',
                'collection',
                $collectionId
            );
            $notices = [[
                'type'    => 'error',
                'message' => AdminActionSupport::failureMessage($ref),
            ]];
        }

        self::finish($notices, $approve ? 'desc_approve' : 'desc_reject');
    }

    public static function handleHidePost(): void
    {
        self::handleHideRequest(true);
    }

    public static function handleUnhidePost(): void
    {
        self::handleHideRequest(false);
    }

    /**
     * Shared request boundary for both directions.
     *
     * One method, not two copies: the ONLY thing that differs between Hide
     * and Unhide at the boundary is which route name the nonce is bound to,
     * and duplicating the gate order is how one copy later drifts out of
     * step with the other.
     *
     * Order is capability → method → id shape → scoped nonce → domain work.
     * The id is read before the nonce because the nonce action is derived
     * from it, but nothing touches a repository until the nonce has proven
     * the request authentic, and the shape check deliberately does no
     * lookup — an unauthenticated request must not be able to probe which
     * collection ids exist.
     */
    private static function handleHideRequest(bool $hide): never
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $collectionId = self::requireCollectionIdShape();
        $route        = $hide ? self::ACTION_HIDE : self::ACTION_UNHIDE;

        AdminActionSupport::requireNonce($route . '_' . $collectionId);

        // FAILURE POLICY, matching the VC-A Test CW-721 rule:
        //
        //  - an EXPECTED negative (unknown collection, id mismatch, the rule
        //    write reporting failure) returns an operator notice and writes
        //    NO durable row. Nothing changed, and a row saying so would be
        //    indistinguishable later from a change that did happen.
        //
        //  - an UNEXPECTED exception is a fault in our code or transport,
        //    not a verdict about the collection. It routes through
        //    AdminActionSupport::failure(), which is the one path that mints
        //    a correlation ID and writes a durable row as part of that
        //    contract — so the event is named rather than left anonymous.
        try {
            $notices = self::handleHideToggle($collectionId, $hide);
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure(
                $e,
                self::hideFailedAction($hide),
                'collection',
                $collectionId
            );
            $notices = [[
                'type'    => 'error',
                'message' => AdminActionSupport::failureMessage($ref),
            ]];
        }

        self::finish($notices, $hide ? 'hide' : 'unhide');
    }

    /**
     * The per-row VC-A forms (Remove, Test CW-721).
     *
     * Public so the wiring can be asserted structurally: the buttons that
     * drive these live inside the big verification form and reach them via
     * the HTML5 `form=` attribute, which only works if every id here is
     * unique and matches exactly one button.
     *
     * @param list<int>          $rowIds
     * @param array<int, bool>   $hiddenById  row id => is currently hidden
     * @param array<int, string> $intentById  row id => provisioning state
     */
    public static function renderRowActionForms(
        array $rowIds,
        int $page,
        string $chain,
        string $tokenStandard,
        string $tab,
        array $hiddenById = [],
        array $intentById = []
    ): void {
        foreach ($rowIds as $rowId) {
            $rowId = (int) $rowId;
            if ($rowId <= 0) {
                continue;
            }

            // VC-B1: exactly ONE of the two directions is emitted per row —
            // the one the row is not already in. Rendering both would put a
            // live Unhide form on a visible collection, and the whole point
            // of splitting the routes is that a direction is never implied.
            $isHidden   = (bool) ($hiddenById[$rowId] ?? false);
            $hideRoute  = $isHidden ? self::ACTION_UNHIDE : self::ACTION_HIDE;
            $hideFormId = self::hideFormId($rowId, $isHidden);
            ?>
            <form id="<?php echo esc_attr($hideFormId); ?>" method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
                <input type="hidden" name="action" value="<?php echo esc_attr($hideRoute); ?>">
                <input type="hidden" name="collection_id" value="<?php echo $rowId; ?>">
                <?php wp_nonce_field($hideRoute . '_' . $rowId); ?>
                <?php self::renderReturnContext($page, $chain, $tokenStandard, $tab); ?>
            </form><?php ?>
            <form id="vc-a-del-<?php echo $rowId; ?>" method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_DELETE); ?>">
                <input type="hidden" name="collection_id" value="<?php echo $rowId; ?>">
                <?php wp_nonce_field(self::ACTION_DELETE . '_' . $rowId); ?>
                <?php self::renderReturnContext($page, $chain, $tokenStandard, $tab); ?>
            </form>
            <form id="vc-a-test-<?php echo $rowId; ?>" method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_TESTQUERY); ?>">
                <input type="hidden" name="collection_id" value="<?php echo $rowId; ?>">
                <?php wp_nonce_field(self::ACTION_TESTQUERY . '_' . $rowId); ?>
                <?php self::renderReturnContext($page, $chain, $tokenStandard, $tab); ?>
            </form>
            <?php
            // PR 6 community intent. Exactly ONE direction is rendered per
            // row — the one the row is not already in — for the same reason
            // Hide/Unhide are two routes: a toggle decides its direction from
            // the state the page had at RENDER time, so a stale tab would
            // withdraw a request through a button labelled "Request".
            //
            // `provisioned` renders NEITHER: there is nothing to request and
            // nothing an operator may withdraw. That is not a rendering
            // nicety — `withdraw` refuses `provisioned` at the service and at
            // the repository too, so this is the third independent place the
            // same rule holds.
            $intent = (string) ($intentById[$rowId] ?? ProvisioningState::NONE);
            if ($intent === ProvisioningState::NONE || $intent === ProvisioningState::FAILED) {
                $intentRoute = self::ACTION_REQUEST_COMMUNITY;
            } elseif ($intent === ProvisioningState::REQUESTED) {
                $intentRoute = self::ACTION_WITHDRAW_REQUEST;
            } else {
                $intentRoute = '';
            }

            if ($intentRoute !== ''):
            ?>
            <form id="vc-intent-<?php echo $rowId; ?>" method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
                <input type="hidden" name="action" value="<?php echo esc_attr($intentRoute); ?>">
                <input type="hidden" name="collection_id" value="<?php echo $rowId; ?>">
                <?php wp_nonce_field($intentRoute . '_' . $rowId); ?>
                <?php self::renderReturnContext($page, $chain, $tokenStandard, $tab); ?>
            </form>
            <?php
            endif;
        }
    }

    /**
     * The per-row community-intent button, as it appears inside the big form.
     *
     * Returns silently for a `provisioned` row: no button at all is the
     * honest rendering, because neither action is available.
     */
    public static function renderIntentButton(int $rowId, string $intent): void
    {
        switch ($intent) {
            case ProvisioningState::NONE:
                ?>
                <button type="submit" form="vc-intent-<?php echo (int) $rowId; ?>"
                        class="button button-small button-primary">Request community</button>
                <?php
                break;

            case ProvisioningState::FAILED:
                ?>
                <button type="submit" form="vc-intent-<?php echo (int) $rowId; ?>"
                        class="button button-small">Retry request</button>
                <?php
                break;

            case ProvisioningState::REQUESTED:
                ?>
                <button type="submit" form="vc-intent-<?php echo (int) $rowId; ?>"
                        class="button button-small">Withdraw request</button>
                <?php
                break;

            default:
                // provisioned, or an unrecognised value. Render nothing
                // rather than guessing an action.
                break;
        }
    }

    /**
     * The two per-row VC-A buttons, as they appear inside the big form.
     *
     * Extracted alongside renderRowActionForms() so a test can compose the
     * exact structure render_page() produces — buttons inside the big form,
     * their forms after it closes — and assert the pairing with a real DOM
     * parse rather than substring matching.
     */
    public static function renderRowActionButtons(int $rowId, bool $isCosmos): void
    {
        if ($isCosmos) {
            ?>
            <button type="submit" form="vc-a-test-<?php echo (int) $rowId; ?>" class="button button-small">
                Test CW-721
            </button>
            <?php
        }
        ?>
        <button type="submit" form="vc-a-del-<?php echo (int) $rowId; ?>" class="button button-small button-link-delete">
            Remove
        </button>
        <?php
    }

    /**
     * The id of a row's Hide-or-Unhide form.
     *
     * Direction is part of the id, so the two can never collide and a DOM
     * assertion can tell from the id alone which direction a row offers.
     */
    public static function hideFormId(int $rowId, bool $isHidden): string
    {
        return ($isHidden ? 'vc-b-unhide-' : 'vc-b-hide-') . (int) $rowId;
    }

    /**
     * The per-row Hide / Unhide button.
     *
     * Called by render_page() AND by the DOM wiring test, so the assertion
     * is made against the markup production actually emits rather than
     * against a copy of it that can drift.
     *
     * The confirmation text states what the control really does: it applies
     * (or lifts) a platform-wide DENY rule on the CONTRACT, which is what
     * governs visibility and rediscovery. It deliberately does NOT describe
     * the scanner-cache sync — that is downstream bookkeeping, and calling
     * it the security control would be untrue.
     */
    /** The hidden form id for one description transition. */
    private static function descFormId(int $rowId, bool $approve): string
    {
        return 'vc-desc-' . ($approve ? 'ok' : 'no') . '-' . $rowId;
    }

    /**
     * Is this row's imported description awaiting a decision?
     *
     * ⚠ `pending` AND non-empty text. The handler's compare-and-swap is FROM
     * `pending`, so any other state is terminal and a control on it would be a
     * button that can never succeed. And a `pending` marker with no text is
     * nothing to review — offering a decision on absent text would record an
     * administrator approving something they could not have read.
     */
    private static function descriptionAwaitsReview(object $row): bool
    {
        $state = $row->chain_description_state ?? null;
        $text  = $row->chain_description ?? null;

        return $state === ChainDescriptionState::PENDING
            && is_string($text)
            && trim($text) !== '';
    }

    /**
     * The hidden approve/reject forms for every row awaiting a decision.
     *
     * ── ⚠⚠⚠ WHY THIS EXISTS ─────────────────────────────────────────────
     * PR E shipped `ACTION_DESC_APPROVE` / `ACTION_DESC_REJECT`, registered
     * them and wrote a careful handler — and NOTHING RENDERED THEM. The actions
     * appeared only in the constant block, the registrations and the handlers,
     * so an imported description could be stored and could never be reviewed.
     * DECISION 17's "the admin review interface is the reader" was false: the
     * text was unreadable by anyone, which is a different thing from
     * unpublished.
     *
     * Emitted outside the table for the same reason as the other row forms:
     * HTML forbids nested forms, and the buttons reach these through the HTML5
     * `form=` attribute.
     *
     * ⚠ EACH NONCE IS BOUND TO ROUTE **AND** COLLECTION ID, matching
     * `handleDescriptionReview()`'s `requireNonce($route . '_' . $id)` exactly.
     * A shared nonce, or one bound to the route alone, would let a token minted
     * for one collection decide another collection's text.
     *
     * @param list<object> $rows the listing rows, as rendered
     */
    public static function renderDescriptionReviewForms(
        array $rows,
        int $page,
        string $chain,
        string $tokenStandard,
        string $tab
    ): void {
        foreach ($rows as $row) {
            if (!is_object($row) || !self::descriptionAwaitsReview($row)) {
                continue;
            }

            $rowId = (int) ($row->id ?? 0);
            if ($rowId <= 0) {
                continue;
            }

            foreach ([true, false] as $approve) {
                $route = $approve ? self::ACTION_DESC_APPROVE : self::ACTION_DESC_REJECT;
                ?>
                <form id="<?php echo esc_attr(self::descFormId($rowId, $approve)); ?>" method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
                    <input type="hidden" name="action" value="<?php echo esc_attr($route); ?>">
                    <input type="hidden" name="collection_id" value="<?php echo $rowId; ?>">
                    <?php wp_nonce_field($route . '_' . $rowId); ?>
                    <?php self::renderReturnContext($page, $chain, $tokenStandard, $tab); ?>
                </form>
                <?php
            }
        }
    }

    /**
     * The imported NFT Collection Description, as an administrator reviews it.
     *
     * ── WHAT IS SHOWN ───────────────────────────────────────────────────
     * The text, where it came from, its current review state, and — only while
     * `pending` — an Approve and a Reject control. A decided description stays
     * visible for reference with NO transition control, because `approved` and
     * `rejected` are terminal at the repository too.
     *
     * ── ⚠⚠⚠ THE TEXT IS UNTRUSTED ───────────────────────────────────────
     * It is imported from contract metadata a contract author controls, which
     * makes it the most attacker-influenced string on this screen — and it is
     * rendered beside live POST forms holding valid nonces. It is escaped as
     * plain text with `esc_html()`, never echoed raw and never passed through
     * any markup-permitting filter. The SOURCE label is provider-influenced
     * too and is escaped identically.
     *
     * ── AND IT IS NOT THE COMMUNITY DESCRIPTION ─────────────────────────
     * Community About is the PeepSo community biography, written by community
     * managers. Approving this text writes nothing to it.
     */
    public static function renderDescriptionReview(object $row): void
    {
        $text = $row->chain_description ?? null;
        if (!is_string($text) || trim($text) === '') {
            return; // nothing imported; no block, no empty scaffolding
        }

        $rowId = (int) ($row->id ?? 0);
        $state = is_string($row->chain_description_state ?? null)
            ? (string) $row->chain_description_state
            : ChainDescriptionState::NONE;
        $source = $row->chain_description_source ?? null;

        $label = match ($state) {
            ChainDescriptionState::PENDING  => 'Pending review',
            ChainDescriptionState::APPROVED => 'Approved',
            ChainDescriptionState::REJECTED => 'Rejected',
            default                         => 'Not reviewed',
        };
        $colour = match ($state) {
            ChainDescriptionState::PENDING  => '#dba617',
            ChainDescriptionState::APPROVED => '#00a32a',
            ChainDescriptionState::REJECTED => '#d63638',
            default                         => '#646970',
        };
        ?>
        <div style="margin-top:6px;padding:6px 8px;border-left:3px solid <?php echo esc_attr($colour); ?>;background:#f6f7f7;max-width:32em;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:<?php echo esc_attr($colour); ?>;">
                Collection description — <?php echo esc_html($label); ?>
            </div>
            <div style="font-size:12px;color:#1d2327;margin-top:2px;">
                <?php echo esc_html($text); ?>
            </div>
            <?php if (is_string($source) && trim($source) !== ''): ?>
                <div style="font-size:11px;color:#646970;margin-top:2px;">
                    Imported from <code><?php echo esc_html($source); ?></code>
                </div>
            <?php endif; ?>
            <div style="font-size:11px;color:#646970;margin-top:2px;">
                Stored for review only. It is <strong>not published anywhere</strong> and is never the
                community&rsquo;s description.
            </div>
            <?php if (self::descriptionAwaitsReview($row)): ?>
                <p style="margin:6px 0 0;">
                    <button type="submit"
                            form="<?php echo esc_attr(self::descFormId($rowId, true)); ?>"
                            class="button button-small"
                            title="Record that you read this text and accept it. It stays unpublished."
                            onclick="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral(
                                'Approve this imported collection description?' . "\n\n"
                                . 'It records that you read and accepted this exact text. It does NOT publish it: '
                                . 'the text stays on this admin screen and reaches no public page, API or community.'
                            )); ?>);">
                        Approve
                    </button>
                    <button type="submit"
                            form="<?php echo esc_attr(self::descFormId($rowId, false)); ?>"
                            class="button button-small"
                            title="Refuse this text so it is not queued for review again."
                            onclick="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral(
                                'Reject this imported collection description?' . "\n\n"
                                . 'The text is kept so it is not offered again, and nothing is published either way.'
                            )); ?>);">
                        Reject
                    </button>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function renderHideButton(int $rowId, bool $isHidden): void
    {
        $rowId  = (int) $rowId;
        $formId = self::hideFormId($rowId, $isHidden);

        $confirm = $isHidden
            ? 'Lift the platform-wide hide rule?' . "\n\n"
                . 'This applies a platform-wide ALLOW rule to the contract, lifting the deny decision. '
                . 'The collection may become visible again on user-facing surfaces where it otherwise '
                . 'qualifies, and discovery may consider it eligible from now on.' . "\n\n"
                . 'It does NOT verify the collection, and it does NOT create or provision its community. '
                . 'You still do that yourself.'
            : 'Hide this collection from users?' . "\n\n"
                . 'This applies a platform-wide DENY rule to the contract. The collection is removed from every '
                . 'user-facing surface, and discovery will no longer treat it as eligible or visible — so it stays '
                . 'hidden even if wallets holding it link later.' . "\n\n"
                . 'Reversible with Unhide. This is not the same as Remove, which only deletes the row.';

        $title = $isHidden
            ? 'Apply a platform-wide ALLOW rule to this contract and lift the deny decision. The collection may '
                . 'become visible again where it otherwise qualifies. This does not verify it or create its community.'
            : 'Apply a platform-wide DENY rule to this contract: hidden from users everywhere and never treated '
                . 'as eligible by discovery. Reversible, unlike Remove.';
        ?>
        <button type="submit"
                form="<?php echo esc_attr($formId); ?>"
                class="button button-small"
                title="<?php echo esc_attr($title); ?>"
                onclick="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirm)); ?>);">
            <?php echo $isHidden ? 'Unhide' : 'Hide'; ?>
        </button>
        <?php
    }

    /**
     * Hidden inputs carrying the operator's list context through an
     * admin-post round trip, so the PRG redirect lands back on the same
     * page/filter/sub-tab instead of page 1 unfiltered.
     */
    private static function renderReturnContext(
        int $page,
        string $chain,
        string $tokenStandard,
        string $tab
    ): void {
        printf('<input type="hidden" name="paged" value="%d">', $page);
        printf('<input type="hidden" name="chain" value="%s">', esc_attr($chain));
        printf('<input type="hidden" name="token_standard" value="%s">', esc_attr($tokenStandard));
        // PR 6: carries the four-state tab, not a verified/unverified flag.
        // Re-resolved on the way out as well as the way in, so a hand-edited
        // value cannot round-trip an unknown tab back into a URL.
        printf('<input type="hidden" name="vstate" value="%s">', esc_attr(self::resolveTab($tab)));
    }

    /**
     * Map any incoming `vstate` value onto exactly one of the four tabs.
     *
     * ── WHY LEGACY VALUES ARE MAPPED, NOT REJECTED ──────────────────────
     * `vstate` was `verified` | anything-else before PR 6. Bookmarks, the
     * operator's browser history and the PRG round trip all still carry
     * those values. `verified` means the tab that now describes the same
     * rows; everything unrecognised — including the old implicit
     * "unverified" — collapses to the discovery queue, which is the default
     * working view and was the old default too.
     *
     * Nothing unknown ever reaches the query builder: an unrecognised tab
     * would otherwise have to be handled somewhere further in, and "somewhere
     * further in" is where it would eventually mean "no filter at all".
     */
    private static function resolveTab(string $raw): string
    {
        if (CollectionStateClassifier::isTab($raw)) {
            return $raw;
        }

        if ($raw === 'verified') {
            return CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY;
        }

        return CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED;
    }

    /**
     * Shape-only validation of the per-row collection id.
     *
     * Deliberately does no repository work: the id feeds the nonce action,
     * so it must be read pre-CSRF, and an unauthenticated request must not
     * be able to probe which collection ids exist. Existence is checked by
     * the domain handler, after the nonce.
     */
    private static function requireCollectionIdShape(): int
    {
        $collectionId = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;

        if ($collectionId <= 0) {
            wp_die(
                esc_html__('Invalid collection.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return $collectionId;
    }

    /**
     * Stash notices and PRG back to the page.
     *
     * The existing handlers return rich operator-facing notice arrays; the
     * transient carries them across the redirect verbatim so no message
     * regresses, while the redirect itself is what makes a refresh inert.
     *
     * @param list<array{type: string, message: string}> $notices
     */
    private static function finish(array $notices, string $op): never
    {
        if ($notices !== []) {
            set_transient(
                self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
                $notices,
                self::NOTICE_TTL
            );
        }

        AdminActionSupport::redirect(self::returnArgs(['bcc_vc_done' => $op]));
    }

    /**
     * Preserve the operator's list context (page, filters, sub-tab) across
     * the redirect so PRG doesn't dump them back on page 1 unfiltered.
     *
     * @param array<string, string|int> $extra
     * @return array<string, string|int>
     */
    private static function returnArgs(array $extra = []): array
    {
        $args = ['page' => self::PAGE_SLUG];

        foreach (['paged', 'chain', 'token_standard', 'vstate'] as $key) {
            if (isset($_POST[$key]) && $_POST[$key] !== '') {
                $args[$key] = sanitize_text_field((string) $_POST[$key]);
            }
        }

        return array_merge($args, $extra);
    }

    /**
     * Pull and clear the PRG notices for the current operator.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function takeNotices(): array
    {
        $key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $stored = get_transient($key);

        if (!is_array($stored)) {
            return [];
        }

        delete_transient($key);

        $out = [];
        foreach ($stored as $n) {
            if (is_array($n) && isset($n['type'], $n['message'])) {
                $out[] = ['type' => (string) $n['type'], 'message' => (string) $n['message']];
            }
        }

        return $out;
    }

    /**
     * AJAX: flip a single collection's is_verified flag. Returns the new
     * state so the row UI can re-render without a page reload.
     */
    public static function ajax_toggle_verified(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        // Shape before nonce: both values feed the nonce action, which is
        // bound to the collection AND the intended state, so a nonce minted
        // to verify row 7 cannot unverify it — nor touch row 8.
        $collectionId = (int) ($_POST['collection_id'] ?? 0);
        // empty() already treats '0', '', and absent as false — the JS
        // sends '1' to verify and '0' to unverify.
        $verify       = !empty($_POST['verify']);

        if ($collectionId <= 0) {
            wp_send_json_error(['message' => 'Invalid collection id.']);
        }

        check_ajax_referer(
            self::AJAX_ACTION_TOGGLE . '_' . $collectionId . '_' . ($verify ? '1' : '0'),
            'nonce'
        );

        // PR 6: the AJAX toggle and the bulk save now share ONE writer, so
        // the "unverify withdraws a pending request" rule cannot hold on one
        // path and not the other. Writing straight to the repository here was
        // exactly how that kind of divergence used to happen.
        $result = OnchainPlugin::instance()->communityRequestService()->applyVerification(
            $verify ? [$collectionId] : [],
            $verify ? [] : [$collectionId],
            get_current_user_id()
        );

        if (!$result['ok']) {
            wp_send_json_error(['message' => 'The change was rolled back; nothing was saved.']);
        }

        $changed = $result['changed'];

        AdminActionSupport::audit(
            $verify ? 'admin_vc_collection_verified' : 'admin_vc_collection_unverified',
            'collection',
            $collectionId,
            ['changed' => $changed, 'withdrawn' => $result['withdrawn']]
        );

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections toggle (ajax)', [
            'action'        => 'verify_collections_toggle_ajax',
            'collection_id' => $collectionId,
            'verify'        => $verify,
            'changed'       => $changed,
            'withdrawn'     => $result['withdrawn'],
            'operator'      => get_current_user_id(),
        ]);

        $message = $verify
            // Say plainly what verification now does and does not do — the
            // whole point of PR 6 is that this click no longer creates a
            // community, and an operator who believes otherwise will wait
            // for one that never arrives.
            ? 'Marked verified. Verification alone does not create a community — use "Request community".'
            : 'Marked unverified.';

        if (!$verify && $result['withdrawn'] > 0) {
            $message .= ' A pending community request was withdrawn. An existing community would not have been removed.';
        }

        wp_send_json_success([
            'verified' => $verify,
            'message'  => $message,
        ]);
    }

    /**
     * AJAX: create the holder community for one collection that has a
     * RECORDED REQUEST.
     *
     * ── PR 6: CONVERTED, NOT RETIRED ────────────────────────────────────
     * This used to be "provision the community for one VERIFIED collection"
     * — a second path by which verification alone produced a community. It
     * is now a convenience for running the queue one row early: the intent
     * gate lives inside `provisionOne()`, which refuses anything not in
     * `requested`, so this route cannot bypass recorded intent however it is
     * called. The button is only rendered for rows that are actually
     * `requested`; reaching it any other way returns "no community has been
     * requested" and writes nothing.
     */
    public static function ajax_provision_one(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $collectionId = (int) ($_POST['collection_id'] ?? 0);
        if ($collectionId <= 0) {
            wp_send_json_error(['message' => 'Invalid collection id.']);
        }

        // Bound to this collection: a nonce for row 7 cannot create the
        // community for row 8.
        check_ajax_referer(self::AJAX_ACTION_PROVISION . '_' . $collectionId, 'nonce');

        $result = OnchainPlugin::instance()->gatedGroupProvisioningService()->provisionOne($collectionId);

        // ── THE READ FAILED: NO CONCLUSION WAS REACHED ──────────────────
        // Handled BEFORE the failed/skipped/unconfirmed block, and returning
        // an error rather than success, because `unavailable` is not an
        // outcome for this collection — it is the absence of one. The
        // previous code matched neither branch and fell through to
        // `wp_send_json_success()`, so an operator whose database had just
        // declined to answer was told the action had worked.
        //
        // ⚠ AND NO DURABLE AUDIT ROW.
        // `admin_vc_community_provision_failed` is a durable statement that
        // provisioning was attempted and refused FOR THIS COLLECTION. The
        // database read established nothing of the sort: no PeepSo call was
        // made, no state was written, and the collection may be in perfect
        // order. Writing that row would put a permanent, misleading claim in
        // the activity log for an infrastructure hiccup — and it is the trail
        // someone reads months later when asking why a community was refused.
        // The fault belongs in the short-retention application log, which the
        // repository already wrote.
        if ($result['status'] === 'unavailable') {
            wp_send_json_error([
                'message' => 'The collection could not be read just now, so nothing was attempted. '
                    . 'No community was created and no state was changed. Please try again.',
            ]);
        }

        if ($result['status'] === 'failed'
            || $result['status'] === 'skipped'
            || $result['status'] === 'unconfirmed'
        ) {
            // ── WHAT THE SERVICE DID, AND WHAT THIS ROW IS ──────────────
            // The service writes the durable `failed` state and its CHECKED
            // audit row in ONE transaction, so either both exist or neither
            // does. `failure_record` says which — and this admin-surface
            // trace carries that verbatim rather than assuming the durable
            // record landed.
            //
            // An earlier revision of this comment claimed the service "already
            // wrote" a checked audit; at the time it wrote an UNCHECKED one
            // after a separate state write, so a lost encode left a durable
            // `failed` row with nothing behind it. The code now genuinely
            // does what this comment says.
            //
            // It carries the bounded code, never the message, so no free text
            // becomes durable here either.
            AdminActionSupport::audit(
                'admin_vc_community_provision_failed',
                'collection',
                $collectionId,
                [
                    'status'         => (string) $result['status'],
                    'failure_code'   => (string) ($result['failure_code'] ?? 'none'),
                    'failure_record' => (string) ($result['failure_record'] ?? 'not_applicable'),
                    'audit_degraded' => !empty($result['audit_degraded']) ? 'yes' : 'no',
                ]
            );
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success([
            'status'   => $result['status'],
            'group_id' => $result['group_id'],
            'message'  => $result['message'],
        ]);
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        // VC-B branches still post back to this page and return notices
        // inline; VC-A actions redirect and leave theirs in a short-lived
        // operator-scoped transient.
        $notices = array_merge(self::takeNotices(), self::handlePost());

        $page                  = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $selectedChain         = isset($_GET['chain']) ? sanitize_text_field((string) $_GET['chain']) : '';
        $selectedTokenStandard = isset($_GET['token_standard'])
            ? sanitize_text_field((string) $_GET['token_standard'])
            : '';

        $availableChains    = ChainRepository::getActive();
        $availableStandards = CollectionRepository::getDistinctTokenStandards();

        // Validate token_standard against the auto-derived whitelist
        // before passing it on — defends against a malformed query
        // string slipping a non-existent value into the SQL (it would
        // be safely placeholdered either way, but rejecting unknowns
        // keeps the dropdown's selected-state honest).
        if ($selectedTokenStandard !== '' && !in_array($selectedTokenStandard, $availableStandards, true)) {
            $selectedTokenStandard = '';
        }

        // PR 6: four collection-state tabs replace the Verified/Unverified
        // pair. `vstate` is kept as the query parameter so existing bookmarks
        // and the return-context round trip keep working; the two legacy
        // values map onto the two tabs that mean the same thing.
        //
        // Defaults to the discovery queue — rows awaiting a decision — which
        // is what "unverified" defaulted to before.
        $vstate = isset($_GET['vstate']) ? sanitize_key((string) $_GET['vstate']) : '';
        $vstate = self::resolveTab($vstate);

        // Retained for the row renderer and the bulk-save form, both of which
        // ask "is this the verified view?" to decide what to draw.
        $isVerified = $vstate === CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY;

        $chainArg    = $selectedChain !== '' ? $selectedChain : null;
        $standardArg = $selectedTokenStandard !== '' ? $selectedTokenStandard : null;

        $listing = CollectionRepository::listForAdminState(
            $vstate,
            $page,
            // Same constant the bulk-save bound uses, so page size and the
            // accepted id count cannot drift apart.
            self::MAX_BULK_IDS,
            $chainArg,
            $standardArg
        );

        // Demand signals, strongest first:
        //   1. Waitlist count — EXPLICIT user opt-ins ("activate this and
        //      count me in"). Airdrop-proof: a scammer can put tokens in
        //      every wallet but can't check the box for anyone.
        //   2. Linked holders — passive holdings, from BCC's own holdings
        //      index ONLY (EVM/SOL). Forgeable by airdrop, so it's the
        //      tiebreaker, not the rank.
        // Spam flags render red and sort flagged rows to the bottom.
        //
        // ⚠ NOTHING HERE LEAVES THE SERVER. Cosmos Hub counts used to be
        // built from a third-party marketplace API at render time, which
        // sent every linked Hub wallet address off-platform whenever this
        // page was opened. They are now NOT CALCULATED, and the cell says
        // so — see CollectionDemandService. Rendering this page must make no
        // outbound request; `VerifyCollectionsRenderIsolationTest` pins it.
        $demand  = CollectionDemandService::linkedHolderCounts();
        $signals = [];
        foreach (\BCC\Trust\Onchain\Repositories\CollectionSignalRepository::countsByCollection() as $t) {
            $signals[CollectionDemandService::key((int) $t->chain_id, (string) $t->contract_address)] = [
                'waitlist' => (int) $t->waitlist_count,
                'spam'     => (int) $t->spam_count,
            ];
        }

        // Re-rank the unverified queue. The repo caps perPage at 100, so
        // ranking re-fetches the scope in chunks up to a 500-row ceiling
        // — same fetch-sort-paginate-in-PHP posture as the §4.7
        // groups-discovery sort. Past the ceiling ranking is DISABLED
        // (not silently partial): SQL order is already a sane fallback
        // and a lying rank order is worse than none.
        $demandRanked = false;
        if ($vstate === CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED
            && $listing['available']
            && $listing['total'] > 0
            && $listing['total'] <= 500
        ) {
            $all = [];
            $chunkPages = (int) ceil($listing['total'] / 100);
            for ($p = 1; $p <= $chunkPages; $p++) {
                $chunk = CollectionRepository::listForAdminState(
                    CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
                    $p,
                    100,
                    $chainArg,
                    $standardArg
                );
                if (!$chunk['available']) {
                    // A failed chunk read must not silently produce a
                    // partial ranking presented as complete.
                    $all = [];
                    break;
                }
                foreach ($chunk['items'] as $chunkRow) {
                    $all[] = $chunkRow;
                }
                if (count($chunk['items']) < 100) {
                    break;
                }
            }

            usort($all, static function (object $a, object $b) use ($demand, $signals): int {
                $ka = CollectionDemandService::key((int) $a->chain_id, (string) $a->contract_address);
                $kb = CollectionDemandService::key((int) $b->chain_id, (string) $b->contract_address);
                $sa = $signals[$ka] ?? ['waitlist' => 0, 'spam' => 0];
                $sb = $signals[$kb] ?? ['waitlist' => 0, 'spam' => 0];

                // Spam-flagged rows sink below everything unflagged.
                if (($sa['spam'] > 0) !== ($sb['spam'] > 0)) {
                    return $sa['spam'] > 0 ? 1 : -1;
                }
                if ($sa['waitlist'] !== $sb['waitlist']) {
                    return $sb['waitlist'] <=> $sa['waitlist'];
                }
                // Rows with MORE indexed linked holders sort first. A row with
                // no indexed count — not calculated, unavailable, or none in
                // the index — carries no evidence either way and ties here;
                // the ordering uses what is known and asserts nothing about
                // what is not. The CELL is what must never show a zero.
                $da = $demand['counts'][$ka] ?? 0;
                $db = $demand['counts'][$kb] ?? 0;
                if ($da !== $db) {
                    return $db <=> $da;
                }
                // Tie-break: marketplace-wide holders (nulls last), then id.
                $ha = $a->unique_holders !== null ? (int) $a->unique_holders : -1;
                $hb = $b->unique_holders !== null ? (int) $b->unique_holders : -1;
                if ($ha !== $hb) {
                    return $hb <=> $ha;
                }
                return (int) $b->id <=> (int) $a->id;
            });
            // `$all === []` means a chunk read failed above. Leaving the SQL
            // ordering in place and NOT claiming `demandRanked` is the honest
            // fallback; presenting a partial ranking as a complete one is the
            // failure mode this guard exists for.
            if ($all !== []) {
                $listing['items'] = array_slice($all, ($page - 1) * 50, 50);
                $demandRanked     = true;
            }
        }

        $stateCountsResult = CollectionRepository::countsByState($chainArg, $standardArg);
        $stateCounts       = $stateCountsResult['counts'];
        $countsAvailable   = $stateCountsResult['available'];

        // Pill chains: intersection of PILL_CHAIN_SLUGS (filterable) and
        // the active chains registry, in the configured order. A
        // missing/disabled chain silently drops its pill.
        /** @var list<string> $pillSlugs */
        $pillSlugs = (array) apply_filters(
            'bcc_verify_collections_pill_chains',
            self::PILL_CHAIN_SLUGS
        );
        $availableChainsBySlug = [];
        foreach ($availableChains as $chain) {
            $availableChainsBySlug[(string) $chain->slug] = $chain;
        }
        $pillChains = [];
        foreach ($pillSlugs as $slug) {
            $slug = (string) $slug;
            if (isset($availableChainsBySlug[$slug])) {
                $pillChains[] = $availableChainsBySlug[$slug];
            }
        }
        ?>
        <div class="wrap">
            <h1>Verify Collections</h1>

            <p><strong>Verification and community creation are two separate
            decisions.</strong> Marking a collection <strong>On-Chain Verified</strong>
            says its identity is sound — it does <em>not</em> create a community.
            To create one, use <em>Request community</em> on the row; the daily
            sweep creates requested communities, or use
            <em>Process requested communities</em> to run it now. Holders see
            "you qualify" suggestions; joining is explicit
            (suggest-don't-auto-join).</p>

            <?php foreach ($notices as $notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>

            <?php
            // Four collection-state sub-tabs. Switching state resets
            // pagination (paged=false) but preserves chain + token_standard
            // filters so the operator stays in the same scope.
            $tabBaseArgs = ['page' => self::PAGE_SLUG, 'paged' => false];
            if ($selectedChain !== '') {
                $tabBaseArgs['chain'] = $selectedChain;
            }
            if ($selectedTokenStandard !== '') {
                $tabBaseArgs['token_standard'] = $selectedTokenStandard;
            }
            ?>
            <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
                <?php foreach (CollectionStateClassifier::tabs() as $tabKey):
                    $tabUrl = add_query_arg(
                        $tabBaseArgs + ['vstate' => $tabKey],
                        admin_url('admin.php')
                    );
                    ?>
                    <a href="<?php echo esc_url($tabUrl); ?>"
                       class="nav-tab <?php echo $vstate === $tabKey ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html(CollectionStateClassifier::tabLabel($tabKey)); ?>
                        <span class="count">(<?php
                            // A count we could not compute is shown as "—",
                            // never as 0. A zero that means "the query failed"
                            // is how an operator concludes there is nothing to
                            // do when there might be a great deal.
                            echo $countsAvailable
                                ? esc_html(number_format_i18n($stateCounts[$tabKey] ?? 0))
                                : '&mdash;';
                        ?>)</span>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php if (!$countsAvailable): ?>
                <div class="notice notice-warning">
                    <p>Tab counts could not be read, so they are shown as &mdash;.
                    The rows below are still accurate for this tab.</p>
                </div>
            <?php endif; ?>

            <?php if (!$listing['available']): ?>
                <div class="notice notice-error">
                    <p><strong>This tab could not be loaded.</strong> Nothing is shown
                    rather than a partial or mislabelled list. See the bcc-trust error
                    log.</p>
                </div>
            <?php endif; ?>

            <?php if ($vstate === CollectionStateClassifier::TAB_NEEDS_ATTENTION): ?>
                <p style="margin:-6px 0 12px 0;color:#646970;font-size:12px;">
                    Rows that no other tab describes honestly: verified with no
                    community, a request that has not been created yet, a failed
                    creation, a community whose collection is no longer verified, or a
                    community whose on-chain identity cannot be resolved.
                </p>
            <?php endif; ?>

            <?php if ($vstate === CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR): ?>
                <p style="margin:-6px 0 12px 0;color:#646970;font-size:12px;">
                    Collections an operator has hidden with a platform-wide DENY rule.
                    This records an operator decision — it is not a claim that the
                    collection is a scam, and nothing in the database records one.
                </p>
            <?php endif; ?>

            <?php if ($vstate === CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED && $demandRanked): ?>
                <p style="margin:-6px 0 12px 0;color:#646970;font-size:12px;">
                    Queue ranked by <strong>Linked holders</strong> — collections that
                    real platform wallets hold sort first. Linked holders are counted from
                    BCC's own holdings index, which covers EVM and Solana; other chains show
                    <em>Not calculated</em>.
                </p>
            <?php endif; ?>

            <div style="margin:0 0 16px 0;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px;background:#fff;">
                <strong>Adding a collection has moved.</strong>
                <p style="color:#646970;margin:6px 0 0 0;">
                    Manual intake now lives in the NFT Discovery control plane, where the
                    chain is selected explicitly and the form is bound to it. This page had
                    two different add forms with different validation and different
                    provenance labelling; they are now one.
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'bcc-onchain-nft-discovery'], admin_url('admin.php'))); ?>">Open NFT Discovery</a>
                </p>
            </div>

            <?php if ($pillChains !== []): ?>
                <div style="margin:0 0 10px 0;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="margin-right:4px;">Quick filter:</strong>
                    <?php
                    // "All" pill — clears the chain filter while preserving
                    // other filter state (token_standard).
                    $allUrl = add_query_arg(
                        ['page' => self::PAGE_SLUG, 'chain' => false, 'paged' => false],
                        admin_url('admin.php')
                    );
                    if ($selectedTokenStandard !== '') {
                        $allUrl = add_query_arg('token_standard', $selectedTokenStandard, $allUrl);
                    }
                    $allUrl = add_query_arg('vstate', $vstate, $allUrl);
                    $allClass = $selectedChain === '' ? 'button button-primary' : 'button';
                    ?>
                    <a href="<?php echo esc_url($allUrl); ?>" class="<?php echo esc_attr($allClass); ?>">All</a>
                    <?php foreach ($pillChains as $pillChain):
                        $pillSlug   = (string) $pillChain->slug;
                        $isActive   = $selectedChain === $pillSlug;
                        // Clicking the active pill clears the filter; clicking
                        // an inactive pill switches to it. Preserve token_standard.
                        $pillUrl = add_query_arg(
                            [
                                'page'  => self::PAGE_SLUG,
                                'chain' => $isActive ? false : $pillSlug,
                                'paged' => false,
                            ],
                            admin_url('admin.php')
                        );
                        if ($selectedTokenStandard !== '') {
                            $pillUrl = add_query_arg('token_standard', $selectedTokenStandard, $pillUrl);
                        }
                        $pillUrl = add_query_arg('vstate', $vstate, $pillUrl);
                        $pillClass = $isActive ? 'button button-primary' : 'button';
                        ?>
                        <a href="<?php echo esc_url($pillUrl); ?>"
                           class="<?php echo esc_attr($pillClass); ?>"
                           aria-pressed="<?php echo $isActive ? 'true' : 'false'; ?>">
                            <?php echo esc_html((string) $pillChain->name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>


            <form method="get" action="" style="margin:0 0 12px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <input type="hidden" name="vstate" value="<?php echo esc_attr($vstate); ?>">
                <span>
                    <label for="bcc-vc-chain-filter" style="margin-right:6px;">
                        <strong>Chain:</strong>
                    </label>
                    <select name="chain" id="bcc-vc-chain-filter" onchange="this.form.submit()">
                        <option value="">All chains</option>
                        <?php foreach ($availableChains as $chainOption): ?>
                            <option value="<?php echo esc_attr((string) $chainOption->slug); ?>"
                                <?php selected($selectedChain, (string) $chainOption->slug); ?>>
                                <?php echo esc_html((string) $chainOption->name); ?>
                                (<?php echo esc_html((string) $chainOption->chain_type); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <?php if ($availableStandards !== []): ?>
                    <span>
                        <label for="bcc-vc-token-filter" style="margin-right:6px;">
                            <strong>Token standard:</strong>
                        </label>
                        <select name="token_standard" id="bcc-vc-token-filter" onchange="this.form.submit()">
                            <option value="">All standards</option>
                            <?php foreach ($availableStandards as $standard): ?>
                                <option value="<?php echo esc_attr($standard); ?>"
                                    <?php selected($selectedTokenStandard, $standard); ?>>
                                    <?php echo esc_html($standard); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                <?php endif; ?>
                <noscript>
                    <button type="submit" class="button">Filter</button>
                </noscript>
            </form>

            <?php
            // NO SHARED NONCE ON THIS FORM ANY MORE.
            //
            // It carried `bcc_verify_collections_nonce` for as long as
            // handlePost() still dispatched real actions from it. VC-B3b
            // moved the last of those (pause / resume / backfill / retry) to
            // Chains ▸ NFT Discovery, so nothing verifies that token now —
            // and a nonce nobody checks is not defence, it is a token minted
            // on every page load that only teaches the next reader the form
            // is protected.
            //
            // The form still exists and still posts to the page, because it
            // carries the VC-A verification checkboxes. Every control that
            // acts overrides the destination with `formaction` and brings its
            // OWN action-scoped nonce: Save and Create Communities below,
            // and the per-row Hide/Unhide, Remove and Test CW-721 forms that
            // are emitted after this one closes.
            ?>
            <?php
            // Collection ids that rendered a VC-A per-row control. Their
            // dedicated forms are emitted after this one closes, because a
            // form cannot be nested inside another.
            $vcRowForms   = [];
            $vcHiddenById = [];
            $vcIntentById = [];
            ?>
            <form method="post" action="">
                <?php wp_nonce_field(self::ACTION_SAVE, '_vc_save_nonce', false); ?>
                <?php wp_nonce_field(self::ACTION_PROVISION, '_vc_provision_nonce', false); ?>
                <input type="hidden" name="paged" value="<?php echo (int) $page; ?>">
                <input type="hidden" name="chain" value="<?php echo esc_attr($selectedChain); ?>">
                <input type="hidden" name="token_standard" value="<?php echo esc_attr($selectedTokenStandard); ?>">
                <input type="hidden" name="vstate" value="<?php echo esc_attr($vstate); ?>">

                <p class="submit" style="margin:0 0 12px 0;">
                    <button type="submit"
                            class="button button-primary"
                            name="action"
                            value="<?php echo esc_attr(self::ACTION_SAVE); ?>"
                            formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>">Save Verification Changes</button>
                    <button type="submit"
                            name="action"
                            value="<?php echo esc_attr(self::ACTION_PROVISION); ?>"
                            formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                            class="button"
                            onclick="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral(
                                "Create the communities that have been REQUESTED?\n\n"
                                . 'This processes only collections an administrator explicitly requested a community for. '
                                . 'Verified collections with no recorded request are not touched — verification on its own '
                                . 'no longer creates anything.'
                                . "\n\n"
                                . 'This does not save your tick boxes first: any unsaved changes on this page are ignored '
                                . 'and will revert. Existing communities are left untouched and no members are added.'
                            )); ?>);">Process requested communities</button>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:90px;">Verified</th>
                            <th style="width:60px;"></th>
                            <th>Collection</th>
                            <th style="width:90px;">Source</th>
                            <th>Chain</th>
                            <th>Contract</th>
                            <th style="width:90px;"
                                title="Users who explicitly opted in via the stance panel — the strongest demand signal (airdrop-proof). The unverified queue ranks by it.">
                                Waitlist
                            </th>
                            <th style="width:80px;"
                                title="Users who flagged this as airdropped scam junk. Flagged rows sink to the bottom; at the soft-hide threshold the collection stops surfacing to users.">
                                Flags
                            </th>
                            <th style="width:110px;"
                                title="Linked platform wallets BCC's holdings index records as holding this collection (EVM and Solana only; passive — airdrops inflate it; tiebreaker only). Never fetched from outside services while this page loads.">
                                Linked holders
                            </th>
                            <th style="width:100px;" title="Marketplace-wide unique holders (upstream metadata).">Holders</th>
                            <th style="width:160px;" title="Members of the collection's holder community (only meaningful once verified).">
                                Community
                            </th>
                            <th style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($listing['items'] === []): ?>
                            <tr>
                                <td colspan="<?php echo (int) self::TABLE_COLSPAN; ?>"><em>
                                    <?php
                                    // The empty state is per tab, and says nothing about
                                    // rows in the OTHER tabs — "no collections" when three
                                    // other tabs are full is how an operator concludes the
                                    // page is broken.
                                    switch ($vstate) {
                                        case CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY:
                                            echo 'No verified collections with a community yet. Verify a collection, then use Request community.';
                                            break;
                                        case CollectionStateClassifier::TAB_NEEDS_ATTENTION:
                                            echo 'Nothing needs attention. Every collection is either healthy, hidden, or awaiting review.';
                                            break;
                                        case CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR:
                                            echo 'No collections are hidden.';
                                            break;
                                        default:
                                            echo 'No unverified collections. Add one from NFT Discovery, or connect a wallet to populate this list.';
                                    }
                                    ?>
                                </em></td>
                            </tr>
                        <?php else: foreach ($listing['items'] as $row): ?>
                            <?php
                            $tokenStandard = (string) ($row->token_standard ?? '');
                            $rowId         = (int) $row->id;
                            $rowVerified   = (int) $row->is_verified === 1;
                            $source        = (string) ($row->source ?? 'discovery');
                            ?>
                            <tr data-collection-id="<?php echo $rowId; ?>">
                                <td>
                                    <input type="hidden" name="known[]" value="<?php echo $rowId; ?>">
                                    <label class="bcc-vc-toggle" style="display:inline-flex;align-items:center;gap:4px;">
                                        <input type="checkbox"
                                               class="bcc-vc-verify"
                                               name="verified[<?php echo $rowId; ?>]"
                                               value="1"
                                               <?php checked($rowVerified); ?>>
                                        <span class="bcc-vc-toggle-status" style="font-size:11px;color:#999;"></span>
                                    </label>
                                </td>
                                <td>
                                    <?php if (!empty($row->image_url)): ?>
                                        <img src="<?php echo esc_url($row->image_url); ?>"
                                             alt=""
                                             style="width:40px;height:40px;border-radius:4px;object-fit:cover;">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($row->collection_name ?? '(no name)'); ?></strong>
                                    <?php if ($tokenStandard !== ''): ?>
                                        <br>
                                        <span style="color:#646970;font-size:11px;"><?php echo esc_html($tokenStandard); ?></span>
                                    <?php endif; ?>
                                    <?php
                                    // DECISION 17's reader. Rendered inside the
                                    // name cell deliberately: a new column would
                                    // shift every cell index downstream, and the
                                    // description belongs with the collection it
                                    // describes rather than in a column that is
                                    // empty for most rows.
                                    self::renderDescriptionReview($row);
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    // Source badge — where the row came from.
                                    //   manual    → operator added it by hand (safe to remove)
                                    //   toplist   → auto-pulled from a chain's top-collections
                                    //               sync (was 'stargaze' pre-Hub-migration)
                                    //   discovery → seen by the holdings transfer indexer
                                    $badge = [
                                        'manual'    => ['Manual', '#2271b1'],
                                        'toplist'   => ['Top list', '#646970'],
                                        'discovery' => ['Discovered', '#646970'],
                                    ][$source] ?? ['Discovered', '#646970'];
                                    ?>
                                    <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr($badge[1]); ?>;">
                                        <?php echo esc_html($badge[0]); ?>
                                    </span>
                                </td>
                                <td><code><?php echo esc_html($row->chain_slug); ?></code></td>
                                <td>
                                    <code style="font-size:11px;"><?php echo esc_html($row->contract_address); ?></code>
                                    <?php
                                    // V2 Phase 2: per-row CW-721 sanity-check button. Only
                                    // shown on cosmos-typed rows because the test validates
                                    // CW-721 `contract_info`; clicking it from a non-cosmos
                                    // row would emit a "wrong chain type" notice (handler
                                    // covers gracefully).
                                    $isCosmos = (string) ($row->chain_type ?? '') === 'cosmos';
                                    if ($isCosmos):
                                    ?>
                                        <br>
                                        <?php $vcRowForms[(int) $row->id] = true; ?>
                                        <button type="submit"
                                                form="vc-a-test-<?php echo (int) $row->id; ?>"
                                                class="button button-small"
                                                style="margin-top:4px;font-size:11px;"
                                                title="Run CW-721 contract_info — confirms the contract is a real CW-721 NFT before flipping is_verified.">
                                            Test CW-721
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <?php
                                $rowKey    = CollectionDemandService::key((int) $row->chain_id, (string) $row->contract_address);
                                $rowSignal = $signals[$rowKey] ?? ['waitlist' => 0, 'spam' => 0];
                                ?>
                                <td>
                                    <?php if ($rowSignal['waitlist'] > 0): ?>
                                        <strong style="color:#2271b1;"><?php echo number_format_i18n($rowSignal['waitlist']); ?></strong>
                                    <?php else: ?>
                                        <span style="color:#999;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rowSignal['spam'] > 0): ?>
                                        <strong style="color:#d63638;">⚑ <?php echo number_format_i18n($rowSignal['spam']); ?></strong>
                                    <?php else: ?>
                                        <span style="color:#999;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    // ⚠ FOUR STATES, AND NONE OF THEM IS A FABRICATED ZERO.
                                    // The dash this cell used to print for "no count" meant
                                    // "nobody holds it" to a reader, whether the truth was
                                    // that, a failed read, or a chain BCC never counts.
                                    $demandCell = CollectionDemandService::rowState(
                                        $demand,
                                        (string) ($row->chain_type ?? ''),
                                        (int) $row->chain_id,
                                        (string) $row->contract_address
                                    );
                                    switch ($demandCell['state']):
                                        case CollectionDemandService::STATE_COUNTED: ?>
                                        <strong style="color:#00a32a;"><?php echo number_format_i18n((int) $demandCell['count']); ?></strong>
                                        <?php break;
                                        case CollectionDemandService::STATE_NONE_INDEXED: ?>
                                        <span style="color:#646970;" title="No linked wallet is recorded as holding this collection in BCC's holdings index. The index can lag behind the chain.">None indexed</span>
                                        <?php break;
                                        case CollectionDemandService::STATE_UNAVAILABLE: ?>
                                        <span style="color:#646970;" title="Linked-holder counts could not be read completely just now, so this row's count is unknown.">Unavailable</span>
                                        <?php break;
                                        default: ?>
                                        <span style="color:#646970;" title="BCC keeps no holdings index for this chain, so it does not count linked holders here. Counts are never fetched from outside services while this page loads.">Not calculated</span>
                                    <?php endswitch; ?>
                                </td>
                                  <td>
                                      <?php
                                      // ⚠ NULL IS NOT ZERO. `unique_holders` is
                                      // `INT UNSIGNED DEFAULT NULL` and is only populated for
                                      // chains BCC counts marketplace-wide, so `?? 0` printed a
                                      // confident "0 holders" for "we have no figure".
                                      //
                                      // The cell immediately above already refuses exactly this
                                      // ("FOUR STATES, AND NONE OF THEM IS A FABRICATED ZERO"),
                                      // and the demand sort comparator maps null to -1 rather
                                      // than 0 for the same reason. This cell was the one place
                                      // that still conflated them.
                                      if ($row->unique_holders === null) {
                                          echo '<span style="color:#646970;" title="BCC has no marketplace-wide holder figure for this collection. This is not a count of zero.">&mdash;</span>';
                                      } else {
                                          echo number_format_i18n((int) $row->unique_holders);
                                      }
                                      ?>
                                  </td>
                                  <td class="bcc-vc-community">
                                      <?php
                                      // ⚠ RENDER THE CAUSE THE CLASSIFIER ALREADY COMPUTES.
                                      // CollectionStateClassifier::attentionCause() and
                                      // causeLabel() existed with no production caller — the
                                      // page printed one static paragraph listing all six
                                      // causes and left the operator to infer which applied to
                                      // each row. The classifier docblock claims each row is
                                      // "rendered with its cause"; this makes that true.
                                      //
                                      // Shown only on the Needs-attention tab, where it is the
                                      // question being asked. All four inputs are already on
                                      // the projected row, so there is no extra query.
                                      if ($vstate === CollectionStateClassifier::TAB_NEEDS_ATTENTION) {
                                          $cause = CollectionStateClassifier::attentionCause(
                                              (int) ($row->is_verified ?? 0) === 1,
                                              $row->canonical_identifier !== null,   // matches the tab predicate exactly:
                                              // CollectionStateClassifier uses
                                              // `canonical_identifier IS NOT NULL`, so an
                                              // empty string must NOT be treated as unresolved
                                              // or the rendered cause could disagree with the
                                              // predicate that selected the row onto this tab.
                                              (int) ($row->has_community ?? 0) === 1,
                                              (string) ($row->provisioning_state ?? ProvisioningState::NONE)
                                          );
                                          if ($cause !== null) {
                                              printf(
                                                  '<div style="font-weight:600;margin-bottom:2px;">%s</div>',
                                                  esc_html(CollectionStateClassifier::causeLabel($cause))
                                              );
                                          }
                                      }
                                      ?>
                                    <?php
                                    // PR 6: community existence is PROJECTED by
                                    // listForAdminState(), so the old per-row
                                    // findGroupForCollection() N+1 is gone — one
                                    // page used to issue 50 of them.
                                    //
                                    // Cell semantics now follow the state machine,
                                    // not verification:
                                    //   community exists → linked member count
                                    //   requested        → "awaiting creation"
                                    //   failed           → the bounded failure reason
                                    //   none             → what to do about it
                                    $hasCommunity = (int) ($row->has_community ?? 0) === 1;
                                    $rowState     = (string) ($row->provisioning_state ?? ProvisioningState::NONE);

                                    if ($hasCommunity) {
                                        $groupId = GatedGroupRepository::findGroupForCollection(
                                            (int) $row->chain_id,
                                            (string) ($row->canonical_identifier ?? $row->contract_address)
                                        );
                                        if ($groupId !== null) {
                                            $count     = PeepSoGroupRepository::countGroupMembers($groupId);
                                            $permalink = get_permalink($groupId);
                                            $label     = number_format_i18n($count) . ' member' . ($count === 1 ? '' : 's');
                                            if (is_string($permalink) && $permalink !== '') {
                                                printf(
                                                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                                                    esc_url($permalink),
                                                    esc_html($label)
                                                );
                                            } else {
                                                echo esc_html($label);
                                            }
                                        } else {
                                            // The set-based predicate found a community
                                            // the canonical lookup cannot resolve. Say so
                                            // rather than rendering "no community", which
                                            // would invite creating a second one.
                                            echo '<span style="color:#d63638;">community present, identity unresolved</span>';
                                        }
                                    } elseif ($rowState === ProvisioningState::REQUESTED) {
                                        echo '<span style="color:#2271b1;">Requested &mdash; awaiting creation</span>';
                                        ?>
                                        <button type="button"
                                                class="button button-small bcc-vc-create-community"
                                                title="Create the requested community now, instead of waiting for the daily sweep.">
                                            Create now
                                        </button>
                                        <?php
                                    } elseif ($rowState === ProvisioningState::FAILED) {
                                        $code = (string) ($row->provisioning_failure_code ?? '');
                                        printf(
                                            '<span style="color:#d63638;">Failed: %s</span>',
                                            esc_html(ProvisioningFailureCode::label($code))
                                        );
                                      } elseif ($rowState === ProvisioningState::PROVISIONED) {
                                          // ⚠ `provisioned` WITH NO LIVE COMMUNITY IS A
                                          // CONTRADICTION, not an absence. The community was
                                          // trashed or deleted out from under the row.
                                          //
                                          // This arm used to fall through to "No community
                                          // requested" below, which is the mis-render
                                          // CollectionStateClassifier::attentionCause()
                                          // explicitly warns against: it "would invite an
                                          // operator to create a second one". The adjacent
                                          // identity-unresolved arm above already applies that
                                          // reasoning; this state was the one that did not.
                                          printf(
                                              '<span style="color:#d63638;">%s</span>',
                                              esc_html(CollectionStateClassifier::causeLabel(
                                                  CollectionStateClassifier::CAUSE_CONTRADICTORY_STATE
                                              ))
                                          );
                                      } elseif ($rowVerified) {
                                          echo '<span style="color:#646970;">No community requested</span>';
                                    } else {
                                        echo '<span style="color:#999;">&mdash;</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    // Hide/Unhide — the flag-don't-delete kill switch. A
                                    // DENY rule on the contract survives rediscovery
                                    // (delete doesn't: the next wallet link would land
                                    // the row again).
                                    // PR 6: projected by listForAdminState() using the
                                    // SAME predicate the tabs classify on, so the badge
                                    // and the tab can never disagree. It also removes the
                                    // second per-row lookup on this page.
                                    $isHidden = (int) ($row->is_hidden ?? 0) === 1;
                                    ?>
                                    <?php if ($isHidden): ?>
                                        <span style="display:inline-block;margin-bottom:4px;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:#d63638;">HIDDEN</span>
                                    <?php endif; ?>
                                    <?php
                                    // VC-B1: the button reaches its own form
                                    // via form=, because HTML forbids nesting
                                    // one form inside another and this row
                                    // sits inside the big verification form.
                                    self::renderHideButton($rowId, $isHidden);
                                    $vcRowForms[$rowId]  = true;
                                    $vcHiddenById[$rowId] = $isHidden;

                                    // PR 6: the community-intent affordance.
                                    // Projected on the row by listForAdminState(),
                                    // so this costs no query.
                                    $rowIntent = (string) ($row->provisioning_state ?? ProvisioningState::NONE);
                                    $vcIntentById[$rowId] = $rowIntent;
                                    self::renderIntentButton($rowId, $rowIntent);
                                    ?>
                                    <button type="submit"
                                            form="vc-a-del-<?php echo $rowId; ?>"
                                            class="button button-small button-link-delete"
                                            style="color:#b32d2e;"
                                            onclick="return confirm('Remove this collection from the list? This deletes the row only — rediscovery can bring it back. Use Hide to keep it away permanently. A collection with a live community can\'t be removed until its community is gone.');">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit"
                            class="button"
                            name="action"
                            value="<?php echo esc_attr(self::ACTION_SAVE); ?>"
                            formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>">Save Verification Changes</button>
                    <span style="color:#646970;font-size:12px;margin-left:8px;">
                        Verify toggles save instantly. This button is a fallback if JavaScript is off.
                    </span>
                </p>
            </form>

            <?php
            // VC-A per-row forms.
            //
            // Emitted here rather than inside the table because HTML forbids
            // nested forms; the buttons in the rows reach them through the
            // HTML5 `form=` attribute. Each carries its OWN target-scoped
            // nonce, so a Remove nonce for collection 7 cannot delete
            // collection 8 and cannot run a CW-721 probe either.
            self::renderRowActionForms(
                array_map('intval', array_keys($vcRowForms)),
                $page,
                $selectedChain,
                $selectedTokenStandard,
                $vstate,
                $vcHiddenById,
                $vcIntentById
            );

            // The description review forms, for the same reason and by the
            // same mechanism. Driven from the listing rows rather than from
            // $vcRowForms because only rows whose description is PENDING get a
            // form at all.
            self::renderDescriptionReviewForms(
                is_array($listing['items'] ?? null) ? $listing['items'] : [],
                $page,
                $selectedChain,
                $selectedTokenStandard,
                $vstate
            );
            ?>

            <?php if ($listing['pages'] > 1): ?>
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $listing['pages'],
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        // Per-row, per-intent AJAX nonces.
        //
        // One page-wide nonce previously covered both AJAX actions and every
        // row, so a nonce handed to the page could verify any collection,
        // unverify any collection, or provision any community. Each intent
        // now gets its own nonce bound to the collection id — and, for the
        // toggle, to the direction as well.
        $vcAjaxNonces = [];
        foreach ($listing['items'] as $nonceRow) {
            $nonceRowId = (int) $nonceRow->id;
            $vcAjaxNonces[$nonceRowId] = [
                'verify'    => wp_create_nonce(self::AJAX_ACTION_TOGGLE . '_' . $nonceRowId . '_1'),
                'unverify'  => wp_create_nonce(self::AJAX_ACTION_TOGGLE . '_' . $nonceRowId . '_0'),
                'provision' => wp_create_nonce(self::AJAX_ACTION_PROVISION . '_' . $nonceRowId),
            ];
        }
        ?>
        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCES  = <?php echo wp_json_encode($vcAjaxNonces); ?>;
            var ACTION_TOGGLE    = <?php echo wp_json_encode(self::AJAX_ACTION_TOGGLE); ?>;
            var ACTION_PROVISION = <?php echo wp_json_encode(self::AJAX_ACTION_PROVISION); ?>;

            function nonceFor(collectionId, intent) {
                var row = NONCES[String(collectionId)];
                return row ? row[intent] : '';
            }

            function post(action, collectionId, intent, extra) {
                var body = new FormData();
                body.append('action', action);
                body.append('nonce', nonceFor(collectionId, intent));
                body.append('collection_id', collectionId);
                if (extra) { Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); }); }
                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); });
            }

            // Instant verify toggle.
            document.querySelectorAll('.bcc-vc-verify').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var row    = cb.closest('tr');
                    var id     = row && row.getAttribute('data-collection-id');
                    var status = row && row.querySelector('.bcc-vc-toggle-status');
                    if (!id) { return; }
                    cb.disabled = true;
                    if (status) { status.textContent = 'saving…'; status.style.color = '#999'; }
                    post(ACTION_TOGGLE, id, cb.checked ? 'verify' : 'unverify', { verify: cb.checked ? '1' : '0' })
                        .then(function (resp) {
                            cb.disabled = false;
                            if (resp && resp.success) {
                                if (status) { status.textContent = '✓'; status.style.color = '#00a32a'; }
                                setTimeout(function () { if (status) { status.textContent = ''; } }, 1500);
                            } else {
                                cb.checked = !cb.checked; // revert
                                if (status) { status.textContent = '✗'; status.style.color = '#d63638'; }
                            }
                        })
                        .catch(function () {
                            cb.disabled = false;
                            cb.checked = !cb.checked;
                            if (status) { status.textContent = '✗'; status.style.color = '#d63638'; }
                        });
                });
            });

            // Inline "Create now" community provisioning.
            document.querySelectorAll('.bcc-vc-create-community').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row  = btn.closest('tr');
                    var cell = btn.closest('.bcc-vc-community');
                    var id   = row && row.getAttribute('data-collection-id');
                    if (!id) { return; }
                    btn.disabled = true;
                    btn.textContent = 'Creating…';
                    post(ACTION_PROVISION, id, 'provision')
                        .then(function (resp) {
                            if (resp && resp.success) {
                                if (cell) { cell.innerHTML = '<span style="color:#00a32a;font-size:12px;">Community created</span>'; }
                            } else {
                                btn.disabled = false;
                                btn.textContent = 'Create now';
                                alert((resp && resp.data && resp.data.message) || 'Could not create community.');
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            btn.textContent = 'Create now';
                            alert('Network error creating community.');
                        });
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * INERT. Every action this dispatcher once owned has moved.
     *
     * ── WHY IT STILL EXISTS ─────────────────────────────────────────────
     * A browser tab opened before the moves still carries the old markup
     * and will POST `bcc_vc_action` here. Falling through silently would
     * render a perfectly normal page and let an operator believe a scam
     * contract had been hidden, a chain opted out, or a backfill started,
     * when nothing whatsoever happened. So a stale submission is answered
     * explicitly.
     *
     * ── WHY IT NO LONGER CHECKS A NONCE ─────────────────────────────────
     * It performs no lookup, no write, no provider call and no audit, so
     * there is nothing here for a nonce to protect. Keeping the broad
     * `bcc_verify_collections_nonce` alive purely to decorate a warning
     * would preserve the exact excessive-authority token this programme
     * spent three batches removing. The nonce is gone with the last
     * action that needed it.
     *
     * ── WHAT IT MUST NOT DO ─────────────────────────────────────────────
     * The submitted value is attacker-controlled. It is never echoed, never
     * logged, and never used to decide anything beyond "something stale
     * arrived" — so this cannot become a log-injection or reflection
     * surface on the way out.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handlePost(): array
    {
        if (empty($_POST['bcc_vc_action'])) {
            return [];
        }

        \BCC\Core\Log\Logger::warning('[bcc-trust] Verify Collections: stale legacy submission refused', [
            'action'   => 'verify_collections_stale_post',
            'operator' => get_current_user_id(),
        ]);

        return [[
            'type'    => 'error',
            'message' => 'That control has moved and this page is out of date, so NOTHING was changed — '
                . 'no rule was written, no chain setting was touched, and no scanner work was started. '
                . 'Reload this page. Hide and Unhide are here; discovery, pause, resume, backfill and '
                . 'retry are in Chains ▸ NFT Discovery.',
        ]];
    }

    /**
     * Hide = RULE_DENY (drops from every user-facing surface + blocks
     * rediscovery). Unhide = RULE_ALLOW (explicit operator allow — also
     * wins over the name heuristics, which is exactly what "I looked at
     * this and it's fine" means).
     *
     * ── THE RULE IS THE WHOLE JOB (S3) ──────────────────────────────────
     * This handler writes the authoritative rule and nothing else. It used
     * to also push the CosmWasm scanner's `denied` flag — a CACHE on the
     * scanner's own inventory, kept so its queue predicates stayed cheap
     * and indexed — and then report partial completion when that push could
     * not be confirmed. That coupling is gone.
     *
     * ⚠ WHY REMOVING IT CHANGES NOTHING AN OPERATOR OR A MEMBER CAN SEE.
     * The cache was only ever read by the scanner's own queue
     * ({@see CosmwasmContractRepository::findEmittable()}) and its own count
     * ({@see CosmwasmContractRepository::countDenied()}). The authority is
     * `wp_bcc_nft_spam_contracts.rule`, in a DIFFERENT table, and the emit
     * path re-checks it live on every pass — which is exactly what
     * `test_the_live_rule_recheck_still_blocks_the_emit_when_the_cached_flag_goes_stale`
     * asserts, and that test is unchanged by this commit. A stale flag
     * therefore costs a candidate being reconsidered and re-refused each
     * sweep, never a hidden collection becoming visible.
     *
     * And the scanner is FROZEN — `ScannerFreeze` refuses every entry point,
     * so nothing sweeps, nothing emits and nothing reads the cache at all.
     * The column stops being updated from here; existing values go inert and
     * are dropped with the table in S9.
     *
     * ⚠ PUBLIC VISIBILITY, JOINING, MEMBERSHIP AND REVOCATION ARE UNTOUCHED.
     * None of them ever consulted the scanner cache; they read the rule and
     * the holdings path, neither of which this commit goes near.
     *
     * ── THE LOOKUP IS KEYED, NOT POSITIONAL ─────────────────────────────
     * {@see CollectionRepository::findManyByIds()} returns a map keyed by
     * collection id. This handler read `$rows[0]`, which is null for every
     * id the admin page can actually emit, so hide and unhide both stopped
     * at "collection not found" and never wrote a rule at all. See the
     * comment on the lookup below before touching it.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleHideToggle(int $collectionId, bool $hide): array
    {
        // THE LOOKUP CONTRACT. `findManyByIds()` ends
        // `$map[(int) $row->id] = $row` — a MAP KEYED BY COLLECTION ID,
        // not a positional list. The original `$rows[0]` therefore
        // resolved to null for every id except the impossible 0, and
        // EVERY hide and unhide answered "Hide: collection not found."
        // while writing nothing. Proven on staging:
        // `findManyByIds_keys=261764, rows[0]_is_NULL`.
        //
        // Index by the id that was asked for. NOT `reset()`, NOT
        // `array_values()[0]`, NOT `current()` — a first-row shortcut
        // turns a wrongly-shaped map into a plausible WRONG answer, and
        // the wrong answer here is a permanent DENY rule on somebody
        // else's contract.
        $rows = CollectionRepository::findManyByIds([$collectionId]);
        $row  = $rows[$collectionId] ?? null;
        if ($row === null) {
            return [['type' => 'error', 'message' => 'Hide: collection not found.']];
        }

        // Belt AND braces. The map key already implies this for the real
        // repository — that is exactly why it is cheap to assert and why
        // it stays: one comparison makes "we denied the wrong contract"
        // unreachable however the lookup is re-implemented later. A row
        // whose own id disagrees with the key is a data-integrity anomaly,
        // not a miss, so it is logged and NOTHING is written: no rule, no
        // cached flag, no inventory sync.
        $rowId = (int) $row->id;
        if ($rowId !== $collectionId) {
            \BCC\Core\Log\Logger::error('[bcc-trust] hide toggle: collection lookup returned a mismatched row', [
                'action'       => 'verify_collections_hide_id_mismatch',
                'requested_id' => $collectionId,
                'returned_id'  => $rowId,
                'operator'     => get_current_user_id(),
            ]);

            return [[
                'type'    => 'error',
                'message' => 'Hide: the collection lookup returned a different collection than the one asked for. '
                    . 'Nothing was changed. Check the bcc-trust error log.',
            ]];
        }

        $chainId  = (int) $row->chain_id;
        $contract = (string) $row->contract_address;
        $rule     = $hide
            ? \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::RULE_DENY
            : \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::RULE_ALLOW;

        $ok = \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::addRule(
            $chainId,
            $contract,
            $rule,
            sprintf('operator %s via Verify Collections', $hide ? 'hide' : 'unhide')
        );

        if (!$ok) {
            // The rule did not land, so the cached flag is deliberately NOT
            // touched: syncing it here would suppress (or un-suppress) a
            // contract on the strength of a rule that does not exist.
            //
            // THIS IS A DURABLE FAILURE, not a validation mistake. Capability,
            // method, target and nonce all passed — an authorised operator
            // asked for a state change and the authoritative write refused
            // it. "Nothing changed" is exactly the fact worth being able to
            // reconstruct later, so it gets a row.
            //
            // Same action name as an unexpected exception, deliberately:
            // both mean "the authoritative rule was NOT applied", which is
            // the only thing the durable row can carry. The cause lives in
            // the technical log. No correlation ID is minted — that contract
            // belongs to AdminActionSupport::failure() and is reserved for
            // exceptions; inventing one here would promise an engineer a
            // stack trace that was never captured.
            \BCC\Core\Log\Logger::error('[bcc-trust] Verify Collections hide toggle: rule write refused', [
                'action'        => 'verify_collections_hide_rule_write_failed',
                'collection_id' => $collectionId,
                'chain_id'      => $chainId,
                'contract'      => $contract,
                'rule'          => $rule,
                'operator'      => get_current_user_id(),
            ]);

            AdminActionSupport::audit(
                self::hideFailedAction($hide),
                'collection',
                $collectionId,
                ['chain_id' => $chainId, 'contract' => $contract, 'rule' => $rule]
            );

            return [['type' => 'error', 'message' => 'Hide: rule write failed. Check the bcc-trust error log.']];
        }

        // THE DURABLE RECORD (VC-B1).
        //
        // The outcome is in the ACTION NAME, not in $meta: AuditLogger::log()
        // accepts $meta and the insert array drops it, so anything encoded
        // there is not durable.
        //
        // ⚠ THE VOCABULARY NARROWED IN S3, AND THE OLD NAMES STAY VALID.
        // `admin_vc_hide_sync_unconfirmed` and its unhide twin can no longer
        // be emitted, because there is no cache push left to be unconfirmed.
        // Rows already carrying them are ACCURATE HISTORY and must not be
        // rewritten or reinterpreted: each one means "the rule was written
        // and the scanner cache push could not be confirmed", which was true
        // when it was recorded.
        //
        // The target is the COLLECTION row — never the chain. A chain id
        // stored under target_type 'collection' would be indistinguishable
        // from a real collection id in any later forensic query.
        AdminActionSupport::audit(
            self::hideAuditAction($hide),
            'collection',
            $collectionId,
            ['chain_id' => $chainId, 'contract' => $contract, 'rule' => $rule]
        );

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections hide toggle', [
            'action'        => 'verify_collections_hide',
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
            'contract'      => $contract,
            'rule'          => $rule,
            'operator'      => get_current_user_id(),
        ]);

        $name = (string) ($row->collection_name ?? $contract);

        return [[
            'type'    => 'success',
            'message' => sprintf(
                '%s "%s" %s users (%s rule on the contract).',
                $hide ? 'Hid' : 'Restored',
                $name,
                $hide ? 'from' : 'for',
                $rule
            ),
        ]];
    }

    /**
     * The VC-B1 audit vocabulary, in one place.
     *
     * Four names survive S3, all inside the 50-character `action` column:
     *
     *   admin_vc_hide_applied      (21) the rule was written
     *   admin_vc_unhide_applied    (23) the rule was written
     *   admin_vc_hide_failed       (20) the rule was NOT written
     *   admin_vc_unhide_failed     (22) the rule was NOT written
     *
     * ⚠ TWO RETIRED NAMES ARE STILL VALID HISTORY.
     * `admin_vc_hide_sync_unconfirmed` and `admin_vc_unhide_sync_unconfirmed`
     * are no longer emitted — S3 removed the scanner-cache push they
     * described, so the condition cannot arise. Existing rows keep them AND
     * keep their meaning: at the moment each was written, the rule HAD been
     * written and the cache push could not be confirmed. Nothing migrates
     * them, and a forensic reader should not read them as errors.
     *
     * "applied" now carries exactly one fact, which is the only fact the
     * durable row could ever act on: the authoritative rule is in force.
     */
    private static function hideAuditAction(bool $hide): string
    {
        return $hide ? 'admin_vc_hide_applied' : 'admin_vc_unhide_applied';
    }
    /**
     * The "the authoritative rule was NOT applied" event.
     *
     *   admin_vc_hide_failed    (20)
     *   admin_vc_unhide_failed  (22)
     *
     * Shared by BOTH failure shapes on purpose: a repository write that
     * returned false, and an unexpected exception escaping the handler.
     * The durable row can only carry one fact, and for both shapes that
     * fact is identical — the operator asked, the rule did not land, and
     * nothing downstream was touched. Splitting them would invent a
     * distinction the row cannot act on; the CAUSE lives in the technical
     * log, which is where an engineer looks anyway.
     */
    private static function hideFailedAction(bool $hide): string
    {
        return $hide ? 'admin_vc_hide_failed' : 'admin_vc_unhide_failed';
    }

    /**
     * V2 Phase 2: pre-verify CW-721 sanity check. Hits the contract's
     * `contract_info` smart query via the per-chain fetcher; renders
     * the result as an admin notice. Catches:
     *   - non-CW-721 contracts (response shape mismatch)
     *   - chains without CosmWasm enabled (Crypto.org returns 501)
     *   - mis-pasted contract addresses (404 from the wasm module)
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleTestQuery(int $collectionId): array
    {
        if ($collectionId <= 0) {
            return [['type' => 'error', 'message' => 'Test query: invalid collection id.']];
        }

        $coll = CollectionRepository::getByIdWithChain($collectionId);
        if ($coll === null) {
            return [['type' => 'error', 'message' => 'Test query: collection not found.']];
        }

        $contract  = (string) $coll->contract_address;
        $chainSlug = (string) $coll->chain_slug;
        $chainType = (string) $coll->chain_type;

        if ($chainType !== 'cosmos') {
            return [[
                'type'    => 'warning',
                'message' => sprintf(
                    'Test query: %s is %s — this button only validates CW-721 (Cosmos) contracts.',
                    $contract,
                    $chainType
                ),
            ]];
        }

        $chain = ChainRepository::getById((int) $coll->chain_id);
        if ($chain === null) {
            return [['type' => 'error', 'message' => 'Test query: chain not found.']];
        }

        if (!FetcherFactory::has_driver($chainType)) {
            return [['type' => 'error', 'message' => 'Test query: no fetcher driver for ' . $chainSlug]];
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!($fetcher instanceof CosmosFetcher)) {
            return [['type' => 'error', 'message' => 'Test query: fetcher driver mismatch for ' . $chainSlug]];
        }

        $info = $fetcher->testCw721ContractInfo($contract);
        if ($info === null) {
            return [[
                'type'    => 'error',
                'message' => sprintf(
                    'Test query: contract_info call FAILED on %s for %s. Likely causes: contract is not CW-721, contract address is wrong, or the chain has no CosmWasm enabled (Crypto.org returns 501 Not Implemented). Check the bcc-trust error log for the LCD response.',
                    $chainSlug,
                    $contract
                ),
            ]];
        }

        $name = isset($info['name']) && is_string($info['name']) ? $info['name'] : '(missing)';
        $symbol = isset($info['symbol']) && is_string($info['symbol']) ? $info['symbol'] : '(missing)';
        return [[
            'type'    => 'success',
            'message' => sprintf(
                'Test query OK on %s — name="%s", symbol="%s". Safe to verify.',
                $chainSlug,
                $name,
                $symbol
            ),
        ]];
    }

    /**
     * Per-row "Remove" handler. Deletes a single collection row, with a
     * guard: a row whose holder community already exists is refused, so
     * deletion can't silently orphan a live PeepSo group. The operator
     * must unverify (and tear the group down) first.
     *
     * ── THE GUARD ASKS THE AUTHORITATIVE LINK, AND FAILS CLOSED ─────────
     * It used to ask `findGroupForCollection($chainId, $contract_address)`.
     * Two problems, both in the direction that permits a delete:
     *
     *   1. `_bcc_gate_contract_address` is legacy/display; the identity is
     *      `_bcc_gate_collection_id`. Matching on the contract string means
     *      canonicalising it first, and a value that is not a valid
     *      identity on its chain — the marketplace symbols the eight
     *      production Solana gates stored — returns null WITHOUT RUNNING A
     *      QUERY. A live community read as absent.
     *   2. A failed query also returns null, and null was read as
     *      permission to delete.
     *
     * Both are closed by keying on the row id and guarding the read:
     * {@see GatedGroupRepository::findPublishedGroupIdForCollectionId()}
     * throws rather than answering null when it could not look. Note the
     * row's own `contract_address` is still used for the MESSAGE and the
     * audit meta — it just no longer decides anything.
     *
     * The published-community policy is unchanged: only a published
     * `peepso-group` blocks, so a trashed group does not strand a row.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleDeleteCollection(int $collectionId): array
    {
        if ($collectionId <= 0) {
            return [['type' => 'error', 'message' => 'Remove: invalid collection id.']];
        }

        $coll = CollectionRepository::getByIdWithChain($collectionId);
        if ($coll === null) {
            return [['type' => 'error', 'message' => 'Remove: collection not found (already deleted?).']];
        }

        $contract = (string) $coll->contract_address;
        $chainId  = (int) $coll->chain_id;

        try {
            $groupId = GatedGroupRepository::findPublishedGroupIdForCollectionId($collectionId);
        } catch (RepositoryReadFailure $e) {
            // ⚠ FAIL CLOSED, AND NO DURABLE AUDIT ROW.
            //
            // Nothing was established about this collection, so nothing is
            // asserted about it. An audit row here would durably record a
            // statement about a community we never managed to look at — the
            // same reasoning as the AJAX provision path above. The fault
            // belongs in the application log, which the repository already
            // wrote once; this adds the operator-facing half.
            \BCC\Core\Log\Logger::error('[bcc-trust] Verify Collections remove: community check unreadable', [
                'action'        => 'verify_collections_remove_community_check_failed',
                'collection_id' => $collectionId,
                'method'        => $e->repositoryMethod(),
                'db_error'      => $e->dbError(),
                'operator'      => get_current_user_id(),
            ]);

            return [[
                'type'    => 'error',
                'message' => 'Remove: could not determine whether this collection still has a '
                    . 'holder community, so nothing was removed. This is a database read failure, '
                    . 'not a statement about the collection — try again, and if it persists check '
                    . 'the database before removing anything.',
            ]];
        }

        if ($groupId !== null) {
            return [[
                'type'    => 'warning',
                'message' => sprintf(
                    'Remove blocked: %s still has a holder community (group #%d). Unverify it and remove the community first, then delete.',
                    $contract,
                    $groupId
                ),
            ]];
        }

        $deleted = CollectionRepository::deleteById($collectionId);
        if ($deleted < 1) {
            // Authorized destructive operation that began and did not
            // complete — that gets a durable row, unlike an ordinary
            // validation rejection.
            AdminActionSupport::audit(
                'admin_vc_collection_delete_failed',
                'collection',
                $collectionId
            );
            return [['type' => 'error', 'message' => 'Remove: nothing was deleted.']];
        }

        AdminActionSupport::audit(
            'admin_vc_collection_deleted',
            'collection',
            $collectionId,
            ['chain_id' => $chainId, 'contract' => $contract]
        );

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections remove', [
            'action'        => 'verify_collections_remove',
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
            'contract'      => $contract,
            'operator'      => get_current_user_id(),
        ]);

        return [[
            'type'    => 'success',
            'message' => sprintf('Removed collection %s.', $contract),
        ]];
    }

    // ADD_TOKEN_STANDARDS was removed with the Add Collection form it
    // constrained. The consolidated form derives the standard from the
    // family instead of asking an operator to type one, so there is no
    // free-typed standard left to allowlist.


    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handleSave(): array
    {
        $knownRaw = isset($_POST['known']) && is_array($_POST['known'])
            ? $_POST['known']
            : [];

        // Positive integers only, de-duplicated. `known[]` is entirely
        // client-controlled and used to build an IN() list, so it is
        // normalised before it can reach the repository.
        $known = [];
        foreach ($knownRaw as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $known[$id] = true;
            }
        }
        $known = array_keys($known);

        // REJECT rather than truncate. One rendered page can submit at
        // most MAX_BULK_IDS checkboxes; more than that is not a bigger
        // page, it is a crafted payload. Truncating would report success
        // for rows that were never written.
        if (count($known) > self::MAX_BULK_IDS) {
            return [[
                'type'    => 'error',
                'message' => sprintf(
                    'Save rejected: %d collections submitted but at most %d can be saved in one request. Nothing was changed.',
                    count($known),
                    self::MAX_BULK_IDS
                ),
            ]];
        }

        $checkedRaw = isset($_POST['verified']) && is_array($_POST['verified'])
            ? $_POST['verified']
            : [];
        $checked = [];
        foreach ($checkedRaw as $id => $_v) {
            $checked[(int) $id] = true;
        }

        $verify   = [];
        $unverify = [];
        foreach ($known as $collectionId) {
            if (isset($checked[$collectionId])) {
                $verify[] = $collectionId;
            } else {
                $unverify[] = $collectionId;
            }
        }

        // PR 6: routed through the service, not straight to the repository.
        // The verification write, the withdrawal of any pending request on a
        // row being unverified, and the checked audit are ONE transaction —
        // so this can no longer report "saved" while a withdrawal silently
        // failed and left the daily sweep about to provision a community for
        // a collection nobody verifies any more.
        $result = OnchainPlugin::instance()->communityRequestService()->applyVerification(
            $verify,
            $unverify,
            get_current_user_id()
        );

        if (!$result['ok']) {
            return [[
                'type'    => 'error',
                'message' => 'Verification changes were rolled back; nothing was saved. See the bcc-trust error log.',
            ]];
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections save', [
            'action'     => 'verify_collections_save',
            'verified'   => count($verify),
            'unverified' => count($unverify),
            'changed'    => $result['changed'],
            'withdrawn'  => $result['withdrawn'],
            'operator'   => get_current_user_id(),
        ]);

        $message = sprintf(
            'Verification flags saved (%d processed, %d actually changed).',
            count($verify) + count($unverify),
            $result['changed']
        );

        if ($result['withdrawn'] > 0) {
            $message .= sprintf(
                ' %d pending community request%s withdrawn. Existing communities were not affected.',
                $result['withdrawn'],
                $result['withdrawn'] === 1 ? '' : 's'
            );
        }

        return [['type' => 'success', 'message' => $message]];
    }

    /**
     * Record or withdraw a community request for one collection.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleCommunityIntent(int $collectionId, bool $request): array
    {
        $service  = OnchainPlugin::instance()->communityRequestService();
        $operator = get_current_user_id();

        $result = $request
            ? $service->request($collectionId, $operator)
            : $service->withdraw($collectionId, $operator);

        if (!$result['ok']) {
            return [[
                'type'    => 'error',
                'message' => self::intentRefusalMessage((string) ($result['reason'] ?? '')),
            ]];
        }

        switch ($result['status']) {
            case 'requested':
                return [['type' => 'success', 'message' =>
                    'Community requested. The daily provisioning sweep will create it, or you can run "Process requested communities" now.']];
            case 'already_requested':
                return [['type' => 'info', 'message' => 'A community was already requested for this collection.']];
            case 'exists':
                return [['type' => 'info', 'message' => 'This collection already has a community.']];
            case 'withdrawn':
                return [['type' => 'success', 'message' => 'Community request withdrawn. Nothing was created or deleted.']];
            case 'nothing_pending':
                return [['type' => 'info', 'message' => 'There was no pending request to withdraw.']];
            case 'provisioned':
                return [['type' => 'info', 'message' =>
                    'This collection already has a community. Withdrawing a request never removes one.']];
            default:
                return [['type' => 'info', 'message' => 'No change was made.']];
        }
    }

    /**
     * Bounded refusal reasons → operator copy.
     *
     * Never renders a raw reason token, and never a database or exception
     * message: an unrecognised reason gets a generic sentence rather than
     * being echoed.
     */
    private static function intentRefusalMessage(string $reason): string
    {
        switch ($reason) {
            case CommunityRequestService::REFUSED_NOT_FOUND:
                return 'That collection no longer exists.';
            case CommunityRequestService::REFUSED_NOT_VERIFIED:
                return 'Verify the collection first. Verification is required before a community can be requested — and on its own it no longer creates one.';
            case CommunityRequestService::REFUSED_IDENTITY:
                return 'This collection has no resolved on-chain identity, so a holder gate built on it could never be satisfied. No request was recorded.';
            case CommunityRequestService::REFUSED_COMMUNITY_EXISTS:
                return 'A community already exists for this collection.';
            case CommunityRequestService::REFUSED_BAD_OPERATOR:
                return 'Your administrator account could not be resolved for this action.';
            case CommunityRequestService::REFUSED_ILLEGAL_TRANSITION:
            case CommunityRequestService::REFUSED_WRITE_FAILED:
            default:
                return 'The request could not be recorded and nothing was changed. See the bcc-trust error log.';
        }
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handleProvision(): array
    {
        // Deliberately does NOT call handleSave() any more — see
        // handleProvisionPost(). A button must not quietly perform another
        // button's write.
        //
        // PR 6: this is "process requested communities", not "provision every
        // verified collection". It drains the queue of collections an
        // administrator explicitly asked for; a verified collection with no
        // recorded request is not in that queue and cannot be reached here.
        $result = OnchainPlugin::instance()->gatedGroupProvisioningService()->processRequested();

        AdminActionSupport::audit(
            'admin_vc_communities_provisioned',
            'collection',
            null,
            [
                'created' => (int) ($result['created'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'failed'  => (int) ($result['failed'] ?? 0),
                'errors'  => count($result['errors'] ?? []),
            ]
        );

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections provision (manual)', [
            'action'   => 'gated_group_provision_manual',
            'created'  => (int) ($result['created'] ?? 0),
            'skipped'  => (int) ($result['skipped'] ?? 0),
            'failed'   => (int) ($result['failed'] ?? 0),
            'errors'   => count($result['errors'] ?? []),
            'operator' => get_current_user_id(),
        ]);

        $message = sprintf(
            'Requested communities processed: %d created, %d already existed, %d failed.',
            (int) ($result['created'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            (int) ($result['failed'] ?? 0)
        );
        $errors = $result['errors'] ?? [];

        $notices = [];
        $notices[] = [
            'type'    => empty($errors) ? 'success' : 'warning',
            'message' => $message,
        ];

        foreach ($errors as $err) {
            $notices[] = ['type' => 'error', 'message' => (string) $err];
        }

        return $notices;
    }
}
