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

    <?php
    // ⚠⚠ THE RESULT OF A DESTRUCTIVE ACTION WAS NEVER SHOWN TO ANYBODY.
    // handleRingAction() redirects with ?ring_msg=…, and NOTHING in the
    // codebase rendered that parameter — a grep for it found only the places
    // that SET it. So an operator pressed "Suspend ALL 3 members", was
    // redirected, and saw nothing at all.
    //
    // That is bad on its own, and it is what hid the truncation bug: the
    // redirect said `suspended_1` for a three-member ring, every time, and
    // there was no surface on which to read it.
    //
    // Codes come from ModerationService::handleRingAction() plus the
    // refusal in the admin-post hook. Anything unrecognised is reported as
    // unrecognised rather than ignored.
    $ring_msg = isset( $_GET['ring_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ring_msg'] ) ) : '';
    if ( $ring_msg !== '' ) :
        $notice_class = 'notice-success';
        if ( preg_match( '/^invalidated_(\d+)$/', $ring_msg, $m ) ) {
            $notice_text = sprintf(
                /* translators: %d is a count of pages. */
                _n( 'Ring votes invalidated. %d page was rescored.', 'Ring votes invalidated. %d pages were rescored.', (int) $m[1], 'bcc-trust' ),
                (int) $m[1]
            );
        } elseif ( preg_match( '/^suspended_(\d+)$/', $ring_msg, $m ) ) {
            $notice_text  = sprintf(
                /* translators: %d is a count of users. */
                _n( '%d ring member was suspended.', '%d ring members were suspended.', (int) $m[1], 'bcc-trust' ),
                (int) $m[1]
            );
            // ⚠ Zero suspended is not a success. Every member failing is a
            // database problem, and it used to be indistinguishable from
            // having worked.
            if ( (int) $m[1] === 0 ) {
                $notice_class = 'notice-error';
                $notice_text  = __( 'No ring member was suspended. Every attempt failed — check the application log.', 'bcc-trust' );
            }
        } elseif ( $ring_msg === 'ring_not_found' ) {
            $notice_class = 'notice-error';
            $notice_text  = __( 'That ring could not be found in the current snapshot, so nothing was changed. The snapshot may have been refreshed since this page was loaded, or the ring may no longer be detected. Reload and try again.', 'bcc-trust' );
        } elseif ( $ring_msg === 'no_users' ) {
            $notice_class = 'notice-error';
            $notice_text  = __( 'That ring had no members to act on, so nothing was changed.', 'bcc-trust' );
        } elseif ( $ring_msg === 'unknown_action' ) {
            $notice_class = 'notice-error';
            $notice_text  = __( 'That ring action is not recognised, so nothing was changed.', 'bcc-trust' );
        } else {
            $notice_class = 'notice-warning';
            $notice_text  = __( 'A ring action returned a result this page does not recognise. Nothing can be confirmed about what it did — check the application log.', 'bcc-trust' );
        }
        ?>
        <div class="notice <?php echo esc_attr( $notice_class ); ?> inline" style="margin:0 0 16px 0;">
            <p><?php echo esc_html( $notice_text ); ?></p>
        </div>
    <?php endif; ?>

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
            // ⚠⚠ THE FORMS NO LONGER POST MEMBERSHIP AT ALL. They post a
            // FINGERPRINT of the ring, and the handler resolves the members
            // from the stored snapshot. Two reasons:
            //
            //   - the old hidden field was a COMMA-SEPARATED STRING, and the
            //     handler cast it with `(array)` — so absint() reduced
            //     "11,12,13" to 11 and "Suspend ALL 3 members" suspended
            //     exactly one, silently;
            //   - a destructive action should not take the set of accounts
            //     to suspend from the browser, even behind a capability
            //     check.
            //
            // $member_ids is therefore gone. A fingerprint that no longer
            // matches a detected ring refuses the action rather than falling
            // back to anything.
            // ⚠ `ring_id` and `mutual_votes` are GONE from this view.
            // normalise() emits neither, no detector ever produced them, and
            // the handler no longer reads a submitted ring_id — so the hidden
            // field was always the loop index and the "Mutual votes" line was
            // permanently dead. They are removed rather than left to look
            // like data that might arrive one day.
            $ring_kind    = ( $ring['kind'] ?? '' ) === 'endorsement_ring'
                ? __( 'Endorsement ring', 'bcc-trust' )
                : __( 'Vote ring', 'bcc-trust' );
            $members      = is_array( $ring['users'] ?? null ) ? $ring['users'] : [];
            $member_count = count( $members );
            $ring_fp      = \BCC\Trust\Core\Support\RingSnapshot::fingerprint( $members );
        ?>
        <div class="bcc-panel" style="border-left:5px solid #ff9800;margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3 style="margin:0 0 5px;">
                        <?php // ⚠⚠ THE KIND IS NAMED. The snapshot merges two
                              // detectors, so this panel can hold an
                              // ENDORSEMENT ring — under a heading that says
                              // "Vote Ring Detection", beside a button
                              // labelled "Invalidate Ring Votes". Calling
                              // every ring a vote ring is the same mislabelling
                              // this change just fixed in FraudDetector's
                              // signal text, and it became visible here for
                              // the first time because the snapshot now
                              // renders. ?>
                        <?php echo esc_html( $ring_kind ); ?> #<?php echo $ring_index + 1; ?>
                        <span style="font-size:13px;font-weight:normal;color:#666;">
                            — <?php echo $member_count; ?> members
                        </span>
                    </h3>
                    <?php if ( ! empty( $ring['detected_at'] ) ): ?>
                        <small style="color:#666;">Detected: <?php echo esc_html( $ring['detected_at'] ); ?></small>
                    <?php endif; ?>

                </div>

                <!-- ── Ring action buttons ── -->
                <div style="display:flex;gap:8px;flex-shrink:0;">

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('Invalidate all votes between members of this ring? This cannot be undone.');">
                        <?php wp_nonce_field( 'bcc_trust_ring_action' ); ?>
                        <input type="hidden" name="action"      value="bcc_trust_ring_action">
                        <input type="hidden" name="ring_action" value="invalidate_votes">
                        <input type="hidden" name="ring_fingerprint" value="<?php echo esc_attr( $ring_fp ); ?>">
                        <button type="submit" class="button" style="background:#ff9800;color:#fff;border-color:#e68900;">
                            🚫 Invalidate Ring Votes
                        </button>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('Suspend ALL <?php echo $member_count; ?> members of this ring?');">
                        <?php wp_nonce_field( 'bcc_trust_ring_action' ); ?>
                        <input type="hidden" name="action"      value="bcc_trust_ring_action">
                        <input type="hidden" name="ring_action" value="suspend_all">
                        <input type="hidden" name="ring_fingerprint" value="<?php echo esc_attr( $ring_fp ); ?>">
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
            <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Ring member', 'bcc-trust' ); ?></th>
                        <th><?php esc_html_e( 'Fraud score', 'bcc-trust' ); ?></th>
                        <th><?php esc_html_e( 'Risk', 'bcc-trust' ); ?></th>
                        <th><?php esc_html_e( 'Suspended', 'bcc-trust' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bcc-trust' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $members as $member_id ): ?>
                        <tr>
                            <td><strong>User #<?php echo intval( $member_id ); ?></strong></td>
                            <?php // ⚠⚠ "Not loaded" IS THE POINT, in all three
                                  // columns. The snapshot carries membership and
                                  // nothing else, so these three facts are not
                                  // available on this page.
                                  //
                                  // The columns are KEPT rather than removed, and
                                  // they say which it is: an operator looking for a
                                  // fraud score should learn that this page does not
                                  // have it, not find the column quietly missing and
                                  // have to work out why.
                                  //
                                  // What they must never do is what they did before:
                                  // `?? 0` and `?? 'unknown'` printed "0" and
                                  // "Unknown" in a coloured risk pill for every
                                  // member of a detected ring — a fabricated
                                  // measurement about a person, on the screen whose
                                  // buttons suspend them. "Unknown" in a pill reads
                                  // as a classification; "not loaded" cannot. ?>
                            <td><em style="color:#646970;"><?php esc_html_e( 'not loaded', 'bcc-trust' ); ?></em></td>
                            <td><em style="color:#646970;"><?php esc_html_e( 'not loaded', 'bcc-trust' ); ?></em></td>
                            <td><em style="color:#646970;"><?php esc_html_e( 'not loaded', 'bcc-trust' ); ?></em></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . intval( $member_id ) ) ); ?>" class="button button-small"><?php esc_html_e( 'Manage', 'bcc-trust' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin:6px 0 0;color:#646970;font-size:12px;">
                <?php esc_html_e( 'The ring snapshot records membership only, so fraud score, risk level and suspension state are not loaded on this page — "not loaded" is not a score of zero and not a risk assessment. Open a member to see their real values.', 'bcc-trust' ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
}