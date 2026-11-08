<?php
/**
 * WooCommerce Orders Dummy Content Generator
 *
 * Generates dummy orders through WooCommerce's CRUD API (wc_create_order,
 * WC_Order::add_product, calculate_totals ...), so it works with both legacy
 * post-based storage and High-Performance Order Storage (HPOS).
 *
 * @package Draxira
 * @subpackage Includes
 * @since 1.0.7
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Draxira_Orders
 *
 * @since 1.0.7
 */
class Draxira_Orders
{
    /**
     * Max orders per run (generation is synchronous).
     */
    const MAX_ORDERS = 500;

    /**
     * Max rows in the manage list.
     */
    const LIST_LIMIT = 100;

    /**
     * @var Draxira_Orders|null
     */
    private static $instance = null;

    /**
     * Message of the last order failure (shown to the admin).
     *
     * @var string
     */
    private $last_error = '';

    /**
     * @return Draxira_Orders
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu'], 11);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_action('wp_ajax_draxira_orders_get_states', [$this, 'ajax_get_states']);
        add_action('wp_ajax_draxira_orders_get_products', [$this, 'ajax_get_products']);
        add_action('wp_ajax_draxira_orders_get_meta_fields', [$this, 'ajax_get_meta_fields']);
        add_action('wp_ajax_draxira_orders_get_dummy_orders', [$this, 'ajax_get_dummy_orders']);

        // Background (AJAX batch) generation
        Draxira_Batch::register('orders', [
            'prepare' => [$this, 'batch_prepare'],
            'step' => [$this, 'batch_step'],
            'finish' => [$this, 'batch_finish'],
        ]);
    }

    /**
     * Is WooCommerce loaded with the order API we need?
     *
     * @return bool
     */
    private function is_wc_active()
    {
        return class_exists('WooCommerce') && function_exists('wc_create_order') && function_exists('wc_get_orders');
    }

    /**
     * Register the submenu (always visible; the page explains when WooCommerce is missing).
     *
     * @return void
     */
    public function add_menu()
    {
        add_submenu_page(
            'draxira',
            'Woo Orders',
            'Woo Orders',
            'manage_options',
            'draxira-orders',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue the orders script on the orders page only.
     *
     * @param string $hook Admin page hook.
     * @return void
     */
    public function enqueue_scripts($hook)
    {
        if ('draxira_page_draxira-orders' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'draxira-orders',
            DRAXIRA_PLUGIN_URL . 'assets/js/orders.js',
            ['jquery', 'draxira-admin'],
            DRAXIRA_VERSION,
            true
        );

        wp_localize_script('draxira-orders', 'draxira_orders_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('draxira_ajax_nonce'),
            'loading' => __('Loading…', 'draxira'),
            'error' => __('Something went wrong. Please reload the page and try again.', 'draxira'),
            'random_state' => __('Random state / region', 'draxira'),
            'no_states' => __('This country has no states / regions.', 'draxira'),
            'selected_count' => __('%d selected', 'draxira'),
            'generating' => __('Generating orders… please keep this page open.', 'draxira'),
            'remove' => __('Remove', 'draxira'),
            'hint_customer' => [
                'specific' => __('Every generated order is assigned to the user you pick below.', 'draxira'),
                'random_user' => __('Each order is assigned to a random existing Customer or Subscriber account.', 'draxira'),
                'random' => __('Each order randomly gets either a registered user or a guest with generated details.', 'draxira'),
            ],
            'hint_date' => [
                'single' => __('All orders land on this day, at different random times.', 'draxira'),
                'range' => __('Each order gets a random date and time inside this range (inclusive).', 'draxira'),
            ],
            'custom_meta_types' => Draxira_Helpers::faker_types(),
            'leave_untouched' => __('— Leave untouched —', 'draxira'),
            'meta_key_placeholder' => __('meta_key', 'draxira'),
        ]);
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ---------------------------------------------------------------- */

    /**
     * Capability + nonce + WooCommerce check shared by all AJAX endpoints.
     *
     * @return void Sends a JSON error and exits when a check fails.
     */
    private function verify_ajax()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, 'draxira_ajax_nonce')) {
            wp_send_json_error(__('Security check failed.', 'draxira'), 403);
        }
        if (!$this->is_wc_active()) {
            wp_send_json_error(__('WooCommerce is required for this feature.', 'draxira'));
        }
    }

    /**
     * States of a country as JSON.
     *
     * @return void
     */
    public function ajax_get_states()
    {
        $this->verify_ajax();

        $country = isset($_POST['country']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['country']))) : '';
        $countries = WC()->countries->get_countries();
        if (!isset($countries[$country])) {
            wp_send_json_success(['states' => new stdClass()]);
        }

        $states = WC()->countries->get_states($country);
        wp_send_json_success(['states' => (is_array($states) && $states) ? $states : new stdClass()]);
    }

    /**
     * Product checklist (optionally filtered by a search term).
     *
     * @return void
     */
    public function ajax_get_products()
    {
        $this->verify_ajax();

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 200,
            's' => $search,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        if (empty($query->posts)) {
            wp_send_json_success('<p class="description">' . esc_html__('No published products found.', 'draxira') . '</p>');
        }

        ob_start();
        foreach ($query->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $sku = $product->get_sku();
            ?>
            <label class="dx-product">
                <input type="checkbox" class="dx-product-cb" value="<?php echo esc_attr($product_id); ?>">
                <span class="dx-product-name">
                    <?php echo esc_html($product->get_name()); ?>
                    <?php if ($sku): ?><small><?php echo esc_html($sku); ?></small><?php endif; ?>
                </span>
                <span class="dx-badge"><?php echo esc_html($product->get_type()); ?></span>
                <span class="dx-product-price"><?php echo wp_kses_post($product->get_price() !== '' ? wc_price($product->get_price()) : '—'); ?></span>
            </label>
            <?php
        }
        $html = ob_get_clean();

        if ($query->found_posts > count($query->posts)) {
            $html .= '<p class="description">' . esc_html(sprintf(
                /* translators: 1: shown, 2: total */
                __('Showing %1$d of %2$d products. Use the search box to narrow the list.', 'draxira'),
                count($query->posts),
                $query->found_posts
            )) . '</p>';
        }

        wp_send_json_success($html);
    }

    /**
     * Meta field configuration table.
     *
     * @return void
     */
    public function ajax_get_meta_fields()
    {
        $this->verify_ajax();

        $types = Draxira_Helpers::faker_types();
        $core = [
            'customer_note' => __('Customer provided note', 'draxira'),
            'transaction_id' => __('Transaction ID', 'draxira'),
        ];
        $custom = $this->detect_custom_meta_keys();
        $order_index = Draxira_Fields::index_for_post_type('shop_order');

        ob_start();
        ?>
        <h4><?php esc_html_e('WooCommerce order fields', 'draxira'); ?></h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Field', 'draxira'); ?></th>
                    <th><?php esc_html_e('Key', 'draxira'); ?></th>
                    <th><?php esc_html_e('Field Type', 'draxira'); ?></th>
                    <th><?php esc_html_e('Fill with', 'draxira'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($core as $key => $label): ?>
                    <tr>
                        <td><strong><?php echo esc_html($label); ?></strong></td>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td><?php echo wp_kses_post(Draxira_Fields::render_badge(Draxira_Fields::describe($key, []))); ?></td>
                        <td>
                            <select name="order_core[<?php echo esc_attr($key); ?>][type]">
                                <option value=""><?php esc_html_e('— Leave untouched —', 'draxira'); ?></option>
                                <?php foreach ($types as $type_value => $type_label): ?>
                                    <option value="<?php echo esc_attr($type_value); ?>"><?php echo esc_html($type_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h4><?php esc_html_e('Custom order meta (detected)', 'draxira'); ?></h4>
        <?php if (empty($custom)): ?>
            <p class="description"><?php esc_html_e('No custom order meta detected from ACF, CMB2 or existing orders. You can add keys manually below.', 'draxira'); ?></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Field', 'draxira'); ?></th>
                        <th><?php esc_html_e('Meta Key', 'draxira'); ?></th>
                        <th><?php esc_html_e('Field Type', 'draxira'); ?></th>
                        <th><?php esc_html_e('Fill with', 'draxira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($custom as $key => $label): ?>
                        <tr>
                            <td><strong><?php echo esc_html($label); ?></strong></td>
                            <td><code><?php echo esc_html($key); ?></code></td>
                            <td><?php echo wp_kses_post(Draxira_Fields::render_badge(Draxira_Fields::describe($key, $order_index))); ?></td>
                            <td>
                                <select name="order_meta[<?php echo esc_attr($key); ?>][type]">
                                    <option value=""><?php esc_html_e('— Leave untouched —', 'draxira'); ?></option>
                                    <?php foreach ($types as $type_value => $type_label): ?>
                                        <option value="<?php echo esc_attr($type_value); ?>"><?php echo esc_html($type_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        wp_send_json_success(ob_get_clean());
    }

    /**
     * Custom order meta keys from ACF, CMB2 and recent orders.
     *
     * @return array key => label
     */
    private function detect_custom_meta_keys()
    {
        $keys = [];

        if (function_exists('acf_get_field_groups')) {
            foreach ((array) acf_get_field_groups(['post_type' => 'shop_order']) as $group) {
                $fields = acf_get_fields($group['key']);
                if ($fields) {
                    foreach ($fields as $field) {
                        if (!empty($field['name'])) {
                            $keys[$field['name']] = !empty($field['label']) ? $field['label'] : $field['name'];
                        }
                    }
                }
            }
        }

        if (class_exists('CMB2_Boxes')) {
            foreach (CMB2_Boxes::get_all() as $cmb) {
                $object_types = $cmb->prop('object_types');
                if ($object_types && in_array('shop_order', (array) $object_types, true)) {
                    foreach ((array) $cmb->prop('fields') as $field) {
                        if (isset($field['id'])) {
                            $keys[$field['id']] = !empty($field['name']) ? $field['name'] : $field['id'];
                        }
                    }
                }
            }
        }

        // Non-underscore meta found on recent orders (underscore keys are WooCommerce internals).
        $recent = wc_get_orders(['limit' => 50, 'orderby' => 'date', 'order' => 'DESC']);
        foreach ($recent as $order) {
            foreach ($order->get_meta_data() as $meta) {
                $data = $meta->get_data();
                $key = isset($data['key']) ? (string) $data['key'] : '';
                if ('' === $key || 0 === strpos($key, '_') || isset($keys[$key])) {
                    continue;
                }
                $keys[$key] = ucwords(str_replace(['_', '-'], ' ', $key));
            }
        }

        asort($keys);
        return $keys;
    }

    /**
     * Manage tab: list of dummy orders.
     *
     * @return void
     */
    public function ajax_get_dummy_orders()
    {
        $this->verify_ajax();

        $result = wc_get_orders([
            'limit' => self::LIST_LIMIT,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => DRAXIRA_META_KEY,
                    'value' => '1',
                ],
            ],
        ]);

        if (empty($result->orders)) {
            wp_send_json_error(__('No dummy orders found.', 'draxira'));
        }

        ob_start();
        ?>
        <p>
            <?php
            echo esc_html(sprintf(
                /* translators: %d: number of dummy orders */
                _n('Found %d dummy order.', 'Found %d dummy orders.', (int) $result->total, 'draxira'),
                (int) $result->total
            ));
            if ($result->total > count($result->orders)) {
                echo ' ' . esc_html(sprintf(
                    /* translators: %d: rows shown */
                    __('Showing the latest %d.', 'draxira'),
                    count($result->orders)
                ));
            }
            ?>
        </p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Order', 'draxira'); ?></th>
                    <th><?php esc_html_e('Date', 'draxira'); ?></th>
                    <th><?php esc_html_e('Customer', 'draxira'); ?></th>
                    <th><?php esc_html_e('Status', 'draxira'); ?></th>
                    <th><?php esc_html_e('Items', 'draxira'); ?></th>
                    <th><?php esc_html_e('Total', 'draxira'); ?></th>
                    <th><?php esc_html_e('Actions', 'draxira'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result->orders as $order):
                    $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                    $date = $order->get_date_created();
                    ?>
                    <tr>
                        <td><strong>#<?php echo esc_html($order->get_order_number()); ?></strong></td>
                        <td><?php echo esc_html($date ? $date->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '—'); ?></td>
                        <td>
                            <?php echo esc_html('' !== $name ? $name : '—'); ?>
                            <?php if (!$order->get_customer_id()): ?>
                                <span class="dx-badge"><?php esc_html_e('Guest', 'draxira'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="dx-badge"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span></td>
                        <td><?php echo esc_html($order->get_item_count()); ?></td>
                        <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                        <td>
                            <a href="<?php echo esc_url($order->get_edit_order_url()); ?>" class="button button-small"><?php esc_html_e('Edit', 'draxira'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        wp_send_json_success(ob_get_clean());
    }

    /* ------------------------------------------------------------------
     * Form handling
     * ---------------------------------------------------------------- */

    /**
     * Store a result notice and go back to the orders page.
     *
     * @param string $message Message.
     * @param string $type    success|error.
     * @param string $hash    Optional tab anchor.
     * @return void
     */
    private function finish($message, $type = 'success', $hash = '')
    {
        set_transient('draxira_orders_results', ['message' => $message, 'type' => $type], 45);
        wp_safe_redirect(admin_url('admin.php?page=draxira-orders') . $hash);
        exit;
    }

    /**
     * Handle generate / clear submissions.
     *
     * @return void
     */
    public function handle_actions()
    {
        if (!isset($_POST['draxira_generate_orders']) && !isset($_POST['draxira_clear_orders'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

        if (isset($_POST['draxira_generate_orders'])) {
            if (!wp_verify_nonce($nonce, 'generate_dummy_orders')) {
                $this->finish(__('Security check failed. Please try again.', 'draxira'), 'error');
            }
            if (!$this->is_wc_active()) {
                $this->finish(__('WooCommerce is not installed or activated.', 'draxira'), 'error');
            }

            $this->generate_orders_sync(wp_unslash($_POST)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        }

        if (isset($_POST['draxira_clear_orders'])) {
            if (!wp_verify_nonce($nonce, 'clear_dummy_orders')) {
                $this->finish(__('Security check failed. Please try again.', 'draxira'), 'error', '#manage-orders-tab');
            }
            if (!$this->is_wc_active()) {
                $this->finish(__('WooCommerce is not installed or activated.', 'draxira'), 'error');
            }

            $deleted = $this->clear_dummy_orders();
            $this->finish(
                sprintf(
                    /* translators: %d: number of deleted orders */
                    _n('Successfully deleted %d dummy order.', 'Successfully deleted %d dummy orders.', $deleted, 'draxira'),
                    $deleted
                ),
                'success',
                '#manage-orders-tab'
            );
        }
    }

    /**
     * Validate a date string.
     *
     * @param string $value Y-m-d.
     * @return bool
     */
    private function is_valid_date($value)
    {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    /**
     * Clean a custom meta key typed by the admin.
     *
     * @param string $key Raw key.
     * @return string
     */
    private function clean_meta_key($key)
    {
        $key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
        if ('' === $key || DRAXIRA_META_KEY === $key) {
            return '';
        }
        return substr($key, 0, 191);
    }

    /**
     * Turn the raw form into a validated, sanitized config.
     *
     * @param array $post Unslashed $_POST.
     * @return array|WP_Error
     */
    private function parse_config(array $post)
    {
        $types = Draxira_Helpers::faker_types();
        $countries = WC()->countries->get_countries();
        $statuses = wc_get_order_statuses();

        $cfg = [];

        $cfg['count'] = isset($post['order_count']) ? max(1, min(self::MAX_ORDERS, absint($post['order_count']))) : 5;

        // Status.
        $status = isset($post['order_status']) ? sanitize_text_field($post['order_status']) : 'random';
        if ('random' !== $status && !isset($statuses['wc-' . $status])) {
            return new WP_Error('draxira', __('Invalid order status selected.', 'draxira'));
        }
        $cfg['status'] = $status;

        // Customer.
        $mode = isset($post['customer_mode']) ? sanitize_key($post['customer_mode']) : 'random';
        if (!in_array($mode, ['specific', 'random_user', 'random'], true)) {
            $mode = 'random';
        }
        $cfg['customer_mode'] = $mode;
        $cfg['customer_id'] = 0;
        $cfg['user_pool'] = [];

        if ('specific' === $mode) {
            $user_id = isset($post['customer_id']) ? absint($post['customer_id']) : 0;
            if (!$user_id || !get_userdata($user_id)) {
                return new WP_Error('draxira', __('Please choose a specific user for the orders.', 'draxira'));
            }
            $cfg['customer_id'] = $user_id;
        } else {
            $cfg['user_pool'] = $this->get_user_pool();
            if ('random_user' === $mode && empty($cfg['user_pool'])) {
                return new WP_Error('draxira', __('No eligible users (Customer / Subscriber / other non-admin accounts) were found. Generate users first or choose another customer option.', 'draxira'));
            }
        }

        // Dates.
        $date_mode = (isset($post['date_mode']) && 'range' === $post['date_mode']) ? 'range' : 'single';
        $today = wp_date('Y-m-d');
        $from = isset($post['date_from']) ? sanitize_text_field($post['date_from']) : '';
        $to = isset($post['date_to']) ? sanitize_text_field($post['date_to']) : '';

        if ('single' === $date_mode) {
            if ('' === $from) {
                $from = $today;
            }
            if (!$this->is_valid_date($from)) {
                return new WP_Error('draxira', __('Please enter a valid order date.', 'draxira'));
            }
            $to = $from;
        } else {
            if (!$this->is_valid_date($from) || !$this->is_valid_date($to)) {
                return new WP_Error('draxira', __('Please enter a valid start and end date for the range.', 'draxira'));
            }
            if ($from > $to) {
                $swap = $from;
                $from = $to;
                $to = $swap;
            }
        }
        $cfg['date_from'] = $from;
        $cfg['date_to'] = $to;

        // Address.
        $cfg['address_same'] = !(isset($post['address_mode']) && 'different' === $post['address_mode']);
        $country = isset($post['address_country']) ? strtoupper(sanitize_text_field($post['address_country'])) : '';
        if ('RANDOM' === $country || '' === $country) {
            $country = 'random';
        } elseif (!isset($countries[$country])) {
            return new WP_Error('draxira', __('Invalid country selected.', 'draxira'));
        }
        $cfg['country'] = $country;

        $state = isset($post['address_state']) ? sanitize_text_field($post['address_state']) : 'random';
        if ('random' !== $country && '' !== $state && 'random' !== $state) {
            $states = WC()->countries->get_states($country);
            if (!is_array($states) || !isset($states[$state])) {
                return new WP_Error('draxira', __('Invalid state / region for the selected country.', 'draxira'));
            }
        }
        $cfg['state'] = ('random' === $country) ? 'random' : $state;

        // Products.
        $cfg['product_mode'] = (isset($post['product_mode']) && 'selected' === $post['product_mode']) ? 'selected' : 'all';
        $cfg['product_ids'] = [];
        if ('selected' === $cfg['product_mode']) {
            $ids = isset($post['product_ids']) ? array_filter(array_map('absint', (array) $post['product_ids'])) : [];
            $cfg['product_ids'] = array_values(array_unique($ids));
            if (empty($cfg['product_ids'])) {
                return new WP_Error('draxira', __('Please select at least one product, or choose "All products".', 'draxira'));
            }
        }

        $min_products = isset($post['min_products']) ? max(1, min(20, absint($post['min_products']))) : 1;
        $max_products = isset($post['max_products']) ? max(1, min(20, absint($post['max_products']))) : 3;
        if ($min_products > $max_products) {
            $swap = $min_products;
            $min_products = $max_products;
            $max_products = $swap;
        }
        $cfg['min_products'] = $min_products;
        $cfg['max_products'] = $max_products;

        $min_qty = isset($post['min_qty']) ? max(1, min(99, absint($post['min_qty']))) : 1;
        $max_qty = isset($post['max_qty']) ? max(1, min(99, absint($post['max_qty']))) : 3;
        if ($min_qty > $max_qty) {
            $swap = $min_qty;
            $min_qty = $max_qty;
            $max_qty = $swap;
        }
        $cfg['min_qty'] = $min_qty;
        $cfg['max_qty'] = $max_qty;

        // Options.
        $cfg['add_shipping'] = !empty($post['add_shipping']);
        $cfg['reduce_stock'] = !empty($post['reduce_stock']);

        // Meta.
        $cfg['core'] = [];
        if (isset($post['order_core']) && is_array($post['order_core'])) {
            foreach (['customer_note', 'transaction_id'] as $field) {
                if (!empty($post['order_core'][$field]['type'])) {
                    $type = sanitize_key($post['order_core'][$field]['type']);
                    if (isset($types[$type])) {
                        $cfg['core'][$field] = $type;
                    }
                }
            }
        }

        $cfg['meta'] = [];
        if (isset($post['order_meta']) && is_array($post['order_meta'])) {
            foreach ($post['order_meta'] as $key => $row) {
                $key = $this->clean_meta_key($key);
                $type = (is_array($row) && !empty($row['type'])) ? sanitize_key($row['type']) : '';
                if ('' !== $key && isset($types[$type])) {
                    $cfg['meta'][$key] = $type;
                }
            }
        }
        if (isset($post['order_custom_meta']) && is_array($post['order_custom_meta'])) {
            foreach ($post['order_custom_meta'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = isset($row['key']) ? $this->clean_meta_key($row['key']) : '';
                $type = isset($row['type']) ? sanitize_key($row['type']) : '';
                if ('' !== $key && isset($types[$type])) {
                    $cfg['meta'][$key] = $type;
                }
            }
        }

        return $cfg;
    }

    /**
     * Users that can be picked as random customers.
     *
     * @return array User IDs.
     */
    private function get_user_pool()
    {
        $ids = get_users([
            'role__in' => ['customer', 'subscriber'],
            'fields' => 'ID',
            'number' => 500,
        ]);

        if (empty($ids)) {
            $ids = get_users([
                'role__not_in' => ['administrator'],
                'fields' => 'ID',
                'number' => 500,
            ]);
        }

        return array_map('absint', $ids);
    }

    /* ------------------------------------------------------------------
     * Generation
     * ---------------------------------------------------------------- */

    /**
     * Mail callback that sends nothing (so dummy orders never e-mail anyone).
     *
     * @return bool
     */
    public function noop_mail()
    {
        return true;
    }

    /**
     * Filter callback returning the no-op mail callable.
     *
     * @return array
     */
    public function mail_callback_filter()
    {
        return [$this, 'noop_mail'];
    }

    /**
     * Fallback for browsers without JavaScript: run the whole job in this request.
     *
     * @param array $post Unslashed form data.
     * @return void
     */
    private function generate_orders_sync(array $post)
    {
        $result = Draxira_Batch::run_sync('orders', $post);
        $this->finish($result['message'], $result['type']);
    }

    /**
     * Batch: validate the form and resolve the product pool.
     *
     * @since 1.0.8
     * @param array $data Unslashed form data.
     * @return array|WP_Error
     */
    public function batch_prepare($data)
    {
        if (!$this->is_wc_active()) {
            return new WP_Error('draxira_wc', __('WooCommerce is not installed or activated.', 'draxira'));
        }

        $cfg = $this->parse_config($data);
        if (is_wp_error($cfg)) {
            return $cfg;
        }

        $pool = $this->resolve_product_pool($cfg);
        if (empty($pool)) {
            return new WP_Error('draxira_pool', __('No orderable products found. Orders need published, priced simple/variable products.', 'draxira'));
        }

        // Sample the pool so a hopeless selection fails fast with a clear message.
        $sample = $pool;
        shuffle($sample);
        $has_orderable = false;
        foreach (array_slice($sample, 0, 40) as $product_id) {
            if ($this->resolve_line_product(wc_get_product($product_id))) {
                $has_orderable = true;
                break;
            }
        }
        if (!$has_orderable) {
            return new WP_Error('draxira_pool', __('None of the chosen products can be purchased (they need a price and a published status; variable products need purchasable variations).', 'draxira'));
        }

        return [
            'total' => $cfg['count'],
            'label' => __('Generating orders…', 'draxira'),
            'config' => $cfg,
            'state' => ['pool' => $pool, 'last_error' => ''],
        ];
    }

    /**
     * Batch: create one order.
     *
     * @since 1.0.8
     * @param array $job   Job (by reference).
     * @param int   $index Unit index.
     * @return bool
     */
    public function batch_step(&$job, $index)
    {
        $cfg = $job['config'];

        // Dummy customers must never receive real e-mails, and stock is untouched unless asked for.
        add_filter('woocommerce_mail_callback', [$this, 'mail_callback_filter'], 999);
        if (!$cfg['reduce_stock']) {
            add_filter('woocommerce_can_reduce_order_stock', '__return_false', 999);
        }

        $this->last_error = '';
        try {
            $order_id = $this->create_single_order($cfg, $job['state']['pool']);
        } catch (\Throwable $e) {
            $order_id = 0;
            $this->last_error = $e->getMessage();
        }

        remove_filter('woocommerce_mail_callback', [$this, 'mail_callback_filter'], 999);
        if (!$cfg['reduce_stock']) {
            remove_filter('woocommerce_can_reduce_order_stock', '__return_false', 999);
        }

        if (!$order_id && '' !== $this->last_error) {
            $job['state']['last_error'] = $this->last_error;
        }

        return (bool) $order_id;
    }

    /**
     * Batch: final notice.
     *
     * @since 1.0.8
     * @param array $job Job.
     * @return array
     */
    public function batch_finish($job)
    {
        $message = sprintf(
            /* translators: 1: generated orders, 2: order label, 3: failed orders */
            __('Successfully generated %1$d %2$s. Failed: %3$d', 'draxira'),
            $job['success'],
            _n('order', 'orders', $job['success'], 'draxira'),
            $job['failed']
        );
        if ($job['failed'] && !empty($job['state']['last_error'])) {
            $message .= ' ' . sprintf(
                /* translators: %s: error message */
                __('Last error: %s', 'draxira'),
                $job['state']['last_error']
            );
        }

        return ['message' => $message, 'type' => $job['success'] ? 'success' : 'error'];
    }

    /**
     * IDs of the products orders may draw from.
     *
     * @param array $cfg Config.
     * @return array Product IDs.
     */
    private function resolve_product_pool(array $cfg)
    {
        if ('selected' === $cfg['product_mode']) {
            $ids = $cfg['product_ids'];
        } else {
            $ids = get_posts([
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => 2000,
                'fields' => 'ids',
                'orderby' => 'ID',
                'no_found_rows' => true,
            ]);
        }

        $pool = [];
        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && !$product->is_type('external')) {
                $pool[] = (int) $product_id;
            }
        }
        return $pool;
    }

    /**
     * Turn a chosen product into something that can sit on an order line.
     *
     * Variable products resolve to a random purchasable variation, grouped
     * products to a random purchasable child; external products are skipped.
     *
     * @param WC_Product|false|null $product Product.
     * @return WC_Product|null
     */
    private function resolve_line_product($product)
    {
        if (!$product || $product->is_type('external')) {
            return null;
        }

        if ($product->is_type('variable') || $product->is_type('grouped')) {
            $children = $product->get_children();
            shuffle($children);
            foreach ($children as $child_id) {
                $child = wc_get_product($child_id);
                if ($child && 'publish' === $child->get_status() && '' !== (string) $child->get_price() && $child->is_purchasable() && !$child->is_type('variable') && !$child->is_type('grouped')) {
                    return $child;
                }
            }
            return null;
        }

        if ('' !== (string) $product->get_price() && $product->is_purchasable()) {
            return $product;
        }
        return null;
    }

    /**
     * Pick a customer for one order.
     *
     * @param array $cfg Config.
     * @return int User ID, 0 for a guest.
     */
    private function pick_customer(array $cfg)
    {
        if ('specific' === $cfg['customer_mode']) {
            return $cfg['customer_id'];
        }
        if ('random_user' === $cfg['customer_mode']) {
            return $cfg['user_pool'][wp_rand(0, count($cfg['user_pool']) - 1)];
        }
        // Fully random: mostly registered users when there are any, otherwise guests.
        if (!empty($cfg['user_pool']) && wp_rand(1, 100) <= 70) {
            return $cfg['user_pool'][wp_rand(0, count($cfg['user_pool']) - 1)];
        }
        return 0;
    }

    /**
     * Pick country + state for one address.
     *
     * @param array $cfg Config.
     * @return array [country, state]
     */
    private function pick_location(array $cfg)
    {
        $country = $cfg['country'];
        if ('random' === $country) {
            $all = array_keys(WC()->countries->get_countries());
            $country = $all[wp_rand(0, count($all) - 1)];
            $state = 'random';
        } else {
            $state = $cfg['state'];
        }

        $states = WC()->countries->get_states($country);
        if (!is_array($states) || empty($states)) {
            return [$country, ''];
        }
        if ('random' === $state || '' === $state) {
            $codes = array_keys($states);
            return [$country, (string) $codes[wp_rand(0, count($codes) - 1)]];
        }
        return [$country, $state];
    }

    /**
     * Create one order.
     *
     * @param array $cfg  Config.
     * @param array $pool Product IDs.
     * @return int Order ID.
     * @throws Exception When the order cannot be built.
     */
    private function create_single_order(array $cfg, array $pool)
    {
        // Customer + addresses.
        $customer_id = $this->pick_customer($cfg);
        list($country, $state) = $this->pick_location($cfg);
        $billing = Draxira_Address::generate($country, $state);

        if ($customer_id) {
            $user = get_userdata($customer_id);
            if ($user) {
                $first = get_user_meta($customer_id, 'first_name', true);
                $last = get_user_meta($customer_id, 'last_name', true);
                if ('' !== $first || '' !== $last) {
                    $billing['first_name'] = (string) $first;
                    $billing['last_name'] = (string) $last;
                }
                $billing['email'] = $user->user_email;
            }
        }

        if ($cfg['address_same']) {
            $shipping = $billing;
        } else {
            list($ship_country, $ship_state) = $this->pick_location($cfg);
            $shipping = Draxira_Address::generate($ship_country, $ship_state);
        }
        unset($shipping['email']);

        // Order skeleton.
        $order = wc_create_order([
            'customer_id' => $customer_id,
            'created_via' => 'draxira',
        ]);
        if (is_wp_error($order)) {
            throw new Exception($order->get_error_message());
        }

        $timestamp = Draxira_Helpers::random_timestamp($cfg['date_from'], $cfg['date_to'], wp_timezone());
        $order->set_date_created($timestamp);
        $order->set_address($billing, 'billing');
        $order->set_address($shipping, 'shipping');
        $order->update_meta_data(DRAXIRA_META_KEY, '1');

        // Line items: random distinct products, random quantities.
        $wanted = wp_rand($cfg['min_products'], $cfg['max_products']);
        $candidates = $pool;
        shuffle($candidates);
        $added = 0;
        $attempts = 0;
        foreach ($candidates as $product_id) {
            if ($added >= $wanted || $attempts >= max(30, $wanted * 8)) {
                break;
            }
            $attempts++;

            $product = $this->resolve_line_product(wc_get_product($product_id));
            if (!$product) {
                continue;
            }

            $qty = wp_rand($cfg['min_qty'], $cfg['max_qty']);
            if ($product->is_sold_individually()) {
                $qty = 1;
            }

            if ($order->add_product($product, $qty)) {
                $added++;
            }
        }

        if (0 === $added) {
            $order->delete(true);
            throw new Exception(__('No purchasable product could be added to the order.', 'draxira'));
        }

        // Shipping from the store's own shipping zones (best effort).
        if ($cfg['add_shipping']) {
            $this->maybe_add_shipping($order, $shipping);
        }

        // Payment method (enabled gateways only).
        $this->maybe_set_payment_method($order);

        // Meta fields.
        foreach ($cfg['core'] as $field => $type) {
            $value = (string) Draxira_Helpers::generate_value($type);
            if ('customer_note' === $field) {
                $order->set_customer_note($value);
            } elseif ('transaction_id' === $field) {
                $order->set_transaction_id($value);
            }
        }
        foreach ($cfg['meta'] as $key => $type) {
            $value = Draxira_Helpers::generate_value($type);
            if ('' !== $value && null !== $value) {
                $order->update_meta_data($key, $value);
            }
        }

        // WooCommerce calculates line totals, taxes (from the order's addresses) and the order total.
        $order->calculate_totals(true);
        $order->save();

        // Status. update_status() fires the normal WooCommerce transitions; mails and stock changes are filtered off.
        $status = $cfg['status'];
        if ('random' === $status) {
            $status = $this->random_status();
        }
        $order->update_status($status, __('Dummy order generated by Draxira.', 'draxira'));

        // Make the lifecycle dates consistent with the (possibly historical) creation date.
        $now = time();
        if (in_array($order->get_status(), wc_get_is_paid_statuses(), true)) {
            $order->set_date_paid(min($timestamp + wp_rand(60, 3600), max($timestamp, $now)));
        }
        if ('completed' === $order->get_status()) {
            $order->set_date_completed(max($timestamp, min($timestamp + wp_rand(3600, 3 * DAY_IN_SECONDS), $now)));
        }
        $order->save();

        return $order->get_id();
    }

    /**
     * Pick a random status with realistic weights.
     *
     * @return string Status without the wc- prefix.
     */
    private function random_status()
    {
        $core = [
            'completed' => 40,
            'processing' => 25,
            'on-hold' => 10,
            'pending' => 10,
            'cancelled' => 6,
            'failed' => 5,
            'refunded' => 4,
        ];

        $weights = [];
        foreach (array_keys(wc_get_order_statuses()) as $key) {
            $slug = substr($key, 3);
            $weights[$slug] = isset($core[$slug]) ? $core[$slug] : 3; // statuses added by extensions
        }

        return (string) Draxira_Helpers::weighted_pick($weights);
    }

    /**
     * Assign a random enabled payment gateway.
     *
     * @param WC_Order $order Order.
     * @return void
     */
    private function maybe_set_payment_method($order)
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return;
        }

        $enabled = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            if ('yes' === $gateway->enabled) {
                $enabled[] = $gateway;
            }
        }
        if (empty($enabled)) {
            return;
        }

        $gateway = $enabled[wp_rand(0, count($enabled) - 1)];
        $order->set_payment_method($gateway->id);
        $order->set_payment_method_title($gateway->get_title());
    }

    /**
     * Add a shipping line using the store's configured shipping zones.
     *
     * Best effort: if the store has no matching zone/rate, or a shipping
     * method needs the cart, the order is simply created without shipping.
     *
     * @param WC_Order $order    Order.
     * @param array    $shipping Shipping address.
     * @return void
     */
    private function maybe_add_shipping($order, array $shipping)
    {
        if (!function_exists('wc_shipping_enabled') || !wc_shipping_enabled()) {
            return;
        }

        try {
            $contents = [];
            foreach ($order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                if (!$product || !$product->needs_shipping()) {
                    continue;
                }
                $contents[$item_id] = [
                    'key' => $item_id,
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'variation' => [],
                    'quantity' => $item->get_quantity(),
                    'data' => $product,
                    'data_hash' => md5((string) $product->get_id()),
                    'line_tax_data' => ['subtotal' => [], 'total' => []],
                    'line_subtotal' => (float) $item->get_subtotal(),
                    'line_subtotal_tax' => 0,
                    'line_total' => (float) $item->get_total(),
                    'line_tax' => 0,
                ];
            }
            if (empty($contents)) {
                return;
            }

            // Some shipping methods read the cart, which is not loaded in wp-admin.
            if (function_exists('wc_load_cart') && null === WC()->cart) {
                wc_load_cart();
            }

            $package = [
                'contents' => $contents,
                'contents_cost' => array_sum(wp_list_pluck($contents, 'line_total')),
                'applied_coupons' => [],
                'user' => ['ID' => $order->get_customer_id()],
                'destination' => [
                    'country' => $shipping['country'],
                    'state' => $shipping['state'],
                    'postcode' => $shipping['postcode'],
                    'city' => $shipping['city'],
                    'address' => $shipping['address_1'],
                    'address_1' => $shipping['address_1'],
                    'address_2' => $shipping['address_2'],
                ],
            ];

            $calculated = WC()->shipping()->calculate_shipping_for_package($package);
            if (empty($calculated['rates'])) {
                return;
            }

            $rates = array_values($calculated['rates']);
            $rate = $rates[wp_rand(0, count($rates) - 1)];

            $item = new WC_Order_Item_Shipping();
            $item->set_shipping_rate($rate);
            $order->add_item($item);
        } catch (\Throwable $e) {
            // Ignore: the order is still valid without a shipping line.
            return;
        }
    }

    /**
     * Delete every dummy order created by Draxira.
     *
     * @return int Deleted count.
     */
    private function clear_dummy_orders()
    {
        $ids = wc_get_orders([
            'limit' => -1,
            'return' => 'ids',
            'meta_query' => [
                [
                    'key' => DRAXIRA_META_KEY,
                    'value' => '1',
                ],
            ],
        ]);

        $deleted = 0;
        foreach ($ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && '1' === (string) $order->get_meta(DRAXIRA_META_KEY, true)) {
                $order->delete(true);
                $deleted++;
            }
        }
        return $deleted;
    }

    /* ------------------------------------------------------------------
     * Page
     * ---------------------------------------------------------------- */

    /**
     * Render the Woo Orders page.
     *
     * @return void
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap draxira">
            <h1><?php esc_html_e('Draxira – Dummy Content Generator - Woo Orders', 'draxira'); ?></h1>
            <?php Draxira_Helpers::render_nav('draxira-orders'); ?>
        <?php

        if (!$this->is_wc_active()) {
            ?>
            <div class="notice notice-error inline">
                <p><strong><?php esc_html_e('WooCommerce is required.', 'draxira'); ?></strong>
                    <?php esc_html_e('Install and activate WooCommerce to generate dummy orders. The rest of Draxira keeps working without it.', 'draxira'); ?></p>
            </div>
        </div>
            <?php
            return;
        }

        $results = get_transient('draxira_orders_results');
        if ($results) {
            delete_transient('draxira_orders_results');
            $notice_class = (isset($results['type']) && 'error' === $results['type']) ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($results['message']) . '</p></div>';
        }

        $statuses = wc_get_order_statuses();
        $countries = WC()->countries->get_countries();
        $base_country = WC()->countries->get_base_country();
        $base_states = WC()->countries->get_states($base_country);
        $users = get_users([
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 500,
            'fields' => ['ID', 'display_name', 'user_login'],
        ]);
        $today = wp_date('Y-m-d');
        ?>
            <h2 class="nav-tab-wrapper">
                <a href="#generate-orders-tab" class="nav-tab nav-tab-active"><?php esc_html_e('Generate Orders', 'draxira'); ?></a>
                <a href="#manage-orders-tab" class="nav-tab"><?php esc_html_e('Manage Dummy Orders', 'draxira'); ?></a>
            </h2>

            <div id="generate-orders-tab" class="tab-content active">
                <form method="post" action="" id="generate-orders-form" data-dx-batch="orders">
                    <?php wp_nonce_field('generate_dummy_orders'); ?>

                    <!-- 1. General -->
                    <section class="dx-section">
                        <header class="dx-section-head">
                            <span class="dx-step">1</span>
                            <div>
                                <h3><?php esc_html_e('General', 'draxira'); ?></h3>
                                <p class="description"><?php esc_html_e('How many orders to create and how WooCommerce should treat them.', 'draxira'); ?></p>
                            </div>
                        </header>
                        <div class="dx-grid">
                            <div class="dx-field">
                                <label for="order-count"><?php esc_html_e('Number of orders', 'draxira'); ?></label>
                                <input type="number" id="order-count" name="order_count" min="1" max="<?php echo esc_attr(self::MAX_ORDERS); ?>" value="5">
                                <p class="description"><?php echo esc_html(sprintf(
                                    /* translators: %d: max orders */
                                    __('Runs in the background with a progress bar (up to %d orders at a time).', 'draxira'),
                                    self::MAX_ORDERS
                                )); ?></p>
                            </div>
                            <div class="dx-field">
                                <span class="dx-label"><?php esc_html_e('Options', 'draxira'); ?></span>
                                <label class="dx-check"><input type="checkbox" name="add_shipping" value="1" checked> <?php esc_html_e('Add shipping using my shipping zones (when a rate matches)', 'draxira'); ?></label>
                                <label class="dx-check"><input type="checkbox" name="reduce_stock" value="1"> <?php esc_html_e('Reduce product stock levels', 'draxira'); ?></label>
                                <p class="description"><?php esc_html_e('Stock is left untouched by default. No e-mails are ever sent for dummy orders.', 'draxira'); ?></p>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Order status -->
                    <section class="dx-section">
                        <header class="dx-section-head">
                            <span class="dx-step">2</span>
                            <div>
                                <h3><?php esc_html_e('Order Status', 'draxira'); ?></h3>
                                <p class="description"><?php esc_html_e('Pick one status for every order, or let Draxira vary them realistically (mostly completed and processing).', 'draxira'); ?></p>
                            </div>
                        </header>
                        <div class="dx-field">
                            <label for="order-status"><?php esc_html_e('Status', 'draxira'); ?></label>
                            <select id="order-status" name="order_status">
                                <option value="random"><?php esc_html_e('Random (weighted)', 'draxira'); ?></option>
                                <?php foreach ($statuses as $key => $label): ?>
                                    <option value="<?php echo esc_attr(substr($key, 3)); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </section>

                    <!-- 3. Customer -->
                    <section class="dx-section">
                        <header class="dx-section-head">
                            <span class="dx-step">3</span>
                            <div>
                                <h3><?php esc_html_e('Customer', 'draxira'); ?></h3>
                                <p class="description" id="customer-hint"><?php esc_html_e('Each order randomly gets either a registered user or a guest with generated details.', 'draxira'); ?></p>
                            </div>
                        </header>
                        <div class="dx-choices">
                            <label class="dx-choice">
                                <input type="radio" name="customer_mode" value="random" checked>
                                <span class="dx-choice-body"><strong><?php esc_html_e('Random', 'draxira'); ?></strong><small><?php esc_html_e('Registered users and guests mixed', 'draxira'); ?></small></span>
                            </label>
                            <label class="dx-choice">
                                <input type="radio" name="customer_mode" value="random_user">
                                <span class="dx-choice-body"><strong><?php esc_html_e('Random user', 'draxira'); ?></strong><small><?php esc_html_e('Customer / Subscriber accounts', 'draxira'); ?></small></span>
                            </label>
                            <label class="dx-choice">
                                <input type="radio" name="customer_mode" value="specific">
                                <span class="dx-choice-body"><strong><?php esc_html_e('Specific user', 'draxira'); ?></strong><small><?php esc_html_e('All orders for one person', 'draxira'); ?></small></span>
                            </label>
                        </div>
                        <div class="dx-field dx-conditional" id="customer-specific-wrap" style="display:none;">
                            <label for="customer-id"><?php esc_html_e('User', 'draxira'); ?></label>
                            <select id="customer-id" name="customer_id">
                                <option value="0"><?php esc_html_e('Select user', 'draxira'); ?></option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </section>

                    <!-- 4. Order date -->
                    <section class="dx-section">
                        <header class="dx-section-head">
                            <span class="dx-step">4</span>
                            <div>
                                <h3><?php esc_html_e('Order Date', 'draxira'); ?></h3>
                                <p class="description" id="date-hint"><?php esc_html_e('All orders land on this day, at different random times.', 'draxira'); ?></p>
                            </div>
                        </header>
                        <div class="dx-choices">
                            <label class="dx-choice">
                                <input type="radio" name="date_mode" value="single" checked>
                                <span class="dx-choice-body"><strong><?php esc_html_e('Single date', 'draxira'); ?></strong><small><?php esc_html_e('e.g. 2026-11-08', 'draxira'); ?></small></span>
                            </label>
                            <label class="dx-choice">
                                <input type="radio" name="date_mode" value="range">
                                <span class="dx-choice-body"><strong><?php esc_html_e('Date range', 'draxira'); ?></strong><small><?php esc_html_e('e.g. 2026-01-01 → 2026-11-08', 'draxira'); ?></small></span>
                            </label>
                        </div>
                        <div class="dx-grid">
                            <div class="dx-field">
                                <label for="date-from" id="date-from-label"><?php esc_html_e('Date', 'draxira'); ?></label>
                                <input type="date" id="date-from" name="date_from" value="<?php echo esc_attr($today); ?>">
                            </div>
                            <div class="dx-field dx-conditional" id="date-to-wrap" style="display:none;">
                                <label for="date-to"><?php esc_html_e('End date', 'draxira'); ?></label>
                                <input type="date" id="date-to" name="date_to" value="<?php echo esc_attr($today); ?>">
                            </div>
                        </div>
                    </section>

                    <!-- 5. Address -->
                    <section class="dx-section">
                        <header class="dx-section-head">
                            <span class="dx-step">5</span>
                            <div>
                                <h3><?php esc_html_e('Billing & Shipping Address', 'draxira'); ?></h3>
                                <p class="description"><?php esc_html_e('Names, street, city, phone and a valid-format postcode are generated for the chosen country and state.', 'draxira'); ?></p>
                            </div>
                        </header>
                        <div class="dx-choices">
                            <label class="dx-choice">
                                <input type="radio" name="address_mode" value="same" checked>
                                <span class="dx-choice-body"><strong><?php esc_html_e('Same', 'draxira'); ?></strong><small><?php esc_html_e('Shipping copies billing', 'draxira'); ?></small></span>
                            </label>
                            <label class="dx-choice">
                                <input type="radio" name="address_mode" value="different">
                                <span class="dx-choice-body"><strong><?php esc_html_e('Different', 'draxira'); ?></strong><small><?php esc_html_e('Generated independently', 'draxira'); ?></small></span>
                            </label>
                        </div>
                        <div class="dx-grid">
                            <div class="dx-field">
                                <label for="address-country"><?php esc_html_e('Country', 'draxira'); ?></label>
                                <select id="address-country" name="address_country">
                                    <option value="random"><?php esc_html_e('Random country', 'draxira'); ?></option>
                                    <?php foreach ($countries as $code => $name): ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($code, $base_country); ?>><?php echo esc_html($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="dx-field">
                                <label for="address-state"><?php esc_html_e('State / Region', 'draxira'); ?></label>
                                <select id="address-state" name="address_state">
                                    <option value="random"><?php esc_html_e('Random state / region', 'draxira'); ?></option>
                                    <?php if (is_array($base_states)): foreach ($base_states as $code => $name): ?>
                                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                                <p class="description" id="state-hint"></p>
                            </div>
                        </div>
                    </section>

                    <!-- 6. Products -->
                    <section class="dx-section">
                        <header class="dx-section-head">
                            <span class="dx-step">6</span>
                            <div>
                                <h3><?php esc_html_e('Products', 'draxira'); ?></h3>
                                <p class="description"><?php esc_html_e('Variable products add a random variation. WooCommerce calculates item totals, taxes, shipping and the order total.', 'draxira'); ?></p>
                            </div>
                        </header>
                        <div class="dx-choices">
                            <label class="dx-choice">
                                <input type="radio" name="product_mode" value="all" checked>
                                <span class="dx-choice-body"><strong><?php esc_html_e('All products', 'draxira'); ?></strong><small><?php esc_html_e('Random products from your whole catalog', 'draxira'); ?></small></span>
                            </label>
                            <label class="dx-choice">
                                <input type="radio" name="product_mode" value="selected">
                                <span class="dx-choice-body"><strong><?php esc_html_e('Selected products', 'draxira'); ?></strong><small><?php esc_html_e('Only products you tick below', 'draxira'); ?></small></span>
                            </label>
                        </div>

                        <div id="product-picker" class="dx-conditional" style="display:none;">
                            <div class="dx-picker-bar">
                                <input type="search" id="product-search" placeholder="<?php esc_attr_e('Search products…', 'draxira'); ?>">
                                <button type="button" class="button" id="products-select-all"><?php esc_html_e('Select all shown', 'draxira'); ?></button>
                                <button type="button" class="button" id="products-select-none"><?php esc_html_e('Clear selection', 'draxira'); ?></button>
                                <span class="dx-badge" id="products-selected-count"></span>
                            </div>
                            <div id="product-list" class="dx-product-list"></div>
                        </div>

                        <div class="dx-grid dx-grid-4">
                            <div class="dx-field">
                                <label for="min-products"><?php esc_html_e('Min products per order', 'draxira'); ?></label>
                                <input type="number" id="min-products" name="min_products" min="1" max="20" value="1">
                            </div>
                            <div class="dx-field">
                                <label for="max-products"><?php esc_html_e('Max products per order', 'draxira'); ?></label>
                                <input type="number" id="max-products" name="max_products" min="1" max="20" value="3">
                            </div>
                            <div class="dx-field">
                                <label for="min-qty"><?php esc_html_e('Min quantity per item', 'draxira'); ?></label>
                                <input type="number" id="min-qty" name="min_qty" min="1" max="99" value="1">
                            </div>
                            <div class="dx-field">
                                <label for="max-qty"><?php esc_html_e('Max quantity per item', 'draxira'); ?></label>
                                <input type="number" id="max-qty" name="max_qty" min="1" max="99" value="5">
                            </div>
                        </div>
                    </section>

                    <!-- 7. Meta fields -->
                    <section class="dx-section">
                        <header class="dx-section-head">
                            <span class="dx-step">7</span>
                            <div>
                                <h3><?php esc_html_e('Meta Fields', 'draxira'); ?></h3>
                                <p class="description"><?php esc_html_e('Choose which fields to fill and with what kind of dummy data. Fields left on "Leave untouched" are not written.', 'draxira'); ?></p>
                            </div>
                        </header>
                        <div id="order-meta-configuration"></div>

                        <h4><?php esc_html_e('Add your own meta keys', 'draxira'); ?></h4>
                        <div id="custom-meta-rows"></div>
                        <button type="button" class="button" id="add-custom-meta"><?php esc_html_e('+ Add meta key', 'draxira'); ?></button>
                    </section>

                    <p class="submit">
                        <input type="submit" name="draxira_generate_orders" class="button button-primary button-hero" value="<?php esc_attr_e('Generate Orders', 'draxira'); ?>">
                    </p>
                </form>
            </div>

            <div id="manage-orders-tab" class="tab-content">
                <h3><?php esc_html_e('Dummy Orders Created by Plugin', 'draxira'); ?></h3>
                <div class="filter-section">
                    <button type="button" id="load-dummy-orders" class="button"><?php esc_html_e('Load Dummy Orders', 'draxira'); ?></button>
                </div>

                <div id="dummy-orders-list">
                    <p><?php esc_html_e('Click "Load Dummy Orders" to see dummy orders.', 'draxira'); ?></p>
                </div>

                <div id="delete-section" style="display:none;">
                    <h4><?php esc_html_e('Delete Dummy Orders', 'draxira'); ?></h4>
                    <p class="description"><strong><?php esc_html_e('Warning:', 'draxira'); ?></strong>
                        <?php esc_html_e('This permanently deletes ALL orders generated by Draxira. Your real orders are not touched. This cannot be undone.', 'draxira'); ?></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('clear_dummy_orders'); ?>
                        <input type="hidden" name="draxira_clear_orders" value="1">
                        <p>
                            <input type="submit" class="button button-large button-link-delete"
                                value="<?php esc_attr_e('Delete All Dummy Orders', 'draxira'); ?>"
                                data-confirm-message="<?php echo esc_attr__("FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy orders generated by Draxira.\nNo backup. No trash. Really sure?", 'draxira'); ?>">
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
