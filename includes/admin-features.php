<?php
/**
 * Admin Features
 * 
 * Features for WordPress admin:
 * - Word Count column in posts list
 * - TextHover Bracket Cleaner tool
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Word Count Column
--------------------------------------------------------------*/

/**
 * Add Word Count column to posts list
 * 
 * @param array $columns Existing columns
 * @return array Modified columns
 */
function custom_add_wordcount_column($columns) {
    $columns['custom_wordcount'] = 'Word Count';
    return $columns;
}
add_filter('manage_posts_columns', 'custom_add_wordcount_column');

/**
 * Display word count in custom column
 * 
 * @param string $column_name The column being rendered
 */
function custom_display_wordcount_column($column_name) {
    global $post;
    
    if ($column_name === 'custom_wordcount') {
        echo custom_get_post_wordcount($post->ID);
    }
}
add_action('manage_posts_custom_column', 'custom_display_wordcount_column');

/**
 * Calculate word count for a post
 * 
 * @param int $post_id The post ID
 * @return int Word count
 */
function custom_get_post_wordcount($post_id) {
    $content = get_post_field('post_content', $post_id);
    $content = strip_tags(strip_shortcodes($content));
    return str_word_count($content);
}

/*--------------------------------------------------------------
# TextHover Bracket Cleaner
--------------------------------------------------------------*/

/**
 * Initialize TextHover Bracket Remover
 * 
 * Schedules daily cron job to clean square brackets from texthover fields.
 */
function texthover_bracket_remover_init() {
    if (!wp_next_scheduled('texthover_clean_brackets_daily')) {
        wp_schedule_event(time(), 'daily', 'texthover_clean_brackets_daily');
    }
    
    add_action('texthover_clean_brackets_daily', 'texthover_clean_brackets_batch');
}
add_action('init', 'texthover_bracket_remover_init');

/**
 * Add admin menu under Tools
 */
function texthover_add_admin_menu() {
    add_management_page(
        'TextHover Bracket Cleaner',
        'TextHover Cleaner',
        'manage_options',
        'texthover-bracket-cleaner',
        'texthover_admin_page'
    );
}
add_action('admin_menu', 'texthover_add_admin_menu');

/**
 * Render TextHover Cleaner admin page
 */
function texthover_admin_page() {
    $log_file = WP_CONTENT_DIR . '/texthover-bracket-cleaner.log';
    
    // Handle form submissions
    if (isset($_POST['run_manual']) && wp_verify_nonce($_POST['texthover_nonce'], 'texthover_manual_run')) {
        $result = texthover_clean_brackets_batch();
        if ($result['processed'] > 0) {
            echo '<div class="notice notice-success"><p>Manual cleaning completed! Processed ' . $result['processed'] . ' posts.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>No posts were processed. Debug: ' . $result['debug'] . '</p></div>';
        }
    }
    
    if (isset($_POST['debug_run']) && wp_verify_nonce($_POST['texthover_nonce'], 'texthover_debug_run')) {
        $debug_info = texthover_debug_posts();
        echo '<div class="notice notice-info" style="white-space: pre-wrap;"><p><strong>Debug:</strong><br>' . esc_html($debug_info) . '</p></div>';
    }
    
    if (isset($_POST['clear_log']) && wp_verify_nonce($_POST['texthover_nonce'], 'texthover_clear_log')) {
        if (file_exists($log_file)) {
            unlink($log_file);
            echo '<div class="notice notice-success"><p>Log file cleared!</p></div>';
        }
    }
    
    // Get stats
    $total_posts_with_brackets = count(get_posts_with_texthover_brackets(1000));
    $next_scheduled = wp_next_scheduled('texthover_clean_brackets_daily');
    $next_run = $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled';
    
    ?>
    <div class="wrap">
        <h1>TextHover Bracket Cleaner</h1>
        
        <div class="card">
            <h2>Status</h2>
            <table class="form-table">
                <tr><th>Posts with brackets:</th><td><?php echo $total_posts_with_brackets; ?></td></tr>
                <tr><th>Next scheduled run:</th><td><?php echo $next_run; ?></td></tr>
                <tr><th>Log file:</th><td><?php echo $log_file; ?></td></tr>
            </table>
        </div>
        
        <div class="card">
            <h2>Actions</h2>
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <?php wp_nonce_field('texthover_manual_run', 'texthover_nonce'); ?>
                <input type="submit" name="run_manual" class="button button-primary" value="Run Manual Cleaning" />
            </form>
            
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <?php wp_nonce_field('texthover_debug_run', 'texthover_nonce'); ?>
                <input type="submit" name="debug_run" class="button button-secondary" value="Debug Posts" />
            </form>
            
            <form method="post" style="display: inline-block;">
                <?php wp_nonce_field('texthover_clear_log', 'texthover_nonce'); ?>
                <input type="submit" name="clear_log" class="button button-secondary" value="Clear Log" onclick="return confirm('Clear log file?');" />
            </form>
        </div>
        
        <div class="card">
            <h2>Log</h2>
            <?php texthover_display_log($log_file); ?>
        </div>
    </div>
    
    <style>
        .texthover-log { background: #f1f1f1; border: 1px solid #ccc; padding: 15px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; }
        .card { margin-bottom: 20px; }
    </style>
    <?php
}

/**
 * Debug function to show posts with brackets
 * 
 * @return string Debug information
 */
function texthover_debug_posts() {
    $posts_with_brackets = get_posts_with_texthover_brackets(5);
    $debug_info = "Found " . count($posts_with_brackets) . " posts with brackets:\n\n";
    
    foreach ($posts_with_brackets as $post_id) {
        $content = get_post_meta($post_id, 'texthover', true);
        $debug_info .= "Post ID: {$post_id}\n";
        $debug_info .= "Title: " . get_the_title($post_id) . "\n";
        $debug_info .= "Content preview: " . substr($content, 0, 200) . "...\n";
        $debug_info .= "Has brackets: " . (preg_match('/\[[^\]]+\]/', $content) ? 'YES' : 'NO') . "\n---\n";
    }
    
    return $debug_info;
}

/**
 * Display log file contents
 * 
 * @param string $log_file Path to log file
 */
function texthover_display_log($log_file) {
    if (!file_exists($log_file)) {
        echo '<p><em>No log file found.</em></p>';
        return;
    }
    
    $content = file_get_contents($log_file);
    if (empty(trim($content))) {
        echo '<p><em>Log file is empty.</em></p>';
        return;
    }
    
    $lines = array_reverse(array_filter(explode("\n", trim($content))));
    echo '<div class="texthover-log">';
    foreach ($lines as $line) {
        echo '<div>' . esc_html($line) . '</div>';
    }
    echo '</div>';
}

/**
 * Clean brackets in batches
 * 
 * @return array Processing results
 */
function texthover_clean_brackets_batch() {
    $batch_size = 50;
    $processed_posts = [];
    $debug_info = [];
    
    $posts_with_brackets = get_posts_with_texthover_brackets($batch_size);
    $debug_info[] = "Found " . count($posts_with_brackets) . " posts";
    
    if (empty($posts_with_brackets)) {
        return ['processed' => 0, 'debug' => 'No posts found with brackets'];
    }
    
    foreach ($posts_with_brackets as $post_id) {
        $result = process_post_texthover($post_id);
        if ($result['success']) {
            $processed_posts[] = $post_id;
        }
        $debug_info[] = "Post {$post_id}: " . $result['message'];
        usleep(10000);
    }
    
    if (!empty($processed_posts)) {
        log_texthover_changes($processed_posts);
    }
    
    if (count($posts_with_brackets) == $batch_size) {
        wp_schedule_single_event(time() + 3600, 'texthover_clean_brackets_daily');
    }
    
    return ['processed' => count($processed_posts), 'debug' => implode(" | ", $debug_info)];
}

/**
 * Get posts with texthover fields containing brackets
 * 
 * @param int $limit Maximum posts to return
 * @return array Post IDs
 */
function get_posts_with_texthover_brackets($limit = 50) {
    global $wpdb;
    
    $results = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
        WHERE meta_key = 'texthover' AND meta_value LIKE %s AND meta_value != '' LIMIT %d",
        '%[%]%',
        $limit
    ));
    
    return $results ? array_map('intval', $results) : [];
}

/**
 * Process individual post's texthover field
 * 
 * @param int $post_id Post ID
 * @return array Result with success status and message
 */
function process_post_texthover($post_id) {
    $content = get_post_meta($post_id, 'texthover', true);
    
    if (empty($content) || !is_string($content)) {
        return ['success' => false, 'message' => 'Empty or invalid content'];
    }
    
    if (!preg_match('/\[[^\]]+\]/', $content)) {
        return ['success' => false, 'message' => 'No brackets found'];
    }
    
    $original = $content;
    
    // Remove brackets from line starts and before =>
    $cleaned = preg_replace('/^\[([^\]]+)\]/m', '$1', $content);
    $cleaned = preg_replace('/\[([^\]]+)\]\s*=>/m', '$1 =>', $cleaned);
    
    if ($cleaned !== $original) {
        update_post_meta($post_id, 'texthover', $cleaned);
        return ['success' => true, 'message' => 'Updated'];
    }
    
    return ['success' => false, 'message' => 'No changes needed'];
}

/**
 * Log changes to texthover fields
 * 
 * @param array $post_ids Processed post IDs
 * @param string $custom_message Optional custom message
 */
function log_texthover_changes($post_ids, $custom_message = '') {
    $log_file = WP_CONTENT_DIR . '/texthover-bracket-cleaner.log';
    $timestamp = current_time('Y-m-d H:i:s');
    
    if (!empty($custom_message)) {
        $entry = sprintf("[%s] %s\n", $timestamp, $custom_message);
    } elseif (!empty($post_ids)) {
        $entry = sprintf("[%s] Processed %d posts - IDs: %s\n", $timestamp, count($post_ids), implode(', ', $post_ids));
    } else {
        $entry = sprintf("[%s] No posts processed\n", $timestamp);
    }
    
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    cleanup_texthover_old_logs($log_file);
}

/**
 * Remove log entries older than 1 year
 * 
 * @param string $log_file Log file path
 */
function cleanup_texthover_old_logs($log_file) {
    if (!file_exists($log_file)) {
        return;
    }
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES);
    $cutoff = date('Y-m-d', strtotime('-1 year'));
    $filtered = [];
    
    foreach ($lines as $line) {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2})/', $line, $matches) && $matches[1] >= $cutoff) {
            $filtered[] = $line;
        }
    }
    
    if (count($filtered) !== count($lines)) {
        file_put_contents($log_file, implode("\n", $filtered) . "\n", LOCK_EX);
    }
}

/**
 * Cleanup on deactivation
 */
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('texthover_clean_brackets_daily');
});