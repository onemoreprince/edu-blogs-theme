<?php
/**
 * Footer Other Sites
 *
 * The "For Other Courses" network block (parts/footer-sites.html) is nested
 * inside the footer template part, so it is included on every template.
 * This module only lets it render on the homepage and on pages using the
 * "contact" template; everywhere else the part outputs nothing.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether the current request should show the footer network block.
 *
 * @return bool
 */
function edu_show_footer_sites() {
    global $_wp_current_template_id;

    if (is_front_page()) {
        return true;
    }

    if (is_page_template('contact')) {
        return true;
    }

    // Block themes: template resolved for this request, e.g. "theme-slug//contact"
    if (is_string($_wp_current_template_id) && substr($_wp_current_template_id, -9) === '//contact') {
        return true;
    }

    return false;
}

/**
 * Hide the footer-sites template part outside the homepage and contact page.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $block         Parsed block.
 * @return string
 */
function edu_filter_footer_sites_part($block_content, $block) {
    if (is_admin() || empty($block['attrs']['slug']) || $block['attrs']['slug'] !== 'footer-sites') {
        return $block_content;
    }

    return edu_show_footer_sites() ? $block_content : '';
}
add_filter('render_block_core/template-part', 'edu_filter_footer_sites_part', 10, 2);
