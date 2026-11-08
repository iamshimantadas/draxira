<?php
/**
 * Draxira custom-field intelligence
 *
 * 1. Detects the real type (text, image, gallery, repeater, ...) of every meta key
 *    shown in the generator "Meta Fields" tables (ACF, CMB2, or a name based guess
 *    for plain custom meta) so the tables can show a "Field Type" column.
 * 2. Provides several fill approaches for complex Advanced Custom Fields types
 *    (gallery, image, file, repeater, group, flexible content, post object,
 *    relationship, page link, taxonomy, user, link, choice fields ...) and saves
 *    them through update_field() so ACF's hidden field-key references are written.
 *
 * Selecting a "Smart fill" option posts a value like  acf:rows_some  together with the
 * ACF field key, so no extra lookups are needed when generating.
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
 * Class Draxira_Fields
 *
 * @since 1.0.8
 */
class Draxira_Fields
{
    const PREFIX = 'acf:';

    /**
     * Maximum nesting handled for repeaters/groups.
     */
    const MAX_DEPTH = 3;

    /**
     * Request cache of field indexes.
     *
     * @var array
     */
    private static $cache = [];

    /* ------------------------------------------------------------------
     * Field index (ACF + CMB2)
     * ---------------------------------------------------------------- */

    /**
     * Fields registered for a post type.
     *
     * @param string $post_type Post type.
     * @return array meta_key => descriptor
     */
    public static function index_for_post_type($post_type)
    {
        return self::build_index('post_type:' . $post_type, ['post_type' => $post_type], 'post_type', $post_type);
    }

    /**
     * Fields registered for users.
     *
     * @return array
     */
    public static function index_for_users()
    {
        return self::build_index('user', ['user_form' => 'all'], 'user', '');
    }

    /**
     * Fields registered for a taxonomy.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return array
     */
    public static function index_for_taxonomy($taxonomy)
    {
        return self::build_index('taxonomy:' . $taxonomy, ['taxonomy' => $taxonomy], 'term', $taxonomy);
    }

    /**
     * @param string $cache_key  Cache key.
     * @param array  $acf_args   acf_get_field_groups() location args.
     * @param string $cmb_kind   post_type|user|term.
     * @param string $cmb_target Post type or taxonomy to match for CMB2.
     * @return array
     */
    private static function build_index($cache_key, array $acf_args, $cmb_kind, $cmb_target)
    {
        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $index = [];

        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            foreach ((array) acf_get_field_groups($acf_args) as $group) {
                $fields = acf_get_fields($group['key']);
                if (!$fields) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (empty($field['name'])) {
                        continue;
                    }
                    $index[$field['name']] = [
                        'source' => 'acf',
                        'key' => isset($field['key']) ? $field['key'] : '',
                        'type' => isset($field['type']) ? $field['type'] : 'text',
                        'label' => isset($field['label']) ? $field['label'] : $field['name'],
                    ];
                }
            }
        }

        if (class_exists('CMB2_Boxes')) {
            foreach (CMB2_Boxes::get_all() as $cmb) {
                $object_types = (array) $cmb->prop('object_types');
                $match = false;
                if ('post_type' === $cmb_kind) {
                    $match = in_array($cmb_target, $object_types, true);
                } elseif ('user' === $cmb_kind) {
                    $match = in_array('user', $object_types, true);
                } elseif ('term' === $cmb_kind) {
                    $match = in_array('term', $object_types, true) && in_array($cmb_target, (array) $cmb->prop('taxonomies'), true);
                }
                if (!$match) {
                    continue;
                }
                foreach ((array) $cmb->prop('fields') as $field) {
                    if (empty($field['id']) || isset($index[$field['id']])) {
                        continue;
                    }
                    $index[$field['id']] = [
                        'source' => 'cmb2',
                        'key' => '',
                        'type' => isset($field['type']) ? $field['type'] : 'text',
                        'label' => isset($field['name']) ? $field['name'] : $field['id'],
                    ];
                }
            }
        }

        self::$cache[$cache_key] = $index;
        return $index;
    }

    /* ------------------------------------------------------------------
     * Type names
     * ---------------------------------------------------------------- */

    /**
     * Human label for a field type.
     *
     * @param string $type ACF / CMB2 type slug.
     * @return string
     */
    public static function type_label($type)
    {
        $labels = [
            'text' => __('Text', 'draxira'),
            'textarea' => __('Textarea', 'draxira'),
            'number' => __('Number', 'draxira'),
            'range' => __('Range', 'draxira'),
            'email' => __('Email', 'draxira'),
            'url' => __('URL', 'draxira'),
            'password' => __('Password', 'draxira'),
            'image' => __('Image', 'draxira'),
            'file' => __('File', 'draxira'),
            'gallery' => __('Gallery', 'draxira'),
            'wysiwyg' => __('Rich text editor', 'draxira'),
            'oembed' => __('oEmbed', 'draxira'),
            'select' => __('Select', 'draxira'),
            'checkbox' => __('Checkbox', 'draxira'),
            'radio' => __('Radio', 'draxira'),
            'button_group' => __('Button group', 'draxira'),
            'true_false' => __('True / False', 'draxira'),
            'link' => __('Link', 'draxira'),
            'post_object' => __('Post object', 'draxira'),
            'page_link' => __('Page link', 'draxira'),
            'relationship' => __('Relationship', 'draxira'),
            'taxonomy' => __('Taxonomy', 'draxira'),
            'user' => __('User', 'draxira'),
            'google_map' => __('Google map', 'draxira'),
            'date_picker' => __('Date picker', 'draxira'),
            'date_time_picker' => __('Date time picker', 'draxira'),
            'time_picker' => __('Time picker', 'draxira'),
            'color_picker' => __('Color picker', 'draxira'),
            'group' => __('Group', 'draxira'),
            'repeater' => __('Repeater', 'draxira'),
            'flexible_content' => __('Flexible content', 'draxira'),
            'clone' => __('Clone', 'draxira'),
            // CMB2 names.
            'text_small' => __('Text', 'draxira'),
            'text_medium' => __('Text', 'draxira'),
            'text_url' => __('URL', 'draxira'),
            'text_email' => __('Email', 'draxira'),
            'text_money' => __('Price', 'draxira'),
            'textarea_small' => __('Textarea', 'draxira'),
            'textarea_code' => __('Code', 'draxira'),
            'text_date' => __('Date', 'draxira'),
            'text_date_timestamp' => __('Date', 'draxira'),
            'text_datetime_timestamp' => __('Date time', 'draxira'),
            'text_time' => __('Time', 'draxira'),
            'colorpicker' => __('Color picker', 'draxira'),
            'multicheck' => __('Checkbox list', 'draxira'),
            'file_list' => __('Gallery / file list', 'draxira'),
            'taxonomy_select' => __('Taxonomy', 'draxira'),
            'taxonomy_multicheck' => __('Taxonomy', 'draxira'),
            'custom' => __('Custom meta', 'draxira'),
        ];

        if (isset($labels[$type])) {
            return $labels[$type];
        }

        return ucfirst(str_replace('_', ' ', (string) $type));
    }

    /**
     * Colour family used by the CSS badge.
     *
     * @param string $type Type slug.
     * @return string media|repeater|relation|text
     */
    public static function type_group($type)
    {
        if (in_array($type, ['image', 'gallery', 'file', 'file_list', 'oembed'], true)) {
            return 'media';
        }
        if (in_array($type, ['repeater', 'group', 'flexible_content', 'clone', 'group_cmb2'], true)) {
            return 'repeater';
        }
        if (in_array($type, ['post_object', 'page_link', 'relationship', 'taxonomy', 'user', 'link', 'taxonomy_select', 'taxonomy_multicheck'], true)) {
            return 'relation';
        }
        return 'text';
    }

    /**
     * Guess the nature of a plain custom meta key from its name.
     *
     * @param string $meta_key Meta key.
     * @return string Type slug.
     */
    public static function guess_type($meta_key)
    {
        $k = strtolower((string) $meta_key);

        $rules = [
            'gallery' => '/gallery|images|photos/',
            'image' => '/image|photo|picture|thumb|avatar|logo|banner|cover/',
            'file' => '/(^|_)file|attachment|document|pdf|download/',
            'repeater' => '/repeater|items|rows|list$/',
            'email' => '/email/',
            'url' => '/(^|_)url|website|link|permalink|href/',
            'date_picker' => '/date|birthday|dob/',
            'textarea' => '/description|content|bio|about|summary|excerpt|notes?$|message/',
            'number' => '/price|amount|total|count|qty|quantity|number|age|rating|score|weight|height|width|length|stock/',
            'true_false' => '/^is_|^has_|_enabled$|_active$|featured|show_|hide_/',
        ];

        foreach ($rules as $type => $regex) {
            if (preg_match($regex, $k)) {
                return $type;
            }
        }

        return 'text';
    }

    /**
     * Describe one meta key for the table.
     *
     * @param string $meta_key Meta key.
     * @param array  $index    Result of index_for_*().
     * @return array source, type, label, acf_key
     */
    public static function describe($meta_key, array $index)
    {
        if (isset($index[$meta_key])) {
            $row = $index[$meta_key];
            return [
                'source' => $row['source'],
                'type' => $row['type'],
                'label' => self::type_label($row['type']),
                'acf_key' => 'acf' === $row['source'] ? $row['key'] : '',
                'guessed' => false,
            ];
        }

        $type = self::guess_type($meta_key);
        return [
            'source' => 'db',
            'type' => $type,
            'label' => self::type_label($type),
            'acf_key' => '',
            'guessed' => true,
        ];
    }

    /* ------------------------------------------------------------------
     * Table rendering
     * ---------------------------------------------------------------- */

    /**
     * Header cells for the meta tables (Field, Meta Key, Field Type, Fill With).
     *
     * @param string $key_label  Label for the key column.
     * @param string $fill_label Label for the last column.
     * @return string HTML
     */
    public static function render_head($key_label = '', $fill_label = '')
    {
        $key_label = $key_label ? $key_label : __('Meta Key', 'draxira');
        $fill_label = $fill_label ? $fill_label : __('Fill With', 'draxira');

        return '<thead><tr><th>' . esc_html__('Field Name', 'draxira') . '</th><th>' . esc_html($key_label)
            . '</th><th>' . esc_html__('Field Type', 'draxira') . '</th><th>' . esc_html($fill_label)
            . '</th></tr></thead>';
    }

    /**
     * Badge for the type column.
     *
     * @param array $info describe() result.
     * @return string HTML
     */
    public static function render_badge(array $info)
    {
        $title = '';
        if ('acf' === $info['source']) {
            $title = __('Advanced Custom Fields', 'draxira');
        } elseif ('cmb2' === $info['source']) {
            $title = __('CMB2', 'draxira');
        } else {
            $title = __('Detected from the meta key name', 'draxira');
        }

        return '<span class="dx-type dx-type-' . esc_attr(self::type_group($info['type'])) . '" title="' . esc_attr($title) . '">'
            . esc_html($info['label']) . '</span>'
            . ('acf' === $info['source'] ? ' <small class="dx-type-src">ACF</small>' : '')
            . ('cmb2' === $info['source'] ? ' <small class="dx-type-src">CMB2</small>' : '');
    }

    /**
     * Strategy options for a field type (value => label), all prefixed with "acf:".
     *
     * @param string $type ACF field type.
     * @return array
     */
    public static function strategies($type)
    {
        $s = ['auto' => __('Smart fill (match the field type)', 'draxira')];

        switch ($type) {
            case 'image':
            case 'gallery':
                $s['media_random'] = __('Random images from the media library', 'draxira');
                $s['media_bundled'] = __('Import Draxira sample images (new uploads)', 'draxira');
                $s['media_new'] = __('Generate new placeholder images', 'draxira');
                break;
            case 'file':
                $s['file_library'] = __('Random file from the media library', 'draxira');
                $s['file_new'] = __('Generate a small text file', 'draxira');
                break;
            case 'repeater':
            case 'flexible_content':
                $s['rows_few'] = __('Auto-fill 1–2 rows', 'draxira');
                $s['rows_some'] = __('Auto-fill 3–5 rows', 'draxira');
                $s['rows_many'] = __('Auto-fill 6–10 rows', 'draxira');
                break;
            case 'post_object':
            case 'page_link':
            case 'relationship':
                $s['rel_random'] = __('Random existing content', 'draxira');
                $s['rel_draxira'] = __('Only Draxira-generated content', 'draxira');
                $s['rel_latest'] = __('Latest content', 'draxira');
                break;
            case 'taxonomy':
                $s['term_random'] = __('Random existing terms', 'draxira');
                $s['term_draxira'] = __('Only Draxira-generated terms', 'draxira');
                break;
            case 'user':
                $s['user_random'] = __('Random existing users', 'draxira');
                $s['user_draxira'] = __('Only Draxira-generated users', 'draxira');
                break;
            case 'link':
                $s['link_internal'] = __('Link to a random post or page', 'draxira');
                $s['link_external'] = __('External website link', 'draxira');
                $s['link_mixed'] = __('Mix of internal and external', 'draxira');
                break;
            case 'select':
            case 'checkbox':
            case 'radio':
            case 'button_group':
                $s['choice_random'] = __('Random choice(s) from the field options', 'draxira');
                break;
            case 'true_false':
                $s['bool_true'] = __('Always on', 'draxira');
                $s['bool_false'] = __('Always off', 'draxira');
                break;
        }

        return $s;
    }

    /**
     * The fill-with <select> (plus a hidden ACF field key where relevant).
     *
     * @param string $name        Field base name, e.g. post_meta[price].
     * @param array  $info        describe() result.
     * @param array  $faker_types value => label.
     * @param string $selected    Preselected value.
     * @param string $empty_label Label of the empty option.
     * @return string HTML
     */
    public static function render_select($name, array $info, array $faker_types, $selected = '', $empty_label = '')
    {
        $empty_label = $empty_label ? $empty_label : __('Leave empty', 'draxira');

        $html = '<select name="' . esc_attr($name) . '[type]" class="dx-fill-select">';
        $html .= '<option value="">' . esc_html('— ' . $empty_label . ' —') . '</option>';

        if ('acf' === $info['source'] && $info['acf_key']) {
            $html .= '<optgroup label="' . esc_attr(sprintf(
                /* translators: %s: ACF field type */
                __('Smart fill – %s', 'draxira'),
                $info['label']
            )) . '">';
            foreach (self::strategies($info['type']) as $value => $label) {
                $html .= '<option value="' . esc_attr(self::PREFIX . $value) . '"' . selected($selected, self::PREFIX . $value, false) . '>'
                    . esc_html($label) . '</option>';
            }
            $html .= '</optgroup><optgroup label="' . esc_attr__('Faker data', 'draxira') . '">';
        }

        foreach ($faker_types as $value => $label) {
            if ('' === $value) {
                continue;
            }
            $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }

        if ('acf' === $info['source'] && $info['acf_key']) {
            $html .= '</optgroup>';
        }

        $html .= '</select>';

        if ($info['acf_key']) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '[acf]" value="' . esc_attr($info['acf_key']) . '">';
        }

        return $html;
    }

    /**
     * One complete table row.
     *
     * @param string $prefix      Input name prefix, e.g. post_meta.
     * @param string $meta_key    Meta key.
     * @param string $label       Display label.
     * @param array  $index       Field index for the context.
     * @param array  $faker_types Faker types.
     * @param string $selected    Preselected value.
     * @param string $note        Small helper text below the select.
     * @param string $empty_label Empty option label.
     * @return string HTML
     */
    public static function render_row($prefix, $meta_key, $label, array $index, array $faker_types, $selected = '', $note = '', $empty_label = '')
    {
        $info = self::describe($meta_key, $index);
        $name = $prefix . '[' . $meta_key . ']';

        return '<tr>'
            . '<td><strong>' . esc_html($label) . '</strong></td>'
            . '<td><code>' . esc_html($meta_key) . '</code><input type="hidden" name="' . esc_attr($name) . '[key]" value="' . esc_attr($meta_key) . '"></td>'
            . '<td>' . self::render_badge($info) . '</td>'
            . '<td>' . self::render_select($name, $info, $faker_types, $selected, $empty_label)
            . ($note ? '<br><small class="description">' . esc_html($note) . '</small>' : '')
            . '</td></tr>';
    }

    /**
     * Keep the posted meta configuration (type + optional ACF field key).
     *
     * @param mixed $posted $_POST style array of meta_key => [ type, acf ].
     * @return array
     */
    public static function parse_config($posted)
    {
        $config = [];
        if (!is_array($posted)) {
            return $config;
        }

        foreach ($posted as $meta_key => $row) {
            if (!is_array($row) || empty($row['type'])) {
                continue;
            }
            $entry = ['type' => sanitize_text_field($row['type'])];
            if (!empty($row['acf'])) {
                $entry['acf'] = sanitize_text_field($row['acf']);
            }
            $config[sanitize_text_field($meta_key)] = $entry;
        }

        return $config;
    }

    /* ------------------------------------------------------------------
     * Filling + saving
     * ---------------------------------------------------------------- */

    /**
     * Fill ONE configured meta key on an object.
     *
     * @param array    $object    [ 'type' => post|user|term, 'id' => int, 'post_type' => string ].
     * @param string   $meta_key  Meta key.
     * @param array    $config    [ type, acf ].
     * @param callable $simple_cb Receives a Faker type, returns a value.
     * @return bool True when something was saved.
     */
    public static function fill(array $object, $meta_key, array $config, $simple_cb)
    {
        $type = isset($config['type']) ? (string) $config['type'] : '';
        if ('' === $type) {
            return false;
        }

        $field = null;
        if (!empty($config['acf']) && function_exists('acf_get_field')) {
            $field = acf_get_field($config['acf']);
        }

        // Smart fill for complex ACF types.
        if (0 === strpos($type, self::PREFIX)) {
            if (!$field) {
                return false;
            }
            $value = self::generate($field, substr($type, strlen(self::PREFIX)), $object, 0);
            if (null === $value) {
                return false;
            }
            return self::save_acf($field, $value, $object);
        }

        $value = call_user_func($simple_cb, $type);
        if ('' === $value || null === $value) {
            return false;
        }

        if ($field) {
            return self::save_acf($field, self::coerce($field, $value), $object);
        }

        return self::save_plain($object, $meta_key, $value);
    }

    private static function acf_ref(array $object)
    {
        if ('user' === $object['type']) {
            return 'user_' . (int) $object['id'];
        }
        if ('term' === $object['type']) {
            return 'term_' . (int) $object['id'];
        }
        return (int) $object['id'];
    }

    private static function save_acf(array $field, $value, array $object)
    {
        if (function_exists('update_field')) {
            return (bool) update_field($field['key'], $value, self::acf_ref($object));
        }
        return self::save_plain($object, $field['name'], $value);
    }

    private static function save_plain(array $object, $meta_key, $value)
    {
        if ('user' === $object['type']) {
            return (bool) update_user_meta($object['id'], $meta_key, $value);
        }
        if ('term' === $object['type']) {
            return (bool) update_term_meta($object['id'], $meta_key, $value);
        }
        return (bool) update_post_meta($object['id'], $meta_key, $value);
    }

    /**
     * Make a plain Faker value acceptable for the ACF field it is stored in.
     */
    private static function coerce(array $field, $value)
    {
        switch ($field['type']) {
            case 'true_false':
                return in_array(strtolower((string) $value), ['yes', '1', 'true', 'on'], true) ? 1 : 0;
            case 'date_picker':
                $ts = strtotime((string) $value);
                return $ts ? gmdate('Ymd', $ts) : $value;
            case 'date_time_picker':
                $ts = strtotime((string) $value);
                return $ts ? gmdate('Y-m-d H:i:s', $ts) : $value;
            case 'number':
            case 'range':
                return is_numeric($value) ? $value + 0 : 0;
        }
        return $value;
    }

    /* ------------------------------------------------------------------
     * Value generation per ACF field type
     * ---------------------------------------------------------------- */

    /**
     * Generate a value for an ACF field.
     *
     * @param array  $field    ACF field array.
     * @param string $strategy Strategy slug (without the acf: prefix).
     * @param array  $object   Target object.
     * @param int    $depth    Nesting depth.
     * @return mixed|null Null means "nothing to store".
     */
    public static function generate(array $field, $strategy, array $object, $depth = 0)
    {
        $type = isset($field['type']) ? $field['type'] : 'text';
        $faker = Draxira_Helpers::faker();

        switch ($type) {
            case 'text':
                $len = !empty($field['maxlength']) ? (int) $field['maxlength'] : 60;
                return mb_substr(Draxira_Helpers::generate_value('text', $faker), 0, max(5, $len));
            case 'textarea':
                return Draxira_Helpers::generate_value('paragraphs', $faker);
            case 'wysiwyg':
                $paras = preg_split('/\n+/', trim((string) Draxira_Helpers::generate_value('paragraphs', $faker)));
                return '<p>' . implode('</p><p>', array_map('esc_html', $paras)) . '</p>';
            case 'email':
                return Draxira_Helpers::generate_value('email', $faker);
            case 'url':
                return Draxira_Helpers::generate_value('url', $faker);
            case 'password':
                return null;
            case 'number':
            case 'range':
                return self::number_for($field);
            case 'true_false':
                if ('bool_true' === $strategy) {
                    return 1;
                }
                if ('bool_false' === $strategy) {
                    return 0;
                }
                return wp_rand(0, 1);
            case 'color_picker':
                return Draxira_Helpers::generate_value('hex_color', $faker);
            case 'date_picker':
                return gmdate('Ymd', time() - wp_rand(0, 730) * DAY_IN_SECONDS);
            case 'date_time_picker':
                return gmdate('Y-m-d H:i:s', time() - wp_rand(0, 730 * DAY_IN_SECONDS));
            case 'time_picker':
                return sprintf('%02d:%02d:00', wp_rand(0, 23), wp_rand(0, 59));
            case 'oembed':
                $urls = [
                    'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
                    'https://www.youtube.com/watch?v=YE7VzlLtp-4',
                    'https://vimeo.com/1084537',
                ];
                return $urls[array_rand($urls)];
            case 'google_map':
                return [
                    'address' => (string) Draxira_Helpers::generate_value('address', $faker),
                    'lat' => (float) Draxira_Helpers::generate_value('latitude', $faker),
                    'lng' => (float) Draxira_Helpers::generate_value('longitude', $faker),
                    'zoom' => 14,
                ];
            case 'select':
            case 'checkbox':
            case 'radio':
            case 'button_group':
                return self::choices_for($field);
            case 'image':
                $ids = self::media_for($field, $strategy, $object, 1, 1);
                return $ids ? $ids[0] : null;
            case 'gallery':
                list($lo, $hi) = self::range_for($field, 3, 6);
                $ids = self::media_for($field, $strategy, $object, $lo, $hi);
                return $ids ? $ids : null;
            case 'file':
                return self::file_for($strategy, $object);
            case 'link':
                return self::link_for($strategy);
            case 'post_object':
            case 'page_link':
            case 'relationship':
                return self::posts_for($field, $strategy, $object);
            case 'taxonomy':
                return self::terms_for($field, $strategy);
            case 'user':
                return self::users_for($field, $strategy);
            case 'group':
                return self::sub_values(isset($field['sub_fields']) ? $field['sub_fields'] : [], $object, $depth);
            case 'repeater':
                return self::repeater_rows($field, $strategy, $object, $depth);
            case 'flexible_content':
                return self::flexible_rows($field, $strategy, $object, $depth);
        }

        return null;
    }

    private static function number_for(array $field)
    {
        $min = isset($field['min']) && '' !== $field['min'] ? (float) $field['min'] : 1;
        $max = isset($field['max']) && '' !== $field['max'] ? (float) $field['max'] : max($min + 99, 100);
        $step = !empty($field['step']) ? (float) $field['step'] : 1;
        if ($max < $min) {
            $max = $min;
        }
        $steps = (int) floor(($max - $min) / $step);
        $value = $min + wp_rand(0, max(0, $steps)) * $step;
        return $step == (int) $step && $min == (int) $min ? (int) $value : round($value, 2);
    }

    /**
     * Random option(s) out of the field's choices.
     */
    private static function choices_for(array $field)
    {
        $choices = isset($field['choices']) && is_array($field['choices']) ? array_keys($field['choices']) : [];
        if (empty($choices)) {
            return null;
        }

        $multiple = 'checkbox' === $field['type'] || !empty($field['multiple']);
        if (!$multiple) {
            return $choices[array_rand($choices)];
        }

        shuffle($choices);
        return array_slice($choices, 0, wp_rand(1, min(3, count($choices))));
    }

    /**
     * Respect min/max settings of a field with sane defaults.
     */
    private static function range_for(array $field, $default_lo, $default_hi)
    {
        $min = !empty($field['min']) ? (int) $field['min'] : 0;
        $max = !empty($field['max']) ? (int) $field['max'] : 0;
        $lo = max($min, $default_lo);
        $hi = $max ? min($max, max($lo, $default_hi)) : max($lo, $default_hi);
        if ($max && $lo > $max) {
            $lo = $max;
        }
        if ($hi < $lo) {
            $hi = $lo;
        }
        return [$lo, $hi];
    }

    /* ----- media ----- */

    private static function library_ids($count, $images_only)
    {
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => max(1, (int) $count),
            'orderby' => 'rand',
            'fields' => 'ids',
            'no_found_rows' => true,
        ];
        if ($images_only) {
            $args['post_mime_type'] = 'image';
        }
        return array_map('intval', get_posts($args));
    }

    private static function media_for(array $field, $strategy, array $object, $lo, $hi)
    {
        $count = $lo === $hi ? $lo : wp_rand($lo, $hi);
        $parent = isset($object['id']) && 'post' === $object['type'] ? (int) $object['id'] : 0;
        $parent_type = isset($object['post_type']) ? $object['post_type'] : '';

        if ('auto' === $strategy || '' === $strategy) {
            $strategy = self::library_ids(1, true) ? 'media_random' : 'media_bundled';
        }

        $ids = [];
        if ('media_random' === $strategy) {
            $ids = self::library_ids($count, true);
            if (empty($ids)) {
                $strategy = 'media_bundled';
            }
        }

        if ('media_bundled' === $strategy || 'media_new' === $strategy) {
            $dir = 'media_bundled' === $strategy ? DRAXIRA_PLUGIN_DIR . 'assets/img/' : '';
            $regex = '/^dummy_content_filler_img_\d+\.(jpe?g|png|gif)$/i';
            for ($i = 0; $i < $count; $i++) {
                $id = Draxira_Helpers::import_random_image($dir, $regex, $parent, $parent_type, 'acf');
                if ($id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    private static function file_for($strategy, array $object)
    {
        if ('file_new' !== $strategy) {
            $ids = self::library_ids(1, false);
            if ($ids) {
                return $ids[0];
            }
        }

        $name = 'draxira-document-' . wp_rand(1000, 9999) . '.txt';
        $body = "Draxira dummy document\n\n" . (string) Draxira_Helpers::generate_value('paragraphs', Draxira_Helpers::faker());
        $upload = wp_upload_bits($name, null, $body);
        if (!empty($upload['error'])) {
            return null;
        }

        $parent = isset($object['id']) && 'post' === $object['type'] ? (int) $object['id'] : 0;
        $id = wp_insert_attachment([
            'post_mime_type' => 'text/plain',
            'post_title' => preg_replace('/\.[^.]+$/', '', $name),
            'post_status' => 'inherit',
        ], $upload['file'], $parent);

        if (is_wp_error($id) || !$id) {
            return null;
        }

        Draxira_Media::register($id, $parent, isset($object['post_type']) ? $object['post_type'] : '', 'acf');
        return (int) $id;
    }

    /* ----- links / relations ----- */

    private static function link_for($strategy)
    {
        $faker = Draxira_Helpers::faker();
        $mode = $strategy;
        if (!in_array($mode, ['link_internal', 'link_external', 'link_mixed'], true)) {
            $mode = 'link_mixed';
        }
        if ('link_mixed' === $mode) {
            $mode = wp_rand(0, 1) ? 'link_internal' : 'link_external';
        }

        $target = wp_rand(1, 10) > 7 ? '_blank' : '';

        if ('link_internal' === $mode) {
            $posts = get_posts([
                'post_type' => ['page', 'post'],
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'orderby' => 'rand',
                'no_found_rows' => true,
            ]);
            if ($posts) {
                return [
                    'title' => get_the_title($posts[0]),
                    'url' => get_permalink($posts[0]),
                    'target' => $target,
                ];
            }
        }

        return [
            'title' => ucfirst((string) Draxira_Helpers::generate_value('words', $faker)),
            'url' => (string) Draxira_Helpers::generate_value('url', $faker),
            'target' => '_blank',
        ];
    }

    private static function posts_for(array $field, $strategy, array $object)
    {
        $post_types = !empty($field['post_type']) ? (array) $field['post_type'] : array_values(array_diff(get_post_types(['public' => true]), ['attachment']));

        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'fields' => 'ids',
            'no_found_rows' => true,
            'posts_per_page' => 6,
            'orderby' => 'rand',
        ];

        if (isset($object['id']) && 'post' === $object['type']) {
            $args['post__not_in'] = [(int) $object['id']];
        }

        if ('rel_draxira' === $strategy) {
            $args['meta_key'] = DRAXIRA_META_KEY;
            $args['meta_value'] = '1';
            $args['post_status'] = 'any';
        } elseif ('rel_latest' === $strategy) {
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
        }

        $ids = array_map('intval', get_posts($args));
        if (empty($ids)) {
            return null;
        }

        $multiple = 'relationship' === $field['type'] || !empty($field['multiple']);
        if (!$multiple) {
            return $ids[0];
        }

        $max = !empty($field['max']) ? (int) $field['max'] : 4;
        $min = !empty($field['min']) ? (int) $field['min'] : 1;
        $take = min(count($ids), wp_rand($min, max($min, min($max, 4))));
        return array_slice($ids, 0, max(1, $take));
    }

    private static function terms_for(array $field, $strategy)
    {
        if (empty($field['taxonomy'])) {
            return null;
        }

        $args = ['taxonomy' => $field['taxonomy'], 'hide_empty' => false, 'fields' => 'ids', 'number' => 60];
        if ('term_draxira' === $strategy) {
            $args['meta_query'] = [['key' => DRAXIRA_META_KEY, 'value' => '1']];
        }

        $ids = get_terms($args);
        if (is_wp_error($ids) || empty($ids)) {
            return null;
        }

        $ids = array_map('intval', $ids);
        shuffle($ids);

        $multiple = in_array(isset($field['field_type']) ? $field['field_type'] : 'checkbox', ['checkbox', 'multi_select'], true);
        return $multiple ? array_slice($ids, 0, wp_rand(1, min(3, count($ids)))) : $ids[0];
    }

    private static function users_for(array $field, $strategy)
    {
        $args = ['fields' => 'ID', 'number' => 40];
        if (!empty($field['role'])) {
            $args['role__in'] = (array) $field['role'];
        }
        if ('user_draxira' === $strategy) {
            $args['meta_key'] = DRAXIRA_META_KEY;
            $args['meta_value'] = '1';
        }

        $ids = array_map('intval', get_users($args));
        if (empty($ids)) {
            return null;
        }

        shuffle($ids);
        return !empty($field['multiple']) ? array_slice($ids, 0, wp_rand(1, min(3, count($ids)))) : $ids[0];
    }

    /* ----- nested ----- */

    private static function sub_values(array $sub_fields, array $object, $depth)
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $row = [];
        foreach ($sub_fields as $sub) {
            if (empty($sub['name'])) {
                continue;
            }
            $value = self::generate($sub, 'auto', $object, $depth + 1);
            if (null !== $value) {
                $row[$sub['name']] = $value;
            }
        }
        return $row;
    }

    private static function row_count(array $field, $strategy)
    {
        $ranges = [
            'rows_few' => [1, 2],
            'rows_some' => [3, 5],
            'rows_many' => [6, 10],
            'auto' => [3, 5],
        ];
        $range = isset($ranges[$strategy]) ? $ranges[$strategy] : $ranges['auto'];

        $min = !empty($field['min']) ? (int) $field['min'] : 0;
        $max = !empty($field['max']) ? (int) $field['max'] : 0;

        $lo = max($range[0], $min);
        $hi = max($range[1], $lo);
        if ($max) {
            $hi = min($hi, $max);
            $lo = min($lo, $hi);
        }

        return wp_rand($lo, $hi);
    }

    private static function repeater_rows(array $field, $strategy, array $object, $depth)
    {
        if ($depth >= self::MAX_DEPTH || empty($field['sub_fields'])) {
            return null;
        }

        $rows = [];
        $count = self::row_count($field, $strategy);
        for ($i = 0; $i < $count; $i++) {
            $row = self::sub_values($field['sub_fields'], $object, $depth);
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows ? $rows : null;
    }

    private static function flexible_rows(array $field, $strategy, array $object, $depth)
    {
        if ($depth >= self::MAX_DEPTH || empty($field['layouts'])) {
            return null;
        }

        $layouts = array_values($field['layouts']);
        $rows = [];
        $count = self::row_count($field, $strategy);
        for ($i = 0; $i < $count; $i++) {
            $layout = $layouts[array_rand($layouts)];
            $row = self::sub_values(isset($layout['sub_fields']) ? $layout['sub_fields'] : [], $object, $depth);
            $row['acf_fc_layout'] = $layout['name'];
            $rows[] = $row;
        }
        return $rows ? $rows : null;
    }
}
