<?php
/**
 * Sheet Modified Date
 *
 * Lets the Google Sheet (Apps Script) set a post's "Last updated" date.
 *
 * The REST API treats `modified` as read-only and WordPress stamps the
 * current time on every update. When a REST post update includes a
 * `modified` value, save that date as the post's real modified date
 * instead, so the page, Rank Math schema/sitemap and admin filters all
 * show the date set in the sheet.
 *
 * Accepted formats: 2026-10-20T09:00:00 (site timezone) or with an
 * offset, e.g. 2026-10-20T09:00:00+05:30. Invalid or future dates are
 * ignored and WordPress keeps its default behavior.
 *
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parse the sheet's `modified` value into a GMT MySQL date
 *
 * @param mixed $modified Value of the `modified` request field
 * @return string|false GMT date (Y-m-d H:i:s), or false if invalid or in the future
 */
function edu_sheet_modified_to_gmt($modified) {
    if (!is_string($modified) || trim($modified) === '') {
        return false;
    }

    $dates = rest_get_date_with_gmt(trim($modified));
    if (!$dates) {
        return false;
    }

    $gmt = $dates[1];
    if (strtotime($gmt . ' UTC') > time()) {
        return false;
    }

    return $gmt;
}

/**
 * Pass the sheet's modified date from the REST request to the post save
 *
 * @param stdClass        $prepared_post Post object prepared for the database
 * @param WP_REST_Request $request       REST request
 * @return stdClass Post object
 */
function edu_sheet_modified_read_request($prepared_post, $request) {
    if (empty($prepared_post->ID)) {
        return $prepared_post;
    }

    $gmt = edu_sheet_modified_to_gmt($request->get_param('modified'));
    if ($gmt) {
        $prepared_post->edu_sheet_modified_gmt = $gmt;
    }

    return $prepared_post;
}
add_filter('rest_pre_insert_post', 'edu_sheet_modified_read_request', 10, 2);

/**
 * Save the sheet's date as the post's modified date
 *
 * @param array $data                Slashed post data for the database
 * @param array $postarr             Sanitized post data
 * @param array $unsanitized_postarr Unsanitized post data
 * @param bool  $update              Whether this is an update
 * @return array Post data
 */
function edu_sheet_modified_apply($data, $postarr, $unsanitized_postarr, $update) {
    if (!$update || empty($unsanitized_postarr['edu_sheet_modified_gmt'])) {
        return $data;
    }

    $gmt = $unsanitized_postarr['edu_sheet_modified_gmt'];
    $data['post_modified_gmt'] = $gmt;
    $data['post_modified']     = get_date_from_gmt($gmt);

    return $data;
}
add_filter('wp_insert_post_data', 'edu_sheet_modified_apply', 10, 4);
