(function ($) {
    'use strict';

    var cfg = window.draxira_orders_ajax || {};
    var selected = {}; // product id => true (survives searches)
    var searchTimer = null;

    $(document).ready(function () {
        if (!$('#generate-orders-form').length) {
            return;
        }

        initCustomer();
        initDates();
        initAddress();
        initProducts();
        initMeta();
        initManage();
        initSubmit();
        initTabs();
    });

    /**
     * AJAX helper - always POST with the nonce.
     */
    function post(action, data) {
        return $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            data: $.extend({ action: action, nonce: cfg.nonce }, data || {})
        });
    }

    function loading(text) {
        return $('<div class="draxira-loading"></div>').append($('<p></p>').text(text));
    }

    function errorBox(text) {
        return $('<div class="draxira-error"></div>').append($('<p></p>').text(text));
    }

    /* ---------------- Customer ---------------- */
    function initCustomer() {
        function update() {
            var mode = $('input[name="customer_mode"]:checked').val();
            $('#customer-hint').text(cfg.hint_customer[mode] || '');
            $('#customer-specific-wrap').toggle(mode === 'specific');
        }
        $('input[name="customer_mode"]').on('change', update);
        update();
    }

    /* ---------------- Dates ---------------- */
    function initDates() {
        function update() {
            var mode = $('input[name="date_mode"]:checked').val();
            $('#date-hint').text(cfg.hint_date[mode] || '');
            $('#date-to-wrap').toggle(mode === 'range');
            $('#date-from-label').text(mode === 'range' ? 'Start date' : 'Date');
        }
        $('input[name="date_mode"]').on('change', update);
        update();
    }

    /* ---------------- Address ---------------- */
    function initAddress() {
        var $country = $('#address-country');
        var $state = $('#address-state');

        function setStates(states) {
            $state.empty().append($('<option></option>').val('random').text(cfg.random_state));
            var keys = states ? Object.keys(states) : [];
            $.each(keys, function (_, code) {
                $state.append($('<option></option>').val(code).text(states[code]));
            });
            $state.prop('disabled', false);
            $('#state-hint').text(keys.length ? '' : cfg.no_states);
        }

        $country.on('change', function () {
            var country = $(this).val();
            if (country === 'random') {
                $state.empty().append($('<option></option>').val('random').text(cfg.random_state)).prop('disabled', true);
                $('#state-hint').text('');
                return;
            }
            post('draxira_orders_get_states', { country: country })
                .done(function (response) {
                    setStates(response && response.success ? response.data.states : {});
                })
                .fail(function () {
                    $('#state-hint').text(cfg.error);
                });
        });

        if ($country.val() === 'random') {
            $country.trigger('change');
        }
    }

    /* ---------------- Products ---------------- */
    function updateSelectedCount() {
        var n = Object.keys(selected).length;
        $('#products-selected-count').text(cfg.selected_count.replace('%d', n));
    }

    function loadProducts(search) {
        var $list = $('#product-list');
        $list.empty().append(loading(cfg.loading));

        post('draxira_orders_get_products', { search: search || '' })
            .done(function (response) {
                if (response && response.success) {
                    $list.html(response.data);
                    $list.find('.dx-product-cb').each(function () {
                        if (selected[$(this).val()]) {
                            $(this).prop('checked', true);
                        }
                    });
                } else {
                    $list.empty().append(errorBox(cfg.error));
                }
            })
            .fail(function () {
                $list.empty().append(errorBox(cfg.error));
            });
    }

    function initProducts() {
        var loaded = false;

        function update() {
            var mode = $('input[name="product_mode"]:checked').val();
            $('#product-picker').toggle(mode === 'selected');
            if (mode === 'selected' && !loaded) {
                loaded = true;
                loadProducts('');
            }
        }

        $('input[name="product_mode"]').on('change', update);
        update();
        updateSelectedCount();

        $(document).on('change', '.dx-product-cb', function () {
            if ($(this).is(':checked')) {
                selected[$(this).val()] = true;
            } else {
                delete selected[$(this).val()];
            }
            updateSelectedCount();
        });

        $('#products-select-all').on('click', function () {
            $('.dx-product-cb').each(function () {
                $(this).prop('checked', true);
                selected[$(this).val()] = true;
            });
            updateSelectedCount();
        });

        $('#products-select-none').on('click', function () {
            selected = {};
            $('.dx-product-cb').prop('checked', false);
            updateSelectedCount();
        });

        $('#product-search').on('input', function () {
            var term = $(this).val();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                loadProducts(term);
            }, 350);
        });
    }

    /* ---------------- Meta ---------------- */
    function initMeta() {
        var $wrap = $('#order-meta-configuration');
        $wrap.empty().append(loading(cfg.loading));

        post('draxira_orders_get_meta_fields')
            .done(function (response) {
                if (response && response.success) {
                    $wrap.html(response.data);
                } else {
                    $wrap.empty().append(errorBox(cfg.error));
                }
            })
            .fail(function () {
                $wrap.empty().append(errorBox(cfg.error));
            });

        var index = 0;
        $('#add-custom-meta').on('click', function () {
            var i = index++;
            var $row = $('<div class="dx-custom-meta-row"></div>');
            var $key = $('<input type="text" class="regular-text">')
                .attr('name', 'order_custom_meta[' + i + '][key]')
                .attr('placeholder', cfg.meta_key_placeholder);
            var $type = $('<select></select>').attr('name', 'order_custom_meta[' + i + '][type]');

            $.each(cfg.custom_meta_types, function (value, label) {
                $type.append($('<option></option>').val(value).text(label));
            });

            var $remove = $('<button type="button" class="button"></button>').text(cfg.remove).on('click', function () {
                $row.remove();
            });

            $row.append($key, $type, $remove);
            $('#custom-meta-rows').append($row);
        });
    }

    /* ---------------- Manage tab ---------------- */
    function loadDummyOrders() {
        var $list = $('#dummy-orders-list');
        $list.empty().append(loading(cfg.loading));

        post('draxira_orders_get_dummy_orders')
            .done(function (response) {
                if (response && response.success) {
                    $list.html(response.data);
                    $('#delete-section').show();
                } else {
                    $list.empty().append($('<div class="draxira-notice draxira-notice-warning"></div>').append($('<p></p>').text(response && response.data ? response.data : cfg.error)));
                    $('#delete-section').hide();
                }
            })
            .fail(function () {
                $list.empty().append(errorBox(cfg.error));
                $('#delete-section').hide();
            });
    }

    function initManage() {
        $('#load-dummy-orders').on('click', loadDummyOrders);
    }

    function initTabs() {
        // admin.js already switches tabs; here we only load data and honour #hash links.
        $('.nav-tab-wrapper a').on('click', function () {
            if ($(this).attr('href') === '#manage-orders-tab') {
                loadDummyOrders();
            }
        });

        if (window.location.hash === '#manage-orders-tab') {
            $('.nav-tab-wrapper a[href="#manage-orders-tab"]').trigger('click');
        }
    }

    /* ---------------- Submit ---------------- */
    function initSubmit() {
        $('#generate-orders-form').on('submit', function () {
            // Selected products are sent as hidden inputs so they survive searches.
            $(this).find('input.dx-selected-product').remove();
            var form = this;
            if ($('input[name="product_mode"]:checked').val() === 'selected') {
                $.each(Object.keys(selected), function (_, id) {
                    $('<input type="hidden" class="dx-selected-product" name="product_ids[]">').val(id).appendTo(form);
                });
            }
        });
    }

})(jQuery);
