<?php
/**
 * Register the "Ad-free" submenu under Settings.
 */
function ad_free_settings_menu() {
    add_options_page(
        'Ad-free Settings',     // Page Title
        'Ad-free',              // Menu Title
        'manage_options',       // Capability required
        'ad-free-settings',     // Menu Slug
        'ad_free_settings_page_html' // Callback function
    );
}
add_action('admin_menu', 'ad_free_settings_menu');

/**
 * Register settings and fields.
 */
function ad_free_register_settings() {
    // Register the URL setting
    register_setting('ad_free_options_group', 'ad_free_paypal_url', array(
        'sanitize_callback' => 'esc_url_raw'
    ));

    // Register the Checkbox setting
    register_setting('ad_free_options_group', 'ad_free_enabled');

    // Add a section
    add_settings_section(
        'ad_free_main_section',
        'Configuration',
        null,
        'ad-free-settings'
    );

    // Add URL Field
    add_settings_field(
        'ad_free_paypal_url',
        'PayPal Payment Page Link',
        'ad_free_url_callback',
        'ad-free-settings',
        'ad_free_main_section'
    );

    // Add Checkbox Field
    add_settings_field(
        'ad_free_enabled',
        'Enable Ad-free Options',
        'ad_free_checkbox_callback',
        'ad-free-settings',
        'ad_free_main_section'
    );
}
add_action('admin_init', 'ad_free_register_settings');

/**
 * HTML Callback for URL Field
 */
function ad_free_url_callback() {
    $url = get_option('ad_free_paypal_url');
    echo '<input type="url" id="ad_free_paypal_url" name="ad_free_paypal_url" value="' . esc_attr($url) . '" class="regular-text" placeholder="https://paypal.com/..." />';
}

/**
 * HTML Callback for Checkbox Field
 */
function ad_free_checkbox_callback() {
    $enabled = get_option('ad_free_enabled');
    // If the URL is empty, we force the checkbox to be unchecked in the PHP logic too, just in case
    $url = get_option('ad_free_paypal_url');
    
    $is_checked = ($enabled && !empty($url)) ? 1 : 0;
    
    echo '<input type="checkbox" id="ad_free_enabled" name="ad_free_enabled" value="1" ' . checked(1, $is_checked, false) . ' />';
    echo '<p class="description">Check this to show ad-free features on the frontend.</p>';
}

/**
 * Render the Settings Page HTML & JavaScript for logic
 */
function ad_free_settings_page_html() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Ad-free Experience Settings</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('ad_free_options_group');
            do_settings_sections('ad-free-settings');
            submit_button();
            ?>
        </form>
    </div>

    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        const urlField = document.getElementById('ad_free_paypal_url');
        const checkBox = document.getElementById('ad_free_enabled');

        function toggleCheckboxState() {
            if (urlField.value.trim() === "") {
                checkBox.disabled = true;
                checkBox.checked = false; // Uncheck if URL is cleared
            } else {
                checkBox.disabled = false;
            }
        }

        // Run on load
        toggleCheckboxState();

        // Run on typing/pasting
        urlField.addEventListener('input', toggleCheckboxState);
    });
    </script>
    <?php
}


// // Here is how to retrieve the status (returns '1' if checked, false/empty if not)
// $is_ad_free_active = get_option('ad_free_enabled');
// $payment_url = get_option('ad_free_paypal_url');

// // We double check that the URL exists before trusting the "enabled" switch
// if ( $is_ad_free_active && !empty($payment_url) ) {
//     // RUN YOUR LOGIC HERE:
//     // e.g., Show the "Remove Ads" button
//     // e.g., Hide the ad blocks
// }

// // Use this variable in your href="" attribute
// $my_paypal_link = get_option('ad_free_paypal_url');

// // Example usage:
// // echo '<a href="' . esc_url($my_paypal_link) . '">Go Ad-Free</a>';

?>


