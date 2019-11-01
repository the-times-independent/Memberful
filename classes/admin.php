<?php

namespace RedRock\Subscriptions;

require_once Plugin::defaultInstance()->getPluginDir() . '/src/options.php';
require_once Plugin::defaultInstance()->getPluginDir() . '/src/metabox.php';

add_action('admin_head',            'memberful_wp_announce_plans_and_download_in_head');
add_action('admin_menu',            'memberful_wp_menu');
add_action('admin_init',            'memberful_wp_register_options');
add_filter('display_post_states',   'memberful_wp_add_protected_state_to_post_list', 10, 2);

function _memberful_wp_debug_all_post_meta() {
    global $wpdb;

    $results = $wpdb->get_results(
        "SELECT posts.ID, meta.meta_value FROM {$wpdb->posts} AS posts ".
        "LEFT JOIN {$wpdb->postmeta} AS meta ON (posts.ID = meta.post_id) ".
        "WHERE meta.meta_key = 'memberful_acl';"
 );

    $meta = array();

    foreach($results as $row) {
        $meta[$row->ID] = $row->meta_value;
    }

    return $meta;
}

function memberful_wp_debug() {
    global $wp_version;

    if (! function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $mapping_stats = new Memberful_User_Map_Stats(Memberful_User_Mapping_Repository::table());
    $counts = count_users();

    $unmapped_users = $mapping_stats->unmapped_users();
    $total_mapping_records = $mapping_stats->count_mapping_records();

    $total_users           = $counts['total_users'];
    $total_unmapped_users  = count($unmapped_users);
    $total_mapped_users    = $total_users - $total_unmapped_users;
    $config                = memberful_wp_option_values();
    $acl_for_all_posts     = _memberful_wp_debug_all_post_meta();
    $plugins               = get_plugins();
    $error_log             = memberful_wp_error_log();

    unset($config['memberful_error_log']);

    if ($total_users != $total_mapped_users) {
        $mapping_records = $mapping_stats->mapping_records();
    }
    else {
        $mapping_records = array();
    }

    memberful_wp_render(
        'debug',
        compact(
            'unmapped_users',
            'total_users',
            'total_unmapped_users',
            'total_mapped_users',
            'total_mapping_records',
            'mapping_records',
            'config',
            'acl_for_all_posts',
            'wp_version',
            'plugins',
            'error_log'
        )
    );
}
 

/**
 * Displays the memberful options page
 */
function memberful_wp_options() {
    if (! function_exists('curl_version') || isset($_GET['curl_message']))
        return memberful_wp_render('curl_required');

    if (! empty($_POST)) {
        if (! memberful_wp_valid_nonce('memberful_options'))
            return;

        if (isset($_POST['manual_sync'])) {
            if (is_wp_error($error = memberful_wp_sync_downloads())) {
                Reporter::report($error, 'error');

                return wp_redirect(admin_url('options-general.php?page=memberful_options'));
            }

            if (is_wp_error($error = memberful_wp_sync_subscription_plans())) {
                Reporter::report($error, 'error');

                return wp_redirect(admin_url('options-general.php?page=memberful_options'));
            }

            return wp_redirect(admin_url('options-general.php?page=memberful_options'));
        }

        if (isset($_POST['reset_plugin'])) {
            return memberful_wp_reset();
        }

        if (isset($_POST['save_changes'])) {
            if (isset($_POST['extend_auth_cookie_expiration'])) {
                update_option('memberful_extend_auth_cookie_expiration', true);
            } else {
                update_option('memberful_extend_auth_cookie_expiration', false);
            }
            return wp_redirect(admin_url('options-general.php?page=memberful_options'));
        }
    }

    if (! memberful_wp_is_connected_to_site()) {
        return memberful_wp_register();
    }

    if (!empty($_GET['subpage'])) {
        switch ($_GET['subpage']) {
        case 'bulk_protect':
            return memberful_wp_bulk_protect();
        case 'debug':
            return memberful_wp_debug();
        case 'advanced_settings':
            return memberful_wp_advanced_settings();
        case 'protect_bbpress':
            return memberful_wp_protect_bbpress();
        case 'private_user_feed_settings':
            return memberful_wp_private_rss_feed_settings();
        case 'cookies_test':
            return memberful_wp_render('cookies_test');
        }
    }

    $downloads = get_option('memberful_downloads', array());
    $subscriptions = get_option('memberful_subscriptions', array());
    $extend_auth_cookie_expiration = get_option('memberful_extend_auth_cookie_expiration');

    memberful_wp_render (
        'options',
        array(
            'downloads' => $downloads,
            'subscriptions' => $subscriptions,
            'extend_auth_cookie_expiration' => $extend_auth_cookie_expiration
     )
 );
}

/**
 * Attempts to get the necessary details from memberful and set them
 * using the wordpress settings API
 *
 * @param $code string The activation code
 */
function memberful_wp_activate($code) {
    $params = array(
        'activation_code'    => trim($code),
        'app_name'           => trim(memberful_wp_site_name()),
        'app_url'            => home_url(),
        'oauth_redirect_url' => memberful_wp_oauth_callback_url(),
        'requirements'       => array('oauth', 'api_key', 'webhook'),
        'webhook_url'        => memberful_wp_webhook_url()
 );

    $response = memberful_wp_post_data_to_api_as_json(
        memberful_activation_url(),
        $params
 );

    if (is_wp_error($response)) {
        return new WP_Error('memberful_activation_request_error', "We had trouble connecting to Memberful, please email info@memberful.com. ({$response->get_error_message()})");
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if (404 === $response_code) {
        return new WP_Error('memberful_activation_code_invalid', "It looks like your activation code is wrong. Please try again, and if this keeps happening email us at info@memberful.com.");
    }

    if ($response_code !== 200 || empty($response_body)) {
        return new WP_Error('memberful_activation_fail', "We couldn't connect to Memberful, please email info@memberful.com.");
    }

    $credentials = json_decode($response_body);

    update_option('memberful_client_id', $credentials->oauth->identifier);
    update_option('memberful_client_secret', $credentials->oauth->secret);
    update_option('memberful_api_key', $credentials->api_key->key);
    update_option('memberful_site', $credentials->site);
    update_option('memberful_webhook_secret', $credentials->webhook->secret);

    return TRUE;
}

function memberful_wp_advanced_settings() {
    $allowed_roles         = memberful_wp_roles_that_can_be_mapped_to();
    $current_active_role   = memberful_wp_role_for_active_customer();
    $current_inactive_role = memberful_wp_role_for_inactive_customer();

    if (!empty($_POST)) {
        $new_active_role   = isset($_POST['role_mappings']['active_customer']) ? $_POST['role_mappings']['active_customer'] : '';
        $new_inactive_role = isset($_POST['role_mappings']['inactive_customer']) ? $_POST['role_mappings']['inactive_customer'] : '';

        if (array_key_exists($new_active_role, $allowed_roles) && array_key_exists($new_inactive_role, $allowed_roles)) {
            update_option('memberful_role_active_customer', $new_active_role);
            update_option('memberful_role_inactive_customer', $new_inactive_role);

            memberful_wp_update_customer_roles($current_active_role, $new_active_role, $current_inactive_role, $new_inactive_role);

            Reporter::report(__('Settings updated'));
        } else {
            Reporter::report(__('The roles you chose aren\'t in the list of allowed roles'), 'error');
        }

        wp_redirect(memberful_wp_plugin_advanced_settings_url());
    }

    $vars = array(
        'available_state_mappings' => array(
            'active_customer'   => array(
                'name' => 'Any active subscription plans',
                'current_role' => $current_active_role,
         ),
            'inactive_customer' => array(
                'name' => 'No active subscription plans',
                'current_role' => $current_inactive_role,
         ),
     ),
        'available_roles' => $allowed_roles,
 );
    memberful_wp_render('advanced_settings', $vars);
}

function memberful_wp_bulk_protect() {
    if (!empty($_POST)) {
        $categories_to_protect           = empty($_POST['memberful_protect_categories']) ? array() : (array) $_POST['memberful_protect_categories'];
        $acl_for_downloads                = empty($_POST['memberful_download_acl']) ? array() : (array) $_POST['memberful_download_acl'];
        $acl_for_subscriptions           = empty($_POST['memberful_subscription_acl']) ? array() : (array) $_POST['memberful_subscription_acl'];
        $marketing_content               = empty($_POST['memberful_marketing_content']) ? '' : $_POST['memberful_marketing_content'];
        $things_to_protect               = empty($_POST['target_for_restriction']) ? '' : $_POST['target_for_restriction'];
        $viewable_by_any_registered_user = empty($_POST['memberful_viewable_by_any_registered_users']) ? '' : $_POST['memberful_viewable_by_any_registered_users'];

        $download_acl_manager   = new Memberful_Post_ACL('download');
        $subscription_acl_manager = new Memberful_Post_ACL('subscription');


        $query_params = array('nopaging' => true, 'fields' => 'ids');

        switch ($things_to_protect) {
        case 'all_pages_and_posts':
            $query_params['post_type'] = array('post', 'page');
            break;
        case 'all_pages':
            $query_params['post_type'] = 'page';
            break;
        case 'all_posts':
            $query_params['post_type'] = 'post';
            break;
        case 'all_posts_from_category':
            $query_params['category__in'] = $categories_to_protect;
            break;

        }

        $query = new WP_Query($query_params);

        foreach($query->posts as $id) {
            $download_acl_manager->set_acl($id, $acl_for_downloads);
            $subscription_acl_manager->set_acl($id, $acl_for_subscriptions);
            if (!empty($marketing_content)) {
                memberful_wp_update_post_marketing_content($id, $marketing_content);
            }
            memberful_wp_set_post_available_to_any_registered_users($id, $viewable_by_any_registered_user);
        }

        if (isset($_POST['memberful_make_default_marketing_content']) && $_POST['memberful_make_default_marketing_content'])
            memberful_wp_update_default_marketing_content($marketing_content);

        wp_redirect(memberful_wp_plugin_bulk_protect_url() . '&success=bulk');
    }

    memberful_wp_render(
        'bulk_protect',
        array(
            'downloads' => memberful_wp_metabox_acl_format(array(), 'download'),
            'subscriptions' => memberful_wp_metabox_acl_format(array(), 'subscription'),
            'marketing_content' => '',
            'form_target'       => memberful_wp_plugin_bulk_protect_url(TRUE),
     )
 );
}

function memberful_wp_private_rss_feed_settings() {
    if (isset($_POST['memberful_private_feed_subscriptions_submit'])) {
        $private_feed_subscriptions = isset($_POST['memberful_private_feed_subscriptions']) ? $_POST['memberful_private_feed_subscriptions'] : false;

        memberful_private_user_feed_settings_set_required_plan($private_feed_subscriptions);
    }

    $current_feed_subscriptions = memberful_private_user_feed_settings_get_required_plan();
    $current_feed_subscriptions = !is_array($current_feed_subscriptions) ? array() : $current_feed_subscriptions;

    memberful_wp_render(
        'private_user_feed_settings',
        array(
            'form_target'               => memberful_wp_plugin_private_user_feed_settings_url(),
            'subscription_plans'        => memberful_subscription_plans(),
            'available_subscriptions'   => memberful_private_user_feed_settings_get_required_plan(),
            'current_feed_subscriptions'=> $current_feed_subscriptions
     )
 );
}

function memberful_wp_announce_plans_and_download_in_head() {
    memberful_wp_render(
        'js_vars',
        array(
            'data' => array(
                'plans' => array_values(memberful_subscription_plans()),
                'downloads' => array_values(memberful_downloads()),
                'connectedToMemberful' => memberful_wp_is_connected_to_site(),
            )
        )
    );
}

function memberful_wp_add_protected_state_to_post_list($states, $post) {
    $ids_of_protected_posts = memberful_wp_posts_that_are_protected();

    if (in_array($post->ID, $ids_of_protected_posts)) {
        $states[] = __('Protected by Memberful');
    }

    return $states;
}