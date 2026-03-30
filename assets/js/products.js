(function ($) {
    'use strict';

    $(document).ready(function () {
        // Load product meta when generate tab is active
        if ($('#generate-products-tab').hasClass('active')) {
            loadProductMeta();
        }

        // Load dummy products when manage tab is active
        if ($('#manage-products-tab').hasClass('active')) {
            loadDummyProducts();
        }

        // Handle tab switching
        $('.nav-tab-wrapper a').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).attr('href');

            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            $('.tab-content').removeClass('active');
            $(tab).addClass('active');

            if (tab === '#generate-products-tab') {
                loadProductMeta();
            }

            if (tab === '#manage-products-tab') {
                loadDummyProducts();
            }
        });

        // Load dummy products button
        $('#load-dummy-products').on('click', function () {
            loadDummyProducts();
        });
    });

    function loadProductMeta() {
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

    function loadDummyProducts() {
        $.ajax({
            url: draxira_ajax.ajax_url,
            type: 'GET',
            data: {
                action: 'draxira_get_dummy_products',
                _wpnonce: draxira_ajax.nonce
            },
            beforeSend: function () {
                $('#dummy-products-list').html('<div class="draxira-loading"><p>' + draxira_products_ajax.loading_products + '</p></div>');
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
                $('#dummy-products-list').html('<div class="draxira-error"><p>' + draxira_products_ajax.error_loading_products + '</p></div>');
                $('#delete-section').hide();
            }
        });
    }
})(jQuery);