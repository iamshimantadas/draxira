<?php
/**
 * Main plugin class for Draxira
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
 * Class Draxira
 * 
 * Handles all dummy content generation for posts, pages, and custom post types
 * 
 * @since 1.0.0
 * @access public
 */
class Draxira
{

    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @access private
     * @var Draxira|null
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
     * @return Draxira Singleton instance
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
        // Admin menu
        add_action('admin_menu', [$this, 'mc_drax_add_admin_menu']);

        // Handle form submissions
        add_action('admin_init', [$this, 'mc_drax_handle_actions']);

        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'mc_drax_enqueue_admin_scripts']);

        // AJAX handlers
        add_action('wp_ajax_draxira_get_post_meta', [$this, 'mc_drax_ajax_get_post_meta']);
        add_action('wp_ajax_draxira_get_dummy_posts', [$this, 'mc_drax_ajax_get_dummy_posts']);
        add_action('wp_ajax_draxira_get_authors', [$this, 'mc_drax_ajax_get_authors']);

        // Cleanup hooks for permanent deletion
        add_action('before_delete_post', [$this, 'mc_drax_cleanup_post_meta'], 10, 1);

        if (class_exists('WooCommerce')) {
            add_action('admin_menu', [$this, 'mc_drax_add_products_menu'], 11);
        }

        // Exclude products from post types
        add_action('admin_init', [$this, 'mc_drax_exclude_products_from_post_types']);
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @since 1.0.0
     * @access public
     * @param string $hook Current admin page hook
     * @return void
     */
    public function mc_drax_enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'draxira') !== false) {
            wp_enqueue_script(
                'draxira-admin',
                DRAXIRA_PLUGIN_URL . 'assets/js/admin.js',
                ['jquery'],
                DRAXIRA_VERSION,
                true
            );

            wp_enqueue_style(
                'draxira-admin',
                DRAXIRA_PLUGIN_URL . 'assets/css/admin.css',
                [],
                DRAXIRA_VERSION
            );

            // Localize script for AJAX with all messages
            wp_localize_script('draxira-admin', 'draxira_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('draxira_ajax_nonce'),
                'loading_message' => __('Loading configuration...', 'draxira'),
                'loading_posts' => __('Loading dummy posts...', 'draxira'),
                'loading_products' => __('Loading dummy products...', 'draxira'),
                'error_message' => __('Error loading configuration.', 'draxira'),
                'error_loading_posts' => __('Error loading posts.', 'draxira'),
                'error_loading_products' => __('Error loading products.', 'draxira'),
                'confirm_delete' => __('Are you sure? This action cannot be undone.', 'draxira'),
                'confirm_delete_post' => __('Are you sure? This will delete the post and all its meta data.', 'draxira'),
                'confirm_delete_all' => __('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy content.\nNo backup. No trash. Really sure?', 'draxira'),
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
    public function mc_drax_add_admin_menu()
    {
        add_menu_page(
            'Draxira - Dummy Content Generator',
            'Draxira',
            'manage_options',
            'draxira',
            [$this, 'mc_drax_render_post_types_page'],
            DRAXIRA_PLUGIN_URL . 'assets/icon.png',
            30
        );

        add_submenu_page(
            'draxira',
            'Post Types',
            'Post Types',
            'manage_options',
            'draxira',
            [$this, 'mc_drax_render_post_types_page']
        );

        add_submenu_page(
            'draxira',
            'Users',
            'Users',
            'manage_options',
            'draxira-users',
            [Draxira_Users::get_instance(), 'mc_drax_render_users_page']
        );
    }

    /**
     * Add products submenu (WooCommerce specific)
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_add_products_menu()
    {
        add_submenu_page(
            'draxira',
            'Products',
            'Products',
            'manage_options',
            'draxira-products',
            [Draxira_Products::get_instance(), 'mc_drax_render_products_page']
        );
    }

    /**
     * Handle form submissions and clear actions
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_handle_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['generate_posts']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'generate_dummy_posts')) {
            $this->mc_drax_generate_dummy_posts();
        }

        $clear_posts_nonce_action = 'clear_dummy_posts';
        if (isset($_REQUEST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), $clear_posts_nonce_action)) {
            if (isset($_REQUEST['clear_dummy_posts']) && !empty($_REQUEST['post_type'])) {
                $post_type = sanitize_key($_REQUEST['post_type']);

                // Basic validation - only allow public post types
                if (!in_array($post_type, get_post_types(['public' => true]), true)) {
                    set_transient('draxira_results', [
                        'message' => __('Invalid post type selected.', 'draxira'),
                        'type' => 'error'
                    ], 45);
                    wp_safe_redirect(admin_url('admin.php?page=draxira'));
                    exit;
                }

                $deleted_count = $this->mc_drax_clear_dummy_posts($post_type);

                set_transient('draxira_results', [
                    'message' => sprintf(
                        /* translators: %1$d: number of deleted posts, %2$s: singular/plural of post */
                        __('Successfully deleted %1$d %2$s (and associated data).', 'draxira'),
                        $deleted_count,
                        _n('dummy post', 'dummy posts', $deleted_count, 'draxira')
                    ),
                    'type' => 'success'
                ], 45);

                wp_safe_redirect(admin_url('admin.php?page=draxira'));
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
     * Generate dummy posts based on form submission
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_generate_dummy_posts()
    {
        $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
        $count = intval($_POST['post_count'] ?? 5);
        $with_images = isset($_POST['with_images']);
        $create_excerpt = isset($_POST['create_excerpt']);
        $post_author = intval($_POST['post_author'] ?? get_current_user_id());

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
                $terms = $this->mc_drax_create_dummy_terms($taxonomy, 10);
                $created_terms[$taxonomy] = $terms;
                $results['taxonomies_created'] += count($terms);
            }
        }

        // Generate posts
        for ($i = 0; $i < $count; $i++) {
            $post_id = $this->mc_drax_create_dummy_post(
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
        set_transient('draxira_results', [
            'message' => sprintf(
                /* translators: %1$d: number of generated posts, %2$s: singular/plural of post, %3$d: number of taxonomy terms created, %4$d: number of failures */
                __('Successfully generated %1$d %2$s with %3$d taxonomy terms. Failed: %4$d', 'draxira'),
                $results['success'],
                _n('post', 'posts', $results['success'], 'draxira'),
                $results['taxonomies_created'],
                $results['failed']
            )
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=draxira'));
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
    private function mc_drax_create_dummy_post(
        $post_type = 'post',
        $with_images = false,
        $create_excerpt = false,
        $post_author = 0,
        $meta_config = [],
        $created_terms = [],
        $taxonomy_config = []
    ) {
        $faker = $this->mc_drax_get_faker();

        // Use current user if no author specified or author is invalid
        if (!$post_author || !get_user_by('id', $post_author)) {
            $post_author = get_current_user_id();
        }

        $post_data = [
            'post_title' => $faker ? $faker->sentence(6) : 'Dummy Post ' . time() . ' ' . wp_rand(1000, 9999),
            'post_content' => $faker ? $faker->paragraphs(3, true) : 'This is dummy content for testing purposes.',
            'post_status' => 'publish',
            'post_type' => $post_type,
            'post_author' => $post_author,
        ];

        // Add excerpt if requested
        if ($create_excerpt) {
            $post_data['post_excerpt'] = $faker ? $faker->paragraph() : 'This is a dummy excerpt.';
        }

        $post_id = wp_insert_post($post_data);

        if ($post_id && !is_wp_error($post_id)) {
            // Add our meta key to identify dummy posts
            update_post_meta($post_id, DRAXIRA_META_KEY, '1');

            // Add featured image if requested
            if ($with_images) {
                $this->mc_drax_attach_featured_image($post_id);
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
                $meta_value = $this->mc_drax_generate_faker_value($config['type']);
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
    private function mc_drax_create_dummy_terms($taxonomy, $count = 10)
    {
        $faker = $this->mc_drax_get_faker();
        $created_terms = [];

        // Get existing terms to avoid duplicates
        $existing_terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'names'
        ]);

        if (is_wp_error($existing_terms)) {
            $existing_terms = [];
        }

        $created = 0;
        $attempts = 0;
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
                'slug' => $term_slug,
                'description' => $faker ? $faker->sentence() : 'Dummy term description'
            ]);

            if (!is_wp_error($term)) {
                $created_terms[] = $term['term_id'];
                $existing_terms[] = $term_name;
                $created++;

                // Add meta to identify dummy terms
                add_term_meta($term['term_id'], DRAXIRA_META_KEY, '1');
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
     * Attach featured image to post from plugin assets
     *
     * @since 1.0.0
     * @access private
     * @param int $post_id Post ID
     * @return bool True on success, false on failure
     */
    private function mc_drax_attach_featured_image($post_id)
    {
        $image_dir = DRAXIRA_PLUGIN_DIR . 'assets/img/';

        if (!file_exists($image_dir)) {
            return false;
        }

        $images = glob($image_dir . 'dummy_content_filler_img_*.{jpg,jpeg,png,gif}', GLOB_BRACE);

        if (empty($images)) {
            return false;
        }

        $random_image = $images[array_rand($images)];
        $filename = basename($random_image);

        // Check if image already exists in media library using WP_Query (replaces deprecated get_page_by_title)
        $existing_image = $this->mc_drax_get_attachment_by_title($filename);

        if ($existing_image) {
            set_post_thumbnail($post_id, $existing_image);
            return true;
        }

        // Upload image to media library
        $upload_file = wp_upload_bits($filename, null, file_get_contents($random_image));

        if (!$upload_file['error']) {
            $wp_filetype = wp_check_filetype($filename, null);
            $attachment = [
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content' => '',
                'post_status' => 'inherit'
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
     * Get attachment by title using WP_Query (replaces deprecated get_page_by_title)
     *
     * @since 1.0.0
     * @access private
     * @param string $title Attachment title
     * @return int|false Attachment ID or false
     */
    private function mc_drax_get_attachment_by_title($title)
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'title' => $title,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        if ($query->have_posts()) {
            return $query->posts[0];
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
    private function mc_drax_get_post_meta_keys($post_type = 'post')
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
    private function mc_drax_get_post_taxonomies($post_type = 'post')
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
    private function mc_drax_get_authors()
    {
        $authors = get_users([
            'role__in' => ['administrator', 'editor', 'author'],
            'orderby' => 'display_name',
            'order' => 'ASC',
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
    private function mc_drax_clear_dummy_posts($post_type = 'post')
    {
        global $wpdb;

        $args = [
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
            'post_status' => 'any', // Include all statuses
        ];

        $dummy_posts = get_posts($args);
        $deleted_count = 0;

        foreach ($dummy_posts as $post_id) {
            // Force delete the post (bypass trash)
            $deleted = wp_delete_post($post_id, true);

            if ($deleted && !is_wp_error($deleted)) {
                $deleted_count++;
            }
        }

        // Clean up dummy taxonomy terms
        $this->mc_drax_cleanup_dummy_terms($post_type);

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
    public function mc_drax_cleanup_post_meta($post_id)
    {
        global $wpdb;

        // Check if this is a dummy post
        $is_dummy = get_post_meta($post_id, DRAXIRA_META_KEY, true);

        if ($is_dummy === '1') {
            // Delete all post meta for this post
            $wpdb->delete(
                $wpdb->postmeta,
                ['post_id' => $post_id],
                ['%d']
            );

            // Delete from our tracking if it exists separately
            delete_post_meta($post_id, DRAXIRA_META_KEY);
        }
    }

    /**
     * Cleanup orphaned post meta (safety measure)
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_cleanup_orphaned_post_meta()
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
    private function mc_drax_cleanup_dummy_terms($post_type = 'post')
    {
        $taxonomies = get_object_taxonomies($post_type);

        foreach ($taxonomies as $taxonomy) {
            // Get all terms with our dummy marker
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'meta_key' => DRAXIRA_META_KEY,
                'meta_value' => '1',
                'hide_empty' => false,
                'fields' => 'ids',
            ]);

            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term_id) {
                    wp_delete_term($term_id, $taxonomy);
                }
            }
        }
    }

    /**
     * AJAX handler for getting post meta fields
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_post_meta()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $post_type = sanitize_text_field($_POST['post_type']);
        $meta_keys = $this->mc_drax_get_post_meta_keys($post_type);
        $taxonomies = $this->mc_drax_get_post_taxonomies($post_type);

        // Get authors directly - no AJAX needed since we already have the data
        $authors = $this->mc_drax_get_authors();

        $output = '<div class="post-meta-section">
        <h3>' . esc_html__('Post Content Options', 'draxira') . '</h3>
        <table class="form-table">
             <tr>
                <th scope="row">' . esc_html__('Post Author', 'draxira') . '</th>
                <td>
                    <select name="post_author" id="post-author-selector">
                        <option value="0">' . esc_html__('Select User', 'draxira') . '</option>';

        // Add authors directly in the HTML
        foreach ($authors as $author_id => $author_name) {
            $output .= '<option value="' . esc_attr($author_id) . '">' . esc_html($author_name) . '</option>';
        }

        $output .= '</select>
                    <span class="description">' . esc_html__('Select who will be the author of generated posts', 'draxira') . '</span>
                </td>
            </tr>
            <tr>
                <th scope="row">' . esc_html__('Post Excerpt', 'draxira') . '</th>
                <td>
                    <label>
                        <input type="checkbox" name="create_excerpt" value="1">
                        ' . esc_html__('Generate post excerpt', 'draxira') . '
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">' . esc_html__('Featured Image', 'draxira') . '</th>
                <td>
                    <label>
                        <input type="checkbox" name="with_images" value="1">
                        ' . esc_html__('Add featured images from plugin assets', 'draxira') . '
                    </label>
                </td>
            </tr>
        </table>';

        if (!empty($taxonomies)):
            $output .= '<h3>' . esc_html__('Taxonomies', 'draxira') . '</h3>
            <p class="description">' . esc_html__('When "Create Terms" is enabled, 10 dummy terms will be automatically created for each taxonomy.', 'draxira') . '</p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>' . esc_html__('Taxonomy', 'draxira') . '</th>
                        <th>' . esc_html__('Create Terms?', 'draxira') . '</th>
                        <th>' . esc_html__('Assign Terms per Post', 'draxira') . '</th>
                    </tr>
                </thead>
                <tbody>';
            foreach ($taxonomies as $taxonomy_slug => $taxonomy_label):
                $output .= '<tr>
                <td>
                    <strong>' . esc_html($taxonomy_label) . '</strong><br>
                    <small>' . esc_html($taxonomy_slug) . '</small>
                </td>
                <td>
                    <select name="taxonomies[' . esc_attr($taxonomy_slug) . '][create]">
                        <option value="no">' . esc_html__('No', 'draxira') . '</option>
                        <option value="yes">' . esc_html__('Yes', 'draxira') . '</option>
                    </select>
                </td>
                <td>
                    <input type="number" name="taxonomies[' . esc_attr($taxonomy_slug) . '][assign]" min="1" max="10" value="2" style="width: 80px;">
                </td>
            </tr>';
            endforeach;
            $output .= '</tbody>
            </table>';
        endif;

        if (!empty($meta_keys)):
            $output .= '<h3>' . esc_html__('Custom Post Meta Fields', 'draxira') . '</h3>
            <p class="description">' . esc_html__('Configure how each custom field should be filled. Only fields from ACF, CMB2, or similar plugins are listed.', 'draxira') . '</p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>' . esc_html__('Field Name', 'draxira') . '</th>
                        <th>' . esc_html__('Meta Key', 'draxira') . '</th>
                        <th>' . esc_html__('Faker Data Type', 'draxira') . '</th>
                    </thead>
                <tbody>';
            foreach ($meta_keys as $meta_key => $field_label):
                $output .= '<tr>
                <td><strong>' . esc_html($field_label) . '</strong></td>
                <td>
                    <code>' . esc_html($meta_key) . '</code>
                    <input type="hidden" name="post_meta[' . esc_attr($meta_key) . '][key]" value="' . esc_attr($meta_key) . '">
                </td>
                <td>
                    <select name="post_meta[' . esc_attr($meta_key) . '][type]">
                        <option value="">-- ' . esc_html__('Leave Empty', 'draxira') . ' --</option>';
                foreach ($this->mc_drax_faker_types as $type_value => $type_label):
                    $output .= '<option value="' . esc_attr($type_value) . '">' . esc_html($type_label) . '</option>';
                endforeach;
                $output .= '</select>
                </td>
            </tr>';
            endforeach;
            $output .= '</tbody>
        </table>';
        else:
            $output .= '<h3>' . esc_html__('Custom Post Meta Fields', 'draxira') . '</h3>
            <p class="description">' . esc_html__('No custom fields from ACF, CMB2, or similar plugins found for this post type.', 'draxira') . '</p>';
        endif;

        $output .= '</div>';

        wp_send_json_success($output);
    }

    /**
     * AJAX handler for getting authors list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_authors()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $authors = $this->mc_drax_get_authors();
        wp_send_json_success($authors);
    }

    /**
     * AJAX handler for getting dummy posts list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_dummy_posts()
    {
        // Add nonce check
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_send_json_error(__('Security check failed.', 'draxira'));
            wp_die();
        }

        $post_type = !empty($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';

        // If no post type specified, get all post types except 'product'
        if (empty($post_type)) {
            $post_types = get_post_types(['public' => true]);
            $post_types = array_diff($post_types, ['product', 'attachment']);
        } else {
            // If specific post type is selected, ensure it's not 'product'
            if ($post_type === 'product') {
                wp_send_json_error(__('Products are managed in the Products tab.', 'draxira'));
            }
            $post_types = [$post_type];
        }

        $args = [
            'post_type' => $post_types,
            'posts_per_page' => 50,
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
        ];

        $dummy_posts = get_posts($args);

        if (empty($dummy_posts)) {
            wp_send_json_error(__('No dummy posts found for the selected post type(s).', 'draxira'));
        }

        $output = '<p>' . sprintf(esc_html__('Found %d dummy posts.', 'draxira'), count($dummy_posts)) . '</p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>' . esc_html__('ID', 'draxira') . '</th>
                    <th>' . esc_html__('Title', 'draxira') . '</th>
                    <th>' . esc_html__('Post Type', 'draxira') . '</th>
                    <th>' . esc_html__('Taxonomies', 'draxira') . '</th>
                    <th>' . esc_html__('Date', 'draxira') . '</th>
                    <th>' . esc_html__('Actions', 'draxira') . '</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($dummy_posts as $post):
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
            $output .= '<tr>
                <td>' . esc_html($post->ID) . '</td>
                <td><strong>' . esc_html($post->post_title) . '</strong></td>
                <td>' . esc_html(get_post_type_object($post->post_type)->labels->singular_name) . '</td>
                <td>' . (!empty($post_terms) ? esc_html(implode(', ', $post_terms)) : '<em>' . esc_html__('None', 'draxira') . '</em>') . '</td>
                <td>' . esc_html(get_the_date('', $post)) . '</td>
                <td>
                    <a href="' . esc_url(get_edit_post_link($post->ID)) . '" class="button button-small">' . esc_html__('Edit', 'draxira') . '</a>
                    <a href="' . esc_url(get_permalink($post->ID)) . '" class="button button-small" target="_blank">' . esc_html__('View', 'draxira') . '</a>
                    <a href="' . esc_url(admin_url('post.php?action=delete&amp;post=' . $post->ID . '&amp;_wpnonce=' . wp_create_nonce('delete-post_' . $post->ID))) . '"
                        class="button button-small button-danger"
                        onclick="return confirm(\'' . esc_js(__('Are you sure? This will delete the post and all its meta data.', 'draxira')) . '\')">' . esc_html__('Delete', 'draxira') . '</a>
                </td>
            </tr>';
        endforeach;

        $output .= '</tbody>
        </table>';

        wp_send_json_success($output);
    }

    /**
     * Render post types page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_render_post_types_page()
    {
        // Get public post types and exclude products
        $post_types = get_post_types(['public' => true], 'objects');
        $selected_post_type = sanitize_text_field($_POST['post_type'] ?? 'post');

        // Filter out unwanted post types - exclude 'product', 'attachment', etc.
        $excluded_types = ['attachment', 'product'];
        $post_types = array_filter($post_types, function ($type) use ($excluded_types) {
            return !in_array($type->name, $excluded_types);
        });

        ?>
        <div class="wrap draxira">
            <h1><?php esc_html_e('Draxira – Dummy Content Generator', 'draxira'); ?></h1>

            <?php $this->mc_drax_show_results_message(); ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-tab" class="nav-tab nav-tab-active"><?php esc_html_e('Generate Posts', 'draxira'); ?></a>
                <a href="#manage-tab" class="nav-tab"><?php esc_html_e('Manage Dummy Posts', 'draxira'); ?></a>
            </h2>

            <div id="generate-tab" class="tab-content active">
                <form method="post" action="" id="generate-posts-form">
                    <?php wp_nonce_field('generate_dummy_posts'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Post Type', 'draxira'); ?></th>
                            <td>
                                <select name="post_type" id="post-type-selector">
                                    <?php foreach ($post_types as $type): ?>
                                        <option value="<?php echo esc_attr($type->name); ?>" <?php echo esc_attr(selected($selected_post_type, $type->name, false)); ?>>
                                            <?php echo esc_html($type->label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Note: WooCommerce products are handled separately in the Products tab', 'draxira'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Number of Posts', 'draxira'); ?></th>
                            <td>
                                <input type="number" name="post_count" min="1" max="100" value="5">
                            </td>
                        </tr>
                    </table>

                    <div id="post-meta-configuration">
                        <!-- Loaded via AJAX -->
                    </div>

                    <p class="submit">
                        <input type="submit" name="generate_posts" class="button button-primary"
                            value="<?php esc_attr_e('Generate Posts', 'draxira'); ?>">
                    </p>
                </form>
            </div>

            <div id="manage-tab" class="tab-content">
                <h3><?php esc_html_e('Manage Dummy Posts', 'draxira'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Note: This section only shows dummy posts for non-product post types. For WooCommerce products, use the Products tab.', 'draxira'); ?>
                </p>

                <div class="filter-section">
                    <form method="get" action="" class="filter-dummy-posts">
                        <input type="hidden" name="page" value="draxira">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Filter by Post Type', 'draxira'); ?></th>
                                <td>
                                    <select name="filter_post_type" id="filter-post-type">
                                        <option value=""><?php esc_html_e('All Post Types', 'draxira'); ?></option>
                                        <?php foreach ($post_types as $type): ?>
                                            <option value="<?php echo esc_attr($type->name); ?>">
                                                <?php echo esc_html($type->label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="apply-filter"
                                        class="button"><?php esc_html_e('Apply Filter', 'draxira'); ?></button>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>

                <div id="dummy-posts-list">
                    <p><?php esc_html_e('Select a post type and click "Apply Filter" to see dummy posts.', 'draxira'); ?></p>
                </div>

                <div id="delete-section"
                    style="display:none; margin-top: 30px; padding: 20px; background: #fff5f5; border: 1px solid #ffb3b3; border-radius: 6px;">
                    <h4 style="color:#d63638; margin-top:0;"><?php esc_html_e('Delete Dummy Posts', 'draxira'); ?></h4>
                    <p class="description"><strong><?php esc_html_e('Warning:', 'draxira'); ?></strong>
                        <?php esc_html_e('This will permanently delete ALL dummy posts of the selected post type (including meta, terms, featured images, etc.). This cannot be undone.', 'draxira'); ?>
                    </p>

                    <form method="post" action="">
                        <?php wp_nonce_field('clear_dummy_posts', '_wpnonce'); ?>
                        <input type="hidden" name="clear_dummy_posts" value="1">

                        <p>
                            <label
                                for="delete-post-type"><strong><?php esc_html_e('Select post type to clean:', 'draxira'); ?></strong></label><br>
                            <select name="post_type" id="delete-post-type" required style="min-width:240px;">
                                <option value="">— <?php esc_html_e('Select post type', 'draxira'); ?> —</option>
                                <?php foreach ($post_types as $type): ?>
                                    <option value="<?php echo esc_attr($type->name); ?>">
                                        <?php echo esc_html($type->label); ?> (<?php echo esc_html($type->name); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p style="margin-top:20px;">
                            <input type="submit" class="button button-large button-link-delete"
                                value="<?php esc_attr_e('Delete All Dummy Posts', 'draxira'); ?>"
                                onclick="return confirm('<?php echo esc_js(__('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy content for the selected post type.\nNo backup. No trash. Really sure?', 'draxira')); ?>');">
                        </p>
                    </form>
                </div>
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
    private function mc_drax_show_results_message()
    {
        $results = get_transient('draxira_results');
        if ($results) {
            delete_transient('draxira_results');
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
    public function mc_drax_exclude_products_from_post_types()
    {
        add_filter('draxira_get_post_types', function ($post_types) {
            if (isset($post_types['product'])) {
                unset($post_types['product']);
            }
            return $post_types;
        });
    }
}