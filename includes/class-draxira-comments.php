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

        // Background (AJAX batch) generation
        Draxira_Batch::register('comments', [
            'prepare' => [$this, 'batch_prepare'],
            'step' => [$this, 'batch_step'],
            'finish' => [$this, 'batch_finish'],
        ]);
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
        // Fallback for browsers without JavaScript: run the whole job in this request.
        $result = Draxira_Batch::run_sync('comments', wp_unslash($_POST)); // phpcs:ignore WordPress.Security.NonceVerification -- verified in mc_drax_handle_comments_actions().

        set_transient('draxira_comments_results', $result, 30);

        wp_safe_redirect(admin_url('admin.php?page=draxira-comments'));
        exit;
    }

    /**
     * Comment status choices (value => label).
     *
     * @since 1.0.8
     * @return array
     */
    public static function status_choices()
    {
        return [
            'approved' => __('Approved', 'draxira'),
            'pending' => __('Pending (awaiting moderation)', 'draxira'),
            'spam' => __('Spam', 'draxira'),
            'trash' => __('Trash', 'draxira'),
            'random' => __('Random mix of statuses', 'draxira'),
        ];
    }

    /**
     * Resolve a status choice to the value wp_insert_comment() expects.
     *
     * @since 1.0.8
     * @param string $choice Status choice.
     * @return string 1|0|spam|trash
     */
    private function mc_drax_resolve_comment_status($choice)
    {
        if ('random' === $choice) {
            $choice = Draxira_Helpers::weighted_pick([
                'approved' => 65,
                'pending' => 20,
                'spam' => 10,
                'trash' => 5,
            ]);
        }

        $map = ['approved' => '1', 'pending' => '0', 'spam' => 'spam', 'trash' => 'trash'];
        return isset($map[$choice]) ? $map[$choice] : '1';
    }

    /**
     * Batch: validate the form.
     *
     * @since 1.0.8
     * @param array $data Unslashed form data.
     * @return array|WP_Error
     */
    public function batch_prepare($data)
    {
        $posts = [];
        if (isset($data['selected_posts']) && is_array($data['selected_posts'])) {
            $posts = array_values(array_unique(array_filter(array_map('intval', $data['selected_posts']))));
        }

        if (empty($posts)) {
            return new WP_Error('draxira_posts', __('Please select at least one post to generate comments.', 'draxira'));
        }

        $per_post = max(1, min(intval($data['comments_per_post'] ?? 3), 20));
        $type = in_array(($data['comment_type'] ?? 'standard'), ['standard', 'reply'], true) ? $data['comment_type'] : 'standard';
        $status = isset($data['comment_status'], self::status_choices()[$data['comment_status']]) ? $data['comment_status'] : 'approved';

        return [
            'total' => count($posts) * $per_post,
            'label' => __('Generating comments…', 'draxira'),
            'config' => [
                'posts' => $posts,
                'per_post' => $per_post,
                'type' => $type,
                'status' => $status,
            ],
            'state' => [],
        ];
    }

    /**
     * Batch: create one comment.
     *
     * @since 1.0.8
     * @param array $job   Job (by reference).
     * @param int   $index Unit index.
     * @return bool
     */
    public function batch_step(&$job, $index)
    {
        $c = $job['config'];
        $post_id = $c['posts'][intdiv($index, $c['per_post'])] ?? 0;
        if (!$post_id || !get_post($post_id)) {
            return false;
        }

        $author_ids = array_keys($this->mc_drax_get_comment_authors());
        $comment_id = $this->mc_drax_create_dummy_comment(
            $post_id,
            $c['type'],
            $author_ids,
            $this->mc_drax_get_faker(),
            $c['status'],
            $index % $c['per_post']
        );

        return $comment_id && !is_wp_error($comment_id);
    }

    /**
     * Batch: final notice.
     *
     * @since 1.0.8
     * @param array $job Job.
     * @return array
     */
    public function batch_finish($job)
    {
        return [
            'message' => sprintf(
                /* translators: %1$d: number of generated comments, %2$d: number of failures */
                __('Successfully generated %1$d comments. Failed: %2$d', 'draxira'),
                $job['success'],
                $job['failed']
            ),
            'type' => 'success',
        ];
    }

    /**
     * Depth of a comment inside its thread (top level = 1).
     *
     * @since 1.0.8
     * @param WP_Comment $comment Comment.
     * @return int
     */
    private function mc_drax_comment_depth($comment)
    {
        $depth = 1;
        $guard = 0;
        $parent_id = (int) $comment->comment_parent;
        while ($parent_id && $guard < 50) {
            $parent = get_comment($parent_id);
            if (!$parent) {
                break;
            }
            $depth++;
            $parent_id = (int) $parent->comment_parent;
            $guard++;
        }
        return $depth;
    }

    /**
     * Pick the comment a new reply should answer. Any approved comment of the post can be
     * chosen - including other replies - so threads grow several levels deep, as long as the
     * site's "nested comments" depth limit is respected.
     *
     * @since 1.0.8
     * @param int $post_id Post ID.
     * @return WP_Comment|null
     */
    private function mc_drax_pick_parent_comment($post_id)
    {
        $candidates = get_comments([
            'post_id' => $post_id,
            'status' => 'approve',
            'number' => 100,
            'orderby' => 'comment_ID',
            'order' => 'DESC',
        ]);

        if (empty($candidates)) {
            return null;
        }

        $max_depth = get_option('thread_comments') ? max(2, (int) get_option('thread_comments_depth', 5)) : 5;

        $eligible = [];
        foreach ($candidates as $candidate) {
            if ($this->mc_drax_comment_depth($candidate) < $max_depth) {
                $eligible[] = $candidate;
            }
        }

        return $eligible ? $eligible[array_rand($eligible)] : null;
    }

    /**
     * Create a single dummy comment
     *
     * @since 1.0.0
     * @access private
     * @param int     $post_id       Post ID
     * @param string  $comment_type  Comment type (standard, reply)
     * @param array   $author_ids    Available author IDs
     * @param object  $faker         Faker instance
     * @param string  $status_choice approved|pending|spam|trash|random
     * @param int     $position      Position of this comment within its post (0 = first)
     * @return int|false Comment ID on success, false on failure
     */
    private function mc_drax_create_dummy_comment($post_id, $comment_type, $author_ids, $faker, $status_choice = 'approved', $position = 0)
    {
        $post = get_post($post_id);
        $is_product = ($post && $post->post_type === 'product');

        // Reply mode: ~75% of comments answer an existing comment or reply (the first comment on an empty post is always top level).
        $parent = null;
        if ($comment_type === 'reply' && !$is_product && wp_rand(1, 4) > 1) {
            $parent = $this->mc_drax_pick_parent_comment($post_id);
        }

        // Author: mostly registered users, sometimes guests. A reply is written by someone other than the author of
        // the comment it answers (when another candidate exists).
        $parent_user = $parent ? (int) $parent->user_id : 0;
        $pool = array_values(array_diff($author_ids, $parent_user ? [$parent_user] : []));
        $author_id = (!empty($pool) && wp_rand(1, 10) > 3) ? $pool[array_rand($pool)] : 0;

        $author_name = '';
        $author_email = '';

        if ($author_id > 0) {
            $author_user = get_user_by('id', $author_id);
            if ($author_user) {
                $author_name = $author_user->display_name;
                $author_email = $author_user->user_email;
            } else {
                $author_id = 0;
            }
        }

        if (empty($author_name)) {
            $author_name = $faker ? $faker->name() : 'Dummy Reviewer ' . wp_rand(100, 999);
        }
        if (empty($author_email)) {
            $author_email = $faker ? $faker->email() : 'dummy' . wp_rand(100, 999) . '@example.com';
        }

        // Guests must differ from the parent's author too.
        if ($parent && !$author_id && strtolower($author_email) === strtolower($parent->comment_author_email)) {
            $author_email = 'guest' . wp_rand(100, 9999) . '@example.com';
        }

        $comment_content = $this->mc_drax_generate_comment_content($faker);

        $comment_data = [
            'comment_post_ID'    => $post_id,
            'comment_author'     => $author_name,
            'comment_author_email' => $author_email,
            'comment_author_url' => $faker ? $faker->url() : '',
            'comment_content'    => $comment_content,
            'comment_type'       => $is_product ? 'review' : 'comment',
            'comment_approved'   => $this->mc_drax_resolve_comment_status($status_choice),
            'user_id'            => $author_id,
            'comment_date'       => current_time('mysql'),
            'comment_date_gmt'   => current_time('mysql', 1),
        ];

        if ($parent) {
            $comment_data['comment_parent'] = (int) $parent->comment_ID;
        }

        $comment_id = wp_insert_comment($comment_data);

        if ($comment_id && !is_wp_error($comment_id)) {
            update_comment_meta($comment_id, DRAXIRA_META_KEY, '1');

            if ($is_product) {
                $rating = $faker ? $faker->numberBetween(3, 5) : wp_rand(3, 5);

                update_comment_meta($comment_id, 'rating', $rating);
                update_comment_meta($comment_id, '_wc_product_review', '1');
                update_comment_meta($comment_id, 'verified', 1);

                // Update product average rating
                if (function_exists('wc_update_product_lookup_tables')) {
                    wc_update_product_lookup_tables($post_id);
                }
            }

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
            'type__in' => ['comment', 'review'],
            'status' => ['approve', 'hold', 'spam', 'trash'],
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
            'number' => 20000,
            'type__in' => ['comment', 'review'],
            // Every status: approved, pending, spam and trash.
            'status' => ['approve', 'hold', 'spam', 'trash'],
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
                    <th><?php esc_html_e('Status', 'draxira'); ?></th>
                    <th><?php esc_html_e('Reply to', 'draxira'); ?></th>
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
                        <td>
                            <?php
                            $c_status = wp_get_comment_status($comment);
                            $c_labels = ['approved' => __('Approved', 'draxira'), 'unapproved' => __('Pending', 'draxira'), 'spam' => __('Spam', 'draxira'), 'trash' => __('Trash', 'draxira')];
                            $c_class = 'approved' === $c_status ? 'is-good' : ('unapproved' === $c_status ? 'is-warn' : 'is-muted');
                            echo '<span class="dx-badge ' . esc_attr($c_class) . '">' . esc_html($c_labels[$c_status] ?? ucfirst((string) $c_status)) . '</span>';
                            ?>
                        </td>
                        <td>
                            <?php echo $comment->comment_parent ? '#' . esc_html($comment->comment_parent) : '&mdash;'; ?>
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
            <?php Draxira_Helpers::render_nav('draxira-comments'); ?>

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
                <form method="post" action="" id="generate-comments-form" data-dx-batch="comments">
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
                                    <?php esc_html_e('"Include Reply Comments" adds replies to existing comments and replies to other replies (threads up to your Settings → Discussion depth), written by a different author than the comment they answer.', 'draxira'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Comment Status', 'draxira'); ?></th>
                            <td>
                                <select name="comment_status" id="comment-status">
                                    <?php foreach (self::status_choices() as $status_value => $status_label): ?>
                                        <option value="<?php echo esc_attr($status_value); ?>"><?php echo esc_html($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Pending comments appear in Comments → Pending for moderation. Replies are always attached to approved comments.', 'draxira'); ?>
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
