<?php
/**
 * Category Redirect Manager
 *
 * Auto-creates 301 redirects when a category slug is renamed, so existing
 * URLs of the form /%category%/%postname%/ continue to resolve.
 *
 * - Detects slug changes via edit_terms / edited_term hooks (categories only).
 * - Stores rules in a custom DB table for efficient first-segment lookup.
 * - Serves redirects on template_redirect, only for 404s.
 * - Provides an admin UI under Posts > Category Redirects for CRUD + bulk ops.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
# Constants
--------------------------------------------------------------*/

define('EDU_CAT_REDIRECT_TABLE', 'edu_category_redirects');
define('EDU_CAT_REDIRECT_DB_VERSION', '1.0');
define('EDU_CAT_REDIRECT_DB_VERSION_OPTION', 'edu_category_redirects_db_version');

/*--------------------------------------------------------------
# Table Setup
--------------------------------------------------------------*/

/**
 * Full table name with WP prefix.
 */
function edu_cat_redirect_table_name() {
    global $wpdb;
    return $wpdb->prefix . EDU_CAT_REDIRECT_TABLE;
}

/**
 * Create/upgrade the redirect table via dbDelta.
 *
 * Idempotent: only runs full schema check when stored version differs.
 */
function edu_cat_redirect_maybe_install_table() {
    $installed = get_option(EDU_CAT_REDIRECT_DB_VERSION_OPTION);
    if ($installed === EDU_CAT_REDIRECT_DB_VERSION) {
        return;
    }

    global $wpdb;
    $table   = edu_cat_redirect_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        old_slug VARCHAR(200) NOT NULL,
        new_slug VARCHAR(200) NOT NULL,
        term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY  (id),
        UNIQUE KEY old_slug (old_slug),
        KEY is_active (is_active)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option(EDU_CAT_REDIRECT_DB_VERSION_OPTION, EDU_CAT_REDIRECT_DB_VERSION);
}
add_action('after_setup_theme', 'edu_cat_redirect_maybe_install_table', 20);

/*--------------------------------------------------------------
# Slug Change Detection
--------------------------------------------------------------*/

/**
 * In-memory store of old slugs captured before update.
 * Keyed by term_id.
 */
function edu_cat_redirect_pre_update_cache(&$set = null) {
    static $cache = array();
    if (is_array($set)) {
        $cache = $set;
    }
    return $cache;
}

/**
 * Capture the OLD term data before WordPress writes the update.
 *
 * Runs on edit_terms (pre-update). We can't compare here because the new
 * values haven't been applied yet — we just remember the old slug.
 *
 * @param int    $term_id
 * @param string $taxonomy
 */
function edu_cat_redirect_capture_old_slug($term_id, $taxonomy) {
    if ($taxonomy !== 'category') {
        return;
    }

    $term = get_term($term_id, 'category');
    if (!$term || is_wp_error($term)) {
        return;
    }

    $cache = edu_cat_redirect_pre_update_cache();
    $cache[(int) $term_id] = $term->slug;
    edu_cat_redirect_pre_update_cache($cache);
}
add_action('edit_terms', 'edu_cat_redirect_capture_old_slug', 10, 2);

/**
 * After term update: compare new slug with the captured old one and
 * record a redirect if they differ.
 *
 * @param int    $term_id
 * @param int    $tt_id
 * @param string $taxonomy
 */
function edu_cat_redirect_handle_slug_change($term_id, $tt_id, $taxonomy) {
    if ($taxonomy !== 'category') {
        return;
    }

    $cache = edu_cat_redirect_pre_update_cache();
    if (empty($cache[(int) $term_id])) {
        return;
    }

    $old_slug = $cache[(int) $term_id];
    unset($cache[(int) $term_id]);
    edu_cat_redirect_pre_update_cache($cache);

    $term = get_term($term_id, 'category');
    if (!$term || is_wp_error($term)) {
        return;
    }
    $new_slug = $term->slug;

    if ($old_slug === $new_slug) {
        return;
    }

    edu_cat_redirect_insert_or_update($old_slug, $new_slug, (int) $term_id);
}
add_action('edited_term', 'edu_cat_redirect_handle_slug_change', 10, 3);

/*--------------------------------------------------------------
# Insert / Chain-Collapse Logic
--------------------------------------------------------------*/

/**
 * Insert a redirect, collapsing chains so all old slugs point to the
 * latest new_slug. Skips self-references.
 *
 * @param string $old_slug
 * @param string $new_slug
 * @param int    $term_id
 * @return bool|int Inserted/updated row id, or false on no-op.
 */
function edu_cat_redirect_insert_or_update($old_slug, $new_slug, $term_id = 0) {
    global $wpdb;
    $table = edu_cat_redirect_table_name();
    $now   = current_time('mysql');

    $old_slug = sanitize_title($old_slug);
    $new_slug = sanitize_title($new_slug);

    if ($old_slug === '' || $new_slug === '' || $old_slug === $new_slug) {
        return false;
    }

    // Chain-collapse: any existing row whose new_slug equals our old_slug
    // should jump straight to the new target.
    $wpdb->update(
        $table,
        array('new_slug' => $new_slug, 'updated_at' => $now),
        array('new_slug' => $old_slug),
        array('%s', '%s'),
        array('%s')
    );

    // If a row already exists for this old_slug, refresh it; else insert.
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$table} WHERE old_slug = %s LIMIT 1",
        $old_slug
    ));

    if ($existing) {
        $wpdb->update(
            $table,
            array(
                'new_slug'   => $new_slug,
                'term_id'    => $term_id,
                'is_active'  => 1,
                'updated_at' => $now,
            ),
            array('id' => $existing->id),
            array('%s', '%d', '%d', '%s'),
            array('%d')
        );
        return (int) $existing->id;
    }

    $wpdb->insert(
        $table,
        array(
            'old_slug'   => $old_slug,
            'new_slug'   => $new_slug,
            'term_id'    => $term_id,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ),
        array('%s', '%s', '%d', '%d', '%s', '%s')
    );

    return (int) $wpdb->insert_id;
}

/*--------------------------------------------------------------
# Redirect Serving
--------------------------------------------------------------*/

/**
 * On 404, check whether the first path segment maps to a known old slug.
 * If so, 301 to the rebuilt URL with new_slug.
 */
function edu_cat_redirect_maybe_redirect() {
    if (!is_404()) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($request_uri === '') {
        return;
    }

    $parts = wp_parse_url($request_uri);
    $path  = isset($parts['path']) ? $parts['path'] : '';
    $query = isset($parts['query']) ? $parts['query'] : '';

    // Account for installs in a subdirectory.
    $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
    if ($home_path && $home_path !== '/' && strpos($path, $home_path) === 0) {
        $path = substr($path, strlen($home_path) - 1);
    }

    $trimmed  = trim($path, '/');
    if ($trimmed === '') {
        return;
    }

    $segments    = explode('/', $trimmed);
    $first       = $segments[0];
    $new_slug    = edu_cat_redirect_lookup_active($first);
    if (!$new_slug) {
        return;
    }

    // Prevent loop: if new_slug equals first segment, nothing to do.
    if ($new_slug === $first) {
        return;
    }

    $segments[0] = $new_slug;
    $new_path    = '/' . implode('/', $segments);

    // Preserve trailing slash from the original request.
    if (substr($path, -1) === '/') {
        $new_path = trailingslashit($new_path);
    }

    $target = home_url($new_path);
    if ($query !== '') {
        $target .= '?' . $query;
    }

    wp_safe_redirect($target, 301);
    exit;
}
add_action('template_redirect', 'edu_cat_redirect_maybe_redirect');

/**
 * Look up an active redirect target for a slug.
 *
 * @param string $old_slug
 * @return string|null new_slug or null
 */
function edu_cat_redirect_lookup_active($old_slug) {
    global $wpdb;
    $table = edu_cat_redirect_table_name();

    $new = $wpdb->get_var($wpdb->prepare(
        "SELECT new_slug FROM {$table} WHERE old_slug = %s AND is_active = 1 LIMIT 1",
        $old_slug
    ));

    return $new ? $new : null;
}

/*--------------------------------------------------------------
# Admin Menu
--------------------------------------------------------------*/

/**
 * Register the submenu under Posts.
 * Sits alongside Categories.
 */
function edu_cat_redirect_register_menu() {
    add_posts_page(
        __('Category Redirects', 'education-blogs'),
        __('Category Redirects', 'education-blogs'),
        'manage_categories',
        'edu-category-redirects',
        'edu_cat_redirect_render_page'
    );
}
add_action('admin_menu', 'edu_cat_redirect_register_menu');

/*--------------------------------------------------------------
# List Table
--------------------------------------------------------------*/

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for category redirects.
 */
class Edu_Category_Redirects_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => 'redirect',
            'plural'   => 'redirects',
            'ajax'     => false,
        ));
    }

    public function get_columns() {
        return array(
            'cb'         => '<input type="checkbox" />',
            'old_slug'   => __('Old Slug', 'education-blogs'),
            'arrow'      => '',
            'new_slug'   => __('New Slug', 'education-blogs'),
            'is_active'  => __('Status', 'education-blogs'),
            'created_at' => __('Created', 'education-blogs'),
            'updated_at' => __('Updated', 'education-blogs'),
        );
    }

    protected function get_sortable_columns() {
        return array(
            'old_slug'   => array('old_slug', false),
            'new_slug'   => array('new_slug', false),
            'is_active'  => array('is_active', false),
            'created_at' => array('created_at', true),
            'updated_at' => array('updated_at', false),
        );
    }

    public function get_bulk_actions() {
        return array(
            'delete'     => __('Delete', 'education-blogs'),
            'activate'   => __('Activate', 'education-blogs'),
            'deactivate' => __('Deactivate', 'education-blogs'),
        );
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="redirect_ids[]" value="%d" />', $item['id']);
    }

    protected function column_old_slug($item) {
        $edit_url = wp_nonce_url(
            add_query_arg(array(
                'page'   => 'edu-category-redirects',
                'action' => 'edit',
                'id'     => $item['id'],
            ), admin_url('edit.php')),
            'edu_cat_redirect_edit_' . $item['id']
        );

        $delete_url = wp_nonce_url(
            add_query_arg(array(
                'page'   => 'edu-category-redirects',
                'action' => 'delete',
                'id'     => $item['id'],
            ), admin_url('edit.php')),
            'edu_cat_redirect_delete_' . $item['id']
        );

        $toggle_url = wp_nonce_url(
            add_query_arg(array(
                'page'   => 'edu-category-redirects',
                'action' => 'toggle',
                'id'     => $item['id'],
            ), admin_url('edit.php')),
            'edu_cat_redirect_toggle_' . $item['id']
        );

        $toggle_label = $item['is_active'] ? __('Deactivate', 'education-blogs') : __('Activate', 'education-blogs');

        $actions = array(
            'edit'   => sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html__('Edit', 'education-blogs')),
            'toggle' => sprintf('<a href="%s">%s</a>', esc_url($toggle_url), esc_html($toggle_label)),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($delete_url),
                esc_js(__('Delete this redirect?', 'education-blogs')),
                esc_html__('Delete', 'education-blogs')
            ),
        );

        return sprintf(
            '<strong>%s</strong>%s',
            esc_html($item['old_slug']),
            $this->row_actions($actions)
        );
    }

    protected function column_arrow($item) {
        return '<span aria-hidden="true" style="color:#888;">&rarr;</span>';
    }

    protected function column_new_slug($item) {
        return esc_html($item['new_slug']);
    }

    protected function column_is_active($item) {
        if ($item['is_active']) {
            return '<span style="color:#1a7f1a;font-weight:600;">' . esc_html__('Active', 'education-blogs') . '</span>';
        }
        return '<span style="color:#999;">' . esc_html__('Inactive', 'education-blogs') . '</span>';
    }

    protected function column_created_at($item) {
        return esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $item['created_at']));
    }

    protected function column_updated_at($item) {
        return esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $item['updated_at']));
    }

    protected function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
    }

    public function no_items() {
        esc_html_e('No redirects yet. They will be created automatically when you rename a category slug.', 'education-blogs');
    }

    public function prepare_items() {
        global $wpdb;
        $table = edu_cat_redirect_table_name();

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $offset       = ($current_page - 1) * $per_page;

        $orderby_input = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'created_at';
        $order_input   = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';

        $allowed = array('id', 'old_slug', 'new_slug', 'is_active', 'created_at', 'updated_at');
        $orderby = in_array($orderby_input, $allowed, true) ? $orderby_input : 'created_at';

        $search = isset($_GET['s']) ? trim(wp_unslash($_GET['s'])) : '';

        $where = '1=1';
        $params = array();
        if ($search !== '') {
            $like   = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (old_slug LIKE %s OR new_slug LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($total_sql, $params))
            : (int) $wpdb->get_var($total_sql);

        $rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order_input} LIMIT %d OFFSET %d";
        $rows_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $rows_params), ARRAY_A);

        $this->items = $rows ? $rows : array();

        $this->set_pagination_args(array(
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ));
    }
}

/*--------------------------------------------------------------
# Admin Page Renderer + Action Handlers
--------------------------------------------------------------*/

/**
 * Render the admin page (list + add/edit forms).
 */
function edu_cat_redirect_render_page() {
    if (!current_user_can('manage_categories')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'education-blogs'));
    }

    edu_cat_redirect_handle_actions();

    $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
    $editing_id = ($action === 'edit' && isset($_GET['id'])) ? (int) $_GET['id'] : 0;

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Category Redirects', 'education-blogs'); ?></h1>
        <a href="#edu-redirect-add-form" class="page-title-action"><?php esc_html_e('Add New', 'education-blogs'); ?></a>
        <hr class="wp-header-end">

        <?php edu_cat_redirect_print_admin_notices(); ?>

        <p class="description" style="max-width:780px;">
            <?php esc_html_e('Redirects are created automatically when you rename a category slug. They serve as 301 redirects only when the original URL would otherwise return a 404, so newly created content using a freed-up slug is never affected.', 'education-blogs'); ?>
        </p>

        <?php if ($editing_id): ?>
            <?php edu_cat_redirect_render_edit_form($editing_id); ?>
        <?php endif; ?>

        <form method="get">
            <input type="hidden" name="page" value="edu-category-redirects" />
            <?php
            $list = new Edu_Category_Redirects_List_Table();
            $list->prepare_items();
            $list->search_box(__('Search slugs', 'education-blogs'), 'edu-redirect-search');
            ?>
            <?php wp_nonce_field('edu_cat_redirect_bulk', 'edu_cat_redirect_bulk_nonce'); ?>
            <?php $list->display(); ?>
        </form>

        <h2 id="edu-redirect-add-form" style="margin-top:30px;"><?php esc_html_e('Add New Redirect', 'education-blogs'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('edu_cat_redirect_add', 'edu_cat_redirect_add_nonce'); ?>
            <input type="hidden" name="edu_cat_redirect_action" value="add" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="add_old_slug"><?php esc_html_e('Old Slug', 'education-blogs'); ?></label></th>
                    <td><input type="text" id="add_old_slug" name="old_slug" class="regular-text" required />
                        <p class="description"><?php esc_html_e('The slug visitors are arriving at (no slashes).', 'education-blogs'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="add_new_slug"><?php esc_html_e('New Slug', 'education-blogs'); ?></label></th>
                    <td><input type="text" id="add_new_slug" name="new_slug" class="regular-text" required />
                        <p class="description"><?php esc_html_e('The slug to redirect them to.', 'education-blogs'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Add Redirect', 'education-blogs')); ?>
        </form>
    </div>
    <?php
}

/**
 * Render the inline edit form for a single redirect.
 *
 * @param int $id
 */
function edu_cat_redirect_render_edit_form($id) {
    global $wpdb;
    $table = edu_cat_redirect_table_name();
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

    if (!$row) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Redirect not found.', 'education-blogs') . '</p></div>';
        return;
    }

    ?>
    <div class="card" style="max-width:780px;">
        <h2><?php esc_html_e('Edit Redirect', 'education-blogs'); ?> #<?php echo (int) $row['id']; ?></h2>
        <form method="post">
            <?php wp_nonce_field('edu_cat_redirect_update_' . $row['id'], 'edu_cat_redirect_update_nonce'); ?>
            <input type="hidden" name="edu_cat_redirect_action" value="update" />
            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="edit_old_slug"><?php esc_html_e('Old Slug', 'education-blogs'); ?></label></th>
                    <td><input type="text" id="edit_old_slug" name="old_slug" class="regular-text" value="<?php echo esc_attr($row['old_slug']); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="edit_new_slug"><?php esc_html_e('New Slug', 'education-blogs'); ?></label></th>
                    <td><input type="text" id="edit_new_slug" name="new_slug" class="regular-text" value="<?php echo esc_attr($row['new_slug']); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Status', 'education-blogs'); ?></th>
                    <td>
                        <label><input type="checkbox" name="is_active" value="1" <?php checked(1, (int) $row['is_active']); ?> /> <?php esc_html_e('Active', 'education-blogs'); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Changes', 'education-blogs')); ?>
            <a href="<?php echo esc_url(admin_url('edit.php?page=edu-category-redirects')); ?>" class="button"><?php esc_html_e('Cancel', 'education-blogs'); ?></a>
        </form>
    </div>
    <?php
}

/**
 * Handle all admin actions (add, update, delete, toggle, bulk).
 * Sets transient-backed notice that gets printed on next render.
 */
function edu_cat_redirect_handle_actions() {
    if (!current_user_can('manage_categories')) {
        return;
    }

    global $wpdb;
    $table = edu_cat_redirect_table_name();
    $now   = current_time('mysql');

    // Add
    if (isset($_POST['edu_cat_redirect_action']) && $_POST['edu_cat_redirect_action'] === 'add') {
        check_admin_referer('edu_cat_redirect_add', 'edu_cat_redirect_add_nonce');

        $old = isset($_POST['old_slug']) ? sanitize_title(wp_unslash($_POST['old_slug'])) : '';
        $new = isset($_POST['new_slug']) ? sanitize_title(wp_unslash($_POST['new_slug'])) : '';

        if ($old === '' || $new === '') {
            edu_cat_redirect_set_notice('error', __('Both slugs are required.', 'education-blogs'));
        } elseif ($old === $new) {
            edu_cat_redirect_set_notice('error', __('Old and new slugs cannot match.', 'education-blogs'));
        } else {
            $id = edu_cat_redirect_insert_or_update($old, $new, 0);
            if ($id) {
                edu_cat_redirect_set_notice('success', __('Redirect saved.', 'education-blogs'));
            } else {
                edu_cat_redirect_set_notice('error', __('Could not save redirect.', 'education-blogs'));
            }
        }
    }

    // Update
    if (isset($_POST['edu_cat_redirect_action']) && $_POST['edu_cat_redirect_action'] === 'update') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        check_admin_referer('edu_cat_redirect_update_' . $id, 'edu_cat_redirect_update_nonce');

        $old = isset($_POST['old_slug']) ? sanitize_title(wp_unslash($_POST['old_slug'])) : '';
        $new = isset($_POST['new_slug']) ? sanitize_title(wp_unslash($_POST['new_slug'])) : '';
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || $old === '' || $new === '') {
            edu_cat_redirect_set_notice('error', __('Invalid input.', 'education-blogs'));
        } elseif ($old === $new) {
            edu_cat_redirect_set_notice('error', __('Old and new slugs cannot match.', 'education-blogs'));
        } else {
            // Check duplicate old_slug on another row.
            $clash = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE old_slug = %s AND id != %d LIMIT 1",
                $old, $id
            ));
            if ($clash) {
                edu_cat_redirect_set_notice('error', __('Another redirect already uses this Old Slug.', 'education-blogs'));
            } else {
                $wpdb->update(
                    $table,
                    array(
                        'old_slug'   => $old,
                        'new_slug'   => $new,
                        'is_active'  => $active,
                        'updated_at' => $now,
                    ),
                    array('id' => $id),
                    array('%s', '%s', '%d', '%s'),
                    array('%d')
                );
                edu_cat_redirect_set_notice('success', __('Redirect updated.', 'education-blogs'));
                wp_safe_redirect(admin_url('edit.php?page=edu-category-redirects'));
                exit;
            }
        }
    }

    // Single-row actions via GET
    $get_action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
    $get_id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($get_id > 0 && in_array($get_action, array('delete', 'toggle'), true)) {
        check_admin_referer('edu_cat_redirect_' . $get_action . '_' . $get_id);

        if ($get_action === 'delete') {
            $wpdb->delete($table, array('id' => $get_id), array('%d'));
            edu_cat_redirect_set_notice('success', __('Redirect deleted.', 'education-blogs'));
        } elseif ($get_action === 'toggle') {
            $row = $wpdb->get_row($wpdb->prepare("SELECT is_active FROM {$table} WHERE id = %d", $get_id));
            if ($row) {
                $wpdb->update(
                    $table,
                    array('is_active' => $row->is_active ? 0 : 1, 'updated_at' => $now),
                    array('id' => $get_id),
                    array('%d', '%s'),
                    array('%d')
                );
                edu_cat_redirect_set_notice('success', __('Redirect status toggled.', 'education-blogs'));
            }
        }

        wp_safe_redirect(admin_url('edit.php?page=edu-category-redirects'));
        exit;
    }

    // Bulk actions (POST from list table)
    if (
        isset($_REQUEST['edu_cat_redirect_bulk_nonce']) &&
        wp_verify_nonce($_REQUEST['edu_cat_redirect_bulk_nonce'], 'edu_cat_redirect_bulk')
    ) {
        $bulk_action = '';
        if (isset($_REQUEST['action']) && $_REQUEST['action'] !== '-1') {
            $bulk_action = sanitize_key($_REQUEST['action']);
        } elseif (isset($_REQUEST['action2']) && $_REQUEST['action2'] !== '-1') {
            $bulk_action = sanitize_key($_REQUEST['action2']);
        }

        $ids = isset($_REQUEST['redirect_ids']) ? array_map('intval', (array) $_REQUEST['redirect_ids']) : array();
        $ids = array_filter($ids);

        if ($bulk_action && !empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));

            if ($bulk_action === 'delete') {
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));
                edu_cat_redirect_set_notice('success', sprintf(_n('%d redirect deleted.', '%d redirects deleted.', count($ids), 'education-blogs'), count($ids)));
            } elseif ($bulk_action === 'activate') {
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_active = 1, updated_at = '{$now}' WHERE id IN ({$placeholders})", $ids));
                edu_cat_redirect_set_notice('success', sprintf(_n('%d redirect activated.', '%d redirects activated.', count($ids), 'education-blogs'), count($ids)));
            } elseif ($bulk_action === 'deactivate') {
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_active = 0, updated_at = '{$now}' WHERE id IN ({$placeholders})", $ids));
                edu_cat_redirect_set_notice('success', sprintf(_n('%d redirect deactivated.', '%d redirects deactivated.', count($ids), 'education-blogs'), count($ids)));
            }
        }
    }
}

/*--------------------------------------------------------------
# Admin Notices (transient-backed)
--------------------------------------------------------------*/

/**
 * Store a one-time admin notice for the current user.
 *
 * @param string $type    success|error|warning|info
 * @param string $message
 */
function edu_cat_redirect_set_notice($type, $message) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }
    set_transient('edu_cat_redirect_notice_' . $user_id, array('type' => $type, 'message' => $message), 30);
}

/**
 * Print and clear any pending notice for the current user.
 */
function edu_cat_redirect_print_admin_notices() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }
    $notice = get_transient('edu_cat_redirect_notice_' . $user_id);
    if (!$notice || empty($notice['message'])) {
        return;
    }
    delete_transient('edu_cat_redirect_notice_' . $user_id);

    $class = 'notice notice-' . sanitize_html_class($notice['type']) . ' is-dismissible';
    printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
}
