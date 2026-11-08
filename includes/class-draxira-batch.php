<?php
/**
 * Draxira background batch engine
 *
 * Runs every generator (posts, products, users, comments, taxonomies, orders)
 * in small AJAX batches so a large run never hits the PHP time limit and the
 * admin sees a live progress bar. Each module registers three callbacks:
 *
 *  - prepare( array $data )            : validate the submitted form and return
 *                                        [ 'total' => int, 'config' => array, 'state' => array, 'label' => string ]
 *                                        or WP_Error.
 *  - step( array &$job, int $index )   : create ONE unit of work. Returns bool,
 *                                        or [ 'success' => n, 'failed' => n ].
 *  - finish( array $job )              : returns the final notice message
 *                                        (string) or [ 'message' => ..., 'type' => ... ].
 *
 * The same callbacks power the plain (non-JavaScript) form post fallback via
 * run_sync(), so there is a single code path for both.
 *
 * @package Draxira
 * @subpackage Includes
 * @since 1.0.8
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Draxira_Batch
 *
 * @since 1.0.8
 */
class Draxira_Batch
{
    /**
     * Seconds a single AJAX request may spend creating items.
     */
    const TIME_BUDGET = 4;

    /**
     * Hard cap of units processed in one AJAX request.
     */
    const MAX_PER_REQUEST = 25;

    /**
     * Registered modules.
     *
     * @var array
     */
    private static $modules = [];

    /**
     * @var Draxira_Batch|null
     */
    private static $instance = null;

    /**
     * @return Draxira_Batch
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_draxira_batch_start', [$this, 'ajax_start']);
        add_action('wp_ajax_draxira_batch_step', [$this, 'ajax_step']);
        add_action('wp_ajax_draxira_batch_cancel', [$this, 'ajax_cancel']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Register a module.
     *
     * @param string $slug Module slug (posts, products, ...).
     * @param array  $def  prepare / step / finish callables and optional capability.
     */
    public static function register($slug, array $def)
    {
        self::$modules[sanitize_key($slug)] = wp_parse_args($def, [
            'capability' => 'manage_options',
            'prepare' => null,
            'step' => null,
            'finish' => null,
        ]);
    }

    /**
     * Load the progress script on every Draxira page.
     *
     * @param string $hook Admin page hook.
     */
    public function enqueue($hook)
    {
        if (false === strpos((string) $hook, 'draxira')) {
            return;
        }

        wp_enqueue_script(
            'draxira-batch',
            DRAXIRA_PLUGIN_URL . 'assets/js/batch.js',
            ['jquery'],
            DRAXIRA_VERSION,
            true
        );

        wp_localize_script('draxira-batch', 'draxira_batch', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('draxira_ajax_nonce'),
            'i18n' => [
                'preparing' => __('Preparing…', 'draxira'),
                'working' => __('Generating in the background…', 'draxira'),
                'progress' => __('%1$d of %2$d done (%3$d%%)', 'draxira'),
                'ok' => __('Created: %d', 'draxira'),
                'failed' => __('Failed: %d', 'draxira'),
                'cancel' => __('Cancel', 'draxira'),
                'cancelled' => __('Generation cancelled. Items created so far were kept.', 'draxira'),
                'done' => __('Finished', 'draxira'),
                'error' => __('The request failed. Check your connection and try again.', 'draxira'),
                'leave' => __('Generation is still running. Leave this page?', 'draxira'),
                'reload' => __('Reload page', 'draxira'),
                'dismiss' => __('Dismiss', 'draxira'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------
     * Form helpers
     * ---------------------------------------------------------------- */

    /**
     * Rebuild a nested array from [ [name, value], ... ] pairs (serializeArray).
     * Sending pairs as JSON avoids PHP's max_input_vars limit for big forms.
     *
     * @param array $pairs List of [ 'name' => ..., 'value' => ... ].
     * @return array
     */
    public static function pairs_to_array(array $pairs)
    {
        $data = [];

        foreach ($pairs as $pair) {
            if (!is_array($pair) || !isset($pair['name'])) {
                continue;
            }
            $name = (string) $pair['name'];
            $value = isset($pair['value']) ? (string) $pair['value'] : '';

            if (!preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/', $name, $m)) {
                continue;
            }

            $keys = [$m[1]];
            if ('' !== $m[2] && preg_match_all('/\[([^\[\]]*)\]/', $m[2], $mm)) {
                foreach ($mm[1] as $k) {
                    $keys[] = $k;
                }
            }

            $ref = &$data;
            $last = count($keys) - 1;
            foreach ($keys as $i => $key) {
                if ($i === $last) {
                    if ('' === $key) {
                        $ref[] = $value;
                    } else {
                        $ref[$key] = $value;
                    }
                    break;
                }
                if ('' === $key) {
                    $ref[] = [];
                    $key = array_key_last_compat($ref);
                }
                if (!isset($ref[$key]) || !is_array($ref[$key])) {
                    $ref[$key] = [];
                }
                $ref = &$ref[$key];
            }
            unset($ref);
        }

        return $data;
    }

    /* ------------------------------------------------------------------
     * Synchronous fallback (form posted without JavaScript)
     * ---------------------------------------------------------------- */

    /**
     * Run a whole module in one request.
     *
     * @param string $slug Module slug.
     * @param array  $data Unslashed form data.
     * @return array { message, type }
     */
    public static function run_sync($slug, array $data)
    {
        $started = self::start_job($slug, $data);
        if (is_wp_error($started)) {
            return ['message' => $started->get_error_message(), 'type' => 'error'];
        }

        $job = $started['job'];
        $def = self::$modules[$slug];

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        for ($i = 0; $i < $job['total']; $i++) {
            self::run_unit($def, $job, $i);
        }

        return self::finish_job($def, $job);
    }

    /* ------------------------------------------------------------------
     * Core job handling
     * ---------------------------------------------------------------- */

    /**
     * Validate + prepare a job.
     *
     * @param string $slug Module slug.
     * @param array  $data Form data.
     * @return array|WP_Error [ 'job' => array ]
     */
    private static function start_job($slug, array $data)
    {
        $slug = sanitize_key($slug);
        if (empty(self::$modules[$slug]) || !is_callable(self::$modules[$slug]['prepare'])) {
            return new WP_Error('draxira_module', __('Unknown generator.', 'draxira'));
        }

        $def = self::$modules[$slug];
        if (!current_user_can($def['capability'])) {
            return new WP_Error('draxira_cap', __('You do not have permission to do this.', 'draxira'));
        }

        $prepared = call_user_func($def['prepare'], $data);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $total = max(0, (int) ($prepared['total'] ?? 0));
        if ($total < 1) {
            return new WP_Error('draxira_empty', __('Nothing to generate.', 'draxira'));
        }

        $job = [
            'module' => $slug,
            'user' => get_current_user_id(),
            'total' => $total,
            'done' => 0,
            'success' => 0,
            'failed' => 0,
            'config' => isset($prepared['config']) ? $prepared['config'] : [],
            'state' => isset($prepared['state']) ? $prepared['state'] : [],
            'label' => isset($prepared['label']) ? (string) $prepared['label'] : '',
            'started' => time(),
        ];

        return ['job' => $job];
    }

    /**
     * Run one unit and update counters.
     */
    private static function run_unit(array $def, array &$job, $index)
    {
        try {
            $result = call_user_func_array($def['step'], [&$job, $index]);
        } catch (Throwable $e) {
            $result = false;
        }

        if (is_array($result)) {
            $job['success'] += (int) ($result['success'] ?? 0);
            $job['failed'] += (int) ($result['failed'] ?? 0);
        } elseif ($result && !is_wp_error($result)) {
            $job['success']++;
        } else {
            $job['failed']++;
        }

        $job['done']++;
    }

    /**
     * Produce the final message.
     *
     * @return array { message, type }
     */
    private static function finish_job(array $def, array $job)
    {
        $out = is_callable($def['finish']) ? call_user_func($def['finish'], $job) : '';

        if (is_array($out)) {
            return [
                'message' => (string) ($out['message'] ?? ''),
                'type' => (string) ($out['type'] ?? 'success'),
            ];
        }

        return [
            'message' => '' !== $out ? (string) $out : sprintf(
                /* translators: 1: created count, 2: failed count */
                __('Created %1$d items. Failed: %2$d', 'draxira'),
                $job['success'],
                $job['failed']
            ),
            'type' => $job['success'] > 0 || 0 === $job['failed'] ? 'success' : 'error',
        ];
    }

    private static function job_key($id)
    {
        return 'draxira_job_' . preg_replace('/[^a-z0-9]/i', '', (string) $id);
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ---------------------------------------------------------------- */

    private function guard()
    {
        if (!check_ajax_referer('draxira_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'draxira')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'draxira')], 403);
        }
    }

    /**
     * Start a job: validate, store and return the total.
     */
    public function ajax_start()
    {
        $this->guard();

        $module = isset($_POST['module']) ? sanitize_key(wp_unslash($_POST['module'])) : '';
        $raw = isset($_POST['form']) ? wp_unslash($_POST['form']) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded and sanitized per module.
        $pairs = json_decode($raw, true);
        if (!is_array($pairs)) {
            wp_send_json_error(['message' => __('Invalid form data.', 'draxira')]);
        }

        $started = self::start_job($module, self::pairs_to_array($pairs));
        if (is_wp_error($started)) {
            wp_send_json_error(['message' => $started->get_error_message()]);
        }

        $job = $started['job'];
        $id = wp_generate_password(12, false, false);
        set_transient(self::job_key($id), $job, HOUR_IN_SECONDS);

        wp_send_json_success([
            'job' => $id,
            'total' => $job['total'],
            'label' => $job['label'],
        ]);
    }

    /**
     * Process the next slice of a job.
     */
    public function ajax_step()
    {
        $this->guard();

        $id = isset($_POST['job']) ? sanitize_text_field(wp_unslash($_POST['job'])) : '';
        $job = get_transient(self::job_key($id));

        if (!$job || (int) $job['user'] !== get_current_user_id() || empty(self::$modules[$job['module']])) {
            wp_send_json_error(['message' => __('This generation job expired. Start it again.', 'draxira')]);
        }

        $def = self::$modules[$job['module']];

        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        $started = microtime(true);
        $processed = 0;

        while ($job['done'] < $job['total'] && $processed < self::MAX_PER_REQUEST) {
            self::run_unit($def, $job, $job['done']);
            $processed++;

            if ((microtime(true) - $started) >= self::TIME_BUDGET) {
                break;
            }
        }

        $finished = $job['done'] >= $job['total'];
        $response = [
            'done' => $job['done'],
            'total' => $job['total'],
            'success' => $job['success'],
            'failed' => $job['failed'],
            'finished' => $finished,
            'label' => $job['label'],
        ];

        if ($finished) {
            $final = self::finish_job($def, $job);
            $response['message'] = $final['message'];
            $response['type'] = $final['type'];
            delete_transient(self::job_key($id));
        } else {
            set_transient(self::job_key($id), $job, HOUR_IN_SECONDS);
        }

        wp_send_json_success($response);
    }

    /**
     * Cancel a running job (keeps whatever was created).
     */
    public function ajax_cancel()
    {
        $this->guard();
        $id = isset($_POST['job']) ? sanitize_text_field(wp_unslash($_POST['job'])) : '';
        delete_transient(self::job_key($id));
        wp_send_json_success();
    }
}

if (!function_exists('array_key_last_compat')) {
    /**
     * array_key_last() polyfill for PHP < 7.3.
     *
     * @param array $arr Array.
     * @return mixed
     */
    function array_key_last_compat(array $arr)
    {
        end($arr);
        return key($arr);
    }
}
