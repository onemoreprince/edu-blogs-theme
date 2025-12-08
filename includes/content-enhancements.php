<?php
/**
 * Content Enhancements
 * 
 * Features that modify or enhance post content:
 * - Table of Contents
 * - External Link References
 * - Text Hover Tooltips
 * - Image Placeholder Hiding
 * - Markdown to HTML Conversion
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Table of Contents
--------------------------------------------------------------*/

/**
 * Add Table of Contents to single posts
 * 
 * Automatically generates a ToC from H2 and H3 headings.
 * Only appears if post has 3+ headings.
 * Includes copy-to-clipboard functionality for heading links.
 * 
 * @param string $content The post content
 * @return string Modified content with ToC
 */
function add_table_of_contents($content) {
    if (!is_single()) {
        return $content;
    }
    
    // Find all H2 and H3 headings
    preg_match_all('/<h[23].*?>(.*?)<\/h[23]>/i', $content, $matches);
    
    // Only proceed if 3 or more headings exist
    if (count($matches[0]) < 3) {
        return $content;
    }
    
    $toc = '<div class="post-toc"><h4>Table of Contents</h4><ul>';
    $modified_content = $content;
    
    foreach ($matches[0] as $index => $heading) {
        $heading_text = strip_tags($matches[1][$index]);
        preg_match('/<h([23])/i', $heading, $level);
        $heading_level = $level[1];
        $anchor_id = sanitize_title($heading_text);
        
        // Build ToC item
        $toc .= sprintf(
            '<li class="toc-h%s"><a href="#%s">%s</a></li>',
            $heading_level,
            $anchor_id,
            $heading_text
        );
        
        // Add ID and copy button to heading
        $new_heading = sprintf(
            '<h%s id="%s">%s <button class="copy-link" data-link="%s">🔗</button></h%s>',
            $heading_level,
            $anchor_id,
            $heading_text,
            esc_url(get_permalink() . '#' . $anchor_id),
            $heading_level
        );
        
        $modified_content = preg_replace(
            '/' . preg_quote($heading, '/') . '/',
            $new_heading,
            $modified_content,
            1
        );
    }
    
    $toc .= '</ul></div>';
    $toc .= get_toc_styles();
    $toc .= get_toc_scripts();
    
    // Insert ToC before first heading
    return preg_replace('/(<h[23].*?>.*?<\/h[23]>)/i', $toc . '$1', $modified_content, 1);
}
add_filter('the_content', 'add_table_of_contents', 20);

/**
 * Get ToC CSS styles
 * 
 * @return string CSS styles wrapped in style tags
 */
function get_toc_styles() {
    return '<style>
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
        .toast-notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--wp--preset--color--accent);
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            font-weight: 500;
            z-index: 9999;
            animation: slideIn 0.3s ease-out, slideOut 0.3s ease-in 2.7s;
            pointer-events: none;
        }
        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(400px); opacity: 0; }
        }
    </style>';
}

/**
 * Get ToC JavaScript for copy functionality
 * 
 * @return string JavaScript wrapped in script tags
 */
function get_toc_scripts() {
    return "<script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.copy-link').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const link = this.getAttribute('data-link');
                    
                    const textarea = document.createElement('textarea');
                    textarea.value = link;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    
                    try {
                        document.execCommand('copy');
                        showToast('Link Copied ✅');
                    } catch (err) {
                        console.error('Failed to copy link:', err);
                        showToast('Failed to copy link ❌');
                    }
                    
                    document.body.removeChild(textarea);
                });
            });
            
            function showToast(message) {
                const existingToast = document.querySelector('.toast-notification');
                if (existingToast) existingToast.remove();
                
                const toast = document.createElement('div');
                toast.className = 'toast-notification';
                toast.textContent = message;
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    if (toast.parentNode) toast.remove();
                }, 3000);
            }
        });
    </script>";
}

/*--------------------------------------------------------------
# External Link References
--------------------------------------------------------------*/

/**
 * Extract external links and add as numbered reference list
 * 
 * Scans post content for external links (different domain)
 * and appends a "References" section with numbered list.
 * 
 * @param string $content The post content
 * @return string Content with references section appended
 */
function add_references_to_content($content) {
    $site_url = get_site_url();
    $site_domain = parse_url($site_url, PHP_URL_HOST);
    
    // Extract all links
    preg_match_all('/<a\s+(?:[^>]*?\s+)?href=(["\'])(.*?)\1/i', $content, $matches);
    
    if (empty($matches[2])) {
        return $content;
    }
    
    $external_links = array();
    
    foreach ($matches[2] as $url) {
        // Skip empty, anchors, and mailto links
        if (empty($url) || $url[0] === '#' || strpos($url, 'mailto:') === 0) {
            continue;
        }
        
        $parsed_url = parse_url($url);
        
        // Skip relative URLs
        if (!isset($parsed_url['host'])) {
            continue;
        }
        
        // Skip same domain (with/without www)
        if ($parsed_url['host'] === $site_domain || 
            $parsed_url['host'] === 'www.' . $site_domain || 
            'www.' . $parsed_url['host'] === $site_domain) {
            continue;
        }
        
        $external_links[$url] = $url;
    }
    
    // Build references section if external links exist
    if (!empty($external_links)) {
        $references = '<h5>References</h5><ol>';
        
        foreach ($external_links as $url) {
            $references .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a></li>';
        }
        
        $references .= '</ol>';
        $content .= $references;
    }
    
    return $content;
}
add_filter('the_content', 'add_references_to_content');

/*--------------------------------------------------------------
# Text Hover Tooltips
--------------------------------------------------------------*/

/**
 * Add tooltip hover effect to specified terms
 * 
 * Uses 'texthover' custom field with format:
 * term => definition
 * 
 * Case-insensitive matching, replaces first occurrence only.
 * 
 * @param string $content The post content
 * @return string Content with tooltip spans added
 */
function replace_text_hover($content) {
    global $post;
    $texthover = get_post_meta($post->ID, 'texthover', true);
    
    if (empty($texthover)) {
        return $content;
    }
    
    $lines = explode("\n", $texthover);
    $texthover_array = array();
    
    foreach ($lines as $line) {
        // Skip empty lines and lines without delimiter
        if (empty(trim($line)) || strpos($line, ' => ') === false) {
            continue;
        }
        
        list($phrase, $definition) = explode(' => ', $line, 2);
        $phrase = trim($phrase);
        $definition = trim($definition);
        
        // Remove square brackets from phrase
        $phrase = trim($phrase, '[]');
        
        if (!empty($phrase) && !empty($definition)) {
            $texthover_array[$phrase] = $definition;
        }
    }
    
    foreach ($texthover_array as $phrase => $definition) {
        if (stripos($content, $phrase) !== false) {
            $escaped_phrase = preg_quote($phrase, '/');
            
            // Case-insensitive replacement, first occurrence only
            $content = preg_replace_callback(
                '/\b' . $escaped_phrase . '\b(?![^<]*>)/i',
                function($matches) use ($definition) {
                    return '<span class="text-hover" data-tooltip="' . esc_attr($definition) . '">' . $matches[0] . '</span>';
                },
                $content,
                1
            );
        }
    }
    
    return $content;
}
add_filter('the_content', 'replace_text_hover');

/*--------------------------------------------------------------
# Hide Image Placeholders
--------------------------------------------------------------*/

/**
 * Hide paragraphs containing [Image:...] placeholders
 * 
 * Adds CSS and JavaScript to hide placeholder text on single posts/pages.
 * 
 * @return void
 */
function hide_image_placeholder_paragraphs() {
    if (!is_single() && !is_page()) {
        return;
    }
    ?>
    <style type="text/css">
        .entry-content p:has-text("[Image:"),
        .post-content p:has-text("[Image:"),
        .content p:has-text("[Image:") {
            display: none !important;
        }
    </style>
    
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var contentSelectors = ['.entry-content', '.post-content', '.content', 'article', '.single-post'];
            
            contentSelectors.forEach(function(selector) {
                var container = document.querySelector(selector);
                if (container) {
                    var paragraphs = container.querySelectorAll('p');
                    paragraphs.forEach(function(p) {
                        var text = p.textContent.trim();
                        if (text.startsWith('[Image:')) {
                            p.style.display = 'none';
                        }
                    });
                }
            });
        });
    </script>
    <?php
}
add_action('wp_head', 'hide_image_placeholder_paragraphs');

/*--------------------------------------------------------------
# Markdown to HTML Converter
--------------------------------------------------------------*/

/**
 * WordPress Markdown to HTML Converter
 * 
 * Converts Markdown-style **bold** formatting to HTML <strong> tags.
 * Processes existing posts in batches via cron job.
 * 
 * @package CustomTheme
 * @subpackage Content
 */
class WP_Markdown_Fixer {
    
    /** @var int Number of posts to process per batch */
    private $batch_size = 10;
    
    /** @var int Track last processed post ID */
    private $last_processed_id = 0;
    
    /**
     * Constructor - Set up hooks
     */
    public function __construct() {
        add_filter('the_content', array($this, 'fix_markdown_in_content'), 20);
        add_filter('content_save_pre', array($this, 'fix_markdown_in_content'), 20);
        add_action('init', array($this, 'schedule_processing'));
        add_action('wp_markdown_fixer_cron', array($this, 'process_existing_posts'));
    }
    
    /**
     * Schedule hourly cron job for batch processing
     */
    public function schedule_processing() {
        if (!wp_next_scheduled('wp_markdown_fixer_cron')) {
            wp_schedule_event(time(), 'hourly', 'wp_markdown_fixer_cron');
        }
    }
    
    /**
     * Convert **text** to <strong>text</strong>
     * 
     * @param string $content The content to process
     * @return string Processed content
     */
    public function fix_markdown_in_content($content) {
        if (empty($content)) {
            return $content;
        }
        
        $content = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $content);
        $content = str_replace('<strong></strong>', '', $content);
        
        return $content;
    }
    
    /**
     * Process existing posts in batches
     * 
     * Finds posts with markdown syntax and converts them.
     */
    public function process_existing_posts() {
        global $wpdb;
        
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
            $this->last_processed_id = 0;
            update_option('wp_markdown_fixer_last_id', 0);
            return;
        }
        
        foreach ($posts as $post) {
            $updated_content = $this->fix_markdown_in_content($post->post_content);
            
            if ($updated_content !== $post->post_content) {
                $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $updated_content),
                    array('ID' => $post->ID),
                    array('%s'),
                    array('%d')
                );
                
                clean_post_cache($post->ID);
            }
            
            $this->last_processed_id = $post->ID;
        }
        
        update_option('wp_markdown_fixer_last_id', $this->last_processed_id);
    }
}

// Initialize Markdown Fixer
add_action('init', function() {
    new WP_Markdown_Fixer();
});