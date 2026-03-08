<?php
/*
 * Plugin Name:       Dummy Content Filler Pro
 * Plugin URI:        https://microcodes.in/dummy-content-filler-pro
 * Description:       Dummy Content Filler Pro is a WordPress plugin that helps to fill dummy posts into targeted post-types with custom options such featured image, post meta etc.
 * Version:           1.0.0
 * Author:            Shimanta Das
 * Author URI:        https://microcodes.in
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dummy-content-filler-pro
 * Domain Path:       /languages
 * 
 * @package Dummy_Content_Filler_Pro
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DUMMY_CONTENT_FILLER_PRO_VERSION', '1.0.0');
define('DUMMY_CONTENT_FILLER_PRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DUMMY_CONTENT_FILLER_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DUMMY_CONTENT_FILLER_PRO_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DUMMY_CONTENT_FILLER_PRO_META_KEY', '_mc_dummy_content_filler_pro');

// Check if Composer autoload exists
$composer_autoload = DUMMY_CONTENT_FILLER_PRO_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Include plugin classes
require_once DUMMY_CONTENT_FILLER_PRO_PLUGIN_DIR . 'includes/class-dummy-content-filler-pro.php';
require_once DUMMY_CONTENT_FILLER_PRO_PLUGIN_DIR . 'includes/class-dummy-content-filler-pro-products.php';

// Initialize plugin
add_action('plugins_loaded', function () {
    // Load text domain
    load_plugin_textdomain('dummy-content-filler-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');

    //plugin main initialize
    Dummy_Content_Filler_Pro::mc_get_instance();

    // if woocommerce enable
    if (class_exists('WooCommerce')) {
        Dummy_Content_Filler_Pro_Products::mc_get_instance();
    }
});
