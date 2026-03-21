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
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Main uninstall function
 * 
 * @since 1.0.0
 */
function draxira_uninstall_cleanup() {
    global $wpdb;
    
    // Define the meta key used to identify dummy content
    $meta_key = '_draxira_dummy_content';
    
    // Get all dummy posts (including products if WooCommerce is/was installed)
    $post_types = get_post_types(['public' => true]);
    
    // Include product post type even if WooCommerce not active (for cleanup)
    if (!in_array('product', $post_types)) {
        $post_types[] = 'product';
    }
    
    $dummy_posts = get_posts([
        'post_type'      => $post_types,
        'posts_per_page' => -1,
        'meta_key'       => $meta_key,
        'meta_value'     => '1',
        'fields'         => 'ids',
        'post_status'    => 'any',
    ]);
    
    if (!empty($dummy_posts)) {
        foreach ($dummy_posts as $post_id) {
            // Force delete the post (bypass trash)
            wp_delete_post($post_id, true);
        }
    }
    
    // Clean up any orphaned post meta (safety measure)
    $wpdb->query("
        DELETE pm FROM {$wpdb->postmeta} pm
        LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.ID IS NULL
    ");
    
    // Get all dummy users
    $dummy_users = get_users([
        'meta_key'   => $meta_key,
        'meta_value' => '1',
        'fields'     => 'ids',
    ]);
    
    if (!empty($dummy_users)) {
        foreach ($dummy_users as $user_id) {
            // Don't delete admin user (ID 1)
            if ($user_id != 1) {
                wp_delete_user($user_id);
            } else {
                // If admin user is marked as dummy, just remove the meta
                delete_user_meta($user_id, $meta_key);
            }
        }
    }
    
    // Clean up any orphaned user meta (safety measure)
    $wpdb->query("
        DELETE um FROM {$wpdb->usermeta} um
        LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id
        WHERE u.ID IS NULL
    ");

    
    // Get all public taxonomies
    $taxonomies = get_taxonomies(['public' => true]);
    
    // Include product taxonomies
    $product_taxonomies = get_object_taxonomies('product', 'names');
    $taxonomies = array_merge($taxonomies, $product_taxonomies);
    $taxonomies = array_unique($taxonomies);
    
    foreach ($taxonomies as $taxonomy) {
        // Get all terms with our dummy marker
        $dummy_terms = get_terms([
            'taxonomy'     => $taxonomy,
            'meta_key'     => $meta_key,
            'meta_value'   => '1',
            'hide_empty'   => false,
            'fields'       => 'ids',
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
    
    $wpdb->delete(
        $wpdb->comments,
        ['comment_approved' => 'spam'],
        ['%s']
    );
    
    // Delete all transients with 'draxira_' prefix
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s",
            '_transient_draxira_%',
            '_transient_timeout_draxira_%'
        )
    );
    
    // Delete any plugin options (add more if you have them)
    delete_option('draxira_settings');
    delete_option('draxira_version');
    
    
    // Delete any remaining meta with our key (orphaned)
    $wpdb->delete(
        $wpdb->postmeta,
        ['meta_key' => $meta_key],
        ['%s']
    );
    
    $wpdb->delete(
        $wpdb->usermeta,
        ['meta_key' => $meta_key],
        ['%s']
    );
    
    $wpdb->delete(
        $wpdb->termmeta,
        ['meta_key' => $meta_key],
        ['%s']
    );
    
    // We can identify them by checking if the post meta exists or by title pattern
    $dummy_attachments = get_posts([
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'meta_key'       => $meta_key,
        'meta_value'     => '1',
        'fields'         => 'ids',
        'post_status'    => 'inherit',
    ]);
    
    // Also check for images with our naming pattern
    $pattern_attachments = get_posts([
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        's'              => 'dummy_content_filler_img_',
        'fields'         => 'ids',
        'post_status'    => 'inherit',
    ]);
    
    $all_attachments = array_unique(array_merge($dummy_attachments, $pattern_attachments));
    
    if (!empty($all_attachments)) {
        foreach ($all_attachments as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }
    
    // If WooCommerce was active, clean up product-related data
    if (class_exists('WooCommerce')) {
        // Delete product attributes that were created
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if (!empty($attribute_taxonomies)) {
            foreach ($attribute_taxonomies as $attribute) {
                $taxonomy_name = wc_attribute_taxonomy_name($attribute->attribute_name);
                $terms = get_terms([
                    'taxonomy'   => $taxonomy_name,
                    'hide_empty' => false,
                    'fields'     => 'ids',
                ]);
                
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term_id) {
                        wp_delete_term($term_id, $taxonomy_name);
                    }
                }
            }
        }
    }
    
    
    // Delete any cached files in uploads directory
    $upload_dir = wp_upload_dir();
    $cache_dir = $upload_dir['basedir'] . '/draxira-cache';
    
    if (file_exists($cache_dir) && is_dir($cache_dir)) {
        // Recursively delete directory
        function draxira_recursive_delete($dir) {
            if (!is_dir($dir)) {
                return;
            }
            
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    draxira_recursive_delete($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        }
        
        draxira_recursive_delete($cache_dir);
    }
}

// -------------------------------------------------------------------------
// RUN THE CLEANUP
// -------------------------------------------------------------------------

// Check if we're in multisite environment
if (is_multisite()) {
    // Get all blog IDs
    $blog_ids = get_sites(['fields' => 'ids']);
    
    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        draxira_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    draxira_uninstall_cleanup();
}
