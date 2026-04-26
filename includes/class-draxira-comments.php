<?php
/**
 * Comments Dummy Content Generator
 * 
 * Handles generation of dummy comments with various options.
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
 * Class Draxira_Comments
 * 
 * Manages all comment dummy content generation functionality.
 *
 * @since 1.0.0
 * @access public
 */
class Draxira_Comments
{
    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @access private
     * @var Draxira_Comments|null
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
     * Get singleton instance of the class
     *
     * @since 1.0.0
     * @access public
     * @static
     * @return Draxira_Comments Singleton instance
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
        add_action('admin_menu', [$this, 'mc_drax_add_comments_menu'], 11);

        // Handle form submissions
        add_action('admin_init', [$this, 'mc_drax_handle_comments_actions']);

        // AJAX handlers
        add_action('wp_ajax_draxira_get_post_types_for_comments', [$this, 'mc_drax_ajax_get_post_types_for_comments']);
        add_action('wp_ajax_draxira_get_posts_for_comments', [$this, 'mc_drax_ajax_get_posts_for_comments']);
        add_action('wp_ajax_draxira_get_dummy_comments', [$this, 'mc_drax_ajax_get_dummy_comments']);

        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'mc_drax_enqueue_comments_scripts']);
    }

    /**
     * Enqueue comments-specific scripts
     *
     * @since 1.0.0
     * @access public
     * @param string $hook Current admin page hook
     * @return void
     */
    public function mc_drax_enqueue_comments_scripts($hook)
    {
        if ($hook === 'draxira_page_draxira-comments') {
            wp_enqueue_script(
                'draxira-comments',
                DRAXIRA_PLUGIN_URL . 'assets/js/comments.js',
                ['jquery', 'draxira-admin'],
                DRAXIRA_VERSION,
                true
            );

            wp_localize_script('draxira-comments', 'draxira_comments_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('draxira_ajax_nonce'),
                'loading_posts' => __('Loading posts...', 'draxira'),
                'loading_comments' => __('Loading dummy comments...', 'draxira'),
                'error_loading_posts' => __('Error loading posts.', 'draxira'),
                'error_loading_comments' => __('Error loading comments.', 'draxira'),
                'select_post_type_first' => __('Please select a post type first.', 'draxira'),
                'no_posts_found' => __('No posts found for the selected post type.', 'draxira'),
                'confirm_delete_all' => __('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy comments.\nNo backup. No trash. Really sure?', 'draxira'),
            ]);
        }
    }

    /**
     * Add comments submenu
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_add_comments_menu()
    {
        add_submenu_page(
            'draxira',
            'Comments',
            'Comments',
            'manage_options',
            'draxira-comments',
            [$this, 'mc_drax_render_comments_page']
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
     * Get post types that support comments
     *
     * @since 1.0.0
     * @access private
     * @return array Array of post types with labels
     */
    private function mc_drax_get_commentable_post_types()
    {
        $post_types = get_post_types(['public' => true], 'objects');
        $commentable_types = [];

        foreach ($post_types as $post_type => $type_obj) {
            // Exclude attachments and other non-commentable types
            if (in_array($post_type, ['attachment', 'product_variation'])) {
                continue;
            }

            // Check if post type supports comments
            if (post_type_supports($post_type, 'comments')) {
                $commentable_types[$post_type] = $type_obj->label;
            }
        }

        // Sort alphabetically
        asort($commentable_types);

        return $commentable_types;
    }

    /**
     * Get posts for a specific post type
     *
     * @since 1.0.0
     * @access private
     * @param string $post_type Post type slug
     * @return array Array of posts with ID and title
     */
    private function mc_drax_get_posts_for_commenting($post_type = 'post')
    {
        $args = [
            'post_type' => $post_type,
            'posts_per_page' => 200,
            'post_status' => 'publish',
            'fields' => 'ids',
        ];

        $posts = get_posts($args);
        $post_list = [];

        foreach ($posts as $post_id) {
            $post_list[$post_id] = get_the_title($post_id) . ' (ID: ' . $post_id . ')';
        }

        return $post_list;
    }

    /**
     * Get existing users for comment author assignment
     *
     * @since 1.0.0
     * @access private
     * @return array Array of user IDs
     */
    private function mc_drax_get_comment_authors()
    {
        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email'],
            'number' => 20,
        ]);

        $author_list = [];
        foreach ($users as $user) {
            $author_list[$user->ID] = $user->display_name . ' (' . $user->user_email . ')';
        }

        return $author_list;
    }

    /**
     * Handle comment form submissions
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_handle_comments_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['generate_comments']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'generate_dummy_comments')) {
            $this->mc_drax_generate_dummy_comments();
        }

        $clear_comments_nonce_action = 'clear_dummy_comments';
        if (isset($_REQUEST['clear_dummy_comments']) && isset($_REQUEST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), $clear_comments_nonce_action)) {
            $deleted_count = $this->mc_drax_clear_dummy_comments();

            set_transient('draxira_comments_results', [
                'message' => sprintf(
                    /* translators: %1$d: number of deleted comments */
                    __('Successfully deleted %1$d dummy comments (and associated data).', 'draxira'),
                    $deleted_count
                ),
                'type' => 'success'
            ], 45);

            wp_safe_redirect(admin_url('admin.php?page=draxira-comments'));
            exit;
        }
    }

    /**
     * Generate dummy comments based on form submission
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function mc_drax_generate_dummy_comments()
    {
        $comment_type = sanitize_text_field($_POST['comment_type'] ?? 'standard');
        $comments_per_post = intval($_POST['comments_per_post'] ?? 3);
        $comments_per_post = min($comments_per_post, 20); // Limit to 20 per post

        $selected_posts = [];
        if (isset($_POST['selected_posts']) && is_array($_POST['selected_posts'])) {
            $selected_posts = array_map('intval', $_POST['selected_posts']);
        }

        if (empty($selected_posts)) {
            set_transient('draxira_comments_results', [
                'message' => __('Please select at least one post to generate comments.', 'draxira'),
                'type' => 'error'
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=draxira-comments'));
            exit;
        }

        $results = ['success' => 0, 'failed' => 0];
        $faker = $this->mc_drax_get_faker();
        $authors = $this->mc_drax_get_comment_authors();
        $author_ids = array_keys($authors);

        foreach ($selected_posts as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            // Check if comments are open for this post
            if (!comments_open($post_id) && $comment_type !== 'closed') {
                // Force open comments temporarily if needed
                add_filter('comments_open', '__return_true', 10, 2);
            }

            for ($i = 0; $i < $comments_per_post; $i++) {
                $comment_data = $this->mc_drax_create_dummy_comment($post_id, $comment_type, $author_ids, $faker);

                if ($comment_data && !is_wp_error($comment_data)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            }

            // Restore comments open status
            if (!comments_open($post_id) && $comment_type !== 'closed') {
                remove_filter('comments_open', '__return_true', 10);
            }
        }

        set_transient('draxira_comments_results', [
            'message' => sprintf(
                /* translators: %1$d: number of generated comments, %2$d: number of failures */
                __('Successfully generated %1$d comments. Failed: %2$d', 'draxira'),
                $results['success'],
                $results['failed']
            ),
            'type' => 'success'
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=draxira-comments'));
        exit;
    }

    /**
     * Create a single dummy comment
     *
     * @since 1.0.0
     * @access private
     * @param int     $post_id      Post ID
     * @param string  $comment_type Comment type (standard, reply)
     * @param array   $author_ids   Available author IDs
     * @param object  $faker        Faker instance
     * @return int|false Comment ID on success, false on failure
     */
    private function mc_drax_create_dummy_comment($post_id, $comment_type, $author_ids, $faker)
    {
        // Randomly select an author
        $author_id = !empty($author_ids) ? $author_ids[array_rand($author_ids)] : 0;

        // Get author info if available
        $author_name = '';
        $author_email = '';

        if ($author_id > 0) {
            $author_user = get_user_by('id', $author_id);
            if ($author_user) {
                $author_name = $author_user->display_name;
                $author_email = $author_user->user_email;
            }
        }

        // Fallback to faker or default values
        if (empty($author_name)) {
            $author_name = $faker ? $faker->name() : 'Dummy Commenter ' . wp_rand(100, 999);
        }

        if (empty($author_email)) {
            $author_email = $faker ? $faker->email() : 'dummy' . wp_rand(100, 999) . '@example.com';
        }

        // Generate comment content
        $comment_content = $this->mc_drax_generate_comment_content($faker);

        // Prepare comment data
        $comment_data = [
            'comment_post_ID' => $post_id,
            'comment_author' => $author_name,
            'comment_author_email' => $author_email,
            'comment_author_url' => $faker ? $faker->url() : '',
            'comment_content' => $comment_content,
            'comment_type' => 'comment',
            'comment_approved' => 1,
            'user_id' => $author_id,
            'comment_date' => current_time('mysql'),
            'comment_date_gmt' => current_time('mysql', 1),
        ];

        // For reply comments, randomly select a parent comment
        if ($comment_type === 'reply') {
            $parent_comments = get_comments([
                'post_id' => $post_id,
                'number' => 5,
                'status' => 'approve',
            ]);

            if (!empty($parent_comments)) {
                $random_parent = $parent_comments[array_rand($parent_comments)];
                $comment_data['comment_parent'] = $random_parent->comment_ID;
            }
        }

        $comment_id = wp_insert_comment($comment_data);

        if ($comment_id && !is_wp_error($comment_id)) {
            // Mark as dummy comment
            update_comment_meta($comment_id, DRAXIRA_META_KEY, '1');
            return $comment_id;
        }

        return false;
    }

    /**
     * Generate comment content using faker or fallback
     *
     * @since 1.0.0
     * @access private
     * @param object $faker Faker instance
     * @return string Generated comment content
     */
    private function mc_drax_generate_comment_content($faker)
    {
        if ($faker) {
            // Randomly choose between different content types
            $content_type = wp_rand(1, 3);

            switch ($content_type) {
                case 1:
                    return $faker->sentence(wp_rand(5, 15));
                case 2:
                    return $faker->paragraph(wp_rand(1, 3));
                case 3:
                    return $faker->text(200);
                default:
                    return $faker->sentence(10);
            }
        }

        // Fallback content
        $fallback_comments = [
            'Great post! Thanks for sharing.',
            'Very informative article. I learned a lot.',
            'I have a question about this topic. Can you clarify?',
            'This is exactly what I was looking for.',
            'Interesting perspective. Thanks for writing this.',
            'I disagree with some points, but overall good content.',
            'Bookmarked this for future reference.',
            'Thanks for the detailed explanation.',
            'Looking forward to more posts like this.',
            'This helped me solve my problem. Thank you!',
        ];

        return $fallback_comments[array_rand($fallback_comments)];
    }

    /**
     * Delete dummy comments and their associated meta data
     *
     * @since 1.0.0
     * @access private
     * @return int Number of deleted comments
     */
    private function mc_drax_clear_dummy_comments()
    {
        global $wpdb;

        $dummy_comments = get_comments([
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
            'number' => 0, // Get all
        ]);

        $deleted_count = 0;

        foreach ($dummy_comments as $comment_id) {
            // Delete comment meta first
            $wpdb->delete(
                $wpdb->commentmeta,
                ['comment_id' => $comment_id],
                ['%d']
            );

            // Delete the comment
            if (wp_delete_comment($comment_id, true)) {
                $deleted_count++;
            }
        }

        return $deleted_count;
    }

    /**
     * AJAX handler for getting post types for comments
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_post_types_for_comments()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $post_types = $this->mc_drax_get_commentable_post_types();

        ob_start();
        ?>
        <select name="comment_post_type" id="comment-post-type" style="min-width: 200px;">
            <option value=""><?php esc_html_e('-- Select Post Type --', 'draxira'); ?></option>
            <?php foreach ($post_types as $post_type => $label): ?>
                <option value="<?php echo esc_attr($post_type); ?>">
                    <?php echo esc_html($label); ?> (<?php echo esc_html($post_type); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php
        $output = ob_get_clean();

        wp_send_json_success($output);
    }

    /**
     * AJAX handler for getting posts for comments
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_posts_for_comments()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_die('Unauthorized');
        }

        $post_type = sanitize_text_field($_POST['post_type'] ?? '');

        if (empty($post_type)) {
            wp_send_json_error(__('No post type selected.', 'draxira'));
        }

        $posts = $this->mc_drax_get_posts_for_commenting($post_type);

        if (empty($posts)) {
            wp_send_json_error(__('No posts found for the selected post type.', 'draxira'));
        }

        ob_start();
        ?>
        <div class="posts-selection-wrapper">
            <p class="description"><?php esc_html_e('Select the posts where you want to generate comments:', 'draxira'); ?></p>
            <div class="posts-list">
                <div class="select-all-wrapper" style="margin-bottom: 10px;">
                    <label>
                        <input type="checkbox" id="select-all-posts" class="select-all-posts">
                        <?php esc_html_e('Select All Posts', 'draxira'); ?>
                    </label>
                </div>
                <div class="posts-checkboxes"
                    style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                    <?php foreach ($posts as $post_id => $post_title): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="selected_posts[]" value="<?php echo esc_attr($post_id); ?>"
                                class="post-checkbox">
                            <?php echo esc_html($post_title); ?>
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
     * AJAX handler for getting dummy comments list
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_ajax_get_dummy_comments()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'draxira_ajax_nonce')) {
            wp_send_json_error(__('Security check failed.', 'draxira'));
            wp_die();
        }

        $args = [
            'meta_key' => DRAXIRA_META_KEY,
            'meta_value' => '1',
            'number' => 100,
            'status' => 'approve',
        ];

        $dummy_comments = get_comments($args);

        if (empty($dummy_comments)) {
            wp_send_json_error(__('No dummy comments found.', 'draxira'));
        }

        ob_start();
        ?>
        <p><?php printf(esc_html__('Found %d dummy comments.', 'draxira'), count($dummy_comments)); ?></p>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'draxira'); ?></th>
                    <th><?php esc_html_e('Author', 'draxira'); ?></th>
                    <th><?php esc_html_e('Comment', 'draxira'); ?></th>
                    <th><?php esc_html_e('Post', 'draxira'); ?></th>
                    <th><?php esc_html_e('Date', 'draxira'); ?></th>
                    <th><?php esc_html_e('Actions', 'draxira'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dummy_comments as $comment):
                    $post = get_post($comment->comment_post_ID);
                    $post_title = $post ? $post->post_title : __('(Deleted Post)', 'draxira');
                    ?>
                    <tr>
                        <td><?php echo esc_html($comment->comment_ID); ?></td>
                        <td>
                            <?php if ($comment->user_id > 0): ?>
                                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $comment->user_id)); ?>">
                                    <?php echo esc_html($comment->comment_author); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($comment->comment_author); ?>
                            <?php endif; ?>
                            <br>
                            <small><?php echo esc_html($comment->comment_author_email); ?></small>
                        </td>
                        <td>
                            <?php echo esc_html(wp_trim_words($comment->comment_content, 20, '...')); ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(get_permalink($comment->comment_post_ID)); ?>" target="_blank">
                                <?php echo esc_html($post_title); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html(get_comment_date('Y-m-d H:i', $comment)); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('comment.php?action=editcomment&c=' . $comment->comment_ID)); ?>"
                                class="button button-small"><?php esc_html_e('Edit', 'draxira'); ?></a>
                            <a href="<?php echo esc_url(get_comment_link($comment)); ?>" class="button button-small"
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
     * Render comments page
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function mc_drax_render_comments_page()
    {
        ?>
        <div class="wrap draxira">
            <h1><?php esc_html_e('Draxira – Dummy Content Generator - Comments', 'draxira'); ?></h1>

            <?php
            $results = get_transient('draxira_comments_results');
            if ($results) {
                delete_transient('draxira_comments_results');
                $notice_class = isset($results['type']) && $results['type'] === 'error' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($results['message']) . '</p></div>';
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="#generate-comments-tab"
                    class="nav-tab nav-tab-active"><?php esc_html_e('Generate Comments', 'draxira'); ?></a>
                <a href="#delete-comments-tab" class="nav-tab"><?php esc_html_e('Delete Dummy Comments', 'draxira'); ?></a>
            </h2>

            <!-- Generate Comments Tab -->
            <div id="generate-comments-tab" class="tab-content active">
                <form method="post" action="" id="generate-comments-form">
                    <?php wp_nonce_field('generate_dummy_comments'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Select Post Type', 'draxira'); ?></th>
                            <td>
                                <div id="post-type-selector-wrapper">
                                    <?php
                                    $commentable_types = $this->mc_drax_get_commentable_post_types();
                                    if (!empty($commentable_types)):
                                        ?>
                                        <select name="comment_post_type" id="comment-post-type" style="min-width: 200px;">
                                            <option value=""><?php esc_html_e('-- Select Post Type --', 'draxira'); ?></option>
                                            <?php foreach ($commentable_types as $post_type => $label): ?>
                                                <option value="<?php echo esc_attr($post_type); ?>">
                                                    <?php echo esc_html($label); ?> (<?php echo esc_html($post_type); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <p class="description"><?php esc_html_e('No commentable post types found.', 'draxira'); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Select Posts', 'draxira'); ?></th>
                            <td>
                                <div id="posts-list-wrapper">
                                    <p class="description">
                                        <?php esc_html_e('Select a post type above to load posts.', 'draxira'); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Comments Type', 'draxira'); ?></th>
                            <td>
                                <select name="comment_type" id="comment-type">
                                    <option value="standard"><?php esc_html_e('Standard Comments (Top Level)', 'draxira'); ?>
                                    </option>
                                    <option value="reply"><?php esc_html_e('Include Reply Comments', 'draxira'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('"Include Reply Comments" will also add nested replies to existing comments.', 'draxira'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Comments Per Post', 'draxira'); ?></th>
                            <td>
                                <input type="number" name="comments_per_post" min="1" max="20" value="3" style="width: 100px;">
                                <p class="description"><?php esc_html_e('Maximum 20 comments per post.', 'draxira'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="generate_comments" class="button button-primary"
                            value="<?php esc_attr_e('Generate Comments', 'draxira'); ?>">
                    </p>
                </form>
            </div>

            <!-- Delete Dummy Comments Tab -->
            <div id="delete-comments-tab" class="tab-content">
                <h3><?php esc_html_e('Dummy Comments Created by Plugin', 'draxira'); ?></h3>

                <div class="filter-section">
                    <button type="button" id="load-dummy-comments" class="button">
                        <?php esc_html_e('Load Dummy Comments', 'draxira'); ?>
                    </button>
                </div>

                <div id="dummy-comments-list">
                    <p><?php esc_html_e('Click "Load Dummy Comments" to see dummy comments.', 'draxira'); ?></p>
                </div>

                <div id="delete-section"
                    style="display:none; margin-top: 30px; padding: 20px; background: #fff5f5; border: 1px solid #ffb3b3; border-radius: 6px;">
                    <h4 style="color:#d63638; margin-top:0;"><?php esc_html_e('Delete Dummy Comments', 'draxira'); ?></h4>
                    <p class="description"><strong><?php esc_html_e('Warning:', 'draxira'); ?></strong>
                        <?php esc_html_e('This will permanently delete ALL dummy comments. This cannot be undone.', 'draxira'); ?>
                    </p>

                    <form method="post" action="">
                        <?php wp_nonce_field('clear_dummy_comments', '_wpnonce'); ?>
                        <input type="hidden" name="clear_dummy_comments" value="1">

                        <p style="margin-top:20px;">
                            <input type="submit" class="button button-large button-link-delete"
                                value="<?php esc_attr_e('Delete All Dummy Comments', 'draxira'); ?>"
                                onclick="return confirm('<?php echo esc_js(__('FINAL WARNING!\n\nThis will PERMANENTLY DELETE all dummy comments.\nNo backup. No trash. Really sure?', 'draxira')); ?>');">
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}