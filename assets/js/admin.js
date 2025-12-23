/**
 * WP Nalda Sync Admin JavaScript
 *
 * @package WP_Nalda_Sync
 */

(function ($) {
    'use strict';

    /**
     * Admin functionality
     */
    const WPNSAdmin = {
        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.initPasswordToggle();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            // Test SFTP connection
            $('#wpns-test-connection').on('click', this.testConnection.bind(this));

            // Run sync manually
            $('#wpns-run-sync').on('click', this.runSync.bind(this));

            // Download CSV
            $('#wpns-download-csv').on('click', this.downloadCSV.bind(this));

            // Clear logs
            $('#wpns-clear-logs').on('click', this.clearLogs.bind(this));

            // Refresh logs
            $('#wpns-refresh-logs').on('click', this.refreshLogs.bind(this));

            // Filter logs
            $('#wpns-log-filter').on('change', this.filterLogs.bind(this));

            // View context modal
            $(document).on('click', '.wpns-view-context', this.showContextModal.bind(this));

            // Close modal
            $(document).on('click', '.wpns-modal-close, .wpns-modal', this.closeModal.bind(this));
            $(document).on('click', '.wpns-modal-content', function (e) {
                e.stopPropagation();
            });

            // ESC key to close modal
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape') {
                    WPNSAdmin.closeModal();
                }
            });

            // Toggle sync run details
            $(document).on('click', '.wpns-run-header', this.toggleRunDetails.bind(this));
            $(document).on('click', '.wpns-run-toggle', function (e) {
                e.stopPropagation();
                WPNSAdmin.toggleRunDetails.call(WPNSAdmin, e);
            });

            // Nalda API test connection
            $('#wpns-test-nalda-api').on('click', this.testNaldaAPI.bind(this));

            // Run order sync manually
            $('#wpns-run-order-sync').on('click', this.runOrderSync.bind(this));
        },

        /**
         * Initialize password toggle
         */
        initPasswordToggle: function () {
            $('.wpns-toggle-password').on('click', function () {
                const targetId = $(this).data('target');
                const $input = $('#' + targetId);
                const $icon = $(this).find('.dashicons');

                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    $input.attr('type', 'password');
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });
        },

        /**
         * Show result message
         *
         * @param {string} message Message to display
         * @param {string} type Message type (success, error, loading)
         */
        showResult: function (message, type) {
            const $result = $('#wpns-action-result');
            $result
                .removeClass('success error loading')
                .addClass(type)
                .html(message)
                .show();

            if (type !== 'loading') {
                setTimeout(function () {
                    $result.fadeOut();
                }, 10000);
            }
        },

        /**
         * Test SFTP connection
         *
         * @param {Event} e Click event
         */
        testConnection: function (e) {
            e.preventDefault();

            const $button = $('#wpns-test-connection');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wpns-spinning"></span> ' +
                wpns_admin.strings.testing
            );

            this.showResult(wpns_admin.strings.testing, 'loading');

            $.ajax({
                url: wpns_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpns_test_connection',
                    nonce: wpns_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        WPNSAdmin.showResult(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + response.data,
                            'success'
                        );
                    } else {
                        WPNSAdmin.showResult(
                            '<span class="dashicons dashicons-warning"></span> ' + wpns_admin.strings.error + ' ' + response.data,
                            'error'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    WPNSAdmin.showResult(
                        '<span class="dashicons dashicons-warning"></span> ' + wpns_admin.strings.error + ' ' + error,
                        'error'
                    );
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Run sync manually
         *
         * @param {Event} e Click event
         */
        runSync: function (e) {
            e.preventDefault();

            const $button = $('#wpns-run-sync');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wpns-spinning"></span> ' +
                wpns_admin.strings.syncing
            );

            this.showResult(wpns_admin.strings.syncing, 'loading');

            $.ajax({
                url: wpns_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpns_run_sync',
                    nonce: wpns_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        WPNSAdmin.showResult(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + response.data,
                            'success'
                        );
                        // Refresh page after successful sync to update status cards
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        WPNSAdmin.showResult(
                            '<span class="dashicons dashicons-warning"></span> ' + wpns_admin.strings.error + ' ' + response.data,
                            'error'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    WPNSAdmin.showResult(
                        '<span class="dashicons dashicons-warning"></span> ' + wpns_admin.strings.error + ' ' + error,
                        'error'
                    );
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Download CSV
         *
         * @param {Event} e Click event
         */
        downloadCSV: function (e) {
            e.preventDefault();

            const $button = $('#wpns-download-csv');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wpns-spinning"></span> ' +
                wpns_admin.strings.loading
            );

            this.showResult(wpns_admin.strings.loading, 'loading');

            $.ajax({
                url: wpns_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpns_download_csv',
                    nonce: wpns_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        WPNSAdmin.showResult(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message +
                            ' <a href="' + response.data.file_url + '" target="_blank" class="button button-small">Download</a>',
                            'success'
                        );
                    } else {
                        WPNSAdmin.showResult(
                            '<span class="dashicons dashicons-warning"></span> ' + wpns_admin.strings.error + ' ' + response.data,
                            'error'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    WPNSAdmin.showResult(
                        '<span class="dashicons dashicons-warning"></span> ' + wpns_admin.strings.error + ' ' + error,
                        'error'
                    );
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Clear logs
         *
         * @param {Event} e Click event
         */
        clearLogs: function (e) {
            e.preventDefault();

            if (!confirm(wpns_admin.strings.confirm_clear)) {
                return;
            }

            const $button = $('#wpns-clear-logs');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wpns-spinning"></span> ' +
                wpns_admin.strings.clearing
            );

            $.ajax({
                url: wpns_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpns_clear_logs',
                    nonce: wpns_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Reload the page to show empty state
                        location.reload();
                    } else {
                        alert(wpns_admin.strings.error + ' ' + response.data);
                    }
                },
                error: function (xhr, status, error) {
                    alert(wpns_admin.strings.error + ' ' + error);
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Refresh logs
         *
         * @param {Event} e Click event
         */
        refreshLogs: function (e) {
            e.preventDefault();

            const $button = $('#wpns-refresh-logs');
            const $icon = $button.find('.dashicons');

            $button.prop('disabled', true);
            $icon.addClass('wpns-spinning');

            // Simply reload the page to refresh the grouped logs view
            location.reload();
        },

        /**
         * Filter logs by level
         *
         * @param {Event} e Change event
         */
        filterLogs: function (e) {
            const level = $(e.target).val();

            if (!level) {
                $('.wpns-log-row').removeClass('hidden');
            } else {
                $('.wpns-log-row').each(function () {
                    if ($(this).data('level') === level) {
                        $(this).removeClass('hidden');
                    } else {
                        $(this).addClass('hidden');
                    }
                });
            }
        },

        /**
         * Show context modal
         *
         * @param {Event} e Click event
         */
        showContextModal: function (e) {
            e.preventDefault();

            const context = $(e.currentTarget).data('context');
            let formattedContext;

            // jQuery's .data() auto-parses JSON, so context may already be an object
            if (typeof context === 'object' && context !== null) {
                formattedContext = JSON.stringify(context, null, 2);
            } else if (typeof context === 'string') {
                try {
                    const parsed = JSON.parse(context);
                    formattedContext = JSON.stringify(parsed, null, 2);
                } catch (err) {
                    formattedContext = context;
                }
            } else {
                formattedContext = String(context);
            }

            $('#wpns-context-content').text(formattedContext);
            $('#wpns-context-modal').show();
        },

        /**
         * Close modal
         *
         * @param {Event} e Click event
         */
        closeModal: function (e) {
            if (e) {
                e.preventDefault();
            }
            $('#wpns-context-modal').hide();
        },

        /**
         * Toggle sync run details
         *
         * @param {Event} e Click event
         */
        toggleRunDetails: function (e) {
            e.preventDefault();

            const $header = $(e.target).closest('.wpns-run-header');
            const $run = $header.closest('.wpns-sync-run');
            const $details = $run.find('.wpns-run-details');
            const $toggle = $run.find('.wpns-run-toggle');

            const isExpanded = $toggle.attr('aria-expanded') === 'true';

            if (isExpanded) {
                $details.slideUp(200);
                $toggle.attr('aria-expanded', 'false');
            } else {
                $details.slideDown(200);
                $toggle.attr('aria-expanded', 'true');
            }
        },

        /**
         * Show result message for order sync page
         *
         * @param {string} message Message to display
         * @param {string} type Message type (success, error, loading)
         */
        showOrderResult: function (message, type) {
            const $result = $('#wpns-order-action-result');
            $result
                .removeClass('success error loading')
                .addClass(type)
                .html(message)
                .show();

            if (type !== 'loading') {
                setTimeout(function () {
                    $result.fadeOut();
                }, 15000);
            }
        },

        /**
         * Test Nalda API connection
         *
         * @param {Event} e Click event
         */
        testNaldaAPI: function (e) {
            e.preventDefault();

            const $button = $('#wpns-test-nalda-api');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wpns-spinning"></span> ' +
                (wpns_admin.strings.testing || 'Testing...')
            );

            this.showOrderResult(wpns_admin.strings.testing || 'Testing API connection...', 'loading');

            $.ajax({
                url: wpns_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpns_test_nalda_api',
                    nonce: wpns_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        WPNSAdmin.showOrderResult(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + response.data,
                            'success'
                        );
                    } else {
                        WPNSAdmin.showOrderResult(
                            '<span class="dashicons dashicons-warning"></span> ' + (wpns_admin.strings.error || 'Error:') + ' ' + response.data,
                            'error'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    WPNSAdmin.showOrderResult(
                        '<span class="dashicons dashicons-warning"></span> ' + (wpns_admin.strings.error || 'Error:') + ' ' + error,
                        'error'
                    );
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Run order sync manually
         *
         * @param {Event} e Click event
         */
        runOrderSync: function (e) {
            e.preventDefault();

            const $button = $('#wpns-run-order-sync');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wpns-spinning"></span> ' +
                (wpns_admin.strings.importing_orders || 'Importing orders...')
            );

            this.showOrderResult(wpns_admin.strings.importing_orders || 'Importing orders from Nalda...', 'loading');

            $.ajax({
                url: wpns_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpns_run_order_sync',
                    nonce: wpns_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        let message = '<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message;

                        // Add stats if available
                        if (response.data.stats) {
                            const stats = response.data.stats;
                            message += '<br><small>';
                            if (typeof stats.fetched !== 'undefined') {
                                message += 'Fetched: ' + stats.fetched + ' | ';
                            }
                            if (typeof stats.imported !== 'undefined') {
                                message += 'Imported: ' + stats.imported + ' | ';
                            }
                            if (typeof stats.updated !== 'undefined') {
                                message += 'Updated: ' + stats.updated + ' | ';
                            }
                            if (typeof stats.skipped !== 'undefined') {
                                message += 'Skipped: ' + stats.skipped;
                            }
                            message += '</small>';
                        }

                        WPNSAdmin.showOrderResult(message, 'success');

                        // Refresh page after successful sync to update tables
                        setTimeout(function () {
                            location.reload();
                        }, 3000);
                    } else {
                        WPNSAdmin.showOrderResult(
                            '<span class="dashicons dashicons-warning"></span> ' + (wpns_admin.strings.error || 'Error:') + ' ' + response.data,
                            'error'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    WPNSAdmin.showOrderResult(
                        '<span class="dashicons dashicons-warning"></span> ' + (wpns_admin.strings.error || 'Error:') + ' ' + error,
                        'error'
                    );
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        WPNSAdmin.init();
    });

})(jQuery);
