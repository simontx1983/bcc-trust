<?php
/**
 * BCC Landing Page Template
 *
 * Full-width landing page explaining the Blue Collar Crypto trust platform.
 * Usage: Include via shortcode [bcc_landing_page] or as a page template.
 *
 * @package BCC_Trust_Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

// Gather live stats if available
$stats = [
    'users'       => 0,
    'votes'       => 0,
    'pages'       => 0,
    'wallets'     => 0,
];

if (class_exists('\\BCC\\Trust\\Core\\Plugin')) {
    try {
        $plugin = \BCC\Trust\Core\Plugin::instance();
        $dash   = $plugin->adminDashboardRepository();
        if ($dash && method_exists($dash, 'getHeadlineStats')) {
            $live = $dash->getHeadlineStats();
            $stats['users'] = (int) ($live['tracked_users'] ?? 0);
            $stats['votes'] = (int) ($live['total_votes'] ?? 0);
            $stats['pages'] = (int) ($live['tracked_pages'] ?? 0);
        }
    } catch (\Throwable $e) {
        // Degrade to zeros but log: silent dashboard failures mask real
        // outages (broken DB handle, suspended plugin) from operators.
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] landing-page stats query failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

$register_url = function_exists('PeepSo') ? PeepSo::get_page('register') : wp_registration_url();
$discover_url = home_url('/discover/');
?>

<div class="bcc-landing" id="bcc-landing">

<style>
/* ═══════════════════════════════════════════════════════════════
   BCC LANDING PAGE — "FORGE" DESIGN
   Dark industrial aesthetic with precision craft details.
   ═══════════════════════════════════════════════════════════════ */

@import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;500;600;700;800&display=swap');

.bcc-landing {
    --bg:              #08090d;
    --bg-raised:       #0e1015;
    --bg-card:         #12141c;
    --bg-card-glow:    #181b25;
    --border:          rgba(255,255,255,0.05);
    --border-lit:      rgba(255,255,255,0.1);
    --text:            #dfe3ed;
    --text-dim:        #7a8299;
    --text-faint:      #454d63;
    --accent:          #3b82f6;
    --accent-glow:     rgba(59,130,246,0.25);
    --emerald:         #34d399;
    --emerald-glow:    rgba(52,211,153,0.15);
    --amber:           #fbbf24;
    --rose:            #f87171;
    --violet:          #a78bfa;
    --font:            'Outfit', -apple-system, sans-serif;
    --mono:            'Space Mono', 'JetBrains Mono', monospace;
    --ease:            cubic-bezier(0.16, 1, 0.3, 1);

    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
    position: relative;
}

.bcc-landing *, .bcc-landing *::before, .bcc-landing *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

/* ── NOISE TEXTURE ──────────────────────────────────────── */
.bcc-landing::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 0;
}

.bcc-landing > * {
    position: relative;
    z-index: 1;
}

/* ── SECTION BASE ───────────────────────────────────────── */
.bcc-section {
    max-width: 1140px;
    margin: 0 auto;
    padding: 100px 24px;
}

.bcc-section--wide {
    max-width: 1320px;
}

/* ═══════════════════════════════════════════════════════════
   HERO
   ═══════════════════════════════════════════════════════════ */
.bcc-hero {
    min-height: 90vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 120px 24px 80px;
    position: relative;
}

/* Radial gradient orb behind hero */
.bcc-hero::after {
    content: '';
    position: absolute;
    top: 15%;
    left: 50%;
    transform: translateX(-50%);
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, rgba(59,130,246,0.08) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
    filter: blur(60px);
}

.bcc-hero__badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(59,130,246,0.08);
    border: 1px solid rgba(59,130,246,0.15);
    border-radius: 999px;
    padding: 7px 18px;
    font-size: 0.78rem;
    font-weight: 500;
    color: var(--accent);
    letter-spacing: 0.03em;
    margin-bottom: 32px;
    animation: bcc-fade-up 0.8s var(--ease) both;
}

.bcc-hero__badge::before {
    content: '';
    width: 7px;
    height: 7px;
    background: var(--emerald);
    border-radius: 50%;
    box-shadow: 0 0 8px var(--emerald-glow);
    animation: bcc-pulse 2s infinite;
}

.bcc-hero__title {
    font-family: var(--font);
    font-size: clamp(2.4rem, 6vw, 4.5rem);
    font-weight: 800;
    line-height: 1.05;
    letter-spacing: -0.04em;
    max-width: 800px;
    animation: bcc-fade-up 0.8s var(--ease) 0.1s both;
}

.bcc-hero__title span {
    background: linear-gradient(135deg, var(--accent) 0%, var(--violet) 50%, var(--emerald) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.bcc-hero__sub {
    font-size: clamp(1rem, 2vw, 1.2rem);
    font-weight: 400;
    color: var(--text-dim);
    max-width: 560px;
    margin-top: 20px;
    animation: bcc-fade-up 0.8s var(--ease) 0.2s both;
}

.bcc-hero__actions {
    display: flex;
    gap: 14px;
    margin-top: 40px;
    flex-wrap: wrap;
    justify-content: center;
    animation: bcc-fade-up 0.8s var(--ease) 0.3s both;
}

/* ── BUTTONS ────────────────────────────────────────────── */
.bcc-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 30px;
    border-radius: 10px;
    font-family: var(--font);
    font-size: 0.92rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s var(--ease);
    cursor: pointer;
    border: none;
}

.bcc-btn--primary {
    background: var(--accent);
    color: #fff;
    box-shadow: 0 4px 20px var(--accent-glow), inset 0 1px 0 rgba(255,255,255,0.1);
}

.bcc-btn--primary:hover {
    background: #2563eb;
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(59,130,246,0.35);
}

.bcc-btn--ghost {
    background: transparent;
    color: var(--text);
    border: 1px solid var(--border-lit);
}

.bcc-btn--ghost:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: rgba(59,130,246,0.04);
}

/* ── TRUST RING (Hero) ──────────────────────────────────── */
.bcc-hero__ring {
    width: 140px;
    height: 140px;
    margin-bottom: 36px;
    animation: bcc-fade-up 0.8s var(--ease) both;
    position: relative;
}

.bcc-hero__ring svg {
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
    filter: drop-shadow(0 0 20px var(--emerald-glow));
}

.bcc-hero__ring circle.bcc-ring-track {
    fill: none;
    stroke: rgba(255,255,255,0.04);
    stroke-width: 3;
}

.bcc-hero__ring circle.bcc-ring-fill {
    fill: none;
    stroke: url(#ring-gradient);
    stroke-width: 3;
    stroke-linecap: round;
    stroke-dasharray: 0 339.292;
    animation: bcc-ring-draw 1.8s var(--ease) 0.5s forwards;
}

.bcc-hero__ring-label {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-family: var(--mono);
}

.bcc-hero__ring-score {
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: -0.04em;
    color: var(--text);
}

.bcc-hero__ring-text {
    font-size: 0.6rem;
    font-weight: 400;
    color: var(--text-faint);
    text-transform: uppercase;
    letter-spacing: 0.15em;
}

/* ═══════════════════════════════════════════════════════════
   LIVE STATS BAR
   ═══════════════════════════════════════════════════════════ */
.bcc-stats-bar {
    display: flex;
    justify-content: center;
    gap: 48px;
    padding: 0 24px 60px;
    animation: bcc-fade-up 0.8s var(--ease) 0.5s both;
    flex-wrap: wrap;
}

.bcc-stats-bar__item {
    text-align: center;
}

.bcc-stats-bar__value {
    font-family: var(--mono);
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.02em;
}

.bcc-stats-bar__label {
    font-size: 0.7rem;
    font-weight: 500;
    color: var(--text-faint);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-top: 2px;
}

/* ═══════════════════════════════════════════════════════════
   HOW IT WORKS — 3-step flow
   ═══════════════════════════════════════════════════════════ */
.bcc-how {
    background: var(--bg-raised);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.bcc-how__header {
    text-align: center;
    margin-bottom: 64px;
}

.bcc-how__tag {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin-bottom: 12px;
}

.bcc-how__title {
    font-size: clamp(1.6rem, 3.5vw, 2.4rem);
    font-weight: 700;
    letter-spacing: -0.03em;
}

.bcc-steps {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 32px;
    position: relative;
}

/* Connector line */
.bcc-steps::before {
    content: '';
    position: absolute;
    top: 48px;
    left: 16.67%;
    right: 16.67%;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--border-lit) 20%, var(--border-lit) 80%, transparent);
}

.bcc-step {
    text-align: center;
    padding: 0 16px;
}

.bcc-step__icon {
    width: 96px;
    height: 96px;
    margin: 0 auto 24px;
    background: var(--bg-card);
    border: 1px solid var(--border-lit);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.4rem;
    position: relative;
    transition: border-color 0.3s var(--ease), box-shadow 0.3s var(--ease);
}

.bcc-step:hover .bcc-step__icon {
    border-color: var(--accent);
    box-shadow: 0 0 30px var(--accent-glow);
}

.bcc-step__num {
    position: absolute;
    top: -8px;
    right: -8px;
    width: 24px;
    height: 24px;
    background: var(--accent);
    border-radius: 50%;
    font-size: 0.68rem;
    font-weight: 700;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px var(--accent-glow);
}

.bcc-step__title {
    font-size: 1.05rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.bcc-step__desc {
    font-size: 0.88rem;
    color: var(--text-dim);
    line-height: 1.6;
}

/* ═══════════════════════════════════════════════════════════
   FEATURES GRID
   ═══════════════════════════════════════════════════════════ */
.bcc-features {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.bcc-feature {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 32px 28px;
    transition: border-color 0.25s var(--ease), transform 0.25s var(--ease);
    position: relative;
    overflow: hidden;
}

.bcc-feature::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
    opacity: 0;
    transition: opacity 0.3s var(--ease);
}

.bcc-feature:hover {
    border-color: var(--border-lit);
    transform: translateY(-3px);
}

.bcc-feature:hover::before {
    opacity: 1;
}

.bcc-feature__icon {
    font-size: 1.8rem;
    margin-bottom: 16px;
    display: block;
}

.bcc-feature__title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.bcc-feature__desc {
    font-size: 0.85rem;
    color: var(--text-dim);
    line-height: 1.65;
}

/* Color accents per feature */
.bcc-feature--trust .bcc-feature__icon { color: var(--emerald); }
.bcc-feature--vote .bcc-feature__icon { color: var(--accent); }
.bcc-feature--wallet .bcc-feature__icon { color: var(--violet); }
.bcc-feature--fraud .bcc-feature__icon { color: var(--rose); }
.bcc-feature--dispute .bcc-feature__icon { color: var(--amber); }
.bcc-feature--search .bcc-feature__icon { color: var(--accent); }

/* ═══════════════════════════════════════════════════════════
   TIERS SECTION
   ═══════════════════════════════════════════════════════════ */
.bcc-tiers__header {
    text-align: center;
    margin-bottom: 56px;
}

.bcc-tiers__tag {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--emerald);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin-bottom: 12px;
}

.bcc-tiers__title {
    font-size: clamp(1.6rem, 3.5vw, 2.4rem);
    font-weight: 700;
    letter-spacing: -0.03em;
}

.bcc-tiers-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
}

.bcc-tier {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 28px 20px;
    text-align: center;
    transition: transform 0.25s var(--ease), border-color 0.25s var(--ease);
}

.bcc-tier:hover {
    transform: translateY(-4px);
}

.bcc-tier__badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 14px;
}

.bcc-tier--risky .bcc-tier__badge   { background: rgba(248,113,113,0.12); color: var(--rose); border: 1px solid rgba(248,113,113,0.2); }
.bcc-tier--caution .bcc-tier__badge { background: rgba(251,191,36,0.1); color: var(--amber); border: 1px solid rgba(251,191,36,0.2); }
.bcc-tier--neutral .bcc-tier__badge { background: rgba(96,165,250,0.1); color: #60a5fa; border: 1px solid rgba(96,165,250,0.2); }
.bcc-tier--trusted .bcc-tier__badge { background: rgba(52,211,153,0.1); color: var(--emerald); border: 1px solid rgba(52,211,153,0.2); }
.bcc-tier--elite .bcc-tier__badge   { background: rgba(167,139,250,0.1); color: var(--violet); border: 1px solid rgba(167,139,250,0.2); }

.bcc-tier:hover { border-color: var(--border-lit); }
.bcc-tier--risky:hover   { border-color: rgba(248,113,113,0.25); }
.bcc-tier--caution:hover { border-color: rgba(251,191,36,0.25); }
.bcc-tier--neutral:hover { border-color: rgba(96,165,250,0.25); }
.bcc-tier--trusted:hover { border-color: rgba(52,211,153,0.25); }
.bcc-tier--elite:hover   { border-color: rgba(167,139,250,0.25); }

.bcc-tier__range {
    font-family: var(--mono);
    font-size: 0.75rem;
    color: var(--text-faint);
    margin-bottom: 10px;
}

.bcc-tier__desc {
    font-size: 0.8rem;
    color: var(--text-dim);
    line-height: 1.5;
}

.bcc-tier__perk {
    font-size: 0.72rem;
    color: var(--text-faint);
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

/* ═══════════════════════════════════════════════════════════
   CTA SECTION
   ═══════════════════════════════════════════════════════════ */
.bcc-cta {
    text-align: center;
    padding: 100px 24px 120px;
    position: relative;
}

.bcc-cta::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 500px;
    height: 400px;
    background: radial-gradient(circle, rgba(52,211,153,0.06) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
    filter: blur(50px);
}

.bcc-cta__title {
    font-size: clamp(1.8rem, 4vw, 3rem);
    font-weight: 800;
    letter-spacing: -0.04em;
    margin-bottom: 16px;
}

.bcc-cta__sub {
    font-size: 1.05rem;
    color: var(--text-dim);
    max-width: 500px;
    margin: 0 auto 36px;
}

/* ═══════════════════════════════════════════════════════════
   FOOTER
   ═══════════════════════════════════════════════════════════ */
.bcc-footer {
    text-align: center;
    padding: 32px 24px;
    border-top: 1px solid var(--border);
    font-size: 0.78rem;
    color: var(--text-faint);
}

/* ═══════════════════════════════════════════════════════════
   ANIMATIONS
   ═══════════════════════════════════════════════════════════ */
@keyframes bcc-fade-up {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes bcc-pulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.4; }
}

@keyframes bcc-ring-draw {
    to { stroke-dasharray: 265 339.292; }
}

/* Scroll reveal */
.bcc-reveal {
    opacity: 0;
    transform: translateY(24px);
    transition: opacity 0.7s var(--ease), transform 0.7s var(--ease);
}

.bcc-reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

/* Stagger children */
.bcc-reveal-stagger > .bcc-reveal:nth-child(1) { transition-delay: 0s; }
.bcc-reveal-stagger > .bcc-reveal:nth-child(2) { transition-delay: 0.08s; }
.bcc-reveal-stagger > .bcc-reveal:nth-child(3) { transition-delay: 0.16s; }
.bcc-reveal-stagger > .bcc-reveal:nth-child(4) { transition-delay: 0.24s; }
.bcc-reveal-stagger > .bcc-reveal:nth-child(5) { transition-delay: 0.32s; }
.bcc-reveal-stagger > .bcc-reveal:nth-child(6) { transition-delay: 0.40s; }

@media (prefers-reduced-motion: reduce) {
    .bcc-landing * {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
    .bcc-reveal { opacity: 1; transform: none; }
}

/* ═══════════════════════════════════════════════════════════
   RESPONSIVE
   ═══════════════════════════════════════════════════════════ */
@media (max-width: 960px) {
    .bcc-steps          { grid-template-columns: 1fr; gap: 40px; }
    .bcc-steps::before  { display: none; }
    .bcc-features       { grid-template-columns: 1fr 1fr; }
    .bcc-tiers-grid     { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 640px) {
    .bcc-section        { padding: 64px 16px; }
    .bcc-hero           { padding: 80px 16px 48px; min-height: 80vh; }
    .bcc-features       { grid-template-columns: 1fr; }
    .bcc-tiers-grid     { grid-template-columns: 1fr 1fr; }
    .bcc-stats-bar      { gap: 24px; }
    .bcc-hero__actions   { flex-direction: column; width: 100%; }
    .bcc-btn            { justify-content: center; width: 100%; }
}
</style>

<!-- SVG Defs (gradient for trust ring) -->
<svg width="0" height="0" aria-hidden="true">
    <defs>
        <linearGradient id="ring-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#3b82f6" />
            <stop offset="50%" stop-color="#a78bfa" />
            <stop offset="100%" stop-color="#34d399" />
        </linearGradient>
    </defs>
</svg>

<!-- ═══════════════════════ HERO ═══════════════════════ -->
<section class="bcc-hero">
    <div class="bcc-hero__ring">
        <svg viewBox="0 0 120 120">
            <circle class="bcc-ring-track" cx="60" cy="60" r="54" />
            <circle class="bcc-ring-fill"  cx="60" cy="60" r="54" />
        </svg>
        <div class="bcc-hero__ring-label">
            <span class="bcc-hero__ring-score">78</span>
            <span class="bcc-hero__ring-text">Trust</span>
        </div>
    </div>

    <div class="bcc-hero__badge">Reputation-first crypto community</div>

    <h1 class="bcc-hero__title">
        Build trust,<br><span>not hype.</span>
    </h1>

    <p class="bcc-hero__sub">
        Blue Collar Crypto is where validators, builders, and creators earn reputation through verified on-chain activity &mdash; not followers.
    </p>

    <div class="bcc-hero__actions">
        <a href="<?php echo esc_url($register_url); ?>" class="bcc-btn bcc-btn--primary">
            Join the community
        </a>
        <a href="<?php echo esc_url($discover_url); ?>" class="bcc-btn bcc-btn--ghost">
            Explore projects
        </a>
    </div>
</section>

<!-- ═══════════════════ LIVE STATS ═══════════════════ -->
<?php if ($stats['users'] > 0): ?>
<div class="bcc-stats-bar">
    <div class="bcc-stats-bar__item">
        <div class="bcc-stats-bar__value"><?php echo number_format($stats['users']); ?></div>
        <div class="bcc-stats-bar__label">Members</div>
    </div>
    <div class="bcc-stats-bar__item">
        <div class="bcc-stats-bar__value"><?php echo number_format($stats['votes']); ?></div>
        <div class="bcc-stats-bar__label">Votes Cast</div>
    </div>
    <div class="bcc-stats-bar__item">
        <div class="bcc-stats-bar__value"><?php echo number_format($stats['pages']); ?></div>
        <div class="bcc-stats-bar__label">Projects</div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════ HOW IT WORKS ═══════════════════ -->
<section class="bcc-how">
    <div class="bcc-section">
        <div class="bcc-how__header bcc-reveal">
            <div class="bcc-how__tag">How it works</div>
            <h2 class="bcc-how__title">Three steps to verifiable reputation</h2>
        </div>

        <div class="bcc-steps bcc-reveal-stagger">
            <div class="bcc-step bcc-reveal">
                <div class="bcc-step__icon">
                    <span class="bcc-step__num">1</span>
                    &#x1f517;
                </div>
                <h3 class="bcc-step__title">Connect your wallets</h3>
                <p class="bcc-step__desc">Link your EVM, Solana, and Cosmos wallets. Signature verification proves ownership &mdash; no private keys shared.</p>
            </div>

            <div class="bcc-step bcc-reveal">
                <div class="bcc-step__icon">
                    <span class="bcc-step__num">2</span>
                    &#x2692;
                </div>
                <h3 class="bcc-step__title">Build your profile</h3>
                <p class="bcc-step__desc">On-chain signals &mdash; wallet age, transaction depth, validator status, NFT collections &mdash; feed into your trust score automatically.</p>
            </div>

            <div class="bcc-step bcc-reveal">
                <div class="bcc-step__icon">
                    <span class="bcc-step__num">3</span>
                    &#x1f3af;
                </div>
                <h3 class="bcc-step__title">Earn reputation</h3>
                <p class="bcc-step__desc">Community members vote and endorse your work. Consistent quality earns you trust tiers &mdash; from Neutral to Elite.</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════ FEATURES ═══════════════════ -->
<section class="bcc-section">
    <div class="bcc-how__header bcc-reveal">
        <div class="bcc-how__tag">Platform Features</div>
        <h2 class="bcc-how__title">Everything a reputation system needs</h2>
    </div>

    <div class="bcc-features bcc-reveal-stagger">
        <div class="bcc-feature bcc-feature--trust bcc-reveal">
            <span class="bcc-feature__icon">&#x1f6e1;</span>
            <h3 class="bcc-feature__title">Trust Scoring</h3>
            <p class="bcc-feature__desc">Composite score from votes, endorsements, on-chain activity, and behavioral analysis. Single source of truth &mdash; no gaming.</p>
        </div>

        <div class="bcc-feature bcc-feature--vote bcc-reveal">
            <span class="bcc-feature__icon">&#x1f5f3;</span>
            <h3 class="bcc-feature__title">Community Voting</h3>
            <p class="bcc-feature__desc">Weighted votes based on voter reputation. Higher-tier members have more influence. Fraud detection flags suspicious patterns.</p>
        </div>

        <div class="bcc-feature bcc-feature--wallet bcc-reveal">
            <span class="bcc-feature__icon">&#x1f4b0;</span>
            <h3 class="bcc-feature__title">Wallet Verification</h3>
            <p class="bcc-feature__desc">Prove ownership of EVM, Solana, and Cosmos wallets. On-chain data &mdash; age, transactions, validator status &mdash; enriches your profile.</p>
        </div>

        <div class="bcc-feature bcc-feature--fraud bcc-reveal">
            <span class="bcc-feature__icon">&#x1f6a8;</span>
            <h3 class="bcc-feature__title">Fraud Detection</h3>
            <p class="bcc-feature__desc">Behavioral analysis, vote ring detection, device fingerprinting, and velocity limits protect the community from manipulation.</p>
        </div>

        <div class="bcc-feature bcc-feature--dispute bcc-reveal">
            <span class="bcc-feature__icon">&#x2696;</span>
            <h3 class="bcc-feature__title">Dispute Resolution</h3>
            <p class="bcc-feature__desc">Challenge unfair votes through panel-based adjudication. Independent panelists review evidence and vote on outcomes.</p>
        </div>

        <div class="bcc-feature bcc-feature--search bcc-reveal">
            <span class="bcc-feature__icon">&#x1f50d;</span>
            <h3 class="bcc-feature__title">Project Discovery</h3>
            <p class="bcc-feature__desc">Find builders, validators, and NFT creators ranked by trust score. Filter by category, chain, or verification status.</p>
        </div>
    </div>
</section>

<!-- ═══════════════════ TRUST TIERS ═══════════════════ -->
<section class="bcc-section bcc-section--wide">
    <div class="bcc-tiers__header bcc-reveal">
        <div class="bcc-tiers__tag">Reputation Tiers</div>
        <h2 class="bcc-tiers__title">Rise through the ranks</h2>
    </div>

    <div class="bcc-tiers-grid bcc-reveal-stagger">
        <div class="bcc-tier bcc-tier--risky bcc-reveal">
            <div class="bcc-tier__badge">Risky</div>
            <div class="bcc-tier__range">&lt; 30 pts</div>
            <p class="bcc-tier__desc">New or flagged accounts. Restricted voting weight.</p>
            <div class="bcc-tier__perk">0.4x vote weight</div>
        </div>

        <div class="bcc-tier bcc-tier--caution bcc-reveal">
            <div class="bcc-tier__badge">Caution</div>
            <div class="bcc-tier__range">30 &ndash; 44 pts</div>
            <p class="bcc-tier__desc">Building history. Reduced influence until consistency proven.</p>
            <div class="bcc-tier__perk">0.7x vote weight</div>
        </div>

        <div class="bcc-tier bcc-tier--neutral bcc-reveal">
            <div class="bcc-tier__badge">Neutral</div>
            <div class="bcc-tier__range">45 &ndash; 64 pts</div>
            <p class="bcc-tier__desc">Baseline reputation. Standard community participation.</p>
            <div class="bcc-tier__perk">1.0x vote weight</div>
        </div>

        <div class="bcc-tier bcc-tier--trusted bcc-reveal">
            <div class="bcc-tier__badge">Trusted</div>
            <div class="bcc-tier__range">65 &ndash; 79 pts</div>
            <p class="bcc-tier__desc">Proven contributor. Eligible to serve as dispute panelist.</p>
            <div class="bcc-tier__perk">1.2x vote weight + panelist</div>
        </div>

        <div class="bcc-tier bcc-tier--elite bcc-reveal">
            <div class="bcc-tier__badge">Elite</div>
            <div class="bcc-tier__range">&ge; 80 pts</div>
            <p class="bcc-tier__desc">Top reputation. Maximum influence and platform privileges.</p>
            <div class="bcc-tier__perk">1.5x vote weight + panelist</div>
        </div>
    </div>
</section>

<!-- ═══════════════════ CTA ═══════════════════ -->
<section class="bcc-cta bcc-reveal">
    <h2 class="bcc-cta__title">Your reputation starts here.</h2>
    <p class="bcc-cta__sub">
        Connect your wallets, verify your work, and let the community decide who&rsquo;s real.
    </p>
    <div class="bcc-hero__actions">
        <a href="<?php echo esc_url($register_url); ?>" class="bcc-btn bcc-btn--primary">
            Create your profile
        </a>
    </div>
</section>

<!-- ═══════════════════ FOOTER ═══════════════════ -->
<div class="bcc-footer">
    Blue Collar Crypto &mdash; reputation you can verify.
</div>

<!-- ═══════════════════ SCROLL REVEAL JS ═══════════════════ -->
<script>
(function() {
    'use strict';
    var els = document.querySelectorAll('.bcc-reveal');
    if (!els.length || !('IntersectionObserver' in window)) {
        els.forEach(function(el) { el.classList.add('is-visible'); });
        return;
    }
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    els.forEach(function(el) { observer.observe(el); });
})();
</script>

</div><!-- .bcc-landing -->