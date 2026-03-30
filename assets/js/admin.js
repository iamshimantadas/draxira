(function ($) {
    'use strict';

    /**
     * Initialize on document ready
     * 
     * @since 1.0.0
     */
    $(document).ready(function () {
        mc_drax_initTabSwitching();
        mc_drax_initPostTypeHandlers();
        mc_drax_initDeleteConfirmations();
        mc_drax_initProductHandlers();
    });

    /**
     * Initialize tab switching functionality
     * 
     * @since 1.0.0
     */
    function mc_drax_initTabSwitching() {
        $('.nav-tab-wrapper a').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).attr('href');

            // Update active tab
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            // Show selected tab content
            $('.tab-content').removeClass('active');
            $(tab).addClass('active');

            // Load content based on active tab
            mc_drax_handleTabContentLoad(tab);
        });
    }

    /**
     * Handle content loading when tabs are switched
     * 
     * @since 1.0.0
     * @param {string} tab - The tab identifier
     */
    function mc_drax_handleTabContentLoad(tab) {
        // Load dummy posts list when manage tab is shown
        if (tab === '#manage-tab') {
            mc_drax_loadDummyPosts();
        }

        // Load post meta when generate tab is shown
        if (tab === '#generate-tab') {
            mc_drax_loadPostMeta();
        }

        // Load product meta when product generate tab is shown
        if (tab === '#generate-products-tab') {
            mc_drax_loadProductMeta();
        }

        // Load dummy products when product manage tab is shown
        if (tab === '#manage-products-tab') {
            mc_drax_loadDummyProducts();
        }
    }

    /**
     * Initialize post type related handlers
     * 
     * @since 1.0.0
     */
    function mc_drax_initPostTypeHandlers() {
        // Load post meta on page load if generate tab is active
        if ($('#generate-tab').hasClass('active')) {
            mc_drax_loadPostMeta();
        }

        // Load post meta when post type changes
        $('#post-type-selector').on('change', function () {
            mc_drax_loadPostMeta();
        });

        // Apply filter button click
        $('#apply-filter').on('click', function () {
            mc_drax_loadDummyPosts();
        });

        // Auto-load posts when manage tab is shown on page load
        if ($('#manage-tab').hasClass('active')) {
            mc_drax_loadDummyPosts();
        }

        // Set delete post type from filter
        $('#filter-post-type').on('change', function () {
            $('#delete-post-type').val($(this).val());
        });
    }

    /**
     * Initialize product related handlers
     * 
     * @since 1.0.0
     */
    function mc_drax_initProductHandlers() {
        // Load product meta on page load if generate tab is active
        if ($('#generate-products-tab').length && $('#generate-products-tab').hasClass('active')) {
            mc_drax_loadProductMeta();
        }

        // Load dummy products button click
        $('#load-dummy-products').on('click', function () {
            mc_drax_loadDummyProducts();
        });

        // Auto-load products when manage tab is shown on page load
        if ($('#manage-products-tab').hasClass('active')) {
            mc_drax_loadDummyProducts();
        }
    }

    /**
     * Initialize delete confirmation handlers
     * 
     * @since 1.0.0
     */
    function mc_drax_initDeleteConfirmations() {
        // Add confirmation for individual post deletion
        $(document).on('click', '.draxira-button-danger, .button-link-delete', function (e) {
            var confirmMessage = $(this).data('confirm-message') || draxira_ajax.confirm_delete || 'Are you sure? This action cannot be undone.';

            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }

            return true;
        });

        // Handle delete buttons that are links
        $(document).on('click', '.button-danger', function (e) {
            if ($(this).text().indexOf('Delete') !== -1 && !$(this).hasClass('confirmed')) {
                e.preventDefault();
                var href = $(this).attr('href');
                var confirmMsg = $(this).data('confirm-message') || draxira_ajax.confirm_delete_post || 'Are you sure? This will delete the post and all its meta data.';

                if (confirm(confirmMsg)) {
                    $(this).addClass('confirmed');
                    window.location.href = href;
                }
            }
        });
    }

    /**
     * Load post meta configuration via AJAX
     * 
     * @since 1.0.0
     */
    function mc_drax_loadPostMeta() {
        var postType = $('#post-type-selector').val();

        $.ajax({
            url: draxira_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'draxira_get_post_meta',
                post_type: postType,
                nonce: draxira_ajax.nonce
            },
            beforeSend: function () {
                $('#post-meta-configuration').html('<div class="draxira-loading"><p>' + draxira_ajax.loading_message + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#post-meta-configuration').html(response.data);
                } else {
                    $('#post-meta-configuration').html('<div class="draxira-error"><p>' + draxira_ajax.error_message + '</p></div>');
                }
            },
            error: function () {
                $('#post-meta-configuration').html('<div class="draxira-error"><p>' + draxira_ajax.error_message + '</p></div>');
            }
        });
    }

    /**
     * Load dummy posts list via AJAX
     * 
     * @since 1.0.0
     */
    function mc_drax_loadDummyPosts() {
        var postType = $('#filter-post-type').val();
        var nonce = draxira_ajax.nonce;

        $.ajax({
            url: draxira_ajax.ajax_url,
            type: 'GET',
            data: {
                action: 'draxira_get_dummy_posts',
                post_type: postType,
                _wpnonce: nonce
            },
            beforeSend: function () {
                $('#dummy-posts-list').html('<div class="draxira-loading"><p>' + draxira_ajax.loading_posts + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#dummy-posts-list').html(response.data);
                    $('#delete-section').show();
                } else {
                    $('#dummy-posts-list').html('<div class="draxira-notice draxira-notice-warning"><p>' + response.data + '</p></div>');
                    $('#delete-section').hide();
                }
            },
            error: function () {
                $('#dummy-posts-list').html('<div class="draxira-error"><p>' + draxira_ajax.error_loading_posts + '</p></div>');
                $('#delete-section').hide();
            }
        });
    }

    /**
     * Load product meta configuration via AJAX
     * 
     * @since 1.0.0
     */
    function mc_drax_loadProductMeta() {
        $.ajax({
            url: draxira_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'draxira_get_product_meta',
                nonce: draxira_ajax.nonce
            },
            beforeSend: function () {
                $('#product-meta-configuration').html('<div class="draxira-loading"><p>' + draxira_ajax.loading_products + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#product-meta-configuration').html(response.data);
                } else {
                    $('#product-meta-configuration').html('<div class="draxira-error"><p>' + draxira_ajax.error_message + '</p></div>');
                }
            },
            error: function () {
                $('#product-meta-configuration').html('<div class="draxira-error"><p>' + draxira_ajax.error_message + '</p></div>');
            }
        });
    }

    /**
     * Load dummy products list via AJAX
     * 
     * @since 1.0.0
     */
    function mc_drax_loadDummyProducts() {
        $.ajax({
            url: draxira_ajax.ajax_url,
            type: 'GET',
            data: {
                action: 'draxira_get_dummy_products',
                _wpnonce: draxira_ajax.nonce
            },
            beforeSend: function () {
                $('#dummy-products-list').html('<div class="draxira-loading"><p>' + draxira_ajax.loading_products + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#dummy-products-list').html(response.data);
                    $('#delete-section').show();
                } else {
                    $('#dummy-products-list').html('<div class="draxira-notice draxira-notice-warning"><p>' + response.data + '</p></div>');
                    $('#delete-section').hide();
                }
            },
            error: function () {
                $('#dummy-products-list').html('<div class="draxira-error"><p>' + draxira_ajax.error_loading_products + '</p></div>');
                $('#delete-section').hide();
            }
        });
    }

})(jQuery);