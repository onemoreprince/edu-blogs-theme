<?php
/**
 * Custom Shortcodes
 * 
 * All shortcode definitions:
 * - [Year] - Current year
 * - [category_syllabus] - Category syllabus from ACF
 * - [related_results_from_url] - Related posts on 404 page
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Current Year Shortcode
--------------------------------------------------------------*/

/**
 * Display current year
 * 
 * Usage: [Year]
 * Output: 2024 (or current year)
 * 
 * @return string Current year
 */
function shortcode_current_year() {
    return date('Y');
}
add_shortcode('Year', 'shortcode_current_year');

/*--------------------------------------------------------------
# Category Syllabus Shortcode
--------------------------------------------------------------*/

/**
 * Display syllabus from category ACF field
 * 
 * Usage: [category_syllabus]
 * Works on single posts (shows all category syllabi) and category archives.
 * Requires ACF 'syllabus' field on category taxonomy.
 * 
 * @return string Syllabus HTML or empty string
 */
function shortcode_category_syllabus() {
    $output = '';

    if (is_single()) {
        $categories = get_the_category();
        
        if (!empty($categories)) {
            foreach ($categories as $category) {
                $syllabus = get_field('syllabus', 'category_' . $category->term_id);
                
                if ($syllabus) {
                    $output .= sprintf(
                        '<div class="syllabus-sidebar"><h4>%s</h4><p>%s</p></div>',
                        esc_html($category->name),
                        $syllabus
                    );
                }
            }
        }
    } elseif (is_category()) {
        $category = get_queried_object();
        
        if ($category instanceof WP_Term) {
            $syllabus = get_field('syllabus', 'category_' . $category->term_id);
            
            if ($syllabus) {
                $output = sprintf(
                    '<div class="syllabus-sidebar"><h4>%s</h4><p>%s</p></div>',
                    esc_html($category->name),
                    $syllabus
                );
            }
        }
    }
    
    return $output;
}
add_shortcode('category_syllabus', 'shortcode_category_syllabus');

/*--------------------------------------------------------------
# 404 Related Results Shortcode
--------------------------------------------------------------*/

/**
 * Display related posts based on 404 URL
 * 
 * Usage: [related_results_from_url]
 * Parses the failed URL to extract search terms and optional category.
 * Displays matching posts using the post-grid-default template part.
 * 
 * @return string Search results HTML
 */
function shortcode_related_results_from_url() {
    $request_uri = $_SERVER['REQUEST_URI'];
    $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
    $segments = explode('/', $path);

    $category_slug = '';
    $search_term = '';

    // Parse URL segments
    if (count($segments) > 1) {
        $category_slug = sanitize_title($segments[0]);
        $search_term = str_replace('-', ' ', $segments[count($segments) - 1]);
    } elseif (count($segments) === 1) {
        $search_term = str_replace('-', ' ', $segments[0]);
    }

    ob_start();

    echo '<main class="wp-block-group alignfull">';
    echo '<h2 class="wp-block-heading has-x-large-font-size" style="text-align:center;">Related results for "' . esc_html($search_term) . '"</h2>';

    // Build query arguments
    global $wp_query;
    $args = [
        's'              => $search_term,
        'posts_per_page' => 10,
        'post_type'      => 'post',
    ];
    
    // Add category filter if valid
    if ($category_slug && term_exists($category_slug, 'category')) {
        $args['category_name'] = $category_slug;
    }

    $wp_query = new WP_Query($args);

    // Load post grid template part
    echo do_blocks('<!-- wp:template-part {"slug":"post-grid-default"} /-->');

    wp_reset_postdata();
    echo '</main>';

    return ob_get_clean();
}
add_shortcode('related_results_from_url', 'shortcode_related_results_from_url');