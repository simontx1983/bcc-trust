<?php

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Services\CosmwasmEvidenceNarrator;
use BCC\Trust\Onchain\Support\ExplorerLinkBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CosmWasm CW-721 scanner panel — the RENDER half of the scanner surface
 * on the existing "Verify Collections" page.
 *
 * ── WHY THIS IS A VIEW AND NOT A PAGE ───────────────────────────────────
 * The scanner has no queue of its own. Everything it finds lands as an
 * UNVERIFIED row in `wp_bcc_onchain_collections`, which the Verify
 * Collections page already lists — so the scanner's operator surface is
 * "context and controls around that existing queue", not a second queue.
 * A separate page would have meant two places to approve a collection
 * from, which is exactly one too many.
 *
 * ── NO QUERIES HERE ─────────────────────────────────────────────────────
 * §3: this file renders pre-fetched data and nothing else. Every number
 * it prints comes from
 * {@see CosmwasmDiscoveryHealthSnapshot::buildSummary()} (four bounded
 * aggregates for ALL chains) or from the two bounded batch reads the page
 * does before the row loop. There is no per-row lookup and no per-chain
 * loop over a single-chain summary.
 *
 * ── PHP-RENDERED HTML IS LEGAL HERE ─────────────────────────────────────
 * §8 forbids UI in PHP everywhere except an `Admin` directory, which is
 * where this file lives. wp-admin is the documented exception; nothing in
 * this class may be reused outside it.
 */
final class CosmwasmScannerPanel
{
    /**
     * Colour for each overall status. Matches the palette
     * {@see NftIndexerStatusView} already uses so the two health panels
     * do not teach the operator two different colour languages.
     *
     * `idle` takes the SAME neutral grey as `disabled` and deliberately
     * not a colour of its own: both mean "on purpose, nothing running",
     * and a fourth hue would imply a fourth severity. It must never take
     * the green — that one says the scanner is working, which an idle
     * scanner is not.
     *
     * `blocked` takes the amber, and NOT that grey. Nothing has failed, so
     * it is not the red; nothing is running, so it is emphatically not the
     * green; and unlike idle and disabled there is something an operator
     * has to change before the selection they have already made can
     * produce any work. Amber is this palette's "act on me, nothing is on
     * fire", which is exactly the register. It borrows the existing hue
     * rather than inventing a fifth: the badge prints the word BLOCKED
     * beside it, so the colour carries severity and the word carries
     * meaning — which is the division of labour the four hues already
     * assume.
     *
     * @var array<string, string>
     */
    private const STATUS_COLOR = [
        CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN       => '#00a32a',
        CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW      => '#dba617',
        CosmwasmDiscoveryHealthSnapshot::STATUS_RED         => '#d63638',
        CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED    => '#646970',
        CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE        => '#646970',
        CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED     => '#dba617',
        CosmwasmDiscoveryHealthSnapshot::STATUS_UNAVAILABLE => '#d63638',
    ];

    /**
     * Render the whole scanner panel.
     *
     * When the summary reports `data_unavailable`, EVERY DB-derived block
     * is skipped rather than rendered from defaults. That is load-bearing:
     * this file reads its numbers with `(int) ($t['families'] ?? 0)` and
     * its chain list with `?: []`, so rendering an unavailable summary
     * through the normal path would print a tidy wall of zeroes and "No
     * active Cosmos chains are registered, so there is nothing to scan."
     * — a confident answer to a question nobody managed to ask.
     *
     * @param array<string, mixed> $summary {@see CosmwasmDiscoveryHealthSnapshot::buildSummary()}
     */
    public static function render(array $summary): void
    {
        $status  = is_string($summary['status'] ?? null) ? (string) $summary['status'] : CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED;
        $enabled = (bool) ($summary['discovery_enabled'] ?? false);
        $color   = self::STATUS_COLOR[$status] ?? '#646970';

        $unavailable = (bool) ($summary['data_unavailable'] ?? false);

        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($summary['chains'] ?? null) ? array_values($summary['chains']) : [];
        /** @var list<string> $issues */
        $issues = is_array($summary['issues'] ?? null) ? array_values($summary['issues']) : [];

        // FORCED OPEN for the states an operator has to deal with now.
        // `blocked` joins red and unavailable because it is the one calm
        // status with an action attached: the selection cannot produce
        // work, and the row naming the chain in the way is inside this
        // disclosure. `idle` and `disabled` stay closed — nothing is
        // waiting on anybody there.
        $forceOpen = $unavailable
            || $status === CosmwasmDiscoveryHealthSnapshot::STATUS_RED
            || $status === CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED;
        ?>
        <details class="bcc-cw-scanner" style="margin:0 0 16px 0;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px;background:#fff;" <?php echo $forceOpen ? 'open' : ''; ?>>
            <summary style="cursor:pointer;font-weight:600;">
                CosmWasm collection scanner
                <span style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr($color); ?>;">
                    <?php echo esc_html(strtoupper($status)); ?>
                </span>
                <span style="margin-left:8px;font-weight:400;color:#646970;font-size:12px;">
                    <?php echo esc_html(self::headlineCounts($summary)); ?>
                </span>
            </summary>

            <p style="color:#646970;margin:8px 0;">
                One resumable historical pass per Cosmos chain, then incremental checks
                for new code IDs and new contracts under confirmed CW-721 code families.
                Settled contracts are not rescanned. Everything it finds arrives here
                <strong>unverified</strong> — only you can approve a collection for a
                holder community.
            </p>

            <?php if ($unavailable): ?>
                <div class="notice notice-error inline" style="margin:8px 0;">
                    <p>
                        <strong>Scanner figures are unavailable.</strong>
                        <?php echo esc_html((string) ($summary['unavailable_reason'] ?? '')); ?>
                    </p>
                    <p>
                        The counts, the per-chain rows and the controls are hidden rather than
                        shown as zeroes — a scanner that has found nothing and a scanner nobody
                        could read look identical once you print a 0, and only one of those is
                        worth acting on. Reload once the database error clears.
                    </p>
                </div>
            <?php elseif ($status === CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE): ?>
                <?php
                // NEUTRAL BY CONSTRUCTION. notice-info, not notice-warning
                // and not notice-error: nothing here is degraded and
                // nothing here failed. An operator who has opted no chain
                // in has configured the scanner, not broken it, and this
                // block must never read as a fault — the panel loses the
                // right to alarm anybody the day it alarms them about a
                // choice they made.
                //
                // It sits AHEAD of the switched-off notice on purpose,
                // matching CosmwasmDiscoveryHealthSnapshot::deriveStatus():
                // with no chain opted in, defining the constant would
                // change nothing, so leading with it would send someone to
                // wp-config.php for no effect. The constant is still named
                // below when it is also undefined, so no fact is hidden.
                ?>
                <div class="notice notice-info inline" style="margin:8px 0;">
                    <p>
                        <strong>Idle — no chains enabled for NFT discovery.</strong>
                        No chain has been opted in, so there is nothing for the scanner to
                        walk. This is a configuration state, not a fault: nothing has
                        failed, nothing is behind, and no work is waiting. Use
                        <em>Enable discovery</em> on a chain in Chains ▸ NFT Discovery to point it at
                        one —
                        everything it then finds still arrives here <strong>unverified</strong>.
                    </p>
                    <?php if (!$enabled): ?>
                        <p>
                            <?php echo esc_html((string) ($summary['disabled_reason'] ?? '')); ?>
                            That gate would have to be opened as well before any scheduled
                            pass ran, but on its own it changes nothing while no chain is
                            opted in.
                        </p>
                    <?php endif; ?>
                </div>
            <?php elseif ($status === CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED): ?>
                <?php
                // notice-warning, NOT notice-error and NOT notice-info.
                // Nothing failed, so it is not an error; but unlike the
                // idle notice above there is something to change before
                // the selection an operator has already made can produce
                // any work, so it does not get the calm treatment either.
                //
                // It sits AHEAD of the switched-off notice for the same
                // reason the idle block does, and
                // CosmwasmDiscoveryHealthSnapshot::deriveStatus() ranks it
                // the same way: with no scannable opted-in chain, defining
                // the constant would change nothing. The constant is still
                // named below when it is also undefined.
                //
                // THE COPY NAMES ALL THREE EXCLUSIONS, not two. It used to
                // say "either paused or has no CosmWasm module", written
                // when the status arithmetic only knew about those two —
                // so on the site that prompted this fix, where the whole
                // selection sat outside BCC_COSMWASM_CHAIN_ALLOWLIST, the
                // notice described a state the operator was not in. The
                // per-chain reason in NFT Discovery is still where
                // the specifics live; this paragraph only has to send them
                // to the right column.
                ?>
                <div class="notice notice-warning inline" style="margin:8px 0;">
                    <p>
                        <strong>No opted-in chain can currently be scanned.</strong>
                        Every chain enabled for NFT discovery is paused, marked as lacking
                        CosmWasm support, or outside the current canary allowlist.
                        Nothing has failed and nothing is
                        behind—the current selection cannot produce any scanner work.
                        Enable an eligible chain, resume a paused chain, or update the
                        canary allowlist. The Discovery and State columns in Chains ▸ NFT
                        Discovery show why each chain is excluded.
                    </p>
                    <?php if (!$enabled): ?>
                        <p>
                            <?php echo esc_html((string) ($summary['disabled_reason'] ?? '')); ?>
                            That gate would have to be opened as well before any scheduled
                            pass ran, but on its own it changes nothing while no opted-in
                            chain can be scanned.
                        </p>
                    <?php endif; ?>
                </div>
            <?php elseif (!$enabled): ?>
                <div class="notice notice-warning inline" style="margin:8px 0;">
                    <p>
                        <strong>Discovery is switched off.</strong>
                        <?php echo esc_html((string) ($summary['disabled_reason'] ?? '')); ?>
                        Nothing is scanned and the scanner controls in Chains ▸ NFT Discovery are
                        inert — the gate fails
                        closed on purpose, so a missing constant never silently means
                        "enabled". Add
                        <code>define('BCC_COSMWASM_DISCOVERY_ENABLED', true);</code>
                        to <code>wp-config.php</code> to turn it on, and
                        <code>define('BCC_COSMWASM_BACKFILL_ENABLED', true);</code>
                        as well for the one-time historical walk.
                    </p>
                </div>
            <?php elseif (!(bool) ($summary['backfill_enabled'] ?? false)): ?>
                <div class="notice notice-info inline" style="margin:8px 0;">
                    <p>
                        Incremental discovery is running, but the <strong>historical backfill is
                        off</strong> — <code>BCC_COSMWASM_BACKFILL_ENABLED</code> is not defined.
                        The Backfill control in Chains ▸ NFT Discovery is not offered until it is.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($issues !== [] && !$unavailable): ?>
                <ul style="margin:8px 0 12px 18px;color:#3c434a;font-size:12px;list-style:disc;">
                    <?php foreach ($issues as $issue): ?>
                        <li><?php echo esc_html($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php
            // The cron table is NOT a database read (wp_next_scheduled), so
            // it survives an unavailable summary and is worth keeping: a
            // stalled cron and a broken DB otherwise look the same.
            if (!$unavailable) {
                self::renderTotals($summary);
                self::renderCoverage($summary, $chains);
            }
            self::renderSchedule($summary);
            // VC-B3b: the per-chain table — the status columns AND the four
            // scanner controls — moved to Chains ▸ NFT Discovery ▸ CosmWasm /
            // CW-721 Discovery, which is now their single owner. Keeping a
            // second copy here is what the transitional duplication existed
            // to avoid becoming permanent.
            //
            // What stays: the chain-wide aggregate and schedule, which the
            // NFT Discovery section does not show, and the per-collection
            // candidate detail rendered from the verification table below.
            //
            // Rendered UNCONDITIONALLY, outside the `$unavailable` guard that
            // hides every DB-derived block. It derives nothing from the
            // summary, so there are no zeroes for it to invent — and a failed
            // database read is exactly the moment an operator goes looking
            // for the controls this panel used to carry.
            self::renderMovedNotice();
            ?>
        </details>
        <?php
    }

    /**
     * Chain-wide coverage and rotation — the two AGGREGATE paragraphs.
     *
     * ── WHY THESE STAYED WHEN THE TABLE WENT ────────────────────────────
     * They lived inside the per-chain table's renderer, but they were never
     * per-chain: "how many of the listed chains will ever be scanned" and
     * "which chain the rotation is on" are single facts about the whole
     * scanner, and NFT Discovery — which is organised one row per chain —
     * shows neither. Removing them with the table would have deleted
     * aggregate information nothing else reports, which is exactly the kind
     * of quiet loss the move was supposed to avoid.
     *
     * `eligible_chain_count` is int|null on purpose. Null means the summary
     * could not work it out, and printing "0 of 21" for that would be an
     * invented finding — the same fail-closed rule the rest of this file
     * follows.
     *
     * @param array<string, mixed>       $summary
     * @param list<array<string, mixed>> $chains
     */
    private static function renderCoverage(array $summary, array $chains): void
    {
        if ($chains === []) {
            echo '<p><em>No active Cosmos chains are registered, so there is nothing to scan.</em></p>';

            return;
        }

        $working = is_array($summary['working_chain'] ?? null) ? $summary['working_chain'] : null;
        $next    = is_array($summary['next_chain'] ?? null) ? $summary['next_chain'] : null;

        $eligibleCount = is_int($summary['eligible_chain_count'] ?? null)
            ? (int) $summary['eligible_chain_count']
            : null;
        ?>
        <p style="font-size:12px;color:#646970;margin:0 0 6px 0;">
            <?php if ($eligibleCount !== null): ?>
                <strong><?php echo esc_html(number_format_i18n($eligibleCount)); ?>
                of <?php echo esc_html(number_format_i18n(count($chains))); ?></strong>
                listed chains are eligible for the scanner.
                A chain is scanned only when an operator has opted it in, the chain reports a
                working wasm module, nobody has paused it, <em>and</em> it is inside the canary
                allowlist when one is set. Chains ▸ NFT Discovery says which, per chain, and why not.
            <?php else: ?>
                How many of these chains are eligible could not be worked out.
            <?php endif; ?>
        </p>

        <p style="font-size:12px;color:#646970;margin:0 0 6px 0;">
            <?php if ($working !== null): ?>
                Currently working through <strong><?php echo esc_html((string) ($working['slug'] ?? '')); ?></strong>.
            <?php else: ?>
                No chain is mid-backfill.
            <?php endif; ?>
            <?php if ($next !== null): ?>
                Next backfill slice goes to <strong><?php echo esc_html((string) ($next['slug'] ?? '')); ?></strong>
                (least recently worked first, so one broken chain cannot starve the rest).
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * The forwarding notice left where the per-chain table used to be.
     *
     * ── WHY A NOTICE AND NOT SILENCE ────────────────────────────────────
     * The table that stood here carried both the per-chain status columns
     * and the four scanner controls. Deleting it without a marker would
     * leave an operator who knows this panel with no way to find out where
     * Pause, Resume, Backfill and Retry went — the controls would look
     * withdrawn rather than moved.
     *
     * ── WHAT THIS MUST NOT DO ───────────────────────────────────────────
     * It renders a link and nothing else. No form, no nonce, no button:
     * this file is no longer a mutation surface for the scanner, and the
     * whole point of VC-B3b is that exactly one page owns those controls.
     * It also reads nothing — no snapshot, no repository, no worker, no
     * provider — so it costs nothing and cannot fail.
     *
     * ── TWO CLAIMS IT DELIBERATELY DOES NOT MAKE ────────────────────────
     * That NFT Discovery is a CosmWasm-only page: it is not. The section
     * named here is one engine's section on it, and chains served by the
     * EVM and Solana NFT paths are neither described nor implied to be
     * ineligible for NFT discovery — they are simply not scanned by THIS
     * engine.
     *
     * And that opening the page does anything. Following this link
     * navigates; it starts no pass, no backfill and no provider call.
     */
    private static function renderMovedNotice(): void
    {
        $url = admin_url(
            'admin.php?page=' . ChainsPage::PAGE_SLUG
            . '&subtab=' . ChainsPage::SUBTAB_NFT_DISCOVERY
        );
        ?>
        <div class="notice notice-info inline bcc-cw-scanner-moved" style="margin:12px 0 4px 0;">
            <p style="margin:8px 0;">
                <strong>Per-chain scanner status and controls have moved.</strong>
                Per-chain CosmWasm/CW-721 scanner status and controls now live under
                <strong>Chains ▸ NFT Discovery</strong>. Overall scanner schedule and
                aggregate discovery totals remain here.
            </p>
            <p style="margin:8px 0;color:#646970;font-size:12px;">
                That section covers the CosmWasm/CW-721 engine specifically. Chains
                indexed by the other NFT engines are handled by their own surfaces —
                nothing there marks them as ineligible for NFT discovery. Opening the
                page starts no scan.
            </p>
            <p style="margin:8px 0;">
                <a href="<?php echo esc_url($url); ?>">Open NFT Discovery</a>
            </p>
        </div>
        <?php
    }

    /**
     * One-line count summary for the collapsed <summary> element.
     *
     * Says so out loud when there are no numbers. `?? 0` on a missing
     * total would read as "0 code families", which is a finding, not an
     * absence.
     *
     * @param array<string, mixed> $summary
     */
    private static function headlineCounts(array $summary): string
    {
        if (!is_array($summary['totals'] ?? null)) {
            return 'counts unavailable — a database read failed';
        }

        /** @var array<string, int> $totals */
        $totals = $summary['totals'];

        return sprintf(
            '%s code families · %s CW-721 · %s contracts inspected · %s candidates awaiting review',
            number_format_i18n((int) ($totals['families'] ?? 0)),
            number_format_i18n((int) ($totals['families_cw721'] ?? 0)),
            number_format_i18n((int) ($totals['contracts_inspected'] ?? 0)),
            number_format_i18n((int) ($totals['candidates'] ?? 0))
        );
    }

    /**
     * The counts block. Every figure here is a slice of ONE of the four
     * bounded aggregates — the label on each tile names which question it
     * answers so an operator can tell "found" from "checked".
     *
     * @param array<string, mixed> $summary
     */
    private static function renderTotals(array $summary): void
    {
        // Belt and braces with the caller's guard: no totals, no tiles.
        // Every tile below defaults to 0, so this must never be reached
        // with an unavailable summary.
        if (!is_array($summary['totals'] ?? null)) {
            return;
        }

        /** @var array<string, int> $t */
        $t = $summary['totals'];

        $tiles = [
            ['Code families known', (int) ($t['families'] ?? 0), 'Distinct wasm binaries inventoried across all Cosmos chains.'],
            ['Classified CW-721', (int) ($t['families_cw721'] ?? 0), 'Code families that answered the CW-721 query set (confirmed + probable).'],
            ['Classified non-NFT', (int) ($t['families_not_cw721'] ?? 0), 'Code families that refused every CW-721 query. Settled — never re-checked.'],
            ['Inconclusive', (int) ($t['families_inconclusive'] ?? 0), 'No verdict yet: nothing deployed from the code, or the node would not answer.'],
            ['Awaiting classification', (int) ($t['families_pending'] ?? 0), 'Code families the scanner still has to decide.'],
            ['Contracts inspected', (int) ($t['contracts_inspected'] ?? 0), 'Individual contracts actually probed (not merely listed).'],
            ['Unverified candidates', (int) ($t['candidates'] ?? 0), 'Probable NFT collections found. They wait here until you approve one.'],
            ['Hidden by a rule', (int) ($t['denied'] ?? 0), 'Candidates suppressed by an operator DENY rule. They stay suppressed through rediscovery.'],
        ];
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px 0;">
            <?php foreach ($tiles as [$label, $value, $tip]): ?>
                <div title="<?php echo esc_attr($tip); ?>"
                     style="flex:1 1 150px;min-width:150px;border:1px solid #dcdcde;border-radius:4px;padding:6px 10px;background:#f6f7f7;">
                    <div style="font-size:11px;color:#646970;"><?php echo esc_html($label); ?></div>
                    <div style="font-size:18px;font-weight:600;"><?php echo esc_html(number_format_i18n($value)); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * The cron picture. Registration and permission are separate facts and
     * both are shown: a hook can be scheduled on a site where the gate is
     * off, in which case it fires and does nothing.
     *
     * @param array<string, mixed> $summary
     */
    private static function renderSchedule(array $summary): void
    {
        /** @var list<array<string, mixed>> $schedule */
        $schedule = is_array($summary['schedule'] ?? null) ? array_values($summary['schedule']) : [];
        if ($schedule === []) {
            return;
        }
        $now = time();
        ?>
        <table class="widefat striped" style="margin-bottom:12px;">
            <thead>
                <tr>
                    <th style="width:34%;">Scheduled pass</th>
                    <th style="width:16%;">Cadence</th>
                    <th style="width:25%;">Next run</th>
                    <th>Hook</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedule as $entry): ?>
                    <?php
                    $scheduled = (bool) ($entry['scheduled'] ?? false);
                    $nextAt    = is_int($entry['next_run_at'] ?? null) ? (int) $entry['next_run_at'] : null;
                    $overdue   = (int) ($entry['overdue_seconds'] ?? 0);
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) ($entry['label'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($entry['interval'] ?? '')); ?></code></td>
                        <td>
                            <?php if (!$scheduled): ?>
                                <span style="color:#d63638;font-weight:600;">not scheduled</span>
                            <?php elseif ($overdue > 0): ?>
                                <span style="color:#dba617;">
                                    overdue by <?php echo esc_html(CosmwasmDiscoveryHealthSnapshot::formatDuration($overdue)); ?>
                                </span>
                            <?php else: ?>
                                in <?php echo esc_html(CosmwasmDiscoveryHealthSnapshot::formatDuration(max(0, ($nextAt ?? $now) - $now))); ?>
                            <?php endif; ?>
                        </td>
                        <td><code style="font-size:11px;"><?php echo esc_html((string) ($entry['hook'] ?? '')); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** Why the start control is or is not usable, in one sentence. */
    /** @param array<string, mixed> $chain */
    /**
     * Per-candidate scanner detail, rendered as a sub-row underneath a
     * collection row in the verification table.
     *
     * Renders NOTHING when the scanner has no record of the contract —
     * the collection came from another path (a manual add, a wallet-link
     * discovery) and inventing a scanner story for it would be a lie.
     *
     * ── WHAT IT SHOWS AND WHAT IT REFUSES TO ────────────────────────────
     * The evidence is rendered as a SENTENCE via
     * {@see CosmwasmEvidenceNarrator}, never as the raw stored tokens.
     * `last_error` is upstream text; it is offered behind a disclosure and
     * labelled as such rather than pasted inline, because it is the one
     * field whose content we do not control.
     *
     * @param object{
     *     contract_address: string,
     *     collection_name: string|null,
     *     token_standard: string|null,
     *     total_supply: string|null,
     *     chain_slug: string,
     *     explorer_url?: string|null
     * } $collection
     * @param object{
     *     code_id: string,
     *     classification: string,
     *     classification_reason: string|null,
     *     probes_ok: string|null,
     *     probes_failed: string|null,
     *     last_error: string|null,
     *     denied: string,
     *     discovered_at: string
     * } $candidate
     * @param object{checksum: string|null, classification: string}|null $family
     */
    public static function renderCandidateDetail(
        object $collection,
        object $candidate,
        ?object $family,
        bool $isVerified,
        int $colspan
    ): void {
        $classification = (string) $candidate->classification;
        $denied         = (int) $candidate->denied === 1;

        $sentence = CosmwasmEvidenceNarrator::describe(
            $classification,
            (string) ($candidate->classification_reason ?? ''),
            (string) ($candidate->probes_ok ?? ''),
            (string) ($candidate->probes_failed ?? '')
        );
        $nextStep = CosmwasmEvidenceNarrator::nextStep($classification);

        $explorerUrl = ExplorerLinkBuilder::addressUrl(
            isset($collection->explorer_url) && is_string($collection->explorer_url) ? $collection->explorer_url : null,
            (string) $collection->contract_address
        );

        $checksum = $family !== null && is_string($family->checksum) && $family->checksum !== ''
            ? (string) $family->checksum
            : null;

        $tokenCount = is_numeric($collection->total_supply ?? null) ? (int) $collection->total_supply : null;
        ?>
        <tr class="bcc-cw-candidate">
            <td colspan="<?php echo (int) $colspan; ?>" style="background:#f6f7f7;border-top:0;padding:6px 12px;font-size:12px;">
                <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr(self::classificationColor($classification)); ?>;">
                    <?php echo esc_html(CosmwasmEvidenceNarrator::classificationLabel($classification)); ?>
                </span>
                <span style="color:#646970;">
                    confidence <?php echo esc_html(CosmwasmEvidenceNarrator::confidence($classification)); ?>
                </span>

                <span style="margin-left:8px;color:#3c434a;"><?php echo esc_html($sentence); ?></span>

                <div style="margin-top:4px;color:#646970;">
                    <?php echo esc_html((string) $collection->chain_slug); ?>
                    · code ID <?php echo esc_html((string) $candidate->code_id); ?>
                    <?php if ($checksum !== null): ?>
                        · family <code style="font-size:11px;"><?php echo esc_html(substr($checksum, 0, 12)); ?>…</code>
                    <?php endif; ?>
                    <?php if ($tokenCount !== null): ?>
                        · <?php echo esc_html(number_format_i18n($tokenCount)); ?> tokens
                    <?php endif; ?>
                    · first seen <?php echo esc_html((string) $candidate->discovered_at); ?> UTC
                    · <?php echo esc_html($isVerified ? 'verified by an admin' : 'awaiting admin approval'); ?>
                    <?php if ($denied): ?>
                        · <strong style="color:#d63638;">hidden by a DENY rule</strong>
                    <?php endif; ?>
                    <?php if ($explorerUrl !== null): ?>
                        · <a href="<?php echo esc_url($explorerUrl); ?>" target="_blank" rel="noopener">explorer</a>
                    <?php endif; ?>
                </div>

                <?php if ($nextStep !== ''): ?>
                    <div style="margin-top:2px;color:#646970;"><?php echo esc_html($nextStep); ?></div>
                <?php endif; ?>

                <?php if (is_string($candidate->last_error) && $candidate->last_error !== ''): ?>
                    <details style="margin-top:4px;">
                        <summary style="cursor:pointer;color:#646970;font-size:11px;">
                            Last upstream message (raw excerpt from the chain node)
                        </summary>
                        <code style="display:block;margin-top:4px;font-size:11px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html((string) $candidate->last_error); ?></code>
                    </details>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function classificationColor(string $classification): string
    {
        switch ($classification) {
            case CosmwasmClassifier::CONFIRMED:
                return '#00a32a';
            case CosmwasmClassifier::PROBABLE:
                return '#2271b1';
            case CosmwasmClassifier::NOT_CW721:
                return '#d63638';
            case CosmwasmClassifier::UNREACHABLE:
                return '#dba617';
            default:
                return '#646970';
        }
    }
}
