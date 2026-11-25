<?php
/**
 * One-time Template Resets
 *
 * Templates edited in the Site Editor are saved in the database and override
 * the theme's file versions. When a theme release fixes a template file, list
 * it here so every site running the theme drops its database copy once and
 * falls back to the updated file (same as "Reset" in the Site Editor).
 *
 * Each key is the theme version that ships the fix. Resets run once per site,
 * tracked by the 'edu_template_resets_done' option.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Templates to reset, keyed by theme version.
 *
 * @return array<string, string[]> Version => list of template slugs.
 */
function edu_template_resets_list() {
    return array(
        // Same-category previous/next post navigation in single.html.
        '4.10.0' => array('single'),
    );
}

/**
 * Delete database copies of listed templates that haven't been reset yet.
 *
 * @return void
 */
function edu_run_template_resets() {
    $done    = get_option('edu_template_resets_done', array());
    $done    = is_array($done) ? $done : array();
    $pending = array_diff(array_keys(edu_template_resets_list()), $done);

    if (empty($pending)) {
        return;
    }

    $resets = edu_template_resets_list();
    $theme  = get_stylesheet();

    foreach ($pending as $version) {
        foreach ($resets[$version] as $slug) {
            $template = get_block_template($theme . '//' . $slug, 'wp_template');

            // Only customized templates have a database post; file-only ones need nothing.
            if ($template && !empty($template->wp_id) && 'custom' === $template->source) {
                wp_delete_post($template->wp_id, true);
            }
        }
        $done[] = $version;
    }

    update_option('edu_template_resets_done', $done);
}
// Priority 20: the wp_theme taxonomy used by block templates is registered on init.
add_action('init', 'edu_run_template_resets', 20);
