<?php
/**
 * Form submission handler.
 *
 * Processes AJAX form submissions with CSRF nonce verification, honeypot
 * bot detection, rate limiting, input sanitization/validation, CAPTCHA
 * verification, database storage, and admin email notification.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AIMF_SCF_Handler
 */
class AIMF_SCF_Handler {

    /**
     * Nonce action name.
     */
    const NONCE_ACTION = 'ascf_submit_form_nonce';

    /**
     * Rate limit: max submissions per IP per hour.
     */
    const RATE_LIMIT_MAX = 5;

    /**
     * Rate limit window in seconds (1 hour).
     */
    const RATE_LIMIT_WINDOW = 3600;

    /**
     * Maximum name length.
     */
    const MAX_NAME_LENGTH = 100;

    /**
     * Maximum message length.
     */
    const MAX_MESSAGE_LENGTH = 5000;

    /**
     * Maximum CAPTCHA token length (reCAPTCHA v3 tokens are ~500 chars).
     */
    const MAX_CAPTCHA_TOKEN_LENGTH = 2048;

    /**
     * Minimum time to submit a form (seconds). Bots submit faster than humans.
     */
    const MIN_SUBMIT_TIME = 3;

    /**
     * Maximum age of a form render before the token expires (seconds).
     * Set generously (6 hours) to accommodate page caching.
     */
    const MAX_FORM_AGE = 21600;

    /**
     * Global rate limit: max total submissions per hour across all IPs.
     * Prevents distributed botnet flooding.
     */
    const GLOBAL_RATE_LIMIT_MAX = 50;

    /**
     * Global rate limit window in seconds (1 hour).
     */
    const GLOBAL_RATE_LIMIT_WINDOW = 3600;

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'wp_ajax_ascf_submit_form', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_ascf_submit_form', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_ascf_get_token', array( __CLASS__, 'handle_token_request' ) );
        add_action( 'wp_ajax_nopriv_ascf_get_token', array( __CLASS__, 'handle_token_request' ) );
    }

    /**
     * Handle a JS token fetch request.
     *
     * This is a lazy-fetch endpoint — the token is NOT in the page HTML.
     * JS calls this endpoint after the page loads AND the user interacts
     * (mousemove, keydown, touchstart, scroll). This means:
     *
     * 1. HTML scrapers get nothing (token isn't in the page source)
     * 2. Bots must make a second HTTP request (detectable pattern)
     * 3. Each token has a unique ID for anti-replay
     */
    public static function handle_token_request() {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'aimf-secure-contact-form' ) ), 403 );
        }

        // Generate a unique token ID and HMAC signature.
        // The token_id is random per request — each token is unique and single-use.
        $token_id  = wp_generate_password( 32, false, false );
        $signature = wp_hash( $token_id . $nonce . 'ascf_js_token' );

        wp_send_json_success( array( 'token' => $token_id . '|' . $signature ) );
    }

    /**
     * Handle the AJAX form submission.
     */
    public static function handle_submission() {
        // Verify nonce.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            self::send_error( __( 'Security check failed. Please refresh the page and try again.', 'aimf-secure-contact-form' ), 403 );
        }

        // Honeypot check — if the hidden "company" field is filled, it's a bot.
        $honeypot = isset( $_POST['ascf_company'] ) ? sanitize_text_field( wp_unslash( $_POST['ascf_company'] ) ) : '';
        if ( ! empty( $honeypot ) ) {
            // Silently reject — pretend success so the bot doesn't know.
            self::send_success( __( 'Thank you for your message. We will get back to you shortly.', 'aimf-secure-contact-form' ) );
        }

        // Rate limiting.
        $ip_address = AIMF_SCF_Captcha::get_client_ip();
        if ( self::is_rate_limited( $ip_address ) ) {
            self::send_error( __( 'Too many submissions from your IP address. Please try again later.', 'aimf-secure-contact-form' ), 429 );
        }
        // Global rate limit — prevents distributed botnet flooding.
        if ( self::is_globally_rate_limited() ) {
            self::send_error( __( 'The contact form is temporarily unavailable due to high volume. Please try again later.', 'aimf-secure-contact-form' ), 429 );
        }

        // Increment both counters — counts all attempts that pass
        // nonce + honeypot, regardless of whether validation succeeds.
        self::increment_rate_limit( $ip_address );
        self::increment_global_rate_limit();

        // JS token verification — lazy-fetched, single-use, interaction-gated.
        // The token is NOT in the page HTML. JS fetches it from a separate AJAX
        // endpoint after the user interacts with the page (mousemove/keydown/etc).
        // Each token has a unique ID and can only be used once (anti-replay).
        $js_token = isset( $_POST['ascf_js_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ascf_js_token'] ) ) : '';
        if ( ! self::verify_js_token( $js_token, $nonce ) ) {
            self::send_error( __( 'Security check failed. Please enable JavaScript and try again.', 'aimf-secure-contact-form' ), 403 );
        }

        // Time-based bot detection — JS records page load time client-side.
        // If the field is missing (no JS) or the form was submitted too fast,
        // reject as a bot. Also check max age to prevent stale cached forms.
        $form_loaded = isset( $_POST['ascf_form_loaded'] ) ? sanitize_text_field( wp_unslash( $_POST['ascf_form_loaded'] ) ) : '';
        $time_check = self::verify_form_time( $form_loaded );
        if ( is_wp_error( $time_check ) ) {
            self::send_error( $time_check->get_error_message(), 403 );
        }

        // Determine which form was submitted and how to validate it.
        $form_id = isset( $_POST['ascf_form_id'] ) ? absint( wp_unslash( $_POST['ascf_form_id'] ) ) : 1;
        $form_def = AIMF_SCF_Forms::get_form( $form_id );

        // If we have a dynamic form definition with fields, use dynamic validation.
        if ( $form_def && ! empty( $form_def['fields'] ) ) {
            self::handle_dynamic_fields( $form_def, $ip_address );
            return; // handle_dynamic_fields sends the response.
        }

        // --- Legacy hardcoded validation (for form ID 1 before migration) ---

        // Sanitize and validate inputs.
        $name    = isset( $_POST['ascf_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ascf_name'] ) ) : '';
        $email   = isset( $_POST['ascf_email'] ) ? sanitize_email( wp_unslash( $_POST['ascf_email'] ) ) : '';
        $service = isset( $_POST['ascf_service'] ) ? sanitize_text_field( wp_unslash( $_POST['ascf_service'] ) ) : '';
        $message = isset( $_POST['ascf_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ascf_message'] ) ) : '';

        // Validate required fields.
        if ( empty( $name ) ) {
            self::send_error( __( 'Please enter your name.', 'aimf-secure-contact-form' ) );
        }
        if ( mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
            self::send_error( __( 'Name is too long (maximum 100 characters).', 'aimf-secure-contact-form' ) );
        }
        if ( empty( $email ) || ! is_email( $email ) ) {
            self::send_error( __( 'Please enter a valid email address.', 'aimf-secure-contact-form' ) );
        }
        if ( empty( $service ) ) {
            self::send_error( __( 'Please select a service.', 'aimf-secure-contact-form' ) );
        }
        if ( ! self::is_valid_service( $service ) ) {
            self::send_error( __( 'Invalid service selection.', 'aimf-secure-contact-form' ) );
        }
        if ( empty( $message ) ) {
            self::send_error( __( 'Please enter a message.', 'aimf-secure-contact-form' ) );
        }
        if ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
            self::send_error( __( 'Message is too long (maximum 5000 characters).', 'aimf-secure-contact-form' ) );
        }

        // Check for malicious patterns in any field.
        if ( self::contains_malicious_patterns( $name )
            || self::contains_malicious_patterns( $email )
            || self::contains_malicious_patterns( $service )
            || self::contains_malicious_patterns( $message ) ) {
            self::send_error( __( 'Your submission contains disallowed content.', 'aimf-secure-contact-form' ), 400 );
        }

        // CAPTCHA verification.
        $captcha_token = isset( $_POST['ascf_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ascf_captcha_token'] ) ) : '';
        if ( strlen( $captcha_token ) > self::MAX_CAPTCHA_TOKEN_LENGTH ) {
            self::send_error( __( 'CAPTCHA token is invalid. Please refresh the page and try again.', 'aimf-secure-contact-form' ), 400 );
        }
        $captcha_result = AIMF_SCF_Captcha::verify( $captcha_token );
        if ( ! $captcha_result['success'] ) {
            self::send_error( $captcha_result['error'], 403 );
        }

        // Get user agent for audit trail.
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        // Akismet spam check — if enabled and Akismet is active.
        $is_spam = self::check_akismet( $name, $email, $message, $ip_address, $user_agent );

        // Store submission in database.
        $inserted = self::store_submission( array(
            'name'         => $name,
            'email'        => $email,
            'service'      => $service,
            'message'      => $message,
            'ip_address'   => $ip_address,
            'user_agent'   => $user_agent,
            'captcha_score' => $captcha_result['score'],
            'status'       => $is_spam ? 'spam' : 'new',
        ) );

        if ( ! $inserted ) {
            self::send_error( __( 'An error occurred while saving your submission. Please try again.', 'aimf-secure-contact-form' ), 500 );
        }

        // Send admin notification email — but NOT for spam submissions.
        if ( ! $is_spam ) {
            self::send_notification_email( $name, $email, $service, $message, $ip_address );
        }

        // Return success to the user regardless of spam status (don't tip off spammers).
        self::send_success( __( 'Thank you for your message. We will get back to you shortly.', 'aimf-secure-contact-form' ) );
    }

    /**
     * Handle a dynamic form submission.
     *
     * Validates each field based on the form definition, stores the submission
     * with form_id and fields_data (JSON), and sends notification email.
     *
     * @param array  $form_def   Form definition from AIMF_SCF_Forms.
     * @param string $ip_address Client IP.
     */
    private static function handle_dynamic_fields( $form_def, $ip_address ) {
        $fields_data = array();
        $errors      = array();

        // Process each field from the form definition.
        foreach ( $form_def['fields'] as $field ) {
            $post_key = 'ascf_field_' . $field['id'];
            $raw_value = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : '';

            // Sanitize based on field type.
            $sanitized = AIMF_SCF_Fields::sanitize_value( $field, $raw_value );

            // Validate.
            $validation = AIMF_SCF_Fields::validate_value( $field, $sanitized );
            if ( true !== $validation ) {
                $errors[] = $validation;
                continue;
            }

            // Check for malicious patterns in text-based fields.
            if ( is_string( $sanitized ) && self::contains_malicious_patterns( $sanitized ) ) {
                self::send_error( __( 'Your submission contains disallowed content.', 'aimf-secure-contact-form' ), 400 );
            }
            if ( is_array( $sanitized ) ) {
                foreach ( $sanitized as $sv ) {
                    if ( self::contains_malicious_patterns( $sv ) ) {
                        self::send_error( __( 'Your submission contains disallowed content.', 'aimf-secure-contact-form' ), 400 );
                    }
                }
            }

            $fields_data[ $field['label'] ] = AIMF_SCF_Fields::format_value( $field, $sanitized );
        }

        // If there were validation errors, return the first one.
        if ( ! empty( $errors ) ) {
            self::send_error( $errors[0] );
        }

        // CAPTCHA verification.
        $captcha_token = isset( $_POST['ascf_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ascf_captcha_token'] ) ) : '';
        if ( strlen( $captcha_token ) > self::MAX_CAPTCHA_TOKEN_LENGTH ) {
            self::send_error( __( 'CAPTCHA token is invalid. Please refresh the page and try again.', 'aimf-secure-contact-form' ), 400 );
        }
        $captcha_result = AIMF_SCF_Captcha::verify( $captcha_token );
        if ( ! $captcha_result['success'] ) {
            self::send_error( $captcha_result['error'], 403 );
        }

        // Get user agent.
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        // Extract name/email for backward compat with the admin view and Akismet.
        $name    = isset( $fields_data['Name'] ) ? $fields_data['Name'] : ( isset( $fields_data['name'] ) ? $fields_data['name'] : '' );
        $email   = isset( $fields_data['Email'] ) ? $fields_data['Email'] : ( isset( $fields_data['email'] ) ? $fields_data['email'] : '' );
        $message = isset( $fields_data['Message'] ) ? $fields_data['Message'] : ( isset( $fields_data['message'] ) ? $fields_data['message'] : '' );
        $service = isset( $fields_data['Service'] ) ? $fields_data['Service'] : ( isset( $fields_data['service'] ) ? $fields_data['service'] : '' );

        // Akismet spam check.
        $is_spam = self::check_akismet( $name, $email, $message, $ip_address, $user_agent );

        // Store submission.
        $inserted = self::store_submission( array(
            'form_id'      => $form_def['id'],
            'name'         => $name,
            'email'        => $email,
            'service'      => $service,
            'message'      => $message,
            'fields_data'  => wp_json_encode( $fields_data ),
            'ip_address'   => $ip_address,
            'user_agent'   => $user_agent,
            'captcha_score' => $captcha_result['score'],
            'status'       => $is_spam ? 'spam' : 'new',
        ) );

        if ( ! $inserted ) {
            self::send_error( __( 'An error occurred while saving your submission. Please try again.', 'aimf-secure-contact-form' ), 500 );
        }

        // Send notification email.
        if ( ! $is_spam ) {
            // Use form-specific notification email if set, otherwise fall back to global.
            $form_settings = isset( $form_def['settings'] ) ? $form_def['settings'] : array();
            $notif_email = isset( $form_settings['notification_email'] ) && $form_settings['notification_email'] ? $form_settings['notification_email'] : '';
            if ( $notif_email ) {
                self::send_dynamic_notification_email( $notif_email, $form_def['title'], $fields_data, $ip_address );
            } else {
                self::send_notification_email( $name, $email, $service, $message, $ip_address );
            }
        }

        // Return success message from form settings or default.
        $success_msg = isset( $form_def['settings']['success_message'] ) && $form_def['settings']['success_message']
            ? $form_def['settings']['success_message']
            : __( 'Thank you for your message. We will get back to you shortly.', 'aimf-secure-contact-form' );
        self::send_success( $success_msg );
    }

    /**
     * Send a notification email for a dynamic form submission.
     *
     * @param string $to          Recipient email.
     * @param string $form_title  Form title.
     * @param array  $fields_data Field data (label => value).
     * @param string $ip_address  Sender IP.
     */
    private static function send_dynamic_notification_email( $to, $form_title, $fields_data, $ip_address ) {
        if ( empty( $to ) ) {
            $to = get_option( 'admin_email' );
        }

        $subject = sprintf( __( '[%s] New submission: %s', 'aimf-secure-contact-form' ), get_bloginfo( 'name' ), $form_title );

        // Build HTML email body.
        $html  = '<html><body style="font-family: sans-serif; font-size: 14px; color: #333;">';
        $html .= '<h2 style="color: #1a1a1a;">' . esc_html( $form_title ) . '</h2>';
        $html .= '<table style="border-collapse: collapse; width: 100%; max-width: 600px;">';

        foreach ( $fields_data as $label => $value ) {
            $html .= '<tr>';
            $html .= '<td style="padding: 8px 12px; border: 1px solid #e0e0e0; background: #f9f9f9; font-weight: 600; width: 30%;">' . esc_html( $label ) . '</td>';
            $html .= '<td style="padding: 8px 12px; border: 1px solid #e0e0e0;">' . nl2br( esc_html( $value ) ) . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr>';
        $html .= '<td style="padding: 8px 12px; border: 1px solid #e0e0e0; background: #f9f9f9; font-weight: 600;">IP Address</td>';
        $html .= '<td style="padding: 8px 12px; border: 1px solid #e0e0e0;">' . esc_html( $ip_address ) . '</td>';
        $html .= '</tr>';
        $html .= '</table>';
        $html .= '<p style="margin-top: 20px; font-size: 12px; color: #999;">' . esc_html__( 'Submitted via AIMF Secure Forms', 'aimf-secure-contact-form' ) . '</p>';
        $html .= '</body></html>';

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        wp_mail( $to, $subject, $html, $headers );
    }

    /**
     * Check if the IP address has exceeded the rate limit.
     *
     * @param string $ip_address The client IP address.
     * @return bool True if rate limited.
     */
    private static function is_rate_limited( $ip_address ) {
        $transient_key = 'ascf_rl_' . md5( $ip_address );
        $count         = get_transient( $transient_key );

        if ( false === $count ) {
            return false;
        }

        return (int) $count >= self::RATE_LIMIT_MAX;
    }

    /**
     * Increment the rate limit counter for an IP address.
     *
     * @param string $ip_address The client IP address.
     */
    private static function increment_rate_limit( $ip_address ) {
        $transient_key = 'ascf_rl_' . md5( $ip_address );
        $count         = get_transient( $transient_key );

        if ( false === $count ) {
            set_transient( $transient_key, 1, self::RATE_LIMIT_WINDOW );
        } else {
            set_transient( $transient_key, (int) $count + 1, self::RATE_LIMIT_WINDOW );
        }
    }

    /**
     * Check if the global rate limit has been exceeded.
     *
     * @return bool True if globally rate limited.
     */
    private static function is_globally_rate_limited() {
        $count = get_transient( 'ascf_global_rl' );

        if ( false === $count ) {
            return false;
        }

        return (int) $count >= self::GLOBAL_RATE_LIMIT_MAX;
    }

    /**
     * Increment the global rate limit counter.
     */
    private static function increment_global_rate_limit() {
        $count = get_transient( 'ascf_global_rl' );

        if ( false === $count ) {
            set_transient( 'ascf_global_rl', 1, self::GLOBAL_RATE_LIMIT_WINDOW );
        } else {
            set_transient( 'ascf_global_rl', (int) $count + 1, self::GLOBAL_RATE_LIMIT_WINDOW );
        }
    }

    /**
     * Check if a service option is valid.
     *
     * @param string $service The service value to check.
     * @return bool
     */
    private static function is_valid_service( $service ) {
        $valid_services = array(
            'Quick Start Assessment',
            'Website Security Audit',
            'Marketing Channel Security Audit',
            'Cloudflare Setup',
            'Google Workspace Security',
            'Device Hardening',
            'Network Hardening',
            '1:1 Security Training',
            'Multiple Services',
            'Other',
        );

        return in_array( $service, $valid_services, true );
    }

    /**
     * Check if a string contains malicious patterns.
     *
     * @param string $input The input to check.
     * @return bool True if malicious patterns found.
     */
    private static function contains_malicious_patterns( $input ) {
        $patterns = array(
            '<script',
            'javascript:',
            'onerror=',
            'onload=',
            'onclick=',
            'onmouseover=',
            'onfocus=',
            'onblur=',
            'onchange=',
            'onsubmit=',
            'ontoggle=',
            'onanimationstart=',
            '<iframe',
            '<object',
            '<embed',
            '<svg',
            '<img',
            '<video',
            '<audio',
            'data:text/html',
            'data:application/',
            'vbscript:',
            'expression(',
        );

        $lower_input = strtolower( $input );

        foreach ( $patterns as $pattern ) {
            if ( false !== strpos( $lower_input, strtolower( $pattern ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store a submission in the custom database table.
     *
     * @param array $data The submission data.
     * @return bool|int False on failure, last insert ID on success.
     */
    private static function store_submission( $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;

        $status = isset( $data['status'] ) ? $data['status'] : 'new';

        $insert_data = array(
            'form_id'       => isset( $data['form_id'] ) ? $data['form_id'] : 1,
            'name'          => $data['name'],
            'email'         => $data['email'],
            'service'       => $data['service'],
            'message'       => $data['message'],
            'fields_data'   => isset( $data['fields_data'] ) ? $data['fields_data'] : null,
            'ip_address'    => $data['ip_address'],
            'user_agent'    => $data['user_agent'],
            'captcha_score' => $data['captcha_score'],
            'status'        => $status,
        );

        $format = array(
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            isset( $data['fields_data'] ) ? '%s' : null,
            '%s',
            '%s',
            is_null( $data['captcha_score'] ) ? null : '%f',
            '%s',
        );

        // Remove null format entries (for optional fields).
        $format = array_values( array_filter( $format, function ( $v ) { return null !== $v; } ) );
        // Remove null data entries.
        $insert_data = array_filter( $insert_data, function ( $v ) { return null !== $v; } );

        $inserted = $wpdb->insert( $table_name, $insert_data, $format );

        if ( false === $inserted ) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Send a notification email to the admin.
     *
     * @param string $name       Sender name.
     * @param string $email      Sender email.
     * @param string $service    Selected service.
     * @param string $message    Message body.
     * @param string $ip_address Sender IP address.
     */
    private static function send_notification_email( $name, $email, $service, $message, $ip_address ) {
        $settings = get_option( 'aimf_scf_settings', array() );

        $to = isset( $settings['notification_email'] ) && is_email( $settings['notification_email'] )
            ? $settings['notification_email']
            : get_option( 'admin_email' );

        $site_name = get_bloginfo( 'name' );

        $subject = sprintf(
            /* translators: 1: site name, 2: sender name */
            __( '[%1$s] New Form Submission from %2$s', 'aimf-secure-contact-form' ),
            $site_name,
            $name
        );

        // Build a sanitized HTML email body — all user input escaped.
        $html  = '<html><body style="font-family: sans-serif; color: #333;">';
        $html .= '<h2>' . esc_html( sprintf( __( 'New Form Submission — %s', 'aimf-secure-contact-form' ), $site_name ) ) . '</h2>';
        $html .= '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">';
        $html .= '<tr><td style="font-weight: 600;">' . esc_html__( 'Name:', 'aimf-secure-contact-form' ) . '</td><td>' . esc_html( $name ) . '</td></tr>';
        $html .= '<tr><td style="font-weight: 600;">' . esc_html__( 'Email:', 'aimf-secure-contact-form' ) . '</td><td>' . esc_html( $email ) . '</td></tr>';
        $html .= '<tr><td style="font-weight: 600;">' . esc_html__( 'Service:', 'aimf-secure-contact-form' ) . '</td><td>' . esc_html( $service ) . '</td></tr>';
        $html .= '<tr><td style="font-weight: 600;">' . esc_html__( 'IP Address:', 'aimf-secure-contact-form' ) . '</td><td>' . esc_html( $ip_address ) . '</td></tr>';
        $html .= '<tr><td style="font-weight: 600;">' . esc_html__( 'Submitted:', 'aimf-secure-contact-form' ) . '</td><td>' . esc_html( current_time( 'mysql' ) ) . '</td></tr>';
        $html .= '</table>';
        $html .= '<h3>' . esc_html__( 'Message:', 'aimf-secure-contact-form' ) . '</h3>';
        $html .= '<div style="background: #f4f4f4; padding: 12px; border-radius: 6px; border-left: 3px solid #f97316;">' . nl2br( esc_html( $message ) ) . '</div>';
        $html .= '</body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        wp_mail( $to, $subject, $html, $headers );
    }

    /**
     * Verify the lazy-fetched JS token.
     *
     * The token format is "token_id|signature" where:
     * - token_id is a random 32-char string (unique per fetch)
     * - signature is wp_hash(token_id . nonce . 'ascf_js_token')
     *
     * Verification checks:
     * 1. Format is valid (two parts separated by |)
     * 2. HMAC signature matches (authenticity — can't be forged)
     * 3. Token hasn't been used before (anti-replay)
     * 4. Marks the token as used after successful verification
     *
     * @param string $token The submitted JS token ("token_id|signature").
     * @param string $nonce The submitted nonce.
     * @return bool True if the token is valid and unused.
     */
    private static function verify_js_token( $token, $nonce ) {
        if ( empty( $token ) || empty( $nonce ) ) {
            return false;
        }

        // Parse "token_id|signature" format.
        $parts = explode( '|', $token, 2 );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        $token_id  = $parts[0];
        $signature = $parts[1];

        // Verify length sanity (token_id should be 32 chars).
        if ( strlen( $token_id ) !== 32 || empty( $signature ) ) {
            return false;
        }

        // Verify HMAC authenticity — proves the token came from our server.
        $expected = wp_hash( $token_id . $nonce . 'ascf_js_token' );
        if ( ! hash_equals( $expected, $signature ) ) {
            return false;
        }

        // Anti-replay: check if this token has already been used.
        $replay_key = 'ascf_tkn_' . md5( $token_id );
        if ( false !== get_transient( $replay_key ) ) {
            return false;
        }

        // Mark as used. TTL covers form max age + 1 hour buffer.
        set_transient( $replay_key, 1, self::MAX_FORM_AGE + HOUR_IN_SECONDS );

        return true;
    }

    /**
     * Verify the server-signed form render timestamp.
     *
     * The form emits a server timestamp + HMAC signature as "timestamp|signature".
     * We verify:
     * 1. The field is present and correctly formatted
     * 2. The HMAC signature is valid (can't be forged by the client)
     * 3. The elapsed time is at least the configured minimum (bot speed check)
     * 4. The elapsed time is at most MAX_FORM_AGE (stale form / cache expiry)
     *
     * @param string $form_loaded The signed timestamp ("timestamp|signature").
     * @return bool|WP_Error True if valid, WP_Error on failure.
     */
    private static function verify_form_time( $form_loaded ) {
        if ( empty( $form_loaded ) ) {
            return new WP_Error( 'missing', __( 'Form timing data is missing. Please refresh the page.', 'aimf-secure-contact-form' ) );
        }

        // Parse the "timestamp|signature" format.
        $parts = explode( '|', $form_loaded, 2 );
        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'invalid_format', __( 'Invalid form submission timing.', 'aimf-secure-contact-form' ) );
        }

        $render_time = $parts[0];
        $render_sig  = $parts[1];

        // Verify the HMAC signature — this proves the timestamp came from
        // our server and hasn't been tampered with by the client.
        $expected_sig = wp_hash( $render_time . 'ascf_render_time' );
        if ( ! hash_equals( $expected_sig, $render_sig ) ) {
            return new WP_Error( 'invalid_sig', __( 'Form security check failed. Please refresh the page.', 'aimf-secure-contact-form' ) );
        }

        $render_seconds = absint( $render_time );
        if ( 0 === $render_seconds ) {
            return new WP_Error( 'invalid_time', __( 'Invalid form submission timing.', 'aimf-secure-contact-form' ) );
        }

        $now = time();
        $elapsed = $now - $render_seconds;

        // Use the admin-configured minimum time, falling back to the constant.
        $settings = get_option( 'aimf_scf_settings', array() );
        $min_time = isset( $settings['min_submit_time'] ) ? absint( $settings['min_submit_time'] ) : self::MIN_SUBMIT_TIME;
        if ( $min_time < 1 ) {
            $min_time = self::MIN_SUBMIT_TIME;
        }

        if ( $elapsed < $min_time ) {
            return new WP_Error( 'too_fast', __( 'Form submitted too quickly. Please try again.', 'aimf-secure-contact-form' ) );
        }

        if ( $elapsed > self::MAX_FORM_AGE ) {
            return new WP_Error( 'expired', __( 'Form has expired. Please refresh the page and try again.', 'aimf-secure-contact-form' ) );
        }

        return true;
    }

    /**
     * Check a submission against Akismet if it's enabled and active.
     *
     * @param string $name       Sender name.
     * @param string $email      Sender email.
     * @param string $message    Message body.
     * @param string $ip_address Sender IP.
     * @param string $user_agent Sender user agent.
     * @return bool True if Akismet says this is spam (or if Akismet is not available).
     */
    private static function check_akismet( $name, $email, $message, $ip_address, $user_agent ) {
        $settings = get_option( 'aimf_scf_settings', array() );

        // Only check if the admin has enabled Akismet integration.
        if ( empty( $settings['enable_akismet'] ) ) {
            return false;
        }

        // Check if Akismet plugin is active and configured.
        if ( ! class_exists( 'Akismet' ) || ! method_exists( 'Akismet', 'is_api_key_configured' ) ) {
            return false;
        }
        if ( ! Akismet::is_api_key_configured() ) {
            return false;
        }

        // Build the Akismet comment-check request.
        $akismet_data = array(
            'blog'                 => get_option( 'home' ),
            'user_ip'              => $ip_address,
            'user_agent'           => $user_agent,
            'comment_type'         => 'contact-form',
            'comment_author'       => $name,
            'comment_author_email' => $email,
            'comment_content'      => $message,
        );

        $response = Akismet::http_post( http_build_query( $akismet_data ), 'comment-check' );

        // Akismet returns 'true' in the body if the submission is spam.
        if ( isset( $response[1] ) && 'true' === trim( $response[1] ) ) {
            return true;
        }

        return false;
    }

    /**
     * Send a JSON success response and die.
     *
     * @param string $message The success message.
     */
    private static function send_success( $message ) {
        wp_send_json_success( array( 'message' => $message ) );
    }

    /**
     * Send a JSON error response and die.
     *
     * @param string $message The error message.
     * @param int    $code    HTTP status code (optional).
     */
    private static function send_error( $message, $code = 400 ) {
        wp_send_json_error( array( 'message' => $message ), $code );
    }
}
