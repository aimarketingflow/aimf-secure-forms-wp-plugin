<?php
/**
 * GitHub Releases update integration.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIMF_SCF_Updater {

    const API_URL = 'https://api.github.com/repos/aimarketingflow/aimf-secure-forms-wp-plugin/releases/latest';
    const REPOSITORY_URL = 'https://github.com/aimarketingflow/aimf-secure-forms-wp-plugin';
    const RELEASE_ASSET = 'aimf-secure-forms.zip';
    const CACHE_KEY = 'ascf_github_release';

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
        add_filter( 'http_request_args', array( __CLASS__, 'authenticate_private_download' ), 10, 2 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache_after_update' ), 10, 2 );
    }

    public static function check_for_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }

        $release = self::get_latest_release();
        if ( is_wp_error( $release ) || ! version_compare( $release['version'], AIMF_SCF_VERSION, '>' ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( AIMF_SCF_PLUGIN_DIR . 'aimf-secure-contact-form.php' );
        $transient->response[ $plugin_file ] = (object) array(
            'id'          => self::REPOSITORY_URL,
            'slug'        => 'aimf-secure-contact-form',
            'plugin'      => $plugin_file,
            'new_version' => $release['version'],
            'url'         => self::REPOSITORY_URL,
            'package'     => $release['package'],
            'icons'       => array(),
            'banners'     => array(),
            'tested'      => '',
            'requires_php' => '7.4',
        );

        return $transient;
    }

    public static function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'aimf-secure-contact-form' !== $args->slug ) {
            return $result;
        }

        $release = self::get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $result;
        }

        return (object) array(
            'name'          => 'AIMF Secure Forms',
            'slug'          => 'aimf-secure-contact-form',
            'version'       => $release['version'],
            'author'        => '<a href="https://aimfsecurity.com">AIMF Security</a>',
            'homepage'      => self::REPOSITORY_URL,
            'download_link' => $release['package'],
            'sections'      => array(
                'description' => '<p>Security-hardened WordPress form builder with Turnstile and encrypted exports.</p>',
                'changelog'   => wpautop( esc_html( $release['notes'] ) ),
            ),
        );
    }

    public static function authenticate_private_download( $args, $url ) {
        $asset_api_prefix = 'https://api.github.com/repos/aimarketingflow/aimf-secure-forms-wp-plugin/releases/assets/';
        if ( 0 !== strpos( $url, $asset_api_prefix ) || ! self::has_token() ) {
            return $args;
        }

        $args['headers']['Authorization'] = 'Bearer ' . AIMF_SCF_GITHUB_TOKEN;
        $args['headers']['Accept'] = 'application/octet-stream';
        $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
        $args['headers']['User-Agent'] = 'AIMF-Secure-Forms/' . AIMF_SCF_VERSION;
        return $args;
    }

    public static function clear_cache_after_update( $upgrader, $options ) {
        if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
            delete_site_transient( self::CACHE_KEY );
        }
    }

    private static function get_latest_release() {
        $cached = get_site_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $headers = array(
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'AIMF-Secure-Forms/' . AIMF_SCF_VERSION,
        );
        if ( self::has_token() ) {
            $headers['Authorization'] = 'Bearer ' . AIMF_SCF_GITHUB_TOKEN;
        }

        $response = wp_safe_remote_get( self::API_URL, array(
            'headers'   => $headers,
            'timeout'   => 10,
            'sslverify' => true,
        ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return new WP_Error( 'release_unavailable', __( 'Unable to retrieve AIMF Secure Forms release information.', 'aimf-secure-contact-form' ) );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
            return new WP_Error( 'invalid_release', __( 'GitHub returned invalid release information.', 'aimf-secure-contact-form' ) );
        }

        $package = '';
        foreach ( $data['assets'] as $asset ) {
            if ( isset( $asset['name'] ) && self::RELEASE_ASSET === $asset['name'] ) {
                $package = self::has_token() && ! empty( $asset['url'] ) ? $asset['url'] : ( isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '' );
                break;
            }
        }
        if ( empty( $package ) || 0 !== strpos( $package, 'https://' ) ) {
            return new WP_Error( 'release_asset_missing', __( 'The installable AIMF Secure Forms release asset is missing.', 'aimf-secure-contact-form' ) );
        }

        $version = ltrim( sanitize_text_field( $data['tag_name'] ), 'vV' );
        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
            return new WP_Error( 'invalid_version', __( 'The GitHub release version is invalid.', 'aimf-secure-contact-form' ) );
        }

        $release = array(
            'version' => $version,
            'package' => esc_url_raw( $package ),
            'notes'   => isset( $data['body'] ) ? sanitize_textarea_field( $data['body'] ) : '',
        );
        set_site_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
        return $release;
    }

    private static function has_token() {
        return defined( 'AIMF_SCF_GITHUB_TOKEN' ) && is_string( AIMF_SCF_GITHUB_TOKEN ) && '' !== AIMF_SCF_GITHUB_TOKEN;
    }
}
