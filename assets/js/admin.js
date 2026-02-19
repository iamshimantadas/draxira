/**
 * WP Dummy Content Filler - Admin JavaScript
 * 
 * Handles all admin-side interactions, AJAX requests, and UI behaviors
 * for the dummy content generation plugin.
 *
 * @package WP_Dummy_Content_Filler
 * @subpackage Assets
 * @since 1.0.0
 * @author Shimanta Das
 * @copyright 2026 Microcodes
 */

(function($) {
    'use strict';

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        initTabSwitching();
        initPostTypeHandlers();
        initDeleteConfirmations();
        initProductHandlers();
    });

    /**
     * Initialize tab switching functionality
     * 
     * @since 1.0.0
     */
    function initTabSwitching() {
        $('.nav-tab-wrapper a').on('click', function(e) {
            e.preventDefault();
            var tab = $(this).attr('href');

            // Update active tab
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            // Show selected tab content
            $('.tab-content').removeClass('active');
            $(tab).addClass('active');

            // Load content based on active tab
            handleTabContentLoad(tab);
        });
    }

    /**
     * Handle content loading when tabs are switched
     * 
     * @since 1.0.0
     * @param {string} tab - The tab identifier
     */
    function handleTabContentLoad(tab) {
        // Load dummy posts list when manage tab is shown
        if (tab === '#manage-tab') {
            loadDummyPosts();
        }

        // Load post meta when generate tab is shown
        if (tab === '#generate-tab') {
            loadPostMeta();
        }

        // Load product meta when product generate tab is shown
        if (tab === '#generate-products-tab') {
            loadProductMeta();
        }

        // Load dummy products when product manage tab is shown
        if (tab === '#manage-products-tab') {
            loadDummyProducts();
        }
    }

    /**
     * Initialize post type related handlers
     * 
     * @since 1.0.0
     */
    function initPostTypeHandlers() {
        // Load post meta on page load if generate tab is active
        if ($('#generate-tab').hasClass('active')) {
            loadPostMeta();
        }

        // Load post meta when post type changes
        $('#post-type-selector').on('change', function() {
            loadPostMeta();
        });

        // Apply filter button click
        $('#apply-filter').on('click', function() {
            loadDummyPosts();
        });

        // Auto-load posts when manage tab is shown on page load
        if ($('#manage-tab').hasClass('active')) {
            loadDummyPosts();
        }

        // Set delete post type from filter
        $('#filter-post-type').on('change', function() {
            $('#delete-post-type').val($(this).val());
        });
    }

    /**
     * Initialize product related handlers
     * 
     * @since 1.0.0
     */
    function initProductHandlers() {
        // Load product meta on page load if generate tab is active
        if ($('#generate-products-tab').length && $('#generate-products-tab').hasClass('active')) {
            loadProductMeta();
        }

        // Load dummy products button click
        $('#load-dummy-products').on('click', function() {
            loadDummyProducts();
        });

        // Auto-load products when manage tab is shown on page load
        if ($('#manage-products-tab').hasClass('active')) {
            loadDummyProducts();
        }
    }

    /**
     * Initialize delete confirmation handlers
     * 
     * @since 1.0.0
     */
    function initDeleteConfirmations() {
        // Add confirmation for individual post deletion
        $(document).on('click', '.button-danger', function(e) {
            if ($(this).text().indexOf('Delete') !== -1 && !$(this).hasClass('confirmed')) {
                e.preventDefault();
                var href = $(this).attr('href');
                if (confirm('Are you sure? This will delete the post and all its meta data.')) {
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
    function loadPostMeta() {
        var postType = $('#post-type-selector').val();

        $.ajax({
            url: wpdcf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wpdcf_get_post_meta',
                post_type: postType,
                nonce: wpdcf_ajax.nonce
            },
            beforeSend: function() {
                $('#post-meta-configuration').html('<div class="loading"><p>Loading post configuration...</p></div>');
            },
            success: function(response) {
                if (response.success) {
                    $('#post-meta-configuration').html(response.data);
                } else {
                    $('#post-meta-configuration').html('<div class="error"><p>Error loading post configuration.</p></div>');
                }
            },
            error: function() {
                $('#post-meta-configuration').html('<div class="error"><p>Error loading post configuration.</p></div>');
            }
        });
    }

    /**
     * Load dummy posts list via AJAX
     * 
     * @since 1.0.0
     */
    function loadDummyPosts() {
        var postType = $('#filter-post-type').val();

        $.ajax({
            url: wpdcf_ajax.ajax_url,
            type: 'GET',
            data: {
                action: 'wpdcf_get_dummy_posts',
                post_type: postType
            },
            beforeSend: function() {
                $('#dummy-posts-list').html('<div class="loading"><p>Loading dummy posts...</p></div>');
            },
            success: function(response) {
                if (response.success) {
                    $('#dummy-posts-list').html(response.data);
                    $('#delete-section').show();
                } else {
                    $('#dummy-posts-list').html('<div class="notice notice-warning"><p>' + response.data + '</p></div>');
                    $('#delete-section').hide();
                }
            },
            error: function() {
                $('#dummy-posts-list').html('<div class="error"><p>Error loading posts.</p></div>');
                $('#delete-section').hide();
            }
        });
    }

    /**
     * Load product meta configuration via AJAX
     * 
     * @since 1.0.0
     */
    function loadProductMeta() {
        $.ajax({
            url: wpdcf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wpdcf_get_product_meta',
                nonce: wpdcf_ajax.nonce
            },
            beforeSend: function() {
                $('#product-meta-configuration').html('<div class="loading"><p>Loading product configuration...</p></div>');
            },
            success: function(response) {
                if (response.success) {
                    $('#product-meta-configuration').html(response.data);
                } else {
                    $('#product-meta-configuration').html('<div class="error"><p>Error loading product configuration.</p></div>');
                }
            },
            error: function() {
                $('#product-meta-configuration').html('<div class="error"><p>Error loading product configuration.</p></div>');
            }
        });
    }

    /**
     * Load dummy products list via AJAX
     * 
     * @since 1.0.0
     */
    function loadDummyProducts() {
        $.ajax({
            url: wpdcf_ajax.ajax_url,
            type: 'GET',
            data: {
                action: 'wpdcf_get_dummy_products'
            },
            beforeSend: function() {
                $('#dummy-products-list').html('<div class="loading"><p>Loading dummy products...</p></div>');
            },
            success: function(response) {
                if (response.success) {
                    $('#dummy-products-list').html(response.data);
                    $('#delete-section').show();
                } else {
                    $('#dummy-products-list').html('<div class="notice notice-warning"><p>' + response.data + '</p></div>');
                    $('#delete-section').hide();
                }
            },
            error: function() {
                $('#dummy-products-list').html('<div class="error"><p>Error loading products.</p></div>');
                $('#delete-section').hide();
            }
        });
    }

})(jQuery);