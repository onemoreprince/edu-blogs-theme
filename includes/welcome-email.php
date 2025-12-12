<?php

/**
 * 
 * New user email with magic link
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 1. Hook into user registration to send the custom email.
 */
add_action('user_register', 'send_welcome_email_with_magic_link', 10, 1);

function send_welcome_email_with_magic_link($user_id) {
    $user = get_userdata($user_id);
    
    // Generate a secure token
    $token = bin2hex(random_bytes(32));
    
    // Save token and expiry (24 hours) in user meta
    update_user_meta($user_id, 'magic_login_token', $token);
    update_user_meta($user_id, 'magic_login_expires', time() + (24 * 60 * 60));

    // Build the Magic Login URL
    $login_url = add_query_arg(
        [
            'magic_login' => $token,
            'uid'         => $user_id,
        ],
        home_url('/') 
    );

    // Email Subject
    $subject = 'Welcome! Here is your quick login link';

    // Email Body (HTML)
    $message  = 'Hi ' . $user->display_name . ',<br><br>';
    $message .= 'Your account has been successfully created.<br><br>';
    $message .= '<strong>Your Login Details:</strong><br>';
    $message .= 'Username/Email: ' . $user->user_login . '<br>';
    
    $message .= '<strong>Quick Access:</strong><br>';
    $message .= '<a href="' . esc_url($login_url) . '">Click here to Auto-Login to your account</a><br><br>';
    
    $message .= '<em>(Note: This link is valid for 24 hours and can only be used once.)</em><br><br>';
    
    $message .= '<strong>Getting Started Help:</strong><br>';
    $message .= '1. Once logged in, please go to your profile to set an password fo reasy login or you can also loigin using magic link by simply enterimng your same email on the login page.<br>';
    $message .= '2. You can access your dashboard from the top menu.<br>';
    $message .= '3. If you have questions, reply to this email.<br><br>';
    $message .= 'Enjoy Ad-Free Expereince!';

    // Set headers for HTML email
    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Send the email
    wp_mail($user->user_email, $subject, $message, $headers);
}

/**
 * 2. Listen for the Magic Link click to log the user in.
 */
add_action('init', 'handle_magic_login');

function handle_magic_login() {
    // Check if URL parameters exist
    if (isset($_GET['magic_login']) && isset($_GET['uid'])) {
        
        $user_id = intval($_GET['uid']);
        $token   = sanitize_text_field($_GET['magic_login']);
        
        // Retrieve stored token and expiry
        $stored_token = get_user_meta($user_id, 'magic_login_token', true);
        $expiry       = get_user_meta($user_id, 'magic_login_expires', true);

        // Validate Token and Expiry
        if ($stored_token && $token === $stored_token && $expiry > time()) {
            
            // 1. Log the user in
            wp_set_auth_cookie($user_id);
            
            // 2. Clear the token (One-time use security)
            delete_user_meta($user_id, 'magic_login_token');
            delete_user_meta($user_id, 'magic_login_expires');
            
            // 3. Redirect to a specific page (e.g., Dashboard or Home)
            wp_redirect(home_url()); // Change admin_url() to home_url('/my-account') if preferred
            exit;
            
        } else {
            wp_die('This login link is invalid or has expired. Please request a password reset.');
        }
    }
}

/**
 * Optional: Disable the default WordPress "New User" notification
 * to prevent the user from receiving two emails.
 */
add_filter('wp_new_user_notification_email', '__return_false');
?>