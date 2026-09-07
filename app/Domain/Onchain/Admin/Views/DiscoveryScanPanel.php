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
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use BCC\Trust\Onchain\Services\DiscoveryScanSession;
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
    public static function statusCopy(
        string $status,
        string $scanComplete = DiscoveryScanProgress::UNKNOWN,
        bool $sessionActive = false,
        bool $sessionStop = false
    ): string {
        // ── ⚠ THE STANDALONE WORD `Finished` IS BANNED WHILE WORK REMAINS ──
        //
        // It is the largest text in the panel and the first thing an operator
        // reads. On 2026-09-04 it sat above a correct sentence saying 730
        // families still needed review, and the pair reads as "the scan is
        // over". The detailed line does contradict it, but a heading that
        // needs its own correction two lines down is a bad heading.
        //
        // `scan_complete` is the authority — the SAME derived answer the
        // progress sentence uses — so the heading and the sentence can never
        // disagree.
        if ($status === DiscoveryRunStatus::SUCCEEDED && $scanComplete !== DiscoveryScanProgress::YES) {
            return $sessionStop ? 'Session finished' : 'Pass finished';
        }

        // A queued run that has already done work is not "waiting to start" —
        // it is mid-session, between two protected batches.
        if ($status === DiscoveryRunStatus::QUEUED && $sessionActive) {
            return 'Scanning in batches';
        }

        return match ($status) {
            DiscoveryRunStatus::QUEUED    => 'Waiting to start',
            DiscoveryRunStatus::RUNNING   => 'Scanning now',
            DiscoveryRunStatus::SUCCEEDED => 'Scan complete',
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

        // ⚠ READ ONCE, PASS DOWN. The heading, the progress sentence, the
        // session outcome and the control all have to agree, and the only
        // way to guarantee that is for all four to read the same snapshot.
        // Calling forChain() per section would also put four identical
        // queries on one page render.
        $progress = DiscoveryScanProgress::forChain($chainId);

        self::renderCurrent($status, $progress);

        // ── ⚠ THE ONE SESSION-SCOPED NUMBER ON THIS SCREEN (PR 7.4) ──────
        //
        // `$progress` is derived from the family queue and describes the
        // CHAIN — it cannot attribute anything to a session. The run ledger
        // can: `collections_emitted` accumulates across every chunk of the
        // session, because both `markSucceeded()` and `releaseForNextChunk()`
        // write `col = col + n`.
        //
        // ⚠ NULL WHEN THERE IS NO RUN TO SPEAK FOR. A chain that has never
        // been scanned must not be told "this session added no new collection
        // record" — there was no session. Do not coalesce this to 0.
        $sessionEmitted = self::sessionEmitted($status);

        // ── PR 7.2: chain-scan completeness, kept apart from pass outcome ──
        //
        // ⚠ `renderCurrent()` above describes ONE RUN — "Finished", a stop
        // reason, its counts. The Cosmos Hub canary proved that is not
        // enough on its own: a run that was genuinely `succeeded` /
        // `partial = 0` / `pass_completed` with 0 collections had examined
        // 5 of 737 families, and every word available to describe it
        // pointed at a finished scan. This line is the other half of the
        // sentence, derived from the family queue rather than the run row.
        self::renderProgress($progress, $sessionEmitted);

        $current = is_array($status['current'] ?? null) ? $status['current'] : [];
        self::renderSessionOutcome($current, $progress);

        self::renderHistory($status);
        self::renderControl($chainId, $chainName, $scannable, $whyNot, $status, $progress);

        echo '</div>';
    }

    /**
     * The session's cumulative collection-record count, or null.
     *
     * ⚠ THE READ MODEL NESTS COUNTS UNDER `counts`, and reading them off the
     * top level yields 0 for everything — which here would not be a wrong
     * number but a wrong SENTENCE, silently turning "we cannot say" into
     * "your session added nothing".
     *
     * ⚠ `current` is the ACTIVE run, or — deliberately, per
     * {@see DiscoveryRunStatusReader} — the most recent one when none is
     * active. Either way it is the session an operator is looking at, which
     * is exactly the one the summary should speak for.
     *
     * @param array<string, mixed> $status a {@see DiscoveryRunStatusReader::forChain()} snapshot
     */
    private static function sessionEmitted(array $status): ?int
    {
        $current = is_array($status['current'] ?? null) ? $status['current'] : null;

        if ($current === null) {
            return null;
        }

        $counts = is_array($current['counts'] ?? null) ? $current['counts'] : null;

        if ($counts === null || !isset($counts['collections_emitted'])) {
            return null;
        }

        return max(0, (int) $counts['collections_emitted']);
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $progress
     */
    private static function renderCurrent(array $status, array $progress): void
    {
        $current = is_array($status['current'] ?? null) ? $status['current'] : null;

        if ($current === null) {
            echo '<p><strong>' . esc_html__('No scan is running.', 'bcc-trust') . '</strong></p>';
            return;
        }

        $state = (string) ($current['status'] ?? '');

        echo '<p><strong>' . esc_html(self::statusCopy(
            $state,
            (string) ($progress['scan_complete'] ?? DiscoveryScanProgress::UNKNOWN),
            ($current['session_active'] ?? false) === true,
            ($current['session_stop'] ?? false) === true
        )) . '</strong>';

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

        self::renderSession($current);
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

        // ⚠ ONE CHUNK IS A PASS; MANY CHUNKS ARE A SESSION (PR 7.4). These
        // counts are cumulative across every chunk the row recorded, so
        // calling a 25-chunk run "this pass" understates what the numbers
        // cover — and it is the same conflation that made the terminal audit
        // row report the last chunk as though it were the whole session.
        $chunks = max(0, (int) ($run['chunks_used'] ?? 0));

        if ($state === DiscoveryRunStatus::SUCCEEDED && $emitted === 0) {
            // ⚠ SCOPED TO THE PASS OR SESSION, EXPLICITLY (PR 7.3).
            // The old wording was "Checked successfully — nothing new was
            // found", which sat directly under the word "Finished" and read
            // as a statement about the CHAIN. On 2026-09-04 that pair
            // appeared while 730 families had never been looked at. Naming
            // the unit of work is the whole fix: it is true, and it cannot be
            // read as "this chain has no NFT collections".
            //
            // ⚠ "ADD A RECORD", NOT "CONFIRM A COLLECTION" (PR 7.4). This
            // branch is chosen on `collections_emitted === 0`, which proves
            // no ROW was stored and proves nothing at all about whether a
            // family was CONFIRMED — emission is separately bounded, so a
            // family confirmed in the last chunk can have its row written
            // later. The run ledger has no confirmed-family counter, so the
            // only honest sentence here is the one about records.
            echo '<li>' . esc_html(
                $chunks > 1
                    ? __('This session completed successfully. It did not add a new collection record.', 'bcc-trust')
                    : __('This pass completed successfully. It did not add a new collection record.', 'bcc-trust')
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
     * CHAIN-SCAN COMPLETENESS — the half the run row cannot express.
     *
     * ⚠ Read-only, and deliberately so: this renders on every page load and
     * on every status poll. It creates no run, writes no checkpoint and
     * records no audit row.
     */
    /**
     * @param array<string, mixed> $progress       a {@see DiscoveryScanProgress::forChain()} snapshot
     * @param int|null             $sessionEmitted see {@see sessionEmitted()} — null means "no session to speak for"
     */
    private static function renderProgress(array $progress, ?int $sessionEmitted = null): void
    {
        printf(
            '<p class="bcc-scan-progress description">%s</p>',
            esc_html(DiscoveryScanProgress::summarySentence($progress, $sessionEmitted))
        );

        // The bounded counts, only when they are actually known. Printing
        // "0 of 0" after a failed read would be the same lie in numbers
        // that the sentence above refuses to tell in words.
        if (($progress['ok'] ?? false) === true && $progress['total_families'] !== null) {
            printf(
                '<p class="description" style="margin-top:2px;">%s</p>',
                esc_html(sprintf(
                    /* translators: 1: checked, 2: total, 3: collection families */
                    __('%1$s of %2$s contract families checked · %3$s NFT collection families confirmed so far', 'bcc-trust'),
                    number_format_i18n((int) $progress['classified_families']),
                    number_format_i18n((int) $progress['total_families']),
                    number_format_i18n((int) $progress['collection_families'])
                ))
            );

            // ⚠ DELAYED AND EXHAUSTED ARE NAMED SEPARATELY, AND NEITHER IS A
            // NEGATIVE VERDICT. "We could not reach it" and "this is not an
            // NFT collection" are different facts that the classification
            // column stores identically; if the panel does not separate them,
            // nothing does.
            $delayed   = (int) ($progress['delayed_families'] ?? 0);
            $exhausted = (int) ($progress['exhausted_families'] ?? 0);

            if ($delayed > 0 || $exhausted > 0) {
                $parts = [];

                if ($delayed > 0) {
                    $parts[] = sprintf(
                        /* translators: %s: number of families */
                        _n(
                            '%s family is waiting to be retried later',
                            '%s families are waiting to be retried later',
                            $delayed,
                            'bcc-trust'
                        ),
                        number_format_i18n($delayed)
                    );
                }

                if ($exhausted > 0) {
                    $parts[] = sprintf(
                        /* translators: %s: number of families */
                        _n(
                            '%s family could not be resolved after repeated attempts — that is unresolved, not a negative result',
                            '%s families could not be resolved after repeated attempts — that is unresolved, not a negative result',
                            $exhausted,
                            'bcc-trust'
                        ),
                        number_format_i18n($exhausted)
                    );
                }

                printf(
                    '<p class="description" style="margin-top:2px;">%s</p>',
                    esc_html(implode(' · ', $parts))
                );
            }
        }
    }

    /**
     * What an administrator-authorized multi-chunk session is doing.
     *
     * ⚠ NO COMPLETION-TIME PROMISE. The number of chunks left is known; how
     * long a chunk takes is not, because it depends on a public LCD proxy.
     * Saying "about four minutes remaining" would be a guess presented as a
     * fact, and the first slow chunk would make it a wrong one.
     *
     * @param array<string, mixed> $current
     */
    private static function renderSession(array $current): void
    {
        $chunksUsed = (int) ($current['chunks_used'] ?? 0);
        $maxChunks  = (int) ($current['max_chunks'] ?? DiscoveryScanSession::MAX_CHUNKS);

        if (($current['session_active'] ?? false) !== true) {
            return;
        }

        echo '<p class="description">';
        echo esc_html__(
            'An administrator started this scan. It continues on its own in small protected batches, and stops by itself.',
            'bcc-trust'
        );
        echo '</p>';

        printf(
            '<p class="description" style="margin-top:2px;">%s</p>',
            esc_html(sprintf(
                /* translators: 1: batches done, 2: batches authorised */
                __('Batch %1$s of up to %2$s in this session.', 'bcc-trust'),
                number_format_i18n($chunksUsed),
                number_format_i18n($maxChunks)
            ))
        );
    }

    /**
     * Why a session stopped, when it stopped short of finishing the chain.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $progress
     */
    private static function renderSessionOutcome(array $current, array $progress): void
    {
        if ((string) ($current['status'] ?? '') !== DiscoveryRunStatus::SUCCEEDED) {
            return;
        }

        if (($progress['scan_complete'] ?? '') === DiscoveryScanProgress::YES) {
            return;
        }

        $sentence = DiscoveryScanSession::stopSentence((string) ($current['stop_reason'] ?? ''));

        if ($sentence === '') {
            return;
        }

        printf('<p class="description">%s</p>', esc_html($sentence));
    }

    /**
     * The control itself.
     *
     * @param array<string, mixed> $status
     * @param array<string, mixed> $progress a {@see DiscoveryScanProgress::forChain()} snapshot
     */
    private static function renderControl(
        int $chainId,
        string $chainName,
        bool $scannable,
        string $whyNot,
        array $status,
        array $progress
    ): void {
        $current = is_array($status['current'] ?? null) ? $status['current'] : null;

        // ⚠ `current` IS NOT "a run in flight". DiscoveryRunStatusReader
        // deliberately falls back to the MOST RECENT run once none is
        // active, so a finished pass does not read as "your scan vanished" —
        // its own comment says exactly that. Treating the presence of that
        // row as liveness is how a `succeeded` run from days ago disabled
        // this button under the words "A scan is already running", and it is
        // the same mistake PR 7.2 fixed one paragraph higher: a completed
        // pass described as something it is not.
        //
        // ⚠ It also made `Continue scan` UNREACHABLE. The label below lives
        // past this branch, so once a chain had ever been scanned the panel
        // could never offer to continue — the exact state the canary left.
        //
        // Liveness is the run's STATUS, and `DiscoveryRunStatus::isTerminal()`
        // is already the authority DiscoveryRunService and this reader's own
        // `retry_allowed` write against. `retry_allowed` is deliberately NOT
        // reused: it excludes `cancelled`, which is terminal and must offer a
        // fresh scan. An unknown status reads as NON-terminal, so a token
        // from a newer build keeps the button disabled rather than opening a
        // second run beside a possibly-live one.
        $hasActive = $current !== null
            && !DiscoveryRunStatus::isTerminal((string) ($current['status'] ?? ''));

        // ⚠ The button LABEL is a claim about what pressing it does. When
        // classification work remains, the next pass RESUMES from the
        // pending queue — enumeration is not restarted — so the honest word
        // is "Continue", not "Start over". `$progress` is the snapshot
        // render() already read, so the label cannot disagree with the
        // sentence above it.

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
            // ⚠ A SESSION BETWEEN BATCHES IS NOT "already running" IN THE
            // SENSE AN OPERATOR HEARS IT. It is queued, it will resume by
            // itself, and it can be stopped — so it gets its own sentence
            // and the Stop control below, rather than the flat refusal that
            // is correct for a chunk actually mid-flight.
            printf(
                '<p><button type="button" class="button" disabled aria-disabled="true">%s</button> <span class="description">%s</span></p>',
                esc_html__('Scan On-Chain for Easy Discovery', 'bcc-trust'),
                ($current['session_active'] ?? false) === true
                    ? esc_html__(
                        'This scan is continuing in batches. It will stop on its own; you can stop it now with the button below.',
                        'bcc-trust'
                    )
                    : esc_html__('A scan is already running for this chain.', 'bcc-trust')
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
                /* translators: 1: action label, 2: chain name */
                __('%1$s — %2$s', 'bcc-trust'),
                DiscoveryScanProgress::actionLabel($progress),
                $chainName
            )),
            esc_html(DiscoveryScanProgress::actionLabel($progress))
        );

        $hint = DiscoveryScanProgress::actionHint($progress);
        if ($hint !== '') {
            printf('<p class="description" style="margin-top:-6px;">%s</p>', esc_html($hint));
        }

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

        // ⚠ Withdraw is offered ONLY while the run is QUEUED. A chunk that is
        // mid-flight cannot be un-run, and a button implying otherwise would
        // be a lie about what the system can do.
        //
        // ── PR 7.3: THIS IS ALSO HOW A SESSION IS CANCELLED ─────────────
        // Between chunks a session sits in exactly this state — `queued`
        // with a `next_retry_at` — so the existing withdraw action already
        // covers it and no new action was needed. `markCancelled()` clears
        // `active_marker`, and the Action Scheduler action that fires later
        // finds a row that is no longer `queued`, fails its claim, and
        // returns before any provider call. Work already committed stays
        // committed; only the NEXT chunk is prevented.
        //
        // The only thing that had to change is the wording: "it has not
        // started yet" is false once batches have run.
        if ($state !== DiscoveryRunStatus::QUEUED || $runUuid === '') {
            return;
        }

        $sessionActive = ($current['session_active'] ?? false) === true;
        $chunksUsed    = (int) ($current['chunks_used'] ?? 0);

        $confirm = $sessionActive
            ? sprintf(
                /* translators: %s: number of batches already completed */
                _n(
                    'Stop this scan? %s batch has already finished and its results are kept.',
                    'Stop this scan? %s batches have already finished and their results are kept.',
                    $chunksUsed,
                    'bcc-trust'
                ),
                number_format_i18n($chunksUsed)
            )
            : __('Withdraw this queued scan? It has not started yet.', 'bcc-trust');

        $label = $sessionActive
            ? __('Stop this scan', 'bcc-trust')
            : __('Withdraw this scan', 'bcc-trust');

        $action = DiscoveryScanActions::ACTION_CANCEL;

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
        printf('<input type="hidden" name="chain_id" value="%d">', $chainId);
        printf('<input type="hidden" name="run_uuid" value="%s">', esc_attr($runUuid));
        wp_nonce_field(DiscoveryScanActions::nonceAction($action, $chainId));

        printf(
            '<p><button type="submit" class="button" onclick="return confirm(%s);">%s</button></p>',
            AdminActionSupport::confirmLiteral($confirm),
            esc_html($label)
        );

        echo '</form>';
    }
}
