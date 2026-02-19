<?php
/**
 * WooCommerce Products Dummy Content Generator
 * 
 * Handles generation of dummy WooCommerce products with variations,
 * categories, attributes, and product meta data.
 *
 * @package WP_Dummy_Content_Filler
 * @subpackage Includes
 * @since 1.0.0
 */

/**
 * Class WP_Dummy_Content_Filler_Products
 * 
 * Manages all WooCommerce product dummy content generation functionality.
 *
 * @since 1.0.0
 * @access public
 */
class WP_Dummy_Content_Filler_Products
{
    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @access private
     * @var WP_Dummy_Content_Filler_Products|null
     */
    private static $instance = null;

    /**
     * Product data loaded from CSV file
     *
     * @since 1.0.0
     * @access private
     * @var array
     */
    private $product_data = [];

    /**
     * Path to the CSV data file
     *
     * @since 1.0.0
     * @access private
     * @var string
     */
    private $csv_file_path;

    /**
     * Available product statuses
     *
     * @since 1.0.0
     * @access private
     * @var array
     */
    private $product_statuses = [
        'publish' => 'Published',
        'draft'   => 'Draft',
        'pending' => 'Pending Review',
    ];

    /**
     * Get singleton instance of the class
     *
     * @since 1.0.0
     * @access public
     * @static
     * @return WP_Dummy_Content_Filler_Products Singleton instance
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
        $this->csv_file_path = WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR . 'woo-data/walmart-products.csv';
        $this->mc_init_hooks();
        $this->mc_load_product_data();
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
        // Handle product generation
        add_action('admin_init', [$this, 'mc_handle_product_actions']);

        // AJAX handlers
        add_action('wp_ajax_wpdcf_get_product_meta', [$this, 'mc_ajax_get_product_meta']);
        add_action('wp_ajax_wpdcf_get_dummy_products', [$this, 'mc_ajax_get_dummy_products']);
    }

    /**
     * Load product data from CSV file
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_load_product_data()
    {
        if (!file_exists($this->csv_file_path)) {
            return;
        }

        $handle = fopen($this->csv_file_path, 'r');
        if ($handle === false) {
            return;
        }

        $headers  = [];
        $data     = [];
        $row_count = 0;
        $max_rows = 100; // Limit to prevent memory issues

        while (($row = fgetcsv($handle)) !== false && $row_count < $max_rows) {
            if ($row_count === 0) {
                $headers = $row;
            } else {
                $row_data = [];
                foreach ($headers as $index => $header) {
                    if (isset($row[$index])) {
                        $row_data[$header] = $row[$index];
                    }
                }
                $data[] = $row_data;
            }
            $row_count++;
        }

        fclose($handle);
        $this->product_data = $data;
    }

    /**
     * Get available product data from CSV
     *
     * @since 1.0.0
     * @access public
     * @return array Product data array
     */
    public function mc_get_available_product_data()
    {
        return $this->product_data;
    }

    /**
     * Handle product form submissions
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_handle_product_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['generate_products']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'generate_dummy_products')) {
            $this->mc_generate_dummy_products();
        }

        $clear_products_nonce_action = 'clear_dummy_products';
        if (wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', $clear_products_nonce_action)) {
            if (isset($_REQUEST['clear_dummy_products'])) {
                $deleted_count = $this->mc_clear_dummy_products();

                set_transient('dummy_product_results', [
                    'message' => sprintf(
                        'Successfully deleted %d %s (and associated data).',
                        $deleted_count,
                        _n('dummy product', 'dummy products', $deleted_count)
                    ),
                    'type' => 'success'
                ], 45);

                wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler-products'));
                exit;
            }
        }
    }

    /**
     * Get WooCommerce product meta fields
     * Excluding our meta key
     *
     * @since 1.0.0
     * @access private
     * @return array Array of product meta keys with labels
     */
    private function mc_get_product_meta_keys()
    {
        $meta_keys = [];

        // Default WooCommerce product meta fields
        $default_woo_fields = [
            '_price'              => 'Price',
            '_regular_price'      => 'Regular Price',
            '_sale_price'         => 'Sale Price',
            '_sku'                => 'SKU',
            '_stock_status'       => 'Stock Status',
            '_manage_stock'       => 'Manage Stock',
            '_stock'              => 'Stock Quantity',
            '_weight'             => 'Weight',
            '_length'             => 'Length',
            '_width'              => 'Width',
            '_height'             => 'Height',
            '_virtual'            => 'Virtual Product',
            '_downloadable'       => 'Downloadable',
            '_tax_status'         => 'Tax Status',
            '_tax_class'          => 'Tax Class',
            '_purchase_note'      => 'Purchase Note',
            '_featured'           => 'Featured Product',
            '_visibility'         => 'Visibility',
            '_backorders'         => 'Backorders',
            '_sold_individually'  => 'Sold Individually',
            '_product_image_gallery' => 'Product Gallery',
        ];

        // Get custom product meta from database
        global $wpdb;
        $custom_meta_keys = $wpdb->get_col("
            SELECT DISTINCT meta_key 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN (
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'product'
            )
            AND meta_key NOT LIKE '\_edit%'
            AND meta_key NOT LIKE '\_wp_%'
            AND meta_key NOT IN (
                '_edit_lock', '_edit_last', '_thumbnail_id', 
                '_product_attributes', '_default_attributes',
                '_variation_description', '_menu_order',
                '_downloadable_files', '_children', '_files',
                '_mc_wp_dummy_content_filler'  -- Exclude our meta key
            )
            ORDER BY meta_key
            LIMIT 50
        ");

        // Format custom meta keys
        $custom_fields = [];
        foreach ($custom_meta_keys as $key) {
            if (!isset($default_woo_fields[$key]) && $key !== '_mc_wp_dummy_content_filler') {
                $label = ucwords(str_replace(['_', '-'], ' ', $key));
                $custom_fields[$key] = $label;
            }
        }

        // Get ACF fields for products
        $acf_fields = [];
        if (function_exists('acf_get_field_groups')) {
            $field_groups = acf_get_field_groups(['post_type' => 'product']);
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

        // Get CMB2 fields for products
        $cmb2_fields = [];
        if (class_exists('CMB2')) {
            $cmb2_boxes = CMB2_Boxes::get_all();
            foreach ($cmb2_boxes as $cmb_id => $cmb) {
                $object_types = $cmb->prop('object_types');
                if ($object_types && in_array('product', (array) $object_types)) {
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

        // Merge all fields
        $all_fields = array_merge(
            $default_woo_fields,
            $custom_fields,
            $acf_fields,
            $cmb2_fields
        );

        // Remove duplicates and sort
        $all_fields = array_unique($all_fields);
        asort($all_fields);

        return $all_fields;
    }

    /**
     * Get WooCommerce product taxonomies
     *
     * @since 1.0.0
     * @access private
     * @return array Array of product taxonomies with labels
     */
    private function mc_get_product_taxonomies()
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
    private function mc_generate_dummy_products()
    {
        if (!class_exists('WooCommerce')) {
            set_transient('dummy_product_results', [
                'message' => 'WooCommerce is not installed or activated.',
                'type'    => 'error'
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler-products'));
            exit;
        }

        $count                = intval($_POST['product_count'] ?? 5);
        $product_status       = sanitize_text_field($_POST['product_status'] ?? 'publish');
        $with_featured_image  = isset($_POST['with_featured_image']);
        $with_gallery         = isset($_POST['with_gallery']);
        $create_excerpt       = isset($_POST['create_excerpt']);
        $product_author       = intval($_POST['product_author'] ?? get_current_user_id());

        // Get product meta configurations
        $product_meta_config = [];
        if (isset($_POST['product_meta']) && is_array($_POST['product_meta'])) {
            foreach ($_POST['product_meta'] as $meta_key => $config) {
                if (!empty($config['type'])) {
                    $product_meta_config[$meta_key] = [
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
                $terms = $this->mc_create_dummy_terms($taxonomy, 10);
                $created_terms[$taxonomy] = $terms;
                $results['taxonomies_created'] += count($terms);
            }
        }

        // Generate products
        $available_data = $this->mc_get_available_product_data();
        $data_count     = count($available_data);

        for ($i = 0; $i < $count; $i++) {
            $data_index   = $i % $data_count; // Cycle through available data
            $product_data = ($data_count > 0) ? $available_data[$data_index] : [];

            $product_id = $this->mc_create_dummy_product(
                $product_status,
                $with_featured_image,
                $with_gallery,
                $create_excerpt,
                $product_author,
                $product_meta_config,
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

        set_transient('dummy_product_results', [
            'message' => sprintf(
                'Successfully generated %d %s with %d taxonomy terms. Failed: %d',
                $results['success'],
                _n('product', 'products', $results['success']),
                $results['taxonomies_created'],
                $results['failed']
            )
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=wp-dummy-content-filler-products'));
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
     * @param array  $meta_config          Meta field configurations
     * @param array  $created_terms        Created taxonomy terms
     * @param array  $taxonomy_config      Taxonomy configurations
     * @param array  $product_data         Product data from CSV
     * @return int|false Product ID on success, false on failure
     */
    private function mc_create_dummy_product(
        $status = 'publish',
        $with_featured_image = false,
        $with_gallery = false,
        $create_excerpt = false,
        $author = 0,
        $meta_config = [],
        $created_terms = [],
        $taxonomy_config = [],
        $product_data = []
    ) {
        // Use current user if no author specified
        if (!$author || !get_user_by('id', $author)) {
            $author = get_current_user_id();
        }

        // Get product name from CSV data or generate one
        $product_name = '';
        if (!empty($product_data['product_name'])) {
            $product_name = $product_data['product_name'];
        } else if (!empty($product_data['description'])) {
            // Try to extract name from description
            $desc = substr($product_data['description'], 0, 100);
            $product_name = trim($desc);
        }

        if (empty($product_name)) {
            $product_name = 'Dummy Product ' . time() . ' ' . wp_rand(1000, 9999);
        }

        // Get product description from CSV data or generate one
        $product_description = '';
        if (!empty($product_data['description'])) {
            $product_description = $product_data['description'];
        } else {
            $product_description = 'This is a dummy product for testing purposes. It contains all the necessary details for a WooCommerce product.';
        }

        // Create product - always use simple product type
        $product_args = [
            'post_title'   => wp_trim_words($product_name, 10, '...'),
            'post_content' => $product_description,
            'post_status'  => $status,
            'post_type'    => 'product',
            'post_author'  => $author,
        ];

        // Add excerpt if requested
        if ($create_excerpt && !empty($product_data['description'])) {
            $excerpt = substr($product_data['description'], 0, 200);
            if (!empty($excerpt)) {
                $product_args['post_excerpt'] = $excerpt . '...';
            }
        }

        $product_id = wp_insert_post($product_args);

        if ($product_id && !is_wp_error($product_id)) {
            // Set product type - always simple
            wp_set_object_terms($product_id, 'simple', 'product_type');

            // Add our meta key to identify dummy products
            update_post_meta($product_id, WP_DUMMY_CONTENT_FILLER_META_KEY, '1');

            // Set basic WooCommerce meta
            $this->mc_set_basic_product_meta($product_id, $product_data);

            // Add featured image if requested
            if ($with_featured_image) {
                $this->mc_attach_featured_image($product_id, $product_data);
            }

            // Add product gallery if requested
            if ($with_gallery) {
                $this->mc_attach_product_gallery($product_id);
            }

            // Assign taxonomies with special handling for product_brand
            if (!empty($created_terms)) {
                foreach ($created_terms as $taxonomy => $terms) {
                    if (!empty($terms) && isset($taxonomy_config[$taxonomy]['assign'])) {
                        $assign_count = $taxonomy_config[$taxonomy]['assign'];

                        // For product_brand, always assign exactly 1 term
                        if ($taxonomy === 'product_brand') {
                            $assign_count = 1;
                        }

                        $assign_count = min($assign_count, count($terms));

                        // Randomly select terms to assign
                        $shuffled_terms = $terms;
                        shuffle($shuffled_terms);
                        $selected_terms = array_slice($shuffled_terms, 0, $assign_count);

                        if (!empty($selected_terms)) {
                            wp_set_post_terms($product_id, $selected_terms, $taxonomy);
                        }
                    }
                }
            }

            // Add configured product meta (only if type is not empty)
            foreach ($meta_config as $meta_key => $config) {
                if ($meta_key === '_mc_wp_dummy_content_filler') {
                    continue; // Skip our meta key
                }

                // Only set meta if type is not empty string
                if (!empty($config['type'])) {
                    $meta_value = $this->mc_get_product_meta_value($meta_key, $config, $product_data);
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
     * Set basic WooCommerce product meta from CSV data
     *
     * @since 1.0.0
     * @access private
     * @param int   $product_id   Product ID
     * @param array $product_data Product data from CSV
     * @return void
     */
    private function mc_set_basic_product_meta($product_id, $product_data)
    {
        // Set price from CSV data
        if (!empty($product_data['final_price'])) {
            $price = floatval($product_data['final_price']);
            if ($price > 0) {
                update_post_meta($product_id, '_price', $price);
                update_post_meta($product_id, '_regular_price', $price);

                // Add random sale price for some products
                if (wp_rand(1, 4) === 1) { // 25% chance of having a sale price
                    $sale_price = $price * 0.8; // 20% off
                    update_post_meta($product_id, '_sale_price', $sale_price);
                    update_post_meta($product_id, '_price', $sale_price);
                }
            }
        } else {
            // Generate random prices
            $price = wp_rand(10, 1000);
            update_post_meta($product_id, '_price', $price);
            update_post_meta($product_id, '_regular_price', $price);

            if (wp_rand(1, 4) === 1) {
                $sale_price = $price * 0.8;
                update_post_meta($product_id, '_sale_price', $sale_price);
                update_post_meta($product_id, '_price', $sale_price);
            }
        }

        // Set SKU
        if (!empty($product_data['sku'])) {
            $sku = sanitize_title($product_data['sku']);
        } else if (!empty($product_data['product_id'])) {
            $sku = 'SKU-' . $product_data['product_id'];
        } else {
            $sku = 'SKU-' . $product_id . '-' . wp_rand(1000, 9999);
        }
        update_post_meta($product_id, '_sku', $sku);

        // Set stock management
        $manage_stock = (wp_rand(1, 2) === 1) ? 'yes' : 'no';
        update_post_meta($product_id, '_manage_stock', $manage_stock);

        if ($manage_stock === 'yes') {
            $stock = wp_rand(0, 100);
            update_post_meta($product_id, '_stock', $stock);
            $stock_status = ($stock > 0) ? 'instock' : 'outofstock';
        } else {
            $stock_status = 'instock';
        }
        update_post_meta($product_id, '_stock_status', $stock_status);

        // Set product dimensions and weight randomly
        $dimensions = ['weight', 'length', 'width', 'height'];
        foreach ($dimensions as $dimension) {
            if (wp_rand(1, 3) === 1) { // 33% chance to have this dimension set
                $value = wp_rand(1, 50);
                update_post_meta($product_id, '_' . $dimension, $value);
            }
        }

        // Set virtual and downloadable flags randomly
        $virtual      = (wp_rand(1, 10) === 1) ? 'yes' : 'no'; // 10% chance to be virtual
        update_post_meta($product_id, '_virtual', $virtual);

        $downloadable = (wp_rand(1, 5) === 1) ? 'yes' : 'no'; // 20% chance to be downloadable
        update_post_meta($product_id, '_downloadable', $downloadable);

        // Set featured product randomly
        $featured = (wp_rand(1, 4) === 1) ? 'yes' : 'no'; // 25% chance to be featured
        update_post_meta($product_id, '_featured', $featured);
    }

    /**
     * Get product meta value based on configuration
     *
     * @since 1.0.0
     * @access private
     * @param string $meta_key     Meta key
     * @param array  $config       Meta configuration
     * @param array  $product_data Product data from CSV
     * @return mixed Meta value
     */
    private function mc_get_product_meta_value($meta_key, $config, $product_data)
    {
        // Skip our meta key
        if ($meta_key === '_mc_wp_dummy_content_filler') {
            return null;
        }

        // If type is empty string, return null (Leave Empty option selected)
        if (empty($config['type'])) {
            return null;
        }

        // Use faker type if specified
        if ($config['type'] !== '' && $config['type'] !== 'custom') {
            $faker = $this->mc_get_faker();
            if ($faker) {
                return $this->mc_generate_faker_value($config['type']);
            }
        }

        // Generate default values for common fields
        switch ($meta_key) {
            case '_price':
                if (!empty($product_data['final_price'])) {
                    return floatval($product_data['final_price']);
                }
                return wp_rand(10, 1000);
            case '_regular_price':
                if (!empty($product_data['final_price'])) {
                    return floatval($product_data['final_price']);
                }
                return wp_rand(10, 1000);
            case '_sale_price':
                return ''; // Empty by default
            case '_sku':
                if (!empty($product_data['sku'])) {
                    return sanitize_title($product_data['sku']);
                }
                return 'SKU-' . wp_rand(1000, 9999);
            case '_tax_status':
                return 'taxable';
            case '_tax_class':
                return '';
            case '_visibility':
                return 'visible';
            case '_backorders':
                return 'no';
            case '_sold_individually':
                return 'no';
            case '_purchase_note':
                return 'Thank you for your purchase!';
            case '_product_image_gallery':
                return ''; // Will be set by attach_product_gallery()
            case '_manage_stock':
                return (wp_rand(1, 2) === 1) ? 'yes' : 'no';
            case '_stock':
                return wp_rand(0, 100);
            case '_stock_status':
                return 'instock';
            default:
                return '';
        }
    }

    /**
     * Get Faker instance
     *
     * @since 1.0.0
     * @access private
     * @return Faker\Generator|false Faker instance or false if not available
     */
    private function mc_get_faker()
    {
        if (class_exists('Faker\Factory')) {
            return Faker\Factory::create();
        }
        return false;
    }

    /**
     * Generate faker value
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

        $methods = [
            'text'      => 'sentence',
            'paragraphs'=> 'paragraphs',
            'words'     => 'words',
            'name'      => 'name',
            'email'     => 'email',
            'phone'     => 'phoneNumber',
            'address'   => 'address',
            'city'      => 'city',
            'country'   => 'country',
            'zipcode'   => 'postcode',
            'number'    => 'numberBetween',
            'price'     => 'randomFloat',
            'date'      => 'date',
            'boolean'   => 'boolean',
            'url'       => 'url',
            'image_url' => 'imageUrl',
            'color'     => 'colorName',
            'hex_color' => 'hexColor',
            'latitude'  => 'latitude',
            'longitude' => 'longitude',
            'company'   => 'company',
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
     * Attach featured image to product
     * First try CSV image_url, then fall back to plugin assets
     *
     * @since 1.0.0
     * @access private
     * @param int   $product_id   Product ID
     * @param array $product_data Product data from CSV
     * @return bool True on success, false on failure
     */
    private function mc_attach_featured_image($product_id, $product_data)
    {
        // First, try to use image from CSV data
        if (!empty($product_data['image_urls'])) {
            $image_urls = $product_data['image_urls'];

            // Try to parse the image URLs (they might be in JSON format)
            if (strpos($image_urls, '[') === 0) {
                $image_urls = json_decode($image_urls, true);
                if (is_array($image_urls) && !empty($image_urls)) {
                    $first_image = $image_urls[0];
                    if (is_string($first_image) && filter_var($first_image, FILTER_VALIDATE_URL)) {
                        $attachment_id = $this->mc_upload_image_from_url($first_image, $product_id . '-featured');
                        if ($attachment_id) {
                            set_post_thumbnail($product_id, $attachment_id);
                            return true;
                        }
                    }
                }
            } else if (is_string($image_urls) && filter_var($image_urls, FILTER_VALIDATE_URL)) {
                // Single image URL
                $attachment_id = $this->mc_upload_image_from_url($image_urls, $product_id . '-featured');
                if ($attachment_id) {
                    set_post_thumbnail($product_id, $attachment_id);
                    return true;
                }
            }
        }

        // Fall back to plugin assets from products folder
        $image_dir = WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR . 'assets/img/products/';

        if (!file_exists($image_dir)) {
            return false; // Don't fall back to general img directory
        }

        // Look for product-specific images
        $product_images = glob($image_dir . 'wp_dummy_content_filler_product_img_*.{jpg,jpeg,png,gif}', GLOB_BRACE);

        if (empty($product_images)) {
            return false; // No product images found
        }

        $random_image = $product_images[array_rand($product_images)];
        $filename     = basename($random_image);

        // Check if image already exists in media library
        $existing_image = get_page_by_title($filename, OBJECT, 'attachment');

        if ($existing_image) {
            set_post_thumbnail($product_id, $existing_image->ID);
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
    private function mc_upload_image_from_url($image_url, $filename_base)
    {
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Download the image
        $response = wp_remote_get($image_url);

        if (is_wp_error($response)) {
            return false;
        }

        $image_data = wp_remote_retrieve_body($response);

        if (empty($image_data)) {
            return false;
        }

        // Extract filename from URL
        $url_path = parse_url($image_url, PHP_URL_PATH);
        $filename = basename($url_path);

        if (empty($filename)) {
            $filename = sanitize_title($filename_base) . '.jpg';
        }

        // Check if image already exists
        $existing_image = get_page_by_title($filename, OBJECT, 'attachment');
        if ($existing_image) {
            return $existing_image->ID;
        }

        // Upload to media library
        $upload_file = wp_upload_bits($filename, null, $image_data);

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
                return $attachment_id;
            }
        }

        return false;
    }

    /**
     * Attach product gallery images from products folder only
     *
     * @since 1.0.0
     * @access private
     * @param int $product_id Product ID
     * @return void
     */
    private function mc_attach_product_gallery($product_id)
    {
        $image_dir = WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR . 'assets/img/products/';

        if (!file_exists($image_dir)) {
            return; // Don't use gallery if products folder doesn't exist
        }

        // Look for product-specific images only
        $product_images = glob($image_dir . 'wp_dummy_content_filler_product_img_*.{jpg,jpeg,png,gif}', GLOB_BRACE);

        if (empty($product_images)) {
            return; // No product images found
        }

        // Select 2-4 random images for gallery
        $gallery_count = wp_rand(2, min(4, count($product_images)));
        shuffle($product_images);
        $selected_images = array_slice($product_images, 0, $gallery_count);

        $gallery_ids = [];

        foreach ($selected_images as $image_path) {
            $filename = basename($image_path);

            // Check if image already exists
            $existing_image = get_page_by_title($filename, OBJECT, 'attachment');

            if ($existing_image) {
                $gallery_ids[] = $existing_image->ID;
                continue;
            }

            // Upload new image
            $upload_file = wp_upload_bits($filename, null, file_get_contents($image_path));

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
                    $gallery_ids[] = $attachment_id;
                }
            }
        }

        // Set product gallery
        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
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
        $max_attempts = $count * 3;

        while ($created < $count && $attempts < $max_attempts) {
            $attempts++;

            if ($faker) {
                if ($taxonomy === 'product_cat') {
                    $term_name = $faker->words(2, true);
                } else if ($taxonomy === 'product_tag') {
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
     * Clear dummy products
     *
     * @since 1.0.0
     * @access private
     * @return int Number of deleted products
     */
    private function mc_clear_dummy_products()
    {
        global $wpdb;

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'meta_key'       => WP_DUMMY_CONTENT_FILLER_META_KEY,
            'meta_value'     => '1',
            'fields'         => 'ids',
            'post_status'    => 'any',
        ];

        $dummy_products = get_posts($args);
        $deleted_count  = 0;

        foreach ($dummy_products as $product_id) {
            // Force delete the product (bypass trash)
            $deleted = wp_delete_post($product_id, true);

            if ($deleted && !is_wp_error($deleted)) {
                $deleted_count++;
            }
        }

        // Clean up dummy taxonomy terms
        $this->mc_cleanup_dummy_product_terms();

        return $deleted_count;
    }

    /**
     * Cleanup dummy product taxonomy terms
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_cleanup_dummy_product_terms()
    {
        $taxonomies = ['product_cat', 'product_tag', 'product_visibility', 'product_shipping_class'];

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
     * AJAX handler for getting product meta fields
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_ajax_get_product_meta()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'wpdcf_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $meta_keys  = $this->mc_get_product_meta_keys();
        $taxonomies = $this->mc_get_product_taxonomies();

        ob_start();
        ?>
        <div class="product-meta-section">
            <h3>Product Content Options</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Product Author</th>
                    <td>
                        <select name="product_author" id="product-author-selector">
                            <option value="0">Select User</option>
                            <?php
                            $authors = get_users([
                                'role__in' => ['administrator', 'editor', 'author'],
                                'orderby'  => 'display_name',
                                'order'    => 'ASC',
                            ]);
                            foreach ($authors as $author) {
                                echo '<option value="' . esc_attr($author->ID) . '">' .
                                    esc_html($author->display_name . ' (' . $author->user_login . ')') .
                                    '</option>';
                            }
                            ?>
                        </select>
                        <span class="description">Select who will be the author of generated products</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Product Status</th>
                    <td>
                        <select name="product_status">
                            <?php foreach ($this->product_statuses as $status_value => $status_label): ?>
                                <option value="<?php echo esc_attr($status_value); ?>"><?php echo esc_html($status_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Product Excerpt</th>
                    <td>
                        <label>
                            <input type="checkbox" name="create_excerpt" value="1">
                            Generate product short description
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Featured Image</th>
                    <td>
                        <label>
                            <input type="checkbox" name="with_featured_image" value="1" checked>
                            Add featured image
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Product Gallery</th>
                    <td>
                        <label>
                            <input type="checkbox" name="with_gallery" value="1">
                            Add product gallery images
                        </label>
                    </td>
                </tr>
            </table>

            <?php if (!empty($taxonomies)): ?>
                <h3>Product Taxonomies</h3>
                <p class="description">When "Create Terms" is enabled, 10 dummy terms will be automatically created for each
                    taxonomy.</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Taxonomy</th>
                            <th>Create Terms?</th>
                            <th>Assign Terms per Product</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($taxonomies as $taxonomy_slug => $taxonomy_label):
                            // For product_brand, only show 1 term assignment option
                            $assign_default = ($taxonomy_slug === 'product_brand') ? 1 : 2;
                            $max_assign     = ($taxonomy_slug === 'product_brand') ? 1 : 10;
                            ?>
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
                                        max="<?php echo esc_attr($max_assign); ?>" value="<?php echo esc_attr($assign_default); ?>"
                                        style="width: 80px;">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($meta_keys)): ?>
                <h3>Product Meta Fields</h3>
                <p class="description">Configure how each product field should be filled. Fields from WooCommerce, ACF, CMB2, or
                    custom plugins are listed.</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Field Name</th>
                            <th>Meta Key</th>
                            <th>Data Type / Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $faker_types = [
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
                            ''           => 'Leave Empty',  // Add Leave Empty option
                        ];

                        foreach ($meta_keys as $meta_key => $field_label):
                            $default_type = $this->mc_get_default_field_type($meta_key);
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
                                        <?php foreach ($faker_types as $type_value => $type_label):
                                            $selected = ($type_value === $default_type) ? 'selected' : '';
                                            ?>
                                            <option value="<?php echo esc_attr($type_value); ?>" <?php echo $selected; ?>>
                                                <?php echo esc_html($type_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($default_type): ?>
                                        <br><small class="description">Suggested:
                                            <?php echo esc_html($faker_types[$default_type] ?? $default_type); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <h3>Product Meta Fields</h3>
                <p class="description">No custom fields found for products.</p>
            <?php endif; ?>

        </div>
        <?php
        wp_send_json_success(ob_get_clean());
    }

    /**
     * Get default field type based on meta key
     *
     * @since 1.0.0
     * @access private
     * @param string $meta_key Meta key
     * @return string Default faker type
     */
    private function mc_get_default_field_type($meta_key)
    {
        $mappings = [
            '_price'              => 'price',
            '_regular_price'      => 'price',
            '_sale_price'         => 'price',
            '_stock'              => 'number',
            '_weight'             => 'number',
            '_length'             => 'number',
            '_width'              => 'number',
            '_height'             => 'number',
            '_sku'                => 'text',
            '_purchase_note'      => 'text',
            '_tax_status'         => 'text',
            '_tax_class'          => 'text',
            '_visibility'         => 'text',
            '_backorders'         => 'boolean',
            '_sold_individually'  => 'boolean',
            '_virtual'            => 'boolean',
            '_downloadable'       => 'boolean',
            '_featured'           => 'boolean',
            '_manage_stock'       => 'boolean',
        ];

        return $mappings[$meta_key] ?? '';
    }

    /**
     * AJAX handler for getting dummy products list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_ajax_get_dummy_products()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => 50,
            'meta_key'       => WP_DUMMY_CONTENT_FILLER_META_KEY,
            'meta_value'     => '1',
        ];

        $dummy_products = get_posts($args);

        if (empty($dummy_products)) {
            wp_send_json_error('No dummy products found.');
        }

        ob_start();
        ?>
        <p>Found <?php echo count($dummy_products); ?> dummy products.</p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Price</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Categories</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dummy_products as $product):
                    $product_obj = wc_get_product($product->ID);
                    $price       = $product_obj ? $product_obj->get_price_html() : 'N/A';
                    $sku         = $product_obj ? $product_obj->get_sku() : 'N/A';
                    $stock       = $product_obj ? $product_obj->get_stock_status() : 'N/A';

                    $categories = wp_get_post_terms($product->ID, 'product_cat', ['fields' => 'names']);
                    ?>
                    <tr>
                        <td><?php echo esc_html($product->ID); ?></td>
                        <td>
                            <strong><?php echo esc_html($product->post_title); ?></strong>
                        </td>
                        <td><?php echo wp_kses_post($price); ?></td>
                        <td><?php echo esc_html($sku); ?></td>
                        <td><?php echo esc_html($stock); ?></td>
                        <td>
                            <?php if (!empty($categories)): ?>
                                <?php echo esc_html(implode(', ', $categories)); ?>
                            <?php else: ?>
                                <em>None</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(get_the_date('', $product)); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($product->ID)); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo esc_url(get_permalink($product->ID)); ?>" class="button button-small"
                                target="_blank">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        wp_send_json_success(ob_get_clean());
    }

    /**
     * Render products page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_render_products_page()
    {
        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap"><h1>WooCommerce Products</h1>';
            echo '<div class="notice notice-error"><p>WooCommerce is not installed or activated. Please install and activate WooCommerce to use this feature.</p></div>';
            echo '</div>';
            return;
        }

        $available_data = $this->mc_get_available_product_data();
        ?>
        <div class="wrap wp-dummy-content-filler">
            <h1>Dummy Content Filler - WooCommerce Products</h1>

            <?php
            $results = get_transient('dummy_product_results');
            if ($results) {
                delete_transient('dummy_product_results');
                echo '<div class="notice notice-success"><p>' . esc_html($results['message']) . '</p></div>';
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-products-tab" class="nav-tab nav-tab-active">Generate Products</a>
                <a href="#manage-products-tab" class="nav-tab">Manage Dummy Products</a>
            </h2>

            <div id="generate-products-tab" class="tab-content active">
                <form method="post" action="" id="generate-products-form">
                    <?php wp_nonce_field('generate_dummy_products'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Number of Products</th>
                            <td>
                                <input type="number" name="product_count" min="1" max="100" value="5">
                                <p class="description">Maximum 100 products at a time</p>
                            </td>
                        </tr>
                    </table>

                    <div id="product-meta-configuration">
                        <!-- Loaded via AJAX -->
                    </div>

                    <p class="submit">
                        <input type="submit" name="generate_products" class="button button-primary" value="Generate Products">
                    </p>
                </form>
            </div>

            <div id="manage-products-tab" class="tab-content">
                <h3>Dummy Products Created by Plugin</h3>
                <div class="filter-section">
                    <form method="get" action="" class="filter-dummy-products">
                        <input type="hidden" name="page" value="wp-dummy-content-filler-products">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Filter Products</th>
                                <td>
                                    <button type="button" id="load-dummy-products" class="button">Load Dummy Products</button>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>

                <div id="dummy-products-list">
                    <p>Click "Load Dummy Products" to see dummy products.</p>
                </div>

                <div id="delete-section"
                    style="display:none; margin-top: 30px; padding: 20px; background: #fff5f5; border: 1px solid #ffb3b3; border-radius: 6px;">
                    <h4 style="color:#d63638; margin-top:0;">Delete Dummy Products</h4>
                    <p class="description"><strong>Warning:</strong> This will <strong>permanently</strong> delete ALL dummy
                        products (including variations, meta, terms, images, etc.). This cannot be undone.</p>

                    <form method="post" action="">
                        <?php wp_nonce_field('clear_dummy_products', '_wpnonce'); ?>
                        <input type="hidden" name="clear_dummy_products" value="1">

                        <p style="margin-top:20px;">
                            <input type="submit" class="button button-large button-link-delete"
                                value="Delete All Dummy Products"
                                onclick="return confirm('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy products.\nNo backup. No trash. Really sure?');">
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Tab switching
                $('.nav-tab-wrapper a').click(function (e) {
                    e.preventDefault();
                    var tab = $(this).attr('href');

                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');

                    $('.tab-content').removeClass('active');
                    $(tab).addClass('active');

                    // Load product meta when generate tab is shown
                    if (tab === '#generate-products-tab') {
                        loadProductMeta();
                    }

                    // Load dummy products when manage tab is shown
                    if (tab === '#manage-products-tab') {
                        loadDummyProducts();
                    }
                });

                // Load product meta on page load
                if ($('#generate-products-tab').hasClass('active')) {
                    loadProductMeta();
                }

                // Load dummy products
                $('#load-dummy-products').click(function () {
                    loadDummyProducts();
                });

                function loadProductMeta() {
                    $.ajax({
                        url: wpdcf_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wpdcf_get_product_meta',
                            nonce: wpdcf_ajax.nonce
                        },
                        beforeSend: function () {
                            $('#product-meta-configuration').html('<div class="loading"><p>Loading product configuration...</p></div>');
                        },
                        success: function (response) {
                            if (response.success) {
                                $('#product-meta-configuration').html(response.data);
                            } else {
                                $('#product-meta-configuration').html('<div class="error"><p>Error loading product configuration.</p></div>');
                            }
                        },
                        error: function () {
                            $('#product-meta-configuration').html('<div class="error"><p>Error loading product configuration.</p></div>');
                        }
                    });
                }

                function loadDummyProducts() {
                    $.ajax({
                        url: wpdcf_ajax.ajax_url,
                        type: 'GET',
                        data: {
                            action: 'wpdcf_get_dummy_products'
                        },
                        beforeSend: function () {
                            $('#dummy-products-list').html('<div class="loading"><p>Loading dummy products...</p></div>');
                        },
                        success: function (response) {
                            if (response.success) {
                                $('#dummy-products-list').html(response.data);
                                $('#delete-section').show();
                            } else {
                                $('#dummy-products-list').html('<div class="notice notice-warning"><p>' + response.data + '</p></div>');
                                $('#delete-section').hide();
                            }
                        },
                        error: function () {
                            $('#dummy-products-list').html('<div class="error"><p>Error loading products.</p></div>');
                            $('#delete-section').hide();
                        }
                    });
                }

                // Auto-load products when manage tab is shown
                if ($('#manage-products-tab').hasClass('active')) {
                    loadDummyProducts();
                }
            });
        </script>
        <?php
    }
}