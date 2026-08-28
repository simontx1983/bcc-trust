<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Admin\AdminActionSupport;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;
use BCC\Trust\Onchain\ValueObjects\RepositoryWriteResult;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONLY SANCTIONED WRITE PATH FOR NFT CAPABILITY.
 *
 * ── WHAT IT OWNS ────────────────────────────────────────────────────────
 * Domain validation and orchestration for the three things an administrator
 * may change, and nothing else:
 *
 *   `wp_bcc_chains.bcc_supports_nft_collections`         product decision
 *   `wp_bcc_chains.manual_collection_discovery_enabled`  permission to START
 *   `wp_bcc_chain_nft_capabilities`                      driver narrowing
 *
 * Above it, {@see \BCC\Trust\Onchain\Admin\NftDiscoveryPage} owns
 * authorization, request shape, nonces, rendering and the PRG. Below it, the
 * two repositories own the SQL and the postcondition reads. This class owns
 * the rules, and it is the only thing that does — a rule enforced in a
 * handler is a rule the next handler can forget.
 *
 * ── WHAT NONE OF IT DOES ────────────────────────────────────────────────
 * No method here starts a discovery, contacts a provider, verifies a
 * collection, provisions a group, refreshes a wallet, writes a collection,
 * schedules anything, or registers a hook. Every one of them ends at a
 * bounded string. Granting a capability makes an operation POSSIBLE for
 * somebody who later presses a different button; it never performs one.
 *
 * ── CONFIGURATION STILL CANNOT INVENT A CAPABILITY ──────────────────────
 * Three separate things stop it, and they are stacked deliberately:
 *
 *   1. this class validates every triple against {@see NftDriverRegistry}
 *      AND the authoritative chain row before writing;
 *   2. the registry INTERSECTION at {@see NftDriverRegistry::driversFor()}
 *      discards anything unmatched at read time, whatever is stored;
 *   3. the manual permission is refused outright on a chain where no
 *      administrator-started operation exists at all.
 *
 * (2) is the guarantee, because it is the only one a row from a manual
 * INSERT or a restored backup is certain to meet. (1) and (3) exist so an
 * operator is told NO at the moment they ask, instead of being handed a
 * stored row that quietly means nothing forever.
 *
 * ── AND ONE INVARIANT IS ENFORCED IN SQL, NOT HERE ──────────────────────
 * "The manual permission may not be set while product support is off" is a
 * CROSS-COLUMN invariant, and a service that reads one column and then
 * writes another cannot hold it under concurrency — the withdrawal it is
 * racing lands in the gap. So that one rule is carried by the statement:
 * {@see ChainRepository::grantManualCollectionDiscovery()} is conditional,
 * and {@see ChainRepository::disableNftProductSupport()} clears both columns
 * in one UPDATE. Between them, `product = 0, manual = 1` is unreachable from
 * either interleaving.
 *
 * The rules that are NOT expressible in a row — which drivers the registry
 * offers, whether an administrator-started operation exists at all — stay
 * here, because they are properties of the code rather than of the data.
 *
 * ── EVERY WRITE ANSWERS FOUR QUESTIONS, IN THIS ORDER ───────────────────
 *   Did the statement RUN?          `isFailure()` — a refusal is never a change
 *   Did it MOVE anything?           `mutated()` — the generation bumps on this
 *   Is the desired state THERE?     a fresh authoritative read, every time
 *   Was this request the one that   `isNoOp()` + a verified postcondition
 *   did it?                         — a concurrent writer gets no audit row here
 *
 * They are separate on purpose. A boolean write result answers the first two
 * at once and gets both wrong; a pre-write read answers the fourth by
 * guessing. See {@see RepositoryWriteResult}.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
final class NftCapabilityEditor
{
    // ── Result codes ────────────────────────────────────────────────────
    //
    // The COMPLETE set of things any method here can return, and therefore
    // the complete set of values that can appear in the PRG URL. Every one
    // is a lowercase `sanitize_key()`-safe literal chosen by us: no
    // submitted value, no chain identity, no driver key, no SQL text and no
    // provider text ever becomes a result code.

    public const RESULT_UNKNOWN_CHAIN  = 'unknown_chain';
    public const RESULT_COLUMN_ABSENT  = 'column_absent';

    public const RESULT_PRODUCT_ENABLED         = 'product_enabled';
    public const RESULT_PRODUCT_DISABLED        = 'product_disabled';
    public const RESULT_PRODUCT_DISABLED_CASCADE = 'product_disabled_manual_cleared';
    public const RESULT_PRODUCT_NOOP_ENABLED    = 'product_noop_enabled';
    public const RESULT_PRODUCT_NOOP_DISABLED   = 'product_noop_disabled';
    public const RESULT_PRODUCT_WRITE_FAILED    = 'product_write_failed';
    public const RESULT_PRODUCT_UNVERIFIED      = 'product_write_unverified';

    public const RESULT_MANUAL_ENABLED       = 'manual_enabled';
    public const RESULT_MANUAL_DISABLED      = 'manual_disabled';
    public const RESULT_MANUAL_NOOP_ENABLED  = 'manual_noop_enabled';
    public const RESULT_MANUAL_NOOP_DISABLED = 'manual_noop_disabled';
    public const RESULT_MANUAL_NO_PRODUCT    = 'manual_refused_no_product';
    public const RESULT_MANUAL_NO_STARTABLE  = 'manual_refused_no_startable_op';
    public const RESULT_MANUAL_WRITE_FAILED  = 'manual_write_failed';
    public const RESULT_MANUAL_UNVERIFIED    = 'manual_write_unverified';

    public const RESULT_OVERRIDE_DISABLED         = 'override_disabled';
    public const RESULT_OVERRIDE_ENABLED          = 'override_enabled';
    public const RESULT_OVERRIDE_INHERITED        = 'override_inherited';
    public const RESULT_OVERRIDE_NOOP             = 'override_noop';
    public const RESULT_OVERRIDE_UNREADABLE       = 'override_state_unreadable';
    public const RESULT_OVERRIDE_INVALID_TRIPLE   = 'override_invalid_triple';
    public const RESULT_OVERRIDE_INVALID_PRIORITY = 'override_invalid_priority';
    public const RESULT_OVERRIDE_WRITE_FAILED     = 'override_write_failed';
    public const RESULT_OVERRIDE_UNVERIFIED       = 'override_write_unverified';

    public const RESULT_STALE_REMOVED     = 'stale_override_removed';
    public const RESULT_STALE_NOT_FOUND   = 'stale_override_not_found';
    public const RESULT_STALE_STILL_VALID = 'stale_override_still_valid';

    /**
     * Priority bounds for an explicit `enabled` override. Lower runs first.
     *
     * Bounded because the column is a signed `INT` and an operator has no
     * reason to reach for either extreme: the registry's own priorities are
     * 10 and 20, so 0–1000 leaves three orders of magnitude of headroom
     * while keeping every stored value obviously deliberate. A value outside
     * the range is REFUSED, never clamped — silently turning 5000 into 1000
     * would store an ordering nobody chose and report it as what was asked
     * for.
     */
    public const PRIORITY_MIN = 0;
    public const PRIORITY_MAX = 1000;

    // ═══════════════════════════════════════════════════════════════════
    //  PRODUCT SUPPORT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Grant BCC product support for NFT collections on one chain.
     *
     * Starts nothing, and — deliberately — permits nothing either. The
     * manual permission is a separate grant and is NOT set here, so a chain
     * brought into product scope arrives with no operator able to start a
     * discovery on it until somebody says so in a second, separate action.
     */
    public static function enableProductSupport(int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::RESULT_UNKNOWN_CHAIN;
        }

        // null = the projection carries no such column (pre-migration).
        // Refused rather than treated as false: an install that cannot STORE
        // the answer must not be told its answer was recorded.
        $before = NftChainCapability::bccNftSupportState($chain);
        if ($before === null) {
            return self::RESULT_COLUMN_ABSENT;
        }
        if ($before === true) {
            return self::RESULT_PRODUCT_NOOP_ENABLED;
        }

        $write = ChainRepository::enableNftProductSupport($chainId);

        return self::settleChainFlagWrite(
            $write,
            $chainId,
            (string) $chain->slug,
            static fn(object $row): bool => NftChainCapability::bccNftSupportState($row) === true,
            'admin_nft_product_support_enabled',
            'admin_nft_product_support_enable_failed',
            'admin_nft_product_support_enable_unconfirmed',
            self::RESULT_PRODUCT_ENABLED,
            self::RESULT_PRODUCT_NOOP_ENABLED,
            self::RESULT_PRODUCT_WRITE_FAILED,
            self::RESULT_PRODUCT_UNVERIFIED
        );
    }

    /**
     * Withdraw product support, taking the manual permission with it.
     *
     * ── THE CASCADE IS THE POINT, NOT A TIDY-UP ─────────────────────────
     * A chain with `bcc_supports_nft_collections = 0` reports
     * `no_bcc_support` for every operation, and the capability model stops
     * there — so a `manual_collection_discovery_enabled = 1` left behind is
     * invisible on every surface in the product. It stays invisible right up
     * until product support is granted again, at which point the chain
     * returns already permitted to start a discovery, on the strength of a
     * decision nobody remembers taking.
     *
     * So both columns move in ONE statement
     * ({@see ChainRepository::disableNftProductSupport()}), and the notice
     * says so when the permission was actually cleared — an operator who
     * withdrew product support and silently lost a permission they set last
     * month deserves to be told.
     *
     * ── AND IT IS NOT A NO-OP JUST BECAUSE PRODUCT IS ALREADY OFF ───────
     * If product support is already 0 but a stale permission is still 1,
     * this runs and clears it. Only BOTH already being 0 is nothing to do.
     */
    public static function disableProductSupport(int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::RESULT_UNKNOWN_CHAIN;
        }

        $beforeProduct = NftChainCapability::bccNftSupportState($chain);
        $beforeManual  = NftChainCapability::manualDiscoveryState($chain);
        if ($beforeProduct === null || $beforeManual === null) {
            return self::RESULT_COLUMN_ABSENT;
        }
        if ($beforeProduct === false && $beforeManual === false) {
            return self::RESULT_PRODUCT_NOOP_DISABLED;
        }

        $write = ChainRepository::disableNftProductSupport($chainId);

        $settled = self::settleChainFlagWrite(
            $write,
            $chainId,
            (string) $chain->slug,
            static fn(object $row): bool =>
                NftChainCapability::bccNftSupportState($row) === false
                && NftChainCapability::manualDiscoveryState($row) === false,
            $beforeManual
                ? 'admin_nft_product_disabled_manual_cleared'
                : 'admin_nft_product_support_disabled',
            'admin_nft_product_support_disable_failed',
            'admin_nft_product_support_disable_unconfirmed',
            $beforeManual ? self::RESULT_PRODUCT_DISABLED_CASCADE : self::RESULT_PRODUCT_DISABLED,
            self::RESULT_PRODUCT_NOOP_DISABLED,
            self::RESULT_PRODUCT_WRITE_FAILED,
            self::RESULT_PRODUCT_UNVERIFIED
        );

        return $settled;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MANUAL DISCOVERY PERMISSION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Permit an administrator to START a chain-wide NFT collection discovery.
     *
     * ── TWO REFUSALS, AND THE ORDER BETWEEN THEM IS DELIBERATE ──────────
     * STRUCTURAL FIRST. If no driver in this build can perform any
     * administrator-started operation on this chain — true of every EVM
     * chain and of Solana, permanently, because no provider sells chain-wide
     * NFT enumeration on those families — the permission could never
     * authorise anything, and it is refused outright. The editor renders no
     * control for such a chain, but the ABSENCE OF A BUTTON IS NOT A
     * BOUNDARY: a crafted POST with a valid nonce reaches this method, and
     * this is where it stops. Storing the intent "for later" would leave a
     * row asserting that somebody granted a capability, which is precisely
     * the misreading the whole model exists to prevent.
     *
     * PERMISSION SECOND. Only then is product support required, read from
     * the AUTHORITATIVE ROW at execution time — never from whatever the
     * browser was rendering, which may be minutes old and may describe a
     * chain whose support was withdrawn since.
     *
     * ── AND THE READ IS NOT WHAT ENFORCES IT ────────────────────────────
     * Reading product support here is a courtesy: it produces a notice that
     * names the missing permission instead of a generic refusal. It cannot
     * be the enforcement, because the window between this read and the write
     * is exactly where a concurrent product-withdrawal lands, and no amount
     * of re-reading closes a window you are standing in.
     *
     * The enforcement is a PREDICATE IN THE STATEMENT —
     * {@see ChainRepository::grantManualCollectionDiscovery()} carries
     * `AND bcc_supports_nft_collections = 1`, so a withdrawal that commits
     * in the window makes this UPDATE match no row. `product = 0, manual = 1`
     * is unreachable from either interleaving: the other ordering is covered
     * by the withdrawal's single-statement cascade.
     *
     * Which is why the outcome is settled by {@see settleManualGrant()}
     * rather than the shared settler: zero affected rows here means EITHER
     * "already granted" OR "refused by the predicate", and only a fresh read
     * of both columns can say which.
     *
     * Naming the structural refusal first is the same choice
     * {@see NftChainCapability::verdict()} documents: sending an operator to
     * turn on product support, so that they can then grant a permission that
     * still cannot mean anything, is a worse answer than the true one.
     *
     * ── AND IT STILL STARTS NOTHING ─────────────────────────────────────
     * A measured Cosmos chain with no wasm module can hold this permission
     * and remains unscannable; the backfill control stays unavailable
     * because {@see NftChainCapability} answers `CHAIN_UNSUPPORTED` before
     * it ever reaches a permission. Intent never overrides a measurement.
     */
    public static function enableManualDiscovery(int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::RESULT_UNKNOWN_CHAIN;
        }

        if (!NftChainCapability::hasOperatorStartableOperation($chain)) {
            return self::RESULT_MANUAL_NO_STARTABLE;
        }

        $product = NftChainCapability::bccNftSupportState($chain);
        $before  = NftChainCapability::manualDiscoveryState($chain);
        if ($product === null || $before === null) {
            return self::RESULT_COLUMN_ABSENT;
        }
        if ($product !== true) {
            return self::RESULT_MANUAL_NO_PRODUCT;
        }
        if ($before === true) {
            return self::RESULT_MANUAL_NOOP_ENABLED;
        }

        // CONDITIONAL on product support at the moment MySQL executes it.
        // The check above is a courtesy that produces a useful notice; THIS
        // is what makes `product = 0, manual = 1` unreachable when a
        // withdrawal commits in the window between them.
        $write = ChainRepository::grantManualCollectionDiscovery($chainId);

        return self::settleManualGrant($write, $chainId, (string) $chain->slug);
    }

    /**
     * Settle a CONDITIONAL grant, where zero affected rows is ambiguous.
     *
     * ── WHY THIS CANNOT USE THE SHARED SETTLER ──────────────────────────
     * Everywhere else, zero affected rows means "the row already held the
     * value". Here it means that OR "the `AND bcc_supports_nft_collections
     * = 1` predicate matched nothing, because support was withdrawn while
     * we were deciding". Those are a no-op and a refusal, and the affected-
     * row count cannot separate them — only a fresh read of BOTH columns
     * can, which is why this ladder reads both and asks about product
     * support FIRST.
     *
     *   product unreadable / chain gone   UNVERIFIED — no claim either way
     *   product now off                   REFUSED    — the predicate bit
     *   manual now on, zero rows          NO-OP      — somebody else did it
     *   manual now on, rows moved         GRANTED
     *   manual still off                  UNVERIFIED
     *
     * A refusal writes NO state-change audit row, because no state changed:
     * the operator asked for something that was no longer permitted by the
     * time it ran, and the correct record of that is the notice, not a
     * durable row implying the chain moved.
     */
    private static function settleManualGrant(
        RepositoryWriteResult $write,
        int $chainId,
        string $slug
    ): string {
        if ($write->isFailure()) {
            \BCC\Core\Log\Logger::error('[bcc-trust] NFT capability flag write failed', [
                'action'   => 'nft_capability_flag_write_failed',
                'chain_id' => $chainId,
                'chain'    => $slug,
                'event'    => 'admin_nft_manual_discovery_enable_failed',
                'operator' => get_current_user_id(),
            ]);

            AdminActionSupport::audit(
                'admin_nft_manual_discovery_enable_failed',
                'chain',
                $chainId,
                ['chain' => $slug]
            );

            return self::RESULT_MANUAL_WRITE_FAILED;
        }

        // BOTH columns, from a re-resolved row. The repository cleared the
        // chain cache inside the write — including on a zero-row result —
        // so this reaches the database rather than the projection we
        // decided from.
        $after        = ChainRepository::getById($chainId);
        $afterProduct = $after === null ? null : NftChainCapability::bccNftSupportState($after);
        $afterManual  = $after === null ? null : NftChainCapability::manualDiscoveryState($after);

        if ($afterProduct === null || $afterManual === null) {
            return self::unconfirmedManualGrant($chainId, $slug);
        }

        if ($afterProduct === false) {
            // The predicate refused it. Nothing changed, and the operator is
            // told the same thing they would have been told a moment
            // earlier — which is now simply true rather than nearly true.
            \BCC\Core\Log\Logger::info('[bcc-trust] manual discovery grant refused by the product-support predicate', [
                'action'   => 'nft_manual_grant_refused_product_withdrawn',
                'chain_id' => $chainId,
                'chain'    => $slug,
                'operator' => get_current_user_id(),
            ]);

            return self::RESULT_MANUAL_NO_PRODUCT;
        }

        if ($afterManual !== true) {
            return self::unconfirmedManualGrant($chainId, $slug);
        }

        if ($write->isNoOp()) {
            // Product on, permission on, and this statement moved nothing:
            // a concurrent request granted it. No audit row — the change
            // belongs to whoever made it.
            return self::RESULT_MANUAL_NOOP_ENABLED;
        }

        AdminActionSupport::audit(
            'admin_nft_manual_discovery_enabled',
            'chain',
            $chainId,
            ['chain' => $slug]
        );

        return self::RESULT_MANUAL_ENABLED;
    }

    private static function unconfirmedManualGrant(int $chainId, string $slug): string
    {
        \BCC\Core\Log\Logger::error('[bcc-trust] NFT capability flag change could not be confirmed', [
            'action'   => 'nft_capability_flag_unconfirmed',
            'chain_id' => $chainId,
            'chain'    => $slug,
            'event'    => 'admin_nft_manual_discovery_enable_unconfirmed',
            'operator' => get_current_user_id(),
        ]);

        AdminActionSupport::audit(
            'admin_nft_manual_discovery_enable_unconfirmed',
            'chain',
            $chainId,
            ['chain' => $slug]
        );

        return self::RESULT_MANUAL_UNVERIFIED;
    }

    /**
     * Withdraw the permission. ALWAYS allowed.
     *
     * Neither the structural check nor the product-support check applies
     * here, and that asymmetry is the whole design. Both of those gates
     * exist to stop a permission being CREATED where it cannot mean
     * anything; applying them to removal would mean a database that already
     * holds a wrong value — a restored backup, an older build, a hand-run
     * `UPDATE` — could not be returned to the safe state through the only
     * sanctioned path. Taking a permission away is safe by definition.
     */
    public static function disableManualDiscovery(int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::RESULT_UNKNOWN_CHAIN;
        }

        $before = NftChainCapability::manualDiscoveryState($chain);
        if ($before === null) {
            return self::RESULT_COLUMN_ABSENT;
        }
        if ($before === false) {
            return self::RESULT_MANUAL_NOOP_DISABLED;
        }

        // UNCONDITIONAL — see withdrawManualCollectionDiscovery(). A gate on
        // product support here would leave a restored backup holding a wrong
        // value with no sanctioned way to correct it.
        $write = ChainRepository::withdrawManualCollectionDiscovery($chainId);

        return self::settleChainFlagWrite(
            $write,
            $chainId,
            (string) $chain->slug,
            static fn(object $row): bool => NftChainCapability::manualDiscoveryState($row) === false,
            'admin_nft_manual_discovery_disabled',
            'admin_nft_manual_discovery_disable_failed',
            'admin_nft_manual_discovery_disable_unconfirmed',
            self::RESULT_MANUAL_DISABLED,
            self::RESULT_MANUAL_NOOP_DISABLED,
            self::RESULT_MANUAL_WRITE_FAILED,
            self::RESULT_MANUAL_UNVERIFIED
        );
    }

    /**
     * The shared tail of every chain-flag write: fail, verify, then classify.
     *
     * ── WHY THE POSTCONDITION IS READ BEFORE THE NO-OP IS DECIDED ───────
     * A zero-row result means "this statement changed nothing", which is
     * TWO situations: the row already held the value (a re-submitted form),
     * or another request applied it in the moment between our read and our
     * write. Both are fine, and both are only fine once we have CONFIRMED
     * the value is now what was asked for. Reporting "already done" without
     * looking would report success on the strength of having done nothing.
     *
     * So: refusal → verify → concurrent no-op → change. A no-op writes no
     * audit event, because this request did not change anything and an audit
     * row claiming it did would credit the wrong request.
     *
     * The verification RE-RESOLVES the chain rather than reusing the row the
     * caller already had — that row is the BEFORE picture, and comparing a
     * write against the value it replaced proves nothing. The repository
     * cleared the chain cache inside the write (including on a zero-row
     * result), so this read reaches the database.
     *
     * @param callable(object): bool $satisfied does a freshly-read row show the intended state?
     */
    private static function settleChainFlagWrite(
        RepositoryWriteResult $write,
        int $chainId,
        string $slug,
        callable $satisfied,
        string $auditChanged,
        string $auditFailed,
        string $auditUnconfirmed,
        string $resultChanged,
        string $resultNoOp,
        string $resultFailed,
        string $resultUnverified
    ): string {
        if ($write->isFailure()) {
            \BCC\Core\Log\Logger::error('[bcc-trust] NFT capability flag write failed', [
                'action'   => 'nft_capability_flag_write_failed',
                'chain_id' => $chainId,
                'chain'    => $slug,
                'event'    => $auditFailed,
                'operator' => get_current_user_id(),
            ]);

            // An authorised operator asked for a state change and the
            // authoritative write refused it. Durable, but no correlation
            // ID — no exception was captured to correlate to.
            AdminActionSupport::audit($auditFailed, 'chain', $chainId, ['chain' => $slug]);

            return $resultFailed;
        }

        $after = ChainRepository::getById($chainId);
        if ($after === null || !$satisfied($after)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] NFT capability flag change could not be confirmed', [
                'action'   => 'nft_capability_flag_unconfirmed',
                'chain_id' => $chainId,
                'chain'    => $slug,
                'event'    => $auditUnconfirmed,
                'operator' => get_current_user_id(),
            ]);

            AdminActionSupport::audit($auditUnconfirmed, 'chain', $chainId, ['chain' => $slug]);

            return $resultUnverified;
        }

        if ($write->isNoOp()) {
            // Verified in the desired state, but THIS statement did not put
            // it there. No audit row: the change belongs to whoever made it.
            return $resultNoOp;
        }

        AdminActionSupport::audit($auditChanged, 'chain', $chainId, ['chain' => $slug]);

        return $resultChanged;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DRIVER OVERRIDES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Switch one registry-offered driver OFF for one operation on one chain.
     *
     * ── THE STORED PRIORITY IS PRESERVED, NOT RESET ─────────────────────
     * `priority` is meaningless while `enabled = 0`, so the value written
     * with a disable is a free choice — and resetting it to the registry
     * default would silently discard an ordering the operator set earlier,
     * discovered only when they switch the driver back on and find it in the
     * wrong place. An existing row keeps its priority; a first-ever row
     * takes the registry default.
     */
    public static function disableDriver(int $chainId, string $operation, string $driverKey): string
    {
        $context = self::resolveOverrideContext($chainId);
        if (is_string($context)) {
            return $context;
        }
        [$chain, $overrides] = $context;

        if (!self::isCurrentTriple($chain, $operation, $driverKey)) {
            return self::RESULT_OVERRIDE_INVALID_TRIPLE;
        }

        $current = self::findRow($overrides, $operation, $driverKey);
        if ($current !== null && $current['enabled'] === false) {
            return self::RESULT_OVERRIDE_NOOP;
        }

        $priority = $current !== null
            ? $current['priority']
            : (NftDriverRegistry::defaultPriority($driverKey) ?? self::PRIORITY_MIN);

        $write = ChainNftCapabilityRepository::upsertOverride(
            $chainId,
            $operation,
            $driverKey,
            false,
            $priority
        );

        return self::settleOverrideWrite(
            $write,
            $chainId,
            (string) $chain->slug,
            $operation,
            $driverKey,
            static fn(?array $row): bool => $row !== null && $row['enabled'] === false,
            'admin_nft_driver_override_disabled',
            self::RESULT_OVERRIDE_DISABLED
        );
    }

    /**
     * Switch one registry-offered driver ON, at an explicit priority.
     *
     * ── "ENABLE" RESTORES OR REORDERS; IT NEVER ADDS ────────────────────
     * {@see isCurrentTriple()} requires the code registry to ALREADY offer
     * this driver for this exact operation on this exact chain. So the only
     * two things this can do are undo a previous disable and change an
     * ordering. It cannot name a driver the build does not implement, point
     * a real driver at a chain it does not serve, or assign one to an
     * operation it does not perform — each of those is a separate check, and
     * all four must pass.
     */
    public static function enableDriver(
        int $chainId,
        string $operation,
        string $driverKey,
        int $priority
    ): string {
        $context = self::resolveOverrideContext($chainId);
        if (is_string($context)) {
            return $context;
        }
        [$chain, $overrides] = $context;

        if (!self::isCurrentTriple($chain, $operation, $driverKey)) {
            return self::RESULT_OVERRIDE_INVALID_TRIPLE;
        }
        // REFUSED, never clamped — see PRIORITY_MIN.
        if ($priority < self::PRIORITY_MIN || $priority > self::PRIORITY_MAX) {
            return self::RESULT_OVERRIDE_INVALID_PRIORITY;
        }

        $current = self::findRow($overrides, $operation, $driverKey);
        if ($current !== null && $current['enabled'] === true && $current['priority'] === $priority) {
            return self::RESULT_OVERRIDE_NOOP;
        }

        $write = ChainNftCapabilityRepository::upsertOverride(
            $chainId,
            $operation,
            $driverKey,
            true,
            $priority
        );

        return self::settleOverrideWrite(
            $write,
            $chainId,
            (string) $chain->slug,
            $operation,
            $driverKey,
            static fn(?array $row): bool =>
                $row !== null && $row['enabled'] === true && $row['priority'] === $priority,
            'admin_nft_driver_override_enabled',
            self::RESULT_OVERRIDE_ENABLED
        );
    }

    /**
     * Return one driver to the code registry's own answer — remove the row.
     *
     * The third state, and the reason it is a DELETE rather than a write of
     * `enabled = 1` at the default priority: an absent row means "whatever
     * the registry says, now and in future", and a materialised row means
     * "exactly this, forever". Writing the second while meaning the first
     * pins today's priority against tomorrow's registry.
     */
    public static function inheritDriver(int $chainId, string $operation, string $driverKey): string
    {
        $context = self::resolveOverrideContext($chainId);
        if (is_string($context)) {
            return $context;
        }
        [$chain, $overrides] = $context;

        if (!self::isCurrentTriple($chain, $operation, $driverKey)) {
            return self::RESULT_OVERRIDE_INVALID_TRIPLE;
        }

        if (self::findRow($overrides, $operation, $driverKey) === null) {
            return self::RESULT_OVERRIDE_NOOP;      // Already inheriting.
        }

        $write = ChainNftCapabilityRepository::deleteOverride($chainId, $operation, $driverKey);

        return self::settleOverrideWrite(
            $write,
            $chainId,
            (string) $chain->slug,
            $operation,
            $driverKey,
            static fn(?array $row): bool => $row === null,
            'admin_nft_driver_override_inherited',
            self::RESULT_OVERRIDE_INHERITED
        );
    }

    /**
     * Delete one INERT row this build no longer recognises.
     *
     * ── WHY THIS ROUTE CANNOT REQUIRE A VALID TRIPLE ────────────────────
     * By definition a stale row names an operation or a driver the current
     * registry does not have — that is what makes it stale. So the triple
     * validation every other override route applies would reject exactly the
     * rows this route exists to remove.
     *
     * That makes it the one route whose strings are not registry-checked,
     * and therefore the one that could become a general "delete any row you
     * name" endpoint. Three things stop it:
     *
     *   1. the handler validates the raw SHAPE — a lowercase key of at most
     *      32 characters, matching the storage columns — without sanitising
     *      anything into validity;
     *   2. the nonce is scoped to the exact chain, operation and driver the
     *      SERVER rendered, so the only removable triples are ones this
     *      install actually displayed as stale;
     *   3. below, the row must EXIST in the chain's override set and must
     *      re-evaluate as inert against the current registry.
     *
     * (3) is the load-bearing one, and it is why a live triple is REFUSED
     * here rather than quietly deleted: `inheritDriver()` owns that case,
     * and it is a different action with a different audit event. A route
     * that removed both would let "clean up an inert leftover" and "revert a
     * driver to registry defaults" become the same button.
     *
     * ── AND IT IS NOT AN ENABLE ─────────────────────────────────────────
     * Removing a stale row grants nothing: the row was already discarded at
     * every read. The audit event says a leftover was removed, and the
     * operator notice says the same, because "capability enabled" is a
     * sentence this action must never produce.
     */
    public static function removeStaleOverride(int $chainId, string $operation, string $driverKey): string
    {
        $context = self::resolveOverrideContext($chainId);
        if (is_string($context)) {
            return $context;
        }
        [$chain, $overrides] = $context;

        if (self::findRow($overrides, $operation, $driverKey) === null) {
            return self::RESULT_STALE_NOT_FOUND;
        }
        if (self::isCurrentTriple($chain, $operation, $driverKey)) {
            return self::RESULT_STALE_STILL_VALID;
        }

        $write = ChainNftCapabilityRepository::deleteOverride($chainId, $operation, $driverKey);

        return self::settleOverrideWrite(
            $write,
            $chainId,
            (string) $chain->slug,
            $operation,
            $driverKey,
            static fn(?array $row): bool => $row === null,
            'admin_nft_stale_override_removed',
            self::RESULT_STALE_REMOVED
        );
    }

    /**
     * Resolve the chain and its COMPLETE override set, or a refusal code.
     *
     * ── THE WHOLE SET, FOR EVERY WRITE, WITHOUT EXCEPTION ───────────────
     * A narrower "fetch the one row I am about to change" read was
     * considered and rejected. It cannot answer the question that has to be
     * answered before any write: is this chain's override state something we
     * can read at all? A single-row lookup returns a perfectly clean row out
     * of a set that is TRUNCATED at its ceiling or contains a MALFORMED
     * sibling — and a write permitted on that basis is a write made against
     * a store {@see NftChainCapability} would refuse to draw any conclusion
     * from. The surface would then show `unknown` for the very chain the
     * editor had just accepted a change to.
     *
     * So an unavailable read refuses every override mutation on the chain,
     * whatever the reason: read failed, table absent, malformed row,
     * overflow. Fail closed, and say which.
     *
     * @return array{0: ChainRow, 1: ChainNftCapabilityOverrides}|string
     */
    private static function resolveOverrideContext(int $chainId): array|string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::RESULT_UNKNOWN_CHAIN;
        }

        $overrides = ChainNftCapabilityRepository::getForChain($chainId);
        if (!$overrides->isAvailable()) {
            return self::RESULT_OVERRIDE_UNREADABLE;
        }

        return [$chain, $overrides];
    }

    /**
     * PURE. Does the CODE registry offer this exact triple for this chain?
     *
     * Four independent questions, all of which must answer yes, and none of
     * which implies another:
     *
     *   is this one of the six operations?
     *   is this a driver this build implements?
     *   does that driver PERFORM that operation?
     *   does it SERVE this chain?
     *
     * `das` — retired when the single Solana DAS driver was split into
     * `das_rpc` (the chain row's endpoint) and `das_helius` (the Helius
     * constants) — fails the second and can never be written again.
     */
    private static function isCurrentTriple(object $chain, string $operation, string $driverKey): bool
    {
        return NftDriverRegistry::isOperation($operation)
            && NftDriverRegistry::isDriver($driverKey)
            && NftDriverRegistry::driverPerformsOperation($driverKey, $operation)
            && NftDriverRegistry::driverSupportsChain($driverKey, $chain);
    }

    /**
     * PURE. The stored row for one exact triple, from an already-read set.
     *
     * @return array{operation: string, driver_key: string, enabled: bool, priority: int}|null
     */
    private static function findRow(
        ChainNftCapabilityOverrides $overrides,
        string $operation,
        string $driverKey
    ): ?array {
        foreach ($overrides->rows() as $row) {
            if ($row['operation'] === $operation && $row['driver_key'] === $driverKey) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The shared tail of every override write.
     *
     * ── THE GENERATION BUMPS BEFORE THE POSTCONDITION IS READ ───────────
     * Deliberately, and this is the one ordering in the class that looks
     * wrong at a glance. If the statement moved a row, the stored
     * configuration has CHANGED — that is true whether or not we can then
     * read it back. A caches-invalidated-only-on-confirmed-success rule
     * would, in exactly the case where the database is misbehaving, leave
     * every reader serving the previous generation of an override set that
     * no longer exists. Over-bumping costs one cache miss; under-bumping
     * serves a stale capability answer until something else happens to bump.
     *
     * The AUDIT is the opposite: it waits for the postcondition, because an
     * audit row is a claim about the state of the system, and a claim we
     * could not verify is not one to make durable.
     *
     * ── AND A CONCURRENT WRITER GETS NO CREDIT HERE ─────────────────────
     * Zero affected rows plus a verified postcondition is a genuine no-op:
     * the desired state is there, and this statement is not what put it
     * there. No bump (nothing changed), no audit (nothing to attribute).
     *
     * @param callable(array{operation: string, driver_key: string, enabled: bool, priority: int}|null): bool $satisfied
     */
    private static function settleOverrideWrite(
        RepositoryWriteResult $write,
        int $chainId,
        string $slug,
        string $operation,
        string $driverKey,
        callable $satisfied,
        string $auditChanged,
        string $resultChanged
    ): string {
        if ($write->isFailure()) {
            \BCC\Core\Log\Logger::error('[bcc-trust] NFT driver override write failed', [
                'action'    => 'nft_driver_override_write_failed',
                'chain_id'  => $chainId,
                'chain'     => $slug,
                'operation' => $operation,
                'driver'    => $driverKey,
                'operator'  => get_current_user_id(),
            ]);

            AdminActionSupport::audit(
                'admin_nft_driver_override_failed',
                'chain',
                $chainId,
                ['chain' => $slug, 'operation' => $operation, 'driver' => $driverKey]
            );

            return self::RESULT_OVERRIDE_WRITE_FAILED;
        }

        // BEFORE the read-back. The store moved; nothing keyed on the old
        // generation may be served, even if the next line cannot confirm
        // what it moved to.
        if ($write->mutated()) {
            ChainNftCapabilityRepository::bumpChainGeneration($chainId);
        }

        $after = ChainNftCapabilityRepository::getForChain($chainId);
        if (!$after->isAvailable() || !$satisfied(self::findRow($after, $operation, $driverKey))) {
            \BCC\Core\Log\Logger::error('[bcc-trust] NFT driver override could not be confirmed', [
                'action'    => 'nft_driver_override_unconfirmed',
                'chain_id'  => $chainId,
                'chain'     => $slug,
                'operation' => $operation,
                'driver'    => $driverKey,
                'operator'  => get_current_user_id(),
            ]);

            AdminActionSupport::audit(
                'admin_nft_driver_override_unconfirmed',
                'chain',
                $chainId,
                ['chain' => $slug, 'operation' => $operation, 'driver' => $driverKey]
            );

            return self::RESULT_OVERRIDE_UNVERIFIED;
        }

        if ($write->isNoOp()) {
            return self::RESULT_OVERRIDE_NOOP;
        }

        AdminActionSupport::audit(
            $auditChanged,
            'chain',
            $chainId,
            ['chain' => $slug, 'operation' => $operation, 'driver' => $driverKey]
        );

        return $resultChanged;
    }
}
