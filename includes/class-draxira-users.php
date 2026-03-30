<?php
/**
 * User Dummy Content Generator
 * 
 * Handles generation of dummy users with their meta data.
 *
 * @package Draxira
 * @subpackage Includes
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Draxira_Users
 * 
 * Manages all user dummy content generation functionality.
 *
 * @since 1.0.0
 * @access public
 */
class Draxira_Users
{
    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @access private
     * @var Draxira_Users|null
     */
    private static $instance = null;

    /**
     * Faker instance for generating dummy data
     *
     * @since 1.0.0
     * @access private
     * @var Faker\Generator|null
     */
    private $faker = null;

    /**
     * Available Faker data types for user selection
     *
     * @since 1.0.0
     * @access private
     * @var array
     */
    private $mc_drax_faker_types = [
        'text' => 'Text (Sentence)',
        'paragraphs' => 'Text (Paragraphs)',
        'words' => 'Text (Words)',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone Number',
        'address' => 'Address',
        'city' => 'City',
        'country' => 'Country',
        'zipcode' => 'ZIP Code',
        'number' => 'Number (1-100)',
        'price' => 'Price (10-1000)',
        'date' => 'Date',
        'boolean' => 'Boolean (Yes/No)',
        'url' => 'URL',
        'image_url' => 'Image URL',
        'color' => 'Color',
        'hex_color' => 'Hex Color',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'company' => 'Company Name',
    ];

    /**
     * Get singleton instance of the class
     *
     * @since 1.0.0
     * @access public
     * @static
     * @return Draxira_Users Singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Private to enforce singleton pattern
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct()
    {
        $this->mc_drax_init_hooks();
    }

    /**
     * Initialize all WordPress hooks
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_init_hooks()
    {
        // Handle user form submissions
        add_action('admin_init', [$this, 'mc_drax_handle_user_actions']);

        // Cleanup hooks for permanent deletion
        add_action('deleted_user', [$this, 'mc_drax_cleanup_user_meta'], 10, 2);
    }

    /**
     * Get Faker instance (initialize if not exists)
     *
     * @since 1.0.0
     * @access private
     * @return Faker\Generator|false Faker instance or false if not available
     */
    private function mc_drax_get_faker()
    {
        if (null === $this->faker) {
            // Check if Faker is available via Composer
            if (class_exists('Faker\Factory')) {
                $this->faker = Faker\Factory::create();
            } else {
                // Fallback to basic random data if Faker not available
                $this->faker = false;
            }
        }
        return $this->faker;
    }

    /**
     * Handle user form submissions
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_handle_user_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['generate_users']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'generate_dummy_users')) {
            $this->mc_drax_generate_dummy_users();
        }

        $clear_users_nonce_action = 'clear_dummy_users';
        if (isset($_REQUEST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), $clear_users_nonce_action)) {
            if (isset($_REQUEST['clear_dummy_users'])) {
                $deleted_count = $this->mc_drax_clear_dummy_users();

                set_transient('draxira_user_results', [
                    'message' => sprintf(
                        /* translators: %1$d: number of deleted users, %2$s: singular/plural of user */
                        __('Successfully deleted %1$d %2$s (and associated data).', 'draxira'),
                        $deleted_count,
                        _n('dummy user', 'dummy users', $deleted_count, 'draxira')
                    ),
                    'type' => 'success'
                ], 45);

                wp_safe_redirect(admin_url('admin.php?page=draxira-users'));
                exit;
            }
        }
    }

    /**
     * Generate dummy users based on form submission
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_generate_dummy_users()
    {
        // Security check - prevent user creation if not allowed
        if (!current_user_can('create_users') && !current_user_can('promote_users')) {
            set_transient('draxira_user_results', [
                'message' => __('You do not have permission to create users.', 'draxira'),
                'type' => 'error'
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=draxira-users'));
            exit;
        }

        $count = min(intval($_POST['user_count'] ?? 5), 20); // Limit to 20 users max
        $role = sanitize_text_field($_POST['user_role'] ?? 'subscriber');

        // Prevent creating admin users
        if ($role === 'administrator' && !current_user_can('create_users')) {
            $role = 'subscriber';
        }

        // Get user meta configurations
        $user_meta_config = [];
        if (isset($_POST['user_meta']) && is_array($_POST['user_meta'])) {
            foreach ($_POST['user_meta'] as $meta_key => $config) {
                if (!empty($config['type'])) {
                    $user_meta_config[$meta_key] = [
                        'type' => sanitize_text_field($config['type'])
                    ];
                }
            }
        }

        $results = ['success' => 0, 'failed' => 0];
        $faker = $this->mc_drax_get_faker();

        for ($i = 0; $i < $count; $i++) {
            $username = $faker ? $faker->userName : 'dummyuser_' . uniqid();
            $email = $faker ? $faker->email : $username . '@example.com';

            // Ensure email is unique
            $email = $this->mc_drax_get_unique_email($email);

            // Create user with basic data
            $userdata = [
                'user_login' => $username,
                'user_email' => $email,
                'user_pass' => wp_generate_password(12, true, true), // Use strong random password
                'role' => $role,
            ];

            // Add optional user fields if configured
            foreach ($user_meta_config as $meta_key => $config) {
                $meta_value = $this->mc_drax_generate_faker_value($config['type']);
                if ($meta_value !== '') {
                    // Handle user table fields specially
                    if (in_array($meta_key, ['user_url', 'display_name', 'description'])) {
                        $userdata[$meta_key] = $meta_value;
                    }
                }
            }

            $user_id = wp_insert_user($userdata);

            if (!is_wp_error($user_id)) {
                // Add our meta key
                update_user_meta($user_id, DRAXIRA_META_KEY, '1');

                // Also add a flag to indicate this is a test/dummy user
                update_user_meta($user_id, '_draxira_is_dummy', '1');

                // Always add first name and last name
                if ($faker) {
                    $first_name = $faker->firstName;
                    $last_name = $faker->lastName;

                    update_user_meta($user_id, 'first_name', $first_name);
                    update_user_meta($user_id, 'last_name', $last_name);

                    // Set display name if not already set
                    if (!isset($userdata['display_name'])) {
                        $display_name = $faker->boolean(70) ? "$first_name $last_name" : $username;
                        wp_update_user([
                            'ID' => $user_id,
                            'display_name' => $display_name
                        ]);
                    }
                }

                // Add configured user meta
                foreach ($user_meta_config as $meta_key => $config) {
                    $meta_value = $this->mc_drax_generate_faker_value($config['type']);
                    if ($meta_value !== '' && !in_array($meta_key, ['user_url', 'display_name', 'description'])) {
                        update_user_meta($user_id, $meta_key, $meta_value);
                    }
                }

                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        set_transient('draxira_user_results', [
            'message' => sprintf(
                /* translators: %1$d: number of generated users, %2$s: singular/plural of user, %3$d: number of failures */
                __('Successfully generated %1$d %2$s. Failed: %3$d', 'draxira'),
                $results['success'],
                _n('user', 'users', $results['success'], 'draxira'),
                $results['failed']
            ),
            'type' => 'success'
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=draxira-users'));
        exit;
    }

    /**
     * Get unique email address
     *
     * @since 1.0.0
     * @access private
     * @param string $email
     * @return string
     */
    private function mc_drax_get_unique_email($email)
    {
        $original_email = $email;
        $counter = 1;

        while (email_exists($email)) {
            $email = str_replace('@', $counter . '@', $original_email);
            $counter++;
        }

        return $email;
    }

    /**
     * Generate value using Faker based on type
     *
     * @since 1.0.0
     * @access private
     * @param string $type Faker data type
     * @return string|int|float Generated value
     */
    private function mc_drax_generate_faker_value($type)
    {
        $faker = $this->mc_drax_get_faker();

        if (!$faker) {
            return '';
        }

        switch ($type) {
            case 'text':
                return $faker->sentence();
            case 'paragraphs':
                return $faker->paragraphs(3, true);
            case 'words':
                return $faker->words(5, true);
            case 'name':
                return $faker->name();
            case 'email':
                return $faker->email();
            case 'phone':
                return $faker->phoneNumber();
            case 'address':
                return $faker->address();
            case 'city':
                return $faker->city();
            case 'country':
                return $faker->country();
            case 'zipcode':
                return $faker->postcode();
            case 'number':
                return $faker->numberBetween(1, 100);
            case 'price':
                return $faker->randomFloat(2, 10, 1000);
            case 'date':
                return $faker->date();
            case 'boolean':
                return $faker->boolean() ? 'yes' : 'no';
            case 'url':
                return $faker->url();
            case 'image_url':
                return $faker->imageUrl();
            case 'color':
                return $faker->colorName();
            case 'hex_color':
                return $faker->hexColor();
            case 'latitude':
                return $faker->latitude();
            case 'longitude':
                return $faker->longitude();
            case 'company':
                return $faker->company();
            default:
                return '';
        }
    }

    /**
     * Delete dummy users and their associated meta data
     *
     * @since 1.0.0
     * @access private
     * @return int Number of deleted users
     */
    private function mc_drax_clear_dummy_users()
    {
        global $wpdb;

        $args = [
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ];

        $dummy_users = get_users($args);
        $deleted_count = 0;

        foreach ($dummy_users as $user_id) {
            if ($user_id != 1) { // Don't delete admin user
                // Delete the user (this should trigger our cleanup hook)
                if (wp_delete_user($user_id)) {
                    $deleted_count++;
                }
            }
        }

        // Additional cleanup: Delete orphaned user meta
        $this->mc_drax_cleanup_orphaned_user_meta();

        return $deleted_count;
    }

    /**
     * Cleanup user meta when a user is deleted
     * This hook ensures all meta is deleted even if deletion happens outside our plugin
     *
     * @since 1.0.0
     * @access public
     * @param int      $user_id  User ID
     * @param int|null $reassign Reassign posts to another user ID
     * @return void
     */
    public function mc_drax_cleanup_user_meta($user_id, $reassign = null)
    {
        global $wpdb;

        // Check if this is a dummy user
        $is_dummy = get_user_meta($user_id, DRAXIRA_META_KEY, true);

        if ($is_dummy === '1') {
            // Delete all user meta for this user
            $wpdb->delete(
                $wpdb->usermeta,
                ['user_id' => $user_id],
                ['%d']
            );

            // Delete from our tracking if it exists separately
            delete_user_meta($user_id, DRAXIRA_META_KEY);
        }
    }

    /**
     * Cleanup orphaned user meta (safety measure)
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_cleanup_orphaned_user_meta()
    {
        global $wpdb;

        // Delete orphaned user meta (users that don't exist anymore)
        $wpdb->query("
            DELETE um FROM {$wpdb->usermeta} um
            LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id
            WHERE u.ID IS NULL
        ");
    }

    /**
     * Get all available user meta keys including defaults and custom fields
     * Filters out WordPress internal meta fields
     *
     * @since 1.0.0
     * @access public
     * @return array Array of user meta keys with labels
     */
    public function mc_drax_get_user_meta_keys()
    {
        global $wpdb;

        // 1. Default WordPress user fields (from wp_users table)
        $default_user_fields = [
            'user_login' => __('Username', 'draxira'),
            'user_email' => __('Email', 'draxira'),
            'user_url' => __('Website', 'draxira'),
            'display_name' => __('Display Name', 'draxira'),
            'description' => __('Biographical Info', 'draxira'),
        ];

        // 2. Default WordPress user meta fields (commonly used)
        $default_user_meta = [
            'nickname' => __('Nickname', 'draxira'),
            'first_name' => __('First Name', 'draxira'),
            'last_name' => __('Last Name', 'draxira'),
            'rich_editing' => __('Visual Editor', 'draxira'),
            'admin_color' => __('Admin Color Scheme', 'draxira'),
            'show_admin_bar_front' => __('Show Toolbar', 'draxira'),
            'locale' => __('Language', 'draxira'),
            'comment_shortcuts' => __('Keyboard Shortcuts', 'draxira'),
        ];

        // 3. WooCommerce fields (if available)
        $woocommerce_fields = [];
        if (class_exists('WooCommerce')) {
            $woocommerce_fields = [
                'billing_first_name' => __('Billing First Name', 'draxira'),
                'billing_last_name' => __('Billing Last Name', 'draxira'),
                'billing_company' => __('Billing Company', 'draxira'),
                'billing_address_1' => __('Billing Address 1', 'draxira'),
                'billing_address_2' => __('Billing Address 2', 'draxira'),
                'billing_city' => __('Billing City', 'draxira'),
                'billing_postcode' => __('Billing Postcode', 'draxira'),
                'billing_country' => __('Billing Country', 'draxira'),
                'billing_state' => __('Billing State', 'draxira'),
                'billing_phone' => __('Billing Phone', 'draxira'),
                'billing_email' => __('Billing Email', 'draxira'),
                'shipping_first_name' => __('Shipping First Name', 'draxira'),
                'shipping_last_name' => __('Shipping Last Name', 'draxira'),
                'shipping_company' => __('Shipping Company', 'draxira'),
                'shipping_address_1' => __('Shipping Address 1', 'draxira'),
                'shipping_address_2' => __('Shipping Address 2', 'draxira'),
                'shipping_city' => __('Shipping City', 'draxira'),
                'shipping_postcode' => __('Shipping Postcode', 'draxira'),
                'shipping_country' => __('Shipping Country', 'draxira'),
                'shipping_state' => __('Shipping State', 'draxira'),
            ];
        }

        // 4. Get custom user meta keys from ACF
        $acf_fields = [];
        if (function_exists('acf_get_field_groups')) {
            $field_groups = acf_get_field_groups(['user_form' => 'all']);
            foreach ($field_groups as $field_group) {
                $fields = acf_get_fields($field_group['key']);
                if ($fields) {
                    foreach ($fields as $field) {
                        if (isset($field['name']) && $field['name']) {
                            $acf_fields[$field['name']] = $field['label'] ?? $field['name'];
                        }
                    }
                }
            }
        }

        // 5. Get custom user meta keys from CMB2
        $cmb2_fields = [];
        if (class_exists('CMB2')) {
            $cmb2_boxes = CMB2_Boxes::get_all();
            foreach ($cmb2_boxes as $cmb_id => $cmb) {
                $object_types = $cmb->prop('object_types');
                if ($object_types && in_array('user', (array) $object_types)) {
                    $fields = $cmb->prop('fields');
                    if ($fields) {
                        foreach ($fields as $field) {
                            if (isset($field['id'])) {
                                $cmb2_fields[$field['id']] = $field['name'] ?? $field['id'];
                            }
                        }
                    }
                }
            }
        }

        // 6. Get other custom user meta keys (excluding WordPress internal fields)
        $custom_meta_keys = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT meta_key 
            FROM {$wpdb->usermeta} 
            WHERE meta_key NOT LIKE %s
            AND meta_key NOT LIKE %s
            AND meta_key NOT LIKE %s
            AND meta_key NOT LIKE %s
            AND meta_key NOT IN (
                'nickname', 'first_name', 'last_name', 'description', 'rich_editing', 
                'comment_shortcuts', 'admin_color', 'show_admin_bar_front', 
                'locale', 'wp_capabilities', 'wp_user_level', 'dismissed_wp_pointers',
                'session_tokens', 'last_update', 'nav_menu_recently_edited',
                'community-events-location', 'dashboard_quick_press_last_post_id',
                'dashboard_widget_options', 'show_welcome_panel', 'user-settings',
                'user-settings-time', 'syntax_highlighting', 'acf_user_settings',
                'elementor_introduction', 'last_login', 'wc_last_active'
            )
            ORDER BY meta_key
            LIMIT 100
        ", '\_%', 'closedpostboxes_%', 'metaboxhidden_%', 'manage%columnshidden'));

        // 7. Format custom meta keys
        $custom_fields = [];
        foreach ($custom_meta_keys as $key) {
            // Skip if already in other arrays
            if (
                isset($default_user_meta[$key]) ||
                isset($acf_fields[$key]) ||
                isset($cmb2_fields[$key]) ||
                isset($woocommerce_fields[$key]) ||
                in_array($key, array_keys($default_user_fields))
            ) {
                continue;
            }

            $label = ucwords(str_replace(['_', '-'], ' ', $key));
            $custom_fields[$key] = $label;
        }

        // 8. Merge all fields
        $all_fields = array_merge(
            $default_user_fields,
            $default_user_meta,
            $woocommerce_fields,
            $acf_fields,
            $cmb2_fields,
            $custom_fields
        );

        // 9. Remove duplicates and sort alphabetically
        $all_fields = array_unique($all_fields);
        asort($all_fields);

        $internal_fields = [
            'closedpostboxes_',
            'metaboxhidden_',
            'meta-box-order_',
            'manage',
            'wp_',
            'dashboard_',
            'elementor_',
            'woocommerce_',
            'woodmart_',
            'tran_',
            'trans_',
            'unreserved_',
            'phillip_',
            'wc_',
            'wishlist_',
            'wp_persisted_'
        ];

        foreach ($all_fields as $key => $value) {
            foreach ($internal_fields as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    unset($all_fields[$key]);
                    break;
                }
            }
        }

        return $all_fields;
    }

    /**
     * Helper method to auto-select field type based on field name
     *
     * @since 1.0.0
     * @access public
     * @param string $field_name Field name to analyze
     * @return string Recommended faker type
     */
    public function mc_drax_get_auto_field_type($field_name)
    {
        $mappings = [
            'first_name' => 'name',
            'last_name' => 'name',
            'nickname' => 'name',
            'display_name' => 'name',
            'description' => 'paragraphs',
            'user_url' => 'url',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'city' => 'city',
            'country' => 'country',
            'zip' => 'zipcode',
            'postcode' => 'zipcode',
            'company' => 'company',
            'price' => 'price',
            'date' => 'date',
        ];

        foreach ($mappings as $key => $type) {
            if (strpos($field_name, $key) !== false) {
                return $type;
            }
        }

        return '';
    }

    /**
     * Render users page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_render_users_page()
    {
        $user_meta_keys = $this->mc_drax_get_user_meta_keys();
        ?>
        <div class="wrap draxira">
            <h1><?php esc_html_e('Draxira – Dummy Content Generator - Users', 'draxira'); ?></h1>

            <?php
            $results = get_transient('draxira_user_results');
            if ($results) {
                delete_transient('draxira_user_results');
                echo '<div class="notice notice-success"><p>' . esc_html($results['message']) . '</p></div>';
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-users-tab"
                    class="nav-tab nav-tab-active"><?php esc_html_e('Generate Users', 'draxira'); ?></a>
                <a href="#manage-users-tab" class="nav-tab"><?php esc_html_e('Manage Dummy Users', 'draxira'); ?></a>
            </h2>

            <div id="generate-users-tab" class="tab-content active">
                <form method="post" action="" id="generate-users-form">
                    <?php wp_nonce_field('generate_dummy_users'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Number of Users', 'draxira'); ?></th>
                            <td>
                                <input type="number" name="user_count" min="1" max="20" value="5">
            </div>
        </div>
        <div class="form-field">
            <th scope="row"><?php esc_html_e('User Role', 'draxira'); ?></th>
            <td>
                <select name="user_role">
                    <?php
                    $roles = wp_roles()->get_names();
                    foreach ($roles as $role_value => $role_name) {
                        echo '<option value="' . esc_attr($role_value) . '">' . esc_html($role_name) . '</option>';
                    }
                    ?>
                </select>
            </td>
        </div>
        </table>

        <div id="user-meta-configuration">
            <h3><?php esc_html_e('User Information', 'draxira'); ?></h3>
            <p class="description">
                <?php esc_html_e('Configure how each user field should be filled. Only essential and custom fields are shown.', 'draxira'); ?>
            </p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Field Name', 'draxira'); ?></th>
                        <th><?php esc_html_e('Field Key', 'draxira'); ?></th>
                        <th><?php esc_html_e('Faker Data Type', 'draxira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($user_meta_keys as $meta_key => $field_label):
                        $auto_type = $this->mc_drax_get_auto_field_type($meta_key);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($field_label); ?></strong>
                            </td>
                            <td>
                                <code><?php echo esc_html($meta_key); ?></code>
                                <input type="hidden" name="user_meta[<?php echo esc_attr($meta_key); ?>][key]"
                                    value="<?php echo esc_attr($meta_key); ?>">
                            </td>
                            <td>
                                <select name="user_meta[<?php echo esc_attr($meta_key); ?>][type]">
                                    <option value="">-- <?php esc_html_e('Leave Empty', 'draxira'); ?> --</option>
                                    <?php foreach ($this->mc_drax_faker_types as $type_value => $type_label):
                                        $selected = ($type_value === $auto_type) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo esc_attr($type_value); ?>" <?php echo $selected; ?>>
                                            <?php echo esc_html($type_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($auto_type): ?>
                                    <br><small
                                        class="description"><?php esc_html_e('Auto-selected based on field name', 'draxira'); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="submit">
            <input type="submit" name="generate_users" class="button button-primary"
                value="<?php esc_attr_e('Generate Users', 'draxira'); ?>">
        </p>
        </form>
        </div>

        <div id="manage-users-tab" class="tab-content">
            <h3><?php esc_html_e('Dummy Users Created by Plugin', 'draxira'); ?></h3>
            <?php
            $dummy_users = get_users([
                'meta_key' => DRAXIRA_META_KEY,
                'meta_value' => '1',
            ]);

            if ($dummy_users) {
                echo '<p>' . sprintf(esc_html__('Found %d dummy users.', 'draxira'), count($dummy_users)) . '</p>';
                echo '<table class="widefat fixed striped">';
                echo '<thead><tr><th>' . esc_html__('ID', 'draxira') . '</th><th>' . esc_html__('Username', 'draxira') . '</th><th>' . esc_html__('Email', 'draxira') . '</th><th>' . esc_html__('Name', 'draxira') . '</th><th>' . esc_html__('Role', 'draxira') . '</th><th>' . esc_html__('Actions', 'draxira') . '</th></tr></thead>';
                echo '<tbody>';

                foreach ($dummy_users as $user) {
                    $first_name = get_user_meta($user->ID, 'first_name', true);
                    $last_name = get_user_meta($user->ID, 'last_name', true);
                    $full_name = trim($first_name . ' ' . $last_name);

                    echo '<tr>';
                    echo '<td>' . esc_html($user->ID) . '</td>';
                    echo '<td>' . esc_html($user->user_login) . '</td>';
                    echo '<td>' . esc_html($user->user_email) . '</td>';
                    echo '<td>' . esc_html($full_name ?: 'N/A') . '</td>';
                    echo '<td>' . esc_html(implode(', ', $user->roles)) . '</td>';
                    echo '<td>';
                    echo '<a href="' . esc_url(admin_url('profile.php?user_id=' . $user->ID)) . '" class="button button-small" target="_blank">' . esc_html__('View Profile', 'draxira') . '</a>';
                    echo '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';

                $nonce = wp_create_nonce('clear_dummy_users');
                echo '<div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">';
                echo '<p><strong>' . esc_html__('Warning:', 'draxira') . '</strong> ' . esc_html__('This will permanently delete ALL dummy users (except admin) along with their meta data.', 'draxira') . '</p>';
                echo '<p><a href="' . esc_url(add_query_arg([
                    'page' => 'draxira-users',
                    'clear_dummy_users' => '1',
                    '_wpnonce' => $nonce
                ], admin_url('admin.php'))) . '" class="button button-danger" onclick="return confirm(\'' . esc_js(__('WARNING: This will PERMANENTLY delete ALL dummy users (except admin) along with their meta data. This action cannot be undone. Are you sure?', 'draxira')) . '\')">' . esc_html__('Delete All Dummy Users', 'draxira') . '</a></p>';
                echo '</div>';
            } else {
                echo '<p>' . esc_html__('No dummy users found.', 'draxira') . '</p>';
            }
            ?>
        </div>
        </div>
        <?php
    }
}