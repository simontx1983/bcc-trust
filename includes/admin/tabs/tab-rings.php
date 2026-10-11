<?php
/**
 * Admin Dashboard — Vote Rings Tab
 *
 * @package BCC\Trust\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_rings_tab() {
    $data       = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getRingsData();
    $rings      = $data['rings'];
    $thresholds = $data['thresholds'];
    // `?? true` keeps a pre-verdict build rendering exactly as before.
    $rings_available = ( $data['available'] ?? true ) === true;
    ?>

    <div class="bcc-panel" style="border-left:5px solid #f44336;">
        <h2 style="margin-top:0;">Vote Ring Detection</h2>
        <p>
            Rings are clusters of users suspected of coordinated voting.
            Min cluster size: <strong><?php echo $thresholds['min_size']; ?></strong>.
            Min mutual votes: <strong><?php echo $thresholds['min_mutual']; ?></strong>.
        </p>
        <p>
            <?php if ( $rings_available ): ?>
                <strong><?php echo count( $rings ); ?></strong> suspicious cluster(s) detected.
                <?php // ⚠ A ring list without its age is not readable. The
                      // producer runs once a day, so "0 clusters" an hour
                      // after a run and "0 clusters" twenty-three hours
                      // after one are different facts about the platform. ?>
                <?php if ( isset( $data['age_seconds'] ) && $data['age_seconds'] !== null ): ?>
                    <span style="color:#646970;">
                        <?php printf(
                            /* translators: %s is a human-readable duration, e.g. "3 hours". */
                            esc_html__( 'Snapshot taken %s ago.', 'bcc-trust' ),
                            esc_html( human_time_diff( time() - (int) $data['age_seconds'], time() ) )
                        ); ?>
                    </span>
                <?php endif; ?>
            <?php else: ?>
                <?php // A count of 0 here would be a measurement nobody took. ?>
                <strong>—</strong> <?php esc_html_e( 'detection has not produced a snapshot yet.', 'bcc-trust' ); ?>
            <?php endif; ?>
        </p>
    </div>

    <?php if ( ! $rings_available ): ?>
        <?php // ⚠ The tick is the problem. "✓ No vote rings detected" is the
              // most confident wrong answer on this dashboard: nothing here
              // reads the database, so an absent snapshot means detection has
              // not run, which is not the same fact. Deliberately NOT styled
              // as an error — on a fresh install this is the correct state. ?>
        <div class="bcc-panel">
            <p style="color:#646970;text-align:center;padding:20px;">
                <strong><?php esc_html_e( 'No ring-detection snapshot yet.', 'bcc-trust' ); ?></strong><br>
                <span style="font-size:12px;">
                    <?php esc_html_e( 'Ring detection runs once a day and stores its result for this page to read. Until it has run there is nothing to show — which is not the same as finding no rings.', 'bcc-trust' ); ?>
                </span>
            </p>
        </div>
    <?php elseif ( empty( $rings ) ): ?>
        <div class="bcc-panel">
            <p style="color:#4caf50;text-align:center;padding:20px;">
                <strong>✓ No vote rings detected.</strong>
            </p>
        </div>
    <?php else: ?>
        <?php foreach ( $rings as $ring_index => $ring ):
            // ⚠⚠ THE PRODUCER EMITS `users`, A FLAT LIST OF INTS. This read
            // `$ring['members']` and then array_column(..., 'user_id') over
            // it, which is a different key AND a different shape — a list of
            // rows rather than a list of ids. Both detectors have always
            // emitted `users`, so this never matched.
            //
            // It was invisible because nothing persisted a snapshot, so this
            // branch never rendered. Measured against a stored payload, every
            // ring printed "— 0 members" with an empty id list, which is bad
            // enough on its own — but $member_ids also feeds the hidden
            // `user_ids` field of the two DESTRUCTIVE forms below
            // (invalidate-ring-votes and suspend-all). Persisting the
            // snapshot without this fix would arm both buttons with no
            // targets, under a confirm dialog reading "Suspend ALL 0
            // members". That is why the shape fix ships WITH the storage
            // change and not after it.
            $ring_id      = $ring['ring_id'] ?? $ring_index;
            $members      = is_array( $ring['users'] ?? null ) ? $ring['users'] : [];
            $member_ids   = implode( ',', array_map( 'intval', $members ) );
            $member_count = count( $members );
        ?>
        <div class="bcc-panel" style="border-left:5px solid #ff9800;margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3 style="margin:0 0 5px;">
                        Ring #<?php echo $ring_index + 1; ?>
                        <span style="font-size:13px;font-weight:normal;color:#666;">
                            — <?php echo $member_count; ?> members
                        </span>
                    </h3>
                    <?php if ( ! empty( $ring['detected_at'] ) ): ?>
                        <small style="color:#666;">Detected: <?php echo esc_html( $ring['detected_at'] ); ?></small>
                    <?php endif; ?>
                    <?php if ( ! empty( $ring['mutual_votes'] ) ): ?>
                        <br><small style="color:#666;">Mutual votes: <?php echo intval($ring['mutual_votes']); ?></small>
                    <?php endif; ?>
                </div>

                <!-- ── Ring action buttons ── -->
                <div style="display:flex;gap:8px;flex-shrink:0;">

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('Invalidate all votes between members of this ring? This cannot be undone.');">
                        <?php wp_nonce_field( 'bcc_trust_ring_action' ); ?>
                        <input type="hidden" name="action"      value="bcc_trust_ring_action">
                        <input type="hidden" name="ring_action" value="invalidate_votes">
                        <input type="hidden" name="user_ids"    value="<?php echo esc_attr( $member_ids ); ?>">
                        <input type="hidden" name="ring_id"     value="<?php echo esc_attr( $ring_id ); ?>">
                        <button type="submit" class="button" style="background:#ff9800;color:#fff;border-color:#e68900;">
                            🚫 Invalidate Ring Votes
                        </button>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('Suspend ALL <?php echo $member_count; ?> members of this ring?');">
                        <?php wp_nonce_field( 'bcc_trust_ring_action' ); ?>
                        <input type="hidden" name="action"      value="bcc_trust_ring_action">
                        <input type="hidden" name="ring_action" value="suspend_all">
                        <input type="hidden" name="user_ids"    value="<?php echo esc_attr( $member_ids ); ?>">
                        <input type="hidden" name="ring_id"     value="<?php echo esc_attr( $ring_id ); ?>">
                        <input type="text"   name="reason"      placeholder="Suspension reason…" style="width:160px;" required>
                        <button type="submit" class="button" style="background:#f44336;color:#fff;border-color:#c62828;">
                            🔴 Suspend All Members
                        </button>
                    </form>

                </div>
            </div>

            <!-- ── Member table ── -->
            <?php // ⚠⚠ THIS TABLE USED TO RENDER FIELDS THE SNAPSHOT HAS
                  // NEVER CARRIED. It treated each member as an enriched ROW
                  // with display_name / fraud_score / risk_level /
                  // mutual_vote_count / is_suspended, while both detectors
                  // emit a flat list of integer USER IDS. With a snapshot
                  // actually stored, every one of those accesses raised
                  // "Trying to access array offset on value of type int" —
                  // 21 diagnostics across three rings — and the `?? 0` /
                  // `?? 'unknown'` defaults would otherwise have printed
                  // "Fraud Score 0, Risk Unknown" for every member in a
                  // detected ring. That is a fabricated measurement about a
                  // user, on the screen whose buttons suspend them.
                  //
                  // So the table now shows what the snapshot ACTUALLY holds:
                  // who is in the ring, and a way to go look at them. The
                  // five unpopulated columns are gone rather than defaulted.
                  // Nothing is lost that ever worked — this branch could not
                  // render at all before the snapshot was persisted.
                  //
                  // Enriching the ids into real fraud scores is worth doing
                  // and is NOT done here: it is a per-member repository read
                  // inside a loop, so it needs its own change with its own
                  // query budget rather than being slipped into a storage
                  // fix. ?>
            <?php if ( ! empty( $members ) ): ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:12px;max-width:520px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Ring member', 'bcc-trust' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bcc-trust' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $members as $member_id ): ?>
                        <tr>
                            <td><strong>User #<?php echo intval( $member_id ); ?></strong></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . intval( $member_id ) ) ); ?>" class="button button-small"><?php esc_html_e( 'Manage', 'bcc-trust' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin:6px 0 0;color:#646970;font-size:12px;">
                <?php esc_html_e( 'The snapshot records ring membership only. Open a member to see their fraud score, risk level and suspension state.', 'bcc-trust' ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
}