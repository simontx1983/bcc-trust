<?php

declare(strict_types=1);

/**
 * The per-chain "Scan On-Chain for Easy Discovery" control.
 *
 * ── WRITTEN FOR AN OPERATOR, NOT AN ENGINEER ────────────────────────────
 * Everything here is phrased so someone who does not know what a CW-721 is
 * can still tell what happened and what to do next. "Nothing new was found"
 * is a SUCCESS and reads like one; "we could not check" is a failure and
 * reads like one; and a scan that stopped at its own ceiling says so instead
 * of pretending it finished.
 *
 * ── IT RENDERS STATE, IT DOES NOT DECIDE IT ─────────────────────────────
 * Every value comes from {@see DiscoveryRunStatusReader}, the PR 7A read
 * model. This view writes nothing, starts nothing and contacts nothing. The
 * `pickup_overdue` flag it shows is DERIVED at read time; a queued run is
 * never failed for being old.
 *
 * ── THE DISABLED BUTTON IS A COURTESY, NOT A GATE ───────────────────────
 * ⚠ Scanning is currently disabled on every chain. The button renders
 * disabled with the reason spelled out, but the real refusal happens in
 * {@see \BCC\Trust\Onchain\Services\DiscoveryRunService}, which re-decides on
 * every request. A user who re-enables the button in their browser gets a
 * bounded refusal, not a scan.
 *
 * @package BCC\Trust\Onchain\Admin\Views
 */

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Admin\AdminActionSupport;
use BCC\Trust\Onchain\Admin\DiscoveryScanActions;
use BCC\Trust\Onchain\Services\DiscoveryRunStatusReader;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;

if (!defined('ABSPATH')) {
    exit;
}

final class DiscoveryScanPanel
{
    /**
     * Operator-facing wording for every bounded refusal code.
     *
     * ⚠ A code with no entry here falls back to a generic sentence rather
     * than being echoed. The codes are internal vocabulary; some of them
     * ("audit_uncommitted") mean nothing to an operator and all of them look
     * like a leak when rendered raw.
     *
     * @return array<string, string>
     */
    public static function reasonCopy(): array
    {
        return [
            'discovery_disabled'         => 'Scanning has not been enabled for this chain.',
            'chain_unsupported'          => 'This chain does not support on-chain collection discovery.',

            // ── PR 7.1: the blockers that used to be indistinguishable ──
            // Each of these previously surfaced as "not ready to scan yet",
            // which told an operator nothing about what to change. The
            // wording names the switch and, where an operator can act, says
            // where. Nothing here echoes a constant name or a file path: an
            // operator does not edit wp-config from this screen, and a
            // reason line is not the place to teach them to.
            'not_opted_in'               => 'Scanning has not been switched on for this chain yet.',
            'paused'                     => 'Scanning is paused for this chain.',
            'allowlist_excluded'         => 'This chain is outside the current scanning rollout.',
            'nft_discovery_unsupported'  => 'BCC does not offer NFT collection discovery on this chain.',
            'discovery_globally_disabled' => 'On-chain discovery is switched off for this environment.',
            'historical_backfill_disabled' => 'The first full scan of a chain is switched off for this environment.',
            'chain_not_ready'            => 'This chain is not ready to scan yet.',
            'chain_unknown'              => 'That chain could not be found.',
            'already_active'             => 'A scan is already running for this chain.',
            'contention'                 => 'Another administrator started a scan at the same moment. Try again.',
            'operator_unresolved'        => 'Your administrator account could not be confirmed for this action.',
            'max_attempts_exhausted'     => 'This scan was retried the maximum number of times and stopped.',
            'execution_failed'           => 'The scan could not finish. Nothing was changed.',
            'read_unavailable'           => 'The scan status could not be read just now.',
            'terminal_write_unconfirmed' => 'The result could not be confirmed, so it is being re-checked automatically.',
            'queue_write_failed'         => 'The request could not be recorded. Nothing was started.',
            'audit_uncommitted'          => 'The request could not be recorded safely, so it was not started.',
            'unsupported_request'        => 'That request is not supported.',
        ];
    }

    /** Operator-facing wording for each run state. */
    public static function statusCopy(string $status): string
    {
        return match ($status) {
            DiscoveryRunStatus::QUEUED    => 'Waiting to start',
            DiscoveryRunStatus::RUNNING   => 'Scanning now',
            DiscoveryRunStatus::SUCCEEDED => 'Finished',
            DiscoveryRunStatus::FAILED    => 'Did not finish',
            DiscoveryRunStatus::CANCELLED => 'Withdrawn',
            default                       => 'Unknown',
        };
    }

    /**
     * Render one chain's panel.
     *
     * @param object $chain     a ChainRow
     * @param bool   $scannable whether the chain+driver are enabled
     * @param string $whyNot    bounded reason code when not scannable
     */
    public static function render(object $chain, bool $scannable, string $whyNot = ''): void
    {
        $chainId   = (int) ($chain->id ?? 0);
        $chainName = (string) ($chain->name ?? $chain->slug ?? 'Unknown chain');

        if ($chainId <= 0) {
            return;
        }

        $status = DiscoveryRunStatusReader::forChain($chainId);
        $ok     = ($status['ok'] ?? false) === true;

        echo '<div class="bcc-discovery-scan card" style="padding:12px;margin:12px 0;max-width:none;">';

        // The chain is named in the heading AND in the button, so the control
        // is unambiguous when several panels are on one screen.
        printf(
            '<h3 style="margin-top:0;">%s <span style="font-weight:400;color:#646970;">— on-chain discovery</span></h3>',
            esc_html($chainName)
        );

        if (!$ok) {
            echo '<p>' . esc_html__('Discovery status is unavailable for this chain right now.', 'bcc-trust') . '</p>';
            echo '</div>';
            return;
        }

        self::renderCurrent($status);
        self::renderHistory($status);
        self::renderControl($chainId, $chainName, $scannable, $whyNot, $status);

        echo '</div>';
    }

    /** @param array<string, mixed> $status */
    private static function renderCurrent(array $status): void
    {
        $current = is_array($status['current'] ?? null) ? $status['current'] : null;

        if ($current === null) {
            echo '<p><strong>' . esc_html__('No scan is running.', 'bcc-trust') . '</strong></p>';
            return;
        }

        $state = (string) ($current['status'] ?? '');

        echo '<p><strong>' . esc_html(self::statusCopy($state)) . '</strong>';

        // ⚠ pickup_overdue is INFORMATION, not a failure. A queued run on an
        // install whose cron is disabled or externally driven is waiting, not
        // broken — so it is worded as a delay, never as an error.
        if (($current['pickup_overdue'] ?? false) === true) {
            echo ' — ' . esc_html__(
                'this has been waiting longer than usual. It has not failed; scheduled tasks may be running behind.',
                'bcc-trust'
            );
        }

        echo '</p>';

        self::renderCounts($current);
    }

    /**
     * Bounded progress counts.
     *
     * ⚠ Zero found is a SUCCESSFUL zero. The copy says so explicitly, because
     * "0 collections" next to a green tick is the single easiest thing for an
     * operator to misread as a failure.
     *
     * @param array<string, mixed> $run
     */
    private static function renderCounts(array $run): void
    {
        $state = (string) ($run['status'] ?? '');

        if (!in_array($state, [DiscoveryRunStatus::RUNNING, DiscoveryRunStatus::SUCCEEDED, DiscoveryRunStatus::FAILED], true)) {
            return;
        }

        // ⚠ The read model NESTS the counts under `counts`. Reading them off
        // the top level silently yields 0 for everything, which would render
        // a successful scan as "found nothing" on every single run.
        $counts  = is_array($run['counts'] ?? null) ? $run['counts'] : [];
        $emitted = (int) ($counts['collections_emitted'] ?? 0);
        $denied  = (int) ($counts['collections_denied'] ?? 0);
        $seen    = (int) ($counts['contracts_seen'] ?? 0);

        echo '<ul style="margin:4px 0 8px 18px;">';

        if ($state === DiscoveryRunStatus::SUCCEEDED && $emitted === 0) {
            echo '<li>' . esc_html__(
                'Checked successfully — nothing new was found. That is a normal result, not an error.',
                'bcc-trust'
            ) . '</li>';
        } else {
            printf(
                '<li>%s</li>',
                esc_html(sprintf(
                    /* translators: 1: new collections, 2: contracts examined */
                    __('Found %1$d new collection(s) from %2$d contract(s) examined.', 'bcc-trust'),
                    $emitted,
                    $seen
                ))
            );
        }

        if ($denied > 0) {
            printf(
                '<li>%s</li>',
                esc_html(sprintf(
                    __('%d were skipped by your block rules.', 'bcc-trust'),
                    $denied
                ))
            );
        }

        // ⚠ A budget stop is a SUCCESS with more work left — materially
        // different from a failure, and the operator must be able to tell.
        if (($run['partial'] ?? false) === true) {
            echo '<li>' . esc_html__(
                'This scan stopped at its safety limit before reaching the end. What it found is real; run it again to continue.',
                'bcc-trust'
            ) . '</li>';
        }

        if (($run['audit_degraded'] ?? false) === true) {
            echo '<li>' . esc_html__(
                'The result is recorded, but its secondary log entry could not be written.',
                'bcc-trust'
            ) . '</li>';
        }

        echo '</ul>';
    }

    /** @param array<string, mixed> $status */
    private static function renderHistory(array $status): void
    {
        $succeeded = is_array($status['last_succeeded'] ?? null) ? $status['last_succeeded'] : null;
        $failed    = is_array($status['last_failed'] ?? null) ? $status['last_failed'] : null;

        echo '<p style="color:#646970;">';

        if ($succeeded === null && $failed === null) {
            echo esc_html__('This chain has never been scanned.', 'bcc-trust');
        } else {
            if ($succeeded !== null) {
                printf(
                    '%s<br>',
                    esc_html(sprintf(
                        __('Last successful scan: %s.', 'bcc-trust'),
                        (string) ($succeeded['finished_at'] ?? '—')
                    ))
                );
            }
            if ($failed !== null) {
                printf(
                    '%s',
                    esc_html(sprintf(
                        __('Last unsuccessful scan: %1$s (%2$s).', 'bcc-trust'),
                        (string) ($failed['finished_at'] ?? '—'),
                        self::humanReason((string) ($failed['error_code'] ?? ''))
                    ))
                );
            }
        }

        echo '</p>';
    }

    /**
     * ⚠ Never echoes an unknown code. An internal identifier in front of an
     * operator is noise at best and a leak at worst.
     */
    public static function humanReason(string $code): string
    {
        $copy = self::reasonCopy();

        return $copy[$code] ?? __('an unexpected problem', 'bcc-trust');
    }

    /**
     * The control itself.
     *
     * @param array<string, mixed> $status
     */
    private static function renderControl(
        int $chainId,
        string $chainName,
        bool $scannable,
        string $whyNot,
        array $status
    ): void {
        $current  = is_array($status['current'] ?? null) ? $status['current'] : null;
        $hasActive = $current !== null;

        if (!$scannable) {
            // ⚠ Disabled, WITH the reason. A greyed-out button that does not
            // say why is a support ticket.
            printf(
                '<p><button type="button" class="button" disabled aria-disabled="true">%s</button></p>',
                esc_html__('Scan On-Chain for Easy Discovery', 'bcc-trust')
            );
            printf(
                '<p class="description">%s</p>',
                esc_html(self::humanReason($whyNot !== '' ? $whyNot : 'discovery_disabled'))
            );

            return;
        }

        if ($hasActive) {
            printf(
                '<p><button type="button" class="button" disabled aria-disabled="true">%s</button> <span class="description">%s</span></p>',
                esc_html__('Scan On-Chain for Easy Discovery', 'bcc-trust'),
                esc_html__('A scan is already running for this chain.', 'bcc-trust')
            );

            self::renderCancel($chainId, $current);

            return;
        }

        $action = DiscoveryScanActions::ACTION_REQUEST;

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
        printf('<input type="hidden" name="chain_id" value="%d">', $chainId);
        wp_nonce_field(DiscoveryScanActions::nonceAction($action, $chainId));

        // ⚠ Duplicate-click protection. `uq_active` already makes a second
        // active run impossible at the database, so this is purely about not
        // making the operator wonder whether their first click registered.
        printf(
            '<p><button type="submit" class="button button-primary" '
            . 'onclick="this.disabled=true;this.form.submit();" '
            . 'aria-label="%s">%s</button></p>',
            esc_attr(sprintf(
                /* translators: %s: chain name */
                __('Scan %s on-chain for easy discovery', 'bcc-trust'),
                $chainName
            )),
            esc_html__('Scan On-Chain for Easy Discovery', 'bcc-trust')
        );

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'Looks for NFT collections on this chain that BCC does not know about yet. '
                . 'Anything found is added for your review — nothing is verified, published '
                . 'or given a community automatically.',
                'bcc-trust'
            )
        );

        echo '</form>';
    }

    /** @param array<string, mixed> $current */
    private static function renderCancel(int $chainId, array $current): void
    {
        $state = (string) ($current['status'] ?? '');

        // ⚠ The browser only ever sees `run_uuid`, the opaque public handle.
        // The integer id stays inside the backend; the action resolves the
        // uuid back to a row and the service still re-checks the operator, so
        // knowing a uuid is a NAME, never a capability.
        $runUuid = (string) ($current['run_uuid'] ?? '');

        // ⚠ Withdraw is offered ONLY while the run has not started. A running
        // scan cannot be un-run, and a button implying otherwise would be a
        // lie about what the system can do.
        if ($state !== DiscoveryRunStatus::QUEUED || $runUuid === '') {
            return;
        }

        $action = DiscoveryScanActions::ACTION_CANCEL;

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
        printf('<input type="hidden" name="chain_id" value="%d">', $chainId);
        printf('<input type="hidden" name="run_uuid" value="%s">', esc_attr($runUuid));
        wp_nonce_field(DiscoveryScanActions::nonceAction($action, $chainId));

        printf(
            '<p><button type="submit" class="button" onclick="return confirm(%s);">%s</button></p>',
            AdminActionSupport::confirmLiteral(
                __('Withdraw this queued scan? It has not started yet.', 'bcc-trust')
            ),
            esc_html__('Withdraw this scan', 'bcc-trust')
        );

        echo '</form>';
    }
}
