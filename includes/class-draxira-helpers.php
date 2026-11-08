<?php
/**
 * Shared helpers for Draxira modules
 *
 * Central place for the Faker data-type list, value generation (with a
 * Faker-less fallback), realistic random dates and the shared admin navigation.
 * New modules (Woo Orders, Media Manager) use this class instead of duplicating
 * the per-module private copies that older modules still carry.
 *
 * @package Draxira
 * @subpackage Includes
 * @since 1.0.7
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Draxira_Helpers
 *
 * @since 1.0.7
 */
class Draxira_Helpers
{
    /**
     * Cached Faker instances keyed by locale.
     *
     * @var array
     */
    private static $fakers = [];

    /**
     * Get a Faker instance (or false when the vendor/ folder is missing).
     *
     * @since 1.0.7
     * @param string $locale Optional Faker locale such as en_IN. Unknown locales fall back to the default.
     * @return Faker\Generator|false
     */
    public static function faker($locale = '')
    {
        if (!class_exists('Faker\Factory')) {
            return false;
        }

        $locale = (string) $locale;
        if ('' !== $locale && !class_exists('Faker\\Provider\\' . $locale . '\\Address')) {
            $locale = '';
        }

        $key = '' === $locale ? 'default' : $locale;
        if (!isset(self::$fakers[$key])) {
            self::$fakers[$key] = '' === $locale ? Faker\Factory::create() : Faker\Factory::create($locale);
        }

        return self::$fakers[$key];
    }

    /**
     * Call a Faker method without ever throwing (some locales lack some formats).
     *
     * @since 1.0.7
     * @param Faker\Generator|false $faker    Faker instance.
     * @param string                $method   Method name.
     * @param array                 $args     Arguments.
     * @param mixed                 $fallback Value returned when Faker is unavailable or fails.
     * @return mixed
     */
    public static function fk($faker, $method, array $args = [], $fallback = '')
    {
        if (!$faker) {
            return $fallback;
        }
        try {
            return call_user_func_array([$faker, $method], $args);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Data types offered in every "Faker Data Type" dropdown of the new modules.
     *
     * @since 1.0.7
     * @return array type => label
     */
    public static function faker_types()
    {
        return [
            'text' => __('Text (Sentence)', 'draxira'),
            'paragraphs' => __('Text (Paragraphs)', 'draxira'),
            'words' => __('Text (Words)', 'draxira'),
            'name' => __('Name', 'draxira'),
            'email' => __('Email', 'draxira'),
            'phone' => __('Phone Number', 'draxira'),
            'address' => __('Address', 'draxira'),
            'city' => __('City', 'draxira'),
            'country' => __('Country', 'draxira'),
            'zipcode' => __('ZIP Code', 'draxira'),
            'number' => __('Number (1-100)', 'draxira'),
            'price' => __('Price (10-1000)', 'draxira'),
            'date' => __('Date', 'draxira'),
            'datetime' => __('Date & Time', 'draxira'),
            'boolean' => __('Boolean (Yes/No)', 'draxira'),
            'url' => __('URL', 'draxira'),
            'image_url' => __('Image URL', 'draxira'),
            'color' => __('Color', 'draxira'),
            'hex_color' => __('Hex Color', 'draxira'),
            'latitude' => __('Latitude', 'draxira'),
            'longitude' => __('Longitude', 'draxira'),
            'company' => __('Company Name', 'draxira'),
            'random' => __('Random Value', 'draxira'),
        ];
    }

    /**
     * Generate a dummy value for a data type. Works with or without Faker.
     *
     * @since 1.0.7
     * @param string                $type  One of faker_types().
     * @param Faker\Generator|false $faker Optional Faker instance.
     * @return string|int|float
     */
    public static function generate_value($type, $faker = null)
    {
        if (null === $faker) {
            $faker = self::faker();
        }

        if ('random' === $type) {
            $pool = ['text', 'words', 'number', 'price', 'date', 'datetime', 'boolean', 'name', 'email', 'url', 'color', 'hex_color', 'company', 'city'];
            $type = $pool[wp_rand(0, count($pool) - 1)];
        }

        if (!$faker) {
            return self::fallback_value($type);
        }

        switch ($type) {
            case 'text':
                return $faker->sentence();
            case 'paragraphs':
                return $faker->paragraphs(3, true);
            case 'words':
                return $faker->words(5, true);
            case 'name':
                return $faker->name();
            case 'email':
                return $faker->safeEmail();
            case 'phone':
                return $faker->phoneNumber();
            case 'address':
                return str_replace("\n", ', ', $faker->address());
            case 'city':
                return $faker->city();
            case 'country':
                return $faker->country();
            case 'zipcode':
                return $faker->postcode();
            case 'number':
                return $faker->numberBetween(1, 100);
            case 'price':
                return $faker->randomFloat(2, 10, 1000);
            case 'date':
                return $faker->date();
            case 'datetime':
                return $faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d H:i:s');
            case 'boolean':
                return $faker->boolean() ? 'yes' : 'no';
            case 'url':
                return $faker->url();
            case 'image_url':
                return $faker->imageUrl();
            case 'color':
                return $faker->colorName();
            case 'hex_color':
                return $faker->hexColor();
            case 'latitude':
                return $faker->latitude();
            case 'longitude':
                return $faker->longitude();
            case 'company':
                return $faker->company();
            default:
                return '';
        }
    }

    /**
     * Basic generators used when Faker is not available.
     *
     * @since 1.0.7
     * @param string $type Data type.
     * @return string|int|float
     */
    private static function fallback_value($type)
    {
        $words = ['alpha', 'bravo', 'canvas', 'delta', 'ember', 'forest', 'garden', 'harbor', 'island', 'jungle', 'kernel', 'lantern', 'meadow', 'nectar', 'orchid', 'prairie', 'quartz', 'river', 'summit', 'tundra'];
        $names = ['Aarav Sharma', 'Emma Wilson', 'Liam Johnson', 'Priya Das', 'Noah Brown', 'Sofia Garcia', 'Arjun Roy', 'Olivia Smith'];
        $cities = ['Springfield', 'Rivertown', 'Lakeside', 'Fairview', 'Kolkata', 'Brookfield'];
        $companies = ['Acme Corp', 'Globex Ltd', 'Initech', 'Umbrella Works', 'Soylent Co'];
        $pick = function (array $list) {
            return $list[wp_rand(0, count($list) - 1)];
        };
        $sentence = function ($n) use ($words, $pick) {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = $pick($words);
            }
            return ucfirst(implode(' ', $out)) . '.';
        };

        switch ($type) {
            case 'text':
                return $sentence(8);
            case 'paragraphs':
                return $sentence(14) . "\n\n" . $sentence(14) . "\n\n" . $sentence(14);
            case 'words':
                return strtolower(rtrim($sentence(5), '.'));
            case 'name':
                return $pick($names);
            case 'email':
                return 'user' . wp_rand(1000, 99999) . '@example.com';
            case 'phone':
                return '+1' . wp_rand(2000000000, 9999999999);
            case 'address':
                return wp_rand(10, 999) . ' ' . ucfirst($pick($words)) . ' Street';
            case 'city':
                return $pick($cities);
            case 'country':
                return 'United States';
            case 'zipcode':
                return (string) wp_rand(10000, 99999);
            case 'number':
                return wp_rand(1, 100);
            case 'price':
                return wp_rand(1000, 100000) / 100;
            case 'date':
                return gmdate('Y-m-d', time() - wp_rand(0, 730) * DAY_IN_SECONDS);
            case 'datetime':
                return gmdate('Y-m-d H:i:s', time() - wp_rand(0, 730 * 86400));
            case 'boolean':
                return wp_rand(0, 1) ? 'yes' : 'no';
            case 'url':
                return 'https://example.com/' . $pick($words);
            case 'image_url':
                return 'https://placehold.co/600x400';
            case 'color':
                return $pick(['red', 'blue', 'green', 'orange', 'purple', 'teal']);
            case 'hex_color':
                return sprintf('#%06x', wp_rand(0, 0xFFFFFF));
            case 'latitude':
                return round(wp_rand(-9000, 9000) / 100, 6);
            case 'longitude':
                return round(wp_rand(-18000, 18000) / 100, 6);
            case 'company':
                return $pick($companies);
            default:
                return '';
        }
    }

    /**
     * Pick a key from a weights array (key => weight).
     *
     * @since 1.0.7
     * @param array $weights Weights.
     * @return mixed Selected key.
     */
    public static function weighted_pick(array $weights)
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            $keys = array_keys($weights);
            return $keys[wp_rand(0, count($keys) - 1)];
        }
        $roll = wp_rand(1, (int) $total);
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }
        $keys = array_keys($weights);
        return end($keys);
    }

    /**
     * Random, realistic timestamp between two calendar days (inclusive).
     *
     * A random day inside the range is chosen first, then a time of day that is
     * weighted towards daytime/evening so generated orders do not look uniform.
     *
     * @since 1.0.7
     * @param string        $from_ymd Start day, Y-m-d.
     * @param string        $to_ymd   End day, Y-m-d.
     * @param DateTimeZone  $tz       Timezone the days are interpreted in.
     * @return int UTC unix timestamp.
     */
    public static function random_timestamp($from_ymd, $to_ymd, $tz = null)
    {
        $tz = $tz ? $tz : new DateTimeZone('UTC');
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $from_ymd, $tz);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $to_ymd, $tz);

        if (!$from || !$to) {
            return time();
        }
        if ($from > $to) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        $days = (int) $from->diff($to)->days;
        $day = $from->modify('+' . wp_rand(0, $days) . ' days');

        $hour_weights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 3, 7 => 4,
            8 => 6, 9 => 8, 10 => 9, 11 => 9, 12 => 9, 13 => 8, 14 => 8, 15 => 8,
            16 => 8, 17 => 8, 18 => 9, 19 => 10, 20 => 10, 21 => 9, 22 => 5, 23 => 2,
        ];
        $hour = (int) self::weighted_pick($hour_weights);

        return $day->setTime($hour, wp_rand(0, 59), wp_rand(0, 59))->getTimestamp();
    }

    /**
     * Pages shown in the shared Draxira navigation.
     *
     * @since 1.0.7
     * @return array slug => label
     */
    public static function get_nav_items()
    {
        $items = [
            'draxira' => __('Post Types', 'draxira'),
            'draxira-users' => __('Users', 'draxira'),
        ];
        if (class_exists('WooCommerce')) {
            $items['draxira-products'] = __('Products', 'draxira');
        }
        $items['draxira-comments'] = __('Comments', 'draxira');
        $items['draxira-taxonomies'] = __('Taxonomies', 'draxira');
        $items['draxira-orders'] = __('Woo Orders', 'draxira');
        $items['draxira-media'] = __('Media Manager', 'draxira');

        return $items;
    }

    /**
     * Print the shared navigation pills used at the top of every Draxira page.
     *
     * @since 1.0.7
     * @param string $current Slug of the current page.
     * @return void
     */
    public static function render_nav($current = 'draxira')
    {
        echo '<nav class="dx-nav" aria-label="' . esc_attr__('Draxira modules', 'draxira') . '">';
        foreach (self::get_nav_items() as $slug => $label) {
            $class = 'dx-nav-link' . ($slug === $current ? ' is-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * Post statuses offered when generating posts, products and custom post types.
     *
     * @since 1.0.8
     * @param bool $include_random Add the "Random mix" choice.
     * @return array status => label
     */
    public static function post_status_choices($include_random = true)
    {
        $choices = [
            'publish' => __('Published', 'draxira'),
            'draft' => __('Draft', 'draxira'),
            'pending' => __('Pending Review', 'draxira'),
            'private' => __('Private', 'draxira'),
            'future' => __('Scheduled (future date)', 'draxira'),
        ];
        if ($include_random) {
            $choices['random'] = __('Random mix of statuses', 'draxira');
        }
        return $choices;
    }

    /**
     * Turn a status choice into wp_insert_post arguments.
     *
     * "future" gets a publish date 1-30 days ahead (required by WordPress), and
     * "random" picks one status per item with realistic weights.
     *
     * @since 1.0.8
     * @param string $choice Status choice from the form.
     * @return array post_status (+ post_date/post_date_gmt for scheduled items)
     */
    public static function resolve_post_status($choice)
    {
        $choice = sanitize_key($choice);
        $valid = array_keys(self::post_status_choices(true));
        if (!in_array($choice, $valid, true)) {
            $choice = 'publish';
        }

        if ('random' === $choice) {
            $choice = self::weighted_pick([
                'publish' => 60,
                'draft' => 15,
                'pending' => 10,
                'private' => 8,
                'future' => 7,
            ]);
        }

        if ('future' === $choice) {
            $ts = time() + wp_rand(1, 30) * DAY_IN_SECONDS + wp_rand(0, DAY_IN_SECONDS - 1);
            return [
                'post_status' => 'future',
                'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts)),
                'post_date_gmt' => gmdate('Y-m-d H:i:s', $ts),
            ];
        }

        return ['post_status' => $choice];
    }

    /**
     * Every post status that should be searched when listing dummy content
     * (draft, pending, private, future, publish, trash ... whatever is registered).
     *
     * @since 1.0.8
     * @return array
     */
    public static function all_post_statuses()
    {
        $statuses = array_values(get_post_stati(['internal' => false]));
        $statuses[] = 'trash';
        return array_values(array_unique($statuses));
    }

    /**
     * Coloured status pill for listing tables.
     *
     * @since 1.0.8
     * @param string $status Post status slug.
     * @return string HTML (escaped)
     */
    public static function status_badge($status)
    {
        $obj = get_post_status_object($status);
        $label = $obj ? $obj->label : ucfirst((string) $status);
        $class = 'is-muted';
        if ('publish' === $status) {
            $class = 'is-good';
        } elseif (in_array($status, ['pending', 'future', 'draft', 'private'], true)) {
            $class = 'is-warn';
        }
        return '<span class="dx-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Last error from the image helpers (for logging / admin messages).
     *
     * @var string
     */
    public static $image_error = '';

    /**
     * List image files in a plugin folder whose name matches a regex.
     * Uses scandir() instead of glob(GLOB_BRACE), which is not available on every server.
     *
     * @param string $dir   Absolute directory path.
     * @param string $regex PCRE matched against the file name.
     * @return array Absolute file paths.
     */
    public static function list_images($dir, $regex = '/\.(jpe?g|png|gif)$/i')
    {
        $found = [];
        if (!is_dir($dir)) {
            self::$image_error = 'Folder not found: ' . $dir;
            return $found;
        }
        $entries = @scandir($dir);
        if (!$entries) {
            self::$image_error = 'Folder not readable: ' . $dir;
            return $found;
        }
        foreach ($entries as $entry) {
            $path = rtrim($dir, '/\\') . '/' . $entry;
            if (is_file($path) && preg_match($regex, $entry)) {
                $found[] = $path;
            }
        }
        if (empty($found)) {
            self::$image_error = 'No matching images in: ' . $dir;
        }
        return $found;
    }

    /**
     * Build a simple generated placeholder JPEG (used only when the bundled
     * images are missing). Returns a temp file path or false.
     *
     * @return string|false
     */
    public static function placeholder_image()
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return false;
        }
        $w = 1200;
        $h = 800;
        $img = imagecreatetruecolor($w, $h);
        if (!$img) {
            return false;
        }
        $r1 = wp_rand(40, 200);
        $g1 = wp_rand(40, 200);
        $b1 = wp_rand(40, 200);
        $r2 = wp_rand(40, 200);
        $g2 = wp_rand(40, 200);
        $b2 = wp_rand(40, 200);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $c = imagecolorallocate(
                $img,
                (int) ($r1 + ($r2 - $r1) * $t),
                (int) ($g1 + ($g2 - $g1) * $t),
                (int) ($b1 + ($b2 - $b1) * $t)
            );
            imageline($img, 0, $y, $w, $y, $c);
        }
        $tmp = wp_tempnam('draxira-placeholder.jpg');
        if (!$tmp) {
            imagedestroy($img);
            return false;
        }
        imagejpeg($img, $tmp, 85);
        imagedestroy($img);
        return $tmp;
    }

    /**
     * Import a local image file into the media library, register it with the
     * Draxira Media Manager and return the attachment ID (0 on failure).
     *
     * @param string $path      Absolute source path.
     * @param int    $parent_id Content the image belongs to.
     * @param string $post_type Post type of that content.
     * @param string $source    Generator name (posts, products).
     * @param string $filename  Optional file name to store (defaults to basename of $path).
     * @return int
     */
    public static function import_image($path, $parent_id = 0, $post_type = '', $source = '', $filename = '')
    {
        $filename = $filename ? $filename : basename($path);
        $data = is_readable($path) ? file_get_contents($path) : false;
        if (false === $data) {
            self::$image_error = 'Cannot read image: ' . $path;
            return 0;
        }

        $upload = wp_upload_bits($filename, null, $data);
        if (!empty($upload['error'])) {
            self::$image_error = 'Upload failed: ' . $upload['error'];
            return 0;
        }

        $type = wp_check_filetype($upload['file'], null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $type['type'] ? $type['type'] : 'image/jpeg',
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file'], (int) $parent_id);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            self::$image_error = 'wp_insert_attachment failed';
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
        Draxira_Media::register($attachment_id, $parent_id, $post_type, $source);

        return (int) $attachment_id;
    }

    /**
     * Pick a random bundled image from a folder (or a generated placeholder
     * when the folder is empty/missing) and import it.
     *
     * @return int Attachment ID, 0 on failure.
     */
    public static function import_random_image($dir, $regex, $parent_id, $post_type, $source)
    {
        $images = self::list_images($dir, $regex);
        if (!empty($images)) {
            return self::import_image($images[array_rand($images)], $parent_id, $post_type, $source);
        }

        $tmp = self::placeholder_image();
        if (!$tmp) {
            return 0;
        }
        $id = self::import_image($tmp, $parent_id, $post_type, $source, 'draxira-placeholder-' . wp_rand(1000, 9999) . '.jpg');
        @unlink($tmp);
        return $id;
    }
}
