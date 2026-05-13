<?php
/**
 * Admin Dashboard — Ecosystem Tab (§O5 operator journal).
 *
 * Sparse, high-signal operator stewardship surface. Reads
 * AdminDashboardRepository::getEcosystemRollup(7) and renders one
 * section per signal class. NEVER public-facing; NEVER user-visible.
 *
 * Design discipline (from the audit doc this tab implements):
 *   - Trajectory over snapshot — sustained patterns matter, spikes don't.
 *   - Civic-register tone — "within band" / "monitor" / "investigate".
 *     No "trending up!" / no "engagement". No gamified copy.
 *   - Operator-only — no popularity ranks, no leaderboards, no DAU.
 *   - Honest about gaps — deferred V1 sections render an explicit
 *     "deferred — needs X" placeholder rather than fabricating data.
 *   - Stewardship not surveillance — the surface increases visibility
 *     into existing mechanics, doesn't introduce new authority.
 *
 * The civic test: an operator reads this tab once a week, takes ≤ 15
 * minutes, leaves with ONE intuition about whether the ecosystem is
 * stable, drifting, or distorting. If a section ever takes longer to
 * read than that, it's the wrong section.
 *
 * @package BCC\Trust\Core
 * @since   §O5 operator-journal pass (2026-05-13)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_ecosystem_tab() {
    $repo   = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();
    $days   = isset( $_GET['days'] ) ? max( 1, min( (int) $_GET['days'], 90 ) ) : 7;
    $rollup = $repo->getEcosystemRollup( $days );
    ?>

    <div class="bcc-panel" style="border-left:5px solid #607d8b;">
        <h2 style="margin-top:0;">Ecosystem — operator journal</h2>
        <p style="margin:0;color:#666;">
            Window: <strong>last <?php echo (int) $rollup['window_days']; ?> days</strong>
            · generated <?php echo esc_html( $rollup['generated_at'] ); ?> UTC
            · cached up to 5 minutes.
        </p>
        <p style="margin:8px 0 0;color:#666;font-size:13px;">
            Read this weekly. Notice <em>sustained</em> patterns; ignore single spikes.
            Healthy looks boring.
        </p>
        <div style="margin-top:10px;">
            <?php
            $windows = [ 7, 14, 30 ];
            foreach ( $windows as $w ):
                $url    = esc_url( admin_url( 'admin.php?page=bcc-trust-dashboard&tab=ecosystem&days=' . $w ) );
                $active = ( $rollup['window_days'] === $w );
            ?>
                <a href="<?php echo $url; ?>" class="button button-small"
                   <?php if ( $active ) echo 'style="background:#607d8b;color:#fff;border-color:#455a64;"'; ?>>
                    <?php echo (int) $w; ?>d
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    // ─────────────────────────────────────────────────────────────────
    // SECTION 1 — Slow-ring activity
    // ─────────────────────────────────────────────────────────────────
    $sr     = $rollup['slow_rings'];
    $byType = $sr['by_type'];
    $latest = $sr['latest_slow_ring'];
    ?>
    <div class="bcc-panel">
        <h3 style="margin-top:0;">◇ Slow-ring activity</h3>

        <?php if ( (int) $sr['total'] === 0 ): ?>
            <p style="color:#4caf50;margin:0;">
                <strong>No rings detected in window.</strong>
                Detector is running on the weekly cron; an empty week is the
                healthy default.
            </p>
        <?php else: ?>
            <table style="width:100%;max-width:520px;">
                <tbody>
                    <tr>
                        <td style="padding:4px 0;">Vote rings</td>
                        <td style="text-align:right;"><strong><?php echo (int) ( $byType['vote_ring'] ?? 0 ); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Endorsement rings (DFS cycle detection)</td>
                        <td style="text-align:right;"><strong><?php echo (int) ( $byType['endorsement_ring'] ?? 0 ); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Slow endorsement rings (Phase 3 soft-flag)</td>
                        <td style="text-align:right;"><strong><?php echo (int) ( $byType['slow_endorsement_ring'] ?? 0 ); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            <?php if ( $latest !== null ): ?>
                <p style="margin-top:10px;color:#666;font-size:13px;">
                    Latest slow-ring detection:
                    <?php echo esc_html( $latest['detected_at'] ?? '—' ); ?>
                    <?php if ( $latest['size'] !== null ): ?>
                        · size <?php echo (int) $latest['size']; ?>
                    <?php endif; ?>
                    <?php if ( $latest['strength'] !== null ): ?>
                        · strength <?php echo esc_html( (string) $latest['strength'] ); ?>
                    <?php endif; ?>
                    · <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-dashboard&tab=ml' ) ); ?>">review patterns</a>
                </p>
            <?php endif; ?>
            <p style="margin-top:10px;font-size:13px;">
                <strong>Operator action:</strong> soft-flag only. Each ring is stored
                for ops review; no automatic fraud-score penalty. Manually classify
                the first 20–30 detections to calibrate threshold.
            </p>
        <?php endif; ?>
    </div>

    <?php
    // ─────────────────────────────────────────────────────────────────
    // SECTION 2 — Panel-duty health
    // ─────────────────────────────────────────────────────────────────
    $pd            = $rollup['panel_duty'];
    $windowStatus  = $pd['window_status_counts'];
    $timeoutPct    = $pd['timeout_rate_pct'];
    $lopsided      = (int) $pd['lopsided_count'];
    $split         = (int) $pd['split_count'];
    $totalResolved = (int) $pd['resolved_in_window'];
    $totalOpened   = (int) $pd['opened_in_window'];

    // Trajectory framing for timeout rate.
    $timeoutLabel = '—';
    $timeoutColor = '#666';
    if ( $timeoutPct !== null ) {
        if ( $timeoutPct < 8.0 ) {
            $timeoutLabel = 'low';
            $timeoutColor = '#4caf50';
        } elseif ( $timeoutPct <= 25.0 ) {
            $timeoutLabel = 'within band';
            $timeoutColor = '#607d8b';
        } else {
            $timeoutLabel = 'investigate';
            $timeoutColor = '#ff9800';
        }
    }
    ?>
    <div class="bcc-panel">
        <h3 style="margin-top:0;">◇ Panel-duty health</h3>

        <?php if ( $totalOpened === 0 && $totalResolved === 0 ): ?>
            <p style="color:#666;margin:0;">No disputes in window — quiet floor.</p>
        <?php else: ?>
            <p style="margin:0 0 8px;">
                <strong><?php echo $totalOpened; ?></strong> opened ·
                <strong><?php echo $totalResolved; ?></strong> resolved ·
                <strong><?php echo (int) ( $windowStatus['reviewing'] ?? 0 ); ?></strong> in review
            </p>

            <table style="width:100%;max-width:520px;margin-bottom:10px;">
                <tbody>
                    <tr>
                        <td style="padding:4px 0;">Accepted</td>
                        <td style="text-align:right;"><strong><?php echo (int) ( $windowStatus['accepted'] ?? 0 ); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Rejected</td>
                        <td style="text-align:right;"><strong><?php echo (int) ( $windowStatus['rejected'] ?? 0 ); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Timeout (no quorum)</td>
                        <td style="text-align:right;"><strong><?php echo (int) ( $windowStatus['timeout_no_quorum'] ?? 0 ); ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <p style="margin:0 0 8px;">
                Timeout rate:
                <strong style="color:<?php echo esc_attr( $timeoutColor ); ?>;">
                    <?php echo $timeoutPct !== null ? esc_html( (string) $timeoutPct ) . '%' : '—'; ?>
                </strong>
                <span style="color:<?php echo esc_attr( $timeoutColor ); ?>;font-size:13px;">
                    (<?php echo esc_html( $timeoutLabel ); ?>)
                </span>
            </p>

            <?php if ( $totalResolved > 0 ): ?>
                <p style="margin:0;font-size:13px;color:#666;">
                    Decision spread on resolved disputes:
                    <strong><?php echo $lopsided; ?></strong> lopsided (≥3-vote margin)
                    · <strong><?php echo $split; ?></strong> split (≤2-vote margin).
                    Healthy panels produce a mix — predominantly-lopsided suggests
                    rubber-stamping; predominantly-split suggests panels lack
                    shared context.
                </p>
            <?php endif; ?>

            <p style="margin-top:10px;font-size:13px;color:#777;">
                Phase 2 (commit <code>bf35a4a</code>) added affinity overlay +
                outsider quota. Repeat-pairing detection + affinity-distribution
                are designed but not wired this pass; review the panel-create
                audit log entries directly.
            </p>
        <?php endif; ?>
    </div>

    <?php
    // ─────────────────────────────────────────────────────────────────
    // SECTION 3 — Tier movement
    // ─────────────────────────────────────────────────────────────────
    $tm    = $rollup['tier_movement'];
    $tiers = $tm['current_distribution'];
    ?>
    <div class="bcc-panel">
        <h3 style="margin-top:0;">◇ Page tier distribution (current snapshot)</h3>
        <table style="width:100%;max-width:520px;">
            <tbody>
                <?php
                $tier_rows = [
                    [ 'label' => 'Elite',   'value' => (int) ( $tiers['elite']   ?? 0 ), 'color' => '#4caf50' ],
                    [ 'label' => 'Trusted', 'value' => (int) ( $tiers['trusted'] ?? 0 ), 'color' => '#8bc34a' ],
                    [ 'label' => 'Neutral', 'value' => (int) ( $tiers['neutral'] ?? 0 ), 'color' => '#ffc107' ],
                    [ 'label' => 'Caution', 'value' => (int) ( $tiers['caution'] ?? 0 ), 'color' => '#ff9800' ],
                    [ 'label' => 'Risky',   'value' => (int) ( $tiers['risky']   ?? 0 ), 'color' => '#f44336' ],
                ];
                $total_pages = max( 1, array_sum( array_column( $tier_rows, 'value' ) ) );
                foreach ( $tier_rows as $r ):
                    $pct = (int) round( $r['value'] / $total_pages * 100 );
                ?>
                    <tr>
                        <td style="padding:3px 0;width:90px;"><?php echo esc_html( $r['label'] ); ?></td>
                        <td style="padding:3px 0;">
                            <div style="height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                <div style="width:<?php echo $pct; ?>%;height:100%;background:<?php echo esc_attr( $r['color'] ); ?>;"></div>
                            </div>
                        </td>
                        <td style="padding:3px 0 3px 8px;text-align:right;width:50px;"><strong><?php echo $r['value']; ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:8px;font-size:13px;color:#777;">
            Week-over-week tier movement deltas are deferred to a follow-up — they
            need a prior-period snapshot store. Current state shown above; track
            stability by reading this tab on a weekly cadence.
        </p>
    </div>

    <?php
    // ─────────────────────────────────────────────────────────────────
    // SECTION 4 — Watch-graph trajectory (OPERATOR-ONLY)
    // ─────────────────────────────────────────────────────────────────
    $wg          = $rollup['watch_graph'];
    $newPulls    = (int) $wg['new_pulls_in_window'];
    $topGainers  = $wg['top_gainers'];
    ?>
    <div class="bcc-panel">
        <h3 style="margin-top:0;">◇ Watch-graph activity</h3>
        <p style="margin:0;color:#777;font-size:12px;">
            Operator-only. NEVER shown publicly. Capped at 5. This list is
            <em>not</em> a leaderboard — it's a celebrity-gravity early-warning
            surface (Phase 4 audit constraint).
        </p>

        <?php if ( $newPulls === 0 ): ?>
            <p style="color:#666;margin:10px 0 0;">No new pulls in window — quiet floor.</p>
        <?php else: ?>
            <p style="margin:10px 0;">
                <strong><?php echo $newPulls; ?></strong> new Keep-Tabs actions in window.
            </p>

            <?php if ( ! empty( $topGainers ) ): ?>
                <p style="margin:0 0 6px;font-size:13px;">Top recipients this window:</p>
                <table style="width:100%;max-width:520px;">
                    <tbody>
                        <?php foreach ( $topGainers as $g ):
                            $display = $g['handle'] !== null ? '@' . $g['handle'] : 'user #' . $g['user_id'];
                            $flagCelebrity = $g['gained'] >= 25; // arbitrary tunable; revisit after bake.
                        ?>
                            <tr>
                                <td style="padding:3px 0;"><?php echo esc_html( $display ); ?></td>
                                <td style="padding:3px 0;text-align:right;">
                                    <strong>+<?php echo (int) $g['gained']; ?></strong>
                                    <?php if ( $flagCelebrity ): ?>
                                        <span style="color:#ff9800;font-size:11px;margin-left:6px;">monitor</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:8px;font-size:13px;color:#777;">
                    Notice if the same name appears across 4+ consecutive windows.
                    Sustained accumulation differentiates earned standing from
                    manufactured celebrity. Single big weeks are noise.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php
    // ─────────────────────────────────────────────────────────────────
    // SECTION 5 — Feed dominance
    // ─────────────────────────────────────────────────────────────────
    $fd       = $rollup['feed_dominance'];
    $totalP   = (int) $fd['total_posts'];
    $top10Pct = (float) $fd['top_10_share_pct'];
    $authors  = (int) $fd['distinct_authors'];
    $byKind   = $fd['by_kind'];

    $domColor = '#4caf50';
    $domLabel = 'green';
    if ( $top10Pct > 40.0 ) {
        $domColor = '#f44336';
        $domLabel = 'investigate — power-poster graph emerging';
    } elseif ( $top10Pct > 25.0 ) {
        $domColor = '#ff9800';
        $domLabel = 'monitor';
    }
    ?>
    <div class="bcc-panel">
        <h3 style="margin-top:0;">◇ Feed dominance</h3>
        <?php if ( $totalP === 0 ): ?>
            <p style="color:#666;margin:0;">No posts in window — quiet floor.</p>
        <?php else: ?>
            <p style="margin:0;">
                <strong><?php echo $totalP; ?></strong> posts ·
                <strong><?php echo $authors; ?></strong> distinct authors
            </p>
            <p style="margin:8px 0 0;">
                Top 10 authors produced
                <strong style="color:<?php echo esc_attr( $domColor ); ?>;">
                    <?php echo esc_html( (string) $top10Pct ); ?>%
                </strong>
                of posts
                <span style="color:<?php echo esc_attr( $domColor ); ?>;font-size:13px;">
                    (<?php echo esc_html( $domLabel ); ?>)
                </span>
            </p>

            <p style="margin:10px 0 6px;font-size:13px;color:#777;">By kind:</p>
            <table style="width:100%;max-width:520px;">
                <tbody>
                    <?php
                    $kindLabels = [
                        'peepso-activity-status' => 'Status',
                        'peepso-photo'           => 'Photo',
                        'peepso-gif'             => 'GIF',
                        'post'                   => 'Long-form (blog/review)',
                    ];
                    foreach ( $kindLabels as $kind => $label ):
                        $count = (int) ( $byKind[ $kind ] ?? 0 );
                    ?>
                        <tr>
                            <td style="padding:3px 0;"><?php echo esc_html( $label ); ?></td>
                            <td style="padding:3px 0;text-align:right;"><strong><?php echo $count; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php
    // ─────────────────────────────────────────────────────────────────
    // SECTION 6 — Deferred surfaces (honest about gaps)
    // ─────────────────────────────────────────────────────────────────
    ?>
    <div class="bcc-panel" style="border-left:5px solid #cfd8dc;">
        <h3 style="margin-top:0;">Surfaces deferred to follow-up passes</h3>
        <table style="width:100%;max-width:760px;">
            <tbody>
                <tr>
                    <td style="padding:6px 0;width:200px;"><strong>Chain activity</strong></td>
                    <td style="padding:6px 0;color:#666;">
                        Needs page→chain bridge (entity_category → chain_id resolver).
                        Once wired, this surfaces per-chain dispute counts + acceptance rates.
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 0;"><strong>Attention-gravity WoW deltas</strong></td>
                    <td style="padding:6px 0;color:#666;">
                        Needs prior-period snapshot store. Once wired, this surfaces
                        watch-concentration trajectory and the "same name in top-5 for
                        4+ consecutive weeks" celebrity-gravity notice.
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 0;"><strong>EXTERNAL slot fire rate</strong></td>
                    <td style="padding:6px 0;color:#666;">
                        Needs HighlightStrip fetch-rate instrumentation in the resolver.
                        Once wired, surfaces what fraction of HighlightStrip fetches
                        actually returned an EXTERNAL highlight + dismissal rate by category.
                    </td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top:10px;font-size:12px;color:#777;">
            These placeholders are intentional — the tab tells the truth about what
            is and isn't wired. Activating each is a one-line change in
            <code>AdminDashboardRepository::getEcosystemRollup</code> when the producer
            lands.
        </p>
    </div>

    <?php
    // ─────────────────────────────────────────────────────────────────
    // Operator reading-key footer
    // ─────────────────────────────────────────────────────────────────
    ?>
    <div class="bcc-panel" style="border-left:5px solid #455a64;background:#fafafa;">
        <h3 style="margin-top:0;">Reading-key</h3>
        <p style="margin:0;font-size:13px;color:#444;">
            <strong>Healthy looks boring.</strong> The civic test: an operator
            should be able to read this tab in 10–15 minutes once a week, leave
            with one intuition about whether the ecosystem is stable, drifting,
            or distorting, and act on at most one signal.
        </p>
        <ul style="margin:8px 0 0;font-size:13px;color:#555;line-height:1.5;">
            <li><strong>Sustained patterns</strong> matter; individual spikes do not.</li>
            <li><strong>Trajectory</strong> beats <strong>snapshot</strong> — notice 4+ week trends.</li>
            <li><strong>Soft-flag rings</strong> are for review, not automatic punishment.</li>
            <li><strong>Watch-gainer list</strong> is operator-only and is <em>never</em> a leaderboard.</li>
            <li>An <strong>empty section</strong> is usually the healthy outcome.</li>
        </ul>
    </div>

    <?php
}
