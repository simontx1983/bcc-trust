<?php
/**
 * Quest Dashboard — PeepSo User Profile Segment
 *
 * Rendered by PeepSoIntegration::renderQuestProfileSegment().
 *
 * Available variables:
 *   $progress        — array from QuestProgressService::getProgress()
 *   $endorseProgress — array from QuestProgressService::getEndorseProgress()
 *   $isOwnProfile    — bool: is the viewer looking at their own profile?
 *   $userId          — int: the profile user's ID
 *
 * @package BCC\Trust
 */

if (!defined('ABSPATH')) {
    exit;
}

$quests         = $progress['quests'] ?? [];
$completedCount = $progress['completed_count'] ?? 0;
$totalCount     = $progress['total_count'] ?? 0;
$pct            = $progress['pct'] ?? 0;
$multiplier     = $progress['multiplier'] ?? 1.0;

$endorseUnlocked = $endorseProgress['unlocked'] ?? false;
$endorseReason   = $endorseProgress['missing_reason'] ?? null;

$categoryLabels = [
    'identity'   => 'Identity Verification',
    'engagement' => 'Platform Engagement',
];

// Build action URLs for each incomplete quest (own profile only).
$questActions = [];
if ($isOwnProfile) {
    // Profile edit URL
    $profileUrl = '';
    if (class_exists('PeepSoUser')) {
        $peepsoUser = \PeepSoUser::get_instance($userId);
        $profileUrl = $peepsoUser->get_profileurl() . 'about/';
    }

    // Discovery page (where projects can be browsed and voted on)
    $discoverUrl = '';
    $discoverPageId = class_exists('PeepSo') ? \PeepSo::get_option('page_pages', 0) : 0;
    if ($discoverPageId) {
        $discoverUrl = get_permalink($discoverPageId);
    }
    if (!$discoverUrl) {
        $discoverUrl = home_url('/projects/');
    }

    // GitHub auth URL (REST endpoint that redirects to GitHub OAuth)
    $githubAuthUrl = rest_url('bcc-trust/v1/github/auth');

    // X auth URL (REST endpoint that returns OAuth URL — we fetch it async)
    $xAuthEndpoint = rest_url('bcc-trust/v1/x/auth');

    $questActions = [
        'complete_profile'  => [
            'url'    => $profileUrl,
            'label'  => 'Edit Profile',
            'inline' => false,
        ],
        'first_vote'        => [
            'url'    => $discoverUrl,
            'label'  => 'Browse Projects',
            'inline' => false,
        ],
        'explore_projects'  => [
            'url'    => $discoverUrl,
            'label'  => 'Explore Projects',
            'inline' => false,
        ],
        'connect_wallet'    => [
            'url'    => '#',
            'label'  => 'Connect Wallet',
            'inline' => true,
            'action' => 'bcc-quest-connect-wallet',
        ],
        'verify_github'     => [
            'url'    => $githubAuthUrl,
            'label'  => 'Verify GitHub',
            'btnClass' => 'bcc-quest-card__btn--github',
            'inline' => false,
        ],
        'verify_x'          => [
            'url'    => '#',
            'label'  => 'Verify X',
            'btnClass' => 'bcc-quest-card__btn--x',
            'inline' => true,
            'action' => 'bcc-quest-verify-x',
            'data'   => ['endpoint' => $xAuthEndpoint],
        ],
        'share_x'           => [
            'url'      => '#',
            'label'    => 'Share on X',
            'btnClass' => 'bcc-quest-card__btn--x',
            'inline'   => true,
            'action'   => 'bcc-quest-share-x',
            'requires' => ($quests['verify_x']['done'] ?? false) ? null : 'Verify your X account first to enable tweet verification',
            'data'     => [
                'verify-endpoint' => rest_url('bcc-trust/v1/x/verify-share'),
                'share-text'      => 'Check out my profile on Blue Collar Crypto — a trust-scored Web3 community',
                'share-url'       => $profileUrl,
            ],
        ],
    ];
}

// Group quests by category for display.
$grouped = [];
foreach ($quests as $slug => $quest) {
    $cat = $quest['category'] ?? 'other';
    $grouped[$cat][] = array_merge($quest, ['slug' => $slug]);
}
?>

<style>
.bcc-quest-dashboard { max-width: 680px; margin: 0 auto; padding: 20px 0; }
.bcc-quest-overview { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
.bcc-quest-overview__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.bcc-quest-overview__title { margin: 0; font-size: 18px; font-weight: 600; }
.bcc-quest-overview__count { font-size: 14px; color: #6b7280; font-weight: 500; }
.bcc-quest-progress-bar { height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; margin-bottom: 16px; }
.bcc-quest-progress-bar__fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); border-radius: 4px; transition: width 0.6s ease; }
.bcc-quest-overview__stats { display: flex; gap: 32px; }
.bcc-quest-stat { display: flex; flex-direction: column; }
.bcc-quest-stat__value { font-size: 20px; font-weight: 700; color: #111827; }
.bcc-quest-stat__value--unlocked { color: #059669; }
.bcc-quest-stat__value--locked { color: #9ca3af; }
.bcc-quest-stat__label { font-size: 12px; color: #6b7280; margin-top: 2px; }
.bcc-quest-overview__hint { margin: 12px 0 0; padding: 10px 12px; background: #fef3c7; border-radius: 6px; font-size: 13px; color: #92400e; }
.bcc-quest-category { margin-bottom: 20px; }
.bcc-quest-category__title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin: 0 0 10px; }
.bcc-quest-list { display: flex; flex-direction: column; gap: 8px; }
.bcc-quest-card { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; transition: border-color 0.2s; }
.bcc-quest-card--done { border-color: #a7f3d0; background: #f0fdf4; }
.bcc-quest-card--pending { opacity: 0.85; }
.bcc-quest-card--pending:hover { opacity: 1; border-color: #93c5fd; }
.bcc-quest-card__status { flex-shrink: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
.bcc-quest-card__check { width: 24px; height: 24px; border-radius: 50%; background: #059669; color: #fff; font-size: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.bcc-quest-card__circle { width: 24px; height: 24px; border-radius: 50%; border: 2px solid #d1d5db; }
.bcc-quest-card__content { flex: 1; min-width: 0; }
.bcc-quest-card__label { display: block; font-size: 14px; font-weight: 600; color: #111827; }
.bcc-quest-card__hint { display: block; font-size: 12px; color: #6b7280; margin-top: 2px; }
.bcc-quest-card__bonus { flex-shrink: 0; font-size: 13px; font-weight: 600; color: #7c3aed; background: #ede9fe; padding: 3px 8px; border-radius: 4px; }
.bcc-quest-card--done .bcc-quest-card__bonus { color: #059669; background: #d1fae5; }
.bcc-quest-card__verified { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.bcc-quest-card__verified-avatar { width: 20px; height: 20px; border-radius: 50%; }
.bcc-quest-card__verified-name { font-size: 12px; color: #059669; font-weight: 500; }
.bcc-quest-card__disconnect { font-size: 11px; color: #9ca3af; background: none; border: none; cursor: pointer; padding: 0; margin-left: auto; text-decoration: underline; }
.bcc-quest-card__disconnect:hover { color: #ef4444; }
.bcc-quest-card__action { flex-shrink: 0; }
.bcc-quest-card__btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-size: 13px; font-weight: 600; color: #fff; background: #3b82f6; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; transition: background 0.15s; white-space: nowrap; }
.bcc-quest-card__btn:hover { background: #2563eb; color: #fff; text-decoration: none; }
.bcc-quest-card__btn:active { background: #1d4ed8; }
.bcc-quest-card__btn i { font-size: 12px; }
.bcc-quest-card__btn--wallet { background: #7c3aed; }
.bcc-quest-card__btn--wallet:hover { background: #6d28d9; }
.bcc-quest-card__btn--github { background: #24292f; }
.bcc-quest-card__btn--github:hover { background: #1b1f23; }
.bcc-quest-card__btn--x { background: #000; }
.bcc-quest-card__btn--x:hover { background: #333; }
.bcc-quest-card__btn--locked { opacity: 0.4; cursor: not-allowed; }
.bcc-quest-card__btn--confirm { background: #059669; animation: bcc-quest-pulse 1.5s ease infinite; }
.bcc-quest-card__btn--confirm:hover { background: #047857; }
@keyframes bcc-quest-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(5,150,105,0.4); } 50% { box-shadow: 0 0 0 6px rgba(5,150,105,0); } }
.bcc-quest-card--done .bcc-quest-card__action { display: none; }

/* Wallet connect modal */
.bcc-quest-wallet-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 100000; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
.bcc-quest-wallet-modal--open { display: flex; }
.bcc-quest-wallet-modal__inner { background: #fff; border-radius: 12px; padding: 24px; max-width: 400px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.bcc-quest-wallet-modal__title { margin: 0 0 8px; font-size: 18px; font-weight: 600; }
.bcc-quest-wallet-modal__desc { margin: 0 0 20px; font-size: 14px; color: #6b7280; }
.bcc-quest-wallet-modal__options { display: flex; flex-direction: column; gap: 10px; }
.bcc-quest-wallet-modal__option { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: border-color 0.15s, background 0.15s; background: #fff; font-size: 14px; font-weight: 500; }
.bcc-quest-wallet-modal__option:hover { border-color: #7c3aed; background: #faf5ff; }
.bcc-quest-wallet-modal__option img { width: 28px; height: 28px; }
.bcc-quest-wallet-modal__option span { flex: 1; }
.bcc-quest-wallet-modal__option .bcc-quest-wallet-modal__arrow { color: #9ca3af; font-size: 18px; }
.bcc-quest-wallet-modal__close { display: block; width: 100%; margin-top: 12px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; cursor: pointer; font-size: 14px; color: #6b7280; text-align: center; }
.bcc-quest-wallet-modal__close:hover { background: #f9fafb; }
.bcc-quest-wallet-modal__status { margin-top: 12px; padding: 10px; border-radius: 6px; font-size: 13px; text-align: center; display: none; }
.bcc-quest-wallet-modal__status--error { display: block; background: #fef2f2; color: #991b1b; }
.bcc-quest-wallet-modal__status--success { display: block; background: #f0fdf4; color: #166534; }
.bcc-quest-wallet-modal__status--loading { display: block; background: #eff6ff; color: #1e40af; }
</style>

<div class="bcc-quest-dashboard ps-page-profile">

    <?php
    // Show X verification result from OAuth redirect
    if (isset($_GET['x_verified'])):
        $xResult = sanitize_text_field($_GET['x_verified']);
        $xError  = isset($_GET['x_error']) ? sanitize_text_field(urldecode($_GET['x_error'])) : '';
    ?>
        <div class="bcc-quest-overview__hint" style="margin-bottom: 16px; <?php echo $xResult === 'success' ? 'background:#d1fae5;color:#065f46;' : 'background:#fef2f2;color:#991b1b;'; ?>">
            <?php if ($xResult === 'success'): ?>
                X account verified successfully!
            <?php else: ?>
                X verification failed<?php echo $xError ? ': ' . esc_html($xError) : '. Please try again.'; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Progress Overview -->
    <div class="bcc-quest-overview">
        <div class="bcc-quest-overview__header">
            <h3 class="bcc-quest-overview__title">
                <?php if ($isOwnProfile): ?>
                    Your Quest Progress
                <?php else: ?>
                    Quest Progress
                <?php endif; ?>
            </h3>
            <span class="bcc-quest-overview__count"><?php echo esc_html($completedCount); ?>/<?php echo esc_html($totalCount); ?> complete</span>
        </div>

        <div class="bcc-quest-progress-bar">
            <div class="bcc-quest-progress-bar__fill" style="width: <?php echo esc_attr($pct); ?>%"></div>
        </div>

        <div class="bcc-quest-overview__stats">
            <div class="bcc-quest-stat">
                <span class="bcc-quest-stat__value"><?php echo esc_html(number_format($multiplier, 2)); ?>x</span>
                <span class="bcc-quest-stat__label">Vote Weight Bonus</span>
            </div>
            <div class="bcc-quest-stat">
                <?php if ($endorseUnlocked): ?>
                    <span class="bcc-quest-stat__value bcc-quest-stat__value--unlocked">Unlocked</span>
                <?php else: ?>
                    <span class="bcc-quest-stat__value bcc-quest-stat__value--locked">Locked</span>
                <?php endif; ?>
                <span class="bcc-quest-stat__label">Endorsements</span>
            </div>
        </div>

        <?php if (!$endorseUnlocked && $isOwnProfile && $endorseReason): ?>
            <p class="bcc-quest-overview__hint"><?php echo esc_html($endorseReason); ?></p>
        <?php endif; ?>
    </div>

    <!-- Quest Cards by Category -->
    <?php foreach ($grouped as $category => $categoryQuests): ?>
        <div class="bcc-quest-category">
            <h4 class="bcc-quest-category__title"><?php echo esc_html($categoryLabels[$category] ?? ucfirst($category)); ?></h4>

            <div class="bcc-quest-list">
                <?php foreach ($categoryQuests as $quest):
                    $slug   = $quest['slug'];
                    $action = $questActions[$slug] ?? null;
                ?>
                    <div class="bcc-quest-card <?php echo $quest['done'] ? 'bcc-quest-card--done' : 'bcc-quest-card--pending'; ?>">
                        <div class="bcc-quest-card__status">
                            <?php if ($quest['done']): ?>
                                <span class="bcc-quest-card__check" aria-label="Completed">&#10003;</span>
                            <?php else: ?>
                                <span class="bcc-quest-card__circle" aria-label="Not completed"></span>
                            <?php endif; ?>
                        </div>
                        <div class="bcc-quest-card__content">
                            <span class="bcc-quest-card__label"><?php echo esc_html($quest['label']); ?></span>
                            <span class="bcc-quest-card__hint"><?php echo esc_html($quest['hint']); ?></span>
                            <?php if ($quest['done'] && isset($verifiedAccounts[$slug])):
                                $acct = $verifiedAccounts[$slug];
                            ?>
                                <div class="bcc-quest-card__verified">
                                    <?php if (!empty($acct['avatar'])): ?>
                                        <img class="bcc-quest-card__verified-avatar" src="<?php echo esc_url($acct['avatar']); ?>" alt="">
                                    <?php endif; ?>
                                    <span class="bcc-quest-card__verified-name">@<?php echo esc_html($acct['username']); ?></span>
                                    <?php if ($isOwnProfile): ?>
                                        <button type="button" class="bcc-quest-card__disconnect" data-disconnect="<?php echo esc_attr($slug); ?>">Disconnect</button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($isOwnProfile && !$quest['done'] && $action):
                            $isLocked = !empty($action['requires']);
                        ?>
                            <div class="bcc-quest-card__action">
                                <?php if (!empty($action['inline']) && !empty($action['action'])): ?>
                                    <button type="button"
                                        class="bcc-quest-card__btn <?php echo esc_attr($action['btnClass'] ?? ''); ?> <?php echo $isLocked ? 'bcc-quest-card__btn--locked' : ''; ?>"
                                        <?php if ($isLocked): ?>
                                            disabled
                                            title="<?php echo esc_attr($action['requires']); ?>"
                                        <?php else: ?>
                                            data-action="<?php echo esc_attr($action['action']); ?>"
                                            <?php if (!empty($action['data'])): foreach ($action['data'] as $k => $v): ?>
                                                data-<?php echo esc_attr($k); ?>="<?php echo esc_attr($v); ?>"
                                            <?php endforeach; endif; ?>
                                        <?php endif; ?>>
                                        <?php echo esc_html($action['label']); ?>
                                    </button>
                                <?php else: ?>
                                    <a href="<?php echo esc_url($action['url']); ?>"
                                       class="bcc-quest-card__btn <?php echo esc_attr($action['btnClass'] ?? ''); ?>">
                                        <?php echo esc_html($action['label']); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="bcc-quest-card__bonus">
                            +<?php echo esc_html(number_format($quest['weight_bonus'] * 100, 0)); ?>%
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<?php if ($isOwnProfile): ?>
<script>
    // Broad REST nonce — used for X-WP-Nonce on REST endpoints below.
    var bccQuestNonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
    // Action-scoped CSRF nonces for the two AJAX handlers in
    // UserLifecycleService. Tighter than wp_rest because each is bound to
    // exactly one mutation, so leaking one cannot authorise the other.
    var bccQuestShareXNonce     = <?php echo wp_json_encode(wp_create_nonce('bcc_quest_share_x')); ?>;
    var bccQuestDisconnectNonce = <?php echo wp_json_encode(wp_create_nonce('bcc_quest_disconnect')); ?>;
</script>
<?php endif; ?>

<?php if ($isOwnProfile && !empty($questActions['connect_wallet']) && !($quests['connect_wallet']['done'] ?? false)): ?>
<!-- Wallet Connect Modal (inline) -->
<div class="bcc-quest-wallet-modal" id="bcc-quest-wallet-modal">
    <div class="bcc-quest-wallet-modal__inner">
        <h3 class="bcc-quest-wallet-modal__title">Connect a Wallet</h3>
        <p class="bcc-quest-wallet-modal__desc">Sign a message with your wallet to prove ownership. No transaction or gas fees required.</p>
        <div class="bcc-quest-wallet-modal__options">
            <button type="button" class="bcc-quest-wallet-modal__option" data-chain="ethereum">
                <img src="<?php echo esc_url(BCC_TRUST_URL . 'assets/images/metamask.svg'); ?>" alt="" onerror="this.style.display='none'">
                <span>MetaMask (Ethereum)</span>
                <span class="bcc-quest-wallet-modal__arrow">&#8250;</span>
            </button>
            <button type="button" class="bcc-quest-wallet-modal__option" data-chain="solana">
                <img src="<?php echo esc_url(BCC_TRUST_URL . 'assets/images/phantom.svg'); ?>" alt="" onerror="this.style.display='none'">
                <span>Phantom (Solana)</span>
                <span class="bcc-quest-wallet-modal__arrow">&#8250;</span>
            </button>
            <button type="button" class="bcc-quest-wallet-modal__option" data-chain="cosmos">
                <img src="<?php echo esc_url(BCC_TRUST_URL . 'assets/images/keplr.svg'); ?>" alt="" onerror="this.style.display='none'">
                <span>Keplr (Cosmos)</span>
                <span class="bcc-quest-wallet-modal__arrow">&#8250;</span>
            </button>
        </div>
        <div class="bcc-quest-wallet-modal__status" id="bcc-quest-wallet-status"></div>
        <button type="button" class="bcc-quest-wallet-modal__close" id="bcc-quest-wallet-close">Cancel</button>
    </div>
</div>

<script>
(function () {
    'use strict';

    var modal     = document.getElementById('bcc-quest-wallet-modal');
    var statusEl  = document.getElementById('bcc-quest-wallet-status');
    var closeBtn  = document.getElementById('bcc-quest-wallet-close');
    if (!modal) return;

    // Open modal from quest card button
    document.querySelectorAll('[data-action="bcc-quest-connect-wallet"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            modal.classList.add('bcc-quest-wallet-modal--open');
            setStatus('', '');
        });
    });

    // Close modal
    closeBtn.addEventListener('click', function () { modal.classList.remove('bcc-quest-wallet-modal--open'); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('bcc-quest-wallet-modal--open'); });

    function setStatus(msg, type) {
        statusEl.textContent = msg;
        statusEl.className = 'bcc-quest-wallet-modal__status' + (type ? ' bcc-quest-wallet-modal__status--' + type : '');
    }

    // Chain handlers
    modal.querySelectorAll('.bcc-quest-wallet-modal__option').forEach(function (opt) {
        opt.addEventListener('click', function () {
            var chain = opt.getAttribute('data-chain');
            connectWallet(chain);
        });
    });

    function connectWallet(chain) {
        setStatus('Requesting wallet...', 'loading');

        if (chain === 'ethereum') connectEthereum();
        else if (chain === 'solana') connectSolana();
        else if (chain === 'cosmos') connectCosmos();
        else setStatus('Unsupported chain.', 'error');
    }

    function connectEthereum() {
        if (typeof window.ethereum === 'undefined') {
            setStatus('MetaMask not detected. Install it from metamask.io', 'error');
            return;
        }
        window.ethereum.request({ method: 'eth_requestAccounts' })
            .then(function (accounts) { return challengeAndVerify('ethereum', accounts[0]); })
            .catch(function (e) { setStatus(e.message || 'Connection rejected.', 'error'); });
    }

    function connectSolana() {
        if (typeof window.solana === 'undefined' || !window.solana.isPhantom) {
            setStatus('Phantom not detected. Install it from phantom.app', 'error');
            return;
        }
        window.solana.connect()
            .then(function (resp) { return challengeAndVerify('solana', resp.publicKey.toString()); })
            .catch(function (e) { setStatus(e.message || 'Connection rejected.', 'error'); });
    }

    function connectCosmos() {
        if (typeof window.keplr === 'undefined') {
            setStatus('Keplr not detected. Install it from keplr.app', 'error');
            return;
        }
        window.keplr.enable('cosmoshub-4')
            .then(function () { return window.keplr.getKey('cosmoshub-4'); })
            .then(function (key) { return challengeAndVerify('cosmos', key.bech32Address); })
            .catch(function (e) { setStatus(e.message || 'Connection rejected.', 'error'); });
    }

    function challengeAndVerify(chainSlug, address) {
        setStatus('Requesting challenge...', 'loading');

        // Step 1: Get challenge nonce from server
        var formData = new FormData();
        formData.append('action', 'bcc_wallet_challenge');
        formData.append('chain_slug', chainSlug);
        formData.append('wallet_address', address);
        formData.append('_wpnonce', (typeof bccQuestNonce !== 'undefined') ? bccQuestNonce : '');

        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success || !json.data || !json.data.message) {
                    setStatus((json.data && json.data.message) || 'Challenge failed.', 'error');
                    return;
                }
                setStatus('Please sign the message in your wallet...', 'loading');
                return signMessage(chainSlug, address, json.data.message, json.data.nonce);
            })
            .catch(function (e) { setStatus('Network error: ' + (e.message || 'unknown'), 'error'); });
    }

    function signMessage(chainSlug, address, message, nonce) {
        var signPromise;

        if (chainSlug === 'ethereum') {
            signPromise = window.ethereum.request({
                method: 'personal_sign',
                params: [message, address]
            });
        } else if (chainSlug === 'solana') {
            var encoder = new TextEncoder();
            signPromise = window.solana.signMessage(encoder.encode(message), 'utf8')
                .then(function (resp) { return btoa(String.fromCharCode.apply(null, resp.signature)); });
        } else if (chainSlug === 'cosmos') {
            signPromise = window.keplr.signArbitrary('cosmoshub-4', address, message)
                .then(function (resp) { return resp.signature; });
        } else {
            setStatus('Unsupported chain.', 'error');
            return;
        }

        signPromise.then(function (signature) {
            setStatus('Verifying signature...', 'loading');
            return verifySignature(chainSlug, address, signature, nonce);
        }).catch(function (e) {
            setStatus(e.message || 'Signing rejected.', 'error');
        });
    }

    function verifySignature(chainSlug, address, signature, nonce) {
        var formData = new FormData();
        formData.append('action', 'bcc_wallet_verify');
        formData.append('chain_slug', chainSlug);
        formData.append('wallet_address', address);
        formData.append('signature', signature);
        formData.append('nonce', nonce);
        formData.append('_wpnonce', (typeof bccQuestNonce !== 'undefined') ? bccQuestNonce : '');

        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.success) {
                    setStatus('Wallet connected! Quest complete.', 'success');
                    // Update the card visually after a short delay
                    setTimeout(function () {
                        modal.classList.remove('bcc-quest-wallet-modal--open');
                        location.reload();
                    }, 1500);
                } else {
                    setStatus((json.data && json.data.message) || 'Verification failed.', 'error');
                }
            })
            .catch(function (e) { setStatus('Network error: ' + (e.message || 'unknown'), 'error'); });
    }
})();
</script>
<?php endif; ?>

<?php if ($isOwnProfile && !($quests['share_x']['done'] ?? false)): ?>
<script>
(function () {
    'use strict';
    document.querySelectorAll('[data-action="bcc-quest-share-x"]').forEach(function (btn) {
        var phase = 'share'; // 'share' or 'confirm'
        var verifyEndpoint = btn.getAttribute('data-verify-endpoint');

        btn.addEventListener('click', function (e) {
            e.preventDefault();

            if (phase === 'share') {
                // Phase 1: Open tweet intent
                var shareText = btn.getAttribute('data-share-text') || 'Check out my profile on Blue Collar Crypto';
                var shareUrl  = btn.getAttribute('data-share-url') || window.location.href;
                var intentUrl = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(shareUrl);
                window.open(intentUrl, '_blank');

                // Switch to confirm phase
                phase = 'confirm';
                btn.textContent = 'I posted it!';
                btn.className = 'bcc-quest-card__btn bcc-quest-card__btn--confirm';

            } else {
                // Phase 2: Mark quest complete via admin-ajax (more reliable auth than REST)
                btn.disabled = true;
                btn.textContent = 'Completing...';

                var formData = new FormData();
                formData.append('action', 'bcc_quest_complete_share_x');
                // Action-scoped CSRF nonce — must match
                // check_ajax_referer('bcc_quest_share_x', '_wpnonce') server-side.
                formData.append('_wpnonce', (typeof bccQuestShareXNonce !== 'undefined') ? bccQuestShareXNonce : '');

                fetch(typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json.success) {
                        btn.textContent = 'Done!';
                        setTimeout(function () { location.reload(); }, 1000);
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'I posted it!';
                        alert((json.data && json.data.message) || 'Failed. Please try again.');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = 'I posted it!';
                });
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($isOwnProfile): ?>
<script>
(function () {
    'use strict';
    document.querySelectorAll('[data-disconnect]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var slug = btn.getAttribute('data-disconnect');
            if (!confirm('Disconnect this account? The quest will be marked incomplete.')) return;

            btn.disabled = true;
            btn.textContent = 'Disconnecting...';

            var formData = new FormData();
            formData.append('action', 'bcc_quest_disconnect');
            formData.append('slug', slug);
            // Action-scoped CSRF nonce — must match
            // check_ajax_referer('bcc_quest_disconnect', '_wpnonce') server-side.
            formData.append('_wpnonce', (typeof bccQuestDisconnectNonce !== 'undefined') ? bccQuestDisconnectNonce : '');

            fetch(typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.success) {
                    location.reload();
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Disconnect';
                    alert((json.data && json.data.message) || 'Failed to disconnect.');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Disconnect';
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($isOwnProfile && !($quests['verify_x']['done'] ?? false)): ?>
<script>
(function () {
    'use strict';
    document.querySelectorAll('[data-action="bcc-quest-verify-x"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var endpoint = btn.getAttribute('data-endpoint');
            if (!endpoint) return;

            btn.disabled = true;
            btn.textContent = 'Redirecting to X...';

            // Store current URL so the callback can redirect back to the quest tab
            document.cookie = 'bcc_x_return=' + encodeURIComponent(window.location.href) + ';path=/;max-age=600;SameSite=Lax';

            fetch(endpoint, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': (typeof bccQuestNonce !== 'undefined') ? bccQuestNonce : '' }
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.success && json.data && json.data.auth_url) {
                    window.location.href = json.data.auth_url;
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Verify X';
                    var msg = (json.data && json.data.message) || 'Could not start X verification.';
                    alert(msg + '\n\nMake sure the X app callback URL in the Developer Portal matches:\n' + window.location.origin + '/wp-json/bcc-trust/v1/x/callback');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Verify X';
                alert('Network error. Please try again.');
            });
        });
    });
})();
</script>
<?php endif; ?>
