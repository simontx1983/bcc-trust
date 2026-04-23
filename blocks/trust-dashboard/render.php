<?php
/**
 * My Trust Dashboard Block -- server-side render.
 *
 * Owner-facing dashboard showing:
 * 1. Current trust score + tier + trend (from recent score events)
 * 2. Verification checklist (what's verified, what's not, with actions)
 * 3. Recent score activity log (vote cast, endorsement, recalculation)
 * 4. Actionable improvement steps
 *
 * Data sources: PageDataLoader (single source of truth), ScoreEventRepository (audit trail).
 * Only visible to the page owner (or admins).
 *
 * @var array    $attributes Block attributes
 * @var string   $content    Inner content
 * @var WP_Block $block      Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

$page_id = (int) ($attributes['pageId'] ?? 0);
if (!$page_id) {
    $page_id = get_the_ID();
}
if (!$page_id) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<div class="bcc-block-placeholder" style="padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px;">'
           . '<strong>Trust Dashboard</strong><br>'
           . '<small>Requires a page ID. Place on a PeepSo profile page or set a Page ID in block settings.</small>'
           . '</div>';
        return;
    }
    return;
}

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<div class="bcc-block-placeholder" style="padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px;">'
           . '<strong>Trust Dashboard</strong><br>'
           . '<small>Only visible to the page owner on their PeepSo profile.</small>'
           . '</div>';
        return;
    }
    return; // Not logged in — nothing to show.
}

if (!class_exists('\\BCC\\Trust\\Core\\Services\\PageDataLoader')) {
    return;
}

$data = \BCC\Trust\Core\Services\PageDataLoader::get($page_id);
if (!$data) {
    return;
}

// Only show to page owner or admins.
$viewer = \BCC\Trust\Core\Services\PageDataLoader::getViewer($current_user_id, $page_id);
$is_owner = (bool) ($viewer['is_owner'] ?? false);
$is_admin = current_user_can('manage_options');

if (!$is_owner && !$is_admin) {
    return;
}

$show_history   = (bool) ($attributes['showHistory'] ?? true);
$show_checklist = (bool) ($attributes['showChecklist'] ?? true);
$history_limit  = max(1, min(50, (int) ($attributes['historyLimit'] ?? 10)));

/* ── Extract data sections ── */
$trust        = $data['trust'] ?? [];
$verification = $data['verification'] ?? [];
$profiles     = $data['profiles'] ?? [];
$wallets      = $data['wallets'] ?? [];
$reputation   = $data['reputation'] ?? [];
$onchain      = $data['onchain'] ?? [];

$score       = $trust['score'] ?? null;
$tier        = $trust['tier'] ?? 'unavailable';
$confidence  = (float) ($trust['confidence'] ?? 0);
$conf_pct    = (int) round($confidence * 100);
$voters      = (int) ($trust['unique_voters'] ?? 0);
$endorsements = (int) ($trust['endorsements'] ?? 0);
$votes_up    = (int) ($trust['votes_up'] ?? 0);
$votes_down  = (int) ($trust['votes_down'] ?? 0);

$tier_labels = [
    'elite' => 'Elite', 'trusted' => 'Trusted', 'neutral' => 'Neutral',
    'caution' => 'Caution', 'risky' => 'Risky', 'unavailable' => 'Unavailable',
];
$tier_label = $tier_labels[$tier] ?? ucfirst($tier);

/* ── Score trend from audit trail ── */
$events = [];
$trend_delta = null;
$trend_direction = 'stable';
if (class_exists('\\BCC\\Trust\\Core\\Repositories\\ScoreEventRepository')) {
    try {
        $event_repo = new \BCC\Trust\Core\Repositories\ScoreEventRepository();
        $events = $event_repo->getForPage($page_id, $history_limit);

        // Calculate trend: compare current score to score 7 days ago.
        $week_ago = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        foreach ($events as $evt) {
            if ($evt->created_at <= $week_ago && $evt->score_before !== null) {
                $trend_delta = $score !== null ? round($score - (float) $evt->score_before, 1) : null;
                break;
            }
        }
        if ($trend_delta !== null) {
            $trend_direction = $trend_delta > 0.5 ? 'up' : ($trend_delta < -0.5 ? 'down' : 'stable');
        }
    } catch (\Exception $e) {
        // Audit-trail read failure: degrade to no trend, but surface the
        // error so an operator can notice a broken audit table or permission
        // change instead of silently losing the signal for every render.
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-trust] trust-dashboard trend query failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

/* ── Verification checklist ── */
$checklist = [];

$checklist[] = [
    'key'      => 'email',
    'label'    => __('Email Verified', 'bcc-trust'),
    'done'     => (bool) ($verification['email'] ?? false),
    'action'   => __('Verify your email in your profile settings.', 'bcc-trust'),
    'impact'   => __('Required for voting and endorsements.', 'bcc-trust'),
];

$checklist[] = [
    'key'      => 'github',
    'label'    => __('GitHub Connected', 'bcc-trust'),
    'done'     => (bool) ($verification['github'] ?? false),
    'action'   => __('Connect your GitHub account to prove developer identity.', 'bcc-trust'),
    'impact'   => __('Increases identity score and trust confidence.', 'bcc-trust'),
];

$checklist[] = [
    'key'      => 'x',
    'label'    => __('X (Twitter) Connected', 'bcc-trust'),
    'done'     => (bool) ($verification['x'] ?? false),
    'action'   => __('Connect your X account.', 'bcc-trust'),
    'impact'   => __('Adds social identity verification.', 'bcc-trust'),
];

$has_any_wallet = (bool) ($verification['wallet'] ?? false);
$wallet_chains_connected = [];
foreach ($wallets as $chain => $connected) {
    if ($connected) {
        $wallet_chains_connected[] = ucfirst($chain);
    }
}
$checklist[] = [
    'key'      => 'wallet',
    'label'    => __('Wallet Connected', 'bcc-trust'),
    'done'     => $has_any_wallet,
    'action'   => __('Connect a blockchain wallet (Ethereum, Solana, or Cosmos).', 'bcc-trust'),
    'impact'   => __('Unlocks on-chain trust boost and NFT verification.', 'bcc-trust'),
    'detail'   => $has_any_wallet ? implode(', ', $wallet_chains_connected) : '',
];

$total_checks = count($checklist);
$done_checks  = 0;
foreach ($checklist as $item) {
    if ($item['done']) {
        $done_checks++;
    }
}
$checklist_pct = $total_checks > 0 ? (int) round(($done_checks / $total_checks) * 100) : 0;

/* ── Improvement suggestions ── */
$suggestions = [];
if ($voters < 3) {
    $suggestions[] = __('Share your page to get more community votes.', 'bcc-trust');
}
if ($endorsements < 1) {
    $suggestions[] = __('Ask trusted community members to endorse your page.', 'bcc-trust');
}
if (!$has_any_wallet) {
    $suggestions[] = __('Connect a wallet to unlock on-chain trust signals.', 'bcc-trust');
}
if ($done_checks < $total_checks) {
    $suggestions[] = sprintf(
        __('Complete your verification checklist (%d of %d done).', 'bcc-trust'),
        $done_checks,
        $total_checks
    );
}
if ($confidence < 0.3 && $voters >= 1) {
    $suggestions[] = __('Your confidence score is low. More diverse voters will increase it.', 'bcc-trust');
}

$wrapper_attributes = get_block_wrapper_attributes(['class' => 'bcc-td']);
?>
<div <?php echo $wrapper_attributes; ?>>

    <!-- Header: Score + Trend -->
    <div class="bcc-td__header">
        <div class="bcc-td__score-area">
            <div class="bcc-td__score-circle bcc-tier-bg--<?php echo esc_attr($tier); ?>">
                <span class="bcc-td__score-value"><?php echo $score !== null ? esc_html((string) $score) : '&mdash;'; ?></span>
            </div>
            <div class="bcc-td__score-meta">
                <span class="bcc-td__tier bcc-tier-text--<?php echo esc_attr($tier); ?>"><?php echo esc_html($tier_label); ?></span>
                <?php if ($trend_delta !== null && $trend_direction !== 'stable'): ?>
                <span class="bcc-td__trend bcc-td__trend--<?php echo esc_attr($trend_direction); ?>">
                    <?php echo $trend_direction === 'up' ? '&#9650;' : '&#9660;'; ?>
                    <?php echo esc_html(abs($trend_delta)); ?> pts this week
                </span>
                <?php elseif ($trend_delta !== null): ?>
                <span class="bcc-td__trend bcc-td__trend--stable">&#8212; Stable this week</span>
                <?php endif; ?>
                <span class="bcc-td__confidence"><?php echo esc_html((string) $conf_pct); ?>% confidence</span>
            </div>
        </div>
        <div class="bcc-td__stats">
            <div class="bcc-td__stat">
                <span class="bcc-td__stat-value"><?php echo esc_html((string) $voters); ?></span>
                <span class="bcc-td__stat-label"><?php esc_html_e('Voters', 'bcc-trust'); ?></span>
            </div>
            <div class="bcc-td__stat">
                <span class="bcc-td__stat-value"><?php echo esc_html((string) $endorsements); ?></span>
                <span class="bcc-td__stat-label"><?php esc_html_e('Endorsements', 'bcc-trust'); ?></span>
            </div>
            <div class="bcc-td__stat">
                <span class="bcc-td__stat-value bcc-td__stat-value--positive"><?php echo esc_html((string) $votes_up); ?></span>
                <span class="bcc-td__stat-label"><?php esc_html_e('Upvotes', 'bcc-trust'); ?></span>
            </div>
            <div class="bcc-td__stat">
                <span class="bcc-td__stat-value bcc-td__stat-value--negative"><?php echo esc_html((string) $votes_down); ?></span>
                <span class="bcc-td__stat-label"><?php esc_html_e('Downvotes', 'bcc-trust'); ?></span>
            </div>
        </div>
    </div>

    <?php if ($show_checklist): ?>
    <!-- Verification Checklist -->
    <div class="bcc-td__section">
        <h4 class="bcc-td__section-title">
            <?php esc_html_e('Verification Checklist', 'bcc-trust'); ?>
            <span class="bcc-td__checklist-progress"><?php echo esc_html("{$done_checks}/{$total_checks}"); ?></span>
        </h4>
        <div class="bcc-td__progress-bar">
            <div class="bcc-td__progress-fill" style="width:<?php echo esc_attr((string) $checklist_pct); ?>%"></div>
        </div>
        <div class="bcc-td__checklist">
            <?php foreach ($checklist as $item): ?>
            <div class="bcc-td__check-item <?php echo $item['done'] ? 'bcc-td__check-item--done' : 'bcc-td__check-item--pending'; ?>">
                <span class="bcc-td__check-icon">
                    <?php if ($item['done']): ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    <?php else: ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
                    <?php endif; ?>
                </span>
                <div class="bcc-td__check-content">
                    <span class="bcc-td__check-label"><?php echo esc_html($item['label']); ?></span>
                    <?php if ($item['done'] && !empty($item['detail'])): ?>
                    <span class="bcc-td__check-detail"><?php echo esc_html($item['detail']); ?></span>
                    <?php elseif (!$item['done']): ?>
                    <span class="bcc-td__check-action"><?php echo esc_html($item['action']); ?></span>
                    <span class="bcc-td__check-impact"><?php echo esc_html($item['impact']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($suggestions)): ?>
    <!-- Improvement Suggestions -->
    <div class="bcc-td__section">
        <h4 class="bcc-td__section-title"><?php esc_html_e('How to Improve Your Score', 'bcc-trust'); ?></h4>
        <ul class="bcc-td__suggestions">
            <?php foreach ($suggestions as $suggestion): ?>
            <li class="bcc-td__suggestion"><?php echo esc_html($suggestion); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($show_history && !empty($events)): ?>
    <!-- Score Activity Log -->
    <div class="bcc-td__section">
        <h4 class="bcc-td__section-title"><?php esc_html_e('Recent Score Activity', 'bcc-trust'); ?></h4>
        <div class="bcc-td__activity">
            <?php foreach ($events as $evt):
                $evt_delta = $evt->delta !== null ? (float) $evt->delta : null;
                $evt_class = '';
                if ($evt_delta !== null) {
                    $evt_class = $evt_delta > 0 ? 'bcc-td__event--positive' : ($evt_delta < 0 ? 'bcc-td__event--negative' : '');
                }
                $evt_type_labels = [
                    'vote_changed'         => __('Vote', 'bcc-trust'),
                    'endorsement_added'    => __('Endorsement', 'bcc-trust'),
                    'endorsement_removed'  => __('Endorsement Revoked', 'bcc-trust'),
                    'recalculation'        => __('Recalculation', 'bcc-trust'),
                    'dispute_resolved'     => __('Dispute', 'bcc-trust'),
                    'moderation'           => __('Moderation', 'bcc-trust'),
                ];
                $evt_label = $evt_type_labels[$evt->event_type] ?? ucfirst(str_replace('_', ' ', $evt->event_type));
                $evt_time  = $evt->created_at ? human_time_diff(strtotime($evt->created_at)) . ' ago' : '';
            ?>
            <div class="bcc-td__event <?php echo esc_attr($evt_class); ?>">
                <span class="bcc-td__event-type"><?php echo esc_html($evt_label); ?></span>
                <?php if ($evt_delta !== null): ?>
                <span class="bcc-td__event-delta"><?php echo ($evt_delta >= 0 ? '+' : '') . esc_html(number_format($evt_delta, 1)); ?></span>
                <?php endif; ?>
                <?php if ($evt->score_after !== null): ?>
                <span class="bcc-td__event-score"><?php echo esc_html((string) round((float) $evt->score_after)); ?></span>
                <?php endif; ?>
                <?php if ($evt->tier_before && $evt->tier_after && $evt->tier_before !== $evt->tier_after): ?>
                <span class="bcc-td__event-tier-change">
                    <span class="bcc-tier-text--<?php echo esc_attr($evt->tier_before); ?>"><?php echo esc_html(ucfirst($evt->tier_before)); ?></span>
                    &rarr;
                    <span class="bcc-tier-text--<?php echo esc_attr($evt->tier_after); ?>"><?php echo esc_html(ucfirst($evt->tier_after)); ?></span>
                </span>
                <?php endif; ?>
                <span class="bcc-td__event-time"><?php echo esc_html($evt_time); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
