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
     * @var array<string, string>
     */
    private const STATUS_COLOR = [
        CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN    => '#00a32a',
        CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW   => '#dba617',
        CosmwasmDiscoveryHealthSnapshot::STATUS_RED      => '#d63638',
        CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED => '#646970',
    ];

    /**
     * Render the whole scanner panel.
     *
     * @param array<string, mixed> $summary {@see CosmwasmDiscoveryHealthSnapshot::buildSummary()}
     */
    public static function render(array $summary): void
    {
        $status  = is_string($summary['status'] ?? null) ? (string) $summary['status'] : CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED;
        $enabled = (bool) ($summary['discovery_enabled'] ?? false);
        $color   = self::STATUS_COLOR[$status] ?? '#646970';

        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($summary['chains'] ?? null) ? array_values($summary['chains']) : [];
        /** @var list<string> $issues */
        $issues = is_array($summary['issues'] ?? null) ? array_values($summary['issues']) : [];
        ?>
        <details class="bcc-cw-scanner" style="margin:0 0 16px 0;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px;background:#fff;" <?php echo $status === CosmwasmDiscoveryHealthSnapshot::STATUS_RED ? 'open' : ''; ?>>
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

            <?php if (!$enabled): ?>
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

            <?php if ($issues !== []): ?>
                <ul style="margin:8px 0 12px 18px;color:#3c434a;font-size:12px;list-style:disc;">
                    <?php foreach ($issues as $issue): ?>
                        <li><?php echo esc_html($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php
            self::renderTotals($summary);
            self::renderSchedule($summary);
            self::renderChains($chains, $enabled, (bool) ($summary['backfill_enabled'] ?? false), $summary);
            ?>
        </details>
        <?php
    }

    /**
     * One-line count summary for the collapsed <summary> element.
     *
     * @param array<string, mixed> $summary
     */
    private static function headlineCounts(array $summary): string
    {
        /** @var array<string, int> $totals */
        $totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];

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
        /** @var array<string, int> $t */
        $t = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];

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
        ?>
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
            <td>
                <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr(self::stateColor($chain)); ?>;">
                    <?php echo esc_html((string) ($chain['state_label'] ?? '')); ?>
                </span>
            </td>
            <td style="font-size:12px;">
                <?php echo esc_html((string) ($chain['progress_label'] ?? '')); ?>
                <?php if ($lastError !== null): ?>
                    <details style="margin-top:4px;">
                        <summary style="cursor:pointer;color:#d63638;font-size:11px;">Last recorded reason</summary>
                        <code style="display:block;margin-top:4px;font-size:11px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html($lastError); ?></code>
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
                        Permanently skipped — this chain has no CosmWasm module.
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
