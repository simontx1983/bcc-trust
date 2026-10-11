<?php
/**
 * Admin Dashboard — Push Delivery Stats tab.
 *
 * Read-only snapshot of the V2 Phase 1 §P1.F counters. Two compact
 * tables: current UTC hour + previous UTC hour. Each row is an event
 * type; each column is an outcome (attempt / success / tombstone /
 * failure). Counters are reset every 2 hours per PushMetrics::TTL —
 * this tab is intentionally a "what's happening right now" lens, not
 * a time-series.
 *
 * If the success rate (success ÷ attempt) drops below 80% inside an
 * hour, the row is flagged in red so admins notice the regression
 * without staring at percentages.
 *
 * @package BCC_Trust
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_render_push_tab(): void {
    $now           = time();
    $prevHour      = $now - HOUR_IN_SECONDS;
    // ⚠ The admin-specific reader, which also reports whether any counter
    // was RECORDED. readBucket() returns 0 for an absent bucket and 0 for a
    // bucket that genuinely counted nothing, so this tab used to render
    // "0 sends" for an hour in which nothing was recorded at all.
    $currentRead   = \BCC\Trust\Core\Support\PushMetrics::readSnapshotForAdmin($now);
    $prevRead      = \BCC\Trust\Core\Support\PushMetrics::readSnapshotForAdmin($prevHour);
    $currentSnap   = $currentRead['buckets'];
    $prevSnap      = $prevRead['buckets'];
    // `?? true` so a build predating the reader renders exactly as before.
    $current_available = ( $currentRead['available'] ?? true ) === true;
    $prev_available    = ( $prevRead['available'] ?? true ) === true;

    $vapidConfigured = defined('BCC_PUSH_VAPID_PUBLIC_KEY')
        && defined('BCC_PUSH_VAPID_PRIVATE_KEY')
        && defined('BCC_PUSH_VAPID_SUBJECT');

    ?>
    <div class="bcc-panel">
        <h2>Push delivery stats</h2>
        <p style="color:#666;font-size:13px;">
            Real-time delivery counters for V2 Phase 1 web push.
            Counters reset hourly; only the current and previous UTC hour are kept.
            See <code>docs/v2-phase-1-push-notifications.md</code> §P1.F for the contract.
        </p>

        <?php if (!$vapidConfigured): ?>
            <div class="notice notice-warning inline" style="margin:10px 0;">
                <p>
                    <strong>VAPID keys not configured.</strong>
                    No pushes will fire until <code>BCC_PUSH_VAPID_PUBLIC_KEY</code>,
                    <code>BCC_PUSH_VAPID_PRIVATE_KEY</code>, and
                    <code>BCC_PUSH_VAPID_SUBJECT</code> are defined in
                    <code>wp-config.php</code>. Run
                    <code>wp bcc-trust push generate-vapid</code> to mint a fresh pair.
                </p>
            </div>
        <?php endif; ?>

        <h3 style="margin-top:20px;">Current UTC hour (<?php echo esc_html(gmdate('Y-m-d H:00', $now)); ?> UTC)</h3>
        <?php if (! $current_available): ?>
            <?php // ⚠ Not a table of zeros: nothing was RECORDED for this
                  // hour, which is different from "nothing was sent". Before
                  // the hour's first push this is the normal state, so it is
                  // deliberately NOT styled as an error. ?>
            <p style="color:#646970;padding:10px 0;">
                <strong><?php esc_html_e('No counters recorded for this hour yet.', 'bcc-trust'); ?></strong><br>
                <span style="font-size:12px;">
                    <?php esc_html_e('Counters appear once the first push of the hour is attempted. An empty hour here is not a statement that no push was sent.', 'bcc-trust'); ?>
                </span>
            </p>
        <?php else: ?>
            <?php bcc_trust_render_push_stats_table($currentSnap); ?>
        <?php endif; ?>

        <h3 style="margin-top:30px;">Previous UTC hour (<?php echo esc_html(gmdate('Y-m-d H:00', $prevHour)); ?> UTC)</h3>
        <?php if (! $prev_available): ?>
            <?php // The previous hour is the more informative of the two: by
                  // now it either recorded something or the counters never
                  // reached this reader. Still not an error — the buckets
                  // expire after PushMetrics::TTL (2 hours). ?>
            <p style="color:#646970;padding:10px 0;">
                <strong><?php esc_html_e('No counters recorded for the previous hour.', 'bcc-trust'); ?></strong><br>
                <span style="font-size:12px;">
                    <?php esc_html_e('Either no push was attempted in that hour, or its counters have expired. Counters are kept for two hours.', 'bcc-trust'); ?>
                </span>
            </p>
        <?php else: ?>
            <?php bcc_trust_render_push_stats_table($prevSnap); ?>
        <?php endif; ?>
    </div>

    <div class="bcc-panel" style="margin-top:20px;">
        <h2>Health rules</h2>
        <ul style="margin-left:20px;">
            <li>A row turns <strong style="color:#dc2626;">red</strong> when its success rate falls below 80% — typically a bad payload, a provider outage, or a bug in the rendering layer.</li>
            <li><code>tombstone</code> counts subscriptions auto-deleted after a 410 Gone — that's the browser saying "this subscription is dead". Healthy in small numbers; concerning if it spikes.</li>
            <li>Zeroes across the board can mean either "no events" or "events fired but push is muted by user prefs". Cross-reference with the bell write rate before debugging.</li>
        </ul>
    </div>
    <?php
}

/**
 * @param array<string, array<string, int>> $snap outcome => eventType => count
 */
function bcc_trust_render_push_stats_table(array $snap): void {
    $eventTypes = \BCC\Trust\Core\Support\NotificationPrefs::PUSH_TYPES;
    $outcomes   = \BCC\Trust\Core\Support\PushMetrics::ALL_OUTCOMES;

    ?>
    <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
        <thead>
            <tr>
                <th>Event type</th>
                <?php foreach ($outcomes as $outcome): ?>
                    <th style="text-align:right;"><?php echo esc_html(ucfirst($outcome)); ?></th>
                <?php endforeach; ?>
                <th style="text-align:right;">Success rate</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($eventTypes as $eventType):
            $attempts = $snap['attempt'][$eventType]   ?? 0;
            $success  = $snap['success'][$eventType]   ?? 0;
            $tombstone = $snap['tombstone'][$eventType] ?? 0;
            $failure  = $snap['failure'][$eventType]   ?? 0;
            $rate     = $attempts > 0 ? ($success / $attempts) : null;
            $unhealthy = $rate !== null && $rate < 0.8;
            $rowStyle  = $unhealthy ? ' style="background:#fef2f2;"' : '';
            ?>
            <tr<?php echo $rowStyle; ?>>
                <td><code><?php echo esc_html($eventType); ?></code></td>
                <td style="text-align:right;"><?php echo intval($attempts); ?></td>
                <td style="text-align:right;color:#16a34a;"><?php echo intval($success); ?></td>
                <td style="text-align:right;color:#d97706;"><?php echo intval($tombstone); ?></td>
                <td style="text-align:right;color:#dc2626;"><?php echo intval($failure); ?></td>
                <td style="text-align:right;<?php echo $unhealthy ? 'color:#dc2626;font-weight:bold;' : ''; ?>">
                    <?php
                    if ($rate === null) {
                        echo '—';
                    } else {
                        echo esc_html(sprintf('%.1f%%', $rate * 100));
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
