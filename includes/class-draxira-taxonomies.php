<?php
/**
 * Taxonomies Dummy Content Generator
 * 
 * Handles generation of dummy taxonomies and terms with meta fields.
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
 * Class Draxira_Taxonomies
 * 
 * Manages all taxonomy dummy content generation functionality.
 *
 * @since 1.0.0
 * @access public
 */
class Draxira_Taxonomies
{
    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @access private
     * @var Draxira_Taxonomies|null
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
        'number' => 'Number (1-100)',
        'price' => 'Price (10-1000)',
        'date' => 'Date',
        'boolean' => 'Boolean (Yes/No)',
        'url' => 'URL',
        'color' => 'Color',
        'hex_color' => 'Hex Color',
    ];

    /**
     * Get singleton instance of the class
     *
     * @since 1.0.0
     * @access public
     * @static
     * @return Draxira_Taxonomies Singleton instance
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
        add_action('admin_menu', [$this, 'mc_drax_add_taxonomies_menu'], 11);

        // Handle form submissions
        add_action('admin_init', [$this, 'mc_drax_handle_taxonomies_actions']);

        // AJAX handlers
        add_action('wp_ajax_draxira_get_taxonomies_for_post_type', [$this, 'mc_drax_ajax_get_taxonomies_for_post_type']);
        add_action('wp_ajax_draxira_get_term_meta_fields', [$this, 'mc_drax_ajax_get_term_meta_fields']);
        add_action('wp_ajax_draxira_get_dummy_terms', [$this, 'mc_drax_ajax_get_dummy_terms']);

        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'mc_drax_enqueue_taxonomies_scripts']);
    }

    /**
     * Enqueue taxonomies-specific scripts
     *
     * @since 1.0.0
     * @access public
     * @param string $hook Current admin page hook
     * @return void
     */
    public function mc_drax_enqueue_taxonomies_scripts($hook)
    {
        if ($hook === 'draxira_page_draxira-taxonomies') {
            wp_enqueue_script(
                'draxira-taxonomies',
                DRAXIRA_PLUGIN_URL . 'assets/js/taxonomies.js',
                ['jquery', 'draxira-admin'],
                DRAXIRA_VERSION,
                true
            );

            wp_localize_script('draxira-taxonomies', 'draxira_taxonomies_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('draxira_ajax_nonce'),
                'loading_taxonomies' => __('Loading taxonomies...', 'draxira'),
                'loading_meta_fields' => __('Loading term meta fields...', 'draxira'),
                'loading_terms' => __('Loading dummy terms...', 'draxira'),
                'error_loading_taxonomies' => __('Error loading taxonomies.', 'draxira'),
                'error_loading_meta' => __('Error loading term meta fields.', 'draxira'),
                'error_loading_terms' => __('Error loading dummy terms.', 'draxira'),
                'confirm_delete_all' => __('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy taxonomy terms.\nNo backup. No trash. Really sure?', 'draxira'),
            ]);
        }
    }

    /**
     * Add taxonomies submenu
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_add_taxonomies_menu()
    {
        add_submenu_page(
            'draxira',
            'Taxonomies',
            'Taxonomies',
            'manage_options',
            'draxira-taxonomies',
            [$this, 'mc_drax_render_taxonomies_page']
        );
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
            if (class_exists('Faker\Factory')) {
                $this->faker = Faker\Factory::create();
            } else {
                $this->faker = false;
            }
        }
        return $this->faker;
    }

    /**
     * Get post types that have taxonomies
     *
     * @since 1.0.0
     * @access private
     * @return array Array of post types with labels
     */
    private function mc_drax_get_post_types_with_taxonomies()
    {
        $post_types = get_post_types(['public' => true], 'objects');
        $filtered_types = [];

        foreach ($post_types as $post_type => $type_obj) {
            // Exclude attachments and product variations
            if (in_array($post_type, ['attachment', 'product_variation'])) {
                continue;
            }

            $taxonomies = get_object_taxonomies($post_type);
            if (!empty($taxonomies)) {
                $filtered_types[$post_type] = $type_obj->label;
            }
        }

        asort($filtered_types);
        return $filtered_types;
    }

    /**
     * Get taxonomies for a specific post type
     *
     * @since 1.0.0
     * @access private
     * @param string $post_type Post type slug
     * @return array Array of taxonomies with details
     */
    private function mc_drax_get_taxonomies_by_post_type($post_type = 'post')
    {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $filtered_taxonomies = [];

        foreach ($taxonomies as $taxonomy_slug => $taxonomy_obj) {
            // Only include public taxonomies
            if ($taxonomy_obj->public && $taxonomy_obj->show_ui) {
                $filtered_taxonomies[$taxonomy_slug] = [
                    'label' => $taxonomy_obj->label,
                    'hierarchical' => $taxonomy_obj->hierarchical,
                    'name' => $taxonomy_slug,
                ];
            }
        }

        return $filtered_taxonomies;
    }

    /**
     * Get term meta fields for a specific taxonomy
     *
     * @since 1.0.0
     * @access private
     * @param string $taxonomy Taxonomy slug
     * @return array Array of term meta keys with labels
     */
    private function mc_drax_get_term_meta_fields($taxonomy = 'category')
    {
        global $wpdb;
        $meta_keys = [];

        // Get ACF fields for taxonomy
        if (function_exists('acf_get_field_groups')) {
            $field_groups = acf_get_field_groups(['taxonomy' => $taxonomy]);
            foreach ($field_groups as $field_group) {
                $fields = acf_get_fields($field_group['key']);
                if ($fields) {
                    foreach ($fields as $field) {
                        if (isset($field['name']) && $field['name']) {
                            $meta_keys[$field['name']] = [
                                'label' => $field['label'] ?? $field['name'],
                                'type' => $this->mc_drax_get_auto_field_type($field['name']),
                                'field_type' => $field['type'] ?? 'text',
                            ];
                        }
                    }
                }
            }
        }

        // Get CMB2 fields for taxonomy
        if (class_exists('CMB2')) {
            $cmb2_boxes = CMB2_Boxes::get_all();
            foreach ($cmb2_boxes as $cmb_id => $cmb) {
                $object_types = $cmb->prop('object_types');
                if ($object_types && in_array('term', (array) $object_types)) {
                    $taxonomies = $cmb->prop('taxonomies');
                    if ($taxonomies && in_array($taxonomy, (array) $taxonomies)) {
                        $fields = $cmb->prop('fields');
                        if ($fields) {
                            foreach ($fields as $field) {
                                if (isset($field['id'])) {
                                    $meta_keys[$field['id']] = [
                                        'label' => $field['name'] ?? $field['id'],
                                        'type' => $this->mc_drax_get_auto_field_type($field['id']),
                                        'field_type' => $field['type'] ?? 'text',
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Get custom term meta from database
        $custom_meta_keys = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT meta_key 
            FROM {$wpdb->termmeta} 
            WHERE meta_key NOT LIKE %s
            AND meta_key NOT LIKE %s
            LIMIT 50
        ", '\_%', 'wp\_%'));

        foreach ($custom_meta_keys as $key) {
            if (!isset($meta_keys[$key])) {
                $meta_keys[$key] = [
                    'label' => ucwords(str_replace(['_', '-'], ' ', $key)),
                    'type' => $this->mc_drax_get_auto_field_type($key),
                    'field_type' => 'custom',
                ];
            }
        }

        // Remove duplicates and sort
        uasort($meta_keys, function ($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        return $meta_keys;
    }

    /**
     * Helper method to auto-select field type based on field name
     *
     * @since 1.0.0
     * @access private
     * @param string $field_name Field name to analyze
     * @return string Recommended faker type
     */
    private function mc_drax_get_auto_field_type($field_name)
    {
        $mappings = [
            'description' => 'paragraphs',
            'desc' => 'paragraphs',
            'excerpt' => 'text',
            'summary' => 'text',
            'image' => 'url',
            'img' => 'url',
            'photo' => 'url',
            'icon' => 'url',
            'color' => 'color',
            'colour' => 'color',
            'hex' => 'hex_color',
            'price' => 'price',
            'cost' => 'price',
            'amount' => 'number',
            'count' => 'number',
            'quantity' => 'number',
            'date' => 'date',
            'url' => 'url',
            'link' => 'url',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'featured' => 'boolean',
        ];

        $field_name_lower = strtolower($field_name);
        foreach ($mappings as $key => $type) {
            if (strpos($field_name_lower, $key) !== false) {
                return $type;
            }
        }

        return 'text';
    }

    // /**
    //  * Handle taxonomy form submissions
    //  *
    //  * @since 1.0.0
    //  * @access public
    //  * @return void
    //  */
    // public function mc_drax_handle_taxonomies_actions()
    // {
    //     if (!current_user_can('manage_options')) {
    //         return;
    //     }

    //     if (isset($_POST['generate_terms']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'generate_dummy_terms')) {
    //         $this->mc_drax_generate_dummy_terms();
    //     }

    //     $auto_assign_terms = isset($_POST['auto_assign_terms']) ? true : false;
    //     $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');

    //     foreach ($selected_taxonomies as $taxonomy_slug) {
    //         $terms_created = $this->mc_drax_create_dummy_terms_for_taxonomy(
    //             $taxonomy_slug,
    //             $terms_per_taxonomy,
    //             $term_meta_config,
    //             $auto_assign_terms,  // Add this parameter
    //             $post_type           // Add this parameter
    //         );

    //         $results['success'] += $terms_created['success'];
    //         $results['failed'] += $terms_created['failed'];
    //     }

    //     $clear_terms_nonce_action = 'clear_dummy_terms';
    //     if (isset($_REQUEST['clear_dummy_terms']) && isset($_REQUEST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), $clear_terms_nonce_action)) {
    //         $deleted_count = $this->mc_drax_clear_dummy_terms();

    //         set_transient('draxira_taxonomies_results', [
    //             'message' => sprintf(
    //                 /* translators: %1$d: number of deleted terms */
    //                 __('Successfully deleted %1$d dummy taxonomy terms (and associated data).', 'draxira'),
    //                 $deleted_count
    //             ),
    //             'type' => 'success'
    //         ], 45);

    //         wp_safe_redirect(admin_url('admin.php?page=draxira-taxonomies'));
    //         exit;
    //     }
    // }

    /**
     * Handle taxonomy form submissions
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_handle_taxonomies_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['generate_terms']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'generate_dummy_terms')) {
            $this->mc_drax_generate_dummy_terms();
        }

        $clear_terms_nonce_action = 'clear_dummy_terms';
        if (isset($_REQUEST['clear_dummy_terms']) && isset($_REQUEST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), $clear_terms_nonce_action)) {
            $deleted_count = $this->mc_drax_clear_dummy_terms();

            set_transient('draxira_taxonomies_results', [
                'message' => sprintf(
                    /* translators: %1$d: number of deleted terms */
                    __('Successfully deleted %1$d dummy taxonomy terms (and associated data).', 'draxira'),
                    $deleted_count
                ),
                'type' => 'success'
            ], 45);

            wp_safe_redirect(admin_url('admin.php?page=draxira-taxonomies'));
            exit;
        }
    }

    // /**
    //  * Generate dummy taxonomy terms based on form submission
    //  *
    //  * @since 1.0.0
    //  * @access private
    //  * @return void
    //  */
    // private function mc_drax_generate_dummy_terms()
    // {
    //     $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
    //     $terms_per_taxonomy = intval($_POST['terms_per_taxonomy'] ?? 10);
    //     $terms_per_taxonomy = min($terms_per_taxonomy, 50); // Max 50 terms per taxonomy

    //     // Get selected taxonomies
    //     $selected_taxonomies = [];
    //     if (isset($_POST['selected_taxonomies']) && is_array($_POST['selected_taxonomies'])) {
    //         $selected_taxonomies = array_map('sanitize_text_field', $_POST['selected_taxonomies']);
    //     }

    //     // Get term meta configurations
    //     $term_meta_config = [];
    //     if (isset($_POST['term_meta']) && is_array($_POST['term_meta'])) {
    //         foreach ($_POST['term_meta'] as $meta_key => $config) {
    //             if (!empty($config['type'])) {
    //                 $term_meta_config[$meta_key] = [
    //                     'type' => sanitize_text_field($config['type'])
    //                 ];
    //             }
    //         }
    //     }

    //     if (empty($selected_taxonomies)) {
    //         set_transient('draxira_taxonomies_results', [
    //             'message' => __('Please select at least one taxonomy to generate terms.', 'draxira'),
    //             'type' => 'error'
    //         ], 30);
    //         wp_safe_redirect(admin_url('admin.php?page=draxira-taxonomies'));
    //         exit;
    //     }

    //     $results = ['success' => 0, 'failed' => 0];

    //     foreach ($selected_taxonomies as $taxonomy_slug) {
    //         $terms_created = $this->mc_drax_create_dummy_terms_for_taxonomy(
    //             $taxonomy_slug,
    //             $terms_per_taxonomy,
    //             $term_meta_config
    //         );

    //         $results['success'] += $terms_created['success'];
    //         $results['failed'] += $terms_created['failed'];
    //     }

    //     set_transient('draxira_taxonomies_results', [
    //         'message' => sprintf(
    //             /* translators: %1$d: number of generated terms, %2$d: number of failures */
    //             __('Successfully generated %1$d taxonomy terms. Failed: %2$d', 'draxira'),
    //             $results['success'],
    //             $results['failed']
    //         ),
    //         'type' => 'success'
    //     ], 30);

    //     wp_safe_redirect(admin_url('admin.php?page=draxira-taxonomies'));
    //     exit;
    // }


    /**
     * Generate dummy taxonomy terms based on form submission
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_generate_dummy_terms()
    {
        $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
        $terms_per_taxonomy = intval($_POST['terms_per_taxonomy'] ?? 10);
        $terms_per_taxonomy = min($terms_per_taxonomy, 50); // Max 50 terms per taxonomy

        // Get auto-assign settings
        $auto_assign_terms = isset($_POST['auto_assign_terms']) ? true : false;
        $assign_posts_count = isset($_POST['assign_posts_count']) ? intval($_POST['assign_posts_count']) : 3;
        $assign_posts_count = min($assign_posts_count, 20); // Max 20 posts per term

        // Get selected taxonomies
        $selected_taxonomies = [];
        if (isset($_POST['selected_taxonomies']) && is_array($_POST['selected_taxonomies'])) {
            $selected_taxonomies = array_map('sanitize_text_field', $_POST['selected_taxonomies']);
        }

        // Get term meta configurations
        $term_meta_config = [];
        if (isset($_POST['term_meta']) && is_array($_POST['term_meta'])) {
            foreach ($_POST['term_meta'] as $meta_key => $config) {
                if (!empty($config['type'])) {
                    $term_meta_config[$meta_key] = [
                        'type' => sanitize_text_field($config['type'])
                    ];
                }
            }
        }

        if (empty($selected_taxonomies)) {
            set_transient('draxira_taxonomies_results', [
                'message' => __('Please select at least one taxonomy to generate terms.', 'draxira'),
                'type' => 'error'
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=draxira-taxonomies'));
            exit;
        }

        $results = ['success' => 0, 'failed' => 0, 'assigned' => 0];

        foreach ($selected_taxonomies as $taxonomy_slug) {
            $terms_created = $this->mc_drax_create_dummy_terms_for_taxonomy(
                $taxonomy_slug,
                $terms_per_taxonomy,
                $term_meta_config,
                $auto_assign_terms,
                $post_type,
                $assign_posts_count
            );

            $results['success'] += $terms_created['success'];
            $results['failed'] += $terms_created['failed'];
            $results['assigned'] += $terms_created['assigned'];
        }

        // Build success message
        $message = sprintf(
            __('Successfully generated %1$d taxonomy terms. Failed: %2$d', 'draxira'),
            $results['success'],
            $results['failed']
        );

        if ($auto_assign_terms && $results['assigned'] > 0) {
            $message .= ' ' . sprintf(
                __('Terms were automatically assigned to %d posts.', 'draxira'),
                $results['assigned']
            );
        }

        set_transient('draxira_taxonomies_results', [
            'message' => $message,
            'type' => 'success'
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=draxira-taxonomies'));
        exit;
    }

    // /**
    //  * Create dummy terms for a specific taxonomy
    //  *
    //  * @since 1.0.0
    //  * @access private
    //  * @param string $taxonomy_slug    Taxonomy slug
    //  * @param int    $count            Number of terms to create
    //  * @param array  $term_meta_config Term meta configurations
    //  * @return array Array with success and failed counts
    //  */
    // private function mc_drax_create_dummy_terms_for_taxonomy($taxonomy_slug, $count, $term_meta_config = [])
    // {
    //     $faker = $this->mc_drax_get_faker();
    //     $taxonomy_obj = get_taxonomy($taxonomy_slug);

    //     if (!$taxonomy_obj) {
    //         return ['success' => 0, 'failed' => 0];
    //     }

    //     $is_hierarchical = $taxonomy_obj->hierarchical;
    //     $existing_terms = get_terms([
    //         'taxonomy' => $taxonomy_slug,
    //         'hide_empty' => false,
    //         'fields' => 'names',
    //     ]);

    //     if (is_wp_error($existing_terms)) {
    //         $existing_terms = [];
    //     }

    //     $created = 0;
    //     $failed = 0;
    //     $created_term_ids = [];
    //     $attempts = 0;
    //     $max_attempts = $count * 5;

    //     while ($created < $count && $attempts < $max_attempts) {
    //         $attempts++;

    //         // Generate term name
    //         $term_name = $this->mc_drax_generate_term_name($taxonomy_slug, $faker);

    //         // Check if term already exists
    //         if (in_array($term_name, $existing_terms)) {
    //             continue;
    //         }

    //         // Generate term slug
    //         $term_slug = sanitize_title($term_name . '-' . wp_rand(100, 9999));

    //         // Generate description
    //         $description = '';
    //         if ($faker && wp_rand(1, 10) > 3) { // 70% chance of having description
    //             $description = wp_rand(1, 2) == 1 ? $faker->sentence() : $faker->paragraph();
    //         }

    //         // Prepare term arguments
    //         $term_args = [
    //             'slug' => $term_slug,
    //             'description' => $description,
    //         ];

    //         // For hierarchical taxonomies, potentially assign a parent
    //         if ($is_hierarchical && $created > 0 && wp_rand(1, 10) > 7) { // 30% chance for child term
    //             $parent_terms = array_slice($created_term_ids, 0, ceil(count($created_term_ids) / 2));
    //             if (!empty($parent_terms)) {
    //                 $term_args['parent'] = $parent_terms[array_rand($parent_terms)];
    //             }
    //         }

    //         $term = wp_insert_term($term_name, $taxonomy_slug, $term_args);

    //         if (!is_wp_error($term)) {
    //             $term_id = $term['term_id'];
    //             $created_term_ids[] = $term_id;
    //             $existing_terms[] = $term_name;
    //             $created++;

    //             // Mark as dummy term
    //             add_term_meta($term_id, DRAXIRA_META_KEY, '1');

    //             // Add term meta fields
    //             foreach ($term_meta_config as $meta_key => $config) {
    //                 if (!empty($config['type'])) {
    //                     $meta_value = $this->mc_drax_generate_faker_value($config['type'], $faker);
    //                     if ($meta_value !== '' && $meta_value !== null) {
    //                         update_term_meta($term_id, $meta_key, $meta_value);
    //                     }
    //                 }
    //             }
    //         } else {
    //             $failed++;
    //         }
    //     }

    //     return ['success' => $created, 'failed' => $failed];
    // }

    /**
     * Create dummy terms for a specific taxonomy
     *
     * @since 1.0.0
     * @access private
     * @param string $taxonomy_slug       Taxonomy slug
     * @param int    $count               Number of terms to create
     * @param array  $term_meta_config    Term meta configurations
     * @param bool   $auto_assign_terms   Whether to auto-assign to posts
     * @param string $post_type           Post type for auto-assignment
     * @param int    $assign_posts_count  Number of posts to assign each term to
     * @return array Array with success, failed, and assigned counts
     */
    private function mc_drax_create_dummy_terms_for_taxonomy($taxonomy_slug, $count, $term_meta_config = [], $auto_assign_terms = false, $post_type = 'post', $assign_posts_count = 3)
    {
        $faker = $this->mc_drax_get_faker();
        $taxonomy_obj = get_taxonomy($taxonomy_slug);

        if (!$taxonomy_obj) {
            return ['success' => 0, 'failed' => 0, 'assigned' => 0];
        }

        $is_hierarchical = $taxonomy_obj->hierarchical;
        $existing_terms = get_terms([
            'taxonomy' => $taxonomy_slug,
            'hide_empty' => false,
            'fields' => 'names',
        ]);

        if (is_wp_error($existing_terms)) {
            $existing_terms = [];
        }

        $created = 0;
        $failed = 0;
        $assigned = 0;
        $created_term_ids = [];
        $attempts = 0;
        $max_attempts = $count * 5;

        while ($created < $count && $attempts < $max_attempts) {
            $attempts++;

            // Generate term name
            $term_name = $this->mc_drax_generate_term_name($taxonomy_slug, $faker);

            // Check if term already exists
            if (in_array($term_name, $existing_terms)) {
                continue;
            }

            // Generate term slug
            $term_slug = sanitize_title($term_name . '-' . wp_rand(100, 9999));

            // Generate description
            $description = '';
            if ($faker && wp_rand(1, 10) > 3) { // 70% chance of having description
                $description = wp_rand(1, 2) == 1 ? $faker->sentence() : $faker->paragraph();
            }

            // Prepare term arguments
            $term_args = [
                'slug' => $term_slug,
                'description' => $description,
            ];

            // For hierarchical taxonomies, potentially assign a parent
            if ($is_hierarchical && $created > 0 && wp_rand(1, 10) > 7) { // 30% chance for child term
                $parent_terms = array_slice($created_term_ids, 0, ceil(count($created_term_ids) / 2));
                if (!empty($parent_terms)) {
                    $term_args['parent'] = $parent_terms[array_rand($parent_terms)];
                }
            }

            $term = wp_insert_term($term_name, $taxonomy_slug, $term_args);

            if (!is_wp_error($term)) {
                $term_id = $term['term_id'];
                $created_term_ids[] = $term_id;
                $existing_terms[] = $term_name;
                $created++;

                // Mark as dummy term
                add_term_meta($term_id, DRAXIRA_META_KEY, '1');

                // Add term meta fields
                foreach ($term_meta_config as $meta_key => $config) {
                    if (!empty($config['type'])) {
                        $meta_value = $this->mc_drax_generate_faker_value($config['type'], $faker);
                        if ($meta_value !== '' && $meta_value !== null) {
                            update_term_meta($term_id, $meta_key, $meta_value);
                        }
                    }
                }

                // Auto-assign term to random posts if enabled
                if ($auto_assign_terms) {
                    $assigned += $this->mc_drax_assign_term_to_random_posts($term_id, $taxonomy_slug, $post_type, $assign_posts_count);
                }
            } else {
                $failed++;
            }
        }

        return ['success' => $created, 'failed' => $failed, 'assigned' => $assigned];
    }

    /**
     * Generate term name based on taxonomy
     *
     * @since 1.0.0
     * @access private
     * @param string $taxonomy Taxonomy slug
     * @param object $faker    Faker instance
     * @return string Generated term name
     */
    private function mc_drax_generate_term_name($taxonomy, $faker)
    {
        if ($faker) {
            // Category-like taxonomies get multi-word names
            if (in_array($taxonomy, ['category', 'product_cat', 'portfolio_category'])) {
                return ucwords($faker->words(wp_rand(1, 3), true));
            }

            // Tag-like taxonomies get single words
            if (in_array($taxonomy, ['post_tag', 'product_tag'])) {
                return $faker->word();
            }

            // Default: random between 1-3 words
            return ucwords($faker->words(wp_rand(1, 3), true));
        }

        // Fallback term names
        $fallback_names = [
            'Technology',
            'Design',
            'Development',
            'Marketing',
            'Business',
            'Lifestyle',
            'Travel',
            'Food',
            'Health',
            'Education',
            'Featured',
            'Popular',
            'Recent',
            'Trending',
            'Top Rated',
        ];

        return $fallback_names[array_rand($fallback_names)] . ' ' . wp_rand(1, 999);
    }

    /**
     * Generate value using Faker based on type
     *
     * @since 1.0.0
     * @access private
     * @param string $type  Faker data type
     * @param object $faker Faker instance
     * @return string|int|float Generated value
     */
    private function mc_drax_generate_faker_value($type, $faker)
    {
        if (!$faker) {
            return '';
        }

        switch ($type) {
            case 'text':
                return $faker->sentence(wp_rand(5, 15));
            case 'paragraphs':
                return $faker->paragraphs(wp_rand(1, 3), true);
            case 'words':
                return $faker->words(wp_rand(3, 8), true);
            case 'name':
                return $faker->name();
            case 'number':
                return $faker->numberBetween(1, 1000);
            case 'price':
                return $faker->randomFloat(2, 1, 9999);
            case 'date':
                return $faker->date();
            case 'boolean':
                return $faker->boolean() ? 'yes' : 'no';
            case 'url':
                return $faker->url();
            case 'color':
                return $faker->colorName();
            case 'hex_color':
                return $faker->hexColor();
            default:
                return '';
        }
    }

    /**
     * Delete dummy taxonomy terms
     *
     * @since 1.0.0
     * @access private
     * @return int Number of deleted terms
     */
    private function mc_drax_clear_dummy_terms()
    {
        global $wpdb;

        // Get all terms with our dummy marker
        $dummy_terms = get_terms([
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
            'hide_empty' => false,
            'fields' => 'ids',
            'number' => 0, // Get all
        ]);

        if (is_wp_error($dummy_terms)) {
            return 0;
        }

        $deleted_count = 0;

        foreach ($dummy_terms as $term_id) {
            // Get taxonomy for this term
            $term = get_term($term_id);
            if ($term && !is_wp_error($term)) {
                // Delete term meta first
                $wpdb->delete(
                    $wpdb->termmeta,
                    ['term_id' => $term_id],
                    ['%d']
                );

                // Delete the term
                if (wp_delete_term($term_id, $term->taxonomy)) {
                    $deleted_count++;
                }
            }
        }

        return $deleted_count;
    }

    /**
     * AJAX handler for getting taxonomies for a post type
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_taxonomies_for_post_type()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $post_type = sanitize_text_field($_POST['post_type'] ?? '');

        if (empty($post_type)) {
            wp_send_json_error(__('No post type selected.', 'draxira'));
        }

        $taxonomies = $this->mc_drax_get_taxonomies_by_post_type($post_type);

        if (empty($taxonomies)) {
            wp_send_json_error(__('No taxonomies found for the selected post type.', 'draxira'));
        }

        ob_start();
        ?>
        <div class="taxonomies-selection-wrapper">
            <p class="description"><?php esc_html_e('Select the taxonomies where you want to generate terms:', 'draxira'); ?>
            </p>
            <div class="taxonomies-list">
                <div class="select-all-wrapper" style="margin-bottom: 10px;">
                    <label>
                        <input type="checkbox" id="select-all-taxonomies" class="select-all-taxonomies">
                        <?php esc_html_e('Select All Taxonomies', 'draxira'); ?>
                    </label>
                </div>
                <div class="taxonomies-checkboxes">
                    <?php foreach ($taxonomies as $taxonomy_slug => $taxonomy_data): ?>
                        <label style="display: block; margin-bottom: 10px; padding: 8px; background: #f9f9f9; border-radius: 4px;">
                            <input type="checkbox" name="selected_taxonomies[]" value="<?php echo esc_attr($taxonomy_slug); ?>"
                                class="taxonomy-checkbox">
                            <strong><?php echo esc_html($taxonomy_data['label']); ?></strong>
                            <br>
                            <small style="color: #666;">
                                <?php
                                echo esc_html($taxonomy_slug);
                                if ($taxonomy_data['hierarchical']) {
                                    echo ' - ' . esc_html__('Hierarchical', 'draxira');
                                } else {
                                    echo ' - ' . esc_html__('Non-hierarchical', 'draxira');
                                }
                                ?>
                            </small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        $output = ob_get_clean();

        wp_send_json_success($output);
    }

    /**
     * AJAX handler for getting term meta fields
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_term_meta_fields()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');

        if (empty($taxonomy)) {
            wp_send_json_error(__('No taxonomy selected.', 'draxira'));
        }

        $meta_fields = $this->mc_drax_get_term_meta_fields($taxonomy);

        ob_start();
        ?>
        <div class="term-meta-section">
            <h3><?php esc_html_e('Term Meta Fields', 'draxira'); ?></h3>
            <p class="description">
                <?php esc_html_e('Configure how term meta fields should be filled using Faker.', 'draxira'); ?>
                <?php if (empty($meta_fields)): ?>
                    <br><?php esc_html_e('No custom term meta fields found for this taxonomy.', 'draxira'); ?>
                <?php endif; ?>
            </p>

            <?php if (!empty($meta_fields)): ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Field Name', 'draxira'); ?></th>
                            <th><?php esc_html_e('Meta Key', 'draxira'); ?></th>
                            <th><?php esc_html_e('Data Type', 'draxira'); ?></th>
                    </thead>
                    <tbody>
                        <?php foreach ($meta_fields as $meta_key => $field_data):
                            $auto_type = $field_data['type'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($field_data['label']); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html($meta_key); ?></code>
                                    <input type="hidden" name="term_meta[<?php echo esc_attr($meta_key); ?>][key]"
                                        value="<?php echo esc_attr($meta_key); ?>">
                                </td>
                                <td>
                                    <select name="term_meta[<?php echo esc_attr($meta_key); ?>][type]">
                                        <option value="">-- <?php esc_html_e('Do not fill', 'draxira'); ?> --</option>
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
                                            class="description"><?php esc_html_e('Recommended based on field name', 'draxira'); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="description">
                    <?php esc_html_e('No term meta fields from ACF, CMB2, or custom meta found for this taxonomy.', 'draxira'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        $output = ob_get_clean();

        wp_send_json_success($output);
    }

    /**
     * AJAX handler for getting dummy terms list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_dummy_terms()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_send_json_error(__('Security check failed.', 'draxira'));
            wp_die();
        }

        $dummy_terms = get_terms([
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
            'hide_empty' => false,
            'number' => 100,
        ]);

        if (is_wp_error($dummy_terms) || empty($dummy_terms)) {
            wp_send_json_error(__('No dummy taxonomy terms found.', 'draxira'));
        }

        ob_start();
        ?>
        <p><?php printf(esc_html__('Found %d dummy taxonomy terms.', 'draxira'), count($dummy_terms)); ?></p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'draxira'); ?></th>
                    <th><?php esc_html_e('Name', 'draxira'); ?></th>
                    <th><?php esc_html_e('Taxonomy', 'draxira'); ?></th>
                    <th><?php esc_html_e('Slug', 'draxira'); ?></th>
                    <th><?php esc_html_e('Description', 'draxira'); ?></th>
                    <th><?php esc_html_e('Count', 'draxira'); ?></th>
                    <th><?php esc_html_e('Actions', 'draxira'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dummy_terms as $term):
                    $taxonomy_obj = get_taxonomy($term->taxonomy);
                    $taxonomy_label = $taxonomy_obj ? $taxonomy_obj->label : $term->taxonomy;
                    ?>
                    <tr>
                        <td><?php echo esc_html($term->term_id); ?></td>
                        <td><strong><?php echo esc_html($term->name); ?></strong></td>
                        <td><?php echo esc_html($taxonomy_label); ?> (<?php echo esc_html($term->taxonomy); ?>)</td>
                        <td><?php echo esc_html($term->slug); ?></td>
                        <td><?php echo esc_html(wp_trim_words($term->description, 10, '...')); ?></td>
                        <td><?php echo intval($term->count); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('term.php?taxonomy=' . $term->taxonomy . '&tag_ID=' . $term->term_id)); ?>"
                                class="button button-small"><?php esc_html_e('Edit', 'draxira'); ?></a>
                            <a href="<?php echo esc_url(get_term_link($term)); ?>" class="button button-small"
                                target="_blank"><?php esc_html_e('View', 'draxira'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $output = ob_get_clean();
        wp_send_json_success($output);
    }

    //     /**
//  * Assign a taxonomy term to random posts
//  *
//  * @since 1.0.0
//  * @access private
//  * @param int    $term_id      Term ID
//  * @param string $taxonomy     Taxonomy slug
//  * @param string $post_type    Post type slug
//  * @return int Number of posts the term was assigned to
//  */
// private function mc_drax_assign_term_to_random_posts($term_id, $taxonomy, $post_type)
// {
//     // Get random posts of the selected post type
//     $args = [
//         'post_type' => $post_type,
//         'posts_per_page' => wp_rand(1, 5), // Assign to 1-5 random posts
//         'orderby' => 'rand',
//         'post_status' => 'publish',
//         'fields' => 'ids',
//     ];

    //     $random_posts = get_posts($args);

    //     if (empty($random_posts)) {
//         return 0;
//     }

    //     $assigned_count = 0;

    //     foreach ($random_posts as $post_id) {
//         // Get existing terms for this taxonomy
//         $existing_terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);

    //         // Add the new term if not already assigned
//         if (!in_array($term_id, $existing_terms)) {
//             $existing_terms[] = $term_id;
//             $result = wp_set_post_terms($post_id, $existing_terms, $taxonomy);

    //             if (!is_wp_error($result)) {
//                 $assigned_count++;
//             }
//         }
//     }

    //     return $assigned_count;
// }


    /**
     * Assign a taxonomy term to random posts
     *
     * @since 1.0.0
     * @access private
     * @param int    $term_id            Term ID
     * @param string $taxonomy           Taxonomy slug
     * @param string $post_type          Post type slug
     * @param int    $posts_per_term     Number of posts to assign this term to
     * @return int Number of successful assignments
     */
    private function mc_drax_assign_term_to_random_posts($term_id, $taxonomy, $post_type, $posts_per_term = 3)
    {
        // Get random posts of the selected post type
        $args = [
            'post_type' => $post_type,
            'posts_per_page' => $posts_per_term,
            'orderby' => 'rand',
            'post_status' => 'publish',
            'fields' => 'ids',
        ];

        $random_posts = get_posts($args);

        if (empty($random_posts)) {
            return 0;
        }

        $assigned_count = 0;

        foreach ($random_posts as $post_id) {
            // Get existing terms for this taxonomy
            $existing_terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);

            // Add the new term if not already assigned
            if (!in_array($term_id, $existing_terms)) {
                $existing_terms[] = $term_id;
                $result = wp_set_post_terms($post_id, $existing_terms, $taxonomy);

                if (!is_wp_error($result)) {
                    $assigned_count++;
                }
            }
        }

        return $assigned_count;
    }

    /**
     * Render taxonomies page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_render_taxonomies_page()
    {
        $post_types = $this->mc_drax_get_post_types_with_taxonomies();
        ?>
        <div class="wrap draxira">
            <h1><?php esc_html_e('Draxira – Dummy Content Generator - Taxonomies', 'draxira'); ?></h1>

            <?php
            $results = get_transient('draxira_taxonomies_results');
            if ($results) {
                delete_transient('draxira_taxonomies_results');
                $notice_class = isset($results['type']) && $results['type'] === 'error' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($results['message']) . '</p></div>';
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-terms-tab"
                    class="nav-tab nav-tab-active"><?php esc_html_e('Generate Dummy Taxonomies', 'draxira'); ?></a>
                <a href="#delete-terms-tab" class="nav-tab"><?php esc_html_e('Delete Dummy Taxonomies', 'draxira'); ?></a>
            </h2>

            <!-- Generate Terms Tab -->
            <div id="generate-terms-tab" class="tab-content active">
                <form method="post" action="" id="generate-terms-form">
                    <?php wp_nonce_field('generate_dummy_terms'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Select Post Type', 'draxira'); ?></th>
                            <td>
                                <select name="post_type" id="taxonomy-post-type" style="min-width: 200px;">
                                    <option value=""><?php esc_html_e('-- Select Post Type --', 'draxira'); ?></option>
                                    <?php foreach ($post_types as $post_type_slug => $post_type_label): ?>
                                        <option value="<?php echo esc_attr($post_type_slug); ?>">
                                            <?php echo esc_html($post_type_label); ?> (<?php echo esc_html($post_type_slug); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Select a post type to see its associated taxonomies.', 'draxira'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Select Taxonomies', 'draxira'); ?></th>
                            <td>
                                <div id="taxonomies-list-wrapper">
                                    <p class="description">
                                        <?php esc_html_e('Select a post type above to load taxonomies.', 'draxira'); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Auto-assign to Posts', 'draxira'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_assign_terms" value="1">
                                    <?php esc_html_e('Automatically assign generated taxonomy terms to random posts', 'draxira'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, each generated term will be randomly assigned to 1-5 existing posts of the selected post type.', 'draxira'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Terms Per Taxonomy', 'draxira'); ?></th>
                            <td>
                                <input type="number" name="terms_per_taxonomy" min="1" max="50" value="10"
                                    style="width: 100px;">
                                <p class="description"><?php esc_html_e('Maximum 50 terms per taxonomy.', 'draxira'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div id="term-meta-configuration">
                        <!-- Loaded via AJAX when taxonomy is selected -->
                    </div>

                    <p class="submit">
                        <input type="submit" name="generate_terms" class="button button-primary"
                            value="<?php esc_attr_e('Generate Taxonomy Terms', 'draxira'); ?>">
                    </p>
                </form>
            </div>

            <!-- Delete Dummy Terms Tab -->
            <div id="delete-terms-tab" class="tab-content">
                <h3><?php esc_html_e('Dummy Taxonomy Terms Created by Plugin', 'draxira'); ?></h3>

                <div class="filter-section">
                    <button type="button" id="load-dummy-terms" class="button">
                        <?php esc_html_e('Load Dummy Taxonomy Terms', 'draxira'); ?>
                    </button>
                </div>

                <div id="dummy-terms-list">
                    <p><?php esc_html_e('Click "Load Dummy Taxonomy Terms" to see dummy terms.', 'draxira'); ?></p>
                </div>

                <div id="delete-section"
                    style="display:none; margin-top: 30px; padding: 20px; background: #fff5f5; border: 1px solid #ffb3b3; border-radius: 6px;">
                    <h4 style="color:#d63638; margin-top:0;"><?php esc_html_e('Delete Dummy Taxonomy Terms', 'draxira'); ?></h4>
                    <p class="description"><strong><?php esc_html_e('Warning:', 'draxira'); ?></strong>
                        <?php esc_html_e('This will permanently delete ALL dummy taxonomy terms and their meta data. This cannot be undone.', 'draxira'); ?>
                    </p>

                    <form method="post" action="">
                        <?php wp_nonce_field('clear_dummy_terms', '_wpnonce'); ?>
                        <input type="hidden" name="clear_dummy_terms" value="1">

                        <p style="margin-top:20px;">
                            <input type="submit" class="button button-large button-link-delete"
                                value="<?php esc_attr_e('Delete All Dummy Taxonomy Terms', 'draxira'); ?>"
                                onclick="return confirm('<?php echo esc_js(__('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy taxonomy terms.\nNo backup. No trash. Really sure?', 'draxira')); ?>');">
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}