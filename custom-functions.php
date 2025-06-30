<?php
// Google Adsense Code & Instant Page & Menu Collapse 
function add_google_adsense_script() {
    ?>
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-7772226184406759"
        crossorigin="anonymous"></script>
        <script src="//instant.page/5.2.0" type="module" integrity="sha384-jnZyxPjiipYXnSU0ygqeac2q7CVYMbh84q0uHVRRxEtvFPiQYbXWUorga2aqZJ0z"></script>
        <script>
	document.querySelectorAll('p').forEach(function(paragraph) {
    if (paragraph.textContent.startsWith('[Image:') && paragraph.textContent.endsWith(']')) {
        paragraph.style.display = 'none';
    }
});
</script>
<script>
// Get all submenu items
const submenus = document.querySelectorAll('.wp-block-navigation-submenu');

// Add click handlers to each submenu
submenus.forEach(submenu => {
  submenu.addEventListener('click', function(e) {
    // Get the actual clicked element
    const clickedElement = e.target.closest('.wp-block-navigation-submenu');
    
    // Only proceed if we clicked this submenu (not a child submenu)
    if (clickedElement === this) {
      // Stop event from reaching parent submenus
      e.stopPropagation();
      
      // Toggle 'open' class on the clicked submenu
      this.classList.toggle('open');
      
      // Optional: Close sibling submenus at the same level
      const parent = this.parentElement;
      const siblings = parent.querySelectorAll(':scope > .wp-block-navigation-submenu');
      siblings.forEach(sibling => {
        if (sibling !== this && sibling.classList.contains('open')) {
          sibling.classList.remove('open');
        }
      });
    }
  });
});

// Optional: Close submenus when clicking outside
document.addEventListener('click', function(e) {
  if (!e.target.closest('.wp-block-navigation-submenu')) {
    submenus.forEach(submenu => {
      submenu.classList.remove('open');
    });
  }
});

</script>
    <?php
}
add_action('wp_head', 'add_google_adsense_script');

//Show Word Count
add_filter('manage_posts_columns', 'wpbeginner_add_column');
function wpbeginner_add_column($wpbeginner_wordcount_column) {
    $wpbeginner_wordcount_column['wpbeginner_wordcount'] = 'Word Count';
    return $wpbeginner_wordcount_column;
}
  
//Link the word count to our new column//
add_action('manage_posts_custom_column',  'wpbeginner_display_wordcount'); 
function wpbeginner_display_wordcount($name) 
{
   global $post;
   switch ($name)
{
     case 'wpbeginner_wordcount':
        //Get the post ID and pass it into the get_wordcount function//
            $wpbeginner_wordcount = wpbeginner_get_wordcount($post->ID);
            echo $wpbeginner_wordcount;
     }
}
 
function wpbeginner_get_wordcount($post_id) {
     //Get the post, remove any unnecessary tags and then perform the word count// 
     $wpbeginner_wordcount = str_word_count( strip_tags( strip_shortcodes(get_post_field( 'post_content', $post_id )) ) );
      return $wpbeginner_wordcount;
}

// Current Year Shortcode
function current_year_shortcode() {
    return date('Y');
}
add_shortcode('Year', 'current_year_shortcode');

// Syllabus on single post
function syllabus_shortcode() {
    $output = '';

    if ( is_single() ) {
        // Get the categories of the current post
        $categories = get_the_category();
        
        if ( ! empty( $categories ) ) {
            foreach ( $categories as $category ) {
                // Get the ACF field 'syllabus' for the category
                $syllabus = get_field('syllabus', 'category_' . $category->term_id);
                
                if ( $syllabus ) {
                    // Return the syllabus content (since it's a WYSIWYG, it contains HTML)
                    $output .= '<div class="syllabus-sidebar"><h4>' . esc_html( $category->name ) . '</h4><p>' . $syllabus . '</p></div>';
                }
            }
        }
    } elseif ( is_category() ) {
        // Get the current category
        $category = get_queried_object();
        
        if ( $category instanceof WP_Term ) {
            // Get the ACF field 'syllabus' for the category
            $syllabus = get_field('syllabus', 'category_' . $category->term_id);
            
            if ( $syllabus ) {
                // Return the syllabus content (since it's a WYSIWYG, it contains HTML)
                $output = '<div class="syllabus-sidebar"><h4>' . esc_html( $category->name ) . '</h4><p>' . $syllabus . '</p></div>';
            }
        }
    }
    
    return $output; // Return the generated output or an empty string if no syllabus is found
}
add_shortcode('category_syllabus', 'syllabus_shortcode');

// Modifying text hover feature
function replace_text_hover($content) {
    global $post;
    $texthover = get_post_meta($post->ID, 'texthover', true);
    if (!empty($texthover)) {
        $lines = explode("\n", $texthover);
        $texthover_array = array();
        foreach ($lines as $line) {
            list($phrase, $definition) = explode(' => ', $line);
            $texthover_array[trim($phrase)] = trim($definition);
        }
        foreach ($texthover_array as $phrase => $definition) {
            // Check if the phrase exists in the content directly without regex
            if (strpos($content, $phrase) !== false) {
                // Use preg_quote only when matching to prevent any regex errors
                $escaped_phrase = preg_quote($phrase, '/');
                $content = preg_replace('/' . $escaped_phrase . '(?![^<]*>)/', '<span class="text-hover" data-tooltip="'.$definition.'">'.$phrase.'</span>', $content, 1);
            }
        }
    }
    return $content;
}
add_filter('the_content', 'replace_text_hover');


// adding table of content

// Add ToC and modify content
function add_table_of_contents($content) {
    if (!is_single()) return $content;
    
    // Find all H2 and H3 headings
    preg_match_all('/<h[23].*?>(.*?)<\/h[23]>/i', $content, $matches);
    
    if (count($matches[0]) < 3) return $content; // Only proceed if 3 or more headings
    
    $toc = '<div class="post-toc"><h4>Table of Contents</h4><ul>';
    $modified_content = $content;
    
    foreach ($matches[0] as $index => $heading) {
        // Get heading text and level
        $heading_text = strip_tags($matches[1][$index]);
        preg_match('/<h([23])/i', $heading, $level);
        $heading_level = $level[1];
        
        // Create anchor ID
        $anchor_id = sanitize_title($heading_text);
        
        // Add to ToC
        $toc .= sprintf(
            '<li class="toc-h%s"><a href="#%s">%s</a></li>',
            $heading_level,
            $anchor_id,
            $heading_text
        );
        
        // Modify original heading to add ID and copy link button
        $new_heading = sprintf(
            '<h%s id="%s">%s <button class="copy-link" data-link="%s">🔗</button></h%s>',
            $heading_level,
            $anchor_id,
            $heading_text,
            esc_url(get_permalink() . '#' . $anchor_id),
            $heading_level
        );
        
        $modified_content = preg_replace('/' . preg_quote($heading, '/') . '/', $new_heading, $modified_content, 1);
    }
    
    $toc .= '</ul></div>';
    
    // Add CSS
    $toc .= '<style>
        .post-toc {
            background: #f8f9fa;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 5px;
            border: 1px solid #e9ecef;
        }
        .post-toc h4 {
            margin-top: 0;
            margin-bottom: 15px;
        }
        .post-toc ul {
            list-style: none;
            padding-left: 0;
            margin: 0;
        }
        .post-toc .toc-h3 {
            padding-left: 20px;
        }
        .post-toc a {
            color: #333;
            text-decoration: none;
            line-height: 1.7;
        }
        .post-toc a:hover {
            text-decoration: underline;
        }
        .copy-link {
            background: none;
            border: none;
            padding: 0;
            margin-left: 5px;
            cursor: pointer;
            font-size: 0.9em;
            opacity: 0.7;
        }
        .copy-link:hover {
            opacity: 1;
        }
    </style>';
    
    // Add JavaScript for clipboard functionality using native APIs
    $toc .= "<script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.copy-link').forEach(button => {
                button.addEventListener('click', function(e) {
                    const link = this.getAttribute('data-link');
                    
                    // Create a temporary textarea to copy the text
                    const textarea = document.createElement('textarea');
                    textarea.value = link;
                    textarea.style.position = 'fixed';  // Prevent scrolling to bottom
                    document.body.appendChild(textarea);
                    textarea.select();
                    
                    try {
                        // Execute copy command
                        document.execCommand('copy');
                        
                        // Show notification
                        if ('Notification' in window) {
                            if (Notification.permission === 'granted') {
                                new Notification('Link Copied!', {
                                    body: link
                                });
                            } else if (Notification.permission !== 'denied') {
                                Notification.requestPermission().then(function(permission) {
                                    if (permission === 'granted') {
                                        new Notification('Link Copied!', {
                                            body: link
                                        });
                                    }
                                });
                            }
                        }
                    } catch (err) {
                        console.error('Failed to copy link:', err);
                    }
                    
                    // Clean up
                    document.body.removeChild(textarea);
                });
            });
        });
    </script>";
    
    // Find first heading and insert ToC before it
    return preg_replace('/(<h[23].*?>.*?<\/h[23]>)/i', $toc . '$1', $modified_content, 1);
}
add_filter('the_content', 'add_table_of_contents', 20);

/**
 * WordPress Markdown to HTML Converter
 * 
 * This script converts Markdown-style formatting to HTML in WordPress posts
 * while maintaining performance by processing a limited number of posts at a time.
 */

 class WP_Markdown_Fixer {
    private $batch_size = 10; // Number of posts to process per batch
    private $last_processed_id = 0; // Track last processed post ID
    
    public function __construct() {
        // Hook into WordPress content filtering
        add_filter('the_content', array($this, 'fix_markdown_in_content'), 20);
        add_filter('content_save_pre', array($this, 'fix_markdown_in_content'), 20);
        
        // Schedule periodic processing of existing posts
        add_action('init', array($this, 'schedule_processing'));
        add_action('wp_markdown_fixer_cron', array($this, 'process_existing_posts'));
    }
    
    /**
     * Schedule the cron job if not already scheduled
     */
    public function schedule_processing() {
        if (!wp_next_scheduled('wp_markdown_fixer_cron')) {
            wp_schedule_event(time(), 'hourly', 'wp_markdown_fixer_cron');
        }
    }
    
    /**
     * Convert Markdown-style formatting to HTML
     */
    public function fix_markdown_in_content($content) {
        if (empty($content)) {
            return $content;
        }
        
        // Convert **text** to <strong>text</strong>
        $content = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $content);
        
        // Remove any empty strong tags
        $content = str_replace('<strong></strong>', '', $content);
        
        return $content;
    }
    
    /**
     * Process existing posts in small batches
     */
    public function process_existing_posts() {
        global $wpdb;
        
        // Get batch of posts that haven't been processed yet
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content 
                FROM {$wpdb->posts} 
                WHERE post_type = 'post' 
                AND ID > %d 
                AND post_content LIKE '%%**%%'
                ORDER BY ID ASC 
                LIMIT %d",
                $this->last_processed_id,
                $this->batch_size
            )
        );
        
        if (empty($posts)) {
            // Reset last processed ID if no more posts found
            $this->last_processed_id = 0;
            update_option('wp_markdown_fixer_last_id', 0);
            return;
        }
        
        foreach ($posts as $post) {
            $updated_content = $this->fix_markdown_in_content($post->post_content);
            
            // Only update if content has changed
            if ($updated_content !== $post->post_content) {
                $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $updated_content),
                    array('ID' => $post->ID),
                    array('%s'),
                    array('%d')
                );
                
                // Clear post cache
                clean_post_cache($post->ID);
            }
            
            $this->last_processed_id = $post->ID;
        }
        
        // Store the last processed ID
        update_option('wp_markdown_fixer_last_id', $this->last_processed_id);
    }
}

// Initialize the class
function init_wp_markdown_fixer() {
    new WP_Markdown_Fixer();
}

// Hook into WordPress init
add_action('init', 'init_wp_markdown_fixer');


/**
 * TextHover Custom Field Square Bracket Remover - DEBUG VERSION
 * Removes square brackets from 'textHover' custom fields across all posts
 * Runs daily via WordPress cron with logging and admin interface
 */

// Hook into WordPress initialization
add_action('init', 'texthover_bracket_remover_init');

function texthover_bracket_remover_init() {
    // Schedule the daily cron job if not already scheduled
    if (!wp_next_scheduled('texthover_clean_brackets_daily')) {
        wp_schedule_event(time(), 'daily', 'texthover_clean_brackets_daily');
    }
    
    // Hook the cleanup function to the cron event
    add_action('texthover_clean_brackets_daily', 'texthover_clean_brackets_batch');
}

/**
 * Add admin menu under Tools
 */
add_action('admin_menu', 'texthover_add_admin_menu');

function texthover_add_admin_menu() {
    add_management_page(
        'TextHover Bracket Cleaner',
        'TextHover Cleaner',
        'manage_options',
        'texthover-bracket-cleaner',
        'texthover_admin_page'
    );
}

/**
 * Admin page callback
 */
function texthover_admin_page() {
    $log_file = WP_CONTENT_DIR . '/texthover-bracket-cleaner.log';
    
    // Handle manual run
    if (isset($_POST['run_manual']) && wp_verify_nonce($_POST['texthover_nonce'], 'texthover_manual_run')) {
        $result = texthover_clean_brackets_batch();
        if ($result['processed'] > 0) {
            echo '<div class="notice notice-success"><p>Manual cleaning completed! Processed ' . $result['processed'] . ' posts. Check the log below for details.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>Manual cleaning completed but no posts were processed. Debug info: ' . $result['debug'] . '</p></div>';
        }
    }
    
    // Handle debug run
    if (isset($_POST['debug_run']) && wp_verify_nonce($_POST['texthover_nonce'], 'texthover_debug_run')) {
        $debug_info = texthover_debug_posts();
        echo '<div class="notice notice-info"><p><strong>Debug Information:</strong><br>' . nl2br(esc_html($debug_info)) . '</p></div>';
    }
    
    // Handle log clearing
    if (isset($_POST['clear_log']) && wp_verify_nonce($_POST['texthover_nonce'], 'texthover_clear_log')) {
        if (file_exists($log_file)) {
            unlink($log_file);
            echo '<div class="notice notice-success"><p>Log file cleared successfully!</p></div>';
        }
    }
    
    // Get current stats
    $total_posts_with_brackets = count(get_posts_with_textHover_brackets(1000));
    $next_scheduled = wp_next_scheduled('texthover_clean_brackets_daily');
    $next_run = $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled';
    
    ?>
    <div class="wrap">
        <h1>TextHover Bracket Cleaner</h1>
        
        <div class="card">
            <h2>Status</h2>
            <table class="form-table">
                <tr>
                    <th>Posts with brackets remaining:</th>
                    <td><?php echo $total_posts_with_brackets; ?></td>
                </tr>
                <tr>
                    <th>Next scheduled run:</th>
                    <td><?php echo $next_run; ?></td>
                </tr>
                <tr>
                    <th>Log file location:</th>
                    <td><?php echo $log_file; ?></td>
                </tr>
                <tr>
                    <th>Log file exists:</th>
                    <td><?php echo file_exists($log_file) ? 'Yes' : 'No'; ?></td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2>Actions</h2>
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <?php wp_nonce_field('texthover_manual_run', 'texthover_nonce'); ?>
                <input type="submit" name="run_manual" class="button button-primary" value="Run Manual Cleaning" />
                <p class="description">Process up to 50 posts immediately</p>
            </form>
            
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <?php wp_nonce_field('texthover_debug_run', 'texthover_nonce'); ?>
                <input type="submit" name="debug_run" class="button button-secondary" value="Debug Posts" />
                <p class="description">Show debug information about posts with brackets</p>
            </form>
            
            <form method="post" style="display: inline-block;">
                <?php wp_nonce_field('texthover_clear_log', 'texthover_nonce'); ?>
                <input type="submit" name="clear_log" class="button button-secondary" value="Clear Log File" onclick="return confirm('Are you sure you want to clear the log file?');" />
                <p class="description">Remove all log entries</p>
            </form>
        </div>
        
        <div class="card">
            <h2>Log File Contents</h2>
            <?php texthover_display_log_content($log_file); ?>
        </div>
    </div>
    
    <style>
    .texthover-log-container {
        background: #f1f1f1;
        border: 1px solid #ccc;
        padding: 15px;
        max-height: 500px;
        overflow-y: auto;
        font-family: monospace;
        font-size: 12px;
        line-height: 1.5;
        white-space: pre-wrap;
    }
    .texthover-log-entry {
        margin-bottom: 3px;
        padding: 2px 0;
    }
    .texthover-log-date {
        color: #0073aa;
        font-weight: bold;
    }
    .texthover-log-posts {
        color: #d54e21;
    }
    .card {
        margin-bottom: 20px;
    }
    </style>
    <?php
}

/**
 * Debug function to show what posts have brackets
 */
function texthover_debug_posts() {
    $posts_with_brackets = get_posts_with_textHover_brackets(10); // Get first 10 for debugging
    $debug_info = "Found " . count($posts_with_brackets) . " posts with brackets:\n\n";
    
    foreach ($posts_with_brackets as $post_id) {
        $post_title = get_the_title($post_id);
        $textHover_content = get_post_meta($post_id, 'textHover', true);
        
        $debug_info .= "Post ID: {$post_id}\n";
        $debug_info .= "Post Title: {$post_title}\n";
        $debug_info .= "TextHover Content (first 200 chars): " . substr($textHover_content, 0, 200) . "...\n";
        $debug_info .= "Has brackets: " . (preg_match('/\[[^\]]+\]/', $textHover_content) ? 'YES' : 'NO') . "\n";
        $debug_info .= "Content length: " . strlen($textHover_content) . " characters\n";
        $debug_info .= "---\n\n";
    }
    
    return $debug_info;
}

/**
 * Display log file content with formatting
 */
function texthover_display_log_content($log_file) {
    if (!file_exists($log_file)) {
        echo '<p><em>No log file found. The cleaner hasn\'t run yet or no changes have been made.</em></p>';
        return;
    }
    
    $log_content = file_get_contents($log_file);
    
    if (empty(trim($log_content))) {
        echo '<p><em>Log file is empty.</em></p>';
        return;
    }
    
    // Split into lines and reverse to show newest first
    $lines = array_reverse(array_filter(explode("\n", trim($log_content))));
    
    if (empty($lines)) {
        echo '<p><em>No log entries found.</em></p>';
        return;
    }
    
    echo '<div class="texthover-log-container">';
    
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        
        // Parse log line format: [2025-06-30 10:30:15] Processed 3 posts - Post IDs: 123, 456, 789
        if (preg_match('/^\[([^\]]+)\]\s*(.+)$/', $line, $matches)) {
            $date = $matches[1];
            $message = $matches[2];
            
            echo '<div class="texthover-log-entry">';
            echo '<span class="texthover-log-date">[' . esc_html($date) . ']</span> ';
            echo '<span class="texthover-log-message">' . esc_html($message) . '</span>';
            echo '</div>';
        } else {
            // Fallback for malformed lines
            echo '<div class="texthover-log-entry">' . esc_html($line) . '</div>';
        }
    }
    
    echo '</div>';
    
    // Show file size and entry count
    $file_size = size_format(filesize($log_file));
    $entry_count = count($lines);
    echo '<p class="description">Log file size: ' . $file_size . ' | Total entries: ' . $entry_count . '</p>';
}

/**
 * Main function to clean brackets in batches - UPDATED WITH DEBUG INFO
 */
function texthover_clean_brackets_batch() {
    $batch_size = 50;
    $processed_posts = [];
    $debug_info = [];
    
    // Get all posts with textHover meta field containing brackets
    $posts_with_brackets = get_posts_with_textHover_brackets($batch_size);
    
    $debug_info[] = "Found " . count($posts_with_brackets) . " posts with brackets";
    
    if (empty($posts_with_brackets)) {
        return [
            'processed' => 0,
            'debug' => 'No posts found with brackets'
        ];
    }
    
    foreach ($posts_with_brackets as $post_id) {
        $result = process_post_textHover($post_id);
        if ($result['success']) {
            $processed_posts[] = $post_id;
        }
        $debug_info[] = "Post {$post_id}: " . $result['message'];
        
        // Small delay to reduce server strain
        usleep(10000); // 0.01 second delay
    }
    
    // Log the changes if any posts were processed
    if (!empty($processed_posts)) {
        log_texthover_changes($processed_posts);
    } else {
        // Log that we tried but found nothing to process
        log_texthover_changes([], "No posts processed - " . implode("; ", $debug_info));
    }
    
    // If we processed a full batch, schedule another run in 1 hour
    if (count($posts_with_brackets) == $batch_size) {
        wp_schedule_single_event(time() + 3600, 'texthover_clean_brackets_daily');
    }
    
    return [
        'processed' => count($processed_posts),
        'debug' => implode(" | ", $debug_info)
    ];
}

/**
 * Get posts that have textHover fields with square brackets
 */
function get_posts_with_textHover_brackets($limit = 50) {
    global $wpdb;
    
    $query = $wpdb->prepare("
        SELECT DISTINCT post_id 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = 'textHover' 
        AND meta_value LIKE %s 
        AND meta_value != ''
        LIMIT %d
    ", '%[%]%', $limit);
    
    $results = $wpdb->get_col($query);
    
    return $results ? array_map('intval', $results) : [];
}

/**
 * Process individual post's textHover field - UPDATED WITH DEBUG INFO
 */
function process_post_textHover($post_id) {
    $textHover_content = get_post_meta($post_id, 'textHover', true);
    
    if (empty($textHover_content) || !is_string($textHover_content)) {
        return [
            'success' => false,
            'message' => 'Empty or invalid content'
        ];
    }
    
    // Check if content has square brackets pattern
    if (!preg_match('/\[[^\]]+\]/', $textHover_content)) {
        return [
            'success' => false,
            'message' => 'No brackets found'
        ];
    }
    
    $original_content = $textHover_content;
    
    // Remove square brackets from the beginning of lines
    $cleaned_content = preg_replace('/^\[([^\]]+)\]/m', '$1', $textHover_content);
    
    // Also handle cases where brackets might not be at line start
    $cleaned_content = preg_replace('/\[([^\]]+)\]\s*=>/m', '$1 =>', $cleaned_content);
    
    // Update the meta field if content changed
    if ($cleaned_content !== $original_content) {
        $update_result = update_post_meta($post_id, 'textHover', $cleaned_content);
        return [
            'success' => true,
            'message' => 'Updated successfully (' . ($update_result ? 'DB updated' : 'DB update failed') . ')'
        ];
    }
    
    return [
        'success' => false,
        'message' => 'No changes needed after processing'
    ];
}

/**
 * Log changes made to textHover fields - UPDATED
 */
function log_texthover_changes($post_ids, $custom_message = '') {
    $log_file = WP_CONTENT_DIR . '/texthover-bracket-cleaner.log';
    $timestamp = current_time('Y-m-d H:i:s');
    
    if (!empty($custom_message)) {
        $log_entry = sprintf("[%s] %s\n", $timestamp, $custom_message);
    } elseif (!empty($post_ids)) {
        $post_ids_string = implode(', ', $post_ids);
        $log_entry = sprintf(
            "[%s] Processed %d posts - Post IDs: %s\n",
            $timestamp,
            count($post_ids),
            $post_ids_string
        );
    } else {
        $log_entry = sprintf("[%s] No posts processed\n", $timestamp);
    }
    
    // Append to log file
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Clean old log entries (keep only last year)
    cleanup_old_logs($log_file);
}

/**
 * Clean up log entries older than 1 year
 */
function cleanup_old_logs($log_file) {
    if (!file_exists($log_file)) {
        return;
    }
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES);
    $cutoff_date = date('Y-m-d', strtotime('-1 year'));
    $filtered_lines = [];
    
    foreach ($lines as $line) {
        // Extract date from log line format [Y-m-d H:i:s]
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2})/', $line, $matches)) {
            if ($matches[1] >= $cutoff_date) {
                $filtered_lines[] = $line;
            }
        }
    }
    
    // Rewrite log file with filtered content
    if (count($filtered_lines) !== count($lines)) {
        file_put_contents($log_file, implode("\n", $filtered_lines) . "\n", LOCK_EX);
    }
}

/**
 * Deactivation hook to clean up scheduled events
 */
register_deactivation_hook(__FILE__, 'texthover_bracket_remover_deactivate');

function texthover_bracket_remover_deactivate() {
    wp_clear_scheduled_hook('texthover_clean_brackets_daily');
}