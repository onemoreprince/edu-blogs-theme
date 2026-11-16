<?php
/**
 * SVG Figures
 *
 * Lets posts show animated SVG figures that live in the Media Library but
 * are printed inline, so they pick up the site's own font and colors.
 *
 * - Administrators can upload .svg files (rejected if they contain scripts).
 * - [edu_svg id="123"] prints attachment 123 inline inside a <figure>.
 * - "Image Status" post meta (and ACF fields, when ACF is active) logs which
 *   posts have had their [Image: ...] placeholders turned into figures.
 * - Posts list gets an "Images" column and a status filter.
 *
 * Nothing here runs a migration or touches existing posts. A site that never
 * uses the shortcode or the fields behaves exactly as before.
 *
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Constants
--------------------------------------------------------------*/

if (!defined('EDU_SVG_MAX_BYTES')) {
    define('EDU_SVG_MAX_BYTES', 400 * 1024);
}

/*--------------------------------------------------------------
# SVG Uploads (administrators only)
--------------------------------------------------------------*/

/**
 * Whether the current user may upload SVG files
 *
 * @return bool
 */
function edu_svg_user_can_upload() {
    return current_user_can('manage_options');
}

/**
 * Whether a filename ends in .svg
 *
 * @param mixed $filename File name
 * @return bool
 */
function edu_svg_is_svg_filename($filename) {
    return is_string($filename) && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg';
}

/**
 * Allow the SVG mime type for administrators
 *
 * @param array $mimes Allowed mime types
 * @return array Allowed mime types
 */
function edu_svg_allow_mime($mimes) {
    if (is_array($mimes) && edu_svg_user_can_upload()) {
        $mimes['svg'] = 'image/svg+xml';
    }
    return $mimes;
}
add_filter('upload_mimes', 'edu_svg_allow_mime');

/**
 * Help WordPress recognise .svg files it could not identify by content
 *
 * @param array  $data     Values for ext, type and proper_filename
 * @param string $file     Full path to the file
 * @param string $filename File name
 * @return array Values for ext, type and proper_filename
 */
function edu_svg_fix_filetype($data, $file, $filename) {
    if (!is_array($data) || (!empty($data['ext']) && !empty($data['type']))) {
        return $data;
    }
    if (!edu_svg_is_svg_filename($filename) || !edu_svg_user_can_upload()) {
        return $data;
    }

    $data['ext']  = 'svg';
    $data['type'] = 'image/svg+xml';

    return $data;
}
add_filter('wp_check_filetype_and_ext', 'edu_svg_fix_filetype', 10, 3);

/**
 * Look for markup that should never be in a figure
 *
 * @param string $svg    SVG markup
 * @param bool   $strict Also flag anything loaded from another address
 * @return string Short description of the problem, or '' if none found
 */
function edu_svg_find_unsafe($svg, $strict = false) {
    $patterns = array(
        'a script element'       => '/<\s*script\b/i',
        'a foreignObject'        => '/<\s*foreignObject\b/i',
        'embedded content'       => '/<\s*(?:iframe|embed|object)\b/i',
        'an event handler'       => '/\son[a-z]+\s*=/i',
        'a javascript: link'     => '/javascript\s*:/i',
        'an entity or doctype'   => '/<!\s*(?:ENTITY|DOCTYPE)\b/i',
    );

    if ($strict) {
        $patterns['an external reference'] = '/(?:href|src)\s*=\s*["\']\s*(?:[a-z][a-z0-9+.\-]*:)?\/\//i';
        $patterns['an external stylesheet'] = '/@import\b|url\(\s*["\']?\s*(?:[a-z][a-z0-9+.\-]*:)?\/\//i';
    }

    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $svg)) {
            return $label;
        }
    }

    return '';
}

/**
 * Reject SVG uploads that contain scripts or similar
 *
 * @param array $file Uploaded file data
 * @return array Uploaded file data, with an error set if rejected
 */
function edu_svg_upload_prefilter($file) {
    if (!is_array($file) || empty($file['name']) || !edu_svg_is_svg_filename($file['name'])) {
        return $file;
    }
    if (!empty($file['error']) || empty($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        return $file;
    }

    $svg = file_get_contents($file['tmp_name']);
    if ($svg === false || stripos($svg, '<svg') === false) {
        $file['error'] = 'This file is not a valid SVG.';
        return $file;
    }

    $problem = edu_svg_find_unsafe($svg);
    if ($problem !== '') {
        $file['error'] = 'SVG rejected: it contains ' . $problem . '.';
    }

    return $file;
}
add_filter('wp_handle_upload_prefilter', 'edu_svg_upload_prefilter');
add_filter('wp_handle_sideload_prefilter', 'edu_svg_upload_prefilter');

/*--------------------------------------------------------------
# Inline Rendering
--------------------------------------------------------------*/

/**
 * Read an SVG attachment and return markup that is safe to print inline
 *
 * @param int $attachment_id Attachment ID
 * @return string SVG markup, or '' if the attachment is missing or unsuitable
 */
function edu_svg_get_markup($attachment_id) {
    static $cache = array();

    $attachment_id = absint($attachment_id);
    if (!$attachment_id) {
        return '';
    }
    if (isset($cache[$attachment_id])) {
        return $cache[$attachment_id];
    }
    $cache[$attachment_id] = '';

    if (get_post_type($attachment_id) !== 'attachment' || get_post_mime_type($attachment_id) !== 'image/svg+xml') {
        return '';
    }

    $path = get_attached_file($attachment_id);
    if (!$path || !is_readable($path) || filesize($path) > EDU_SVG_MAX_BYTES) {
        return '';
    }

    $svg = file_get_contents($path);
    if ($svg === false) {
        return '';
    }

    // Drop the XML declaration, comments and anything before the <svg> tag
    $svg = preg_replace('/<\?xml.*?\?>/is', '', $svg);
    $svg = is_string($svg) ? preg_replace('/<!--.*?-->/s', '', $svg) : '';
    if (!is_string($svg)) {
        return '';
    }

    $start = stripos($svg, '<svg');
    if ($start === false) {
        return '';
    }
    $svg = trim(substr($svg, $start));

    if (edu_svg_find_unsafe($svg, true) !== '') {
        return '';
    }

    $cache[$attachment_id] = $svg;

    return $svg;
}

/**
 * Build the <figure> for an SVG attachment
 *
 * @param int $attachment_id Attachment ID
 * @return string Figure HTML ('' for visitors when the SVG cannot be shown)
 */
function edu_svg_figure_html($attachment_id) {
    $attachment_id = absint($attachment_id);
    $svg           = edu_svg_get_markup($attachment_id);

    if ($svg === '') {
        if (current_user_can('edit_posts')) {
            return '<p class="edu-svg-missing"><em>SVG figure ' . $attachment_id . ' could not be shown (missing, too large, or not a clean SVG). Only editors see this note.</em></p>';
        }
        return '';
    }

    return '<figure class="edu-svg-figure" data-svg-id="' . $attachment_id . '">' . $svg . '</figure>';
}

/**
 * [edu_svg id="123"] shortcode
 *
 * Inside post content this only leaves a marker; the SVG itself is added by
 * edu_svg_inject_figures() after the other content filters (ToC, markdown
 * fixer, text hover and so on) have run, so none of them can alter the SVG.
 *
 * @param array $atts Shortcode attributes
 * @return string Marker or figure HTML
 */
function edu_svg_shortcode($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'edu_svg');
    $id   = absint($atts['id']);

    if (!$id || is_feed()) {
        return '';
    }

    if (doing_filter('the_content')) {
        return '<!--edu-svg:' . $id . '-->';
    }

    return edu_svg_figure_html($id);
}
add_shortcode('edu_svg', 'edu_svg_shortcode');

/**
 * Swap figure markers for the inline SVG
 *
 * @param string $content Post content HTML
 * @return string Post content HTML
 */
function edu_svg_inject_figures($content) {
    if (!is_string($content) || strpos($content, '<!--edu-svg:') === false) {
        return $content;
    }

    $result = preg_replace_callback(
        '/<p[^>]*>\s*<!--edu-svg:(\d+)-->\s*<\/p>|<!--edu-svg:(\d+)-->/',
        function ($matches) {
            $id = !empty($matches[1]) ? $matches[1] : (isset($matches[2]) ? $matches[2] : 0);
            return edu_svg_figure_html($id);
        },
        $content
    );

    return is_string($result) ? $result : $content;
}
add_filter('the_content', 'edu_svg_inject_figures', 99);

/*--------------------------------------------------------------
# Image Status - Post Meta
--------------------------------------------------------------*/

/**
 * Image status values and their labels
 *
 * @return array key => label
 */
function edu_image_status_options() {
    return array(
        'not_needed'   => 'Not needed',
        'pending'      => 'Pending',
        'generated'    => 'Generated',
        'needs_review' => 'Needs review',
        'approved'     => 'Approved',
    );
}

/**
 * Keep only known status values
 *
 * @param mixed $value Submitted value
 * @return string Status key, or '' if unknown
 */
function edu_image_status_sanitize($value) {
    $value = is_scalar($value) ? sanitize_key((string) $value) : '';
    return array_key_exists($value, edu_image_status_options()) ? $value : '';
}

/**
 * Register the Image Status meta so it can be read and written over REST
 * (works with or without ACF)
 */
function edu_image_status_register_meta() {
    $can_edit = function () {
        return current_user_can('edit_posts');
    };
    $text = function ($value) {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    };
    $long_text = function ($value) {
        return is_scalar($value) ? sanitize_textarea_field((string) $value) : '';
    };

    $fields = array(
        'edu_image_status'        => array('string', 'edu_image_status_sanitize'),
        'edu_image_count'         => array('integer', function ($value) {
            return is_scalar($value) ? absint($value) : 0;
        }),
        'edu_image_generated'     => array('string', $text),
        'edu_image_skill_version' => array('string', $text),
        'edu_image_log'           => array('string', $long_text),
        'edu_image_notes'         => array('string', $long_text),
    );

    foreach ($fields as $key => $field) {
        register_post_meta('post', $key, array(
            'type'              => $field[0],
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => $field[1],
            'auth_callback'     => $can_edit,
        ));
    }
}
add_action('init', 'edu_image_status_register_meta');

/*--------------------------------------------------------------
# Image Status - ACF Fields
--------------------------------------------------------------*/

/**
 * Show the Image Status meta as an ACF field group on the post edit screen
 */
function edu_image_status_register_acf_fields() {
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    acf_add_local_field_group(array(
        'key'          => 'group_edu_image_status',
        'title'        => 'Image Status',
        'fields'       => array(
            array(
                'key'           => 'field_edu_image_status',
                'label'         => 'Status',
                'name'          => 'edu_image_status',
                'type'          => 'select',
                'choices'       => edu_image_status_options(),
                'allow_null'    => 1,
                'return_format' => 'value',
            ),
            array(
                'key'   => 'field_edu_image_count',
                'label' => 'Figures added',
                'name'  => 'edu_image_count',
                'type'  => 'number',
                'min'   => 0,
            ),
            array(
                'key'            => 'field_edu_image_generated',
                'label'          => 'Generated on',
                'name'           => 'edu_image_generated',
                'type'           => 'date_time_picker',
                'display_format' => 'j M Y, g:i a',
                'return_format'  => 'Y-m-d H:i:s',
            ),
            array(
                'key'   => 'field_edu_image_skill_version',
                'label' => 'Design skill version',
                'name'  => 'edu_image_skill_version',
                'type'  => 'text',
            ),
            array(
                'key'          => 'field_edu_image_log',
                'label'        => 'Figure log',
                'name'         => 'edu_image_log',
                'type'         => 'textarea',
                'rows'         => 5,
                'new_lines'    => '',
                'instructions' => 'One line per figure: media ID and the placeholder it replaced.',
            ),
            array(
                'key'       => 'field_edu_image_notes',
                'label'     => 'Notes',
                'name'      => 'edu_image_notes',
                'type'      => 'textarea',
                'rows'      => 3,
                'new_lines' => '',
            ),
        ),
        'location'     => array(
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'post',
                ),
            ),
        ),
        'position'     => 'side',
        'menu_order'   => 20,
        'active'       => true,
        'show_in_rest' => 0,
    ));
}
add_action('acf/init', 'edu_image_status_register_acf_fields');

/*--------------------------------------------------------------
# Image Status - Posts List Column & Filter
--------------------------------------------------------------*/

/**
 * Add the "Images" column to the Posts list
 *
 * @param array $columns Columns
 * @return array Columns
 */
function edu_image_status_add_column($columns) {
    if (is_array($columns)) {
        $columns['edu_images'] = 'Images';
    }
    return $columns;
}
add_filter('manage_post_posts_columns', 'edu_image_status_add_column');

/**
 * Print the "Images" column: the logged status, or the number of
 * [Image: ...] placeholders still waiting in the post
 *
 * @param string $column  Column name
 * @param int    $post_id Post ID
 */
function edu_image_status_render_column($column, $post_id) {
    if ($column !== 'edu_images') {
        return;
    }

    $options = edu_image_status_options();
    $status  = get_post_meta($post_id, 'edu_image_status', true);
    $waiting = preg_match_all('/\[Image:/i', (string) get_post_field('post_content', $post_id));
    $parts   = array();

    if (is_string($status) && isset($options[$status])) {
        $label = $options[$status];
        $count = absint(get_post_meta($post_id, 'edu_image_count', true));
        if ($count) {
            $label .= ' (' . $count . ')';
        }
        $parts[] = $label;
    }
    if ($waiting) {
        $parts[] = $waiting . ' placeholder' . ($waiting === 1 ? '' : 's') . ' waiting';
    }

    echo $parts ? esc_html(implode(' · ', $parts)) : '<span aria-hidden="true">—</span>';
}
add_action('manage_post_posts_custom_column', 'edu_image_status_render_column', 10, 2);

/**
 * Render the status dropdown on the Posts list screen
 *
 * @param string $post_type Current post type
 */
function edu_image_status_render_filter($post_type) {
    if ($post_type !== 'post') {
        return;
    }

    $selected = isset($_GET['edu_image_status']) ? sanitize_key($_GET['edu_image_status']) : '';

    echo '<select name="edu_image_status" id="edu_image_status">';
    echo '<option value="">Image status…</option>';
    foreach (edu_image_status_options() as $key => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($key),
            selected($selected, $key, false),
            esc_html($label)
        );
    }
    echo '</select>';
}
add_action('restrict_manage_posts', 'edu_image_status_render_filter');

/**
 * Apply the status dropdown to the Posts list query
 *
 * @param WP_Query $query Query
 */
function edu_image_status_apply_filter($query) {
    if (!is_admin() || !$query->is_main_query() || empty($_GET['edu_image_status'])) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php' || $query->get('post_type') !== 'post') {
        return;
    }

    $status = edu_image_status_sanitize(wp_unslash($_GET['edu_image_status']));
    if ($status === '') {
        return;
    }

    $meta_query = $query->get('meta_query');
    if (!is_array($meta_query)) {
        $meta_query = array();
    }
    $meta_query[] = array(
        'key'   => 'edu_image_status',
        'value' => $status,
    );
    $query->set('meta_query', $meta_query);
}
add_action('pre_get_posts', 'edu_image_status_apply_filter');
