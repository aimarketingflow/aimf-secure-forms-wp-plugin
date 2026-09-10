<?php
/**
 * Admin interface: submissions management and settings.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load the form builder classes (bootstrap is locked, so we require here).
require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-forms.php';
require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-fields.php';
require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-builder.php';
require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-encryption.php';

/**
 * Class AIMF_SCF_Admin
 */
class AIMF_SCF_Admin {

    /**
     * Admin page slug for the main submissions page.
     */
    const PAGE_SLUG = 'aimf-scf';

    /**
     * Admin page slug for the settings page.
     */
    const SETTINGS_SLUG = 'aimf-scf-settings';

    /**
     * Settings option name.
     */
    const OPTION_NAME = 'aimf_scf_settings';

    /**
     * Settings option group.
     */
    const OPTION_GROUP = 'aimf_scf_settings_group';

    /**
     * Initialize admin hooks.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_post_ascf_delete_submission', array( __CLASS__, 'handle_delete_submission' ) );
        add_action( 'admin_post_ascf_update_status', array( __CLASS__, 'handle_update_status' ) );
        add_action( 'admin_post_ascf_export_configuration', array( __CLASS__, 'handle_export_configuration' ) );
        add_action( 'admin_post_ascf_import_configuration', array( __CLASS__, 'handle_import_configuration' ) );
        add_action( 'admin_post_ascf_export_submissions', array( __CLASS__, 'handle_export_submissions' ) );

        // Initialize the form builder.
        AIMF_SCF_Builder::init();

        // Register the [aimf_form] shortcode (bootstrap is locked, only registers [aimf_contact_form]).
        AIMF_SCF_Form::init();

        // Migrate default form and add DB columns on first admin load.
        add_action( 'admin_init', array( __CLASS__, 'maybe_run_migration' ) );

        // Enqueue admin CSS on settings subpage (bootstrap only loads it on top-level page).
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_settings_assets' ) );

        // GDPR: data retention cron job.
        add_action( 'ascf_data_retention_cleanup', array( __CLASS__, 'run_data_retention_cleanup' ) );
        if ( ! wp_next_scheduled( 'ascf_data_retention_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'ascf_data_retention_cleanup' );
        }

        // GDPR: register personal data exporter and eraser.
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_data_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_data_eraser' ) );
    }

    /**
     * Enqueue admin CSS on the settings subpage.
     *
     * The bootstrap only enqueues admin CSS for the top-level submissions page.
     * This ensures the settings subpage also gets the brand header styling.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_settings_assets( $hook ) {
        if ( false === strpos( $hook, 'aimf-scf-settings' ) ) {
            return;
        }
        wp_enqueue_style(
            'aimf-scf-admin',
            AIMF_SCF_PLUGIN_URL . 'assets/ascf-admin.css',
            array(),
            AIMF_SCF_VERSION
        );
    }

    /**
     * Run migration: create default form + add DB columns if needed.
     */
    public static function maybe_run_migration() {
        // Migrate the default contact form to Form ID 1.
        AIMF_SCF_Forms::migrate_default_form();

        // Add form_id and fields_data columns to the submissions table.
        self::add_submission_columns();
    }

    /**
     * Add form_id and fields_data columns to the submissions table.
     */
    private static function add_submission_columns() {
        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;

        // Check if form_id column exists.
        $form_id_col = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_name} LIKE %s",
            'form_id'
        ) );
        if ( empty( $form_id_col ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN form_id bigint(20) unsigned NOT NULL DEFAULT 1 AFTER id" );
        }

        // Check if fields_data column exists.
        $fields_data_col = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_name} LIKE %s",
            'fields_data'
        ) );
        if ( empty( $fields_data_col ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN fields_data longtext NULL AFTER message" );
        }
    }

    /**
     * Add admin menu pages.
     */
    public static function add_admin_menu() {
        add_menu_page(
            __( 'AIMF Forms', 'aimf-secure-contact-form' ),
            __( 'AIMF Forms', 'aimf-secure-contact-form' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_submissions_page' ),
            'dashicons-email-alt',
            26
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __( 'Submissions', 'aimf-secure-contact-form' ),
            __( 'Submissions', 'aimf-secure-contact-form' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_submissions_page' )
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __( 'Settings', 'aimf-secure-contact-form' ),
            __( 'Settings', 'aimf-secure-contact-form' ),
            'manage_options',
            self::SETTINGS_SLUG,
            array( __CLASS__, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public static function register_settings() {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array( __CLASS__, 'sanitize_settings' )
        );

        add_settings_section(
            'ascf_settings_captcha',
            __( 'CAPTCHA Configuration', 'aimf-secure-contact-form' ),
            array( __CLASS__, 'render_captcha_section' ),
            self::SETTINGS_SLUG
        );

        add_settings_section(
            'ascf_settings_antispam',
            __( 'Anti-Spam & Bot Detection', 'aimf-secure-contact-form' ),
            array( __CLASS__, 'render_antispam_section' ),
            self::SETTINGS_SLUG
        );

        add_settings_section(
            'ascf_settings_notifications',
            __( 'Email Notifications', 'aimf-secure-contact-form' ),
            array( __CLASS__, 'render_notifications_section' ),
            self::SETTINGS_SLUG
        );

        $fields = array(
            'turnstile_site_key'   => __( 'Turnstile Site Key', 'aimf-secure-contact-form' ),
            'turnstile_secret_key' => __( 'Turnstile Secret Key', 'aimf-secure-contact-form' ),
            'recaptcha_site_key'   => __( 'reCAPTCHA Site Key', 'aimf-secure-contact-form' ),
            'recaptcha_secret_key' => __( 'reCAPTCHA Secret Key', 'aimf-secure-contact-form' ),
            'hcaptcha_site_key'    => __( 'hCaptcha Site Key', 'aimf-secure-contact-form' ),
            'hcaptcha_secret_key'  => __( 'hCaptcha Secret Key', 'aimf-secure-contact-form' ),
            'enable_akismet'       => __( 'Enable Akismet', 'aimf-secure-contact-form' ),
            'min_submit_time'      => __( 'Minimum Submit Time (seconds)', 'aimf-secure-contact-form' ),
            'data_retention_days'  => __( 'Data Retention (days)', 'aimf-secure-contact-form' ),
            'notification_email'   => __( 'Notification Email Address', 'aimf-secure-contact-form' ),
        );

        foreach ( $fields as $key => $label ) {
            if ( 'notification_email' === $key ) {
                $section = 'ascf_settings_notifications';
            } elseif ( 'enable_akismet' === $key || 'min_submit_time' === $key || 'data_retention_days' === $key ) {
                $section = 'ascf_settings_antispam';
            } else {
                $section = 'ascf_settings_captcha';
            }
            add_settings_field(
                $key,
                $label,
                array( __CLASS__, 'render_settings_field' ),
                self::SETTINGS_SLUG,
                $section,
                array( 'key' => $key )
            );
        }
    }

    /**
     * Sanitize settings input.
     *
     * @param array $input Raw input array.
     * @return array Sanitized settings.
     */
    public static function sanitize_settings( $input ) {
        $sanitized = array();

        $sanitized['turnstile_site_key']   = isset( $input['turnstile_site_key'] ) ? sanitize_text_field( $input['turnstile_site_key'] ) : '';
        $sanitized['turnstile_secret_key'] = isset( $input['turnstile_secret_key'] ) ? sanitize_text_field( $input['turnstile_secret_key'] ) : '';
        $sanitized['recaptcha_site_key']   = isset( $input['recaptcha_site_key'] ) ? sanitize_text_field( $input['recaptcha_site_key'] ) : '';
        $sanitized['recaptcha_secret_key'] = isset( $input['recaptcha_secret_key'] ) ? sanitize_text_field( $input['recaptcha_secret_key'] ) : '';
        $sanitized['hcaptcha_site_key']    = isset( $input['hcaptcha_site_key'] ) ? sanitize_text_field( $input['hcaptcha_site_key'] ) : '';
        $sanitized['hcaptcha_secret_key']  = isset( $input['hcaptcha_secret_key'] ) ? sanitize_text_field( $input['hcaptcha_secret_key'] ) : '';

        // Akismet toggle — checkbox, only present in POST if checked.
        $sanitized['enable_akismet'] = isset( $input['enable_akismet'] ) ? 1 : 0;

        // Minimum submit time — integer, default 3, clamped to 1-120.
        $min_time = isset( $input['min_submit_time'] ) ? absint( $input['min_submit_time'] ) : 3;
        if ( $min_time < 1 ) {
            $min_time = 1;
        }
        if ( $min_time > 120 ) {
            $min_time = 120;
        }
        $sanitized['min_submit_time'] = $min_time;

        // Data retention days — 0 means keep forever, otherwise 1-3650 days.
        $retention = isset( $input['data_retention_days'] ) ? absint( $input['data_retention_days'] ) : 0;
        if ( $retention > 3650 ) {
            $retention = 3650;
        }
        $sanitized['data_retention_days'] = $retention;

        $email = isset( $input['notification_email'] ) ? sanitize_email( $input['notification_email'] ) : '';
        if ( ! empty( $email ) && ! is_email( $email ) ) {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_email',
                __( 'The notification email address is not valid. It was not saved.', 'aimf-secure-contact-form' )
            );
        } else {
            $sanitized['notification_email'] = $email;
        }

        return $sanitized;
    }

    /**
     * Render the CAPTCHA settings section description.
     */
    public static function render_captcha_section() {
        echo '<p>' . esc_html__( 'Configure CAPTCHA to protect your form from automated spam. Turnstile is recommended (free, privacy-friendly). If no CAPTCHA provider is configured, submissions will be accepted without CAPTCHA verification.', 'aimf-secure-contact-form' ) . '</p>';
    }

    /**
     * Render the anti-spam settings section description.
     */
    public static function render_antispam_section() {
        echo '<p>' . esc_html__( 'Additional bot detection and spam filtering. These features work alongside CAPTCHA to provide layered protection.', 'aimf-secure-contact-form' ) . '</p>';
    }

    /**
     * Render the notifications settings section description.
     */
    public static function render_notifications_section() {
        echo '<p>' . esc_html__( 'Set the email address that receives new submission notifications. If left blank, the default WordPress admin email is used.', 'aimf-secure-contact-form' ) . '</p>';
    }

    /**
     * Render an individual settings field.
     *
     * @param array $args Field arguments.
     */
    public static function render_settings_field( $args ) {
        $settings = get_option( self::OPTION_NAME, array() );
        $key      = $args['key'];
        $value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

        // Handle special field types.
        if ( 'enable_akismet' === $key ) {
            $checked = ! empty( $value ) ? 'checked' : '';
            printf(
                '<label><input type="checkbox" id="%s" name="%s[%s]" value="1" %s /> %s</label>',
                esc_attr( $key ),
                esc_attr( self::OPTION_NAME ),
                esc_attr( $key ),
                esc_attr( $checked ),
                esc_html__( 'Check submissions against Akismet spam filter', 'aimf-secure-contact-form' )
            );
            if ( ! class_exists( 'Akismet' ) ) {
                echo '<p class="description" style="color: #d63638;">' . esc_html__( 'Akismet plugin is not active. Install and activate Akismet to use this feature.', 'aimf-secure-contact-form' ) . '</p>';
            } elseif ( ! method_exists( 'Akismet', 'is_api_key_configured' ) || ! Akismet::is_api_key_configured() ) {
                echo '<p class="description" style="color: #d63638;">' . esc_html__( 'Akismet is active but no API key is configured. Configure Akismet to use this feature.', 'aimf-secure-contact-form' ) . '</p>';
            }
            echo '<p class="description">' . esc_html__( 'When enabled, submissions flagged as spam by Akismet are stored with "spam" status and no notification email is sent.', 'aimf-secure-contact-form' ) . '</p>';
            return;
        }

        if ( 'data_retention_days' === $key ) {
            $val = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;
            printf(
                '<input type="number" id="%s" name="%s[%s]" value="%d" min="0" max="3650" class="small-text" />',
                esc_attr( $key ),
                esc_attr( self::OPTION_NAME ),
                esc_attr( $key ),
                esc_attr( $val )
            );
            echo '<p class="description">' . esc_html__( 'Anonymize IP addresses and delete user agents after this many days. Set to 0 to keep data forever. GDPR recommendation: 90 days.', 'aimf-secure-contact-form' ) . '</p>';
            return;
        }

        if ( 'min_submit_time' === $key ) {
            $val = empty( $value ) ? 3 : absint( $value );
            printf(
                '<input type="number" id="%s" name="%s[%s]" value="%d" min="1" max="120" class="small-text" />',
                esc_attr( $key ),
                esc_attr( self::OPTION_NAME ),
                esc_attr( $key ),
                esc_attr( $val )
            );
            echo '<p class="description">' . esc_html__( 'Reject submissions that arrive faster than this (bots submit instantly). Default: 3 seconds.', 'aimf-secure-contact-form' ) . '</p>';
            return;
        }

        $is_secret = ( false !== strpos( $key, 'secret' ) );
        $type      = $is_secret ? 'password' : 'text';

        printf(
            '<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( $type ),
            esc_attr( $key ),
            esc_attr( self::OPTION_NAME ),
            esc_attr( $key ),
            esc_attr( $value )
        );

        if ( $is_secret && ! empty( $value ) ) {
            echo '<button type="button" class="button button-secondary ascf-toggle-secret" data-target="' . esc_attr( $key ) . '">' . esc_html__( 'Show', 'aimf-secure-contact-form' ) . '</button>';
        }

        // Helpful descriptions.
        $descriptions = array(
            'turnstile_site_key'   => __( 'Cloudflare Turnstile site key. Get yours at dash.cloudflare.com (free).', 'aimf-secure-contact-form' ),
            'turnstile_secret_key' => __( 'Cloudflare Turnstile secret key.', 'aimf-secure-contact-form' ),
            'recaptcha_site_key'   => __( 'Google reCAPTCHA v3 site key. Get yours at recaptcha.google.com.', 'aimf-secure-contact-form' ),
            'recaptcha_secret_key' => __( 'Google reCAPTCHA v3 secret key.', 'aimf-secure-contact-form' ),
            'hcaptcha_site_key'    => __( 'hCaptcha site key. Get yours at dashboard.hcaptcha.com.', 'aimf-secure-contact-form' ),
            'hcaptcha_secret_key'  => __( 'hCaptcha secret key.', 'aimf-secure-contact-form' ),
            'notification_email'   => __( 'Email address to receive new submission notifications.', 'aimf-secure-contact-form' ),
        );

        if ( isset( $descriptions[ $key ] ) ) {
            echo '<p class="description">' . esc_html( $descriptions[ $key ] ) . '</p>';
        }
    }

    /**
     * Render the settings page.
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'aimf-secure-contact-form' ) );
        }
        ?>
        <div class="wrap ascf-admin-wrap">
            <div class="ascf-brand-header">
                <img src="<?php echo esc_url( AIMF_SCF_PLUGIN_URL . 'assets/aimf-logo.svg' ); ?>" alt="AIMF Security" class="ascf-brand-logo" />
                <h1><?php esc_html_e( 'AIMF Secure Forms Settings', 'aimf-secure-contact-form' ); ?></h1>
            </div>
            <form action="options.php" method="post">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::SETTINGS_SLUG );
                submit_button( __( 'Save Settings', 'aimf-secure-contact-form' ) );
                ?>
            </form>

            <hr />
            <h2><?php esc_html_e( 'Configuration Backup & Transfer', 'aimf-secure-contact-form' ); ?></h2>
            <p><?php esc_html_e( 'Exports are encrypted to the AIMF Security YubiKey PIV 9D certificate. Decrypt the file locally with the authorized YubiKey before importing it. CAPTCHA secret keys are never exported.', 'aimf-secure-contact-form' ); ?></p>

            <?php if ( isset( $_GET['ascf_import'] ) ) : ?>
                <?php $import_status = sanitize_key( wp_unslash( $_GET['ascf_import'] ) ); ?>
                <div class="notice <?php echo 'success' === $import_status ? 'notice-success' : 'notice-error'; ?> inline"><p>
                    <?php echo 'success' === $import_status ? esc_html__( 'Configuration imported successfully.', 'aimf-secure-contact-form' ) : esc_html__( 'Configuration import failed. Confirm that the file is a valid AIMF Secure Forms export.', 'aimf-secure-contact-form' ); ?>
                </p></div>
            <?php endif; ?>

            <div class="ascf-config-tools">
                <div class="ascf-config-card">
                    <h3><?php esc_html_e( 'Export Configuration', 'aimf-secure-contact-form' ); ?></h3>
                    <p><?php esc_html_e( 'Downloads an encrypted backup of all form definitions and safe plugin settings. Submissions and secret keys are excluded.', 'aimf-secure-contact-form' ); ?></p>
                    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                        <input type="hidden" name="action" value="ascf_export_configuration" />
                        <?php wp_nonce_field( 'ascf_export_configuration' ); ?>
                        <?php submit_button( __( 'Download Configuration', 'aimf-secure-contact-form' ), 'secondary', 'submit', false ); ?>
                    </form>
                </div>

                <div class="ascf-config-card">
                    <h3><?php esc_html_e( 'Import Configuration', 'aimf-secure-contact-form' ); ?></h3>
                    <p><?php esc_html_e( 'Import the JSON file produced after locally decrypting an AIMF Secure Forms export. Merge updates matching form IDs; replace removes current form definitions before importing.', 'aimf-secure-contact-form' ); ?></p>
                    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data" onsubmit="return confirm('<?php echo esc_js( __( 'Import this configuration now?', 'aimf-secure-contact-form' ) ); ?>');">
                        <input type="hidden" name="action" value="ascf_import_configuration" />
                        <?php wp_nonce_field( 'ascf_import_configuration' ); ?>
                        <p><input type="file" name="ascf_configuration" accept="application/json,.json" required /></p>
                        <p>
                            <label><input type="radio" name="import_mode" value="merge" checked /> <?php esc_html_e( 'Merge with existing forms', 'aimf-secure-contact-form' ); ?></label><br />
                            <label><input type="radio" name="import_mode" value="replace" /> <?php esc_html_e( 'Replace existing form definitions', 'aimf-secure-contact-form' ); ?></label>
                        </p>
                        <?php submit_button( __( 'Import Configuration', 'aimf-secure-contact-form' ), 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            </div>
            <script>
                document.addEventListener('click', function(e) {
                    if (e.target && e.target.classList.contains('ascf-toggle-secret')) {
                        var targetId = e.target.getAttribute('data-target');
                        var input = document.getElementById(targetId);
                        if (input) {
                            if (input.type === 'password') {
                                input.type = 'text';
                                e.target.textContent = '<?php echo esc_js( __( 'Hide', 'aimf-secure-contact-form' ) ); ?>';
                            } else {
                                input.type = 'password';
                                e.target.textContent = '<?php echo esc_js( __( 'Show', 'aimf-secure-contact-form' ) ); ?>';
                            }
                        }
                    }
                });
            </script>
        </div>
        <?php
    }

    public static function get_export_configuration() {
        $settings = get_option( self::OPTION_NAME, array() );
        $safe_settings = array_diff_key(
            is_array( $settings ) ? $settings : array(),
            array_flip( array( 'turnstile_secret_key', 'recaptcha_secret_key', 'hcaptcha_secret_key' ) )
        );

        return array(
            'format'     => 'aimf-secure-forms',
            'version'    => 1,
            'exported_at' => gmdate( 'c' ),
            'forms'      => AIMF_SCF_Forms::get_all_forms(),
            'settings'   => $safe_settings,
        );
    }

    public static function handle_export_configuration() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export this configuration.', 'aimf-secure-contact-form' ), 403 );
        }
        check_admin_referer( 'ascf_export_configuration' );

        $encrypted = AIMF_SCF_Encryption::encrypt_export( self::get_export_configuration(), 'configuration' );
        if ( is_wp_error( $encrypted ) ) {
            wp_die( esc_html( $encrypted->get_error_message() ), 500 );
        }

        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="aimf-secure-forms-' . gmdate( 'Y-m-d' ) . '.ascfenc"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $encrypted;
        exit;
    }

    public static function handle_export_submissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export submissions.', 'aimf-secure-contact-form' ), 403 );
        }
        check_admin_referer( 'ascf_export_submissions' );

        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;
        $submission_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
        if ( $submission_count > 5000 ) {
            wp_die( esc_html__( 'Encrypted export is limited to 5,000 submissions at a time. Reduce retained data before exporting.', 'aimf-secure-contact-form' ), 400 );
        }

        $submissions = $wpdb->get_results(
            "SELECT id, form_id, created_at, name, email, service, message, fields_data, ip_address, user_agent, captcha_score, status FROM {$table_name} ORDER BY id ASC",
            ARRAY_A
        );
        $forms = AIMF_SCF_Forms::get_all_forms();
        $form_titles = array();
        foreach ( $forms as $form ) {
            $form_titles[ (int) $form['id'] ] = $form['title'];
        }

        $payload = array(
            'format'      => 'aimf-secure-forms-submissions',
            'version'     => 1,
            'exported_at' => gmdate( 'c' ),
            'site'        => home_url( '/' ),
            'forms'       => $form_titles,
            'submissions' => is_array( $submissions ) ? $submissions : array(),
        );
        $encrypted = AIMF_SCF_Encryption::encrypt_export( $payload, 'submissions' );
        if ( is_wp_error( $encrypted ) ) {
            wp_die( esc_html( $encrypted->get_error_message() ), 500 );
        }

        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="aimf-secure-form-submissions-' . gmdate( 'Y-m-d' ) . '.ascfenc"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $encrypted;
        exit;
    }

    public static function import_configuration_data( $configuration, $mode = 'merge' ) {
        if ( ! is_array( $configuration )
            || ! isset( $configuration['format'], $configuration['version'], $configuration['forms'], $configuration['settings'] )
            || 'aimf-secure-forms' !== $configuration['format']
            || 1 !== absint( $configuration['version'] )
            || ! is_array( $configuration['forms'] )
            || ! is_array( $configuration['settings'] )
            || count( $configuration['forms'] ) > 100 ) {
            return new WP_Error( 'invalid_configuration', __( 'Invalid AIMF Secure Forms configuration.', 'aimf-secure-contact-form' ) );
        }

        foreach ( $configuration['forms'] as $form ) {
            if ( ! is_array( $form ) || empty( $form['id'] ) || ! isset( $form['title'], $form['fields'] ) || ! is_array( $form['fields'] ) || count( $form['fields'] ) > 100 ) {
                return new WP_Error( 'invalid_form', __( 'The configuration contains an invalid form definition.', 'aimf-secure-contact-form' ) );
            }
        }

        $allowed_settings = array(
            'turnstile_site_key',
            'recaptcha_site_key',
            'hcaptcha_site_key',
            'enable_akismet',
            'min_submit_time',
            'data_retention_days',
            'notification_email',
        );
        $imported_settings = array_intersect_key( $configuration['settings'], array_flip( $allowed_settings ) );
        $existing_settings = get_option( self::OPTION_NAME, array() );
        $existing_settings = is_array( $existing_settings ) ? $existing_settings : array();
        $merged_settings   = array_merge( $existing_settings, $imported_settings );
        $clean_settings    = self::sanitize_settings( $merged_settings );

        if ( 'replace' === $mode ) {
            update_option( AIMF_SCF_Forms::OPTION_KEY, array(), false );
        }
        foreach ( $configuration['forms'] as $form ) {
            AIMF_SCF_Forms::save_form( $form );
        }
        AIMF_SCF_Forms::migrate_default_form();
        update_option( self::OPTION_NAME, $clean_settings, false );

        return true;
    }

    public static function handle_import_configuration() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to import configuration.', 'aimf-secure-contact-form' ), 403 );
        }
        check_admin_referer( 'ascf_import_configuration' );

        $redirect = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
        if ( empty( $_FILES['ascf_configuration']['tmp_name'] ) || ! isset( $_FILES['ascf_configuration']['name'], $_FILES['ascf_configuration']['size'] ) ) {
            wp_safe_redirect( add_query_arg( 'ascf_import', 'error', $redirect ) );
            exit;
        }

        $file_name = sanitize_file_name( wp_unslash( $_FILES['ascf_configuration']['name'] ) );
        $file_size = absint( $_FILES['ascf_configuration']['size'] );
        $tmp_name  = $_FILES['ascf_configuration']['tmp_name'];
        if ( 'json' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) || 0 === $file_size || $file_size > MB_IN_BYTES || ! is_uploaded_file( $tmp_name ) ) {
            wp_safe_redirect( add_query_arg( 'ascf_import', 'error', $redirect ) );
            exit;
        }

        $json = file_get_contents( $tmp_name );
        $configuration = json_decode( $json, true, 32 );
        $mode = isset( $_POST['import_mode'] ) && 'replace' === sanitize_key( wp_unslash( $_POST['import_mode'] ) ) ? 'replace' : 'merge';
        $result = self::import_configuration_data( $configuration, $mode );

        wp_safe_redirect( add_query_arg( 'ascf_import', is_wp_error( $result ) ? 'error' : 'success', $redirect ) );
        exit;
    }

    /**
     * Render the submissions list page or a single submission view.
     */
    public static function render_submissions_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'aimf-secure-contact-form' ) );
        }

        $action = isset( $_GET['ascf_action'] ) ? sanitize_text_field( wp_unslash( $_GET['ascf_action'] ) ) : '';

        if ( 'view' === $action ) {
            self::render_single_submission();
        } else {
            self::render_submissions_list();
        }
    }

    /**
     * Submissions per page in the admin list.
     */
    const PER_PAGE = 25;

    /**
     * Render the submissions list table.
     */
    private static function render_submissions_list() {
        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;

        // Handle status filter.
        $status_filter = isset( $_GET['ascf_status'] ) ? sanitize_text_field( wp_unslash( $_GET['ascf_status'] ) ) : '';
        $valid_statuses = array( 'new', 'read', 'replied', 'deleted', 'spam' );

        $where = '';
        $params = array();
        if ( in_array( $status_filter, $valid_statuses, true ) ) {
            $where = ' WHERE status = %s';
            $params[] = $status_filter;
        }

        // Count for current filter (for pagination).
        $count_query = "SELECT COUNT(*) FROM {$table_name}{$where}";
        if ( ! empty( $params ) ) {
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( $count_query, $params ) );
        } else {
            $total_items = (int) $wpdb->get_var( $count_query );
        }

        // Pagination.
        $current_page = isset( $_GET['ascf_paged'] ) ? max( 1, absint( $_GET['ascf_paged'] ) ) : 1;
        $total_pages  = max( 1, (int) ceil( $total_items / self::PER_PAGE ) );
        if ( $current_page > $total_pages ) {
            $current_page = $total_pages;
        }
        $offset = ( $current_page - 1 ) * self::PER_PAGE;

        // Fetch one page of results.
        $query = "SELECT * FROM {$table_name}{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        if ( ! empty( $params ) ) {
            $params[] = self::PER_PAGE;
            $params[] = $offset;
            $query = $wpdb->prepare( $query, $params );
        } else {
            $query = $wpdb->prepare( $query, self::PER_PAGE, $offset );
        }

        $submissions = $wpdb->get_results( $query );

        // Count by status for filter tabs.
        $counts = array(
            'all'      => 0,
            'new'      => 0,
            'read'     => 0,
            'replied'  => 0,
            'deleted'  => 0,
            'spam'     => 0,
        );
        $all_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
        $counts['all'] = (int) $all_count;

        foreach ( $valid_statuses as $st ) {
            $counts[ $st ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE status = %s", $st ) );
        }

        $base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        ?>
        <div class="wrap ascf-admin-wrap">
            <div class="ascf-brand-header">
                <img src="<?php echo esc_url( AIMF_SCF_PLUGIN_URL . 'assets/aimf-logo.svg' ); ?>" alt="AIMF Security" class="ascf-brand-logo" />
                <h1><?php esc_html_e( 'Form Submissions', 'aimf-secure-contact-form' ); ?></h1>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="ascf-export-submissions-form">
                    <input type="hidden" name="action" value="ascf_export_submissions" />
                    <?php wp_nonce_field( 'ascf_export_submissions' ); ?>
                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Download Encrypted Export', 'aimf-secure-contact-form' ); ?></button>
                </form>
            </div>

            <ul class="subsubsub ascf-status-filters">
                <li>
                    <a href="<?php echo esc_url( $base_url ); ?>" <?php echo ( empty( $status_filter ) ) ? 'class="current"' : ''; ?>>
                        <?php esc_html_e( 'All', 'aimf-secure-contact-form' ); ?>
                        <span class="count">(<?php echo esc_html( $counts['all'] ); ?>)</span>
                    </a>
                </li>
                <?php foreach ( $valid_statuses as $st ) : ?>
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( 'ascf_status', $st, $base_url ) ); ?>" <?php echo ( $status_filter === $st ) ? 'class="current"' : ''; ?>>
                            <?php echo esc_html( ucfirst( $st ) ); ?>
                            <span class="count">(<?php echo esc_html( $counts[ $st ] ); ?>)</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( empty( $submissions ) ) : ?>
                <p><?php esc_html_e( 'No submissions found.', 'aimf-secure-contact-form' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped ascf-submissions-table">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'aimf-secure-contact-form' ); ?></th>
                            <th scope="col" class="manage-column column-name"><?php esc_html_e( 'Name', 'aimf-secure-contact-form' ); ?></th>
                            <th scope="col" class="manage-column column-email"><?php esc_html_e( 'Email', 'aimf-secure-contact-form' ); ?></th>
                            <th scope="col" class="manage-column column-service"><?php esc_html_e( 'Service', 'aimf-secure-contact-form' ); ?></th>
                            <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'aimf-secure-contact-form' ); ?></th>
                            <th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'aimf-secure-contact-form' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $submissions as $submission ) : ?>
                            <?php
                            $view_url   = add_query_arg( array(
                                'page'        => self::PAGE_SLUG,
                                'ascf_action' => 'view',
                                'ascf_id'     => $submission->id,
                            ), admin_url( 'admin.php' ) );
                            $delete_url = add_query_arg( array(
                                'action'  => 'ascf_delete_submission',
                                'ascf_id' => $submission->id,
                                'ascf_nonce' => wp_create_nonce( 'ascf_delete_' . $submission->id ),
                            ), admin_url( 'admin-post.php' ) );
                            $status_class = 'ascf-status-' . esc_attr( $submission->status );
                            ?>
                            <tr class="<?php echo esc_attr( $status_class ); ?>">
                                <td class="column-date">
                                    <a href="<?php echo esc_url( $view_url ); ?>">
                                        <?php echo esc_html( mysql2date( 'M j, Y g:i a', $submission->created_at ) ); ?>
                                    </a>
                                </td>
                                <td class="column-name">
                                    <a href="<?php echo esc_url( $view_url ); ?>">
                                        <?php echo esc_html( $submission->name ); ?>
                                    </a>
                                </td>
                                <td class="column-email"><?php echo esc_html( $submission->email ); ?></td>
                                <td class="column-service"><?php echo esc_html( $submission->service ); ?></td>
                                <td class="column-status">
                                    <span class="ascf-badge ascf-badge-<?php echo esc_attr( $submission->status ); ?>">
                                        <?php echo esc_html( ucfirst( $submission->status ) ); ?>
                                    </span>
                                </td>
                                <td class="column-actions">
                                    <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'aimf-secure-contact-form' ); ?></a>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small ascf-delete-btn" onclick="return confirm('<?php echo esc_js( __( 'Delete this submission permanently?', 'aimf-secure-contact-form' ) ); ?>');"><?php esc_html_e( 'Delete', 'aimf-secure-contact-form' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php
                            printf(
                                /* translators: %s: number of items */
                                esc_html( _n( '%s item', '%s items', $total_items, 'aimf-secure-contact-form' ) ),
                                esc_html( number_format_i18n( $total_items ) )
                            );
                            ?>
                        </span>
                        <span class="pagination-links">
                            <?php
                            $page_links = paginate_links( array(
                                'base'      => add_query_arg( 'ascf_paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $current_page,
                                'type'      => 'plain',
                            ) );
                            echo wp_kses_post( $page_links );
                            ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single submission detail view.
     */
    private static function render_single_submission() {
        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;

        $submission_id = isset( $_GET['ascf_id'] ) ? absint( $_GET['ascf_id'] ) : 0;
        if ( ! $submission_id ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Invalid submission ID.', 'aimf-secure-contact-form' ) . '</p></div>';
            return;
        }

        $submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $submission_id ) );

        if ( ! $submission ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Submission not found.', 'aimf-secure-contact-form' ) . '</p></div>';
            return;
        }

        // Mark as read if currently "new".
        if ( 'new' === $submission->status ) {
            $wpdb->update(
                $table_name,
                array( 'status' => 'read' ),
                array( 'id' => $submission_id ),
                array( '%s' ),
                array( '%d' )
            );
            $submission->status = 'read';
        }

        $back_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $delete_url   = add_query_arg( array(
            'action'    => 'ascf_delete_submission',
            'ascf_id'   => $submission_id,
            'ascf_nonce' => wp_create_nonce( 'ascf_delete_' . $submission_id ),
        ), admin_url( 'admin-post.php' ) );
        $replied_url  = add_query_arg( array(
            'action'    => 'ascf_update_status',
            'ascf_id'   => $submission_id,
            'ascf_status' => 'replied',
            'ascf_nonce' => wp_create_nonce( 'ascf_status_' . $submission_id ),
        ), admin_url( 'admin-post.php' ) );
        $not_spam_url = add_query_arg( array(
            'action'    => 'ascf_update_status',
            'ascf_id'   => $submission_id,
            'ascf_status' => 'read',
            'ascf_nonce' => wp_create_nonce( 'ascf_status_' . $submission_id ),
        ), admin_url( 'admin-post.php' ) );
        $mail_to_url  = 'mailto:' . rawurlencode( $submission->email ) . '?subject=' . rawurlencode( 'RE: ' . $submission->service );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'View Submission', 'aimf-secure-contact-form' ); ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'aimf-secure-contact-form' ); ?></a>
            </h1>

            <div class="ascf-submission-detail">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Date', 'aimf-secure-contact-form' ); ?></th>
                        <td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $submission->created_at ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Name', 'aimf-secure-contact-form' ); ?></th>
                        <td><?php echo esc_html( $submission->name ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Email', 'aimf-secure-contact-form' ); ?></th>
                        <td><a href="<?php echo esc_url( $mail_to_url ); ?>"><?php echo esc_html( $submission->email ); ?></a></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Service', 'aimf-secure-contact-form' ); ?></th>
                        <td><?php echo esc_html( $submission->service ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Status', 'aimf-secure-contact-form' ); ?></th>
                        <td>
                            <span class="ascf-badge ascf-badge-<?php echo esc_attr( $submission->status ); ?>">
                                <?php echo esc_html( ucfirst( $submission->status ) ); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Message', 'aimf-secure-contact-form' ); ?></th>
                        <td><div class="ascf-message-content"><?php echo nl2br( esc_html( $submission->message ) ); ?></div></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'IP Address', 'aimf-secure-contact-form' ); ?></th>
                        <td><?php echo esc_html( $submission->ip_address ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'User Agent', 'aimf-secure-contact-form' ); ?></th>
                        <td><?php echo esc_html( $submission->user_agent ); ?></td>
                    </tr>
                    <?php if ( null !== $submission->captcha_score ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'CAPTCHA Score', 'aimf-secure-contact-form' ); ?></th>
                        <td><?php echo esc_html( number_format( (float) $submission->captcha_score, 2 ) ); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <p class="ascf-detail-actions">
                    <a href="<?php echo esc_url( $mail_to_url ); ?>" class="button button-primary"><?php esc_html_e( 'Reply by Email', 'aimf-secure-contact-form' ); ?></a>
                    <a href="<?php echo esc_url( $replied_url ); ?>" class="button"><?php esc_html_e( 'Mark as Replied', 'aimf-secure-contact-form' ); ?></a>
                    <?php if ( 'spam' === $submission->status ) : ?>
                    <a href="<?php echo esc_url( $not_spam_url ); ?>" class="button button-primary"><?php esc_html_e( 'Not Spam', 'aimf-secure-contact-form' ); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete ascf-delete-btn" onclick="return confirm('<?php echo esc_js( __( 'Delete this submission permanently?', 'aimf-secure-contact-form' ) ); ?>');"><?php esc_html_e( 'Delete', 'aimf-secure-contact-form' ); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Handle the delete submission action.
     */
    public static function handle_delete_submission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'aimf-secure-contact-form' ) );
        }

        $submission_id = isset( $_GET['ascf_id'] ) ? absint( $_GET['ascf_id'] ) : 0;
        $nonce         = isset( $_GET['ascf_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['ascf_nonce'] ) ) : '';

        if ( ! $submission_id || ! wp_verify_nonce( $nonce, 'ascf_delete_' . $submission_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'aimf-secure-contact-form' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;

        $wpdb->delete( $table_name, array( 'id' => $submission_id ), array( '%d' ) );

        $redirect = add_query_arg(
            array(
                'page'        => self::PAGE_SLUG,
                'ascf_notice' => 'deleted',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Handle the update status action.
     */
    public static function handle_update_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'aimf-secure-contact-form' ) );
        }

        $submission_id = isset( $_GET['ascf_id'] ) ? absint( $_GET['ascf_id'] ) : 0;
        $new_status    = isset( $_GET['ascf_status'] ) ? sanitize_text_field( wp_unslash( $_GET['ascf_status'] ) ) : '';
        $nonce         = isset( $_GET['ascf_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['ascf_nonce'] ) ) : '';

        $valid_statuses = array( 'new', 'read', 'replied', 'deleted', 'spam' );

        if ( ! $submission_id || ! wp_verify_nonce( $nonce, 'ascf_status_' . $submission_id ) || ! in_array( $new_status, $valid_statuses, true ) ) {
            wp_die( esc_html__( 'Security check failed.', 'aimf-secure-contact-form' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;

        $wpdb->update(
            $table_name,
            array( 'status' => $new_status ),
            array( 'id' => $submission_id ),
            array( '%s' ),
            array( '%d' )
        );

        $redirect = add_query_arg(
            array(
                'page'        => self::PAGE_SLUG,
                'ascf_action' => 'view',
                'ascf_id'     => $submission_id,
                'ascf_notice' => 'status_updated',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * GDPR: Run data retention cleanup.
     *
     * Anonymizes IP addresses and clears user agents on submissions older
     * than the configured retention period. Called daily via WP-Cron.
     */
    public static function run_data_retention_cleanup() {
        $settings  = get_option( self::OPTION_NAME, array() );
        $retention = isset( $settings['data_retention_days'] ) ? absint( $settings['data_retention_days'] ) : 0;

        // 0 means keep forever.
        if ( 0 === $retention ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;
        $cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );

        // Anonymize IP and clear user agent on old rows that haven't been anonymized yet.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name} SET ip_address = '0.0.0.0', user_agent = '' WHERE created_at < %s AND ip_address != '0.0.0.0'",
                $cutoff
            )
        );
    }

    /**
     * GDPR: Register the personal data exporter.
     *
     * @param array $exporters Existing exporters.
     * @return array
     */
    public static function register_data_exporter( $exporters ) {
        $exporters['aimf-secure-contact-form'] = array(
            'exporter_friendly_name' => __( 'AIMF Secure Forms Submissions', 'aimf-secure-contact-form' ),
            'callback'               => array( __CLASS__, 'export_personal_data' ),
        );
        return $exporters;
    }

    /**
     * GDPR: Register the personal data eraser.
     *
     * @param array $erasers Existing erasers.
     * @return array
     */
    public static function register_data_eraser( $erasers ) {
        $erasers['aimf-secure-contact-form'] = array(
            'eraser_friendly_name' => __( 'AIMF Secure Forms Submissions', 'aimf-secure-contact-form' ),
            'callback'             => array( __CLASS__, 'erase_personal_data' ),
        );
        return $erasers;
    }

    /**
     * GDPR: Export personal data for a given email address.
     *
     * @param string $email_address The email to export data for.
     * @param int    $page          Page number for batching.
     * @return array
     */
    public static function export_personal_data( $email_address, $page = 1 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;
        $per_page   = 100;
        $offset     = ( $page - 1 ) * $per_page;

        $submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE email = %s LIMIT %d OFFSET %d",
                $email_address,
                $per_page,
                $offset
            )
        );

        $export_items = array();
        foreach ( $submissions as $submission ) {
            $data = array(
                array( 'name' => __( 'Date', 'aimf-secure-contact-form' ), 'value' => $submission->created_at ),
                array( 'name' => __( 'Name', 'aimf-secure-contact-form' ), 'value' => $submission->name ),
                array( 'name' => __( 'Email', 'aimf-secure-contact-form' ), 'value' => $submission->email ),
                array( 'name' => __( 'Service', 'aimf-secure-contact-form' ), 'value' => $submission->service ),
                array( 'name' => __( 'Message', 'aimf-secure-contact-form' ), 'value' => $submission->message ),
                array( 'name' => __( 'IP Address', 'aimf-secure-contact-form' ), 'value' => $submission->ip_address ),
            );

            $export_items[] = array(
                'group_id'    => 'aimf-contact-form',
                'group_label' => __( 'Form Submissions', 'aimf-secure-contact-form' ),
                'item_id'     => 'ascf-submission-' . $submission->id,
                'data'        => $data,
            );
        }

        return array(
            'data' => $export_items,
            'done' => count( $submissions ) < $per_page,
        );
    }

    /**
     * GDPR: Erase personal data for a given email address.
     *
     * Anonymizes the submissions (removes name, email, IP, user agent)
     * rather than deleting them, so the admin retains the message record.
     *
     * @param string $email_address The email to erase data for.
     * @param int    $page          Page number for batching.
     * @return array
     */
    public static function erase_personal_data( $email_address, $page = 1 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . AIMF_SCF_TABLE_NAME;

        $count = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name} SET name = '[erased]', email = '[erased]', ip_address = '0.0.0.0', user_agent = '' WHERE email = %s",
                $email_address
            )
        );

        return array(
            'items_removed'  => (int) $count,
            'items_retained' => 0,
            'messages'       => array(),
            'done'           => true,
        );
    }
}
