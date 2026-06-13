<?php
/**
 * Admin Post Filters
 *
 * Adds a "Last Updated" dropdown filter on the Posts list screen
 * to surface posts that haven't been updated in N+ months/years,
 * making it easy to identify and clean up stale content.
 *
 * Also makes the Date column sortable by post_modified.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Stale Posts Dropdown Filter
--------------------------------------------------------------*/

/**
 * Available "not updated in X+" options.
 *
 * @return array key => label
 */
function edu_stale_filter_options() {
    return array(
        '1month'  => 'Not updated in 1+ month',
        '3months' => 'Not updated in 3+ months',
        '6months' => 'Not updated in 6+ months',
        '1year'   => 'Not updated in 1+ year',
        '2years'  => 'Not updated in 2+ years',
        '3years'  => 'Not updated in 3+ years',
    );
}

/**
 * Render the dropdown on the Posts list screen.
 *
 * @param string $post_type Current post type
 */
function edu_render_stale_filter($post_type) {
    if ($post_type !== 'post') {
        return;
    }

    $selected = isset($_GET['edu_stale']) ? sanitize_key($_GET['edu_stale']) : '';
    $options  = edu_stale_filter_options();

    echo '<select name="edu_stale" id="edu_stale">';
    echo '<option value="">' . esc_html__('Last updated…', 'education-blogs') . '</option>';
    foreach ($options as $key => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($key),
            selected($selected, $key, false),
            esc_html($label)
        );
    }
    echo '</select>';
}
add_action('restrict_manage_posts', 'edu_render_stale_filter');

/**
 * Apply the filter to the main query on edit.php.
 *
 * @param WP_Query $query
 */
function edu_apply_stale_filter($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return;
    }

    if (empty($_GET['edu_stale'])) {
        return;
    }

    $key     = sanitize_key($_GET['edu_stale']);
    $options = edu_stale_filter_options();
    if (!isset($options[$key])) {
        return;
    }

    $before = edu_stale_filter_cutoff_string($key);
    if (!$before) {
        return;
    }

    $query->set('date_query', array(
        array(
            'column' => 'post_modified_gmt',
            'before' => $before,
            'inclusive' => true,
        ),
    ));

    // Surface the staleness by sorting oldest-modified first by default.
    if (empty($_GET['orderby'])) {
        $query->set('orderby', 'modified');
        $query->set('order', 'ASC');
    }
}
add_action('pre_get_posts', 'edu_apply_stale_filter');

/**
 * Map a filter key to an absolute cutoff date (GMT).
 *
 * Anything modified on or before this date is "stale" for that bucket.
 *
 * @param string $key
 * @return string|null Y-m-d H:i:s or null
 */
function edu_stale_filter_cutoff_string($key) {
    $map = array(
        '1month'  => '-1 month',
        '3months' => '-3 months',
        '6months' => '-6 months',
        '1year'   => '-1 year',
        '2years'  => '-2 years',
        '3years'  => '-3 years',
    );

    if (!isset($map[$key])) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', strtotime($map[$key]));
}

/*--------------------------------------------------------------
# Sortable Date Column (by modified date)
--------------------------------------------------------------*/

/**
 * Register a virtual "modified" sort key on the existing Date column.
 *
 * WP's Date column sorts by post_date by default. We register an extra
 * sortable key so users can switch to modified-date ordering via URL
 * (e.g., &orderby=modified) and via our filter above.
 *
 * @param array $columns
 * @return array
 */
function edu_register_modified_sortable($columns) {
    $columns['modified'] = 'modified';
    return $columns;
}
add_filter('manage_edit-post_sortable_columns', 'edu_register_modified_sortable');
