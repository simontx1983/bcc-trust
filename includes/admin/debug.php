<?php
/**
 * Trust Debug Panel — Admin Page
 *
 * Forensic tool for answering "Why is this page's trust score X?"
 * Shows stored data only — no expensive live computations on page load.
 * Live fraud analysis available via explicit button click.
 *
 * @package BCC_Trust_Engine
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Core\Services\Admin\TrustDebugService;

// ── Menu registration ───────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_submenu_page(
        'bcc-trust-dashboard',
        __('Trust Debug', 'bcc-trust'),
        __('Debug', 'bcc-trust'),
        'manage_options',
        'bcc-trust-debug',
        'bcc_trust_render_debug_page',
        99  // Last in submenu
    );
}, 30);

// ── AJAX handler for live fraud analysis ────────────────────────────────────

add_action('wp_ajax_bcc_trust_debug_fraud', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('bcc_trust_debug_fraud', 'nonce');

    $userId = (int) ($_POST['user_id'] ?? 0);
    if (!$userId) {
        wp_send_json_error(['message' => 'Missing user_id']);
    }

    $result = TrustDebugService::runLiveFraudAnalysis($userId);
    wp_send_json_success($result);
});

// ── AJAX handler for vote filtering ─────────────────────────────────────────

add_action('wp_ajax_bcc_trust_debug_votes', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('bcc_trust_debug_votes', 'nonce');

    $pageId = (int) ($_POST['page_id'] ?? 0);
    $filter = sanitize_key($_POST['filter'] ?? 'recent');
    $search = sanitize_text_field($_POST['search'] ?? '');

    if (!$pageId) {
        wp_send_json_error(['message' => 'Missing page_id']);
    }

    $limit = $filter === 'all' ? 500 : 10;
    $votes = TrustDebugService::getVotes($pageId, $filter, $limit, $search ?: null);

    // Defense-in-depth: escape attacker-controlled identifiers at the
    // response boundary so even a direct curl probe gets safe HTML back.
    if (isset($votes['items']) && is_array($votes['items'])) {
        foreach ($votes['items'] as $i => $item) {
            if (is_object($item)) {
                if (isset($item->voter_name)) {
                    $votes['items'][$i]->voter_name = esc_html((string) $item->voter_name);
                }
                if (isset($item->voter_tier)) {
                    $votes['items'][$i]->voter_tier = esc_html((string) $item->voter_tier);
                }
                if (isset($item->created_at)) {
                    $votes['items'][$i]->created_at = esc_html((string) $item->created_at);
                }
            } elseif (is_array($item)) {
                if (isset($item['voter_name']))  { $votes['items'][$i]['voter_name']  = esc_html((string) $item['voter_name']); }
                if (isset($item['voter_tier']))  { $votes['items'][$i]['voter_tier']  = esc_html((string) $item['voter_tier']); }
                if (isset($item['created_at'])) { $votes['items'][$i]['created_at'] = esc_html((string) $item['created_at']); }
            }
        }
    }

    wp_send_json_success($votes);
});

// ── Render ──────────────────────────────────────────────────────────────────

function bcc_trust_render_debug_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Resolve input: page_id or user_id
    $pageId = absint($_GET['page_id'] ?? 0);
    $userId = absint($_GET['user_id'] ?? 0);
    $search = sanitize_text_field($_GET['search'] ?? '');

    // Resolve user_id → page_id via PeepSoPageResolver
    if (!$pageId && $userId) {
        $pageId = (int) \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver()->getPageForOwner($userId);
    }

    // Search by username
    if (!$pageId && $search) {
        $user = get_user_by('login', $search) ?: get_user_by('email', $search);
        if ($user) {
            $pageId = (int) \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver()->getPageForOwner($user->ID);
            $userId = $user->ID;
        } elseif (is_numeric($search)) {
            $pageId = (int) $search;
        }
    }

    $data = $pageId ? TrustDebugService::getPageDebugData($pageId) : null;

    echo '<div class="wrap bcc-admin-wrap">';
    echo '<h1>' . esc_html__('Trust Debug', 'bcc-trust') . '</h1>';

    // ── Search form ─────────────────────────────────────────────────────
    echo '<div class="card" style="max-width:600px;margin-bottom:20px;">';
    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="bcc-trust-debug" />';
    printf(
        '<label><strong>%s</strong></label><br>',
        esc_html__('Page ID, User ID, or Username', 'bcc-trust')
    );
    printf(
        '<input type="text" name="search" value="%s" class="regular-text" placeholder="123 or username" style="margin-top:4px;" />',
        esc_attr($search ?: ($pageId ?: ''))
    );
    printf(
        ' <button type="submit" class="button button-primary">%s</button>',
        esc_html__('Debug', 'bcc-trust')
    );
    echo '</form>';
    echo '</div>';

    if (!$data) {
        if ($pageId || $userId || $search) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Page not found.', 'bcc-trust') . '</p></div>';
        }
        echo '</div>';
        return;
    }

    $page  = $data['page'];
    $score = $data['score'];
    $fraud = $data['fraud'];

    // Color helpers
    $tierColors = [
        'elite' => '#2e7d32', 'trusted' => '#1565c0', 'neutral' => '#666',
        'caution' => '#e65100', 'risky' => '#c62828',
    ];
    $tierColor = $tierColors[$score['reputation_tier']] ?? '#666';

    $fraudNonce = wp_create_nonce('bcc_trust_debug_fraud');
    $voteNonce  = wp_create_nonce('bcc_trust_debug_votes');

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px;">';

    // ── SCORE CARD ──────────────────────────────────────────────────────
    echo '<div class="card" style="max-width:none;">';
    printf('<h2>%s: %s <small>(#%d)</small></h2>',
        esc_html__('Score', 'bcc-trust'),
        esc_html($page['title']),
        $page['page_id']
    );
    echo '<p>' . esc_html($page['owner_name']) . ' (#' . $page['owner_id'] . ')</p>';

    echo '<table class="widefat striped" style="border:none;">';
    $check = $score['formula_check'] ? '<span style="color:green;">&#10003;</span>' : '<span style="color:red;">&#10007; expected ' . esc_html($score['formula_expected']) . '</span>';
    $rows = [
        __('Total Score', 'bcc-trust') => sprintf('<strong style="color:%s;font-size:1.3em;">%.1f</strong> (%s) %s',
            esc_attr($tierColor), $score['total_score'], esc_html(ucfirst($score['reputation_tier'])), $check),
        __('Positive', 'bcc-trust')    => round($score['positive_score'], 2),
        __('Negative', 'bcc-trust')    => round($score['negative_score'], 2),
        __('Endorsement Bonus', 'bcc-trust') => '+' . round($score['endorsement_bonus'], 2),
        __('On-chain Bonus', 'bcc-trust')    => '+' . round($score['onchain_bonus'], 2),
        __('Confidence', 'bcc-trust')  => ($score['confidence_percentage'] ?? 0) . '%',
        __('Votes / Unique', 'bcc-trust') => $score['vote_count'] . ' / ' . $score['unique_voters'],
        __('Endorsements', 'bcc-trust') => $score['endorsement_count'],
    ];
    foreach ($rows as $label => $val) {
        printf('<tr><th style="width:140px;">%s</th><td>%s</td></tr>', esc_html($label), wp_kses_post((string) $val));
    }
    echo '</table></div>';

    // ── FRAUD SIGNALS (stored) ──────────────────────────────────────────
    echo '<div class="card" style="max-width:none;">';
    echo '<h2>' . esc_html__('Fraud Signals', 'bcc-trust') . ' <small>(stored)</small></h2>';

    $riskColors = ['critical' => '#c62828', 'high' => '#e65100', 'medium' => '#ed6c02', 'low' => '#1565c0', 'minimal' => '#2e7d32'];
    $riskColor = $riskColors[$fraud['risk_level']] ?? '#666';

    echo '<table class="widefat striped" style="border:none;">';
    $fRows = [
        __('Fraud Score', 'bcc-trust')     => sprintf('<strong style="color:%s;">%d</strong> (%s)', esc_attr($riskColor), $fraud['fraud_score'], esc_html($fraud['risk_level'])),
        __('Suspended', 'bcc-trust')       => $fraud['is_suspended'] ? '<span style="color:red;">Yes</span>' : 'No',
        __('Email Verified', 'bcc-trust')  => $fraud['is_verified'] ? '<span style="color:green;">Yes</span>' : '<span style="color:#e65100;">No</span>',
        __('Behavior Score', 'bcc-trust')  => $fraud['behavior_score'] . '/100',
        __('Trust Rank', 'bcc-trust')      => round($fraud['trust_rank'], 3),
        __('Automation Score', 'bcc-trust') => $fraud['automation_score'],
    ];
    foreach ($fRows as $label => $val) {
        printf('<tr><th style="width:140px;">%s</th><td>%s</td></tr>', esc_html($label), wp_kses_post((string) $val));
    }
    echo '</table>';

    // Live analysis button
    printf(
        '<div style="margin-top:12px;"><button type="button" class="button" id="bcc-debug-run-fraud" data-user-id="%d" data-nonce="%s">%s</button> <span id="bcc-debug-fraud-status"></span></div>',
        $page['owner_id'],
        esc_attr($fraudNonce),
        esc_html__('Run Live Fraud Analysis', 'bcc-trust')
    );
    echo '<div id="bcc-debug-fraud-result" style="margin-top:8px;display:none;"></div>';
    echo '</div>';

    echo '</div>'; // end 2-col grid

    // ── VOTES TABLE ─────────────────────────────────────────────────────
    echo '<div class="card" style="max-width:none;margin-top:16px;">';
    printf('<h2>%s (%d total)</h2>', esc_html__('Votes', 'bcc-trust'), $data['votes']['total']);

    // Filter buttons
    echo '<div style="margin-bottom:10px;">';
    $filters = ['recent' => 'Last 10', 'top_weight' => 'Top Weight', 'suspicious' => 'Suspicious', 'all' => 'All'];
    foreach ($filters as $key => $label) {
        $active = ($data['votes']['filter'] === $key) ? 'button-primary' : '';
        printf(
            '<button type="button" class="button %s bcc-debug-vote-filter" data-filter="%s" data-page-id="%d" data-nonce="%s" style="margin-right:4px;">%s</button>',
            $active, esc_attr($key), $page['page_id'], esc_attr($voteNonce), esc_html($label)
        );
    }
    echo ' <input type="text" id="bcc-debug-voter-search" placeholder="' . esc_attr__('Search voter...', 'bcc-trust') . '" style="width:150px;margin-left:8px;" />';
    echo '</div>';

    echo '<table class="widefat striped" id="bcc-debug-votes-table">';
    echo '<thead><tr>';
    echo '<th>Voter</th><th>Type</th><th>Weight</th><th>Vested</th><th>Stage</th><th>Fraud@Vote</th><th>Tier</th><th>Date</th>';
    echo '</tr></thead><tbody>';
    foreach ($data['votes']['items'] as $v) {
        $typeIcon = ((int) $v->vote_type === 1) ? '<span style="color:green;">&#9650;</span>' : '<span style="color:red;">&#9660;</span>';
        printf('<tr><td>%s (#%d)</td><td>%s</td><td>%.4f</td><td>%.4f</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($v->voter_name ?: 'Unknown'), (int) $v->voter_user_id,
            $typeIcon,
            (float) $v->weight, (float) $v->vested_weight,
            (int) $v->vesting_stage,
            $v->fraud_score_at_vote !== null ? (int) $v->fraud_score_at_vote : '-',
            esc_html($v->voter_tier ?: 'neutral'),
            esc_html($v->created_at)
        );
    }
    echo '</tbody></table></div>';

    // ── VERIFICATIONS ───────────────────────────────────────────────────
    $ver = $data['verifications'];
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">';
    echo '<div class="card" style="max-width:none;">';
    echo '<h2>' . esc_html__('Verifications', 'bcc-trust') . '</h2>';
    echo '<table class="widefat striped" style="border:none;">';
    printf('<tr><th>Email</th><td>%s</td></tr>', $ver['email'] ? '<span style="color:green;">Verified</span>' : '<span style="color:#e65100;">Not verified</span>');
    if ($ver['github']) {
        printf('<tr><th>GitHub</th><td>@%s (%d followers, %d repos) +%.1f boost</td></tr>',
            esc_html($ver['github']['username']), $ver['github']['followers'], $ver['github']['repos'], $ver['github']['trust_boost']);
    } else {
        echo '<tr><th>GitHub</th><td>Not connected</td></tr>';
    }
    if ($ver['x']) {
        printf('<tr><th>X</th><td>@%s +%.1f boost</td></tr>', esc_html($ver['x']['username']), $ver['x']['trust_boost']);
    } else {
        echo '<tr><th>X</th><td>Not connected</td></tr>';
    }
    printf('<tr><th>Wallets</th><td>%d connected</td></tr>', count($ver['wallets']));
    echo '</table></div>';

    // ── ON-CHAIN SUMMARY ────────────────────────────────────────────────
    $oc = $data['onchain'];
    echo '<div class="card" style="max-width:none;">';
    echo '<h2>' . esc_html__('On-Chain', 'bcc-trust') . '</h2>';
    echo '<table class="widefat striped" style="border:none;">';
    printf('<tr><th>Validators</th><td>%d across %d chains</td></tr>', $oc['validator_count'], $oc['chains_count']);
    printf('<tr><th>Total Stake</th><td>%s</td></tr>', esc_html($oc['total_stake']));
    printf('<tr><th>On-chain Bonus</th><td>+%s</td></tr>', round($score['onchain_bonus'], 2));
    echo '</table></div>';
    echo '</div>';

    // ── ENDORSEMENTS ────────────────────────────────────────────────────
    $end = $data['endorsements'];
    if ($end['count'] > 0) {
        echo '<div class="card" style="max-width:none;margin-top:16px;">';
        printf('<h2>%s (%d, weight: %.2f)</h2>', esc_html__('Endorsements', 'bcc-trust'), $end['count'], $end['total_weight']);
        echo '<table class="widefat striped"><thead><tr><th>Endorser</th><th>Weight</th><th>Stage</th><th>Context</th><th>Date</th></tr></thead><tbody>';
        foreach ($end['items'] as $e) {
            printf('<tr><td>%s (#%d)</td><td>%.2f</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                esc_html($e->endorser_name ?: 'Unknown'), (int) $e->endorser_user_id,
                (float) $e->weight, (int) $e->vesting_stage,
                esc_html($e->context), esc_html($e->created_at));
        }
        echo '</tbody></table></div>';
    }

    // ── PUBLIC FLAGS (signal only — no score impact) ──────────────────
    $flagData = $data['flags'] ?? ['count' => 0, 'recent' => []];
    echo '<div class="card" style="max-width:none;margin-top:16px;">';
    printf('<h2>%s (%d)</h2>', esc_html__('Public Flags', 'bcc-trust'), $flagData['count']);
    if ($flagData['count'] === 0) {
        echo '<p style="color:#888;">' . esc_html__('No flags on this page.', 'bcc-trust') . '</p>';
    } else {
        echo '<p style="color:#666;font-style:italic;">' . esc_html__('Signal only — flags do not affect trust score.', 'bcc-trust') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>User</th><th>Reason</th><th>Date</th></tr></thead><tbody>';
        foreach ($flagData['recent'] as $flag) {
            printf('<tr><td>%s (#%d)</td><td>%s</td><td>%s</td></tr>',
                esc_html($flag->display_name ?: 'Unknown'), (int) $flag->user_id,
                esc_html($flag->reason ?: '—'),
                esc_html($flag->created_at));
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    // ── TIMELINE ────────────────────────────────────────────────────────
    if (!empty($data['timeline'])) {
        echo '<div class="card" style="max-width:none;margin-top:16px;">';
        echo '<h2>' . esc_html__('Recent Events', 'bcc-trust') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Action</th><th>Weight</th><th>Date</th></tr></thead><tbody>';
        foreach ($data['timeline'] as $ev) {
            printf('<tr><td>%s</td><td>%s</td><td>%.4f</td><td>%s</td></tr>',
                esc_html($ev->event_type), esc_html($ev->action),
                (float) $ev->weight, esc_html($ev->created_at));
        }
        echo '</tbody></table></div>';
    }

    echo '</div>'; // end .wrap

    // ── Inline JS for AJAX ──────────────────────────────────────────────
    ?>
    <script>
    (function(){
        // Live fraud analysis
        var btn = document.getElementById('bcc-debug-run-fraud');
        if(btn) btn.addEventListener('click', function(){
            var uid = this.dataset.userId, nonce = this.dataset.nonce;
            var status = document.getElementById('bcc-debug-fraud-status');
            var result = document.getElementById('bcc-debug-fraud-result');
            status.textContent = 'Running...';
            fetch(ajaxurl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'action=bcc_trust_debug_fraud&nonce='+nonce+'&user_id='+uid
            }).then(r=>r.json()).then(function(r){
                status.textContent = r.success ? 'Done' : 'Failed';
                if(r.success && r.data){
                    result.style.display='block';
                    result.innerHTML='<pre style="background:#f0f0f0;padding:8px;font-size:12px;max-height:300px;overflow:auto;">'+JSON.stringify(r.data,null,2)+'</pre>';
                }
            });
        });

        // Vote filtering
        document.querySelectorAll('.bcc-debug-vote-filter').forEach(function(b){
            b.addEventListener('click', function(){
                var filter = this.dataset.filter, pageId = this.dataset.pageId, nonce = this.dataset.nonce;
                var search = document.getElementById('bcc-debug-voter-search').value;
                fetch(ajaxurl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=bcc_trust_debug_votes&nonce='+nonce+'&page_id='+pageId+'&filter='+filter+'&search='+encodeURIComponent(search)
                }).then(r=>r.json()).then(function(r){
                    if(!r.success) return;
                    // XSS FIX: escape all attacker-controlled values (display_name, tier, timestamps)
                    // before DOM insertion. Never use innerHTML += with untrusted data.
                    function escHtml(s){var d=document.createElement('div'); d.textContent=(s===null||s===undefined)?'':String(s); return d.innerHTML;}
                    var tbody = document.querySelector('#bcc-debug-votes-table tbody');
                    tbody.innerHTML='';
                    var rows = r.data.items.map(function(v){
                        var icon = v.vote_type==1 ? '<span style="color:green;">&#9650;</span>':'<span style="color:red;">&#9660;</span>';
                        return '<tr>'
                            + '<td>'+escHtml(v.voter_name||'Unknown')+' (#'+(v.voter_user_id|0)+')</td>'
                            + '<td>'+icon+'</td>'
                            + '<td>'+Number(v.weight).toFixed(4)+'</td>'
                            + '<td>'+Number(v.vested_weight).toFixed(4)+'</td>'
                            + '<td>'+(v.vesting_stage|0)+'</td>'
                            + '<td>'+(v.fraud_score_at_vote!==null?(v.fraud_score_at_vote|0):'-')+'</td>'
                            + '<td>'+escHtml(v.voter_tier||'neutral')+'</td>'
                            + '<td>'+escHtml(v.created_at)+'</td>'
                            + '</tr>';
                    }).join('');
                    tbody.innerHTML = rows;
                    document.querySelectorAll('.bcc-debug-vote-filter').forEach(function(x){x.classList.remove('button-primary');});
                    b.classList.add('button-primary');
                });
            });
        });
    })();
    </script>
    <?php
}
