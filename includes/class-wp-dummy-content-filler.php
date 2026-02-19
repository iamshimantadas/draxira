<?php
/**
 * Main plugin class for WP Dummy Content Filler
 * 
 * @package WP_Dummy_Content_Filler
 * @subpackage Includes
 * @since 1.0.0
 */

/**
 * Class WP_Dummy_Content_Filler
 * 
 * Handles all dummy content generation for posts, pages, and custom post types
 * 
 * @since 1.0.0
 * @access public
 */
class WP_Dummy_Content_Filler
{

    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @access private
     * @var WP_Dummy_Content_Filer|null
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
    private $faker_types = [
        'text'       => 'Text (Sentence)',
        'paragraphs' => 'Text (Paragraphs)',
        'words'      => 'Text (Words)',
        'name'       => 'Name',
        'email'      => 'Email',
        'phone'      => 'Phone Number',
        'address'    => 'Address',
        'city'       => 'City',
        'country'    => 'Country',
        'zipcode'    => 'ZIP Code',
        'number'     => 'Number (1-100)',
        'price'      => 'Price (10-1000)',
        'date'       => 'Date',
        'boolean'    => 'Boolean (Yes/No)',
        'url'        => 'URL',
        'image_url'  => 'Image URL',
        'color'      => 'Color',
        'hex_color'  => 'Hex Color',
        'latitude'   => 'Latitude',
        'longitude'  => 'Longitude',
        'company'    => 'Company Name',
    ];

    /**
     * Get singleton instance of the class
     *
     * @since 1.0.0
     * @access public
     * @static
     * @return WP_Dummy_Content_Filler Singleton instance
     */
    public static function mc_get_instance()
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
        $this->mc_init_hooks();
    }

    /**
     * Initialize all WordPress hooks
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_init_hooks()
    {
        // Admin menu
        add_action('admin_menu', [$this, 'mc_add_admin_menu']);

        // Handle form submissions
        add_action('admin_init', [$this, 'mc_handle_actions']);

        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'mc_enqueue_admin_scripts']);

        // AJAX handlers
        add_action('wp_ajax_wpdcf_get_post_meta', [$this, 'mc_ajax_get_post_meta']);
        add_action('wp_ajax_wpdcf_get_dummy_posts', [$this, 'mc_ajax_get_dummy_posts']);
        add_action('wp_ajax_wpdcf_get_authors', [$this, 'mc_ajax_get_authors']);

        // Cleanup hooks for permanent deletion
        add_action('before_delete_post', [$this, 'mc_cleanup_post_meta'], 10, 1);
        add_action('deleted_user', [$this, 'mc_cleanup_user_meta'], 10, 2);

        if (class_exists('WooCommerce')) {
            add_action('admin_menu', [$this, 'mc_add_products_menu'], 11);
        }
        
        // Exclude products from post types
        add_action('admin_init', [$this, 'mc_exclude_products_from_post_types']);
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @since 1.0.0
     * @access public
     * @param string $hook Current admin page hook
     * @return void
     */
    public function mc_enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'wp-dummy-content-filler') !== false) {
            wp_enqueue_script(
                'wp-dummy-content-filler-admin',
                WP_DUMMY_CONTENT_FILLER_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                WP_DUMMY_CONTENT_FILLER_VERSION,
                true
            );

            wp_enqueue_style(
                'wp-dummy-content-filler-admin',
                WP_DUMMY_CONTENT_FILLER_PLUGIN_URL . 'assets/css/admin.css',
                [],
                WP_DUMMY_CONTENT_FILLER_VERSION
            );

            // Localize script for AJAX
            wp_localize_script('wp-dummy-content-filler-admin', 'wpdcf_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wpdcf_ajax_nonce')
            ]);
        }
    }

    /**
     * Add main menu and post types submenu
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_add_admin_menu()
    {
        add_menu_page(
            'WP Dummy Content Filler',
            'Dummy Content',
            'manage_options',
            'wp-dummy-content-filler',
            [$this, 'mc_render_post_types_page'],
            WP_DUMMY_CONTENT_FILLER_PLUGIN_URL . 'assets/icon/icon.png',
            30
        );

        add_submenu_page(
            'wp-dummy-content-filler',
            'Post Types',
            'Post Types',
            'manage_options',
            'wp-dummy-content-filler',
            [$this, 'mc_render_post_types_page']
        );

        add_submenu_page(
            'wp-dummy-content-filler',
            'Users',
            'Users',
            'manage_options',
            'wp-dummy-content-filler-users',
            [$this, 'mc_render_users_page']
        );
    }

    /**
     * Add products submenu (WooCommerce specific)
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_add_products_menu()
    {
        add_submenu_page(
            'wp-dummy-content-filler',
            'Products',
            'Products',
            'manage_options',
            'wp-dummy-content-filler-products',
            [WP_Dummy_Content_Filler_Products::mc_get_instance(), 'mc_render_products_page']
        );
    }

    /**
     * Handle form submissions and clear actions
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_handle_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['generate_posts']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'generate_dummy_posts')) {
            $this->mc_generate_dummy_posts();
        }

        $clear_posts_nonce_action = 'clear_dummy_posts';
        if (wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', $clear_posts_nonce_action)) {
            if (isset($_REQUEST['clear_dummy_posts']) && !empty($_REQUEST['post_type'])) {
                $post_type = sanitize_key($_REQUEST['post_type']);

                // Basic validation - only allow public post types
                if (!in_array($post_type, get_post_types(['public' => true]), true)) {
                    set_transient('dummy_content_results', [
                        'message' => 'Invalid post type selected.',
                        'type'    => 'error'
                    ], 45);
                    wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler'));
                    exit;
                }

                $deleted_count = $this->mc_clear_dummy_posts($post_type);

                set_transient('dummy_content_results', [
                    'message' => sprintf(
                        'Successfully deleted %d %s (and associated data).',
                        $deleted_count,
                        _n('dummy post', 'dummy posts', $deleted_count)
                    ),
                    'type' => 'success'
                ], 45);

                wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler'));
                exit;
            }
        }

        if (isset($_POST['generate_users']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'generate_dummy_users')) {
            $this->mc_generate_dummy_users();
        }

        $clear_users_nonce_action = 'clear_dummy_users';
        if (wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', $clear_users_nonce_action)) {
            if (isset($_REQUEST['clear_dummy_users'])) {
                $deleted_count = $this->mc_clear_dummy_users();

                set_transient('dummy_user_results', [
                    'message' => sprintf(
                        'Successfully deleted %d %s (and associated data).',
                        $deleted_count,
                        _n('dummy user', 'dummy users', $deleted_count)
                    ),
                    'type' => 'success'
                ], 45);

                wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler-users'));
                exit;
            }
        }
    }

    /**
     * Get Faker instance (initialize if not exists)
     *
     * @since 1.0.0
     * @access private
     * @return Faker\Generator|false Faker instance or false if not available
     */
    private function mc_get_faker()
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
     * Generate dummy posts based on form submission
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_generate_dummy_posts()
    {
        $post_type      = sanitize_text_field($_POST['post_type'] ?? 'post');
        $count          = intval($_POST['post_count'] ?? 5);
        $with_images    = isset($_POST['with_images']);
        $create_excerpt = isset($_POST['create_excerpt']);
        $post_author    = intval($_POST['post_author'] ?? get_current_user_id());

        // Get post meta configurations
        $post_meta_config = [];
        if (isset($_POST['post_meta']) && is_array($_POST['post_meta'])) {
            foreach ($_POST['post_meta'] as $meta_key => $config) {
                if (!empty($config['type'])) {
                    $post_meta_config[$meta_key] = [
                        'type' => sanitize_text_field($config['type'])
                    ];
                }
            }
        }

        // Get taxonomy configurations
        $taxonomy_config = [];
        if (isset($_POST['taxonomies']) && is_array($_POST['taxonomies'])) {
            foreach ($_POST['taxonomies'] as $taxonomy => $config) {
                if (isset($config['create']) && $config['create'] === 'yes') {
                    $taxonomy_config[$taxonomy] = [
                        'create' => 'yes',
                        'assign' => isset($config['assign']) ? intval($config['assign']) : 2
                    ];
                }
            }
        }

        $results = ['success' => 0, 'failed' => 0, 'taxonomies_created' => 0];

        // First create taxonomies if requested
        $created_terms = [];
        if (!empty($taxonomy_config)) {
            foreach ($taxonomy_config as $taxonomy => $config) {
                // Always create 10 terms for each taxonomy that has create enabled
                $terms = $this->mc_create_dummy_terms($taxonomy, 10);
                $created_terms[$taxonomy] = $terms;
                $results['taxonomies_created'] += count($terms);
            }
        }

        // Generate posts
        for ($i = 0; $i < $count; $i++) {
            $post_id = $this->mc_create_dummy_post(
                $post_type, 
                $with_images, 
                $create_excerpt, 
                $post_author, 
                $post_meta_config, 
                $created_terms, 
                $taxonomy_config
            );

            if ($post_id && !is_wp_error($post_id)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        // Store results in transient for display
        set_transient('dummy_content_results', [
            'message' => sprintf(
                'Successfully generated %d %s with %d taxonomy terms. Failed: %d',
                $results['success'],
                _n('post', 'posts', $results['success']),
                $results['taxonomies_created'],
                $results['failed']
            )
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler'));
        exit;
    }

    /**
     * Create a single dummy post
     *
     * @since 1.0.0
     * @access private
     * @param string $post_type      Post type slug
     * @param bool   $with_images    Whether to add featured image
     * @param bool   $create_excerpt Whether to create excerpt
     * @param int    $post_author    Author ID
     * @param array  $meta_config    Meta field configurations
     * @param array  $created_terms  Created taxonomy terms
     * @param array  $taxonomy_config Taxonomy configurations
     * @return int|false Post ID on success, false on failure
     */
    private function mc_create_dummy_post(
        $post_type = 'post', 
        $with_images = false, 
        $create_excerpt = false, 
        $post_author = 0, 
        $meta_config = [], 
        $created_terms = [], 
        $taxonomy_config = []
    ) {
        $faker = $this->mc_get_faker();

        // Use current user if no author specified or author is invalid
        if (!$post_author || !get_user_by('id', $post_author)) {
            $post_author = get_current_user_id();
        }

        $post_data = [
            'post_title'   => $faker ? $faker->sentence(6) : 'Dummy Post ' . time() . ' ' . wp_rand(1000, 9999),
            'post_content' => $faker ? $faker->paragraphs(3, true) : 'This is dummy content for testing purposes.',
            'post_status'  => 'publish',
            'post_type'    => $post_type,
            'post_author'  => $post_author,
        ];

        // Add excerpt if requested
        if ($create_excerpt) {
            $post_data['post_excerpt'] = $faker ? $faker->paragraph() : 'This is a dummy excerpt.';
        }

        $post_id = wp_insert_post($post_data);

        if ($post_id && !is_wp_error($post_id)) {
            // Add our meta key to identify dummy posts
            update_post_meta($post_id, WP_DUMMY_CONTENT_FILLER_META_KEY, '1');

            // Add featured image if requested
            if ($with_images) {
                $this->mc_attach_featured_image($post_id);
            }

            // Assign taxonomies
            if (!empty($created_terms)) {
                foreach ($created_terms as $taxonomy => $terms) {
                    if (!empty($terms) && isset($taxonomy_config[$taxonomy]['assign'])) {
                        $assign_count = $taxonomy_config[$taxonomy]['assign'];
                        $assign_count = min($assign_count, count($terms));

                        // Randomly select terms to assign
                        $shuffled_terms = $terms;
                        shuffle($shuffled_terms);
                        $selected_terms = array_slice($shuffled_terms, 0, $assign_count);

                        if (!empty($selected_terms)) {
                            wp_set_post_terms($post_id, $selected_terms, $taxonomy);
                        }
                    }
                }
            }

            // Add configured post meta
            foreach ($meta_config as $meta_key => $config) {
                $meta_value = $this->mc_generate_faker_value($config['type']);
                if ($meta_value !== '') {
                    update_post_meta($post_id, $meta_key, $meta_value);
                }
            }
        }

        return $post_id;
    }

    /**
     * Create dummy taxonomy terms
     *
     * @since 1.0.0
     * @access private
     * @param string $taxonomy Taxonomy slug
     * @param int    $count    Number of terms to create
     * @return array Array of created term IDs
     */
    private function mc_create_dummy_terms($taxonomy, $count = 10)
    {
        $faker = $this->mc_get_faker();
        $created_terms = [];

        // Get existing terms to avoid duplicates
        $existing_terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'names'
        ]);

        if (is_wp_error($existing_terms)) {
            $existing_terms = [];
        }

        $created      = 0;
        $attempts     = 0;
        $max_attempts = $count * 3; // Prevent infinite loop

        while ($created < $count && $attempts < $max_attempts) {
            $attempts++;

            if ($faker) {
                // For category-like taxonomies, use appropriate words
                if ($taxonomy === 'category') {
                    $term_name = $faker->words(2, true);
                } else if ($taxonomy === 'post_tag') {
                    $term_name = $faker->word();
                } else {
                    $term_name = $faker->words(rand(1, 3), true);
                }
            } else {
                $term_name = 'Term ' . ($created + 1) . ' ' . wp_rand(100, 999);
            }

            // Check if term already exists
            if (in_array($term_name, $existing_terms)) {
                continue;
            }

            $term_slug = sanitize_title($term_name . '-' . wp_rand(100, 999));

            $term = wp_insert_term($term_name, $taxonomy, [
                'slug'        => $term_slug,
                'description' => $faker ? $faker->sentence() : 'Dummy term description'
            ]);

            if (!is_wp_error($term)) {
                $created_terms[] = $term['term_id'];
                $existing_terms[] = $term_name;
                $created++;

                // Add meta to identify dummy terms
                add_term_meta($term['term_id'], WP_DUMMY_CONTENT_FILLER_META_KEY, '1');
            }
        }

        return $created_terms;
    }

    /**
     * Generate value using Faker based on type
     *
     * @since 1.0.0
     * @access private
     * @param string $type Faker data type
     * @return string|int|float Generated value
     */
    private function mc_generate_faker_value($type)
    {
        $faker = $this->mc_get_faker();

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
     * Attach featured image to post from plugin assets
     *
     * @since 1.0.0
     * @access private
     * @param int $post_id Post ID
     * @return bool True on success, false on failure
     */
    private function mc_attach_featured_image($post_id)
    {
        $image_dir = WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR . 'assets/img/';

        if (!file_exists($image_dir)) {
            return false;
        }

        $images = glob($image_dir . 'wp_dummy_content_filler_img_*.{jpg,jpeg,png,gif}', GLOB_BRACE);

        if (empty($images)) {
            return false;
        }

        $random_image = $images[array_rand($images)];
        $filename     = basename($random_image);

        // Check if image already exists in media library
        $existing_image = get_page_by_title($filename, OBJECT, 'attachment');

        if ($existing_image) {
            set_post_thumbnail($post_id, $existing_image->ID);
            return true;
        }

        // Upload image to media library
        $upload_file = wp_upload_bits($filename, null, file_get_contents($random_image));

        if (!$upload_file['error']) {
            $wp_filetype = wp_check_filetype($filename, null);
            $attachment = [
                'post_mime_type' => $wp_filetype['type'],
                'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];

            $attachment_id = wp_insert_attachment($attachment, $upload_file['file']);

            if (!is_wp_error($attachment_id)) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload_file['file']);
                wp_update_attachment_metadata($attachment_id, $attachment_data);
                set_post_thumbnail($post_id, $attachment_id);
                return true;
            }
        }

        return false;
    }

    /**
     * Get post meta keys for a specific post type
     *
     * @since 1.0.0
     * @access private
     * @param string $post_type Post type slug
     * @return array Array of meta keys with labels
     */
    private function mc_get_post_meta_keys($post_type = 'post')
    {
        // Skip if this is a product post type
        if ($post_type === 'product') {
            return [];
        }

        $meta_keys = [];

        // Check for ACF fields
        if (function_exists('acf_get_field_groups')) {
            $field_groups = acf_get_field_groups(['post_type' => $post_type]);
            foreach ($field_groups as $field_group) {
                $fields = acf_get_fields($field_group['key']);
                if ($fields) {
                    foreach ($fields as $field) {
                        if (isset($field['name']) && $field['name']) {
                            $meta_keys[$field['name']] = $field['label'] ?? $field['name'];
                        }
                    }
                }
            }
        }

        // Check for CMB2 fields
        if (class_exists('CMB2')) {
            $cmb2_boxes = CMB2_Boxes::get_all();
            foreach ($cmb2_boxes as $cmb_id => $cmb) {
                $object_types = $cmb->prop('object_types');
                if ($object_types && in_array($post_type, (array) $object_types)) {
                    $fields = $cmb->prop('fields');
                    if ($fields) {
                        foreach ($fields as $field) {
                            if (isset($field['id'])) {
                                $meta_keys[$field['id']] = $field['name'] ?? $field['id'];
                            }
                        }
                    }
                }
            }
        }

        return $meta_keys;
    }

    /**
     * Get post taxonomies for a specific post type
     *
     * @since 1.0.0
     * @access private
     * @param string $post_type Post type slug
     * @return array Array of taxonomies with labels
     */
    private function mc_get_post_taxonomies($post_type = 'post')
    {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $available_taxonomies = [];

        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->public && $taxonomy->show_ui) {
                $available_taxonomies[$taxonomy->name] = $taxonomy->label;
            }
        }

        return $available_taxonomies;
    }

    /**
     * Get all available authors for post assignment
     *
     * @since 1.0.0
     * @access private
     * @return array Array of authors with ID as key and name as value
     */
    private function mc_get_authors()
    {
        $authors = get_users([
            'role__in' => ['administrator', 'editor', 'author'],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ]);

        $author_list = [];
        foreach ($authors as $author) {
            $author_list[$author->ID] = $author->display_name . ' (' . $author->user_login . ')';
        }

        return $author_list;
    }

    /**
     * Delete dummy posts and their associated meta data
     *
     * @since 1.0.0
     * @access private
     * @param string $post_type Post type slug
     * @return int Number of deleted posts
     */
    private function mc_clear_dummy_posts($post_type = 'post')
    {
        global $wpdb;

        $args = [
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'meta_key'       => WP_DUMMY_CONTENT_FILLER_META_KEY,
            'meta_value'     => '1',
            'fields'         => 'ids',
            'post_status'    => 'any', // Include all statuses
        ];

        $dummy_posts   = get_posts($args);
        $deleted_count = 0;

        foreach ($dummy_posts as $post_id) {
            // Force delete the post (bypass trash)
            $deleted = wp_delete_post($post_id, true);

            if ($deleted && !is_wp_error($deleted)) {
                $deleted_count++;
            }
        }

        // Clean up dummy taxonomy terms
        $this->mc_cleanup_dummy_terms($post_type);

        return $deleted_count;
    }

    /**
     * Cleanup post meta when a post is deleted
     * This hook ensures all meta is deleted even if deletion happens outside our plugin
     *
     * @since 1.0.0
     * @access public
     * @param int $post_id Post ID
     * @return void
     */
    public function mc_cleanup_post_meta($post_id)
    {
        global $wpdb;

        // Check if this is a dummy post
        $is_dummy = get_post_meta($post_id, WP_DUMMY_CONTENT_FILLER_META_KEY, true);

        if ($is_dummy === '1') {
            // Delete all post meta for this post
            $wpdb->delete(
                $wpdb->postmeta,
                ['post_id' => $post_id],
                ['%d']
            );

            // Delete from our tracking if it exists separately
            delete_post_meta($post_id, WP_DUMMY_CONTENT_FILLER_META_KEY);
        }
    }

    /**
     * Cleanup orphaned post meta (safety measure)
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_cleanup_orphaned_post_meta()
    {
        global $wpdb;

        // Delete orphaned post meta (posts that don't exist anymore)
        $wpdb->query("
            DELETE pm FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.ID IS NULL
        ");
    }

    /**
     * Cleanup dummy taxonomy terms for a post type
     *
     * @since 1.0.0
     * @access private
     * @param string $post_type Post type slug
     * @return void
     */
    private function mc_cleanup_dummy_terms($post_type = 'post')
    {
        $taxonomies = get_object_taxonomies($post_type);

        foreach ($taxonomies as $taxonomy) {
            // Get all terms with our dummy marker
            $terms = get_terms([
                'taxonomy'     => $taxonomy,
                'meta_key'     => WP_DUMMY_CONTENT_FILLER_META_KEY,
                'meta_value'   => '1',
                'hide_empty'   => false,
                'fields'       => 'ids',
            ]);

            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term_id) {
                    wp_delete_term($term_id, $taxonomy);
                }
            }
        }
    }

    /**
     * Get all available user meta keys including defaults and custom fields
     * Filters out WordPress internal meta fields
     *
     * @since 1.0.0
     * @access private
     * @return array Array of user meta keys with labels
     */
    private function mc_get_user_meta_keys()
    {
        global $wpdb;

        // 1. Default WordPress user fields (from wp_users table)
        $default_user_fields = [
            'user_login'    => 'Username',
            'user_email'    => 'Email',
            'user_url'      => 'Website',
            'display_name'  => 'Display Name',
            'description'   => 'Biographical Info',
        ];

        // 2. Default WordPress user meta fields (commonly used)
        $default_user_meta = [
            'nickname'               => 'Nickname',
            'first_name'             => 'First Name',
            'last_name'              => 'Last Name',
            'rich_editing'           => 'Visual Editor',
            'admin_color'            => 'Admin Color Scheme',
            'show_admin_bar_front'   => 'Show Toolbar',
            'locale'                 => 'Language',
            'comment_shortcuts'      => 'Keyboard Shortcuts',
        ];

        // 3. WooCommerce fields (if available)
        $woocommerce_fields = [];
        if (class_exists('WooCommerce')) {
            $woocommerce_fields = [
                'billing_first_name'  => 'Billing First Name',
                'billing_last_name'   => 'Billing Last Name',
                'billing_company'     => 'Billing Company',
                'billing_address_1'   => 'Billing Address 1',
                'billing_address_2'   => 'Billing Address 2',
                'billing_city'        => 'Billing City',
                'billing_postcode'    => 'Billing Postcode',
                'billing_country'     => 'Billing Country',
                'billing_state'       => 'Billing State',
                'billing_phone'       => 'Billing Phone',
                'billing_email'       => 'Billing Email',
                'shipping_first_name' => 'Shipping First Name',
                'shipping_last_name'  => 'Shipping Last Name',
                'shipping_company'    => 'Shipping Company',
                'shipping_address_1'  => 'Shipping Address 1',
                'shipping_address_2'  => 'Shipping Address 2',
                'shipping_city'       => 'Shipping City',
                'shipping_postcode'   => 'Shipping Postcode',
                'shipping_country'    => 'Shipping Country',
                'shipping_state'      => 'Shipping State',
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
        $custom_meta_keys = $wpdb->get_col("
            SELECT DISTINCT meta_key 
            FROM {$wpdb->usermeta} 
            WHERE meta_key NOT LIKE '\_%' 
            AND meta_key NOT LIKE 'closedpostboxes_%'
            AND meta_key NOT LIKE 'metaboxhidden_%'
            AND meta_key NOT LIKE 'meta-box-order_%'
            AND meta_key NOT LIKE 'manage%columnshidden'
            AND meta_key NOT IN (
                'nickname', 'first_name', 'last_name', 'description', 'rich_editing', 
                'comment_shortcuts', 'admin_color', 'show_admin_bar_front', 
                'locale', 'wp_capabilities', 'wp_user_level', 'dismissed_wp_pointers',
                'session_tokens', 'billing_%', 'shipping_%', 'last_update',
                'nav_menu_recently_edited', 'community-events-location',
                'dashboard_quick_press_last_post_id', 'dashboard_widget_options',
                'show_welcome_panel', 'user-settings', 'user-settings-time',
                'syntax_highlighting', 'elementor_%', 'woocommerce_%',
                'woodmart_%', 'tran_%', 'trans_%', 'unreserved_%',
                'phillip_%', 'acf_user_settings', 'elementor_introduction',
                'last_login', 'wc_last_active', 'wishlist_%',
                'wp_persisted_preferences', 'wp_user-settings', 'wp_user-settings-time',
                'wp_dashboard_quick_press_last_post_id'
            )
            ORDER BY meta_key
            LIMIT 100
        ");

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
     * @access private
     * @param string $field_name Field name to analyze
     * @return string Recommended faker type
     */
    private function mc_get_auto_field_type($field_name)
    {
        $mappings = [
            'first_name' => 'name',
            'last_name'  => 'name',
            'nickname'   => 'name',
            'display_name' => 'name',
            'description' => 'paragraphs',
            'user_url'   => 'url',
            'email'      => 'email',
            'phone'      => 'phone',
            'address'    => 'address',
            'city'       => 'city',
            'country'    => 'country',
            'zip'        => 'zipcode',
            'postcode'   => 'zipcode',
            'company'    => 'company',
            'price'      => 'price',
            'date'       => 'date',
        ];

        foreach ($mappings as $key => $type) {
            if (strpos($field_name, $key) !== false) {
                return $type;
            }
        }

        return '';
    }

    /**
     * Generate dummy users based on form submission
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_generate_dummy_users()
    {
        $count = intval($_POST['user_count'] ?? 5);
        $role  = sanitize_text_field($_POST['user_role'] ?? 'subscriber');

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
        $faker   = $this->mc_get_faker();

        for ($i = 0; $i < $count; $i++) {
            $username = $faker ? $faker->userName : 'dummyuser_' . uniqid();
            $email    = $faker ? $faker->email : $username . '@example.com';

            // Create user with basic data
            $userdata = [
                'user_login' => $username,
                'user_email' => $email,
                'user_pass'  => 'password',
                'role'       => $role,
            ];

            // Add optional user fields if configured
            foreach ($user_meta_config as $meta_key => $config) {
                $meta_value = $this->mc_generate_faker_value($config['type']);
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
                update_user_meta($user_id, WP_DUMMY_CONTENT_FILLER_META_KEY, '1');

                // Always add first name and last name
                if ($faker) {
                    $first_name = $faker->firstName;
                    $last_name  = $faker->lastName;

                    update_user_meta($user_id, 'first_name', $first_name);
                    update_user_meta($user_id, 'last_name', $last_name);

                    // Set display name if not already set
                    if (!isset($userdata['display_name'])) {
                        $display_name = $faker->boolean(70) ? "$first_name $last_name" : $username;
                        wp_update_user([
                            'ID'           => $user_id,
                            'display_name' => $display_name
                        ]);
                    }
                }

                // Add configured user meta
                foreach ($user_meta_config as $meta_key => $config) {
                    $meta_value = $this->mc_generate_faker_value($config['type']);
                    if ($meta_value !== '' && !in_array($meta_key, ['user_url', 'display_name', 'description'])) {
                        update_user_meta($user_id, $meta_key, $meta_value);
                    }
                }

                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        set_transient('dummy_user_results', [
            'message' => sprintf(
                'Successfully generated %d %s. Failed: %d',
                $results['success'],
                _n('user', 'users', $results['success']),
                $results['failed']
            )
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler-users'));
        exit;
    }

    /**
     * Delete dummy users and their associated meta data
     *
     * @since 1.0.0
     * @access private
     * @return int Number of deleted users
     */
    private function mc_clear_dummy_users()
    {
        global $wpdb;

        $args = [
            'meta_key'   => WP_DUMMY_CONTENT_FILLER_META_KEY,
            'meta_value' => '1',
            'fields'     => 'ids',
        ];

        $dummy_users   = get_users($args);
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
        $this->mc_cleanup_orphaned_user_meta();

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
    public function mc_cleanup_user_meta($user_id, $reassign = null)
    {
        global $wpdb;

        // Check if this is a dummy user
        $is_dummy = get_user_meta($user_id, WP_DUMMY_CONTENT_FILLER_META_KEY, true);

        if ($is_dummy === '1') {
            // Delete all user meta for this user
            $wpdb->delete(
                $wpdb->usermeta,
                ['user_id' => $user_id],
                ['%d']
            );

            // Delete from our tracking if it exists separately
            delete_user_meta($user_id, WP_DUMMY_CONTENT_FILLER_META_KEY);
        }
    }

    /**
     * Cleanup orphaned user meta (safety measure)
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_cleanup_orphaned_user_meta()
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
     * AJAX handler for getting post meta fields
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_ajax_get_post_meta()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'wpdcf_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $post_type  = sanitize_text_field($_POST['post_type']);
        $meta_keys  = $this->mc_get_post_meta_keys($post_type);
        $taxonomies = $this->mc_get_post_taxonomies($post_type);

        ob_start();
        ?>
        <div class="post-meta-section">
            <h3>Post Content Options</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Post Author</th>
                    <td>
                        <select name="post_author" id="post-author-selector">
                            <option value="0">Select User</option>
                        </select>
                        <span class="description">Select who will be the author of generated posts</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Post Excerpt</th>
                    <td>
                        <label>
                            <input type="checkbox" name="create_excerpt" value="1">
                            Generate post excerpt
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Featured Image</th>
                    <td>
                        <label>
                            <input type="checkbox" name="with_images" value="1">
                            Add featured images from plugin assets
                        </label>
                    </td>
                </tr>
            </table>

            <?php if (!empty($taxonomies)): ?>
                <h3>Taxonomies</h3>
                <p class="description">When "Create Terms" is enabled, 10 dummy terms will be automatically created for each
                    taxonomy.</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Taxonomy</th>
                            <th>Create Terms?</th>
                            <th>Assign Terms per Post</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($taxonomies as $taxonomy_slug => $taxonomy_label): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($taxonomy_label); ?></strong><br>
                                    <small><?php echo esc_html($taxonomy_slug); ?></small>
                                </td>
                                <td>
                                    <select name="taxonomies[<?php echo esc_attr($taxonomy_slug); ?>][create]">
                                        <option value="no">No</option>
                                        <option value="yes">Yes</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="taxonomies[<?php echo esc_attr($taxonomy_slug); ?>][assign]" min="1"
                                        max="10" value="2" style="width: 80px;">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($meta_keys)): ?>
                <h3>Custom Post Meta Fields</h3>
                <p class="description">Configure how each custom field should be filled. Only fields from ACF, CMB2, or similar
                    plugins are listed.</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Field Name</th>
                            <th>Meta Key</th>
                            <th>Faker Data Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($meta_keys as $meta_key => $field_label): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($field_label); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html($meta_key); ?></code>
                                    <input type="hidden" name="post_meta[<?php echo esc_attr($meta_key); ?>][key]"
                                        value="<?php echo esc_attr($meta_key); ?>">
                                </td>
                                <td>
                                    <select name="post_meta[<?php echo esc_attr($meta_key); ?>][type]">
                                        <option value="">-- Leave Empty --</option>
                                        <?php foreach ($this->faker_types as $type_value => $type_label): ?>
                                            <option value="<?php echo esc_attr($type_value); ?>">
                                                <?php echo esc_html($type_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <h3>Custom Post Meta Fields</h3>
                <p class="description">No custom fields from ACF, CMB2, or similar plugins found for this post type.</p>
            <?php endif; ?>
        </div>
        <script>
            jQuery(document).ready(function ($) {
                // Load authors for post author selection
                $.ajax({
                    url: wpdcf_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wpdcf_get_authors',
                        nonce: wpdcf_ajax.nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            var authors = response.data;
                            var select = $('#post-author-selector');
                            $.each(authors, function (id, name) {
                                select.append('<option value="' + id + '">' + name + '</option>');
                            });
                        }
                    }
                });
            });
        </script>
        <?php
        wp_send_json_success(ob_get_clean());
    }

    /**
     * AJAX handler for getting authors list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_ajax_get_authors()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'wpdcf_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $authors = $this->mc_get_authors();
        wp_send_json_success($authors);
    }

    /**
     * AJAX handler for getting dummy posts list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_ajax_get_dummy_posts()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $post_type = !empty($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';

        // If no post type specified, get all post types except 'product'
        if (empty($post_type)) {
            $post_types = get_post_types(['public' => true]);
            $post_types = array_diff($post_types, ['product', 'attachment']);
        } else {
            // If specific post type is selected, ensure it's not 'product'
            if ($post_type === 'product') {
                wp_send_json_error('Products are managed in the Products tab.');
            }
            $post_types = [$post_type];
        }

        $args = [
            'post_type'      => $post_types,
            'posts_per_page' => 50,
            'meta_key'       => WP_DUMMY_CONTENT_FILLER_META_KEY,
            'meta_value'     => '1',
        ];

        $dummy_posts = get_posts($args);

        if (empty($dummy_posts)) {
            wp_send_json_error('No dummy posts found for the selected post type(s).');
        }

        ob_start();
        ?>
        <p>Found <?php echo count($dummy_posts); ?> dummy posts.</p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Post Type</th>
                    <th>Taxonomies</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dummy_posts as $post):
                    $taxonomies = get_object_taxonomies($post->post_type);
                    $post_terms = [];
                    foreach ($taxonomies as $taxonomy) {
                        $terms = get_the_terms($post->ID, $taxonomy);
                        if ($terms && !is_wp_error($terms)) {
                            foreach ($terms as $term) {
                                $post_terms[] = $term->name;
                            }
                        }
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($post->ID); ?></td>
                        <td>
                            <strong><?php echo esc_html($post->post_title); ?></strong>
                        </td>
                        <td><?php echo esc_html(get_post_type_object($post->post_type)->labels->singular_name); ?></td>
                        <td>
                            <?php if (!empty($post_terms)): ?>
                                <?php echo esc_html(implode(', ', $post_terms)); ?>
                            <?php else: ?>
                                <em>None</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(get_the_date('', $post)); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="button button-small"
                                target="_blank">View</a>
                            <a href="<?php echo esc_url(admin_url('post.php?action=delete&amp;post=' . $post->ID . '&amp;_wpnonce=' . wp_create_nonce('delete-post_' . $post->ID))); ?>"
                                class="button button-small button-danger"
                                onclick="return confirm('Are you sure? This will delete the post and all its meta data.')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        wp_send_json_success(ob_get_clean());
    }

    /**
     * Render post types page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_render_post_types_page()
    {
        // Get public post types and exclude products
        $post_types = get_post_types(['public' => true], 'objects');
        $selected_post_type = sanitize_text_field($_POST['post_type'] ?? 'post');

        // Filter out unwanted post types - exclude 'product', 'attachment', etc.
        $excluded_types = ['attachment', 'product'];
        $post_types = array_filter($post_types, function ($type) use ($excluded_types) {
            return !in_array($type->name, $excluded_types);
        });

        // Also update the delete section to exclude product post type
        ?>
        <div class="wrap wp-dummy-content-filler">
            <h1>Dummy Content Filler - Post Types</h1>

            <?php $this->mc_show_results_message(); ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-tab" class="nav-tab nav-tab-active">Generate Posts</a>
                <a href="#manage-tab" class="nav-tab">Manage Dummy Posts</a>
            </h2>

            <div id="generate-tab" class="tab-content active">
                <form method="post" action="" id="generate-posts-form">
                    <?php wp_nonce_field('generate_dummy_posts'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Post Type</th>
                            <td>
                                <select name="post_type" id="post-type-selector">
                                    <?php foreach ($post_types as $type): ?>
                                        <option value="<?php echo esc_attr($type->name); ?>" <?php selected($selected_post_type, $type->name); ?>>
                                            <?php echo esc_html($type->label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Note: WooCommerce products are handled separately in the Products tab</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Number of Posts</th>
                            <td>
                                <input type="number" name="post_count" min="1" max="100" value="5">
                            </td>
                        </tr>
                    </table>

                    <div id="post-meta-configuration">
                        <!-- Loaded via AJAX -->
                    </div>

                    <p class="submit">
                        <input type="submit" name="generate_posts" class="button button-primary" value="Generate Posts">
                    </p>
                </form>
            </div>

            <div id="manage-tab" class="tab-content">
                <h3>Manage Dummy Posts</h3>
                <p class="description">Note: This section only shows dummy posts for non-product post types. For WooCommerce
                    products, use the Products tab.</p>

                <div class="filter-section">
                    <form method="get" action="" class="filter-dummy-posts">
                        <input type="hidden" name="page" value="wp-dummy-content-filler">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Filter by Post Type</th>
                                <td>
                                    <select name="filter_post_type" id="filter-post-type">
                                        <option value="">All Post Types</option>
                                        <?php foreach ($post_types as $type): ?>
                                            <option value="<?php echo esc_attr($type->name); ?>">
                                                <?php echo esc_html($type->label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="apply-filter" class="button">Apply Filter</button>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>

                <div id="dummy-posts-list">
                    <p>Select a post type and click "Apply Filter" to see dummy posts.</p>
                </div>

                <div id="delete-section"
                    style="display:none; margin-top: 30px; padding: 20px; background: #fff5f5; border: 1px solid #ffb3b3; border-radius: 6px;">
                    <h4 style="color:#d63638; margin-top:0;">Delete Dummy Posts</h4>
                    <p class="description"><strong>Warning:</strong> This will <strong>permanently</strong> delete ALL dummy
                        posts of the selected post type (including meta, terms, featured images, etc.). This cannot be undone.
                    </p>

                    <form method="post" action="">
                        <?php wp_nonce_field('clear_dummy_posts', '_wpnonce'); ?>
                        <input type="hidden" name="clear_dummy_posts" value="1">

                        <p>
                            <label for="delete-post-type"><strong>Select post type to clean:</strong></label><br>
                            <select name="post_type" id="delete-post-type" required style="min-width:240px;">
                                <option value="">— Select post type —</option>
                                <?php foreach ($post_types as $type): ?>
                                    <option value="<?php echo esc_attr($type->name); ?>">
                                        <?php echo esc_html($type->label); ?> (<?php echo esc_html($type->name); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p style="margin-top:20px;">
                            <input type="submit" class="button button-large button-link-delete" value="Delete All Dummy Posts"
                                onclick="return confirm('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy content for the selected post type.\nNo backup. No trash. Really sure?');">
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render users page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_render_users_page()
    {
        $user_meta_keys = $this->mc_get_user_meta_keys();
        ?>
        <div class="wrap wp-dummy-content-filler">
            <h1>Dummy Content Filler - Users</h1>

            <?php
            $results = get_transient('dummy_user_results');
            if ($results) {
                delete_transient('dummy_user_results');
                echo '<div class="notice notice-success"><p>' . esc_html($results['message']) . '</p></div>';
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-users-tab" class="nav-tab nav-tab-active">Generate Users</a>
                <a href="#manage-users-tab" class="nav-tab">Manage Dummy Users</a>
            </h2>

            <div id="generate-users-tab" class="tab-content active">
                <form method="post" action="" id="generate-users-form">
                    <?php wp_nonce_field('generate_dummy_users'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Number of Users</th>
                            <td>
                                <input type="number" name="user_count" min="1" max="50" value="5">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">User Role</th>
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
                        </tr>
                    </table>

                    <div id="user-meta-configuration">
                        <h3>User Information</h3>
                        <p class="description">Configure how each user field should be filled. Only essential and custom fields
                            are shown.</p>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Field Name</th>
                                    <th>Field Key</th>
                                    <th>Faker Data Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_meta_keys as $meta_key => $field_label):
                                    $auto_type = $this->mc_get_auto_field_type($meta_key);
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
                                                <option value="">-- Leave Empty --</option>
                                                <?php foreach ($this->faker_types as $type_value => $type_label):
                                                    $selected = ($type_value === $auto_type) ? 'selected' : '';
                                                    ?>
                                                    <option value="<?php echo esc_attr($type_value); ?>" <?php echo $selected; ?>>
                                                        <?php echo esc_html($type_label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($auto_type): ?>
                                                <br><small class="description">Auto-selected based on field name</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="submit">
                        <input type="submit" name="generate_users" class="button button-primary" value="Generate Users">
                    </p>
                </form>
            </div>

            <div id="manage-users-tab" class="tab-content">
                <h3>Dummy Users Created by Plugin</h3>
                <?php
                $dummy_users = get_users([
                    'meta_key'   => WP_DUMMY_CONTENT_FILLER_META_KEY,
                    'meta_value' => '1',
                ]);

                if ($dummy_users) {
                    echo '<p>Found ' . count($dummy_users) . ' dummy users.</p>';
                    echo '<table class="widefat fixed striped">';
                    echo '<thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Name</th><th>Role</th><th>Actions</th></tr></thead>';
                    echo '<tbody>';

                    foreach ($dummy_users as $user) {
                        $first_name = get_user_meta($user->ID, 'first_name', true);
                        $last_name  = get_user_meta($user->ID, 'last_name', true);
                        $full_name  = trim($first_name . ' ' . $last_name);

                        echo '<tr>';
                        echo '<td>' . esc_html($user->ID) . '</td>';
                        echo '<td>' . esc_html($user->user_login) . '</td>';
                        echo '<td>' . esc_html($user->user_email) . '</td>';
                        echo '<td>' . esc_html($full_name ?: 'N/A') . '</td>';
                        echo '<td>' . esc_html(implode(', ', $user->roles)) . '</td>';
                        echo '<td>';
                        echo '<a href="' . esc_url(admin_url('profile.php?user_id=' . $user->ID)) . '" class="button button-small" target="_blank">View Profile</a>';
                        echo '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody></table>';

                    $nonce = wp_create_nonce('clear_dummy_users');
                    echo '<div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">';
                    echo '<p><strong>Warning:</strong> This will permanently delete ALL dummy users (except admin) along with their meta data.</p>';
                    echo '<p><a href="' . esc_url(add_query_arg([
                        'page'             => 'wp-dummy-content-filler-users',
                        'clear_dummy_users' => '1',
                        '_wpnonce'          => $nonce
                    ], admin_url('admin.php'))) . '" class="button button-danger" onclick="return confirm(\'WARNING: This will PERMANENTLY delete ALL dummy users (except admin) along with their meta data. This action cannot be undone. Are you sure?\')">Delete All Dummy Users</a></p>';
                    echo '</div>';
                } else {
                    echo '<p>No dummy users found.</p>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render products page (placeholder)
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_render_products_page()
    {
        ?>
        <div class="wrap">
            <h1>Dummy Content Filler - WooCommerce Products</h1>
            <p>WooCommerce product generation will be implemented in Phase 2.</p>
            <p>This tab will allow generation of dummy WooCommerce products with variations, categories, and attributes.</p>
        </div>
        <?php
    }

    /**
     * Render settings page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_render_settings_page()
    {
        ?>
        <div class="wrap">
            <h1>WP Dummy Content Filler - Settings</h1>
            <div class="card" style="max-width: 600px;">
                <h2 class="title">Welcome to Plugin Settings</h2>
                <p>This is the settings page for WP Dummy Content Filler plugin.</p>
                <p>More settings and options will be added in future updates.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Show results message from transient
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_show_results_message()
    {
        $results = get_transient('dummy_content_results');
        if ($results) {
            delete_transient('dummy_content_results');
            echo '<div class="notice notice-success"><p>' . esc_html($results['message']) . '</p></div>';
        }
    }

    /**
     * Exclude products from post types page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_exclude_products_from_post_types()
    {
        add_filter('wpdcf_get_post_types', function ($post_types) {
            if (isset($post_types['product'])) {
                unset($post_types['product']);
            }
            return $post_types;
        });
    }
}