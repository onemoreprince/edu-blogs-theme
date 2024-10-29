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
document.addEventListener('DOMContentLoaded', function() {
    // Select all submenu toggle buttons
    var submenuToggles = document.querySelectorAll('.wp-block-navigation-submenu__toggle');

    submenuToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();

            // Toggle 'open' class on parent <li> element
            var parentLi = toggle.closest('li');
            if (parentLi) {
                parentLi.classList.toggle('open');
            }

            // Update aria-expanded attribute for accessibility
            var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', !isExpanded);
        });
    });
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