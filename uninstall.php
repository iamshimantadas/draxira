<?php
/**
 * Uninstall script for Draxira Plugin
 * 
 * This file runs when the plugin is uninstalled (deleted) from WordPress.
 * It removes all plugin data from the database including:
 * - Dummy posts and their meta
 * - Dummy products and their variations
 * - Dummy users and their meta
 * - Dummy taxonomy terms
 * - Plugin transients and options
 * 
 * @package Draxira
 * @since 1.0.1
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Additional security: Verify the user has permission to uninstall plugins
if (!current_user_can('activate_plugins')) {
    return;
}

/**
 * Recursively delete a directory using WP_Filesystem
 *
 * @since 1.0.1
 * @param string $dir Directory path to delete
 * @return bool True on success, false on failure
 */
function draxira_recursive_delete_with_wp_filesystem($dir)
{
    global $wp_filesystem;

    // Initialize WP_Filesystem if not already done
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    if (!$wp_filesystem) {
        // Fallback to PHP functions if WP_Filesystem is not available
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                draxira_recursive_delete_with_wp_filesystem($path);
            } else {
                wp_delete_file($path);
            }
        }
        return rmdir($dir);
    }

    // Use WP_Filesystem
    if (!$wp_filesystem->exists($dir)) {
        return false;
    }

    return $wp_filesystem->delete($dir, true);
}

/**
 * Main uninstall function
 * 
 * @since 1.0.1
 */
function draxira_uninstall_cleanup()
{
    global $wpdb;

    // Define the meta key used to identify dummy content
    $meta_key = '_draxira_dummy_content';

    $post_types = get_post_types(['public' => true]);
    
    // Include product post type even if WooCommerce not active (for cleanup)
    if (!in_array('product', $post_types)) {
        $post_types[] = 'product';
    }

    $dummy_posts = get_posts([
        'post_type' => $post_types,
        'posts_per_page' => -1,
        'meta_key' => $meta_key,
        'meta_value' => '1',
        'fields' => 'ids',
        'post_status' => 'any',
    ]);

    if (!empty($dummy_posts)) {
        foreach ($dummy_posts as $post_id) {
            // Force delete the post (bypass trash)
            wp_delete_post($post_id, true);
        }
    }

    // Clean up any orphaned post meta
    $wpdb->query("
        DELETE pm FROM {$wpdb->postmeta} pm
        LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.ID IS NULL
    ");

    $dummy_users = get_users([
        'meta_key' => $meta_key,
        'meta_value' => '1',
        'fields' => 'ids',
    ]);

    if (!empty($dummy_users)) {
        foreach ($dummy_users as $user_id) {
            // Extra security: Double-check this is a dummy user
            $is_dummy = get_user_meta($user_id, $meta_key, true);
            
            if ($is_dummy === '1') {
                // Don't delete admin user (ID 1)
                if ($user_id != 1) {
                    // Reassign content to admin (0 = delete all content)
                    // Using wp_delete_user is necessary for complete cleanup
                    wp_delete_user($user_id, 0);
                } else {
                    // If admin user is marked as dummy, just remove the meta
                    delete_user_meta($user_id, $meta_key);
                }
            }
        }
    }

    // Clean up any orphaned user meta
    $wpdb->query("
        DELETE um FROM {$wpdb->usermeta} um
        LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id
        WHERE u.ID IS NULL
    ");

    $taxonomies = get_taxonomies(['public' => true]);
    $product_taxonomies = get_object_taxonomies('product', 'names');
    $taxonomies = array_merge($taxonomies, $product_taxonomies);
    $taxonomies = array_unique($taxonomies);

    foreach ($taxonomies as $taxonomy) {
        $dummy_terms = get_terms([
            'taxonomy' => $taxonomy,
            'meta_key' => $meta_key,
            'meta_value' => '1',
            'hide_empty' => false,
            'fields' => 'ids',
        ]);

        if (!is_wp_error($dummy_terms) && !empty($dummy_terms)) {
            foreach ($dummy_terms as $term_id) {
                wp_delete_term($term_id, $taxonomy);
            }
        }
    }

    // Clean up any orphaned term meta
    $wpdb->query("
        DELETE tm FROM {$wpdb->termmeta} tm
        LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
        WHERE t.term_id IS NULL
    ");

    $dummy_attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'meta_key' => $meta_key,
        'meta_value' => '1',
        'fields' => 'ids',
        'post_status' => 'inherit',
    ]);

    $pattern_attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        's' => 'dummy_content_filler_img_',
        'fields' => 'ids',
        'post_status' => 'inherit',
    ]);

    $all_attachments = array_unique(array_merge($dummy_attachments, $pattern_attachments));

    if (!empty($all_attachments)) {
        foreach ($all_attachments as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s",
            '_transient_draxira_%',
            '_transient_timeout_draxira_%'
        )
    );

    delete_option('draxira_settings');
    delete_option('draxira_version');

    $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta_key], ['%s']);
    $wpdb->delete($wpdb->usermeta, ['meta_key' => $meta_key], ['%s']);
    $wpdb->delete($wpdb->termmeta, ['meta_key' => $meta_key], ['%s']);

    // delete uplpaded files
    $upload_dir = wp_upload_dir();
    $dummy_images_dir = $upload_dir['basedir'];

    $dummy_files = glob($dummy_images_dir . '/dummy_content_filler_img_*.*');
    if (!empty($dummy_files) && is_array($dummy_files)) {
        foreach ($dummy_files as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
    }

    $dummy_product_files = glob($dummy_images_dir . '/dummy_content_filler_product_img_*.*');
    if (!empty($dummy_product_files) && is_array($dummy_product_files)) {
        foreach ($dummy_product_files as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
    }

    // Clean up cache directory
    $cache_dir = $upload_dir['basedir'] . '/draxira-cache';
    if (file_exists($cache_dir) && is_dir($cache_dir)) {
        draxira_recursive_delete_with_wp_filesystem($cache_dir);
    }
}

if (is_multisite()) {
    $blog_ids = get_sites(['fields' => 'ids']);

    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        draxira_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    draxira_uninstall_cleanup();
}