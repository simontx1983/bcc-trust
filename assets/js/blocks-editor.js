/**
 * BCC Trust Engine – Gutenberg Block Editor Registration
 *
 * All blocks are dynamic (server-side rendered), so the editor
 * shows a <ServerSideRender /> preview with an InspectorControls panel.
 */
(function (wp) {
    var registerBlockType  = wp.blocks.registerBlockType;
    var createElement      = wp.element.createElement;
    var Fragment           = wp.element.Fragment;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var PanelBody          = wp.components.PanelBody;
    var TextControl        = wp.components.TextControl;
    var ToggleControl      = wp.components.ToggleControl;
    var ServerSideRender   = wp.serverSideRender;
    var useBlockProps       = wp.blockEditor.useBlockProps;

    /* =========================================================
       1. Trust Header Panel
       ========================================================= */
    registerBlockType('bcc-trust/trust-score', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to auto-detect from the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show vote/endorse actions',
                            checked: attrs.showActions,
                            onChange: function (v) { props.setAttributes({ showActions: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/trust-score',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       2. Builder Card
       ========================================================= */
    registerBlockType('bcc-trust/builder-card', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to use the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show score',
                            checked: attrs.showScore,
                            onChange: function (v) { props.setAttributes({ showScore: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show verification icons',
                            checked: attrs.showVerifications,
                            onChange: function (v) { props.setAttributes({ showVerifications: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/builder-card',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       3. Wallet Verification
       ========================================================= */
    registerBlockType('bcc-trust/wallet-verification', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to use the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show Ethereum',
                            checked: attrs.showEthereum,
                            onChange: function (v) { props.setAttributes({ showEthereum: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show Solana',
                            checked: attrs.showSolana,
                            onChange: function (v) { props.setAttributes({ showSolana: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show Cosmos',
                            checked: attrs.showCosmos,
                            onChange: function (v) { props.setAttributes({ showCosmos: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Compact mode',
                            checked: attrs.compact,
                            onChange: function (v) { props.setAttributes({ compact: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/wallet-verification',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       4. Trust Signals
       ========================================================= */
    registerBlockType('bcc-trust/trust-signals', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to use the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/trust-signals',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       5. Project Header
       ========================================================= */
    registerBlockType('bcc-trust/project-header', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to auto-detect from the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show trust summary',
                            checked: attrs.showTrustSummary,
                            onChange: function (v) { props.setAttributes({ showTrustSummary: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show verified badge',
                            checked: attrs.showVerifiedBadge,
                            onChange: function (v) { props.setAttributes({ showVerifiedBadge: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show follower count',
                            checked: attrs.showFollowers,
                            onChange: function (v) { props.setAttributes({ showFollowers: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/project-header',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       6. On-Chain Signals
       ========================================================= */
    registerBlockType('bcc-trust/on-chain-signals', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to use the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/on-chain-signals',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       7. Project Discovery Hub
       ========================================================= */
    registerBlockType('bcc-trust/project-discovery-hub', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();

            var SelectControl = wp.components.SelectControl;
            var CheckboxControl = wp.components.CheckboxControl;

            // Project type checkboxes state helper.
            function toggleType(type) {
                var current = (attrs.types || []).slice();
                var idx = current.indexOf(type);
                if (idx > -1) {
                    current.splice(idx, 1);
                } else {
                    current.push(type);
                }
                props.setAttributes({ types: current });
            }
            function hasType(type) {
                return (attrs.types || []).indexOf(type) > -1;
            }

            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    // Project Types panel
                    createElement(PanelBody, { title: 'Project Types', initialOpen: true },
                        createElement(CheckboxControl, {
                            label: 'Builder',
                            checked: hasType('builder'),
                            onChange: function () { toggleType('builder'); },
                        }),
                        createElement(CheckboxControl, {
                            label: 'Validator',
                            checked: hasType('validator'),
                            onChange: function () { toggleType('validator'); },
                        }),
                        createElement(CheckboxControl, {
                            label: 'NFT',
                            checked: hasType('nft'),
                            onChange: function () { toggleType('nft'); },
                        }),
                        createElement(CheckboxControl, {
                            label: 'DAO',
                            checked: hasType('dao'),
                            onChange: function () { toggleType('dao'); },
                        }),
                        createElement('p', { className: 'components-base-control__help' },
                            'Leave all unchecked to show all types.'
                        )
                    ),
                    // Sort & Count panel
                    createElement(PanelBody, { title: 'Sort & Count', initialOpen: true },
                        createElement(SelectControl, {
                            label: 'Sort by',
                            value: attrs.sortBy || 'trust',
                            options: [
                                { label: 'Top Trusted', value: 'trust' },
                                { label: 'Most Endorsed', value: 'endorsements' },
                                { label: 'Newest', value: 'newest' },
                                { label: 'Most Followers', value: 'followers' },
                            ],
                            onChange: function (v) { props.setAttributes({ sortBy: v }); },
                        }),
                        createElement(SelectControl, {
                            label: 'Projects per page',
                            value: String(attrs.limit || 12),
                            options: [
                                { label: '6', value: '6' },
                                { label: '8', value: '8' },
                                { label: '12', value: '12' },
                                { label: '16', value: '16' },
                                { label: '24', value: '24' },
                            ],
                            onChange: function (v) { props.setAttributes({ limit: parseInt(v, 10) }); },
                        })
                    ),
                    // Display Options panel
                    createElement(PanelBody, { title: 'Display Options', initialOpen: false },
                        createElement(ToggleControl, {
                            label: 'Show search',
                            checked: attrs.showSearch,
                            onChange: function (v) { props.setAttributes({ showSearch: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show filter tabs',
                            checked: attrs.showFilters,
                            onChange: function (v) { props.setAttributes({ showFilters: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show cover background',
                            checked: attrs.showCoverBackground,
                            onChange: function (v) { props.setAttributes({ showCoverBackground: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show verified badge',
                            checked: attrs.showVerifiedBadge,
                            onChange: function (v) { props.setAttributes({ showVerifiedBadge: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show endorsements',
                            checked: attrs.showEndorsements,
                            onChange: function (v) { props.setAttributes({ showEndorsements: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show followers',
                            checked: attrs.showFollowers,
                            onChange: function (v) { props.setAttributes({ showFollowers: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Verified only',
                            checked: attrs.verifiedOnly,
                            onChange: function (v) { props.setAttributes({ verifiedOnly: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/project-discovery-hub',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       8. Validator Stats
       ========================================================= */
    registerBlockType('bcc-trust/validator-stats', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings' },
                        createElement(TextControl, {
                            label: 'Page ID (0 = current)',
                            value: String(attrs.pageId || 0),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show Claim Button',
                            checked: attrs.showClaim !== false,
                            onChange: function (v) { props.setAttributes({ showClaim: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/validator-stats',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       9. Collection Showcase
       ========================================================= */
    registerBlockType('bcc-trust/collection-showcase', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings' },
                        createElement(TextControl, {
                            label: 'Page ID (0 = current)',
                            value: String(attrs.pageId || 0),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show Claim Button',
                            checked: attrs.showClaim !== false,
                            onChange: function (v) { props.setAttributes({ showClaim: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/collection-showcase',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       10. NFT Leaderboard
       ========================================================= */
    registerBlockType('bcc-trust/nft-leaderboard', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            var SelectControl = wp.components.SelectControl;
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings' },
                        createElement(SelectControl, {
                            label: 'Default Chain Tab',
                            value: attrs.defaultChain || 'evm',
                            options: [
                                { label: 'EVM', value: 'evm' },
                                { label: 'Solana', value: 'solana' },
                                { label: 'Cosmos', value: 'cosmos' },
                            ],
                            onChange: function (v) { props.setAttributes({ defaultChain: v }); },
                        }),
                        createElement(TextControl, {
                            label: 'Items Per Page',
                            value: String(attrs.perPage || 20),
                            onChange: function (v) { props.setAttributes({ perPage: parseInt(v, 10) || 20 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show Claim Buttons',
                            checked: attrs.showClaim !== false,
                            onChange: function (v) { props.setAttributes({ showClaim: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/nft-leaderboard',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       11. Validator Leaderboard
       ========================================================= */
    registerBlockType('bcc-trust/validator-leaderboard', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            var SelectControl = wp.components.SelectControl;
            var RangeControl  = wp.components.RangeControl;
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings' },
                        createElement(RangeControl, {
                            label: 'Validators Per Page',
                            value: attrs.perPage || 25,
                            min: 5,
                            max: 100,
                            onChange: function (v) { props.setAttributes({ perPage: v }); },
                        }),
                        createElement(SelectControl, {
                            label: 'Default Time Window',
                            value: attrs.defaultTimeWindow || '',
                            options: [
                                { label: 'All Time', value: '' },
                                { label: '1 Hour',   value: '1h' },
                                { label: '6 Hours',  value: '6h' },
                                { label: '12 Hours', value: '12h' },
                                { label: '1 Day',    value: '1d' },
                                { label: '7 Days',   value: '7d' },
                                { label: '30 Days',  value: '30d' },
                            ],
                            onChange: function (v) { props.setAttributes({ defaultTimeWindow: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show Claim Buttons',
                            checked: attrs.showClaim !== false,
                            onChange: function (v) { props.setAttributes({ showClaim: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/validator-leaderboard',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       12. Verification Badges
       ========================================================= */
    registerBlockType('bcc-trust/verification-badges', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to auto-detect from the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Compact mode (icons only)',
                            checked: attrs.compact,
                            onChange: function (v) { props.setAttributes({ compact: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/verification-badges',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       13. Trust Score Breakdown
       ========================================================= */
    registerBlockType('bcc-trust/trust-breakdown', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to auto-detect from the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/trust-breakdown',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       14. Endorsement Leaderboard
       ========================================================= */
    registerBlockType('bcc-trust/endorsement-leaderboard', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            var RangeControl = wp.components.RangeControl;
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(RangeControl, {
                            label: 'Number of pages to show',
                            value: attrs.limit || 10,
                            min: 1,
                            max: 100,
                            onChange: function (v) { props.setAttributes({ limit: v }); },
                        }),
                        createElement(TextControl, {
                            label: 'Context filter',
                            help: 'Leave empty to show all contexts (e.g. "general").',
                            value: attrs.context || '',
                            onChange: function (v) { props.setAttributes({ context: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show endorser count',
                            checked: attrs.showEndorserCount !== false,
                            onChange: function (v) { props.setAttributes({ showEndorserCount: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-trust/endorsement-leaderboard',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

})(window.wp);
