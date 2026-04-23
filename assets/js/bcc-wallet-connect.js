/**
 * BCC Wallet Connect
 *
 * Handles wallet detection, connection, challenge signing, and verification
 * for EVM (MetaMask), Cosmos (Keplr), and Solana (Phantom) wallets.
 *
 * Depends on: bccWallet (localized via wp_localize_script)
 *   - ajaxUrl, nonce, chains[], i18n{}
 */
(function () {
    'use strict';

    if (typeof bccWallet === 'undefined') return;

    const { ajaxUrl, nonce, chains, i18n } = bccWallet;

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function safeUrl(url) {
        try { var u = new URL(url); return (u.protocol === 'https:' || u.protocol === 'http:') ? url : '#'; }
        catch (_) { return '#'; }
    }

    // ── Wallet Providers ─────────────────────────────────────────────────────

    const providers = {
        evm: {
            name: 'MetaMask',
            detect: () => typeof window.ethereum !== 'undefined' && window.ethereum.isMetaMask,
            connect: async () => {
                const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
                return accounts[0] || null;
            },
            sign: async (address, message) => {
                return window.ethereum.request({
                    method: 'personal_sign',
                    params: [message, address],
                });
            },
            getChainId: async () => {
                return window.ethereum.request({ method: 'eth_chainId' });
            },
            switchChain: async (chainIdHex) => {
                try {
                    await window.ethereum.request({
                        method: 'wallet_switchEthereumChain',
                        params: [{ chainId: chainIdHex }],
                    });
                    return true;
                } catch (e) {
                    return false;
                }
            },
        },

        cosmos: {
            name: 'Keplr',
            detect: () => typeof window.keplr !== 'undefined',
            connect: async (chainSlug) => {
                const chainId = cosmosChainId(chainSlug);
                await window.keplr.enable(chainId);
                const offlineSigner = window.keplr.getOfflineSigner(chainId);
                const accounts = await offlineSigner.getAccounts();
                return accounts[0]?.address || null;
            },
            sign: async (address, message, chainSlug) => {
                const chainId = cosmosChainId(chainSlug);
                const signDoc = {
                    chain_id: '',
                    account_number: '0',
                    sequence: '0',
                    fee: { amount: [], gas: '0' },
                    msgs: [{
                        type: 'sign/MsgSignData',
                        value: {
                            signer: address,
                            data: btoa(message),
                        },
                    }],
                    memo: '',
                };
                const result = await window.keplr.signAmino(chainId, address, signDoc);
                return JSON.stringify({
                    signature: result.signature.signature,
                    pub_key: result.signature.pub_key,
                });
            },
        },

        solana: {
            name: 'Phantom',
            detect: () => {
                // Phantom v22.4+ exposes window.phantom.solana; legacy: window.solana
                const provider = window.phantom?.solana || window.solana;
                return provider?.isPhantom === true;
            },
            _getProvider: () => window.phantom?.solana || window.solana,
            connect: async () => {
                const provider = providers.solana._getProvider();
                const resp = await provider.connect();
                return resp.publicKey.toString();
            },
            sign: async (address, message) => {
                const provider = providers.solana._getProvider();
                const encoded = new TextEncoder().encode(message);
                const result = await provider.signMessage(encoded, 'utf8');
                // Convert Uint8Array to base58
                return base58Encode(result.signature);
            },
        },
    };

    // ── Cosmos chain ID mapping ──────────────────────────────────────────────

    const cosmosChainIds = {
        cosmos: 'cosmoshub-4',
        osmosis: 'osmosis-1',
        akash: 'akashnet-2',
        juno: 'juno-1',
        stargaze: 'stargaze-1',
    };

    function cosmosChainId(slug) {
        return cosmosChainIds[slug] || slug;
    }

    // ── Base58 Encoding (for Solana signatures) ──────────────────────────────

    function base58Encode(bytes) {
        if (!(bytes instanceof Uint8Array) || bytes.length !== 64) {
            throw new Error('Invalid signature length');
        }

        const alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        let num = BigInt(0);
        for (const b of bytes) {
            num = num * 58n + BigInt(b);
        }

        // BigInt-based Base58 encoder — safe for fixed-length Ed25519 signatures (64 bytes, no leading-zero ambiguity).
        let str = '';
        while (num > 0n) {
            str = alphabet[Number(num % 58n)] + str;
            num = num / 58n;
        }

        // Preserve leading zeros
        for (const b of bytes) {
            if (b !== 0) break;
            str = '1' + str;
        }

        return str;
    }

    // ── AJAX Helper ──────────────────────────────────────────────────────────

    async function ajax(action, data = {}) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', nonce);
        for (const [k, v] of Object.entries(data)) {
            body.append(k, v);
        }

        const resp = await fetch(ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
        const json = await resp.json();

        if (!json.success) {
            throw new Error(json.data?.message || 'Request failed');
        }

        return json.data;
    }

    // ── Main Connect Flow ────────────────────────────────────────────────────

    /**
     * Full connect + verify flow.
     *
     * @param {string} chainSlug   - Chain slug from wp_bcc_chains (e.g. 'ethereum')
     * @param {number} postId      - Shadow CPT post ID
     * @param {string} walletType  - 'user', 'treasury', 'multisig', etc.
     * @param {string} label       - Optional label
     * @returns {object} Verified wallet data
     */
    async function connectAndVerify(chainSlug, postId, walletType = 'user', label = '') {
        // Find chain config
        const chain = chains.find(c => c.slug === chainSlug);
        if (!chain) throw new Error('Unknown chain: ' + chainSlug);

        const provider = providers[chain.chain_type];
        if (!provider) throw new Error('No provider for chain type: ' + chain.chain_type);

        // Step 1: Detect wallet
        if (!provider.detect()) {
            // Wallet extensions only inject on secure origins (https:// or localhost)
            if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                throw new Error(provider.name + ' requires HTTPS. Enable SSL in Local Sites.');
            }
            throw new Error(i18n.no_wallet + ': ' + provider.name);
        }

        // Step 2: Connect (get address)
        const address = await provider.connect(chainSlug);
        if (!address) throw new Error('Failed to get wallet address');

        // Step 3: Request challenge from server
        const challenge = await ajax('bcc_wallet_challenge', {
            chain_slug: chainSlug,
            wallet_address: address,
        });

        // Step 4: Sign the challenge
        const signature = await provider.sign(address, challenge.message, chainSlug);

        // Step 5: Verify on server
        const result = await ajax('bcc_wallet_verify', {
            wallet_address: address,
            signature: signature,
            post_id: postId,
            wallet_type: walletType,
            label: label,
        });

        return result;
    }

    /**
     * Disconnect a wallet.
     */
    async function disconnectWallet(walletLinkId) {
        return ajax('bcc_wallet_disconnect', { wallet_link_id: walletLinkId });
    }

    /**
     * Set a wallet as primary.
     */
    async function setPrimaryWallet(walletLinkId) {
        return ajax('bcc_wallet_set_primary', { wallet_link_id: walletLinkId });
    }

    /**
     * List all wallets for the current user.
     */
    async function listWallets() {
        const data = await ajax('bcc_wallet_list');
        return data.wallets;
    }

    // ── UI Binding ───────────────────────────────────────────────────────────

    function initUI() {
        // ── All click handling via delegation on document ────────────────
        // This ensures buttons work regardless of when data-chain is set
        // (e.g. chain-picker "Connect" buttons get data-chain dynamically).

        document.addEventListener('click', async function (e) {
            // ── 1. Ecosystem buttons that toggle a chain picker ──────────
            const ecoBtn = e.target.closest('.bcc-ecosystem-btn');
            if (ecoBtn) {
                const ecosystem = ecoBtn.closest('.bcc-ecosystem');
                const picker = ecosystem?.querySelector('.bcc-chain-picker');

                // If this ecosystem has a picker and the button has NO data-chain,
                // toggle the picker instead of connecting.
                if (picker && !ecoBtn.dataset.chain) {
                    e.preventDefault();
                    const isOpen = !picker.hidden;

                    // Close all pickers first
                    document.querySelectorAll('.bcc-chain-picker').forEach(p => {
                        p.hidden = true;
                        const pb = p.closest('.bcc-ecosystem')?.querySelector('.bcc-ecosystem-btn');
                        if (pb) pb.classList.remove('bcc-ecosystem-btn--active');
                    });

                    if (!isOpen) {
                        picker.hidden = false;
                        ecoBtn.classList.add('bcc-ecosystem-btn--active');
                    }
                    return;
                }

                // If the button HAS data-chain, fall through to the connect logic below.
            }

            // ── 2. Chain picker select change (handled separately) ───────
            // (see addEventListener('change') below)

            // ── 3. Wallet connect buttons ────────────────────────────────
            // Matches: .bcc-ecosystem-btn[data-chain] (single-chain ecosystem)
            //          .bcc-chain-picker__go (picker "Connect" button)
            //          .bcc-wallet-connect (legacy / other connect buttons)
            const btn = e.target.closest('.bcc-ecosystem-btn[data-chain], .bcc-chain-picker__go[data-chain], .bcc-wallet-connect[data-chain], .bcc-wallet-connect-btn[data-chain]');
            if (!btn || btn.disabled) return;

            e.preventDefault();
            const chainSlug  = btn.dataset.chain;
            if (!chainSlug) return;

            const postId     = btn.dataset.postId || 0;
            const walletType = btn.dataset.walletType || 'user';
            const label      = btn.dataset.label || '';

            // Find the parent ecosystem button (if any) to update its state too
            const ecosystem = btn.closest('.bcc-ecosystem');
            const parentEcoBtn = ecosystem?.querySelector('.bcc-ecosystem-btn');

            btn.disabled = true;
            const origText = btn.textContent;
            btn.textContent = i18n.signing;
            if (parentEcoBtn && parentEcoBtn !== btn) {
                parentEcoBtn.disabled = true;
            }

            try {
                const result = await connectAndVerify(chainSlug, postId, walletType, label);

                // Brief success flash, then reset so user can connect more chains
                btn.textContent = i18n.verified + ' ✓';
                btn.classList.add('bcc-wallet-verified');

                // Dispatch custom event for other components to react
                document.dispatchEvent(new CustomEvent('bcc:wallet:connected', {
                    detail: result,
                }));

                // Refresh wallet list if panel exists
                renderWalletList();

                // After 2s, reset the button so another chain can be connected
                setTimeout(() => {
                    btn.textContent = origText;
                    btn.classList.remove('bcc-wallet-verified');
                    btn.disabled = false;

                    if (parentEcoBtn && parentEcoBtn !== btn) {
                        parentEcoBtn.disabled = false;
                        parentEcoBtn.classList.remove('bcc-wallet-verified');
                    }

                    // Reset the chain picker select back to placeholder
                    if (btn.classList.contains('bcc-chain-picker__go')) {
                        const picker = btn.closest('.bcc-chain-picker');
                        const select = picker?.querySelector('.bcc-chain-picker__select');
                        if (select) select.value = '';
                        delete btn.dataset.chain;
                        btn.disabled = true;
                    }
                }, 2000);
            } catch (err) {
                if (parentEcoBtn) parentEcoBtn.classList.add('bcc-wallet-error');
                btn.classList.add('bcc-wallet-error');
                btn.textContent = err.message;

                setTimeout(() => {
                    btn.textContent = origText;
                    btn.classList.remove('bcc-wallet-error');
                    btn.disabled = false;
                    if (parentEcoBtn && parentEcoBtn !== btn) {
                        parentEcoBtn.classList.remove('bcc-wallet-error');
                        parentEcoBtn.disabled = false;
                    }
                }, 3000);
            }
        });

        // Chain picker select → enable/disable the "Connect" button & set data-chain
        document.addEventListener('change', function (e) {
            const select = e.target.closest('.bcc-chain-picker__select');
            if (!select) return;

            const picker = select.closest('.bcc-chain-picker');
            const goBtn = picker?.querySelector('.bcc-chain-picker__go');
            if (!goBtn) return;

            if (select.value) {
                goBtn.dataset.chain = select.value;
                goBtn.disabled = false;
            } else {
                delete goBtn.dataset.chain;
                goBtn.disabled = true;
            }
        });

        // Disconnect buttons (delegated)
        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('.bcc-wallet-disconnect');
            if (!btn) return;

            e.preventDefault();
            const id = btn.dataset.walletId;
            btn.disabled = true;

            try {
                await disconnectWallet(id);
                const row = btn.closest('.bcc-wallet-row');
                if (row) row.remove();
                document.dispatchEvent(new CustomEvent('bcc:wallet:disconnected', { detail: { id } }));
            } catch (err) {
                btn.disabled = false;
                alert(err.message);
            }
        });

        // Set primary buttons (delegated)
        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('.bcc-wallet-set-primary');
            if (!btn) return;

            e.preventDefault();
            const id = btn.dataset.walletId;

            try {
                await setPrimaryWallet(id);
                renderWalletList();
            } catch (err) {
                alert(err.message);
            }
        });

        // Initial render
        const panel = document.querySelector('.bcc-wallet-list-panel');
        if (panel) {
            renderWalletList();
        }
    }

    /**
     * Render the wallet list in the .bcc-wallet-list-panel container.
     */
    async function renderWalletList() {
        const panel = document.querySelector('.bcc-wallet-list-panel');
        if (!panel) return;

        try {
            const wallets = await listWallets();

            if (wallets.length === 0) {
                panel.innerHTML = '<p class="bcc-wallet-empty">No wallets connected yet.</p>';
                return;
            }

            panel.innerHTML = wallets.map(w => {
                const addr = escHtml(w.wallet_address);
                const shortAddr = escHtml(w.wallet_address.slice(0, 6) + '…' + w.wallet_address.slice(-4));
                const explorerLink = w.explorer_url
                    ? `<a href="${safeUrl(w.explorer_url + '/address/' + w.wallet_address)}" target="_blank" rel="noopener">${shortAddr}</a>`
                    : shortAddr;
                const primaryBadge = w.is_primary ? '<span class="bcc-badge bcc-badge-primary">Primary</span>' : '';
                const typeBadge = w.wallet_type !== 'user' ? `<span class="bcc-badge bcc-badge-type">${escHtml(w.wallet_type)}</span>` : '';
                const verifiedBadge = w.verified ? '<span class="bcc-badge bcc-badge-verified">Verified</span>' : '';

                return `
                    <div class="bcc-wallet-row" data-wallet-id="${escHtml(w.id)}">
                        <div class="bcc-wallet-info">
                            <span class="bcc-wallet-chain">${escHtml(w.chain_name)}</span>
                            <span class="bcc-wallet-address">${explorerLink}</span>
                            ${primaryBadge}${typeBadge}${verifiedBadge}
                            ${w.label ? `<span class="bcc-wallet-label">${escHtml(w.label)}</span>` : ''}
                        </div>
                        <div class="bcc-wallet-actions">
                            ${!w.is_primary ? `<button class="bcc-wallet-set-primary" data-wallet-id="${escHtml(w.id)}">Set Primary</button>` : ''}
                            <button class="bcc-wallet-disconnect" data-wallet-id="${escHtml(w.id)}">${escHtml(i18n.disconnect)}</button>
                        </div>
                    </div>
                `;
            }).join('');

        } catch (err) {
            panel.innerHTML = `<p class="bcc-wallet-error">${escHtml(err.message)}</p>`;
        }
    }

    // ── Expose API ───────────────────────────────────────────────────────────

    window.BCCWallet = {
        connectAndVerify,
        disconnectWallet,
        setPrimaryWallet,
        listWallets,
        providers,
        chains,
    };

    // ── Init ─────────────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUI);
    } else {
        initUI();
    }

})();
