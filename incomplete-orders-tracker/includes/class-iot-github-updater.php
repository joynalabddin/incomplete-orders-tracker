<?php
/**
 * Public GitHub Releases updater for Incomplete Orders Tracker.
 *
 * This integration only adds WordPress update notifications. It does not
 * require a license key, activation key, account, token, or paid service.
 *
 * @package Incomplete_Orders_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class IOT_GitHub_Updater {
    const RELEASE_CACHE_KEY = 'iot_github_latest_release';
    const RELEASE_CACHE_TTL = 6 * HOUR_IN_SECONDS;

    private static $plugin_file = '';
    private static $current_version = '';

    public static function register( $plugin_file, $current_version ) {
        self::$plugin_file      = $plugin_file;
        self::$current_version  = (string) $current_version;

        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
    }

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $plugin_basename = plugin_basename( self::$plugin_file );
        $release         = self::get_latest_release();
        $update          = self::build_update_object( $release );

        if ( ! $update ) {
            return $transient;
        }

        if ( version_compare( $update->new_version, self::$current_version, '>' ) ) {
            $transient->response[ $plugin_basename ] = $update;
        } else {
            // WordPress uses no_update for the external-plugin auto-update UI.
            $transient->no_update[ $plugin_basename ] = $update;
        }

        return $transient;
    }

    public static function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || self::slug() !== $args->slug ) {
            return $result;
        }

        $release = self::get_latest_release();
        $update  = self::build_update_object( $release );
        if ( ! $update ) {
            return $result;
        }

        $description = __( 'Free, activation-free tool to capture and recover incomplete WooCommerce checkouts with product context, responsive admin tools, WhatsApp follow-up, email recovery and CSV export.', 'incomplete-orders-tracker' );
        $release_notes = isset( $release['body'] ) && is_scalar( $release['body'] )
            ? wp_kses_post( wp_unslash( (string) $release['body'] ) )
            : __( 'See the GitHub release notes for this version.', 'incomplete-orders-tracker' );

        return (object) array(
            'name'              => 'Incomplete Orders Tracker',
            'slug'              => self::slug(),
            'version'           => $update->new_version,
            'author'            => '<a href="https://devjoynal.com">Joynal Abdin</a>',
            'author_profile'    => 'https://devjoynal.com',
            'homepage'          => 'https://devjoynal.com',
            'short_description' => $description,
            'sections'          => array(
                'description'  => '<p>' . esc_html( $description ) . '</p>',
                'installation' => '<p>' . esc_html__( 'Install the ZIP from WordPress Plugins → Add New → Upload Plugin. After installation, WordPress will show future GitHub release updates in the normal Updates screen for administrator approval.', 'incomplete-orders-tracker' ) . '</p>',
                'changelog'    => wpautop( $release_notes ),
            ),
            'download_link'     => $update->package,
            'requires'          => '6.2',
            'requires_php'      => '7.4',
            'last_updated'      => isset( $release['published_at'] ) ? sanitize_text_field( (string) $release['published_at'] ) : '',
            'banners'           => array(),
            'icons'             => array(),
        );
    }

    private static function build_update_object( $release ) {
        if ( ! is_array( $release ) || empty( $release['tag_name'] ) || empty( $release['download_url'] ) ) {
            return false;
        }

        $version = self::normalize_version( $release['tag_name'] );
        if ( '' === $version || ! version_compare( $version, '0.0.1', '>=' ) ) {
            return false;
        }

        $plugin_basename = plugin_basename( self::$plugin_file );
        return (object) array(
            'id'            => $plugin_basename,
            'slug'          => self::slug(),
            'plugin'        => $plugin_basename,
            'new_version'   => $version,
            'url'           => esc_url_raw( isset( $release['html_url'] ) ? $release['html_url'] : self::repository_url() ),
            'package'       => esc_url_raw( $release['download_url'] ),
            'icons'         => array(),
            'banners'       => array(),
            'banners_rtl'   => array(),
            'tested'        => '6.2',
            'requires_php'  => '7.4',
            'compatibility' => new stdClass(),
        );
    }

    private static function get_latest_release() {
        $cached = get_transient( self::RELEASE_CACHE_KEY );
        if ( is_array( $cached ) && ! empty( $cached['tag_name'] ) && ! empty( $cached['download_url'] ) ) {
            return $cached;
        }

        $response = wp_safe_remote_get(
            'https://api.github.com/repos/' . IOT_GITHUB_REPOSITORY . '/releases/latest',
            array(
                'timeout'     => 10,
                'redirection' => 2,
                'sslverify'   => true,
                'headers'     => array(
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'Incomplete-Orders-Tracker/' . self::$current_version . ' (+https://devjoynal.com)',
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return array();
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['html_url'] ) ) {
            return array();
        }

        $download_url = '';
        if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                if ( ! is_array( $asset ) ) {
                    continue;
                }
                $asset_name = isset( $asset['name'] ) ? sanitize_file_name( (string) $asset['name'] ) : '';
                if ( IOT_GITHUB_RELEASE_ASSET === $asset_name && ! empty( $asset['browser_download_url'] ) ) {
                    $download_url = esc_url_raw( $asset['browser_download_url'] );
                    break;
                }
            }
        }

        // A release without the expected asset is not treated as an update.
        if ( '' === $download_url ) {
            return array();
        }

        $release = array(
            'tag_name'     => sanitize_text_field( (string) $data['tag_name'] ),
            'html_url'     => esc_url_raw( (string) $data['html_url'] ),
            'download_url' => $download_url,
            'published_at' => isset( $data['published_at'] ) ? sanitize_text_field( (string) $data['published_at'] ) : '',
            'body'         => isset( $data['body'] ) && is_scalar( $data['body'] ) ? (string) $data['body'] : '',
        );

        set_transient( self::RELEASE_CACHE_KEY, $release, self::RELEASE_CACHE_TTL );
        return $release;
    }

    private static function normalize_version( $tag ) {
        $tag = trim( (string) $tag );
        $tag = preg_replace( '/^v/i', '', $tag );
        return preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $tag ) ? $tag : '';
    }

    private static function slug() {
        return 'incomplete-orders-tracker';
    }

    private static function repository_url() {
        return 'https://github.com/' . IOT_GITHUB_REPOSITORY;
    }
}
