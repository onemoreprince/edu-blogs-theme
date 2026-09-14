<?php
/**
 * Nofollow External Links
 *
 * Makes every external link in post content rel="nofollow" by default,
 * EXCEPT links to our own network of educational sites.
 *
 * The network list is NOT configured anywhere in WP admin. It is read straight
 * from the footer template part (parts/footer.html, the "#footer-sites" block),
 * which is the single place we already maintain when adding a new site.
 * Add a site to the footer, push the theme, and every site in the network
 * treats it as "ours" (dofollow) automatically.
 *
 * Rank Math SEO integration (primary path):
 *  - Forces Rank Math's General > Links > "Nofollow External Links" option ON
 *    in memory for front-end requests, so it never has to be enabled site by site.
 *  - Hooks `rank_math/nofollow/url` to whitelist network domains, so the
 *    "Nofollow Exclude Domains" field never has to be maintained.
 *
 * Fallback (Rank Math not active):
 *  - The theme applies rel="nofollow" itself on `the_content` using the same
 *    domain list, so behaviour is identical with or without the plugin.
 *
 * Opt-outs (put in a site's wp-config.php or a mu-plugin):
 *  - add_filter('edu_force_rankmath_nofollow', '__return_false');  // don't force Rank Math option
 *  - add_filter('edu_nofollow_external_links', '__return_false');   // disable this module entirely
 *  - add_filter('edu_network_domains', function($d){ $d[] = 'extra.com'; return $d; });
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Network Domain List (parsed from parts/footer.html)
--------------------------------------------------------------*/

/**
 * Normalise a URL or host to a bare, lowercase host without "www."
 *
 * @param string $url_or_host URL (https://www.slm.mba/) or host (slm.mba)
 * @return string Empty string if no host could be determined
 */
function edu_normalize_host($url_or_host) {
    $value = trim((string) $url_or_host);
    if ($value === '') {
        return '';
    }

    // Protocol-relative and bare hosts need a scheme for wp_parse_url
    if (strpos($value, '//') === 0) {
        $value = 'https:' . $value;
    } elseif (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        $value = 'https://' . $value;
    }

    $host = wp_parse_url($value, PHP_URL_HOST);
    if (empty($host)) {
        return '';
    }

    $host = strtolower($host);
    $host = preg_replace('/^www\./', '', $host);

    return $host;
}

/**
 * Get the list of network domains from the footer template part.
 *
 * Reads parts/footer.html, isolates the "#footer-sites" block and extracts
 * every absolute href. Result is cached per request.
 *
 * @return string[] Lowercase hosts without "www." (e.g. ['slm.mba', 'psychology.town'])
 */
function edu_get_network_domains() {
    static $domains = null;

    if ($domains !== null) {
        return $domains;
    }

    $domains = array();
    $file    = get_theme_file_path('parts/footer.html');

    if (is_readable($file)) {
        $html = file_get_contents($file);

        // Restrict to the network block so unrelated footer links are ignored
        $start = strpos($html, 'id="footer-sites"');
        if ($start !== false) {
            $html = substr($html, $start);
            $end  = strpos($html, '<script');
            if ($end !== false) {
                $html = substr($html, 0, $end);
            }
        }

        if (preg_match_all('/href\s*=\s*(["\'])\s*((?:https?:)?\/\/[^"\']+)\1/i', $html, $matches)) {
            foreach ($matches[2] as $href) {
                $host = edu_normalize_host($href);
                if ($host !== '') {
                    $domains[] = $host;
                }
            }
        }
    }

    // The current site is always "ours"
    $self = edu_normalize_host(home_url());
    if ($self !== '') {
        $domains[] = $self;
    }

    /**
     * Filter the list of network (dofollow) domains.
     *
     * @param string[] $domains Lowercase hosts without "www."
     */
    $domains = apply_filters('edu_network_domains', $domains);
    $domains = array_values(array_unique(array_filter(array_map('edu_normalize_host', (array) $domains))));

    return $domains;
}

/**
 * Check whether a URL points to one of our network sites (or a subdomain of one).
 *
 * @param string $url
 * @return bool
 */
function edu_is_network_url($url) {
    $host = edu_normalize_host($url);
    if ($host === '') {
        return false;
    }

    foreach (edu_get_network_domains() as $domain) {
        if ($host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain) {
            return true;
        }
    }

    return false;
}

/**
 * Whether this module should do anything at all.
 *
 * @return bool
 */
function edu_nofollow_module_enabled() {
    return (bool) apply_filters('edu_nofollow_external_links', true);
}

/*--------------------------------------------------------------
# Rank Math Integration
--------------------------------------------------------------*/

/**
 * Force Rank Math's "Nofollow External Links" option ON for this request.
 *
 * Rank Math lazy-loads its options into memory and exposes a public set()
 * on its Settings object, so we flip the flag there instead of touching the
 * database. Skipped in wp-admin so the settings screen shows the stored value.
 */
function edu_rankmath_force_nofollow_setting() {
    if (!edu_nofollow_module_enabled() || is_admin()) {
        return;
    }

    if (!apply_filters('edu_force_rankmath_nofollow', true)) {
        return;
    }

    if (!function_exists('rank_math')) {
        return;
    }

    $settings = rank_math()->settings;
    if (!is_object($settings) || !method_exists($settings, 'get') || !method_exists($settings, 'set')) {
        return;
    }

    // get() first: it triggers the lazy load so set() doesn't create a partial options array
    if ($settings->get('general.nofollow_external_links')) {
        return;
    }

    $settings->set('general', 'nofollow_external_links', true);
}
// Priority 20: after Rank Math's own init (10), before its `wp` / `rest_api_init` link handlers
add_action('init', 'edu_rankmath_force_nofollow_setting', 20);

/**
 * Tell Rank Math NOT to nofollow links to our own network.
 *
 * @param bool   $nofollow Whether Rank Math intends to add nofollow
 * @param string $url      The link href
 * @return bool
 */
function edu_rankmath_exclude_network_domains($nofollow, $url) {
    if ($nofollow && edu_nofollow_module_enabled() && edu_is_network_url($url)) {
        return false;
    }
    return $nofollow;
}
add_filter('rank_math/nofollow/url', 'edu_rankmath_exclude_network_domains', 10, 2);

/*--------------------------------------------------------------
# Fallback (Rank Math not active)
--------------------------------------------------------------*/

/**
 * Add rel="nofollow" to external, non-network links in post content.
 *
 * Only registered when Rank Math is not loaded. Mirrors Rank Math's rules:
 * skips relative/anchor links, same-site links, links already carrying
 * nofollow or dofollow, and role="button" links.
 *
 * @param string $content
 * @return string
 */
function edu_fallback_nofollow_external_links($content) {
    if (empty($content) || !edu_nofollow_module_enabled() || strpos($content, '<a') === false) {
        return $content;
    }

    return preg_replace_callback(
        '/<a\s[^>]*>/i',
        function ($m) {
            $tag = $m[0];

            if (!preg_match('/\shref\s*=\s*(["\'])(.*?)\1/i', $tag, $href)) {
                return $tag;
            }

            $url = trim($href[2]);
            if ($url === '' || $url[0] === '#' || $url[0] === '/' && substr($url, 0, 2) !== '//') {
                return $tag;
            }

            $host = wp_parse_url((strpos($url, '//') === 0 ? 'https:' : '') . $url, PHP_URL_HOST);
            if (empty($host) || edu_is_network_url($url)) {
                return $tag;
            }

            if (preg_match('/\srole\s*=\s*(["\'])button\1/i', $tag)) {
                return $tag;
            }

            if (preg_match('/\srel\s*=\s*(["\'])(.*?)\1/i', $tag, $rel)) {
                if (preg_match('/\b(no|do)follow\b/i', $rel[2])) {
                    return $tag;
                }
                $new_rel = trim($rel[2] . ' nofollow');
                return str_replace($rel[0], ' rel=' . $rel[1] . $new_rel . $rel[1], $tag);
            }

            return preg_replace('/^<a\s/i', '<a rel="nofollow" ', $tag, 1);
        },
        $content
    );
}

/**
 * Register the fallback only when Rank Math is not active.
 * Runs on init so all plugins have had a chance to load.
 */
function edu_maybe_register_fallback_nofollow() {
    if (function_exists('rank_math')) {
        return;
    }
    // Priority 11 matches Rank Math, so the theme's References list (10) is covered too
    add_filter('the_content', 'edu_fallback_nofollow_external_links', 11);
}
add_action('init', 'edu_maybe_register_fallback_nofollow', 20);
