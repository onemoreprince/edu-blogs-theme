<?php
/**
 * Custom Functions - Main Loader
 * 
 * This file loads all custom functionality modules.
 * Each module is organized by feature type for better maintainability.
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define the includes directory path
define('CUSTOM_INCLUDES_PATH', get_stylesheet_directory() . '/includes/');

/**
 * Load all custom function modules
 */
function load_custom_modules() {
    $modules = array(
        'ads-and-scripts.php',      // AdSense, Instant Page, Menu scripts
        'content-enhancements.php', // ToC, References, Text Hover, Markdown
        'admin-features.php',       // Word Count, TextHover Cleaner
        'shortcodes.php',           // All shortcodes
        'welcome-email.php',           // All shortcodes

    );

    foreach ($modules as $module) {
        $file_path = CUSTOM_INCLUDES_PATH . $module;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}
add_action('after_setup_theme', 'load_custom_modules');