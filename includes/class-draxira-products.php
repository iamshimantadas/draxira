<?php
/**
 * WooCommerce Products Dummy Content Generator
 * 
 * Handles generation of dummy WooCommerce products with variations,
 * categories, attributes, and product meta data.
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
 * Class Draxira_Products
 * 
 * Manages all WooCommerce product dummy content generation functionality.
 *
 * @since 1.0.0
 * @access public
 */
class Draxira_Products
{
    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @access private
     * @var Draxira_Products|null
     */
    private static $instance = null;

    /**
     * Product data loaded from CSV file
     *
     * @since 1.0.0
     * @access private
     * @var array
     */
    private $mc_drax_product_data = [];

    /**
     * Variation data loaded from CSV file
     *
     * @since 1.0.0
     * @access private
     * @var array
     */
    private $mc_drax_variation_data = [];

    /**
     * Path to the CSV data file
     *
     * @since 1.0.0
     * @access private
     * @var string
     */
    private $mc_drax_csv_file_path;

    /**
     * Available product statuses
     *
     * @since 1.0.0
     * @access private
     * @var array
     */
    private $mc_drax_product_statuses = [
        'publish' => 'Published',
        'draft' => 'Draft',
        'pending' => 'Pending Review',
    ];

    /**
     * Faker types for custom meta
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
     * @return Draxira_Products Singleton instance
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
        $this->mc_drax_csv_file_path = DRAXIRA_PLUGIN_DIR . 'woo-data/woo.csv';
        $this->mc_drax_init_hooks();
        $this->mc_drax_load_product_data();
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
        // products scripts enqueue
        add_action('admin_enqueue_scripts', [$this, 'mc_draxira_enqueue_product_scripts']);

        // Handle product generation
        add_action('admin_init', [$this, 'mc_drax_handle_product_actions']);

        // AJAX handlers
        add_action('wp_ajax_draxira_get_product_meta', [$this, 'mc_drax_ajax_get_product_meta']);
        add_action('wp_ajax_draxira_get_dummy_products', [$this, 'mc_drax_ajax_get_dummy_products']);
    }

    /**
     * Enqueue product-specific scripts and styles
     * 
     * @since 1.0.0
     * @param string $hook Current admin page hook
     */
    public function mc_draxira_enqueue_product_scripts($hook)
    {
        if ($hook === 'draxira_page_draxira-products') {
            // Register and enqueue product-specific JavaScript
            wp_enqueue_script(
                'draxira-products',
                DRAXIRA_PLUGIN_URL . 'assets/js/products.js',
                ['jquery', 'draxira-admin'],
                DRAXIRA_VERSION,
                true
            );

            // Localize script for AJAX
            wp_localize_script('draxira-products', 'draxira_products_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('draxira_ajax_nonce'),
                'loading_products' => __('Loading dummy products...', 'draxira'),
                'error_loading_products' => __('Error loading products.', 'draxira'),
            ]);
        }
    }

    /**
     * Load product data from CSV file
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_load_product_data()
    {
        if (!file_exists($this->mc_drax_csv_file_path)) {
            $this->mc_drax_product_data = [];
            $this->mc_drax_variation_data = [];
            return;
        }

        global $wp_filesystem;

        // Initialize WP_Filesystem if not already done
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (!$wp_filesystem || !$wp_filesystem->exists($this->mc_drax_csv_file_path)) {
            $this->mc_drax_product_data = [];
            $this->mc_drax_variation_data = [];
            return;
        }

        $content = $wp_filesystem->get_contents($this->mc_drax_csv_file_path);
        if (empty($content)) {
            $this->mc_drax_product_data = [];
            $this->mc_drax_variation_data = [];
            return;
        }

        $lines = explode("\n", $content);
        if (empty($lines)) {
            $this->mc_drax_product_data = [];
            $this->mc_drax_variation_data = [];
            return;
        }

        $headers = str_getcsv(array_shift($lines));
        $data = [];
        $variations = [];

        foreach ($lines as $line) {
            if (empty(trim($line)))
                continue;

            $row = str_getcsv($line);
            if (count($row) !== count($headers))
                continue;

            $row_data = [];
            foreach ($headers as $index => $header) {
                if (isset($row[$index])) {
                    $row_data[$header] = $row[$index];
                }
            }

            // Separate variations from parent products
            if (isset($row_data['Type'])) {
                if ($row_data['Type'] === 'variation') {
                    $variations[] = $row_data;
                } elseif (in_array($row_data['Type'], ['variable', 'simple'])) {
                    $data[] = $row_data;
                }
            }
        }

        $this->mc_drax_product_data = $data;

        // Group variations by parent SKU
        $this->mc_drax_variation_data = [];
        foreach ($variations as $variation) {
            $parent_sku = !empty($variation['Parent']) ? $variation['Parent'] : '';
            if (!empty($parent_sku)) {
                if (!isset($this->mc_drax_variation_data[$parent_sku])) {
                    $this->mc_drax_variation_data[$parent_sku] = [];
                }
                $this->mc_drax_variation_data[$parent_sku][] = $variation;
            }
        }
    }

    /**
     * Get available product data from CSV
     *
     * @since 1.0.0
     * @access public
     * @return array Product data array
     */
    public function mc_drax_get_available_product_data()
    {
        return $this->mc_drax_product_data;
    }

    /**
     * Get variations for a product by SKU
     *
     * @since 1.0.0
     * @access private
     * @param string $sku Product SKU
     * @return array Variations data
     */
    private function mc_drax_get_variations_by_sku($sku)
    {
        return isset($this->mc_drax_variation_data[$sku]) ? $this->mc_drax_variation_data[$sku] : [];
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
     * Get Faker instance
     *
     * @since 1.0.0
     * @access private
     * @return Faker\Generator|false Faker instance or false if not available
     */
    private function mc_drax_get_faker()
    {
        if (class_exists('Faker\Factory')) {
            return Faker\Factory::create();
        }
        return false;
    }

    /**
     * Handle product form submissions
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_handle_product_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['generate_products']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'generate_dummy_products')) {
            $this->mc_drax_generate_dummy_products();
        }

        $clear_products_nonce_action = 'clear_dummy_products';
        if (isset($_REQUEST['clear_dummy_products']) && isset($_REQUEST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), $clear_products_nonce_action)) {
            $deleted_count = $this->mc_drax_clear_dummy_products();

            set_transient('draxira_product_results', [
                'message' => sprintf(
                    /* translators: %1$d: number of deleted products, %2$s: singular/plural of product */
                    __('Successfully deleted %1$d %2$s (and associated data).', 'draxira'),
                    $deleted_count,
                    _n('dummy product', 'dummy products', $deleted_count, 'draxira')
                ),
                'type' => 'success'
            ], 45);

            wp_safe_redirect(admin_url('admin.php?page=draxira-products'));
            exit;
        }
    }

    /**
     * Get WooCommerce product meta fields (for custom meta only)
     *
     * @since 1.0.0
     * @access private
     * @return array Array of product meta keys with labels
     */
    private function mc_drax_get_product_meta_keys()
    {
        global $wpdb;

        $meta_keys = [];

        // Get custom product meta from database (excluding WooCommerce core fields)
        $excluded_keys = [
            '_edit_lock',
            '_edit_last',
            '_thumbnail_id',
            '_product_attributes',
            '_default_attributes',
            '_variation_description',
            '_menu_order',
            '_downloadable_files',
            '_children',
            '_files',
            '_price',
            '_regular_price',
            '_sale_price',
            '_sku',
            '_stock_status',
            '_manage_stock',
            '_stock',
            '_weight',
            '_length',
            '_width',
            '_height',
            '_virtual',
            '_downloadable',
            '_tax_status',
            '_tax_class',
            '_purchase_note',
            '_featured',
            '_visibility',
            '_backorders',
            '_sold_individually',
            '_product_image_gallery',
            '_draxira_dummy_content'
        ];

        $placeholders = array_fill(0, count($excluded_keys), '%s');
        $excluded_condition = implode(', ', $placeholders);

        $custom_meta_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_key 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN (
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'product'
            )
            AND meta_key NOT LIKE %s
            AND meta_key NOT LIKE %s
            AND meta_key NOT IN ($excluded_condition)
            ORDER BY meta_key
            LIMIT 50",
            array_merge(['\_edit%', '\_wp%'], $excluded_keys)
        ));

        // Format custom meta keys
        foreach ($custom_meta_keys as $key) {
            $label = ucwords(str_replace(['_', '-'], ' ', $key));
            $meta_keys[$key] = $label;
        }

        // Get ACF fields for products
        if (function_exists('acf_get_field_groups')) {
            $field_groups = acf_get_field_groups(['post_type' => 'product']);
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

        // Get CMB2 fields for products
        if (class_exists('CMB2')) {
            $cmb2_boxes = CMB2_Boxes::get_all();
            foreach ($cmb2_boxes as $cmb_id => $cmb) {
                $object_types = $cmb->prop('object_types');
                if ($object_types && in_array('product', (array) $object_types)) {
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

        // Remove duplicates and sort
        $meta_keys = array_unique($meta_keys);
        asort($meta_keys);

        return $meta_keys;
    }

    /**
     * Get WooCommerce product taxonomies
     *
     * @since 1.0.0
     * @access private
     * @return array Array of product taxonomies with labels
     */
    private function mc_drax_get_product_taxonomies()
    {
        $taxonomies = get_object_taxonomies('product', 'objects');
        $available_taxonomies = [];

        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->public && $taxonomy->show_ui) {
                $available_taxonomies[$taxonomy->name] = $taxonomy->label;
            }
        }

        return $available_taxonomies;
    }

    /**
     * Generate dummy products based on form submission
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_generate_dummy_products()
    {
        if (!class_exists('WooCommerce')) {
            set_transient('draxira_product_results', [
                'message' => __('WooCommerce is not installed or activated.', 'draxira'),
                'type' => 'error'
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=draxira-products'));
            exit;
        }

        $count = min(intval($_POST['product_count'] ?? 5), 200);
        $product_status = sanitize_text_field($_POST['product_status'] ?? 'publish');
        $with_featured_image = isset($_POST['with_featured_image']);
        $with_gallery = isset($_POST['with_gallery']);
        $create_excerpt = isset($_POST['create_excerpt']);
        $product_author = intval($_POST['product_author'] ?? get_current_user_id());

        // Get custom meta configurations (only for custom fields)
        $custom_meta_config = [];
        if (isset($_POST['product_meta']) && is_array($_POST['product_meta'])) {
            foreach ($_POST['product_meta'] as $meta_key => $config) {
                if (!empty($config['type'])) {
                    $custom_meta_config[$meta_key] = [
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
                        'assign' => isset($config['assign']) ? intval($config['assign']) : ($taxonomy === 'product_brand' ? 1 : 2)
                    ];
                }
            }
        }

        $results = ['success' => 0, 'failed' => 0, 'taxonomies_created' => 0];

        // Create taxonomies if requested
        $created_terms = [];
        if (!empty($taxonomy_config)) {
            foreach ($taxonomy_config as $taxonomy => $config) {
                $terms = $this->mc_drax_create_dummy_terms($taxonomy, 10);
                $created_terms[$taxonomy] = $terms;
                $results['taxonomies_created'] += count($terms);
            }
        }

        // Generate products
        $available_data = $this->mc_drax_get_available_product_data();
        $data_count = count($available_data);

        if ($data_count === 0) {
            set_transient('draxira_product_results', [
                'message' => __('No product data found in CSV file. Please check the woo-data/woo.csv file.', 'draxira'),
                'type' => 'error'
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=draxira-products'));
            exit;
        }

        for ($i = 0; $i < $count; $i++) {
            $data_index = $i % $data_count;
            $product_data = $available_data[$data_index];

            $product_id = $this->mc_drax_create_dummy_product(
                $product_status,
                $with_featured_image,
                $with_gallery,
                $create_excerpt,
                $product_author,
                $custom_meta_config,
                $created_terms,
                $taxonomy_config,
                $product_data
            );

            if ($product_id && !is_wp_error($product_id)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        set_transient('draxira_product_results', [
            'message' => sprintf(
                /* translators: %1$d: number of generated products, %2$s: singular/plural of product, %3$d: number of taxonomy terms created, %4$d: number of failures */
                __('Successfully generated %1$d %2$s with %3$d taxonomy terms. Failed: %4$d', 'draxira'),
                $results['success'],
                _n('product', 'products', $results['success'], 'draxira'),
                $results['taxonomies_created'],
                $results['failed']
            ),
            'type' => 'success'
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=draxira-products'));
        exit;
    }

    /**
     * Create a single dummy product
     *
     * @since 1.0.0
     * @access private
     * @param string $status               Product status
     * @param bool   $with_featured_image  Whether to add featured image
     * @param bool   $with_gallery         Whether to add gallery images
     * @param bool   $create_excerpt       Whether to create excerpt
     * @param int    $author               Author ID
     * @param array  $custom_meta_config   Custom meta field configurations
     * @param array  $created_terms        Created taxonomy terms
     * @param array  $taxonomy_config      Taxonomy configurations
     * @param array  $product_data         Product data from CSV
     * @return int|false Product ID on success, false on failure
     */
    private function mc_drax_create_dummy_product(
        $status = 'publish',
        $with_featured_image = false,
        $with_gallery = false,
        $create_excerpt = false,
        $author = 0,
        $custom_meta_config = [],
        $created_terms = [],
        $taxonomy_config = [],
        $product_data = []
    ) {
        // Use current user if no author specified
        if (!$author || !get_user_by('id', $author)) {
            $author = get_current_user_id();
        }

        // Get product name from CSV
        $product_name = !empty($product_data['Name']) ? $product_data['Name'] : 'Dummy Product';

        // Get product description from CSV
        $product_description = !empty($product_data['description']) ? $product_data['description'] : '';

        // Get short description from CSV
        $short_description = !empty($product_data['Short description']) ? $product_data['Short description'] : '';

        // Create product
        $product_args = [
            'post_title' => wp_trim_words($product_name, 10, '...'),
            'post_content' => $product_description,
            'post_status' => $status,
            'post_type' => 'product',
            'post_author' => $author,
        ];

        // Add excerpt if requested
        if ($create_excerpt && !empty($short_description)) {
            $product_args['post_excerpt'] = $short_description;
        }

        $product_id = wp_insert_post($product_args);

        if ($product_id && !is_wp_error($product_id)) {
            // Set product type from CSV
            $product_type = !empty($product_data['Type']) ? $product_data['Type'] : 'simple';
            wp_set_object_terms($product_id, $product_type, 'product_type');

            // Add our meta key to identify dummy products
            update_post_meta($product_id, DRAXIRA_META_KEY, '1');

            // Set basic WooCommerce meta from CSV (no faker)
            $this->mc_drax_set_basic_product_meta($product_id, $product_data);

            // Handle categories from CSV
            if (!empty($product_data['Categories'])) {
                $this->mc_drax_handle_categories($product_id, $product_data['Categories']);
            }

            // Handle tags from CSV
            if (!empty($product_data['Tags'])) {
                $tags = explode('|', $product_data['Tags']);
                wp_set_post_terms($product_id, $tags, 'product_tag');
            }

            // Add featured image from CSV if requested
            if ($with_featured_image && !empty($product_data['Images'])) {
                $this->mc_drax_attach_featured_image($product_id, $product_data['Images']);
            }

            // Add product gallery from plugin assets if requested
            if ($with_gallery) {
                $this->mc_drax_attach_product_gallery($product_id);
            }

            // Handle attributes for variable products
            if ($product_type === 'variable') {
                $this->mc_drax_create_product_attributes($product_id, $product_data);

                // Create variations if they exist in CSV
                $variations = $this->mc_drax_get_variations_by_sku($product_data['SKU']);
                if (!empty($variations)) {
                    $this->mc_drax_create_product_variations($product_id, $variations, $status, $author);
                }
            }

            // Assign taxonomies from created terms (faker terms)
            if (!empty($created_terms)) {
                foreach ($created_terms as $taxonomy => $terms) {
                    if (!empty($terms) && isset($taxonomy_config[$taxonomy]['assign'])) {
                        $assign_count = $taxonomy_config[$taxonomy]['assign'];
                        if ($taxonomy === 'product_brand') {
                            $assign_count = 1;
                        }
                        $assign_count = min($assign_count, count($terms));
                        $shuffled_terms = $terms;
                        shuffle($shuffled_terms);
                        $selected_terms = array_slice($shuffled_terms, 0, $assign_count);
                        if (!empty($selected_terms)) {
                            wp_set_post_terms($product_id, $selected_terms, $taxonomy);
                        }
                    }
                }
            }

            // Add custom meta fields (only these use faker)
            foreach ($custom_meta_config as $meta_key => $config) {
                if (!empty($config['type'])) {
                    $meta_value = $this->mc_drax_get_custom_meta_value($config['type']);
                    if ($meta_value !== '' && $meta_value !== null) {
                        update_post_meta($product_id, $meta_key, $meta_value);
                    }
                }
            }

            return $product_id;
        }

        return false;
    }

    /**
     * Handle hierarchical categories from CSV
     *
     * @since 1.0.0
     * @access private
     * @param int    $product_id Product ID
     * @param string $categories Categories string
     * @return void
     */
    private function mc_drax_handle_categories($product_id, $categories)
    {
        $category_parts = explode('|', $categories);
        $term_ids = [];

        foreach ($category_parts as $category) {
            $hierarchy = explode('>', $category);
            $parent_id = 0;

            foreach ($hierarchy as $cat_name) {
                $cat_name = trim($cat_name);
                if (empty($cat_name))
                    continue;

                $term = term_exists($cat_name, 'product_cat', $parent_id);
                if (!$term) {
                    $term = wp_insert_term($cat_name, 'product_cat', ['parent' => $parent_id]);
                }

                if (!is_wp_error($term)) {
                    $parent_id = $term['term_id'];
                    if (!in_array($parent_id, $term_ids)) {
                        $term_ids[] = $parent_id;
                    }
                }
            }
        }

        if (!empty($term_ids)) {
            wp_set_post_terms($product_id, $term_ids, 'product_cat');
        }
    }

    /**
     * Create product attributes for variable products
     *
     * @since 1.0.0
     * @access private
     * @param int   $product_id   Product ID
     * @param array $product_data Product data from CSV
     * @return void
     */
    private function mc_drax_create_product_attributes($product_id, $product_data)
    {
        $attributes = [];
        $attribute_names = ['Attribute 1 name', 'Attribute 2 name', 'Attribute 3 name', 'Attribute 4 name', 'Attribute 5 name'];
        $attribute_values = ['Attribute 1 value(s)', 'Attribute 2 value(s)', 'Attribute 3 value(s)', 'Attribute 4 value(s)', 'Attribute 5 value(s)'];

        for ($i = 0; $i < count($attribute_names); $i++) {
            $attr_name = $attribute_names[$i];
            $attr_values = $attribute_values[$i];

            if (!empty($product_data[$attr_name]) && !empty($product_data[$attr_values])) {
                $name = sanitize_title($product_data[$attr_name]);
                $values = explode('|', $product_data[$attr_values]);

                $attributes[$name] = [
                    'name' => $product_data[$attr_name],
                    'value' => implode(' | ', $values),
                    'position' => $i,
                    'is_visible' => 1,
                    'is_variation' => 1,
                    'is_taxonomy' => 0
                ];
            }
        }

        if (!empty($attributes)) {
            update_post_meta($product_id, '_product_attributes', $attributes);
        }
    }

    /**
     * Create product variations from CSV data
     *
     * @since 1.0.0
     * @access private
     * @param int    $product_id Parent product ID
     * @param array  $variations Variations data
     * @param string $status     Product status
     * @param int    $author     Author ID
     * @return void
     */
    private function mc_drax_create_product_variations($product_id, $variations, $status, $author)
    {
        foreach ($variations as $variation_data) {
            // Create variation post
            $variation_args = [
                'post_title' => !empty($variation_data['Name']) ? $variation_data['Name'] : 'Variation',
                'post_name' => 'product-' . $product_id . '-variation',
                'post_status' => $status,
                'post_parent' => $product_id,
                'post_type' => 'product_variation',
                'post_author' => $author,
            ];

            $variation_id = wp_insert_post($variation_args);

            if ($variation_id && !is_wp_error($variation_id)) {
                // Set SKU
                if (!empty($variation_data['SKU'])) {
                    update_post_meta($variation_id, '_sku', $variation_data['SKU']);
                }

                // Set price
                if (!empty($variation_data['Regular price'])) {
                    $price = floatval($variation_data['Regular price']);
                    update_post_meta($variation_id, '_price', $price);
                    update_post_meta($variation_id, '_regular_price', $price);

                    if (!empty($variation_data['Sale price'])) {
                        $sale_price = floatval($variation_data['Sale price']);
                        update_post_meta($variation_id, '_sale_price', $sale_price);
                        update_post_meta($variation_id, '_price', $sale_price);
                    }
                }

                // Set stock
                if (isset($variation_data['In stock?']) && $variation_data['In stock?'] == 1) {
                    update_post_meta($variation_id, '_stock_status', 'instock');
                    if (!empty($variation_data['Stock'])) {
                        update_post_meta($variation_id, '_stock', intval($variation_data['Stock']));
                        update_post_meta($variation_id, '_manage_stock', 'yes');
                    }
                } else {
                    update_post_meta($variation_id, '_stock_status', 'outofstock');
                }

                // Set weight and dimensions
                if (!empty($variation_data['Weight (lbs)'])) {
                    update_post_meta($variation_id, '_weight', floatval($variation_data['Weight (lbs)']));
                }
                if (!empty($variation_data['Length (in)'])) {
                    update_post_meta($variation_id, '_length', floatval($variation_data['Length (in)']));
                }
                if (!empty($variation_data['Width (in)'])) {
                    update_post_meta($variation_id, '_width', floatval($variation_data['Width (in)']));
                }
                if (!empty($variation_data['Height (in)'])) {
                    update_post_meta($variation_id, '_height', floatval($variation_data['Height (in)']));
                }

                // Set variation description
                if (!empty($variation_data['description'])) {
                    update_post_meta($variation_id, '_variation_description', $variation_data['description']);
                }

                // Set attributes
                $attributes = [];
                $attribute_names = ['Attribute 1 name', 'Attribute 2 name', 'Attribute 3 name', 'Attribute 4 name', 'Attribute 5 name'];
                $attribute_values = ['Attribute 1 value(s)', 'Attribute 2 value(s)', 'Attribute 3 value(s)', 'Attribute 4 value(s)', 'Attribute 5 value(s)'];

                for ($i = 0; $i < count($attribute_names); $i++) {
                    $attr_name = $attribute_names[$i];
                    $attr_value = $attribute_values[$i];

                    if (!empty($variation_data[$attr_name]) && !empty($variation_data[$attr_value])) {
                        $attr_slug = sanitize_title($variation_data[$attr_name]);
                        $attributes['attribute_' . $attr_slug] = $variation_data[$attr_value];
                    }
                }

                if (!empty($attributes)) {
                    foreach ($attributes as $key => $value) {
                        update_post_meta($variation_id, $key, $value);
                    }
                }

                // Set featured image for variation
                if (!empty($variation_data['Images'])) {
                    $images = explode(',', $variation_data['Images']);
                    $first_image = trim($images[0]);
                    if (!empty($first_image) && filter_var($first_image, FILTER_VALIDATE_URL)) {
                        $attachment_id = $this->mc_drax_upload_image_from_url($first_image, $variation_id . '-variation');
                        if ($attachment_id) {
                            set_post_thumbnail($variation_id, $attachment_id);
                        }
                    }
                }

                // Mark as dummy
                update_post_meta($variation_id, DRAXIRA_META_KEY, '1');
            }
        }
    }

    /**
     * Set basic WooCommerce product meta from CSV data (NO FAKER)
     *
     * @since 1.0.0
     * @access private
     * @param int   $product_id   Product ID
     * @param array $product_data Product data from CSV
     * @return void
     */
    private function mc_drax_set_basic_product_meta($product_id, $product_data)
    {
        // Set price from CSV
        if (!empty($product_data['Regular price'])) {
            $price = floatval($product_data['Regular price']);
            update_post_meta($product_id, '_price', $price);
            update_post_meta($product_id, '_regular_price', $price);

            if (!empty($product_data['Sale price'])) {
                $sale_price = floatval($product_data['Sale price']);
                update_post_meta($product_id, '_sale_price', $sale_price);
                update_post_meta($product_id, '_price', $sale_price);
            }
        }

        // Set SKU from CSV
        if (!empty($product_data['SKU'])) {
            update_post_meta($product_id, '_sku', sanitize_title($product_data['SKU']));
        }

        // Set stock status from CSV
        $in_stock = isset($product_data['In stock?']) && $product_data['In stock?'] == 1;
        $stock_status = $in_stock ? 'instock' : 'outofstock';
        update_post_meta($product_id, '_stock_status', $stock_status);

        if (!empty($product_data['Stock']) && $in_stock) {
            update_post_meta($product_id, '_stock', intval($product_data['Stock']));
            update_post_meta($product_id, '_manage_stock', 'yes');
        }

        // Set weight and dimensions from CSV
        if (!empty($product_data['Weight (lbs)'])) {
            update_post_meta($product_id, '_weight', floatval($product_data['Weight (lbs)']));
        }
        if (!empty($product_data['Length (in)'])) {
            update_post_meta($product_id, '_length', floatval($product_data['Length (in)']));
        }
        if (!empty($product_data['Width (in)'])) {
            update_post_meta($product_id, '_width', floatval($product_data['Width (in)']));
        }
        if (!empty($product_data['Height (in)'])) {
            update_post_meta($product_id, '_height', floatval($product_data['Height (in)']));
        }

        // Set featured status from CSV
        $featured = isset($product_data['Is featured?']) && $product_data['Is featured?'] == 1 ? 'yes' : 'no';
        update_post_meta($product_id, '_featured', $featured);

        // Set visibility from CSV
        $visibility = !empty($product_data['Visibility in catalog']) ? $product_data['Visibility in catalog'] : 'visible';
        update_post_meta($product_id, '_visibility', $visibility);

        // Set tax status from CSV
        $tax_status = !empty($product_data['Tax status']) ? $product_data['Tax status'] : 'taxable';
        update_post_meta($product_id, '_tax_status', $tax_status);

        // Set tax class from CSV
        if (!empty($product_data['Tax class'])) {
            update_post_meta($product_id, '_tax_class', $product_data['Tax class']);
        }

        // Set backorders from CSV
        $backorders = isset($product_data['Backorders allowed?']) && $product_data['Backorders allowed?'] == 1 ? 'yes' : 'no';
        update_post_meta($product_id, '_backorders', $backorders);

        // Set sold individually from CSV
        $sold_individually = isset($product_data['Sold individually?']) && $product_data['Sold individually?'] == 1 ? 'yes' : 'no';
        update_post_meta($product_id, '_sold_individually', $sold_individually);

        // Set purchase note from CSV
        if (!empty($product_data['Purchase note'])) {
            update_post_meta($product_id, '_purchase_note', $product_data['Purchase note']);
        }

        // Set virtual and downloadable
        update_post_meta($product_id, '_virtual', 'no');
        update_post_meta($product_id, '_downloadable', 'no');
    }

    /**
     * Get custom meta value using faker (ONLY for custom fields)
     *
     * @since 1.0.0
     * @access private
     * @param string $type Faker data type
     * @return string|int|float Generated value
     */
    private function mc_drax_get_custom_meta_value($type)
    {
        $faker = $this->mc_drax_get_faker();
        if (!$faker || empty($type)) {
            return '';
        }

        $methods = [
            'text' => 'sentence',
            'paragraphs' => 'paragraphs',
            'words' => 'words',
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phoneNumber',
            'address' => 'address',
            'city' => 'city',
            'country' => 'country',
            'zipcode' => 'postcode',
            'number' => 'numberBetween',
            'price' => 'randomFloat',
            'date' => 'date',
            'boolean' => 'boolean',
            'url' => 'url',
            'image_url' => 'imageUrl',
            'color' => 'colorName',
            'hex_color' => 'hexColor',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'company' => 'company',
        ];

        if (isset($methods[$type])) {
            $method = $methods[$type];
            if ($method === 'numberBetween') {
                return $faker->$method(1, 100);
            } elseif ($method === 'randomFloat') {
                return $faker->$method(2, 10, 1000);
            } elseif (in_array($method, ['paragraphs', 'words'])) {
                return $faker->$method(3, true);
            } else {
                return $faker->$method();
            }
        }

        return '';
    }

    /**
     * Attach featured image to product from CSV images
     *
     * @since 1.0.0
     * @access private
     * @param int    $product_id Product ID
     * @param string $images     CSV images string
     * @return bool True on success, false on failure
     */
    private function mc_drax_attach_featured_image($product_id, $images)
    {
        if (empty($images)) {
            return false;
        }

        $images_list = explode(',', $images);
        $first_image = trim($images_list[0]);

        if (!empty($first_image) && filter_var($first_image, FILTER_VALIDATE_URL)) {
            $attachment_id = $this->mc_drax_upload_image_from_url($first_image, $product_id . '-featured');
            if ($attachment_id) {
                set_post_thumbnail($product_id, $attachment_id);
                return true;
            }
        }

        return false;
    }

    /**
     * Upload image from URL
     *
     * @since 1.0.0
     * @access private
     * @param string $image_url     Image URL
     * @param string $filename_base Base filename
     * @return int|false Attachment ID on success, false on failure
     */
    private function mc_drax_upload_image_from_url($image_url, $filename_base)
    {
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $response = wp_remote_get($image_url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            return false;
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return false;
        }

        $url_path = wp_parse_url($image_url, PHP_URL_PATH);
        $filename = basename($url_path);
        if (empty($filename)) {
            $filename = sanitize_title($filename_base) . '.jpg';
        }

        // Check if image already exists using WP_Query (replaces deprecated get_page_by_title)
        $existing_image = $this->mc_drax_get_attachment_by_title($filename);

        if ($existing_image) {
            return $existing_image;
        }

        $upload_file = wp_upload_bits($filename, null, $image_data);
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
                return $attachment_id;
            }
        }

        return false;
    }

    /**
     * Attach product gallery images from plugin assets
     *
     * @since 1.0.0
     * @access private
     * @param int $product_id Product ID
     * @return void
     */
    private function mc_drax_attach_product_gallery($product_id)
    {
        $image_dir = DRAXIRA_PLUGIN_DIR . 'assets/img/products/';

        if (!file_exists($image_dir)) {
            return;
        }

        $product_images = glob($image_dir . 'dummy_content_filler_product_img_*.{jpg,jpeg,png,gif}', GLOB_BRACE);

        if (empty($product_images)) {
            return;
        }

        $gallery_count = wp_rand(2, min(4, count($product_images)));
        shuffle($product_images);
        $selected_images = array_slice($product_images, 0, $gallery_count);

        $gallery_ids = [];

        foreach ($selected_images as $image_path) {
            $filename = basename($image_path);
            $existing_image = $this->mc_drax_get_attachment_by_title($filename);

            if ($existing_image) {
                $gallery_ids[] = $existing_image;
                continue;
            }

            $upload_file = wp_upload_bits($filename, null, file_get_contents($image_path));

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
                    $gallery_ids[] = $attachment_id;
                }
            }
        }

        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    /**
     * Create dummy taxonomy terms (for custom taxonomies only)
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
        $max_attempts = $count * 3;

        while ($created < $count && $attempts < $max_attempts) {
            $attempts++;

            if ($faker) {
                if ($taxonomy === 'product_cat') {
                    $term_name = ucwords($faker->words(2, true));
                } else if ($taxonomy === 'product_tag') {
                    $term_name = $faker->word();
                } else {
                    $term_name = ucwords($faker->words(wp_rand(1, 3), true));
                }
            } else {
                $term_name = 'Term ' . ($created + 1) . ' ' . wp_rand(100, 999);
            }

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
                add_term_meta($term['term_id'], DRAXIRA_META_KEY, '1');
            }
        }

        return $created_terms;
    }

    /**
     * Clear dummy products
     *
     * @since 1.0.0
     * @access private
     * @return int Number of deleted products
     */
    private function mc_drax_clear_dummy_products()
    {
        $args = [
            'post_type' => ['product', 'product_variation'],
            'posts_per_page' => -1,
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
            'post_status' => 'any',
        ];

        $dummy_products = get_posts($args);
        $deleted_count = 0;

        foreach ($dummy_products as $product_id) {
            $deleted = wp_delete_post($product_id, true);
            if ($deleted && !is_wp_error($deleted)) {
                $deleted_count++;
            }
        }

        $this->mc_drax_cleanup_dummy_product_terms();
        return $deleted_count;
    }

    /**
     * Cleanup dummy product taxonomy terms
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_cleanup_dummy_product_terms()
    {
        $product_taxonomies = get_object_taxonomies('product', 'names');

        if (empty($product_taxonomies)) {
            return;
        }

        foreach ($product_taxonomies as $taxonomy) {
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
     * AJAX handler for getting product meta fields
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_product_meta()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $meta_keys = $this->mc_drax_get_product_meta_keys();
        $taxonomies = $this->mc_drax_get_product_taxonomies();

        ob_start();
        ?>
        <div class="product-meta-section">
            <h3><?php esc_html_e('Product Content Options', 'draxira'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Product Author', 'draxira'); ?></th>
                    <td>
                        <select name="product_author" id="product-author-selector">
                            <option value="0"><?php esc_html_e('Select User', 'draxira'); ?></option>
                            <?php
                            $authors = get_users([
                                'role__in' => ['administrator', 'editor', 'author'],
                                'orderby' => 'display_name',
                                'order' => 'ASC',
                            ]);
                            foreach ($authors as $author) {
                                echo '<option value="' . esc_attr($author->ID) . '">' .
                                    esc_html($author->display_name . ' (' . $author->user_login . ')') .
                                    '</option>';
                            }
                            ?>
                        </select>
                        <span
                            class="description"><?php esc_html_e('Select who will be the author of generated products', 'draxira'); ?></span>
        </div>
        </div>
        </div>
        <tr>
            <th scope="row"><?php esc_html_e('Product Status', 'draxira'); ?></th>
            <td>
                <select name="product_status">
                    <?php foreach ($this->mc_drax_product_statuses as $status_value => $status_label): ?>
                        <option value="<?php echo esc_attr($status_value); ?>"><?php echo esc_html($status_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Number of Products', 'draxira'); ?></th>
            <td>
                <input type="number" name="product_count" min="1" max="200" value="5" style="width: 100px;">
                <p class="description"><?php esc_html_e('Maximum 200 products at a time', 'draxira'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Product Excerpt', 'draxira'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="create_excerpt" value="1">
                    <?php esc_html_e('Use short description from CSV', 'draxira'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Featured Image', 'draxira'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="with_featured_image" value="1" checked>
                    <?php esc_html_e('Use product image from CSV', 'draxira'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Product Gallery', 'draxira'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="with_gallery" value="1">
                    <?php esc_html_e('Add gallery images from plugin assets', 'draxira'); ?>
                </label>
            </td>
        </tr>
        </table>

        <?php if (!empty($taxonomies)): ?>
            <h3><?php esc_html_e('Product Taxonomies (Faker Generated)', 'draxira'); ?></h3>
            <p class="description">
                <?php esc_html_e('When "Create Terms" is enabled, dummy terms will be generated using Faker for custom taxonomies.', 'draxira'); ?>
            </p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Taxonomy', 'draxira'); ?></th>
                        <th><?php esc_html_e('Create Terms?', 'draxira'); ?></th>
                        <th><?php esc_html_e('Assign Terms per Product', 'draxira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($taxonomies as $taxonomy_slug => $taxonomy_label):
                        $assign_default = ($taxonomy_slug === 'product_brand') ? 1 : 2;
                        $max_assign = ($taxonomy_slug === 'product_brand') ? 1 : 10;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($taxonomy_label); ?></strong><br>
                                <small><?php echo esc_html($taxonomy_slug); ?></small>
                            </td>
                            <td>
                                <select name="taxonomies[<?php echo esc_attr($taxonomy_slug); ?>][create]">
                                    <option value="no"><?php esc_html_e('No', 'draxira'); ?></option>
                                    <option value="yes"><?php esc_html_e('Yes', 'draxira'); ?></option>
                                </select>
                            </td>
                            <td>
                                <input type="number" name="taxonomies[<?php echo esc_attr($taxonomy_slug); ?>][assign]" min="1"
                                    max="<?php echo esc_attr($max_assign); ?>" value="<?php echo esc_attr($assign_default); ?>"
                                    style="width: 80px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($meta_keys)): ?>
            <h3><?php esc_html_e('Custom Meta Fields (Faker Generated)', 'draxira'); ?></h3>
            <p class="description">
                <?php esc_html_e('Configure how custom fields (ACF, CMB2, custom meta) should be filled using Faker.', 'draxira'); ?>
            </p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Field Name', 'draxira'); ?></th>
                        <th><?php esc_html_e('Meta Key', 'draxira'); ?></th>
                        <th><?php esc_html_e('Data Type / Value', 'draxira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $faker_types = $this->mc_drax_faker_types;

                    foreach ($meta_keys as $meta_key => $field_label):
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($field_label); ?></strong>
                            </td>
                            <td>
                                <code><?php echo esc_html($meta_key); ?></code>
                                <input type="hidden" name="product_meta[<?php echo esc_attr($meta_key); ?>][key]"
                                    value="<?php echo esc_attr($meta_key); ?>">
                            </td>
                            <td>
                                <select name="product_meta[<?php echo esc_attr($meta_key); ?>][type]" class="product-meta-type">
                                    <?php foreach ($faker_types as $type_value => $type_label): ?>
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
            <h3><?php esc_html_e('Custom Meta Fields', 'draxira'); ?></h3>
            <p class="description"><?php esc_html_e('No custom fields found for products.', 'draxira'); ?></p>
        <?php endif; ?>

        </div>
        <?php
        $output = ob_get_clean();
        wp_send_json_success($output);
    }

    /**
     * AJAX handler for getting dummy products list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_dummy_products()
    {
        // Add nonce check
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_send_json_error(__('Security check failed.', 'draxira'));
            wp_die();
        }

        $args = [
            'post_type' => 'product',
            'posts_per_page' => 50,
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
        ];

        $dummy_products = get_posts($args);

        if (empty($dummy_products)) {
            wp_send_json_error(__('No dummy products found.', 'draxira'));
        }

        ob_start();
        ?>
        <p><?php printf(esc_html__('Found %d dummy products.', 'draxira'), count($dummy_products)); ?></p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'draxira'); ?></th>
                    <th><?php esc_html_e('Product', 'draxira'); ?></th>
                    <th><?php esc_html_e('Type', 'draxira'); ?></th>
                    <th><?php esc_html_e('Price', 'draxira'); ?></th>
                    <th><?php esc_html_e('SKU', 'draxira'); ?></th>
                    <th><?php esc_html_e('Stock', 'draxira'); ?></th>
                    <th><?php esc_html_e('Categories', 'draxira'); ?></th>
                    <th><?php esc_html_e('Date', 'draxira'); ?></th>
                    <th><?php esc_html_e('Actions', 'draxira'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dummy_products as $product):
                    $product_obj = wc_get_product($product->ID);
                    $price = $product_obj ? $product_obj->get_price_html() : 'N/A';
                    $sku = $product_obj ? $product_obj->get_sku() : 'N/A';
                    $stock = $product_obj ? $product_obj->get_stock_status() : 'N/A';
                    $type = $product_obj ? $product_obj->get_type() : 'N/A';

                    $categories = wp_get_post_terms($product->ID, 'product_cat', ['fields' => 'names']);
                    ?>
                    <tr>
                        <td><?php echo esc_html($product->ID); ?></td>
                        <td>
                            <strong><?php echo esc_html($product->post_title); ?></strong>
                        </td>
                        <td><?php echo esc_html($type); ?></td>
                        <td><?php echo wp_kses_post($price); ?></td>
                        <td><?php echo esc_html($sku); ?></td>
                        <td><?php echo esc_html($stock); ?></td>
                        <td>
                            <?php if (!empty($categories)): ?>
                                <?php echo esc_html(implode(', ', $categories)); ?>
                            <?php else: ?>
                                <em><?php esc_html_e('None', 'draxira'); ?></em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(get_the_date('', $product)); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($product->ID)); ?>"
                                class="button button-small"><?php esc_html_e('Edit', 'draxira'); ?></a>
                            <a href="<?php echo esc_url(get_permalink($product->ID)); ?>" class="button button-small"
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

    /**
     * Render products page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_render_products_page()
    {
        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap"><h1>' . esc_html__('WooCommerce Products', 'draxira') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is not installed or activated. Please install and activate WooCommerce to use this feature.', 'draxira') . '</p></div>';
            echo '</div>';
            return;
        }

        $available_data = $this->mc_drax_get_available_product_data();
        $data_count = count($available_data);
        ?>
        <div class="wrap draxira">
            <h1><?php esc_html_e('Draxira – Dummy Content Generator - WooCommerce Products', 'draxira'); ?></h1>

            <?php if ($data_count === 0): ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('Warning:', 'draxira'); ?></strong>
                        <?php printf(esc_html__('No product data found in %s. Please ensure the CSV file exists and contains valid product data.', 'draxira'), '<code>woo-data/woo.csv</code>'); ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="notice notice-info">
                    <p><strong><?php echo esc_html($data_count); ?></strong>
                        <?php esc_html_e('products loaded from CSV file. Products will be generated using this data.', 'draxira'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $results = get_transient('draxira_product_results');
            if ($results) {
                delete_transient('draxira_product_results');
                $notice_class = isset($results['type']) && $results['type'] === 'error' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($results['message']) . '</p></div>';
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-products-tab"
                    class="nav-tab nav-tab-active"><?php esc_html_e('Generate Products', 'draxira'); ?></a>
                <a href="#manage-products-tab" class="nav-tab"><?php esc_html_e('Manage Dummy Products', 'draxira'); ?></a>
            </h2>

            <div id="generate-products-tab" class="tab-content active">
                <form method="post" action="" id="generate-products-form">
                    <?php wp_nonce_field('generate_dummy_products'); ?>
                    <div id="product-meta-configuration">
                        <!-- Loaded via AJAX -->
                    </div>

                    <p class="submit">
                        <input type="submit" name="generate_products" class="button button-primary"
                            value="<?php esc_attr_e('Generate Products', 'draxira'); ?>">
                    </p>
                </form>
            </div>

            <div id="manage-products-tab" class="tab-content">
                <h3><?php esc_html_e('Dummy Products Created by Plugin', 'draxira'); ?></h3>
                <div class="filter-section">
                    <form method="get" action="" class="filter-dummy-products">
                        <input type="hidden" name="page" value="draxira-products">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Filter Products', 'draxira'); ?></th>
                                <td>
                                    <button type="button" id="load-dummy-products"
                                        class="button"><?php esc_html_e('Load Dummy Products', 'draxira'); ?></button>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>

                <div id="dummy-products-list">
                    <p><?php esc_html_e('Click "Load Dummy Products" to see dummy products.', 'draxira'); ?></p>
                </div>

                <div id="delete-section"
                    style="display:none; margin-top: 30px; padding: 20px; background: #fff5f5; border: 1px solid #ffb3b3; border-radius: 6px;">
                    <h4 style="color:#d63638; margin-top:0;"><?php esc_html_e('Delete Dummy Products', 'draxira'); ?></h4>
                    <p class="description"><strong><?php esc_html_e('Warning:', 'draxira'); ?></strong>
                        <?php esc_html_e('This will permanently delete ALL dummy products (including variations, meta, terms, images, etc.). This cannot be undone.', 'draxira'); ?>
                    </p>

                    <form method="post" action="">
                        <?php wp_nonce_field('clear_dummy_products', '_wpnonce'); ?>
                        <input type="hidden" name="clear_dummy_products" value="1">

                        <p style="margin-top:20px;">
                            <input type="submit" class="button button-large button-link-delete"
                                value="<?php esc_attr_e('Delete All Dummy Products', 'draxira'); ?>"
                                onclick="return confirm('<?php echo esc_js(__('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy products.\nNo backup. No trash. Really sure?', 'draxira')); ?>');">
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <?php
    }
}