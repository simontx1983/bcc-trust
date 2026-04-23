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
        initFraudDashboard();
        initCharts();
        initBulkActions();
        initTooltips();
        initRealTimeUpdates();
        initFraudAnalysisButtons();

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
         * Initialize fraud dashboard with real-time data
         */
        function initFraudDashboard() {
            if ($('#fraud-dashboard').length) {
                loadFraudStats();
                loadHighRiskUsers();
                loadRecentFraudAlerts();
            }
        }

        /**
         * Load fraud statistics
         */
        function loadFraudStats() {
            if (!$('#fraud-stats').length) return;

            $.ajax({
                url: bccTrustAdmin.rest_url + 'fraud/stats',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        updateFraudDashboard(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load fraud stats:', error);
                    $('#fraud-stats').html('<div class="notice notice-error">Failed to load fraud statistics</div>');
                }
            });
        }

        /**
         * Load high risk users
         */
        function loadHighRiskUsers() {
            if (!$('#high-risk-users').length) return;

            $.ajax({
                url: bccTrustAdmin.rest_url + 'users/high-risk',
                method: 'GET',
                data: {
                    limit: 20,
                    threshold: (typeof bccTrustAdmin !== 'undefined' && bccTrustAdmin.highRiskThreshold) ? parseInt(bccTrustAdmin.highRiskThreshold, 10) : 60
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        updateHighRiskUsersTable(response.data.users || response.data);
                    }
                }
            });
        }

        /**
         * Load recent fraud alerts
         */
        function loadRecentFraudAlerts() {
            if (!$('#recent-fraud-alerts').length) return;

            $.ajax({
                url: bccTrustAdmin.rest_url + 'activity/fraud',
                method: 'GET',
                data: {
                    limit: 10
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        updateFraudAlerts(response.data.events || response.data);
                    }
                }
            });
        }

        /**
         * Update fraud dashboard with real data
         */
        function updateFraudDashboard(data) {
            // Update stat cards
            $('#total-fraud-alerts').text(data.total_alerts || 0);
            $('#avg-fraud-score').text(data.avg_score || '0');
            $('#high-risk-count').text(data.high_risk_count || 0);
            $('#suspended-count').text(data.suspended_count || 0);

            // Update risk distribution chart if exists
            if (typeof updateRiskChart === 'function' && data.risk_distribution) {
                updateRiskChart(data.risk_distribution);
            }

            // Update last updated time
            $('#last-updated').text(new Date().toLocaleTimeString());
        }

        /**
         * Update high risk users table
         */
        function updateHighRiskUsersTable(users) {
            var tbody = $('#high-risk-users tbody');
            tbody.empty();

            if (!users || users.length === 0) {
                tbody.append('<tr><td colspan="7">No high risk users found</td></tr>');
                return;
            }

            users.forEach(function(user) {
                var safeId = parseInt(user.id, 10) || 0;
                var safeScore = parseFloat(user.fraud_score) || 0;
                var safeTriggers = user.triggers ? user.triggers.slice(0, 2).map(escapeHtml).join(', ') : '—';
                var row = '<tr>' +
                    '<td><a href="admin.php?page=bcc-trust-moderation&user_id=' + safeId + '">' +
                    escapeHtml(user.name) + '<br><small>ID: ' + safeId + '</small></a></td>' +
                    '<td>' + escapeHtml(user.email) + '</td>' +
                    '<td><div class="bcc-fraud-meter"><div class="bcc-fraud-fill" style="width:' +
                    safeScore + '%; background:' + getScoreColor(safeScore) + ';"></div>' +
                    '<span>' + safeScore + '</span></div></td>' +
                    '<td><span class="bcc-risk-badge bcc-risk-' + escapeHtml(user.risk_level) + '">' +
                    capitalize(escapeHtml(user.risk_level)) + '</span></td>' +
                    '<td>' + safeTriggers + '</td>' +
                    '<td>' + (user.suspended ? '✓' : '✗') + '</td>' +
                    '<td><a href="admin.php?page=bcc-trust-moderation&user_id=' + safeId +
                    '" class="button button-small">Investigate</a></td>' +
                    '</tr>';
                tbody.append(row);
            });
        }

        /**
         * Update fraud alerts
         */
        function updateFraudAlerts(alerts) {
            var container = $('#recent-fraud-alerts');
            container.empty();

            if (!alerts || alerts.length === 0) {
                container.append('<p>No recent fraud alerts</p>');
                return;
            }

            alerts.forEach(function(alert) {
                var safeUserId = parseInt(alert.user_id, 10) || 0;
                var alertHtml = '<div class="fraud-alert ' + escapeHtml(alert.severity) + '">' +
                    '<div class="alert-time">' + escapeHtml(alert.time) + '</div>' +
                    '<div class="alert-message">' + escapeHtml(alert.message) + '</div>' +
                    '<div class="alert-user">' +
                    '<a href="admin.php?page=bcc-trust-moderation&user_id=' + safeUserId + '">' +
                    'View User</a></div>' +
                    '</div>';
                container.append(alertHtml);
            });
        }

        /**
         * Initialize charts
         */
        function initCharts() {
            if (typeof Chart === 'undefined') return;

            // Trust score trend chart
            if ($('#trust-score-chart').length) {
                initTrustScoreChart();
            }

            // Risk distribution chart
            if ($('#risk-distribution-chart').length) {
                initRiskDistributionChart();
            }

            // Fraud score trend chart
            if ($('#fraud-trend-chart').length) {
                initFraudTrendChart();
            }

            // Device distribution chart
            if ($('#device-chart').length) {
                initDeviceChart();
            }
        }

        /**
         * Initialize trust score chart
         */
        function initTrustScoreChart() {
            var ctx = document.getElementById('trust-score-chart').getContext('2d');
            
            // Load historical data
            $.ajax({
                url: bccTrustAdmin.rest_url + 'stats/trust-trend',
                method: 'GET',
                data: {
                    days: 30
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: response.data.labels,
                                datasets: [{
                                    label: 'Average Trust Score',
                                    data: response.data.scores,
                                    borderColor: '#2196f3',
                                    backgroundColor: 'rgba(33, 150, 243, 0.1)',
                                    tension: 0.4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: true,
                                        position: 'top'
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        max: 100
                                    }
                                }
                            }
                        });
                    }
                }
            });
        }

        /**
         * Initialize risk distribution chart
         */
        function initRiskDistributionChart() {
            var ctx = document.getElementById('risk-distribution-chart').getContext('2d');
            
            $.ajax({
                url: bccTrustAdmin.rest_url + 'stats/risk-distribution',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Critical', 'High', 'Medium', 'Low', 'Minimal'],
                                datasets: [{
                                    data: [
                                        response.data.critical,
                                        response.data.high,
                                        response.data.medium,
                                        response.data.low,
                                        response.data.minimal
                                    ],
                                    backgroundColor: [
                                        '#9c27b0',
                                        '#f44336',
                                        '#ff9800',
                                        '#2196f3',
                                        '#4caf50'
                                    ]
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'right'
                                    }
                                }
                            }
                        });
                    }
                }
            });
        }

        /**
         * Initialize fraud trend chart
         */
        function initFraudTrendChart() {
            var ctx = document.getElementById('fraud-trend-chart').getContext('2d');
            
            $.ajax({
                url: bccTrustAdmin.rest_url + 'stats/fraud-trend',
                method: 'GET',
                data: {
                    days: 30
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: response.data.labels,
                                datasets: [{
                                    label: 'Fraud Detections',
                                    data: response.data.counts,
                                    backgroundColor: '#f44336'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false
                            }
                        });
                    }
                }
            });
        }

        /**
         * Initialize device distribution chart
         */
        function initDeviceChart() {
            var ctx = document.getElementById('device-chart').getContext('2d');
            
            $.ajax({
                url: bccTrustAdmin.rest_url + 'stats/devices',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        new Chart(ctx, {
                            type: 'pie',
                            data: {
                                labels: ['Clean', 'Suspicious', 'Automated', 'Shared'],
                                datasets: [{
                                    data: [
                                        response.data.clean,
                                        response.data.suspicious,
                                        response.data.automated,
                                        response.data.shared
                                    ],
                                    backgroundColor: [
                                        '#4caf50',
                                        '#ff9800',
                                        '#f44336',
                                        '#9c27b0'
                                    ]
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false
                            }
                        });
                    }
                }
            });
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

        /**
         * Initialize real-time updates
         */
        function initRealTimeUpdates() {
            // Refresh stats every 60 seconds if on dashboard
            if ($('#fraud-dashboard').length) {
                setInterval(loadFraudStats, 60000);
            }

            // Check for new fraud alerts every 30 seconds
            if ($('#recent-fraud-alerts').length) {
                setInterval(loadRecentFraudAlerts, 30000);
            }
        }

        /**
         * Initialize fraud analysis buttons
         */
        function initFraudAnalysisButtons() {
            $('.analyze-fraud-btn').on('click', function(e) {
                e.preventDefault();
                var userId = $(this).data('user-id');
                var btn = $(this);
                
                btn.prop('disabled', true).text('Analyzing...');
                
                $.ajax({
                    url: bccTrustAdmin.rest_url + 'analyze-user/' + userId,
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', bccTrustAdmin.nonce);
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Analysis complete. Fraud score: ' + response.data.fraud_score);
                            location.reload();
                        } else {
                            alert('Analysis failed: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('Analysis failed. Please try again.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).text('Reanalyze');
                    }
                });
            });
        }

        /**
         * Helper: Get color for fraud score.
         * Thresholds provided by PHP via bccTrustAdmin.fraudThresholds.
         */
        function getScoreColor(score) {
            var thresholds = (typeof bccTrustAdmin !== 'undefined' && bccTrustAdmin.fraudThresholds) ? bccTrustAdmin.fraudThresholds : [];
            for (var i = 0; i < thresholds.length; i++) {
                if (score >= parseInt(thresholds[i].min, 10)) return thresholds[i].color;
            }
            return '#28a745';
        }

        /**
         * Helper: Escape HTML
         */
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        /**
         * Helper: Capitalize first letter
         */
        function capitalize(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
    });

})(jQuery);