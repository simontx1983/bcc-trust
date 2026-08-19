<?php

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
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
     * Colour per eligibility value.
     *
     * GREEN IS RESERVED FOR `eligible`, and the lookup below defaults to
     * the UNKNOWN colour rather than to grey — an eligibility value this
     * build has never heard of is not a quiet "no", it is a "something is
     * wrong with this panel", and it must not be able to borrow the calm
     * colour of a deliberately-disabled chain.
     *
     * @var array<string, string>
     */
    private const ELIGIBILITY_COLOR = [
        CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ELIGIBLE           => '#00a32a',
        CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_NOT_OPTED_IN       => '#646970',
        CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNSUPPORTED        => '#646970',
        // Amber, matching the State pill this chain already shows: a pause
        // is a hold somebody put on, not a settled fact like "no wasm
        // module" and not a mistake like an unreadable opt-in.
        CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_PAUSED             => '#dba617',
        CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED => '#dba617',
        CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNKNOWN            => '#d63638',
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
                        <em>Enable discovery</em> on a chain below to point it at one —
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
                // per-chain reason in the Discovery column is still where
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
                        canary allowlist. The Discovery and State columns below show why
                        each chain is excluded.
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
                        Nothing is scanned and the controls below are inert — the gate fails
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
                        Start controls below will not do anything until it is.
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
            }
            self::renderSchedule($summary);
            if (!$unavailable) {
                self::renderChains($chains, $enabled, (bool) ($summary['backfill_enabled'] ?? false), $summary);
            }
            ?>
        </details>
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

    /**
     * Per-chain state + the operator controls.
     *
     * The controls post back into the SAME `handlePost()` dispatcher and
     * the SAME nonce the rest of the page uses. A separate form element is
     * required only because HTML forbids nesting one form inside another,
     * and the verification table is already a form.
     *
     * @param list<array<string, mixed>> $chains
     * @param array<string, mixed>       $summary
     */
    private static function renderChains(array $chains, bool $discoveryEnabled, bool $backfillEnabled, array $summary): void
    {
        if ($chains === []) {
            echo '<p><em>No active Cosmos chains are registered, so there is nothing to scan.</em></p>';

            return;
        }

        $working = is_array($summary['working_chain'] ?? null) ? $summary['working_chain'] : null;
        $next    = is_array($summary['next_chain'] ?? null) ? $summary['next_chain'] : null;

        // "How many of these will actually be scanned?" — the question the
        // table used to leave an operator to work out for themselves.
        // int|null on purpose: null means the summary could not work it out,
        // and printing "0 of 21" for that would be an invented finding.
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
                allowlist when one is set — the Discovery column says which, per chain, and why not.
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

        <form method="post" action="" style="margin:0 0 8px 0;">
            <?php wp_nonce_field(VerifyCollectionsPage::NONCE_KEY, VerifyCollectionsPage::NONCE_NAME); ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:110px;">Chain</th>
                        <th style="width:250px;">Discovery</th>
                        <th style="width:100px;">State</th>
                        <th>Progress</th>
                        <th style="width:210px;">Inventory</th>
                        <th style="width:150px;">Last run</th>
                        <th style="width:230px;">Controls</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chains as $chain): ?>
                        <?php self::renderChainRow($chain, $discoveryEnabled, $backfillEnabled); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <?php
    }

    /**
     * @param array<string, mixed> $chain
     */
    private static function renderChainRow(array $chain, bool $discoveryEnabled, bool $backfillEnabled): void
    {
        $chainId     = (int) ($chain['chain_id'] ?? 0);
        $slug        = (string) ($chain['slug'] ?? '');
        $paused      = (bool) ($chain['paused'] ?? false);
        $unsupported = (bool) ($chain['unsupported'] ?? false);
        $lastError   = is_string($chain['last_error'] ?? null) ? (string) $chain['last_error'] : null;
        $familiesErrored = (int) ($chain['families_errored'] ?? 0);
        $age         = is_int($chain['last_discovery_age_seconds'] ?? null)
            ? (int) $chain['last_discovery_age_seconds']
            : null;

        /** @var array<string, int> $familyCounts */
        $familyCounts = is_array($chain['families_by_classification'] ?? null) ? $chain['families_by_classification'] : [];
        ?>
        <tr>
            <td>
                <code><?php echo esc_html($slug); ?></code>
            </td>
            <?php self::renderDiscoveryCell($chain, $chainId, $slug); ?>
            <td>
                <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr(self::stateColor($chain)); ?>;">
                    <?php echo esc_html((string) ($chain['state_label'] ?? '')); ?>
                </span>
            </td>
            <td style="font-size:12px;">
                <?php echo esc_html((string) ($chain['progress_label'] ?? '')); ?>
                <?php if ($familiesErrored > 0): ?>
                    <?php // THE SENTENCE THAT WAS MISSING. Plain, countable,
                          // and it says the work is not lost — an operator
                          // who reads "errors" without "retryable" reaches
                          // for a rebuild they do not need. ?>
                    <br><span style="color:#d63638;font-size:11px;">
                        <?php echo esc_html(sprintf(
                            $familiesErrored === 1
                                ? '%s code family has unresolved discovery errors and remains eligible for retry.'
                                : '%s code families have unresolved discovery errors and remain eligible for retry.',
                            number_format_i18n($familiesErrored)
                        )); ?>
                    </span>
                <?php endif; ?>
                <?php if ($lastError !== null): ?>
                    <details style="margin-top:4px;">
                        <summary style="cursor:pointer;color:#d63638;font-size:11px;">Last recorded reason</summary>
                        <?php
                        // VC-B3a display-safety correction. Same redactor the
                        // NFT Discovery status row uses, so the two surfaces
                        // cannot diverge on what an operator may see. The
                        // stored column and the technical log keep the raw
                        // text — this is display only.
                        ?>
                        <code style="display:block;margin-top:4px;font-size:11px;white-space:pre-wrap;word-break:break-word;"><?php
                            echo esc_html(\BCC\Trust\Onchain\Admin\AdminActionSupport::operatorSafeExcerpt($lastError));
                        ?></code>
                    </details>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;">
                <?php echo esc_html(number_format_i18n(CosmwasmDiscoveryHealthSnapshot::cw721Total($familyCounts))); ?> CW-721
                · <?php echo esc_html(number_format_i18n((int) ($familyCounts[CosmwasmClassifier::NOT_CW721] ?? 0))); ?> non-NFT
                · <?php echo esc_html(number_format_i18n((int) ($chain['families_pending'] ?? 0))); ?> pending
                <br>
                <span style="color:#646970;">
                    <?php echo esc_html(number_format_i18n((int) ($chain['contracts_inspected'] ?? 0))); ?> contracts inspected,
                    <?php echo esc_html(number_format_i18n((int) ($chain['candidates'] ?? 0))); ?> candidates
                    <?php if ((int) ($chain['contracts_denied'] ?? 0) > 0): ?>
                        , <?php echo esc_html(number_format_i18n((int) $chain['contracts_denied'])); ?> hidden
                    <?php endif; ?>
                </span>
            </td>
            <td style="font-size:12px;">
                <?php if ($age === null): ?>
                    <span style="color:#646970;">never</span>
                <?php else: ?>
                    <?php echo esc_html(CosmwasmDiscoveryHealthSnapshot::formatDuration($age)); ?> ago
                <?php endif; ?>
                <?php if (is_string($chain['metadata_refreshed_at'] ?? null)): ?>
                    <br><span style="color:#646970;font-size:11px;">
                        migration check <?php echo esc_html((string) $chain['metadata_refreshed_at']); ?> UTC
                    </span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($unsupported): ?>
                    <span style="color:#646970;font-size:11px;">
                        No CosmWasm module — no scheduled pass runs for this chain and
                        nothing on this page can start one.
                    </span>
                <?php else: ?>
                    <?php if ($paused): ?>
                        <button type="submit"
                                name="bcc_vc_action"
                                value="cw_resume_<?php echo $chainId; ?>"
                                class="button button-small"
                                title="Put this chain back in the rotation. It returns to the state its own progress says it is in, so a completed backfill is not re-walked.">
                            Resume
                        </button>
                    <?php else: ?>
                        <button type="submit"
                                name="bcc_vc_action"
                                value="cw_pause_<?php echo $chainId; ?>"
                                class="button button-small"
                                title="Stop every scanner pass for this chain — backfill, daily, retries. Progress is kept and nothing is lost.">
                            Pause
                        </button>
                    <?php endif; ?>

                    <button type="submit"
                            name="bcc_vc_action"
                            value="cw_backfill_<?php echo $chainId; ?>"
                            class="button button-small"
                            <?php disabled(!$discoveryEnabled || !$backfillEnabled || $paused); ?>
                            title="<?php echo esc_attr(self::startTitle($discoveryEnabled, $backfillEnabled, $paused)); ?>">
                        Run backfill slice
                    </button>

                    <button type="submit"
                            name="bcc_vc_action"
                            value="cw_retry_<?php echo $chainId; ?>"
                            class="button button-small"
                            <?php disabled(!$discoveryEnabled); ?>
                            title="Clear the wait on unresolved code families and contracts for this chain so the next pass looks at them again. Settled non-NFT results and hidden contracts are not touched.">
                        Force retry
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * The Discovery cell: opt-in state, eligibility verdict, the reason,
     * and the control that changes it.
     *
     * ── EVERY READ HERE FAILS CLOSED ────────────────────────────────────
     * `eligibility` is taken from the row only when it is a NON-EMPTY
     * STRING, and anything else — a missing key, a null, an int, an empty
     * string — becomes `unknown`. `discovery_opted_in` is compared with
     * `=== true`, so `'1'`, `1` and a missing key all read as NOT opted in.
     *
     * That is not defensive noise. The panel is a display of a fail-closed
     * rule, and the one thing it must never do is let an ABSENT field read
     * as a permission: "this key is missing" and "an operator switched
     * this on" have to be different answers, or a summary built by older
     * code would quietly show every chain as opted in and eligible.
     *
     * `eligibility_reason` is a plain sentence produced by the snapshot and
     * escaped here — the view derives no policy of its own, it only prints
     * what it was handed.
     *
     * @param array<string, mixed> $chain
     */
    private static function renderDiscoveryCell(array $chain, int $chainId, string $slug): void
    {
        $eligibility = is_string($chain['eligibility'] ?? null) && $chain['eligibility'] !== ''
            ? (string) $chain['eligibility']
            : CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNKNOWN;

        $optedIn = ($chain['discovery_opted_in'] ?? null) === true;

        $reason = is_string($chain['eligibility_reason'] ?? null) && $chain['eligibility_reason'] !== ''
            ? (string) $chain['eligibility_reason']
            : CosmwasmDiscoveryHealthSnapshot::eligibilityReason(
                CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNKNOWN,
                null
            );

        $color = self::ELIGIBILITY_COLOR[$eligibility]
            ?? self::ELIGIBILITY_COLOR[CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNKNOWN];
        ?>
        <td style="font-size:12px;">
            <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr($color); ?>;">
                <?php echo esc_html(CosmwasmDiscoveryHealthSnapshot::eligibilityLabel($eligibility)); ?>
            </span>
            <span style="margin-left:6px;color:#646970;">
                opt-in <strong><?php echo esc_html($optedIn ? 'ON' : 'OFF'); ?></strong>
            </span>

            <div style="margin-top:4px;color:#646970;"><?php echo esc_html($reason); ?></div>

            <?php
            // ── VC-B2 TRANSITIONAL DUPLICATION — READ BEFORE "TIDYING" ──
            //
            // The control that CHANGES the discovery opt-in moved to
            // Chains ▸ NFT Discovery ▸ CosmWasm / CW-721, where it has its
            // own direction- and chain-scoped nonce instead of sharing one
            // page-wide nonce with a provider-consuming backfill.
            //
            // The opt-in STATE is still shown here, and also on the new tab.
            // That duplication is deliberate and bounded:
            //
            //   • ONE authoritative source. Both surfaces render from
            //     CosmwasmDiscoveryHealthSnapshot::buildSummary(). Neither
            //     computes eligibility itself, so they cannot disagree.
            //     ChainsNftDiscoveryTabTest pins this.
            //   • ZERO duplicate mutation controls. Exactly one Enable and
            //     one Disable exist in production, both on the new tab.
            //   • It is here only because Pause / Resume / Backfill / Retry
            //     still live on this page. An operator deciding whether to
            //     pause a chain needs to know whether it is even opted in.
            //
            // VC-B3 moves those four worker controls to the same tab and
            // deletes this panel's per-chain display with them. Until then,
            // removing the read-only state here would leave the remaining
            // controls without the context needed to use them safely.
            ?>
            <div style="margin-top:4px;font-size:11px;">
                <a href="<?php echo esc_url(
                    admin_url('admin.php?page=bcc-onchain-chains&subtab=nft-discovery')
                ); ?>">Change in Chains ▸ NFT Discovery</a>
            </div>
        </td>
        <?php
    }

    /** Why the start control is or is not usable, in one sentence. */
    private static function startTitle(bool $discoveryEnabled, bool $backfillEnabled, bool $paused): string
    {
        if (!$discoveryEnabled) {
            return 'Unavailable: BCC_COSMWASM_DISCOVERY_ENABLED is not defined, so no scanner work can run.';
        }
        if (!$backfillEnabled) {
            return 'Unavailable: BCC_COSMWASM_BACKFILL_ENABLED is not defined, so the historical walk cannot run.';
        }
        if ($paused) {
            return 'Unavailable: this chain is paused. Resume it first.';
        }

        return 'Run one bounded slice of the historical walk now instead of waiting for the next scheduled tick.';
    }

    /** @param array<string, mixed> $chain */
    private static function stateColor(array $chain): string
    {
        if ((bool) ($chain['unsupported'] ?? false)) {
            return '#646970';
        }
        if ((bool) ($chain['paused'] ?? false)) {
            return '#dba617';
        }

        $state = (string) ($chain['state'] ?? '');
        if ($state === ChainCheckpointRepository::CW_STATE_BACKFILLED) {
            return '#00a32a';
        }
        if ($state === ChainCheckpointRepository::CW_STATE_BACKFILLING) {
            return '#2271b1';
        }

        return '#646970';
    }

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
