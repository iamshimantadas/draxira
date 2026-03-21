<?php
/**
 * Plugin Name: Draxira – Dummy Content Generator
 * Plugin URI: 
 * Description: Generate dummy posts, pages, custom post types, users, and WooCommerce products for testing WordPress websites instantly.
 * Version: 1.0.0
 * Author: microcodes
 * Author URI: https://microcodes.in
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: draxira
 * Domain Path: /languages
 * 
 * @package Draxira
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// plugin constants
define('DRAXIRA_VERSION', '1.0.0');
define('DRAXIRA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DRAXIRA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DRAXIRA_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DRAXIRA_META_KEY', '_draxira_dummy_content');

$composer_autoload = DRAXIRA_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

require_once DRAXIRA_PLUGIN_DIR . 'includes/class-draxira.php';
require_once DRAXIRA_PLUGIN_DIR . 'includes/class-draxira-products.php';

// Initialize plugin
add_action('plugins_loaded', function () {
    // Plugin main initialize
    Draxira::get_instance();

    // If woocommerce enabled
    if (class_exists('WooCommerce')) {
        Draxira_Products::get_instance();
    }
});

/**
 * Increase execution limits for bulk operations
 */
function draxira_increase_limits() {
    if (function_exists('set_time_limit')) {
        set_time_limit(600);
    }
    
    if (function_exists('ini_set')) {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '600');
        ini_set('max_input_time', '600');
    }
}

// Hook into admin_init to increase limits for plugin pages
add_action('admin_init', function() {
    if (isset($_GET['page']) && strpos($_GET['page'], 'draxira') !== false) {
        draxira_increase_limits();
    }
});