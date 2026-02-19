<?php
/*
 * Plugin Name:       WP Dummy Content Filler
 * Description:       WP Dummy Content Filler is a WordPress plugin that helps to fill dummy posts into targeted post-types with custom options such featured image, post meta etc.
 * Text Domain:       wp-dummy-content-filler
 * Version:           1.0.0
 * Author:            shimanta das
 * Author URI:        https://microcodes.in
 * 
 * @package WP_Dummy_Content_Filler
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_DUMMY_CONTENT_FILLER_VERSION', '1.0.0');
define('WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_DUMMY_CONTENT_FILLER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_DUMMY_CONTENT_FILLER_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WP_DUMMY_CONTENT_FILLER_META_KEY', '_mc_wp_dummy_content_filler');

// Check if Composer autoload exists
$composer_autoload = WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Include plugin classes
require_once WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR . 'includes/class-wp-dummy-content-filler.php';
require_once WP_DUMMY_CONTENT_FILLER_PLUGIN_DIR . 'includes/class-wp-dummy-content-filler-products.php';

// Initialize plugin
add_action('plugins_loaded', function () {
    WP_Dummy_Content_Filler::mc_get_instance();

    // if woocommerce enable
    if (class_exists('WooCommerce')) {
        WP_Dummy_Content_Filler_Products::mc_get_instance();
    }
});