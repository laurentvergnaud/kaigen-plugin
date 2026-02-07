jQuery(document).ready(function($) {
    'use strict';

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Toggle authentication method fields
    $('input[name="kaigen_settings[auth_method]"]').on('change', function() {
        var method = $(this).val();
        if (method === 'api_key') {
            $('.kaigen-api-key-field').show();
            $('.kaigen-app-password-field').hide();
        } else {
            $('.kaigen-api-key-field').hide();
            $('.kaigen-app-password-field').show();
        }
    });

    // Select all post types
    $('#kaigen-select-all').on('change', function() {
        $('input[name="kaigen_settings[enabled_post_types][]"]').prop('checked', $(this).prop('checked'));
    });

    // Test connection
    $('#kaigen-test-connection').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $status = $('#kaigen-connection-status');

        $button.prop('disabled', true).text(kaigenAdmin.strings.testing);
        $status.html('<span class="spinner is-active"></span>');

        $.ajax({
            url: kaigenAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kaigen_test_connection',
                nonce: kaigenAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ' + kaigenAdmin.strings.success);
                } else {
                    var message = response && response.data && response.data.message ? escapeHtml(response.data.message) : '';
                    $status.html('<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ' + kaigenAdmin.strings.error + ' ' + message);
                }
            },
            error: function() {
                $status.html('<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ' + kaigenAdmin.strings.error + ' Connection failed');
            },
            complete: function() {
                $button.prop('disabled', false).text(kaigenAdmin.strings.testConnection);
            }
        });
    });

    // Sync content
    $('#kaigen-sync-content').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $status = $('#kaigen-sync-status');

        $button.prop('disabled', true).text(kaigenAdmin.strings.syncing);
        $status.html('<span class="spinner is-active"></span>');

        $.ajax({
            url: kaigenAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kaigen_sync_content',
                nonce: kaigenAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var message = 'Synced ' + (response.data.postsIngested || 0) + ' posts, ' +
                                  'created ' + (response.data.chunksCreated || 0) + ' chunks';
                    $status.html('<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ' + message);

                    // Reload logs after 1 second
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    var message = response && response.data && response.data.message ? escapeHtml(response.data.message) : '';
                    $status.html('<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ' + kaigenAdmin.strings.error + ' ' + message);
                }
            },
            error: function() {
                $status.html('<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ' + kaigenAdmin.strings.error + ' Sync failed');
            },
            complete: function() {
                $button.prop('disabled', false).text(kaigenAdmin.strings.syncContentNow);
            }
        });
    });
});
