<?php
/**
 * CAPTCHA verification class.
 *
 * Supports Cloudflare Turnstile, Google reCAPTCHA v3, and hCaptcha.
 * Verification is optional — if no CAPTCHA provider is configured,
 * submissions are allowed through.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AIMF_SCF_Captcha
 */
class AIMF_SCF_Captcha {

    const TURNSTILE_ACTION = 'aimf_secure_form';

    /**
     * Cached plugin settings.
     *
     * @var array|null
     */
    private static $settings = null;

    /**
     * Retrieve plugin settings (cached).
     *
     * @return array
     */
    public static function get_settings() {
        if ( null === self::$settings ) {
            self::$settings = get_option( 'aimf_scf_settings', array() );
        }
        return self::$settings;
    }

    /**
     * Check whether reCAPTCHA is configured (both site and secret keys present).
     *
     * @return bool
     */
    public static function is_recaptcha_configured() {
        $settings = self::get_settings();
        return ! empty( $settings['recaptcha_site_key'] ) && ! empty( $settings['recaptcha_secret_key'] );
    }

    /**
     * Check whether hCaptcha is configured (both site and secret keys present).
     *
     * @return bool
     */
    public static function is_hcaptcha_configured() {
        $settings = self::get_settings();
        return ! empty( $settings['hcaptcha_site_key'] ) && ! empty( $settings['hcaptcha_secret_key'] );
    }

    /**
     * Check whether Cloudflare Turnstile is configured (both site and secret keys present).
     *
     * @return bool
     */
    public static function is_turnstile_configured() {
        $settings = self::get_settings();
        return ! empty( $settings['turnstile_site_key'] ) && ! empty( $settings['turnstile_secret_key'] );
    }

    /**
     * Check whether any CAPTCHA provider is configured.
     *
     * @return bool
     */
    public static function is_configured() {
        return self::is_turnstile_configured() || self::is_recaptcha_configured() || self::is_hcaptcha_configured();
    }

    /**
     * Get the reCAPTCHA site key.
     *
     * @return string
     */
    public static function get_recaptcha_site_key() {
        $settings = self::get_settings();
        return isset( $settings['recaptcha_site_key'] ) ? $settings['recaptcha_site_key'] : '';
    }

    /**
     * Get the hCaptcha site key.
     *
     * @return string
     */
    public static function get_hcaptcha_site_key() {
        $settings = self::get_settings();
        return isset( $settings['hcaptcha_site_key'] ) ? $settings['hcaptcha_site_key'] : '';
    }

    /**
     * Get the Cloudflare Turnstile site key.
     *
     * @return string
     */
    public static function get_turnstile_site_key() {
        $settings = self::get_settings();
        return isset( $settings['turnstile_site_key'] ) ? $settings['turnstile_site_key'] : '';
    }

    /**
     * Verify a CAPTCHA token. Dispatches to the appropriate provider based on
     * which keys are configured. Turnstile takes precedence, then reCAPTCHA,
     * then hCaptcha.
     *
     * @param string $token The CAPTCHA token from the form submission.
     * @return array {
     *     @type bool   $success  Whether verification passed.
     *     @type float  $score    reCAPTCHA score (0.0–1.0), or null for others.
     *     @type string $error    Error message on failure.
     * }
     */
    public static function verify( $token ) {
        // If no CAPTCHA is configured, allow the submission.
        if ( ! self::is_configured() ) {
            return array(
                'success' => true,
                'score'   => null,
                'error'   => null,
            );
        }

        // Turnstile takes precedence (most modern, lowest friction).
        if ( self::is_turnstile_configured() ) {
            return self::verify_turnstile( $token );
        }

        // reCAPTCHA next.
        if ( self::is_recaptcha_configured() ) {
            return self::verify_recaptcha( $token );
        }

        // hCaptcha fallback.
        return self::verify_hcaptcha( $token );
    }

    /**
     * Verify a Google reCAPTCHA v3 token via the siteverify endpoint.
     *
     * @param string $token The reCAPTCHA token.
     * @return array
     */
    public static function verify_recaptcha( $token ) {
        $settings = self::get_settings();
        $secret   = isset( $settings['recaptcha_secret_key'] ) ? $settings['recaptcha_secret_key'] : '';

        if ( empty( $token ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'CAPTCHA token is missing.', 'aimf-secure-contact-form' ),
            );
        }

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            array(
                'body'      => array(
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => self::get_client_ip(),
                ),
                'timeout'   => 15,
                'sslverify' => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'Unable to reach CAPTCHA verification service.', 'aimf-secure-contact-form' ),
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data['success'] ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'Invalid CAPTCHA verification response.', 'aimf-secure-contact-form' ),
            );
        }

        if ( ! $data['success'] ) {
            $error_codes = isset( $data['error-codes'] ) ? implode( ', ', $data['error-codes'] ) : '';
            return array(
                'success' => false,
                'score'   => null,
                'error'   => sprintf(
                    /* translators: %s: error codes from Google */
                    __( 'CAPTCHA verification failed: %s', 'aimf-secure-contact-form' ),
                    $error_codes
                ),
            );
        }

        $score = isset( $data['score'] ) ? floatval( $data['score'] ) : 1.0;

        // Reject scores below 0.5 (likely bot).
        if ( $score < 0.5 ) {
            return array(
                'success' => false,
                'score'   => $score,
                'error'   => __( 'CAPTCHA score too low — submission rejected.', 'aimf-secure-contact-form' ),
            );
        }

        return array(
            'success' => true,
            'score'   => $score,
            'error'   => null,
        );
    }

    /**
     * Verify an hCaptcha token via the siteverify endpoint.
     *
     * @param string $token The hCaptcha token.
     * @return array
     */
    public static function verify_hcaptcha( $token ) {
        $settings = self::get_settings();
        $secret   = isset( $settings['hcaptcha_secret_key'] ) ? $settings['hcaptcha_secret_key'] : '';

        if ( empty( $token ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'CAPTCHA token is missing.', 'aimf-secure-contact-form' ),
            );
        }

        $response = wp_remote_post(
            'https://api.hcaptcha.com/siteverify',
            array(
                'body'      => array(
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => self::get_client_ip(),
                ),
                'timeout'   => 15,
                'sslverify' => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'Unable to reach CAPTCHA verification service.', 'aimf-secure-contact-form' ),
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data['success'] ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'Invalid CAPTCHA verification response.', 'aimf-secure-contact-form' ),
            );
        }

        if ( ! $data['success'] ) {
            $error_codes = isset( $data['error-codes'] ) ? implode( ', ', $data['error-codes'] ) : '';
            return array(
                'success' => false,
                'score'   => null,
                'error'   => sprintf(
                    /* translators: %s: error codes from hCaptcha */
                    __( 'CAPTCHA verification failed: %s', 'aimf-secure-contact-form' ),
                    $error_codes
                ),
            );
        }

        return array(
            'success' => true,
            'score'   => null,
            'error'   => null,
        );
    }

    /**
     * Verify a Cloudflare Turnstile token via the siteverify endpoint.
     *
     * @param string $token The Turnstile token.
     * @return array
     */
    public static function verify_turnstile( $token ) {
        $settings = self::get_settings();
        $secret   = isset( $settings['turnstile_secret_key'] ) ? $settings['turnstile_secret_key'] : '';

        if ( empty( $token ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'CAPTCHA token is missing.', 'aimf-secure-contact-form' ),
            );
        }

        $response = wp_remote_post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            array(
                'body'      => array(
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => self::get_client_ip(),
                ),
                'timeout'   => 15,
                'sslverify' => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'Unable to reach CAPTCHA verification service.', 'aimf-secure-contact-form' ),
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( 200 !== $status_code || ! is_array( $data ) || ! isset( $data['success'] ) ) {
            return array(
                'success' => false,
                'score'   => null,
                'error'   => __( 'Invalid CAPTCHA verification response.', 'aimf-secure-contact-form' ),
            );
        }

        if ( ! $data['success'] ) {
            $error_codes = isset( $data['error-codes'] ) ? implode( ', ', $data['error-codes'] ) : '';
            return array(
                'success' => false,
                'score'   => null,
                'error'   => sprintf(
                    /* translators: %s: error codes from Cloudflare */
                    __( 'CAPTCHA verification failed: %s', 'aimf-secure-contact-form' ),
                    $error_codes
                ),
            );
        }

        $test_secrets = array(
            '1x0000000000000000000000000000000AA',
            '2x0000000000000000000000000000000AA',
            '3x0000000000000000000000000000000AA',
        );
        if ( ! in_array( $secret, $test_secrets, true ) ) {
            $expected_hostname = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
            $response_hostname = isset( $data['hostname'] ) ? strtolower( sanitize_text_field( $data['hostname'] ) ) : '';
            $response_action   = isset( $data['action'] ) ? sanitize_key( $data['action'] ) : '';

            if ( empty( $expected_hostname ) || ! hash_equals( $expected_hostname, $response_hostname ) ) {
                return array(
                    'success' => false,
                    'score'   => null,
                    'error'   => __( 'CAPTCHA hostname validation failed.', 'aimf-secure-contact-form' ),
                );
            }
            if ( ! hash_equals( self::TURNSTILE_ACTION, $response_action ) ) {
                return array(
                    'success' => false,
                    'score'   => null,
                    'error'   => __( 'CAPTCHA action validation failed.', 'aimf-secure-contact-form' ),
                );
            }
        }

        return array(
            'success' => true,
            'score'   => null,
            'error'   => null,
        );
    }

    /**
     * Safely retrieve the client IP address.
     *
     * Uses REMOTE_ADDR as the primary source. We do NOT trust forwarded
     * headers for security reasons (they can be spoofed). The IP is used
     * for rate limiting and audit trail only.
     *
     * @return string
     */
    public static function get_client_ip() {
        $ip = '';

        if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }
}
