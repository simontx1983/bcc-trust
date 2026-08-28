<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Services\NftCapabilityEditor;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE SELECTED CHAIN'S CAPABILITY EDITOR — A PURE PRINTER.
 *
 * ── WHY THE EDITOR IS PER-CHAIN AND NOT PER-CELL ────────────────────────
 * The capability matrix is six operations wide and as tall as the chains
 * table. Putting a writable form in every cell would put dozens of nonces
 * and hundreds of controls on one page, and — the part that actually
 * matters — it would put a mutation one mis-click away from every cell an
 * operator is reading. So the matrix stays a read surface, one chain is
 * selected at a time, and everything that can be changed is in one place
 * where it can be seen together.
 *
 * ── IT DERIVES NOTHING ──────────────────────────────────────────────────
 * Every status, driver, priority, override state and stale row printed
 * below arrived in the matrix array from {@see NftChainCapability}. This
 * class consults no repository, no registry state and no readiness check of
 * its own. That is the same discipline
 * {@see \BCC\Trust\Onchain\Admin\NftDiscoveryPage::render_capability_matrix()}
 * follows and for the same recorded reason: a renderer that resolves its
 * own facts becomes a second definition of "can this chain do X", written
 * to agree and free to drift.
 *
 * The one thing it reads from the registry is the operation LABEL list, and
 * only to order and title the sections — not to decide what is offered.
 *
 * ── THE CONTROLS IT REFUSES TO OFFER ────────────────────────────────────
 * No bulk enable. No family-wide action. No "enable all drivers". No
 * automatic-run control. No provider credential or RPC editor, and no "test
 * provider" button — readiness is observed by {@see NftProviderReadiness} at
 * read time and is not a thing an operator sets. And no path from any form
 * here to the backfill route: saving configuration must never be able to
 * start work, so the backfill stays in its own section, under its own gates.
 */
final class NftCapabilityEditorPanel
{
    /**
     * @param array<string, mixed>      $snapshot
     * @param array<string, mixed>|null $chain the selected chain's matrix row
     */
    public static function render(array $snapshot, ?array $chain): void
    {
        if ($chain === null) {
            self::renderPrompt();

            return;
        }

        $family  = (string) ($snapshot['family'] ?? '');
        $chainId = (int) ($chain['chain_id'] ?? 0);
        $name    = (string) ($chain['name'] ?? '');
        $slug    = (string) ($chain['slug'] ?? '');

        if ($chainId <= 0) {
            self::renderPrompt();

            return;
        }
        ?>
        <h2 style="margin-top:28px;">
            Capability editor — <?php echo esc_html($name); ?>
            <code style="font-size:12px;"><?php echo esc_html($slug); ?></code>
        </h2>

        <?php if (($chain['overrides_available'] ?? false) !== true): ?>
            <div class="notice notice-error inline" style="margin:0 0 12px;">
                <p style="max-width:900px;">
                    <strong>This chain's driver-override rows could not be established, so nothing
                    here can be edited.</strong>
                    The read failed, a row is malformed, or there are more rows than can be read at
                    once. Changing an override against a set we only partly read could honour some
                    restrictions and silently drop others, so every override control is withheld
                    until the store reads cleanly. The two permissions below are stored on the chain
                    row and are unaffected.
                </p>
            </div>
        <?php endif; ?>

        <?php self::renderProductSupport($family, $chainId, $chain); ?>
        <?php self::renderManualPermission($family, $chainId, $chain); ?>
        <?php self::renderDriverOverrides($family, $chainId, $chain); ?>
        <?php self::renderStaleOverrides($family, $chainId, $chain); ?>
        <?php
    }

    private static function renderPrompt(): void
    {
        ?>
        <h2 style="margin-top:28px;">Capability editor</h2>
        <p style="max-width:900px;color:#646970;">
            Choose <strong>Edit capability</strong> on a chain above to change what BCC permits for
            it. Nothing is selected, so nothing can be changed from here.
        </p>
        <?php
    }

    // ── Product support ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $chain
     */
    private static function renderProductSupport(string $family, int $chainId, array $chain): void
    {
        $state = $chain['bcc_supports'] ?? null;
        ?>
        <h3 style="margin-bottom:4px;">BCC product support</h3>
        <p style="max-width:900px;color:#646970;margin-top:0;">
            Whether BCC treats this chain as one it does NFT collections for. It is a product
            decision, never a claim about the blockchain — a chain can be perfectly CW-721 capable
            and sit at off because BCC has not taken it on. It does not claim any provider is
            configured, and <strong>it starts nothing</strong>.
        </p>

        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr>
                    <td style="width:200px;"><strong>Current value</strong></td>
                    <td><?php self::printTriState($state, 'Supported', 'Not supported'); ?></td>
                </tr>
                <tr>
                    <td><strong>Change it</strong></td>
                    <td>
                        <?php if ($state === null): ?>
                            <em>This install cannot store the value — the column is absent from the
                            chain projection, so the migration has not run here. Nothing can be
                            edited until it has.</em>
                        <?php else: ?>
                            <?php self::renderFlagButton(
                                NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE,
                                $family,
                                $chainId,
                                'Enable NFT product support',
                                $state === true,
                                'Enable BCC NFT product support for this chain?'
                                    . "\n\n"
                                    . 'This starts nothing and permits nothing. It does NOT grant the manual '
                                    . 'discovery permission — that is a separate action.'
                            ); ?>
                            <?php self::renderFlagButton(
                                NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE,
                                $family,
                                $chainId,
                                'Disable NFT product support',
                                $state === false && ($chain['manual_enabled'] ?? null) === false,
                                'Disable BCC NFT product support for this chain?'
                                    . "\n\n"
                                    . 'This ALSO clears the manual discovery permission, in the same write. '
                                    . 'Existing collections are kept and nothing is unverified.'
                            ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <p style="max-width:900px;color:#646970;">
            Disabling also sets the manual discovery permission to off, in the same database write.
            A permission left behind on an unsupported chain is invisible everywhere in the product
            — and would come back already granted the day support is restored.
        </p>
        <?php
    }

    // ── Manual permission ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $chain
     */
    private static function renderManualPermission(string $family, int $chainId, array $chain): void
    {
        $state     = $chain['manual_enabled'] ?? null;
        $product   = $chain['bcc_supports'] ?? null;
        $startable = ($chain['operator_startable'] ?? false) === true;
        $measured  = ($chain['measured_unsupported'] ?? false) === true;
        ?>
        <h3 style="margin-bottom:4px;">Manual discovery permission</h3>
        <p style="max-width:900px;color:#646970;margin-top:0;">
            Whether an administrator is permitted to <em>start</em> a chain-wide collection
            discovery on this chain. <strong>It does not schedule or start anything by itself</strong>
            — no cron reads it, because every recurring discovery hook was retired and cannot
            re-arm. Today it applies only to administrator-started enumeration.
        </p>

        <?php if (!$startable): ?>
            <div class="notice notice-info inline" style="margin:0 0 12px;max-width:900px;">
                <p>
                    <strong>Not applicable to this chain.</strong>
                    No driver in this build can enumerate it, and
                    <strong>no setting can add chain-wide NFT enumeration to this family</strong>.
                    No provider sells it: Alchemy's <code>getContractsForOwner</code> enumerates a
                    <em>wallet's</em> contracts, which is a different question from "every collection
                    on this chain". This is a structural limit, not a missing credential, so the
                    permission is not offered here — and it is refused server-side even if a request
                    for it is constructed by hand.
                </p>
                <?php if ($state === true): ?>
                    <p>
                        <strong>This chain nevertheless has the permission stored as ON</strong> — from an
                        older build, a restored backup or a hand-run update. It grants nothing, but it
                        should not be there. Withdrawing it is always permitted:
                    </p>
                    <p>
                        <?php self::renderFlagButton(
                            NftDiscoveryPage::ACTION_CAP_MANUAL_DISABLE,
                            $family,
                            $chainId,
                            'Withdraw the stored permission',
                            false,
                            'Withdraw the manual discovery permission from this chain?'
                        ); ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr>
                    <td style="width:200px;"><strong>Current value</strong></td>
                    <td><?php self::printTriState($state, 'Permitted', 'Not permitted'); ?></td>
                </tr>
                <?php if ($measured): ?>
                    <tr>
                        <td><strong>Measured capability</strong></td>
                        <td style="color:#d63638;">
                            <strong>This chain answered that it has no CosmWasm module.</strong>
                            Granting the permission will not change that: enumeration stays refused
                            on the measurement, the backfill control is not offered, and no operator
                            setting can make a wasm module appear.
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td><strong>Change it</strong></td>
                    <td>
                        <?php if ($state === null): ?>
                            <em>This install cannot store the value — the column is absent from the
                            chain projection.</em>
                        <?php else: ?>
                            <?php if ($product !== true): ?>
                                <p style="margin-top:0;color:#dba617;">
                                    <strong>Granting is refused while product support is off.</strong>
                                    Enable product support above first — the server re-checks it at the
                                    moment the request runs, so what is on screen never decides this.
                                </p>
                            <?php else: ?>
                                <?php self::renderFlagButton(
                                    NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE,
                                    $family,
                                    $chainId,
                                    'Permit operator-started discovery',
                                    $state === true,
                                    'Permit an administrator to start a chain-wide discovery on this chain?'
                                        . "\n\n"
                                        . 'Nothing is started or scheduled by this. Every other gate still '
                                        . 'applies before a discovery can run.'
                                ); ?>
                            <?php endif; ?>
                            <?php self::renderFlagButton(
                                NftDiscoveryPage::ACTION_CAP_MANUAL_DISABLE,
                                $family,
                                $chainId,
                                'Withdraw the permission',
                                $state === false,
                                'Withdraw the manual discovery permission from this chain?'
                            ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    // ── Driver overrides ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $chain
     */
    private static function renderDriverOverrides(string $family, int $chainId, array $chain): void
    {
        /** @var array<string, array<string, mixed>> $operations */
        $operations = is_array($chain['operations'] ?? null) ? $chain['operations'] : [];
        $available  = ($chain['overrides_available'] ?? false) === true;
        ?>
        <h3 style="margin-bottom:4px;">Driver overrides</h3>
        <p style="max-width:900px;color:#646970;margin-top:0;">
            An override can only <strong>narrow or reorder capability the code already declares</strong>.
            It can never add a driver this build does not have, point a real driver at a chain it
            does not serve, or assign one to an operation it does not perform — and it does not make
            an unconfigured provider ready. Only the triples registered in code appear below, which
            is why there is nothing here to press for a capability that does not exist.
            <strong>Lower priority runs first</strong>; ties keep registry order.
        </p>

        <?php if (!$available): ?>
            <p style="max-width:900px;"><em>Withheld — see the notice above.</em></p>
            <?php return; ?>
        <?php endif; ?>

        <?php
        $anything = false;
        foreach (NftDriverRegistry::operations() as $operation) {
            $op = is_array($operations[$operation] ?? null) ? $operations[$operation] : [];
            /** @var list<array<string, mixed>> $editable */
            $editable = is_array($op['editable'] ?? null) ? $op['editable'] : [];
            if ($editable === []) {
                continue;
            }
            $anything = true;
            self::renderOperationDrivers($family, $chainId, $operation, $editable);
        }

        if (!$anything) {
            ?>
            <p style="max-width:900px;">
                <em>No driver in this build performs any NFT operation on this chain, so there is
                nothing to narrow or reorder.</em>
            </p>
            <?php
        }
    }

    /**
     * One operation's registry-offered drivers, each in one of three states.
     *
     * @param list<array<string, mixed>> $editable
     */
    private static function renderOperationDrivers(
        string $family,
        int $chainId,
        string $operation,
        array $editable
    ): void {
        ?>
        <h4 style="margin-bottom:4px;"><?php echo esc_html(self::operationLabel($operation)); ?></h4>
        <table class="widefat striped" style="max-width:900px;margin-bottom:16px;">
            <thead>
                <tr>
                    <th style="width:220px;">Driver</th>
                    <th style="width:150px;">State</th>
                    <th style="width:110px;">Provider</th>
                    <th>Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($editable as $driver): ?>
                    <?php
                    $key      = (string) ($driver['driver_key'] ?? '');
                    $state    = (string) ($driver['state'] ?? NftChainCapability::OVERRIDE_STATE_DEFAULT);
                    $priority = (int) ($driver['priority'] ?? 0);
                    $default  = (int) ($driver['default_priority'] ?? 0);
                    $ready    = ($driver['ready'] ?? false) === true;
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td><?php self::printOverrideState($state, $priority, $default); ?></td>
                        <td>
                            <span style="color:<?php echo $ready ? '#00a32a' : '#d63638'; ?>;">
                                <?php echo $ready ? '✓ configured' : '✗ not configured'; ?>
                            </span>
                        </td>
                        <td>
                            <?php self::renderDriverControls(
                                $family,
                                $chainId,
                                $operation,
                                $key,
                                $state,
                                $priority
                            ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The three state controls for one driver, each its own form and nonce.
     *
     * Three separate direction-named routes rather than one "save" that
     * reads a radio group: a form whose meaning depends on which input
     * happened to be selected when it was rendered takes its direction from
     * stale page state, which is the failure this file's routes are shaped
     * to avoid. Each button says what it does and carries a nonce bound to
     * exactly that action, chain, operation and driver.
     */
    private static function renderDriverControls(
        string $family,
        int $chainId,
        string $operation,
        string $driverKey,
        string $state,
        int $priority
    ): void {
        $enableAction  = NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE;
        $disableAction = NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE;
        $inheritAction = NftDiscoveryPage::ACTION_CAP_DRIVER_INHERIT;
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:flex-start;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="display:inline-flex;gap:4px;align-items:center;">
                <input type="hidden" name="action" value="<?php echo esc_attr($enableAction); ?>">
                <input type="hidden" name="chain_id" value="<?php echo (int) $chainId; ?>">
                <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
                <input type="hidden" name="driver_key" value="<?php echo esc_attr($driverKey); ?>">
                <input type="hidden" name="family" value="<?php echo esc_attr($family); ?>">
                <?php wp_nonce_field(
                    $enableAction . '_' . $chainId . '_' . $operation . '_' . $driverKey
                ); ?>
                <label style="font-size:11px;">
                    priority
                    <input type="number" name="priority" min="<?php echo (int) NftCapabilityEditor::PRIORITY_MIN; ?>"
                           max="<?php echo (int) NftCapabilityEditor::PRIORITY_MAX; ?>" step="1"
                           value="<?php echo (int) $priority; ?>" style="width:76px;">
                </label>
                <button type="submit" class="button button-small">Enable</button>
            </form>

            <?php self::renderDriverButton(
                $disableAction,
                $family,
                $chainId,
                $operation,
                $driverKey,
                'Disable',
                $state === NftChainCapability::OVERRIDE_STATE_DISABLED,
                'Switch this driver OFF for this operation on this chain?'
            ); ?>

            <?php self::renderDriverButton(
                $inheritAction,
                $family,
                $chainId,
                $operation,
                $driverKey,
                'Use code default',
                $state === NftChainCapability::OVERRIDE_STATE_DEFAULT,
                'Remove the override row so this driver follows the code registry again, '
                    . 'including its priority?'
            ); ?>
        </div>
        <?php
    }

    // ── Stale rows ──────────────────────────────────────────────────────

    /**
     * Rows this build no longer recognises: shown, labelled inert, removable
     * one exact row at a time, and never touched by anything else.
     *
     * @param array<string, mixed> $chain
     */
    private static function renderStaleOverrides(string $family, int $chainId, array $chain): void
    {
        /** @var list<array<string, mixed>> $stale */
        $stale = is_array($chain['stale_overrides'] ?? null) ? $chain['stale_overrides'] : [];
        if ($stale === []) {
            return;
        }
        ?>
        <h3 style="margin-bottom:4px;">Leftover override rows (inert)</h3>
        <p style="max-width:900px;color:#646970;margin-top:0;">
            These rows name an operation, a driver, or a driver-and-chain pairing
            <strong>this build does not have</strong> — normally the residue of a downgrade, a
            restored backup, or a driver retired between releases.
            <strong>They already do nothing:</strong> every read discards rows the registry does not
            match, so they neither grant nor block anything. They are listed because a row nobody
            can see is a row nobody can clean up. Removing one changes no capability; saving
            anything else on this page leaves them exactly where they are.
        </p>

        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:150px;">Operation</th>
                    <th style="width:180px;">Driver</th>
                    <th>Why it is inert</th>
                    <th style="width:130px;">Remove</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stale as $row): ?>
                    <?php
                    $operation = (string) ($row['operation'] ?? '');
                    $driverKey = (string) ($row['driver_key'] ?? '');
                    $reason    = (string) ($row['reason'] ?? '');
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($operation); ?></code></td>
                        <td><code><?php echo esc_html($driverKey); ?></code></td>
                        <td style="font-size:12px;color:#646970;">
                            <?php echo esc_html(self::staleReasonSentence($reason)); ?>
                        </td>
                        <td>
                            <?php self::renderDriverButton(
                                NftDiscoveryPage::ACTION_CAP_STALE_REMOVE,
                                $family,
                                $chainId,
                                $operation,
                                $driverKey,
                                'Remove row',
                                false,
                                'Delete this leftover override row?'
                                    . "\n\n"
                                    . 'It is already inert, so nothing is enabled or granted by removing it.'
                            ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ── Shared control rendering ────────────────────────────────────────

    /**
     * One direction-named chain-flag button in its own form.
     *
     * `$disabled` reflects the CURRENT state and is a convenience only — it
     * stops an operator pressing something that would do nothing. It is not
     * a gate: the server re-reads the authoritative row and decides again,
     * because a disabled attribute is a property of a page that may be
     * minutes old.
     */
    private static function renderFlagButton(
        string $action,
        string $family,
        int $chainId,
        string $label,
        bool $disabled,
        string $confirm
    ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="display:inline-block;margin-right:6px;"
              onsubmit="return confirm(<?php echo esc_attr(
                  \BCC\Trust\Onchain\Admin\AdminActionSupport::confirmLiteral($confirm)
              ); ?>);">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="chain_id" value="<?php echo (int) $chainId; ?>">
            <input type="hidden" name="family" value="<?php echo esc_attr($family); ?>">
            <?php wp_nonce_field($action . '_' . $chainId); ?>
            <button type="submit" class="button"<?php echo $disabled ? ' disabled' : ''; ?>>
                <?php echo esc_html($label); ?>
            </button>
        </form>
        <?php
    }

    /** One driver-scoped button in its own form, nonce bound to the exact triple. */
    private static function renderDriverButton(
        string $action,
        string $family,
        int $chainId,
        string $operation,
        string $driverKey,
        string $label,
        bool $disabled,
        string $confirm
    ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="display:inline-block;"
              onsubmit="return confirm(<?php echo esc_attr(
                  \BCC\Trust\Onchain\Admin\AdminActionSupport::confirmLiteral($confirm)
              ); ?>);">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="chain_id" value="<?php echo (int) $chainId; ?>">
            <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
            <input type="hidden" name="driver_key" value="<?php echo esc_attr($driverKey); ?>">
            <input type="hidden" name="family" value="<?php echo esc_attr($family); ?>">
            <?php wp_nonce_field($action . '_' . $chainId . '_' . $operation . '_' . $driverKey); ?>
            <button type="submit" class="button button-small"<?php echo $disabled ? ' disabled' : ''; ?>>
                <?php echo esc_html($label); ?>
            </button>
        </form>
        <?php
    }

    /**
     * Yes / no / "this install cannot say" — three answers, never two.
     *
     * A null is the column being absent from the projection, and it is
     * printed as its own thing rather than folded into "off": telling an
     * operator they declined something they were never offered sends them
     * looking for a switch that is not there.
     */
    private static function printTriState(mixed $state, string $yes, string $no): void
    {
        if ($state === null) {
            echo '<span style="color:#d63638;font-weight:600;">Unknown</span>'
                . ' <span style="color:#646970;font-size:12px;">— this install cannot store the value</span>';

            return;
        }

        if ($state === true) {
            echo '<span style="color:#00a32a;font-weight:600;">' . esc_html($yes) . '</span>';

            return;
        }

        echo '<span style="color:#646970;font-weight:600;">' . esc_html($no) . '</span>';
    }

    /** The three override states, said in words rather than left to a symbol. */
    private static function printOverrideState(string $state, int $priority, int $default): void
    {
        switch ($state) {
            case NftChainCapability::OVERRIDE_STATE_DISABLED:
                echo '<span style="color:#d63638;font-weight:600;">Disabled</span>'
                    . '<div style="font-size:11px;color:#646970;">an override row switches it off</div>';

                return;

            case NftChainCapability::OVERRIDE_STATE_ENABLED:
                echo '<span style="color:#00a32a;font-weight:600;">Enabled</span>'
                    . '<div style="font-size:11px;color:#646970;">priority ' . (int) $priority
                    . ' (code default ' . (int) $default . ')</div>';

                return;
        }

        echo '<span style="color:#646970;font-weight:600;">Code default</span>'
            . '<div style="font-size:11px;color:#646970;">no override row · priority '
            . (int) $default . '</div>';
    }

    /** PURE. Why one leftover row cannot be honoured. */
    private static function staleReasonSentence(string $reason): string
    {
        switch ($reason) {
            case NftChainCapability::STALE_UNKNOWN_OPERATION:
                return 'This build has no such operation.';
            case NftChainCapability::STALE_UNKNOWN_DRIVER:
                return 'This build has no such driver. (The retired single "das" driver became '
                    . 'das_rpc and das_helius — two different endpoints, two different answers.)';
            case NftChainCapability::STALE_DRIVER_LACKS_OPERATION:
                return 'That driver exists, but does not perform this operation in this build.';
            case NftChainCapability::STALE_DRIVER_LACKS_CHAIN:
                return 'That driver exists, but does not serve this chain.';
        }

        return 'This build does not recognise this row.';
    }

    /** PURE. The heading for one operation. Mirrors the matrix's column labels. */
    private static function operationLabel(string $operation): string
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
}
