/**
 * BCC Trust Engine - Admin Interface
 * Enhanced with fraud detection, real-time updates, and data visualization
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Check if admin data exists
        if (typeof bccTrustAdmin === "undefined") {
            console.error('BCC Trust Admin: Configuration missing');
            return;
        }

        // Initialize all admin features
        initFilters();
        initConfirmationDialogs();
        initSearch();
        initTabs();
        initBulkActions();
        initTooltips();

        /**
         * Initialize filter functionality
         */
        function initFilters() {
            // Filter button for activity log
            $('#filter-button').on('click', function() {
                var action = $('#action-filter').val();
                var userId = $('#user-filter').val();
                var riskLevel = $('#risk-filter').val();
                
                var url = new URL(window.location.href);
                if (action) url.searchParams.set('action', action);
                if (userId) url.searchParams.set('user_id', userId);
                if (riskLevel) url.searchParams.set('risk_level', riskLevel);
                
                window.location.href = url.toString();
            });

            // Date range filter
            $('#date-range').on('change', function() {
                var range = $(this).val();
                if (range) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('date_range', range);
                    window.location.href = url.toString();
                }
            });

            // Quick filters
            $('.quick-filter').on('click', function(e) {
                e.preventDefault();
                var filter = $(this).data('filter');
                var url = new URL(window.location.href);
                url.searchParams.set('filter', filter);
                window.location.href = url.toString();
            });
        }

        /**
         * Initialize confirmation dialogs for moderation actions
         */
        function initConfirmationDialogs() {
            // Suspend user with reason
            $('button[name="suspend_user"]').on('click', function(e) {
                var reason = $('#suspend_reason').val() || 'manual_suspension';
                var message = bccTrustAdmin.strings.confirm_suspend + '\n\nReason: ' + reason;
                if (!confirm(message)) {
                    e.preventDefault();
                }
            });

            // Unsuspend user
            $('button[name="unsuspend_user"]').on('click', function(e) {
                if (!confirm(bccTrustAdmin.strings.confirm_unsuspend)) {
                    e.preventDefault();
                }
            });

            // Clear votes
            $('button[name="clear_votes"]').on('click', function(e) {
                if (!confirm(bccTrustAdmin.strings.confirm_clear_votes)) {
                    e.preventDefault();
                }
            });

            // Clear fingerprints
            $('button[name="clear_fingerprints"]').on('click', function(e) {
                if (!confirm(bccTrustAdmin.strings.confirm_clear_fingerprints)) {
                    e.preventDefault();
                }
            });

            // Reanalyze user
            $('button[name="reanalyze_user"]').on('click', function(e) {
                if (!confirm(bccTrustAdmin.strings.confirm_reanalyze)) {
                    e.preventDefault();
                }
                // Show loading state
                $(this).text('Analyzing...').prop('disabled', true);
            });
        }

        /**
         * Initialize search functionality
         */
        function initSearch() {
            var searchTimeout;
            
            $('#user-search, #page-search, #fingerprint-search').on('keyup', function() {
                clearTimeout(searchTimeout);
                var searchTerm = $(this).val();
                var formId = $(this).data('form') || 'search-form';
                
                searchTimeout = setTimeout(function() {
                    if (searchTerm.length > 2 || searchTerm.length === 0) {
                        $('#' + formId).submit();
                    }
                }, 500);
            });

            // Advanced search toggle
            $('#advanced-search-toggle').on('click', function() {
                $('#advanced-search-fields').slideToggle();
            });
        }

        /**
         * Initialize tab switching
         */
        function initTabs() {
            // Tab switching with URL hash
            var hash = window.location.hash;
            if (hash) {
                $('.nav-tab-wrapper a[href="' + hash + '"]').click();
            }

            // Save active tab to localStorage
            $('.nav-tab-wrapper a').on('click', function() {
                var tab = $(this).attr('href');
                localStorage.setItem('bccTrustActiveTab', tab);
            });

            // Restore last active tab
            var savedTab = localStorage.getItem('bccTrustActiveTab');
            if (savedTab && $('.nav-tab-wrapper a[href="' + savedTab + '"]').length) {
                window.location.hash = savedTab;
            }
        }

        /**
         * Initialize bulk actions
         */
        function initBulkActions() {
            $('#doaction, #doaction2').on('click', function(e) {
                var action = $(this).prev('select').val();
                var selected = $('input[name="bulk-select"]:checked').length;
                
                if (selected === 0) {
                    alert('Please select at least one item');
                    e.preventDefault();
                    return;
                }

                var messages = {
                    'suspend': 'Are you sure you want to suspend ' + selected + ' selected users?',
                    'unsuspend': 'Are you sure you want to unsuspend ' + selected + ' selected users?',
                    'clear_votes': 'Clear all votes for ' + selected + ' users? This cannot be undone.',
                    'reanalyze': 'Reanalyze ' + selected + ' users? This may take a moment.'
                };

                if (messages[action] && !confirm(messages[action])) {
                    e.preventDefault();
                }
            });

            // Select all checkbox
            $('#select-all').on('change', function() {
                $('input[name="bulk-select"]').prop('checked', $(this).prop('checked'));
            });
        }

        /**
         * Initialize tooltips
         */
        function initTooltips() {
            $('.tier-badge, .risk-badge, .fraud-meter').tooltip({
                position: { my: 'center bottom', at: 'center top-10' },
                tooltipClass: 'bcc-tooltip'
            });
        }

    });

})(jQuery);