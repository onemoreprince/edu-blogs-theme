<?php
// Google Adsense Code
function add_google_adsense_script() {
    ?>
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-7772226184406759"
        crossorigin="anonymous"></script>
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
                    $output .= '<div class="syllabus textwidget"><h4>' . esc_html( $category->name ) . '</h4><p>' . $syllabus . '</p></div>';
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
                $output = '<div class="syllabus textwidget"><h4>' . esc_html( $category->name ) . '</h4><p>' . $syllabus . '</p></div>';
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
