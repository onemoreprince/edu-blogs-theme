<?php
/**
 * Post Content Fixer
 *
 * Fixes post content issues via cron and on post save:
 * - Replaces all kinds of em dashes / en dashes with regular dashes
 * - Removes links that point to the same post (self-links)
 *
 * Uses cron scheduling to avoid server load and tracks processed posts
 * via post meta to avoid redundant processing. Re-processes when a post is updated.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Constants
--------------------------------------------------------------*/

define('POST_FIXER_BATCH_SIZE', 20);
define('POST_FIXER_META_KEY', '_content_fixer_done');
define('POST_FIXER_LOG_FILE', WP_CONTENT_DIR . '/post-content-fixer.log');
define('POST_FIXER_CRON_HOOK', 'edu_post_content_fixer_cron');

/*--------------------------------------------------------------
# Cron Scheduling
--------------------------------------------------------------*/

/**
 * Schedule the content fixer cron job (runs twice daily)
 */
function edu_content_fixer_schedule_cron() {
    if (!wp_next_scheduled(POST_FIXER_CRON_HOOK)) {
        wp_schedule_event(time(), 'twicedaily', POST_FIXER_CRON_HOOK);
    }
}
add_action('init', 'edu_content_fixer_schedule_cron');

/**
 * Cron callback - process a batch of posts
 */
function edu_content_fixer_cron_run() {
    edu_content_fixer_process_batch();
}
add_action(POST_FIXER_CRON_HOOK, 'edu_content_fixer_cron_run');

/*--------------------------------------------------------------
# Process on Post Save/Update
--------------------------------------------------------------*/

/**
 * Fix content when a post is saved or updated
 * Clears the "done" flag so cron re-processes if needed,
 * and immediately fixes the content.
 *
 * @param int    $post_id Post ID
 * @param object $post    Post object
 * @param bool   $update  Whether this is an update
 */
function edu_content_fixer_on_save($post_id, $post, $update) {
    // Skip autosaves, revisions, and non-post types
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if ($post->post_type !== 'post') {
        return;
    }
    if ($post->post_status !== 'publish') {
        return;
    }

    // Prevent infinite loop - unhook before updating
    remove_action('save_post', 'edu_content_fixer_on_save', 99);

    $result = edu_content_fixer_fix_post($post_id);

    if ($result['changed']) {
        edu_content_fixer_log(sprintf(
            'Post %d (%s) fixed on save: %s',
            $post_id,
            get_the_title($post_id),
            implode(', ', $result['fixes'])
        ));
    }

    // Mark as processed
    update_post_meta($post_id, POST_FIXER_META_KEY, time());

    // Re-hook
    add_action('save_post', 'edu_content_fixer_on_save', 99, 3);
}
add_action('save_post', 'edu_content_fixer_on_save', 99, 3);

/*--------------------------------------------------------------
# Batch Processing
--------------------------------------------------------------*/

/**
 * Process a batch of unprocessed posts
 *
 * @return array Summary of processing results
 */
function edu_content_fixer_process_batch() {
    global $wpdb;

    // Find published posts that have NOT been processed yet
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_content
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND pm.meta_value IS NULL
        ORDER BY p.ID ASC
        LIMIT %d",
        POST_FIXER_META_KEY,
        POST_FIXER_BATCH_SIZE
    ));

    if (empty($posts)) {
        return ['processed' => 0, 'fixed' => 0];
    }

    $fixed_count = 0;

    foreach ($posts as $post) {
        $result = edu_content_fixer_fix_post($post->ID);

        if ($result['changed']) {
            $fixed_count++;
            edu_content_fixer_log(sprintf(
                'Post %d (%s) fixed via cron: %s',
                $post->ID,
                get_the_title($post->ID),
                implode(', ', $result['fixes'])
            ));
        }

        // Mark as processed regardless of whether changes were made
        update_post_meta($post->ID, POST_FIXER_META_KEY, time());
    }

    if ($fixed_count > 0 || count($posts) > 0) {
        edu_content_fixer_log(sprintf(
            'Batch complete: %d posts checked, %d fixed',
            count($posts),
            $fixed_count
        ));
    }

    return ['processed' => count($posts), 'fixed' => $fixed_count];
}

/*--------------------------------------------------------------
# Content Fixing Logic
--------------------------------------------------------------*/

/**
 * Fix a single post's content
 *
 * @param int $post_id Post ID
 * @return array Result with 'changed' boolean and 'fixes' array of descriptions
 */
function edu_content_fixer_fix_post($post_id) {
    $content = get_post_field('post_content', $post_id);
    $original = $content;
    $fixes = array();

    // Fix 1: Replace em dashes and en dashes with regular dashes
    $content = edu_content_fixer_replace_dashes($content);
    if ($content !== $original) {
        $fixes[] = 'dashes replaced';
    }

    // Fix 2: Remove self-links (links pointing to the same post)
    $before_self_links = $content;
    $content = edu_content_fixer_remove_self_links($content, $post_id);
    if ($content !== $before_self_links) {
        $fixes[] = 'self-links removed';
    }

    // Update post if changes were made
    if (!empty($fixes)) {
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            array('post_content' => $content),
            array('ID' => $post_id),
            array('%s'),
            array('%d')
        );
        clean_post_cache($post_id);
    }

    return array('changed' => !empty($fixes), 'fixes' => $fixes);
}

/**
 * Replace all types of em dashes, en dashes, and similar dash characters
 * with a regular hyphen-minus (-), but ONLY in visible text content.
 *
 * Skips replacements inside:
 * - HTML tags and attributes (href, src, style, data-*, etc.)
 * - <code>, <pre>, <script>, <style>, <textarea> elements and their contents
 * - HTML/Gutenberg comments (<!-- ... -->)
 *
 * @param string $content Post content
 * @return string Content with dashes replaced in text nodes only
 */
function edu_content_fixer_replace_dashes($content) {
    // Match protected elements, HTML comments, HTML tags, or text nodes.
    // Only text nodes (group 2) get dash replacement; everything else passes through.
    return preg_replace_callback(
        '/'
        . '<!--.*?-->'                                              // HTML/Gutenberg comments
        . '|<(code|pre|script|style|textarea)\b[^>]*>.*?<\/\1\s*>' // Protected elements with contents
        . '|<[^>]+>'                                                // Any HTML tag
        . '|([^<]+)'                                                // Text node (capture group 2)
        . '/is',
        function ($matches) {
            // Group 2 = text node: safe to replace dashes
            if (!empty($matches[2])) {
                return edu_content_fixer_replace_dashes_in_text($matches[2]);
            }
            // Everything else (tags, comments, protected blocks): return unchanged
            return $matches[0];
        },
        $content
    );
}

/**
 * Replace dash characters in a plain text string (no HTML)
 *
 * Characters replaced:
 * - U+2014 Em Dash (—)
 * - U+2013 En Dash (–)
 * - U+2012 Figure Dash (‒)
 * - U+2015 Horizontal Bar (―)
 * - U+2E3A Two-Em Dash (⸺)
 * - U+2E3B Three-Em Dash (⸻)
 * - U+FE58 Small Em Dash (﹘)
 * - U+FE63 Small Hyphen-Minus (﹣)
 * - U+FF0D Fullwidth Hyphen-Minus (－)
 * - HTML entities: &mdash; &ndash; &#8212; &#8211; &#x2014; &#x2013;
 *
 * @param string $text Plain text string
 * @return string Text with dashes replaced
 */
function edu_content_fixer_replace_dashes_in_text($text) {
    // Replace Unicode dash characters (UTF-8 byte sequences)
    $dash_chars = array(
        "\xE2\x80\x94",     // Em Dash (—) U+2014
        "\xE2\x80\x93",     // En Dash (–) U+2013
        "\xE2\x80\x92",     // Figure Dash (‒) U+2012
        "\xE2\x80\x95",     // Horizontal Bar (―) U+2015
        "\xE2\xB8\xBA",     // Two-Em Dash (⸺) U+2E3A
        "\xE2\xB8\xBB",     // Three-Em Dash (⸻) U+2E3B
        "\xEF\xB9\x98",     // Small Em Dash (﹘) U+FE58
        "\xEF\xB9\xA3",     // Small Hyphen-Minus (﹣) U+FE63
        "\xEF\xBC\x8D",     // Fullwidth Hyphen-Minus (－) U+FF0D
    );
    $text = str_replace($dash_chars, '-', $text);

    // Replace HTML entities for dashes
    $html_entities = array(
        '&mdash;',
        '&ndash;',
        '&#8212;',
        '&#8211;',
        '&#x2014;',
        '&#x2013;',
        '&#x2014',
        '&#x2013',
    );
    $text = str_ireplace($html_entities, '-', $text);

    return $text;
}

/**
 * Remove links from post content that point to the same post
 * Keeps the anchor text, removes only the <a> wrapper
 *
 * @param string $content Post content
 * @param int    $post_id Post ID
 * @return string Content with self-links removed
 */
function edu_content_fixer_remove_self_links($content, $post_id) {
    $permalink = get_permalink($post_id);
    if (!$permalink) {
        return $content;
    }

    // Get all possible URL variations for this post
    $urls_to_match = array();
    $urls_to_match[] = $permalink;
    $urls_to_match[] = trailingslashit($permalink);
    $urls_to_match[] = untrailingslashit($permalink);

    // Also match without protocol (http vs https)
    foreach ($urls_to_match as $url) {
        $urls_to_match[] = str_replace('https://', 'http://', $url);
    }

    // Parse post URL path for relative matching
    $post_path = parse_url($permalink, PHP_URL_PATH);
    if ($post_path) {
        $urls_to_match[] = $post_path;
        $urls_to_match[] = trailingslashit($post_path);
        $urls_to_match[] = untrailingslashit($post_path);
    }

    $urls_to_match = array_unique($urls_to_match);

    // Find and replace self-links, keeping anchor text
    $content = preg_replace_callback(
        '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
        function($matches) use ($urls_to_match) {
            $href = $matches[1];
            $anchor_text = $matches[2];

            // Strip fragment/query from href for comparison
            $href_clean = strtok($href, '#');
            $href_clean = strtok($href_clean, '?');

            foreach ($urls_to_match as $url) {
                if (strcasecmp($href_clean, $url) === 0) {
                    // Return just the anchor text without the link
                    return $anchor_text;
                }
            }

            // Not a self-link, keep as-is
            return $matches[0];
        },
        $content
    );

    return $content;
}

/*--------------------------------------------------------------
# Logging
--------------------------------------------------------------*/

/**
 * Log a message to the content fixer log file
 *
 * @param string $message Message to log
 */
function edu_content_fixer_log($message) {
    $timestamp = current_time('Y-m-d H:i:s');
    $entry = sprintf("[%s] %s\n", $timestamp, $message);
    file_put_contents(POST_FIXER_LOG_FILE, $entry, FILE_APPEND | LOCK_EX);

    // Clean up old log entries (older than 6 months)
    edu_content_fixer_cleanup_log();
}

/**
 * Remove log entries older than 6 months
 */
function edu_content_fixer_cleanup_log() {
    if (!file_exists(POST_FIXER_LOG_FILE)) {
        return;
    }

    // Only run cleanup once per day
    $last_cleanup = get_option('edu_content_fixer_last_log_cleanup', 0);
    if (time() - $last_cleanup < DAY_IN_SECONDS) {
        return;
    }

    $lines = file(POST_FIXER_LOG_FILE, FILE_IGNORE_NEW_LINES);
    $cutoff = date('Y-m-d', strtotime('-6 months'));
    $filtered = array();

    foreach ($lines as $line) {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2})/', $line, $matches) && $matches[1] >= $cutoff) {
            $filtered[] = $line;
        }
    }

    if (count($filtered) !== count($lines)) {
        file_put_contents(POST_FIXER_LOG_FILE, implode("\n", $filtered) . "\n", LOCK_EX);
    }

    update_option('edu_content_fixer_last_log_cleanup', time());
}

/*--------------------------------------------------------------
# Reset on Post Update (clear flag so cron re-checks)
--------------------------------------------------------------*/

/**
 * When a post is updated via REST API or other means,
 * clear the processed flag so it gets re-checked
 *
 * @param int $post_id Post ID
 */
function edu_content_fixer_clear_flag_on_update($post_id) {
    if (wp_is_post_revision($post_id)) {
        return;
    }
    delete_post_meta($post_id, POST_FIXER_META_KEY);
}
add_action('post_updated', 'edu_content_fixer_clear_flag_on_update');

/*--------------------------------------------------------------
# Cleanup on Theme Switch
--------------------------------------------------------------*/

/**
 * Unschedule cron when theme is deactivated
 */
function edu_content_fixer_deactivate() {
    wp_clear_scheduled_hook(POST_FIXER_CRON_HOOK);
}
add_action('switch_theme', 'edu_content_fixer_deactivate');