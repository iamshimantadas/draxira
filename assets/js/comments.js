(function ($) {
    'use strict';

    /**
     * Initialize on document ready
     * 
     * @since 1.0.0
     */
    $(document).ready(function () {
        initCommentsHandlers();
        initTabSwitching();
        loadDummyCommentsOnLoad();
    });

    /**
     * Initialize tab switching for comments page
     * 
     * @since 1.0.0
     */
    function initTabSwitching() {
        $('.nav-tab-wrapper a').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).attr('href');

            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            $('.tab-content').removeClass('active');
            $(tab).addClass('active');

            if (tab === '#delete-comments-tab') {
                loadDummyComments();
            }
        });
    }

    /**
     * Initialize comments page handlers
     * 
     * @since 1.0.0
     */
    function initCommentsHandlers() {
        // Post type selection change
        $('#comment-post-type').on('change', function () {
            var postType = $(this).val();
            if (postType) {
                loadPostsForComments(postType);
            } else {
                $('#posts-list-wrapper').html('<p class="description">' + draxira_comments_ajax.select_post_type_first + '</p>');
            }
        });

        // Select all posts checkbox
        $(document).on('change', '#select-all-posts', function () {
            $('.post-checkbox').prop('checked', $(this).prop('checked'));
        });

        // Load dummy comments button
        $('#load-dummy-comments').on('click', function () {
            loadDummyComments();
        });
    }

    /**
     * Load dummy comments on page load if delete tab is active
     * 
     * @since 1.0.0
     */
    function loadDummyCommentsOnLoad() {
        if ($('#delete-comments-tab').hasClass('active')) {
            loadDummyComments();
        }
    }

    /**
     * Load posts for selected post type
     * 
     * @since 1.0.0
     * @param {string} postType - The post type slug
     */
    function loadPostsForComments(postType) {
        $.ajax({
            url: draxira_comments_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'draxira_get_posts_for_comments',
                post_type: postType,
                nonce: draxira_comments_ajax.nonce
            },
            beforeSend: function () {
                $('#posts-list-wrapper').html('<div class="draxira-loading"><p>' + draxira_comments_ajax.loading_posts + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#posts-list-wrapper').html(response.data);
                } else {
                    $('#posts-list-wrapper').html('<div class="draxira-notice draxira-notice-warning"><p>' + response.data + '</p></div>');
                }
            },
            error: function () {
                $('#posts-list-wrapper').html('<div class="draxira-error"><p>' + draxira_comments_ajax.error_loading_posts + '</p></div>');
            }
        });
    }

    /**
     * Load dummy comments list
     * 
     * @since 1.0.0
     */
    function loadDummyComments() {
        $.ajax({
            url: draxira_comments_ajax.ajax_url,
            type: 'GET',
            data: {
                action: 'draxira_get_dummy_comments',
                _wpnonce: draxira_comments_ajax.nonce
            },
            beforeSend: function () {
                $('#dummy-comments-list').html('<div class="draxira-loading"><p>' + draxira_comments_ajax.loading_comments + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#dummy-comments-list').html(response.data);
                    $('#delete-section').show();
                } else {
                    $('#dummy-comments-list').html('<div class="draxira-notice draxira-notice-warning"><p>' + response.data + '</p></div>');
                    $('#delete-section').hide();
                }
            },
            error: function () {
                $('#dummy-comments-list').html('<div class="draxira-error"><p>' + draxira_comments_ajax.error_loading_comments + '</p></div>');
                $('#delete-section').hide();
            }
        });
    }

})(jQuery);