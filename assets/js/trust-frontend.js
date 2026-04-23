/**
 * BCC Trust Engine - Frontend Interface
 * 
 * Handles:
 * - Score display for PeepSo Pages
 * - Voting (upvote/downvote)
 * - Endorsements
 * - Device fingerprinting
 * - Fraud prevention
 * - Real-time updates
 * - GitHub verification (redirect approach)
 * 
 * @version 2.3.0
 */

(function($) {
    'use strict';

    if (!$) return;

    /**
     * Debug logger — silent in production.
     * Enable via console: window.bccTrustDebug = true
     * Or via wp_add_inline_script: var bccTrustDebug = true;
     */
    function dbg() {
        if (window.bccTrustDebug) {
            console.log.apply(console, ['[bcc-trust]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    $(document).ready(function() {
        if (typeof window.bccTrust === "undefined") {
            console.error('BCC Trust: Configuration missing');
            return;
        }

        // Wait for fingerprint to be ready
        waitForFingerprint().then(() => {
            // Auto-initialize on pages with trust widgets
            $('.bcc-trust-wrapper').each(function() {
                initializeWidget($(this));
            });
        });

        // Listen for dynamically added widgets
        $(document).on('bccTrustWidgetAdded', function(e, wrapper) {
            waitForFingerprint().then(() => {
                initializeWidget($(wrapper));
            });
        });

        // Check for GitHub callback parameters (for redirect approach)
        const urlParams = new URLSearchParams(window.location.search);
        const githubVerified = urlParams.get('github_verified');
        
        if (githubVerified === 'success') {
            showMessage($('.bcc-trust-wrapper').first(), 
                '✓ GitHub account verified successfully! Your trust score has been updated.', 
                false, 5000);
            
            // Clean up URL
            const newUrl = window.location.pathname + window.location.hash;
            window.history.replaceState({}, document.title, newUrl);
        } else if (githubVerified === 'error') {
            showMessage($('.bcc-trust-wrapper').first(),
                '❌ GitHub verification failed. Please try again.',
                true, 8000);

            // Clean up URL
            const newUrl = window.location.pathname + window.location.hash;
            window.history.replaceState({}, document.title, newUrl);
        }

        // Check for X callback parameters
        const xVerified = urlParams.get('x_verified');

        if (xVerified === 'success') {
            showMessage($('.bcc-trust-wrapper').first(),
                '✓ X account verified successfully! Your trust score has been updated.',
                false, 5000);

            const newUrl = window.location.pathname + window.location.hash;
            window.history.replaceState({}, document.title, newUrl);
        } else if (xVerified === 'error') {
            showMessage($('.bcc-trust-wrapper').first(),
                '❌ X verification failed. Please try again.',
                true, 8000);

            const newUrl = window.location.pathname + window.location.hash;
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    /**
     * Wait for fingerprint to be ready
     * @returns {Promise}
     */
    function waitForFingerprint() {
        return new Promise((resolve) => {
            // If fingerprint is already available or not needed
            if (window.bccFingerprinter?.ready || !window.bccTrust.fingerprint_enabled) {
                resolve();
                return;
            }

            // Wait for fingerprint ready event
            document.addEventListener('fingerprintReady', resolve, { once: true });
            
            // Timeout after 3 seconds
            setTimeout(resolve, 3000);
        });
    }

    /**
     * Inject a write-response into the page store so all subscribers
     * (trust-header, discovery hub, etc.) update immediately from
     * authoritative server data — no re-fetch, no stale-cache risk.
     *
     * @param {number} pageId   - The page ID
     * @param {Object} respData - result.data from the write endpoint
     * @param {number} [ver]    - Version from store.nextVersion() to
     *                            prevent stale responses from overwriting
     *                            newer data.
     */
    function injectResponseIntoStore(pageId, respData, ver) {
        var store = window.bccPageStore;
        if (!store) return;

        if (store.inject && store.buildStorePayload) {
            store.inject(pageId, store.buildStorePayload(respData), ver);
        } else if (store.inject) {
            // buildStorePayload not available — build inline (should not happen)
            var s = respData.score || {};
            store.inject(pageId, {
                trust: {
                    score:         s.total_score       !== undefined ? s.total_score       : 50,
                    tier:          s.reputation_tier    || 'neutral',
                    confidence:    s.confidence_score   !== undefined ? s.confidence_score  : 0,
                    votes_up:      respData.votes_up    !== undefined ? respData.votes_up   : 0,
                    votes_down:    respData.votes_down  !== undefined ? respData.votes_down : 0,
                    unique_voters: s.unique_voters      !== undefined ? s.unique_voters     : 0,
                    endorsements:  respData.endorsement_count !== undefined ? respData.endorsement_count : 0,
                },
                viewer: null,
            }, ver);
        } else {
            // Fallback: bust with delay if inject not available
            setTimeout(function () { store.bust(pageId); }, 500);
        }
    }

    /**
     * Map page-store data (from /bcc/v1/page/{id}) to the flat shape that
     * updatePageScoreDisplay() expects.  This is the single adapter between
     * the unified endpoint and the widget renderer.
     *
     * @param {Object} data - Full page-store response
     * @returns {Object} Flat score object consumable by updatePageScoreDisplay
     */
    function mapStoreData(data) {
        var trust  = data.trust  || {};
        var viewer = data.viewer || {};
        return {
            total_score:       trust.score        || 50,
            reputation_tier:   trust.tier          || 'neutral',
            confidence_score:  trust.confidence    || 0,
            vote_count:        (trust.votes_up || 0) + (trust.votes_down || 0),
            endorsement_count: trust.endorsements || 0,
            // viewer state
            user_vote:    viewer.vote_type ? { vote_type: viewer.vote_type } : null,
            user_endorsed: viewer.has_endorsed || false,
        };
    }

    /**
     * Initialize a trust widget
     * @param {jQuery} wrapper - The widget wrapper element
     */
    function initializeWidget(wrapper) {
        const pageId = parseInt(wrapper.data('page-id') || wrapper.data('target'));

        if (!pageId || isNaN(pageId)) {
            console.error('BCC Trust: No page ID found');
            wrapper.find('.bcc-score-value').text('Error');
            wrapper.find('.bcc-status-message').text('Configuration error').css('color', '#f44336');
            return;
        }

        // Store page ID
        wrapper.data('page-id', pageId);

        // Animate SVG ring from server-rendered data-score attribute
        animateRingOnInit(wrapper);

        // Load score + user state from the shared page store (same endpoint
        // as trust-header.js — one source of truth, zero duplicate requests).
        loadPageData(pageId, wrapper);

        // Subscribe to page-store bust events so this widget refreshes
        // when trust-header.js (or any other block) mutates the same page.
        if (window.bccPageStore) {
            window.bccPageStore.subscribe(pageId, function(freshData) {
                updatePageScoreDisplay(wrapper, mapStoreData(freshData));
            });
        }
    }

    /**
     * Load page data (score + user vote) via the shared page store.
     * Uses bccPageStore.get() which deduplicates and caches automatically —
     * multiple widgets on the same page share a single request.
     *
     * @param {number} pageId - The page ID
     * @param {jQuery} wrapper - The widget wrapper element
     */
    async function loadPageData(pageId, wrapper) {
        try {
            wrapper.find('.bcc-score-value').text('Loading...');

            var store = window.bccPageStore;
            if (!store) {
                throw new Error('Page store not available');
            }

            const raw  = await store.get(pageId);
            const data = mapStoreData(raw);

            updatePageScoreDisplay(wrapper, data);
            wrapper.data('initial-score', data.total_score);

            // Apply user vote state
            if (window.bccTrust.logged_in && data.user_vote) {
                const voteType = data.user_vote.vote_type;
                const voteButton = wrapper.find(`.bcc-vote-button[data-type="${voteType}"]`);
                voteButton.addClass('active');
            }

            // Endorsement state is independent of vote state
            if (window.bccTrust.logged_in && data.user_endorsed) {
                wrapper.find('.bcc-endorse-button').addClass('revoke').text('Revoke Endorsement');
            }
        } catch (error) {
            console.error('Load error:', error);
            wrapper.find('.bcc-score-value').text('Error');
            showMessage(wrapper, 'Failed to load: ' + error.message, true);
        }
    }

    /**
     * Update page score display
     * @param {jQuery} wrapper - The widget wrapper element
     * @param {Object} data - The score data
     */
    function updatePageScoreDisplay(wrapper, data) {
        // Update main score with animation
        const currentScore = parseFloat(wrapper.find('.bcc-score-value').text());
        const newScore = parseFloat(data.total_score);

        if (!isNaN(currentScore) && !isNaN(newScore) && currentScore !== newScore) {
            animateScoreChange(wrapper, currentScore, newScore);
        } else if (!isNaN(newScore)) {
            wrapper.find('.bcc-score-value').text(Math.round(newScore));
        }

        // Animate SVG ring (stroke-dashoffset, circumference = 327)
        const ringEl = wrapper.find('.bcc-score-progress');
        if (ringEl.length && !isNaN(newScore)) {
            const circumference = 327;
            const offset = circumference - (newScore / 100) * circumference;
            ringEl[0].style.strokeDashoffset = offset;
        }

        // Update tier badge
        if (data.reputation_tier) {
            const tiers = ['elite', 'trusted', 'neutral', 'caution', 'risky'];
            const tierLabels = { elite: 'Elite', trusted: 'Trusted', neutral: 'Neutral', caution: 'Caution', risky: 'Risky' };

            // New badge
            const badge = wrapper.find('.bcc-tier-badge');
            if (badge.length) {
                tiers.forEach(t => badge.removeClass('bcc-tier-badge--' + t));
                badge.addClass('bcc-tier-badge--' + data.reputation_tier);
                // Preserve fraud indicator if present
                const fraudEl = badge.find('.bcc-fraud-indicator').detach();
                badge.text(tierLabels[data.reputation_tier] || data.reputation_tier);
                if (fraudEl.length) badge.append(fraudEl);
            }

            // Legacy label
            const tierEl = wrapper.find('.bcc-tier-label');
            if (tierEl.length) {
                tierEl.text('(' + data.reputation_tier + ')').attr('data-tier', data.reputation_tier);
            }

            // Ring stroke color
            if (ringEl.length) {
                tiers.forEach(t => ringEl.removeClass('bcc-tier-stroke--' + t));
                ringEl.addClass('bcc-tier-stroke--' + data.reputation_tier);
            }

            // Score number color
            const scoreValEl = wrapper.find('.bcc-score-value');
            tiers.forEach(t => scoreValEl.removeClass('bcc-tier-color--' + t));
            scoreValEl.addClass('bcc-tier-color--' + data.reputation_tier);

            // Confidence fill color
            const confFill = wrapper.find('.bcc-confidence-fill');
            tiers.forEach(t => confFill.removeClass('bcc-confidence-fill--' + t));
            confFill.addClass('bcc-confidence-fill--' + data.reputation_tier);
        }

        // Update confidence bar + percent
        if (data.confidence_score !== undefined) {
            const confidencePercent = Math.round(data.confidence_score * 100);
            wrapper.find('.bcc-confidence-fill').css('width', confidencePercent + '%');
            wrapper.find('.bcc-confidence-pct').text(confidencePercent + '%');
            // Legacy
            wrapper.find('.bcc-confidence-level')
                .text(confidencePercent + '% confidence')
                .attr('data-confidence', confidencePercent);
        }

        // Update counts
        if (data.vote_count !== undefined) {
            const vc = data.vote_count;
            wrapper.find('.bcc-vote-total').text(vc + ' vote' + (vc !== 1 ? 's' : ''));
            wrapper.find('.bcc-trust-counts').html(
                vc + ' vote' + (vc !== 1 ? 's' : '') +
                '<span class="bcc-trust-counts__sep">\u00b7</span>' +
                (data.endorsement_count || 0) + ' endorsement' + ((data.endorsement_count || 0) !== 1 ? 's' : '')
            );
        }
        if (data.endorsement_count !== undefined) {
            const ec = data.endorsement_count;
            wrapper.find('.bcc-endorsement-total').text(ec + ' endorsement' + (ec !== 1 ? 's' : ''));
        }

        // Update detailed scores (legacy)
        const positiveEl = wrapper.find('.bcc-positive-score .value');
        if (positiveEl.length && data.positive_score !== undefined) {
            positiveEl.text(data.positive_score.toFixed(1));
        }
        const negativeEl = wrapper.find('.bcc-negative-score .value');
        if (negativeEl.length && data.negative_score !== undefined) {
            negativeEl.text(data.negative_score.toFixed(1));
        }

        // Clear any error messages
        wrapper.find('.bcc-status-message').text('').hide();
    }

    /**
     * Animate SVG ring on widget init (called once per widget on page load)
     */
    function animateRingOnInit(wrapper) {
        const ringEl = wrapper.find('.bcc-score-progress');
        if (!ringEl.length) return;
        const score = parseFloat(ringEl.data('score'));
        if (isNaN(score)) return;
        const circumference = 327;
        const offset = circumference - (score / 100) * circumference;
        // Small delay so CSS transition fires after paint
        setTimeout(function () {
            ringEl[0].style.strokeDashoffset = offset;
        }, 100);
    }

    /**
     * Animate score change
     * @param {jQuery} wrapper - The widget wrapper element
     * @param {number} oldValue - The old score value
     * @param {number} newValue - The new score value
     */
    function animateScoreChange(wrapper, oldValue, newValue) {
        const scoreEl        = wrapper.find('.bcc-score-value');
        const ringEl         = wrapper.find('.bcc-score-progress')[0];
        const circumference  = 327;
        const duration       = 900;
        const startTime      = performance.now();

        function update(currentTime) {
            const elapsed  = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic
            const eased    = 1 - Math.pow(1 - progress, 3);
            const current  = oldValue + (newValue - oldValue) * eased;

            scoreEl.text(Math.round(current));

            if (ringEl) {
                ringEl.style.strokeDashoffset = circumference - (current / 100) * circumference;
            }

            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }

        requestAnimationFrame(update);
    }

    /**
     * Show message to user
     * @param {jQuery} wrapper - The widget wrapper element
     * @param {string} message - The message to show
     * @param {boolean} isError - Whether this is an error message
     * @param {number} duration - How long to show the message in ms
     */
    function showMessage(wrapper, message, isError = false, duration = 5000) {
        const messageEl = wrapper.find('.bcc-status-message');
        // Always use .text() to prevent XSS — never render raw HTML.
        messageEl.text(message);
        messageEl.css('color', isError ? '#f44336' : '#4caf50')
            .fadeIn(300);

        // Auto-clear after duration
        if (duration > 0) {
            setTimeout(() => {
                messageEl.fadeOut(300, function() {
                    $(this).text('').css('color', '#666').show();
                });
            }, duration);
        }
    }

    /**
     * Handle page vote action with fingerprint
     * @param {jQuery} wrapper - The widget wrapper element
     * @param {number} pageId - The page ID
     * @param {number} voteType - The vote type (1 for upvote, -1 for downvote)
     */
    async function handlePageVote(wrapper, pageId, voteType) {
        const voteButton = wrapper.find(`.bcc-vote-button[data-type="${voteType}"]`);
        const ver = window.bccPageStore && window.bccPageStore.nextVersion
            ? window.bccPageStore.nextVersion(pageId) : undefined;

        try {
            voteButton.prop('disabled', true);
            wrapper.find('.bcc-status-message').text('');

            // Get fingerprint data if available
            let fingerprintData = null;
            if (window.bccFingerprinter?.ready) {
                fingerprintData = {
                    hash: window.bccFingerprinter.fingerprint.hash,
                    timestamp: Date.now()
                };
            }


            const response = await fetch(window.bccTrust.rest_url + 'vote', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.bccTrust.nonce
                },
                body: JSON.stringify({
                    page_id: pageId,
                    vote_type: voteType,
                    fingerprint: fingerprintData
                })
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || `HTTP error ${response.status}`);
            }

            if (response.ok && result.success) {
                updatePageScoreDisplay(wrapper, result.data.score);

                // Show weight information
                const weightMsg = result.data.analysis?.weight_applied ?
                    ` (weight: ${result.data.analysis.weight_applied.toFixed(2)}x)` : '';
                showMessage(wrapper,
                    voteType === 1 ? `✓ Upvote recorded${weightMsg}!` : `✓ Downvote recorded${weightMsg}!`,
                    false, 3000);

                // Update active states
                wrapper.find('.bcc-vote-button').removeClass('active');
                voteButton.addClass('active');

                // Inject authoritative data into page store so other blocks
                // (trust-header, discovery hub) update without re-fetching.
                // This replaces bust() which could read stale cached data.
                injectResponseIntoStore(pageId, result.data, ver);

                // Trigger event for other scripts
                $(document).trigger('bccTrustVoteCast', [pageId, voteType, result]);
            } else {
                throw new Error(result.message || 'Vote failed');
            }
        } catch (error) {
            console.error('BCC Trust: Vote error', error);
            showMessage(wrapper, error.message, true);
        } finally {
            voteButton.prop('disabled', false);
        }
    }

    /**
     * Handle remove page vote
     * @param {jQuery} wrapper - The widget wrapper element
     * @param {number} pageId - The page ID
     */
    async function handleRemovePageVote(wrapper, pageId) {
        const activeButton = wrapper.find('.bcc-vote-button.active');
        const ver = window.bccPageStore && window.bccPageStore.nextVersion
            ? window.bccPageStore.nextVersion(pageId) : undefined;

        try {
            activeButton.prop('disabled', true);
            wrapper.find('.bcc-status-message').text('');

            const response = await fetch(window.bccTrust.rest_url + 'remove-vote', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.bccTrust.nonce
                },
                body: JSON.stringify({
                    page_id: pageId
                })
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || `HTTP error ${response.status}`);
            }

            if (response.ok && result.success) {
                updatePageScoreDisplay(wrapper, result.data.score);
                showMessage(wrapper, '✓ Vote removed', false, 3000);
                wrapper.find('.bcc-vote-button').removeClass('active');

                // Inject authoritative data into page store
                injectResponseIntoStore(pageId, result.data, ver);

                // Trigger event
                $(document).trigger('bccTrustVoteRemoved', [pageId, result]);
            } else {
                throw new Error(result.message || 'Remove failed');
            }
        } catch (error) {
            console.error('BCC Trust: Remove vote error', error);
            showMessage(wrapper, error.message, true);
        } finally {
            activeButton.prop('disabled', false);
        }
    }

    /**
     * Handle page endorsement with fingerprint
     * @param {jQuery} wrapper - The widget wrapper element
     * @param {number} pageId - The page ID
     */
    async function handlePageEndorsement(wrapper, pageId) {
        const endorseButton = wrapper.find('.bcc-endorse-button');
        const ver = window.bccPageStore && window.bccPageStore.nextVersion
            ? window.bccPageStore.nextVersion(pageId) : undefined;

        try {
            endorseButton.prop('disabled', true);
            wrapper.find('.bcc-status-message').text('');

            // Get fingerprint data if available
            let fingerprintData = null;
            if (window.bccFingerprinter?.ready) {
                fingerprintData = {
                    hash: window.bccFingerprinter.fingerprint.hash,
                    timestamp: Date.now()
                };
            }

            dbg('Endorsing page', {pageId: pageId, fingerprint: !!fingerprintData});

            const response = await fetch(window.bccTrust.rest_url + 'endorse', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.bccTrust.nonce
                },
                body: JSON.stringify({
                    page_id: pageId,
                    context: 'general',
                    fingerprint: fingerprintData
                })
            });

            const result = await response.json();
            dbg('Endorse response', result);

            if (!response.ok) {
                throw new Error(result.message || `HTTP error ${response.status}`);
            }

            if (response.ok && result.success) {
                updatePageScoreDisplay(wrapper, result.data.score);
                showMessage(wrapper, '✓ Endorsement added!', false, 3000);
                endorseButton.text('Revoke Endorsement').addClass('revoke');

                // Inject authoritative data into page store
                injectResponseIntoStore(pageId, result.data, ver);

                // Trigger event
                $(document).trigger('bccTrustEndorsed', [pageId, result]);
            } else {
                throw new Error(result.message || 'Endorsement failed');
            }
        } catch (error) {
            console.error('BCC Trust: Endorse error', error);
            showMessage(wrapper, error.message, true);
        } finally {
            endorseButton.prop('disabled', false);
        }
    }

    /**
     * Handle revoke page endorsement
     * @param {jQuery} wrapper - The widget wrapper element
     * @param {number} pageId - The page ID
     */
    async function handleRevokePageEndorsement(wrapper, pageId) {
        const endorseButton = wrapper.find('.bcc-endorse-button.revoke');
        const ver = window.bccPageStore && window.bccPageStore.nextVersion
            ? window.bccPageStore.nextVersion(pageId) : undefined;

        try {
            endorseButton.prop('disabled', true);
            wrapper.find('.bcc-status-message').text('');

            dbg('Revoking page endorsement', {pageId: pageId});

            const response = await fetch(window.bccTrust.rest_url + 'revoke-endorsement', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.bccTrust.nonce
                },
                body: JSON.stringify({
                    page_id: pageId,
                    context: 'general'
                })
            });

            const result = await response.json();
            dbg('Revoke response', result);

            if (!response.ok) {
                throw new Error(result.message || `HTTP error ${response.status}`);
            }

            if (response.ok && result.success) {
                updatePageScoreDisplay(wrapper, result.data.score);
                showMessage(wrapper, '✓ Endorsement revoked', false, 3000);
                endorseButton.text('⭐ Endorse Page').removeClass('revoke');

                // Inject authoritative data into page store
                injectResponseIntoStore(pageId, result.data, ver);

                // Trigger event
                $(document).trigger('bccTrustEndorsementRevoked', [pageId, result]);
            } else {
                throw new Error(result.message || 'Revoke failed');
            }
        } catch (error) {
            console.error('BCC Trust: Revoke error', error);
            showMessage(wrapper, error.message, true);
        } finally {
            endorseButton.prop('disabled', false);
        }
    }

    /**
     * Handle GitHub Connect (redirect approach - no popup)
     * @param {jQuery} wrapper - The widget wrapper element
     */
    async function handleGitHubConnect(wrapper) {
        const connectButton = wrapper.find('.bcc-github-connect');
        
        try {
            connectButton.prop('disabled', true).text('Redirecting to GitHub...');
            wrapper.find('.bcc-status-message').text('');

            dbg('Starting GitHub connection');

            const response = await fetch(window.bccTrust.rest_url + 'github/auth', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': window.bccTrust.nonce,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const result = await response.json();
            dbg('GitHub auth response', result);

            if (result.success && result.data?.auth_url) {
                // Store current page URL to return to after verification
                sessionStorage.setItem('bcc_github_return_url', window.location.href);
                
                // REDIRECT to GitHub (no popup)
                window.location.href = result.data.auth_url;
            } else {
                throw new Error(result.message || 'Failed to get GitHub auth URL');
            }
        } catch (error) {
            console.error('BCC Trust: GitHub connect error', error);
            showMessage(wrapper, 'Connection failed: ' + error.message, true, 5000);
            connectButton.prop('disabled', false).text('Connect GitHub Account');
        }
    }

    /**
     * Check GitHub connection status
     * @param {jQuery} wrapper - The widget wrapper element
     */
    async function checkGitHubStatus(wrapper) {
        try {
            const response = await fetch(window.bccTrust.rest_url + 'github/status', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': window.bccTrust.nonce,
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) return;
            
            const result = await response.json();
            
            if (result.success && result.data?.connected) {
                showMessage(wrapper, '✓ GitHub connected successfully!', false, 3000);
                // Reload to show connected state
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                // Not connected, re-enable the button
                $('.bcc-github-connect').prop('disabled', false).text('Connect GitHub Account');
            }
        } catch (error) {
            console.error('Failed to check GitHub status:', error);
            $('.bcc-github-connect').prop('disabled', false).text('Connect GitHub Account');
        }
    }

    /**
     * Handle GitHub disconnect
     * @param {jQuery} wrapper - The widget wrapper element
     */
    async function handleGitHubDisconnect(wrapper) {
        const disconnectButton = wrapper.find('.bcc-github-disconnect');
        
        if (!confirm('Are you sure you want to disconnect your GitHub account? This may affect your trust score.')) {
            return;
        }
        
        try {
            disconnectButton.prop('disabled', true).text('Disconnecting...');
            wrapper.find('.bcc-status-message').text('');

            dbg('Disconnecting GitHub');

            const response = await fetch(window.bccTrust.rest_url + 'github/disconnect', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': window.bccTrust.nonce,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const result = await response.json();
            dbg('GitHub disconnect response', result);

            if (result.success) {
                showMessage(wrapper, '✓ GitHub disconnected', false, 3000);
                
                // Reload the page after a short delay
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                throw new Error(result.message || 'Failed to disconnect GitHub');
            }
        } catch (error) {
            console.error('BCC Trust: GitHub disconnect error', error);
            showMessage(wrapper, 'Disconnect failed: ' + error.message, true, 5000);
            disconnectButton.prop('disabled', false).text('Disconnect');
        }
    }

    /**
     * Handle X Connect (redirect approach - same as GitHub)
     * @param {jQuery} wrapper - The widget wrapper element
     */
    async function handleXConnect(wrapper) {
        const connectButton = wrapper.find('.bcc-x-connect');

        try {
            connectButton.prop('disabled', true).text('Redirecting to X...');
            wrapper.find('.bcc-status-message').text('');

            const response = await fetch(window.bccTrust.rest_url + 'x/auth', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': window.bccTrust.nonce,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.data?.auth_url) {
                sessionStorage.setItem('bcc_x_return_url', window.location.href);
                window.location.href = result.data.auth_url;
            } else {
                throw new Error(result.message || 'Failed to get X auth URL');
            }
        } catch (error) {
            console.error('BCC Trust: X connect error', error);
            showMessage(wrapper, 'Connection failed: ' + error.message, true, 5000);
            connectButton.prop('disabled', false).text('Connect X');
        }
    }

    /**
     * Handle X disconnect
     * @param {jQuery} wrapper - The widget wrapper element
     */
    async function handleXDisconnect(wrapper) {
        const disconnectButton = wrapper.find('.bcc-x-disconnect');

        if (!confirm('Are you sure you want to disconnect your X account? This may affect your trust score.')) {
            return;
        }

        try {
            disconnectButton.prop('disabled', true).text('Disconnecting...');
            wrapper.find('.bcc-status-message').text('');

            const response = await fetch(window.bccTrust.rest_url + 'x/disconnect', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': window.bccTrust.nonce,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                showMessage(wrapper, '✓ X disconnected', false, 3000);
                setTimeout(() => { location.reload(); }, 1500);
            } else {
                throw new Error(result.message || 'Failed to disconnect X');
            }
        } catch (error) {
            console.error('BCC Trust: X disconnect error', error);
            showMessage(wrapper, 'Disconnect failed: ' + error.message, true, 5000);
            disconnectButton.prop('disabled', false).text('Disconnect');
        }
    }

    /**
     * Event delegation for trust widgets
     */
    $(document).on('click', '.bcc-trust-wrapper button', function(e) {
        e.preventDefault();
        
        const wrapper = $(this).closest('.bcc-trust-wrapper');
        const pageId = parseInt(wrapper.data('page-id') || wrapper.data('target'));
        const button = $(this);
        
        dbg('Button clicked', {
            pageId: pageId,
            buttonClass: button.attr('class')
        });

        if (!pageId || isNaN(pageId)) {
            showMessage(wrapper, 'Error: Page ID not found', true);
            return;
        }

        // Check login status for vote/endorse buttons only
        if (!window.bccTrust.logged_in &&
            (button.hasClass('bcc-vote-button') || button.hasClass('bcc-endorse-button'))) {
            const loginUrl = window.bccTrust.login_url || '/wp-login.php';
            const messageEl = wrapper.find('.bcc-status-message');
            messageEl.empty();
            messageEl.append(document.createTextNode('Please '));
            const link = document.createElement('a');
            link.href = loginUrl + '?redirect_to=' + encodeURIComponent(window.location.href);
            link.textContent = 'log in';
            messageEl.append(link);
            messageEl.append(document.createTextNode(' to vote'));
            messageEl.css('color', '#f44336').fadeIn(300);
            setTimeout(() => {
                messageEl.fadeOut(300, function() {
                    $(this).text('').css('color', '#666').show();
                });
            }, 5000);
            return;
        }

        // Vote buttons
        if (button.hasClass('bcc-vote-button')) {
            const voteType = parseInt(button.data('type'));
            
            // If clicking active vote, remove it
            if (button.hasClass('active')) {
                handleRemovePageVote(wrapper, pageId);
            } else {
                handlePageVote(wrapper, pageId, voteType);
            }
            return;
        }

        // Endorse/Revoke button
        if (button.hasClass('bcc-endorse-button')) {
            if (button.hasClass('revoke')) {
                handleRevokePageEndorsement(wrapper, pageId);
            } else {
                handlePageEndorsement(wrapper, pageId);
            }
            return;
        }

        // GitHub Connect button
        if (button.hasClass('bcc-github-connect')) {
            handleGitHubConnect(wrapper);
            return;
        }

        // GitHub Disconnect button
        if (button.hasClass('bcc-github-disconnect')) {
            handleGitHubDisconnect(wrapper);
            return;
        }

        // X Connect button
        if (button.hasClass('bcc-x-connect')) {
            handleXConnect(wrapper);
            return;
        }

        // X Disconnect button
        if (button.hasClass('bcc-x-disconnect')) {
            handleXDisconnect(wrapper);
            return;
        }
    });

    // Handle dynamic content loading
    $(document).on('bccTrustWidgetAdded', function(e, wrapper) {
        initializeWidget($(wrapper));
    });

})(window.jQuery);