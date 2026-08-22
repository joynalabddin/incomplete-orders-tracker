<?php
/**
 * Plugin Name: Incomplete Orders Tracker
 * Plugin URI:  https://devjoynal.com
 * Description: Free, activation-free tool to capture and recover incomplete WooCommerce checkouts with reliable Classic and Block Checkout support.
 * Version:     1.0.0
 * Author:      Joynal Abdin
 * Author URI:  https://devjoynal.com
 * License:     GPLv2 or later
 * Update URI:  https://github.com/joynalabddin/incomplete-orders-tracker
 * Requires PHP: 7.4
 * Requires at least: 6.2
 * Requires Plugins: woocommerce
 * Text Domain: incomplete-orders-tracker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'IOT_VERSION', '1.0.0' );
define( 'IOT_DB_VERSION', '1.0.0' );

define( 'IOT_GITHUB_REPOSITORY', 'joynalabddin/incomplete-orders-tracker' );
define( 'IOT_GITHUB_RELEASE_ASSET', 'incomplete-orders-tracker.zip' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-iot-github-updater.php';
IOT_GitHub_Updater::register( __FILE__, IOT_VERSION );

class IOT_Plugin {
    private static $instance = null;
    private $table;
    private $opt_name = 'iot_settings';
    private $legacy_opt_name = 'iot_settings_v35';

    public static function instance(){
        if(self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct(){
        global $wpdb;
        $this->table = $wpdb->prefix . 'iot_incomplete_orders';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
        add_action( 'iot_daily_maintenance', array( $this, 'daily_maintenance' ) );

        add_action( 'wp_ajax_iot_save', array( $this, 'ajax_save' ) );
        add_action( 'wp_ajax_nopriv_iot_save', array( $this, 'ajax_save' ) );

        add_action( 'wp_ajax_iot_mark_complete', array( $this, 'ajax_mark_complete' ) );
        add_action('wp_ajax_iot_delete_entry', array($this, 'ajax_delete_entry'));
        add_action( 'wp_ajax_iot_save_settings', array( $this, 'ajax_save_settings' ) );

        add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_session_to_order' ), 10, 2 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'attach_block_session_to_order' ), 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'hook_order_processed' ), 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'hook_order_processed_block' ), 10, 1 );
        add_action( 'woocommerce_payment_complete', array( $this, 'hook_order_payment_complete' ), 10, 1 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'hook_order_status_processing' ), 10, 2 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'hook_order_status_completed' ), 10, 2 );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_notices', array( $this, 'woocommerce_dependency_notice' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );

        add_action( 'admin_post_iot_export_csv', array( $this, 'admin_export_csv' ) );
        add_action( 'admin_post_iot_save_settings_fallback', array( $this, 'admin_save_settings_fallback' ) );

        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'plugin_action_links' ) );
    }


public function ajax_delete_entry(){
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(array('message' => 'Unauthorized.'), 403);
    }

    $nonce = isset($_POST['nonce']) && is_scalar($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ( '' === $nonce || ! wp_verify_nonce($nonce, 'iot-admin') ) {
        wp_send_json_error(array('message' => 'Security check failed.'), 403);
    }

    global $wpdb;
    $id = isset($_POST['id']) && is_scalar($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
    if(!$id) wp_send_json_error(array('message' => 'Invalid record.'), 400);

    $deleted = $wpdb->delete(
        $this->table,
        array('id' => $id),
        array('%d')
    );

    if($deleted){
        wp_send_json_success(['message'=>'deleted']);
    } else {
        wp_send_json_error(['message'=>'The record could not be deleted.'], 500);
    }
}





    private function default_settings(){
        return array(
            'site_name'            => get_bloginfo('name'),
            'site_url'             => home_url(),
            'default_country_code' => '880',
            'capture_delay'        => 1200,
            'match_window_days'    => 14,
            'retention_days'       => 90,
            'whatsapp_template'    => "হ্যালো {{customer_name}} — আপনি আমাদের সাইটে {{product_name}} পণ্যটি অর্ডার শুরু করেছিলেন কিন্তু শেষ করেননি।\nচাইলে আমি সাহায্য করতে পারি।\nধন্যবাদ, {{site_name}}",
            'email_subject'        => 'Incomplete Order – {{product_name}}',
            'email_body'           => "হ্যালো {{customer_name}},\n\nআপনি আমাদের ওয়েবসাইটে '{{product_name}}' অর্ডার শুরু করেছিলেন কিন্তু সম্পূর্ণ করেননি।\nতারিখ: {{order_date}}\n\nশুভেচ্ছা,\n{{site_name}}",
        );
    }

    private function get_settings(){
        $settings = get_option( $this->opt_name, null );
        if ( ! is_array( $settings ) ) {
            $settings = get_option( $this->legacy_opt_name, array() );
        }
        return wp_parse_args( is_array($settings) ? $settings : array(), $this->default_settings() );
    }

    public function activate(){
        $this->create_table();
        $schema_ready = $this->repair_legacy_schema();
        $settings = $this->get_settings();
        update_option( $this->opt_name, $settings, false );
        $this->ensure_maintenance_schedule();
        if ( $schema_ready ) {
            update_option( 'iot_db_version', IOT_DB_VERSION, false );
        }
    }

    public function maybe_upgrade(){
        if ( get_option( 'iot_db_version' ) === IOT_DB_VERSION ) {
            $this->ensure_maintenance_schedule();
            return;
        }
        $this->activate();
    }

    private function ensure_maintenance_schedule(){
        if ( ! wp_next_scheduled( 'iot_daily_maintenance' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'iot_daily_maintenance' );
        }
    }

    public function deactivate(){
        wp_clear_scheduled_hook( 'iot_daily_maintenance' );
    }

    public function daily_maintenance(){
        global $wpdb;
        $settings = $this->get_settings();
        $days = max( 30, min( 3650, absint( $settings['retention_days'] ) ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table} WHERE updated_at < %s",
            $cutoff
        ) );
    }

    private function create_table(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        // dbDelta expects CREATE TABLE followed immediately by the table name.
        // Including IF NOT EXISTS prevents it from detecting and upgrading old schemas.
        $sql = "CREATE TABLE {$this->table} (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(191) DEFAULT '',
            order_id BIGINT(20) DEFAULT 0,
            email VARCHAR(191) DEFAULT '',
            name VARCHAR(255) DEFAULT '',
            phone VARCHAR(80) DEFAULT '',
            address TEXT,
            product_link TEXT,
            product_name TEXT,
            cart TEXT,
            meta TEXT,
            status VARCHAR(50) DEFAULT 'incomplete',
            ip VARCHAR(45) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email),
            KEY session_status (session_key, status),
            KEY status_updated (status, updated_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * dbDelta is intentionally conservative and some legacy installations did
     * not receive columns introduced after the first release. Add only missing
     * columns so existing incomplete-order data remains untouched.
     */
    private function repair_legacy_schema(){
        global $wpdb;

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $this->table)
        );
        if ( $table_exists !== $this->table ) return false;

        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM `{$this->table}`", 0);
        if ( ! is_array($existing_columns) ) $existing_columns = array();

        $required_columns = array(
            'session_key'  => "VARCHAR(191) NOT NULL DEFAULT ''",
            'order_id'     => 'BIGINT(20) NOT NULL DEFAULT 0',
            'email'        => "VARCHAR(191) NOT NULL DEFAULT ''",
            'name'         => "VARCHAR(255) NOT NULL DEFAULT ''",
            'phone'        => "VARCHAR(80) NOT NULL DEFAULT ''",
            'address'      => 'TEXT NULL',
            'product_link' => 'TEXT NULL',
            'product_name' => 'TEXT NULL',
            'cart'         => 'TEXT NULL',
            'meta'         => 'TEXT NULL',
            'status'       => "VARCHAR(50) NOT NULL DEFAULT 'incomplete'",
            'ip'           => "VARCHAR(45) NOT NULL DEFAULT ''",
            'created_at'   => 'DATETIME NULL',
            'updated_at'   => 'DATETIME NULL',
        );

        foreach ( $required_columns as $column => $definition ) {
            if ( in_array($column, $existing_columns, true) ) continue;

            $result = $wpdb->query("ALTER TABLE `{$this->table}` ADD COLUMN `{$column}` {$definition}");
            if ( false === $result ) {
                if ( ! empty($wpdb->last_error) ) {
                    error_log('[Incomplete Orders Tracker] Schema repair failed for ' . $column . ': ' . $wpdb->last_error);
                }
                continue;
            }
            $existing_columns[] = $column;
        }

        $missing_columns = array_diff(array_keys($required_columns), $existing_columns);
        return empty($missing_columns);
    }

    public function frontend_assets(){
        $load_script = false;
        if (function_exists('is_checkout') && is_checkout()) {
            $load_script = true;
        } elseif (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'checkout') !== false) {
            $load_script = true;
        }

        if ($load_script) {
            $script_file = plugin_dir_path(__FILE__) . 'iot-checkout.js';
            $settings    = $this->get_settings();
            wp_enqueue_script( 'iot-frontend', plugin_dir_url(__FILE__) . 'iot-checkout.js', array('jquery'), file_exists($script_file) ? filemtime($script_file) : IOT_VERSION, true );
            wp_localize_script(
                'iot-frontend',
                'iot_ajax',
                array(
                    'ajax_url'      => admin_url('admin-ajax.php'),
                    'nonce'         => wp_create_nonce('iot-save'),
                    'capture_delay' => max(500, min(10000, absint($settings['capture_delay']))),
                )
            );
        }
    }

    public function admin_assets($hook){
        if ( strpos($hook, 'iot-incomplete-orders') === false && strpos($hook, 'toplevel_page_iot-incomplete-orders') === false && strpos($hook, 'incomplete-orders-tracker-settings') === false ) return;
        $css_file = plugin_dir_path(__FILE__) . 'assets/iot-admin.css';
        $js_file  = plugin_dir_path(__FILE__) . 'assets/iot-admin.js';
        wp_enqueue_style( 'iot-admin-css', plugin_dir_url(__FILE__) . 'assets/iot-admin.css', array(), file_exists($css_file) ? filemtime($css_file) : IOT_VERSION );
        wp_enqueue_script( 'iot-admin-js', plugin_dir_url(__FILE__) . 'assets/iot-admin.js', array('jquery'), file_exists($js_file) ? filemtime($js_file) : IOT_VERSION, true );
        wp_localize_script( 'iot-admin-js', 'iotAdmin', array( 'ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('iot-admin') ) );
    }

    public function admin_menu(){
        add_menu_page(
            'Incomplete Orders',
            'Incomplete Orders',
            'manage_options',
            'iot-incomplete-orders',
            array( $this, 'admin_page' ),
            $this->menu_icon_data_uri(),
            56
        );
        add_submenu_page( 'iot-incomplete-orders', 'Settings', 'Settings', 'manage_options', 'incomplete-orders-tracker-settings', array( $this, 'settings_page' ) );
    }

    public function woocommerce_dependency_notice(){
        if ( class_exists('WooCommerce') || ! current_user_can('activate_plugins') ) return;
        echo '<div class="notice notice-error"><p>' . esc_html__('Incomplete Orders Tracker requires WooCommerce to be installed and active.', 'incomplete-orders-tracker') . '</p></div>';
    }

    private function menu_icon_data_uri(){
        // Monochrome cart icon for WP admin sidebar (WP recolors this correctly).
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M2.2 3h2.6c.5 0 .9.3 1 .8l.3 1.2h11c.3 0 .6.1.8.4.2.2.3.6.2.9l-1.4 5a1 1 0 0 1-1 .7H7.5a1 1 0 0 1-1-.8L5.2 5.5H2.2V3zm6 11.2a1.8 1.8 0 1 1 0 3.6 1.8 1.8 0 0 1 0-3.6zm6.4 0a1.8 1.8 0 1 1 0 3.6 1.8 1.8 0 0 1 0-3.6z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function plugin_action_links( $links ){
        $settings_link = '<a href="' . esc_url( admin_url('admin.php?page=iot-incomplete-orders') ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    private function replace_placeholders( $template, $data = array() ){
        $repl = array(
            '{{customer_name}}' => $data['name'] ?? '',
            '{{product_name}}' => $data['product_name'] ?? '',
            '{{site_name}}' => $data['site_name'] ?? get_bloginfo('name'),
            '{{site_url}}' => $data['site_url'] ?? home_url(),
            '{{order_date}}' => $data['order_date'] ?? ''
        );
        return strtr( $template, $repl );
    }

    private function is_placeholder_product_name( $name ){
        $name = strtolower( trim( wp_strip_all_tags( (string) $name ) ) );
        $name = preg_replace('/\s+/', ' ', $name);

        if ( $name === '' ) return true;

        $blocked = array(
            'product', 'products', 'item', 'items',
            'subtotal', 'total', 'shipping', 'tax',
            'coupon', 'discount', 'cart', 'checkout'
        );

        return in_array( $name, $blocked, true );
    }

    private function sanitize_product_names( $names ){
        if ( ! is_array($names) ) return array();

        $clean = array();
        foreach ( $names as $name ) {
            if ( ! is_string($name) ) continue;

            $name = wp_strip_all_tags($name);
            $name = preg_replace('/\s+/', ' ', trim($name));
            // remove trailing quantity fragments like "x 2" or "×2"
            $name = preg_replace('/\s*(?:x|\x{00D7})\s*\d+\s*$/ui', '', $name);
            $name = sanitize_text_field($name);

            if ( $this->is_placeholder_product_name($name) ) continue;
            if ( $name === '' ) continue;
            if ( in_array($name, $clean, true) ) continue;

            $clean[] = $name;
        }

        return array_slice($clean, 0, 20);
    }

    private function sanitize_product_links( $links ){
        if ( ! is_array($links) ) return array();

        $clean = array();
        foreach ( $links as $link ) {
            if ( ! is_string($link) ) continue;
            $link = trim($link);
            if ( $link === '' ) continue;
            if ( strpos($link, 'javascript:') === 0 ) continue;
            if ( strpos($link, '#') === 0 ) continue;

            $link = esc_url_raw($link);
            if ( $link === '' ) continue;
            if ( in_array($link, $clean, true) ) continue;

            $clean[] = $link;
        }

        return array_slice($clean, 0, 20);
    }

    /**
     * Read product details without assuming that legacy database rows contain
     * the product_name/product_link columns. Older releases kept this data in
     * cart or meta JSON, so use those values as a backwards-compatible fallback.
     */
    private function get_row_product_data( $row ){
        $names = array();
        $links = array();

        if ( ! is_object($row) ) {
            return array('name' => '', 'link' => '');
        }

        if ( isset($row->product_name) && is_scalar($row->product_name) ) {
            $names[] = (string) $row->product_name;
        }
        if ( isset($row->product_link) && is_scalar($row->product_link) ) {
            $links[] = (string) $row->product_link;
        }

        foreach ( array('cart', 'meta') as $property ) {
            if ( ! isset($row->{$property}) || ! is_scalar($row->{$property}) ) continue;

            $raw = (string) $row->{$property};
            if ( $raw === '' ) continue;

            $decoded = json_decode($raw, true);
            if ( ! is_array($decoded) && function_exists('maybe_unserialize') ) {
                $decoded = maybe_unserialize($raw);
            }
            if ( ! is_array($decoded) ) continue;

            foreach ( array('names', 'product_names') as $key ) {
                if ( ! isset($decoded[$key]) ) continue;
                $values = is_array($decoded[$key]) ? $decoded[$key] : array($decoded[$key]);
                $names = array_merge($names, $values);
            }
            foreach ( array('links', 'product_links') as $key ) {
                if ( ! isset($decoded[$key]) ) continue;
                $values = is_array($decoded[$key]) ? $decoded[$key] : array($decoded[$key]);
                $links = array_merge($links, $values);
            }

            $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
            foreach ( $items as $item ) {
                if ( is_object($item) ) $item = (array) $item;
                if ( ! is_array($item) ) continue;

                foreach ( array('product_name', 'name', 'title') as $key ) {
                    if ( isset($item[$key]) && is_scalar($item[$key]) ) {
                        $names[] = (string) $item[$key];
                        break;
                    }
                }
                foreach ( array('product_link', 'link', 'url', 'permalink') as $key ) {
                    if ( isset($item[$key]) && is_scalar($item[$key]) ) {
                        $links[] = (string) $item[$key];
                        break;
                    }
                }
            }
        }

        $names = $this->sanitize_product_names($names);
        $links = $this->sanitize_product_links($links);

        return array(
            'name' => isset($names[0]) ? $names[0] : '',
            'link' => isset($links[0]) ? $links[0] : '',
        );
    }

    private function get_cart_products_fallback(){
        $names = array();
        $links = array();

        if ( ! function_exists('WC') || ! WC() || ! WC()->cart ) {
            return array($names, $links);
        }

        $cart_items = WC()->cart->get_cart();
        if ( ! is_array($cart_items) ) {
            return array($names, $links);
        }

        foreach ( $cart_items as $item ) {
            $product_id = !empty($item['product_id']) ? intval($item['product_id']) : 0;
            $product = isset($item['data']) && is_object($item['data']) ? $item['data'] : null;

            $name = '';
            if ( $product && method_exists($product, 'get_name') ) {
                $name = (string) $product->get_name();
            } elseif ( $product_id > 0 ) {
                $name = (string) get_the_title($product_id);
            }

            $name = sanitize_text_field( preg_replace('/\s+/', ' ', trim( wp_strip_all_tags($name) ) ) );
            if ( ! $this->is_placeholder_product_name($name) && $name !== '' && !in_array($name, $names, true) ) {
                $names[] = $name;
            }

            if ( $product_id > 0 ) {
                $link = esc_url_raw( get_permalink($product_id) );
                if ( $link !== '' && !in_array($link, $links, true) ) {
                    $links[] = $link;
                }
            }
        }

        return array($names, $links);
    }

    private function request_scalar( $source, $key, $default = '' ){
        if ( ! is_array($source) || ! isset($source[$key]) || ! is_scalar($source[$key]) ) return $default;
        return wp_unslash( (string) $source[$key] );
    }

    private function normalize_session_key( $value ){
        $value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
        $value = preg_replace( '/[^a-z0-9_\-]/', '', $value );
        return substr( (string) $value, 0, 64 );
    }

    private function generate_session_key(){
        return 'iot_' . str_replace( '-', '', wp_generate_uuid4() );
    }

    private function get_tracking_session_key( $request = null ){
        $candidates = array();

        if ( isset($_POST['iot_session_key']) && is_scalar($_POST['iot_session_key']) ) {
            $candidates[] = wp_unslash($_POST['iot_session_key']);
        }
        if ( isset($_COOKIE['iot_session_key']) && is_scalar($_COOKIE['iot_session_key']) ) {
            $candidates[] = wp_unslash($_COOKIE['iot_session_key']);
        }
        if ( is_object($request) && method_exists($request, 'get_param') ) {
            $request_key = $request->get_param('iot_session_key');
            if ( is_scalar($request_key) ) $candidates[] = $request_key;
        }

        foreach ( $candidates as $candidate ) {
            $key = $this->normalize_session_key($candidate);
            if ( '' !== $key ) return $key;
        }

        if ( function_exists('WC') && WC() && WC()->session ) {
            $customer_id = (string) WC()->session->get_customer_id();
            if ( '' !== $customer_id ) {
                return 'wc_' . substr( hash('sha256', $customer_id), 0, 40 );
            }
        }

        return '';
    }

    private function persist_session_cookie( $session_key ){
        if ( headers_sent() || '' === $session_key ) return;
        setcookie(
            'iot_session_key',
            $session_key,
            array(
                'expires'  => time() + (30 * DAY_IN_SECONDS),
                'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            )
        );
    }

    private function normalize_phone_digits( $phone ){
        return substr( preg_replace('/\D+/', '', (string) $phone), 0, 24 );
    }

    private function normalize_whatsapp_phone( $phone ){
        $digits   = $this->normalize_phone_digits($phone);
        $settings = $this->get_settings();
        $country  = substr( preg_replace('/\D+/', '', (string) $settings['default_country_code']), 0, 5 );

        if ( '' !== $country && 0 === strpos($digits, '0') ) {
            $digits = $country . ltrim($digits, '0');
        }
        return $digits;
    }

    private function get_client_ip(){
        $raw = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $ip  = filter_var($raw, FILTER_VALIDATE_IP);
        return $ip ? (string) $ip : '';
    }

    private function is_public_request_rate_limited( $session ){
        $identity = $this->get_client_ip();
        if ( '' === $identity ) {
            $identity = (string) $session;
        }
        $key = 'iot_rate_' . md5( $identity );
        $count = absint( get_transient( $key ) );
        if ( $count >= 30 ) {
            return true;
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return false;
    }

    public function ajax_save(){
        $nonce = $this->request_scalar($_POST, 'nonce');
        if ( '' === $nonce || ! wp_verify_nonce($nonce, 'iot-save') ) {
            wp_send_json_error(array('message' => 'Security check failed. Refresh checkout and try again.'), 403);
        }

        // Public checkout requests can arrive before an administrator visits
        // the dashboard after an update, so complete pending schema repair here.
        if ( get_option('iot_db_version') !== IOT_DB_VERSION ) {
            $this->activate();
        }

        global $wpdb;

        $session = $this->normalize_session_key( $this->request_scalar($_POST, 'session_key') );
        if ( '' === $session ) $session = $this->get_tracking_session_key();
        if ( '' === $session ) $session = $this->generate_session_key();
        $this->persist_session_cookie($session);

        if ( $this->is_public_request_rate_limited( $session ) ) {
            wp_send_json_error( array( 'message' => 'Too many checkout updates. Please wait a moment and try again.' ), 429 );
        }

        $email   = strtolower( sanitize_email( $this->request_scalar($_POST, 'email') ) );
        $name    = substr( sanitize_text_field( $this->request_scalar($_POST, 'name') ), 0, 255 );
        $phone   = substr( sanitize_text_field( $this->request_scalar($_POST, 'phone') ), 0, 80 );
        $address = substr( sanitize_textarea_field( $this->request_scalar($_POST, 'address') ), 0, 2000 );
        $ip      = $this->get_client_ip();

        $product_links_json = $this->request_scalar($_POST, 'product_links', '[]');
        $product_names_json = $this->request_scalar($_POST, 'product_names', '[]');
        if ( strlen($product_links_json) > 20000 || strlen($product_names_json) > 20000 ) {
            wp_send_json_error(array('message' => 'Checkout data is too large.'), 400);
        }

        $product_links = json_decode($product_links_json, true);
        $product_names = json_decode($product_names_json, true);

        $product_links = $this->sanitize_product_links($product_links);
        $product_names = $this->sanitize_product_names($product_names);

        if (empty($email) && empty($phone) && empty($name)) {
            wp_send_json_error(array('message' => 'No contact information provided.'), 400);
        }

        // Theme-independent fallback: load products from Woo cart when selectors fail.
        if ( empty($product_names) || empty($product_links) ) {
            list($cart_names, $cart_links) = $this->get_cart_products_fallback();
            if ( empty($product_names) && !empty($cart_names) ) {
                $product_names = $cart_names;
            }
            if ( empty($product_links) && !empty($cart_links) ) {
                $product_links = $cart_links;
            }
        }

        if ( empty($product_names) && empty($product_links) ) {
            wp_send_json_error(array('message' => 'No valid products found.'), 400);
        }

        $product_link_first = (is_array($product_links) && !empty($product_links)) ? esc_url_raw($product_links[0]) : '';
        $product_name_first = (is_array($product_names) && !empty($product_names)) ? sanitize_text_field($product_names[0]) : '';
        $cart_json          = wp_json_encode(
            array(
                'names' => $product_names,
                'links' => $product_links,
            )
        );

        $data = [
            'session_key' => $session,
            'email'        => $email,
            'name'         => $name,
            'phone'        => $phone,
            'address'      => $address,
            'product_link' => $product_link_first,
            'product_name' => $product_name_first,
            'cart'         => $cart_json,
            'ip'           => $ip,
            'status'       => 'incomplete',
            'updated_at'   => current_time('mysql', true),
        ];

        $data_format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE session_key = %s AND status = 'incomplete' ORDER BY updated_at DESC LIMIT 1",
            $session
        ));

        if ($existing_id) {
            $result = $wpdb->update($this->table, $data, ['id' => $existing_id], $data_format, ['%d']);
            if ($result !== false) {
                wp_send_json_success(['id' => (int) $existing_id, 'status' => 'updated', 'session_key' => $session]);
            } else {
                if ( ! empty($wpdb->last_error) ) {
                    error_log('[Incomplete Orders Tracker] Database update failed: ' . $wpdb->last_error);
                }
                wp_send_json_error(['message' => 'The incomplete order could not be updated.'], 500);
            }
        } else {
            $result = $wpdb->insert($this->table, $data, $data_format);
            if ($result !== false) {
                wp_send_json_success(['id' => (int) $wpdb->insert_id, 'status' => 'inserted', 'session_key' => $session]);
            } else {
                if ( ! empty($wpdb->last_error) ) {
                    error_log('[Incomplete Orders Tracker] Database insert failed: ' . $wpdb->last_error);
                }
                wp_send_json_error(['message' => 'The incomplete order could not be saved.'], 500);
            }
        }
    }

    public function attach_session_to_order( $order, $data = array() ){
        if ( ! $order || ! is_object($order) || ! method_exists($order, 'update_meta_data') ) return;
        $session = $this->get_tracking_session_key();
        if ( '' !== $session ) $order->update_meta_data('_iot_session_key', $session);
    }

    public function attach_block_session_to_order( $order, $request = null ){
        if ( ! $order || ! is_object($order) || ! method_exists($order, 'update_meta_data') ) return;
        $session = $this->get_tracking_session_key($request);
        if ( '' !== $session ) $order->update_meta_data('_iot_session_key', $session);
    }

    public function hook_order_processed( $order_id, $posted_data, $order ){
        if ( ! $order_id ) return;
        $this->mark_as_complete_by_order($order);
    }

    public function hook_order_processed_block( $order ){
        if ( is_object($order) || is_numeric($order) ) {
            $this->mark_as_complete_by_order($order);
        }
    }

    public function hook_order_payment_complete( $order_id ){
        if ( ! $order_id ) return;
        $this->mark_as_complete_by_order( $order_id );
    }

    public function hook_order_status_processing( $order_id, $order = null ){
        if ( $order ) {
            $this->mark_as_complete_by_order( $order );
            return;
        }
        if ( $order_id ) $this->mark_as_complete_by_order( $order_id );
    }

    public function hook_order_status_completed( $order_id, $order = null ){
        if ( $order ) {
            $this->mark_as_complete_by_order( $order );
            return;
        }
        if ( $order_id ) $this->mark_as_complete_by_order( $order_id );
    }

    private function mark_as_complete_by_order($order) {
        if ( is_numeric($order) ) $order = wc_get_order($order);
        if ( ! $order || ! is_object($order) || ! method_exists($order, 'get_id') ) return;

        global $wpdb;
        $order_id = absint($order->get_id());
        $session  = method_exists($order, 'get_meta') ? $this->normalize_session_key($order->get_meta('_iot_session_key')) : '';
        if ( '' === $session ) $session = $this->get_tracking_session_key();

        $matched_id = 0;
        if ( '' !== $session ) {
            $matched_id = absint( $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE status = 'incomplete' AND session_key = %s ORDER BY updated_at DESC, id DESC LIMIT 1",
                    $session
                )
            ) );
        }

        if ( ! $matched_id ) {
            $email = method_exists($order, 'get_billing_email')
                ? strtolower( sanitize_email( (string) $order->get_billing_email() ) )
                : '';
            $phone_raw = method_exists($order, 'get_billing_phone')
                ? sanitize_text_field( (string) $order->get_billing_phone() )
                : '';
            $phone_digits = $this->normalize_phone_digits($phone_raw);

            $conditions = array();
            $params = array();

            if ( $email !== '' ) {
                $conditions[] = "email = %s";
                $params[] = $email;
            }

            if ( $phone_raw !== '' ) {
                $conditions[] = "phone = %s";
                $params[] = $phone_raw;

                if ( $phone_digits !== '' ) {
                    $conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') = %s";
                    $params[] = $phone_digits;
                }
            }

            if ( ! empty($conditions) ) {
                $settings = $this->get_settings();
                $days     = max(1, min(90, absint($settings['match_window_days'])));
                $cutoff   = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
                $sql = "SELECT id FROM {$this->table}
                        WHERE status = 'incomplete' AND updated_at >= %s AND (" . implode(' OR ', $conditions) . ")
                        ORDER BY updated_at DESC, id DESC LIMIT 1";
                $query_params = array_merge( array( $cutoff ), $params );
                $matched_id = absint( $wpdb->get_var( $wpdb->prepare( $sql, $query_params ) ) );
            }
        }

        if ( $matched_id ) {
            $wpdb->update(
                $this->table,
                array(
                    'status' => 'complete',
                    'order_id' => $order_id,
                    'updated_at' => current_time('mysql', true),
                ),
                array('id' => $matched_id),
                array('%s', '%d', '%s'),
                array('%d')
            );
        }

        $this->clear_tracking_cookie();
    }

    private function clear_tracking_cookie(){
        if ( headers_sent() ) return;
        setcookie(
            'iot_session_key',
            '',
            array(
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            )
        );
    }

    private function shorten_link( $url ){
        if (empty($url) || !is_string($url)) return $url;
        $parsed_url = wp_parse_url( $url );
        if ( ! isset($parsed_url['host']) ) return $url;

        $host = $parsed_url['host'];
        $path = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';
        $host = preg_replace('/^www\./', '', $host);

        $short_url = $host . '/' . $path;

        if ( strlen($short_url) > 60 ) {
            return substr($short_url, 0, 57) . '...';
        }
        return $short_url;
    }

    public function admin_page(){
        if ( ! current_user_can('manage_options') ) return;
        global $wpdb;
        // Show incomplete leads + admin-manually completed leads.
        // Hide auto-completed WooCommerce orders (they have order_id > 0).
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE (status = 'incomplete' AND (order_id = 0 OR order_id IS NULL))
                OR (status = 'complete' AND (order_id = 0 OR order_id IS NULL))
             ORDER BY created_at DESC
             LIMIT 500"
        );
        $incomplete_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status='incomplete' AND (order_id = 0 OR order_id IS NULL)"));
        $completed_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status='complete' AND (order_id = 0 OR order_id IS NULL)"));
        ?>
        <div class="wrap iot-wrap">
            <header class="iot-topbar">
                <div class="iot-top-left">
                    <div class="iot-brand-line"><span class="iot-brand-icon dashicons dashicons-cart" aria-hidden="true"></span><span>RECOVERY CENTER</span></div>
                    <h1 class="iot-title">Incomplete Orders <span class="iot-badge">v<?php echo esc_html(IOT_VERSION); ?></span></h1>
                    <p class="iot-sub">Built by <a href="https://devjoynal.com" target="_blank" rel="noopener noreferrer">Joynal Abdin</a> · Classic and Block Checkout ready</p>
                </div>
                <div class="iot-top-right">
                    <div class="iot-stats-inline">
                        <div class="iot-stat-inline"><strong id="iot-incomplete-count"><?php echo $incomplete_count; ?></strong><span>Incomplete</span></div>
                        <div class="iot-stat-inline"><strong id="iot-completed-count"><?php echo $completed_count; ?></strong><span>Completed</span></div>
                    </div>
                    <div class="iot-top-actions">
                        <a class="iot-btn iot-btn-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=iot_export_csv'), 'iot_export_csv')); ?>">Export CSV</a>
                        <a class="iot-btn" href="<?php echo esc_url(admin_url('admin.php?page=incomplete-orders-tracker-settings')); ?>">Settings</a>
                    </div>
                </div>
            </header>

            <div class="iot-table-wrap">
                <table class="iot-table">
                    <thead>
                        <tr><th>#</th><th>Customer</th><th>Contact</th><th>Address</th><th>Product</th><th>Status</th><th>Actions</th><th>Time</th></tr>
                    </thead>
                    <tbody>
                        <?php if($rows): $i=1; foreach($rows as $r): ?>
                        <?php
                        $row_id = isset($r->id) ? absint($r->id) : 0;
                        $row_status = isset($r->status) && is_scalar($r->status) ? sanitize_key((string) $r->status) : 'incomplete';
                        $row_phone = isset($r->phone) && is_scalar($r->phone) ? sanitize_text_field((string) $r->phone) : '';
                        $row_created_at = isset($r->created_at) && is_scalar($r->created_at) ? (string) $r->created_at : '';
                        $customer_name = isset($r->name) && is_scalar($r->name) && trim((string) $r->name) !== '' ? (string) $r->name : 'N/A';
                        $customer_email = isset($r->email) && is_scalar($r->email) ? sanitize_email((string) $r->email) : '';
                        $address_raw = isset($r->address) && is_scalar($r->address) && trim((string) $r->address) !== '' ? (string) $r->address : 'N/A';
                        $product_data = $this->get_row_product_data($r);
                        $row_product_name = $product_data['name'];
                        $row_product_link = $product_data['link'];
                        $address_single = preg_replace('/\s+/', ' ', trim(wp_strip_all_tags($address_raw)));
                        if ($address_single === '') {
                            $address_single = 'N/A';
                        }
                        ?>
                        <tr>
                            <td data-label="#"><?php echo $i++; ?></td>
                            <td data-label="Customer">
                                <strong class="iot-ellipsis" title="<?php echo esc_attr($customer_name); ?>"><?php echo esc_html($customer_name); ?></strong>
                                <?php if ($customer_email !== ''): ?>
                                    <div class="small-text iot-ellipsis" title="<?php echo esc_attr($customer_email); ?>"><?php echo esc_html($customer_email); ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Contact">
                                <?php if ( $row_phone !== '' ): ?>
                                    <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $row_phone)); ?>" class="iot-phone-link">
                                        <?php echo esc_html($row_phone); ?>
                                    </a>
                                <?php else: ?>
                                    <span>N/A</span>
                                <?php endif; ?>
                            </td>

                            <td data-label="Address"><span class="iot-ellipsis" title="<?php echo esc_attr($address_single); ?>"><?php echo esc_html($address_single); ?></span></td>

                           <td class="iot-product-cell" data-label="Product">
    <?php
    // 1) direct saved link আছে কি না চেক কর
    if ( $row_product_link !== '' ) : ?>
        <a class="iot-btn iot-btn-product" href="<?php echo esc_url( $row_product_link ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $row_product_name !== '' ? $row_product_name : 'View Product' ); ?>">
            View Product
        </a>

    <?php
    // 2) saved link না থাকলে product_name দিয়ে WP product খুঁজে দেখ
    elseif ( $row_product_name !== '' ) :

        // চেষ্টা: post title থেকে product post খুঁজে permalink নেয়া
        $maybe_link = '';
        // সেফ হবে বলেই sanitize
        $possible_title = $row_product_name;

        if ( ! empty( $possible_title ) ) {
            static $iot_product_lookup_cache = array();
            if ( array_key_exists($possible_title, $iot_product_lookup_cache) ) {
                $maybe_link = $iot_product_lookup_cache[$possible_title];
            } else {
                $matches = get_posts(
                    array(
                        'post_type'      => 'product',
                        'post_status'    => 'publish',
                        'title'          => $possible_title,
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                        'no_found_rows'  => true,
                    )
                );
                if ( ! empty($matches[0]) ) $maybe_link = get_permalink(absint($matches[0]));
                $iot_product_lookup_cache[$possible_title] = $maybe_link;
            }
        }

        if ( ! empty( $maybe_link ) ) : ?>
            <a class="iot-btn iot-btn-product" href="<?php echo esc_url( $maybe_link ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $row_product_name ); ?>">
                View Product
            </a>
        <?php else: ?>
            <span class="iot-item-text"><?php echo esc_html( wp_html_excerpt( $row_product_name, 100, '…' ) ); ?></span>
        <?php endif; ?>

    <?php else: ?>
        <span class="iot-item-text">-</span>
    <?php endif; ?>
</td>



                            <td data-label="Status">
                                <?php if($row_status === 'incomplete'): ?>
                                    <span class="iot-badge-status iot-incomplete">Incomplete</span>
                                <?php else: ?>
                                    <span class="iot-badge-status iot-complete">Completed</span>
                                <?php endif; ?>
                            </td>

                            <td class="iot-actions-td" data-label="Actions">
                                <?php
                                $settings = $this->get_settings();
                                $data = array(
                                    'name' => $customer_name,
                                    'product_name' => $row_product_name,
                                    'site_name' => $settings['site_name'] ?? get_bloginfo('name'),
                                    'site_url' => $settings['site_url'] ?? home_url(),
                                    'order_date' => $row_created_at !== '' ? date('Y-m-d', strtotime($row_created_at)) : ''
                                );
                                $wa_msg = rawurlencode($this->replace_placeholders($settings['whatsapp_template'] ?? '', $data));
                                $wa_phone = $this->normalize_whatsapp_phone($row_phone);
                                $wa_url = $wa_phone ? 'https://wa.me/' . $wa_phone . '?text=' . $wa_msg : '';
                                $mailto = $customer_email !== '' ? 'mailto:' . rawurlencode($customer_email) . '?subject=' . rawurlencode($this->replace_placeholders($settings['email_subject'] ?? '', $data)) . '&body=' . rawurlencode($this->replace_placeholders($settings['email_body'] ?? '', $data)) : '';
                                ?>
                                <div class="iot-actions">
                                    <?php if($wa_url): ?>
                                        <a class="iot-btn iot-btn-icon iot-btn-green" target="_blank" href="<?php echo esc_url($wa_url); ?>" aria-label="WhatsApp" title="WhatsApp">
                                            <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if($mailto): ?>
                                        <a class="iot-btn iot-btn-icon iot-btn-email" href="<?php echo esc_url($mailto); ?>" aria-label="Email" title="Email">
                                            <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ( $address_single !== 'N/A' ): ?>
                                    <a class="iot-btn iot-btn-icon iot-btn-map" target="_blank" href="<?php echo esc_url('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address_single)); ?>" aria-label="Map" title="Map">
                                        <span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ( 'incomplete' === $row_status ): ?>
                                    <button class="iot-btn iot-btn-icon iot-mark-complete" data-id="<?php echo $row_id; ?>" aria-label="Complete" title="Complete">
                                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                    </button>
                                    <?php endif; ?>
                                    <button class="iot-btn iot-btn-icon iot-btn-danger iot-delete" data-id="<?php echo $row_id; ?>" aria-label="Delete" title="Delete">
                                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </td>

                            <td data-label="Time">
                                <?php
                                // DB created_at is stored in UTC on many hosts; compare in UTC to avoid timezone drift.
                                $created_time_gmt = $row_created_at !== '' ? strtotime($row_created_at . ' UTC') : false;
                                $current_time_gmt = current_time('timestamp', true);

                                if ($created_time_gmt && $current_time_gmt > 0) {
                                    if ($created_time_gmt > $current_time_gmt) {
                                        $created_time_gmt = $current_time_gmt;
                                    }
                                    echo esc_html(human_time_diff($created_time_gmt, $current_time_gmt) . ' ago');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>




                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td class="iot-empty" colspan="8">No records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                 <div class="iot-author-card">
                    <img class="iot-author-avatar" src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/joynal-abdin-author.jpg'); ?>" alt="Joynal Abdin" loading="lazy" />
                    <div class="iot-author-copy">
                        <span class="iot-author-label">Plugin Author</span>
                        <strong>Joynal Abdin</strong>
                        <a href="https://devjoynal.com" target="_blank" rel="noopener noreferrer">devjoynal.com</a>
                    </div>
                 </div>
                 <div class="iot-note">
                    <p>Developed by <a href="https://devjoynal.com" target="_blank" rel="noopener noreferrer">Joynal Abdin</a> · <a href="https://devjoynal.com" target="_blank" rel="noopener noreferrer">devjoynal.com</a></p>
                 </div>
            </div>

        </div>
        <?php
    }

    public function settings_page(){
        if ( ! current_user_can('manage_options') ) return;
        $settings = $this->get_settings();
        $saved    = isset($_GET['iot_saved']) && '1' === sanitize_text_field(wp_unslash($_GET['iot_saved']));
        ?>
        <div class="wrap iot-wrap iot-settings-wrap">
            <header class="iot-topbar iot-settings-header">
                <div>
                    <div class="iot-brand-line"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><span>DEVJOYNAL RECOVERY TOOLS</span></div>
                    <h1 class="iot-title">Tracker Settings</h1>
                    <p class="iot-sub">Control lead matching and recovery message templates.</p>
                </div>
                <a class="iot-btn" href="<?php echo esc_url(admin_url('admin.php?page=iot-incomplete-orders')); ?>">Back to Orders</a>
            </header>

            <div id="iot-settings-notice" class="iot-settings-notice <?php echo $saved ? 'is-visible is-success' : ''; ?>" role="status" aria-live="polite"><?php echo $saved ? esc_html__('Settings saved successfully.', 'incomplete-orders-tracker') : ''; ?></div>

            <form id="iot-settings-form" class="iot-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="iot_save_settings_fallback">
                <?php wp_nonce_field( 'iot-admin', 'iot-admin-nonce' ); ?>

                <section class="iot-settings-card">
                    <div class="iot-settings-card__head"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><div><h2>Store & Tracking</h2><p>Identity and matching rules used by the recovery tracker.</p></div></div>
                    <div class="iot-settings-grid">
                        <label class="iot-setting-field"><span>Site Name</span><input type="text" id="site_name" name="site_name" value="<?php echo esc_attr($settings['site_name']); ?>" /></label>
                        <label class="iot-setting-field"><span>Site URL</span><input type="url" id="site_url" name="site_url" value="<?php echo esc_attr($settings['site_url']); ?>" /></label>
                        <label class="iot-setting-field"><span>WhatsApp Country Code</span><input type="text" inputmode="numeric" id="default_country_code" name="default_country_code" value="<?php echo esc_attr($settings['default_country_code']); ?>" /><small>Example: 880 for Bangladesh.</small></label>
                        <label class="iot-setting-field"><span>Capture Delay (milliseconds)</span><input type="number" min="500" max="10000" step="100" id="capture_delay" name="capture_delay" value="<?php echo esc_attr($settings['capture_delay']); ?>" /><small>Wait time after a customer changes a checkout field.</small></label>
                        <label class="iot-setting-field"><span>Completion Match Window (days)</span><input type="number" min="1" max="90" id="match_window_days" name="match_window_days" value="<?php echo esc_attr($settings['match_window_days']); ?>" /><small>Only recent matching leads are auto-completed.</small></label>
                        <label class="iot-setting-field"><span>Data Retention (days)</span><input type="number" min="30" max="3650" step="1" id="retention_days" name="retention_days" value="<?php echo esc_attr($settings['retention_days']); ?>" /><small>Older records are removed automatically by daily maintenance.</small></label>
                    </div>
                </section>

                <section class="iot-settings-card">
                    <div class="iot-settings-card__head"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><div><h2>WhatsApp Recovery</h2><p>Message opened from the WhatsApp action button.</p></div></div>
                    <label class="iot-setting-field"><span>WhatsApp Template</span><textarea id="whatsapp_template" name="whatsapp_template" rows="6"><?php echo esc_textarea($settings['whatsapp_template']); ?></textarea><small>Placeholders: <code>{{customer_name}}</code>, <code>{{product_name}}</code>, <code>{{site_name}}</code>, <code>{{site_url}}</code></small></label>
                </section>

                <section class="iot-settings-card">
                    <div class="iot-settings-card__head"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><div><h2>Email Recovery</h2><p>Subject and body used by the Email action button.</p></div></div>
                    <div class="iot-settings-grid">
                        <label class="iot-setting-field iot-setting-field--full"><span>Email Subject</span><input type="text" id="email_subject" name="email_subject" value="<?php echo esc_attr($settings['email_subject']); ?>" /></label>
                        <label class="iot-setting-field iot-setting-field--full"><span>Email Body</span><textarea id="email_body" name="email_body" rows="8"><?php echo esc_textarea($settings['email_body']); ?></textarea><small>Placeholders: <code>{{customer_name}}</code>, <code>{{product_name}}</code>, <code>{{order_date}}</code>, <code>{{site_name}}</code>, <code>{{site_url}}</code></small></label>
                    </div>
                </section>

                <div class="iot-settings-savebar"><span id="iot-settings-save-status">Changes are saved securely in WordPress.</span><button type="submit" id="iot-save-settings" class="iot-btn iot-btn-primary">Save Settings</button></div>
            </form>
        </div>
        <?php
    }

    private function sanitize_admin_settings( $source ){
        $current = $this->get_settings();
        return array(
            'site_name'            => sanitize_text_field($this->request_scalar($source, 'site_name', $current['site_name'])),
            'site_url'             => esc_url_raw($this->request_scalar($source, 'site_url', $current['site_url'])),
            'default_country_code' => substr(preg_replace('/\D+/', '', $this->request_scalar($source, 'default_country_code', $current['default_country_code'])), 0, 5),
            'capture_delay'        => max(500, min(10000, absint($this->request_scalar($source, 'capture_delay', $current['capture_delay'])))),
            'match_window_days'    => max(1, min(90, absint($this->request_scalar($source, 'match_window_days', $current['match_window_days'])))),
            'retention_days'       => max(30, min(3650, absint($this->request_scalar($source, 'retention_days', $current['retention_days'])))),
            'whatsapp_template'    => substr(sanitize_textarea_field($this->request_scalar($source, 'whatsapp_template', $current['whatsapp_template'])), 0, 5000),
            'email_subject'        => substr(sanitize_text_field($this->request_scalar($source, 'email_subject', $current['email_subject'])), 0, 255),
            'email_body'           => substr(sanitize_textarea_field($this->request_scalar($source, 'email_body', $current['email_body'])), 0, 10000),
        );
    }

    public function ajax_save_settings(){
        if ( ! current_user_can('manage_options') ) wp_send_json_error(array('message' => 'Unauthorized.'), 403);
        if ( ! check_ajax_referer('iot-admin', 'nonce', false) ) {
            wp_send_json_error(array('message' => 'Security check failed. Refresh the page and try again.'), 403);
        }

        $settings = $this->sanitize_admin_settings($_POST);
        update_option($this->opt_name, $settings, false);
        $stored = $this->get_settings();
        if ( $stored !== $settings ) {
            wp_send_json_error(array('message' => 'WordPress could not verify the saved settings.'), 500);
        }
        wp_send_json_success(['message' => 'Settings saved successfully.']);
    }

    public function admin_save_settings_fallback(){
        if ( ! current_user_can('manage_options') ) wp_die(esc_html__('You are not allowed to save these settings.', 'incomplete-orders-tracker'));
        check_admin_referer('iot-admin', 'iot-admin-nonce');
        update_option($this->opt_name, $this->sanitize_admin_settings($_POST), false);
        wp_safe_redirect(admin_url('admin.php?page=incomplete-orders-tracker-settings&iot_saved=1'));
        exit;
    }

    public function ajax_mark_complete(){
        if ( ! current_user_can('manage_options') ) wp_send_json_error(array('message' => 'Unauthorized.'), 403);
        if ( ! check_ajax_referer('iot-admin', 'nonce', false) ) wp_send_json_error(array('message' => 'Security check failed.'), 403);
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
        if (!$id) wp_send_json_error(array('message' => 'Invalid record.'), 400);
        global $wpdb;
        $updated = $wpdb->update(
            $this->table,
            array('status'=>'complete', 'order_id' => 0, 'updated_at' => current_time('mysql', true)),
            array('id'=>$id, 'status'=>'incomplete'),
            array('%s', '%d', '%s'),
            array('%d', '%s')
        );
        if ( false === $updated ) {
            wp_send_json_error(array('message' => 'The record could not be updated.'), 500);
        }
        if ( 0 === $updated ) {
            wp_send_json_error(array('message' => 'The record is already completed or no longer exists.'), 409);
        }
        wp_send_json_success(array('id'=>$id));
    }

    private function csv_safe_cell( $value ){
        $value = (string) $value;
        if ( preg_match('/^[=+\-@]/', ltrim($value)) ) $value = "'" . $value;
        return $value;
    }

    public function admin_export_csv(){
        if ( ! current_user_can('manage_options') ) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        check_admin_referer('iot_export_csv');
        global $wpdb;
        $table = $this->table;

        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=incomplete-orders-'.current_time('Y-m-d').'.csv');
        $out = fopen('php://output','w');
        fputcsv($out, array('ID','OrderID','Name','Email','Phone','Address','Product Link','Product Name','Status','IP','Date'));
        foreach($rows as $r) {
            fputcsv($out, array_map(array($this, 'csv_safe_cell'), array($r['id'],$r['order_id'],$r['name'],$r['email'],$r['phone'],$r['address'],$r['product_link'],$r['product_name'],$r['status'],$r['ip'],$r['created_at'])));
        }
        fclose($out);
        exit;
    }
}

IOT_Plugin::instance();
