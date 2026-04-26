(function ($) {
    'use strict';

    /**
     * Initialize on document ready
     * 
     * @since 1.0.0
     */
    $(document).ready(function () {
        initTaxonomiesHandlers();
        initTabSwitching();
        loadDummyTermsOnLoad();
    });

    /**
     * Initialize tab switching for taxonomies page
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

            if (tab === '#delete-terms-tab') {
                loadDummyTerms();
            }
        });
    }

    /**
     * Initialize taxonomies page handlers
     * 
     * @since 1.0.0
     */
    function initTaxonomiesHandlers() {
        // Post type selection change
        $('#taxonomy-post-type').on('change', function () {
            var postType = $(this).val();
            if (postType) {
                loadTaxonomiesForPostType(postType);
            } else {
                $('#taxonomies-list-wrapper').html('<p class="description">' + draxira_taxonomies_ajax.select_post_type_first + '</p>');
                $('#term-meta-configuration').html('');
            }
        });

        // Select all taxonomies checkbox
        $(document).on('change', '#select-all-taxonomies', function () {
            $('.taxonomy-checkbox').prop('checked', $(this).prop('checked'));
            loadTermMetaForSelectedTaxonomies();
        });

        // Individual taxonomy checkbox change
        $(document).on('change', '.taxonomy-checkbox', function () {
            loadTermMetaForSelectedTaxonomies();
        });

        // Load dummy terms button
        $('#load-dummy-terms').on('click', function () {
            loadDummyTerms();
        });
    }

    /**
     * Load dummy terms on page load if delete tab is active
     * 
     * @since 1.0.0
     */
    function loadDummyTermsOnLoad() {
        if ($('#delete-terms-tab').hasClass('active')) {
            loadDummyTerms();
        }
    }

    /**
     * Load taxonomies for selected post type
     * 
     * @since 1.0.0
     * @param {string} postType - The post type slug
     */
    function loadTaxonomiesForPostType(postType) {
        $.ajax({
            url: draxira_taxonomies_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'draxira_get_taxonomies_for_post_type',
                post_type: postType,
                nonce: draxira_taxonomies_ajax.nonce
            },
            beforeSend: function () {
                $('#taxonomies-list-wrapper').html('<div class="draxira-loading"><p>' + draxira_taxonomies_ajax.loading_taxonomies + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#taxonomies-list-wrapper').html(response.data);
                } else {
                    $('#taxonomies-list-wrapper').html('<div class="draxira-notice draxira-notice-warning"><p>' + response.data + '</p></div>');
                }
            },
            error: function () {
                $('#taxonomies-list-wrapper').html('<div class="draxira-error"><p>' + draxira_taxonomies_ajax.error_loading_taxonomies + '</p></div>');
            }
        });
    }

    /**
     * Load term meta fields for selected taxonomies
     * 
     * @since 1.0.0
     */
    function loadTermMetaForSelectedTaxonomies() {
        var selectedTaxonomies = [];
        $('.taxonomy-checkbox:checked').each(function () {
            selectedTaxonomies.push($(this).val());
        });

        if (selectedTaxonomies.length === 0) {
            $('#term-meta-configuration').html('');
            return;
        }

        // For now, just load meta for the first selected taxonomy
        var taxonomy = selectedTaxonomies[0];

        $.ajax({
            url: draxira_taxonomies_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'draxira_get_term_meta_fields',
                taxonomy: taxonomy,
                nonce: draxira_taxonomies_ajax.nonce
            },
            beforeSend: function () {
                $('#term-meta-configuration').html('<div class="draxira-loading"><p>' + draxira_taxonomies_ajax.loading_meta_fields + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#term-meta-configuration').html(response.data);
                } else {
                    $('#term-meta-configuration').html('<div class="draxira-notice draxira-notice-warning"><p>' + response.data + '</p></div>');
                }
            },
            error: function () {
                $('#term-meta-configuration').html('<div class="draxira-error"><p>' + draxira_taxonomies_ajax.error_loading_meta + '</p></div>');
            }
        });
    }

    /**
     * Load dummy taxonomy terms list
     * 
     * @since 1.0.0
     */
    function loadDummyTerms() {
        $.ajax({
            url: draxira_taxonomies_ajax.ajax_url,
            type: 'GET',
            data: {
                action: 'draxira_get_dummy_terms',
                _wpnonce: draxira_taxonomies_ajax.nonce
            },
            beforeSend: function () {
                $('#dummy-terms-list').html('<div class="draxira-loading"><p>' + draxira_taxonomies_ajax.loading_terms + '</p></div>');
            },
            success: function (response) {
                if (response.success) {
                    $('#dummy-terms-list').html(response.data);
                    $('#delete-section').show();
                } else {
                    $('#dummy-terms-list').html('<div class="draxira-notice draxira-notice-warning"><p>' + response.data + '</p></div>');
                    $('#delete-section').hide();
                }
            },
            error: function () {
                $('#dummy-terms-list').html('<div class="draxira-error"><p>' + draxira_taxonomies_ajax.error_loading_terms + '</p></div>');
                $('#delete-section').hide();
            }
        });
    }

})(jQuery);