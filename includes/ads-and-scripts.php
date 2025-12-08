<?php
/**
 * Ads and Frontend Scripts
 * 
 * Handles Google AdSense, Instant Page preloading, and menu functionality.
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if current user should see ads
 * 
 * Excludes administrators and subscribers from seeing AdSense ads.
 * 
 * @return bool True if ads should be shown, false otherwise
 */
function should_show_ads() {
    // Don't show ads if user is logged in and has admin or subscriber role
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $excluded_roles = array('administrator', 'subscriber');
        
        // Check if user has any of the excluded roles
        if (array_intersect($excluded_roles, (array) $user->roles)) {
            return false;
        }
    }
    
    return true;
}

/**
 * Add Google AdSense, Instant Page, and Menu Collapse scripts to head
 * 
 * - Google AdSense: Only loads for non-admin/subscriber users
 * - Instant Page: Preloads pages on hover for faster navigation
 * - Menu Collapse: Handles submenu toggle functionality
 * - Image Placeholder: Hides [Image:...] placeholder text
 * 
 * @return void
 */
function add_frontend_scripts() {
    ?>
    <?php if (should_show_ads()) : ?>
    <!-- Google AdSense -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-7772226184406759" crossorigin="anonymous"></script>
    <?php endif; ?>
    
    <!-- Instant Page - Preload on hover -->
    <script src="//instant.page/5.2.0" type="module" integrity="sha384-jnZyxPjiipYXnSU0ygqeac2q7CVYMbh84q0uHVRRxEtvFPiQYbXWUorga2aqZJ0z"></script>
    
    <!-- Hide Image Placeholders -->
    <script>
    document.querySelectorAll('p').forEach(function(paragraph) {
        if (paragraph.textContent.startsWith('[Image:') && paragraph.textContent.endsWith(']')) {
            paragraph.style.display = 'none';
        }
    });
    </script>
    
    <!-- Submenu Toggle Handler -->
    <script>
    (function() {
        const submenus = document.querySelectorAll('.wp-block-navigation-submenu');

        submenus.forEach(submenu => {
            submenu.addEventListener('click', function(e) {
                const clickedElement = e.target.closest('.wp-block-navigation-submenu');
                
                if (clickedElement === this) {
                    e.stopPropagation();
                    this.classList.toggle('open');
                    
                    // Close sibling submenus
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

        // Close submenus when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.wp-block-navigation-submenu')) {
                submenus.forEach(submenu => {
                    submenu.classList.remove('open');
                });
            }
        });
    })();
    </script>
    <?php
}
add_action('wp_head', 'add_frontend_scripts');