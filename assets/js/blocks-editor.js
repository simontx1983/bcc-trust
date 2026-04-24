/**
 * BCC Trust — Gutenberg editor registration.
 *
 * WordPress's auto-generation of a ServerSideRender edit for
 * block.json-only dynamic blocks wasn't firing reliably in this
 * environment, so every BCC block is registered explicitly here with
 * a ServerSideRender preview. The attributes schema still comes from
 * each block's block.json (loaded via the REST block-types endpoint),
 * so this JS only owns the edit UI.
 */
(function (wp) {
    if (!wp || !wp.blocks) return;

    var registerBlockType      = wp.blocks.registerBlockType;
    var registerBlockVariation = wp.blocks.registerBlockVariation;
    var getBlockType           = wp.blocks.getBlockType;
    var createElement          = wp.element.createElement;
    var Fragment               = wp.element.Fragment;
    var InspectorControls      = wp.blockEditor.InspectorControls;
    var useBlockProps          = wp.blockEditor.useBlockProps;
    var PanelBody              = wp.components.PanelBody;
    var TextControl            = wp.components.TextControl;
    var ToggleControl          = wp.components.ToggleControl;
    var SelectControl          = wp.components.SelectControl;
    var ServerSideRender       = wp.serverSideRender;

    /**
     * Make a registerBlockType config that wraps a ServerSideRender edit.
     * Optional `inspector` callback receives (attrs, setAttrs) and returns
     * the InspectorControls children for that block.
     */
    function ssrBlock(name, inspector) {
        return {
            edit: function (props) {
                var blockProps = useBlockProps();
                var children = [];
                if (typeof inspector === 'function') {
                    var controls = inspector(props.attributes, props.setAttributes);
                    if (controls) {
                        children.push(
                            createElement(InspectorControls, { key: 'bcc-inspector' },
                                createElement(PanelBody, { title: 'Settings', initialOpen: true }, controls)
                            )
                        );
                    }
                }
                children.push(
                    createElement('div', Object.assign({ key: 'bcc-ssr' }, blockProps),
                        createElement(ServerSideRender, {
                            block: name,
                            attributes: props.attributes,
                        })
                    )
                );
                return createElement(Fragment, null, children);
            },
            save: function () { return null; },
        };
    }

    /**
     * Safely register a block only if it was registered server-side
     * (i.e. exposed via REST) AND hasn't already been registered on
     * the client. Avoids "already registered" console warnings when
     * auto-registration does happen to fire.
     */
    function tryRegister(name, config) {
        if (getBlockType && getBlockType(name)) return; // already registered (auto)
        try {
            registerBlockType(name, config);
        } catch (e) {
            /* ignore — duplicate or invalid metadata */
        }
    }

    /* ── Page-ID inspector — shared by many profile-bound blocks ── */
    function pageIdInspector(attrs, setAttrs) {
        return createElement(TextControl, {
            label: 'Page ID',
            help: 'Leave 0 to auto-detect from the current page.',
            value: String(attrs.pageId || ''),
            onChange: function (v) { setAttrs({ pageId: parseInt(v, 10) || 0 }); },
        });
    }

    /* ── bcc-trust namespace ─────────────────────────────────── */
    tryRegister('bcc-trust/builder-card', ssrBlock('bcc-trust/builder-card', function (attrs, setAttrs) {
        return [
            pageIdInspector(attrs, setAttrs),
            createElement(ToggleControl, {
                key: 'show-score',
                label: 'Show score',
                checked: attrs.showScore !== false,
                onChange: function (v) { setAttrs({ showScore: !!v }); },
            }),
            createElement(ToggleControl, {
                key: 'show-verif',
                label: 'Show verifications',
                checked: attrs.showVerifications !== false,
                onChange: function (v) { setAttrs({ showVerifications: !!v }); },
            }),
        ];
    }));

    tryRegister('bcc-trust/wallet-verification',    ssrBlock('bcc-trust/wallet-verification',    pageIdInspector));
    tryRegister('bcc-trust/trust-signals',          ssrBlock('bcc-trust/trust-signals',          pageIdInspector));
    tryRegister('bcc-trust/on-chain-signals',       ssrBlock('bcc-trust/on-chain-signals',       pageIdInspector));
    tryRegister('bcc-trust/verification-badges',    ssrBlock('bcc-trust/verification-badges',    pageIdInspector));
    tryRegister('bcc-trust/trust-breakdown',        ssrBlock('bcc-trust/trust-breakdown',        pageIdInspector));
    tryRegister('bcc-trust/trust-dashboard',        ssrBlock('bcc-trust/trust-dashboard',        pageIdInspector));
    tryRegister('bcc-trust/my-endorsements',        ssrBlock('bcc-trust/my-endorsements'));
    tryRegister('bcc-trust/project-discovery-hub',  ssrBlock('bcc-trust/project-discovery-hub'));
    tryRegister('bcc-trust/validator-stats',        ssrBlock('bcc-trust/validator-stats',        pageIdInspector));
    tryRegister('bcc-trust/collection-showcase',    ssrBlock('bcc-trust/collection-showcase',    pageIdInspector));

    /* Unified Leaderboard — one block, three editor variations. */
    tryRegister('bcc-trust/leaderboard', ssrBlock('bcc-trust/leaderboard', function (attrs, setAttrs) {
        var type = attrs.type || 'nft';
        var controls = [
            createElement(SelectControl, {
                key: 'lb-type',
                label: 'Leaderboard type',
                value: type,
                options: [
                    { label: 'NFT Collections',  value: 'nft' },
                    { label: 'Validators',       value: 'validator' },
                    { label: 'Endorsements',     value: 'endorsement' },
                ],
                onChange: function (v) { setAttrs({ type: v }); },
            }),
        ];
        if (type === 'nft' || type === 'validator') {
            controls.push(
                createElement(SelectControl, {
                    key: 'lb-chain',
                    label: 'Default chain',
                    value: attrs.defaultChain || (type === 'nft' ? 'evm' : 'cosmos'),
                    options: [
                        { label: 'Ethereum & compatible (EVM)', value: 'evm' },
                        { label: 'Solana', value: 'solana' },
                        { label: 'Cosmos', value: 'cosmos' },
                    ],
                    onChange: function (v) { setAttrs({ defaultChain: v }); },
                }),
                createElement(ToggleControl, {
                    key: 'lb-claim',
                    label: 'Show claim button',
                    checked: attrs.showClaim !== false,
                    onChange: function (v) { setAttrs({ showClaim: !!v }); },
                })
            );
        }
        if (type === 'endorsement') {
            controls.push(
                createElement(TextControl, {
                    key: 'lb-limit',
                    type: 'number',
                    label: 'Limit',
                    value: String(attrs.limit || 10),
                    onChange: function (v) { setAttrs({ limit: parseInt(v, 10) || 10 }); },
                }),
                createElement(ToggleControl, {
                    key: 'lb-endorser',
                    label: 'Show endorser count',
                    checked: attrs.showEndorserCount !== false,
                    onChange: function (v) { setAttrs({ showEndorserCount: !!v }); },
                })
            );
        }
        if (type === 'nft' || type === 'validator') {
            controls.unshift(
                createElement(TextControl, {
                    key: 'lb-perpage',
                    type: 'number',
                    label: 'Per page',
                    value: String(attrs.perPage || (type === 'validator' ? 25 : 20)),
                    onChange: function (v) { setAttrs({ perPage: parseInt(v, 10) || 20 }); },
                })
            );
        }
        return controls;
    }));

    if (registerBlockVariation) {
        registerBlockVariation('bcc-trust/leaderboard', {
            name: 'nft',
            title: 'NFT Leaderboard',
            description: 'Top NFT collections ranked by trust score.',
            icon: 'chart-bar',
            attributes: { type: 'nft', perPage: 20, defaultChain: 'evm', showClaim: true },
            scope: ['inserter', 'transform'],
            keywords: ['nft', 'collections', 'leaderboard'],
            isActive: function (blockAttrs) { return (blockAttrs.type || 'nft') === 'nft'; },
        });
        registerBlockVariation('bcc-trust/leaderboard', {
            name: 'validator',
            title: 'Validator Leaderboard',
            description: 'Top validators ranked by trust score and stake.',
            icon: 'shield',
            attributes: { type: 'validator', perPage: 25, defaultChain: 'cosmos', showClaim: true },
            scope: ['inserter', 'transform'],
            keywords: ['validator', 'cosmos', 'staking', 'leaderboard'],
            isActive: function (blockAttrs) { return blockAttrs.type === 'validator'; },
        });
        registerBlockVariation('bcc-trust/leaderboard', {
            name: 'endorsement',
            title: 'Endorsement Leaderboard',
            description: 'Most endorsed community members.',
            icon: 'thumbs-up',
            attributes: { type: 'endorsement', limit: 10, showEndorserCount: true },
            scope: ['inserter', 'transform'],
            keywords: ['endorsement', 'endorsed', 'leaderboard'],
            isActive: function (blockAttrs) { return blockAttrs.type === 'endorsement'; },
        });
    }

    /* ── bcc-disputes namespace ─────────────────────────────── */
    tryRegister('bcc-disputes/dispute-form', ssrBlock('bcc-disputes/dispute-form', pageIdInspector));
    tryRegister('bcc-disputes/dispute-queue', ssrBlock('bcc-disputes/dispute-queue'));
    tryRegister('bcc-disputes/report-button', ssrBlock('bcc-disputes/report-button', function (attrs, setAttrs) {
        return createElement(TextControl, {
            label: 'User ID',
            help: 'The ID of the user to report. Required.',
            value: String(attrs.userId || ''),
            onChange: function (v) { setAttrs({ userId: parseInt(v, 10) || 0 }); },
        });
    }));

    /* ── bcc-onchain namespace ──────────────────────────────── */
    tryRegister('bcc-onchain/signals', ssrBlock('bcc-onchain/signals', pageIdInspector));

}(window.wp));
