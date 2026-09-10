<?php
/**
 * Frontend form renderer.
 *
 * Renders the [aimf_contact_form] and [aimf_form id="X"] shortcodes.
 * Dynamic forms are loaded from the ASCF_Forms options.
 * All security layers (nonce, honeypot, JS token, signed timestamp, CAPTCHA)
 * are preserved for both legacy and dynamic forms.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AIMF_SCF_Form
 */
class AIMF_SCF_Form {

    /**
     * Available service options for the legacy hardcoded form (fallback).
     *
     * @var array
     */
    private static $services = array(
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

    /**
     * Initialize — register the dynamic form shortcode.
     * Called from AIMF_SCF_Admin::init() since the bootstrap is locked.
     */
    public static function init() {
        add_shortcode( 'aimf_form', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
    }

    /**
     * Enqueue public assets when either shortcode is present.
     * The bootstrap only checks for [aimf_contact_form]; this covers [aimf_form].
     */
    public static function maybe_enqueue_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }
        if ( has_shortcode( $post->post_content, 'aimf_contact_form' ) ) {
            return; // Bootstrap already handles this.
        }
        if ( ! has_shortcode( $post->post_content, 'aimf_form' ) ) {
            return;
        }

        wp_enqueue_style(
            'aimf-scf-public',
            AIMF_SCF_PLUGIN_URL . 'assets/ascf-public.css',
            array(),
            AIMF_SCF_VERSION
        );
        wp_enqueue_script(
            'aimf-scf-public',
            AIMF_SCF_PLUGIN_URL . 'assets/ascf-public.js',
            array(),
            AIMF_SCF_VERSION,
            true
        );
    }

    /**
     * Render the contact form shortcode.
     *
     * Supports both [aimf_contact_form] (legacy, renders form ID 1) and
     * [aimf_form id="X"] (dynamic, renders the specified form).
     *
     * @param array $atts Shortcode attributes. 'id' => form ID (default: 1).
     * @return string The form HTML.
     */
    public static function render( $atts = array() ) {
        $form_id = isset( $atts['id'] ) ? absint( $atts['id'] ) : 1;

        // Try to load the dynamic form definition.
        $form = AIMF_SCF_Forms::get_form( $form_id );

        if ( $form && ! empty( $form['fields'] ) ) {
            return self::render_dynamic_form( $form );
        }

        // Fallback to the legacy hardcoded form (for form ID 1 before migration).
        if ( 1 === $form_id ) {
            return self::render_legacy_form();
        }

        // Form not found.
        return '<p class="ascf-form-error">' . esc_html__( 'Form not found. Please check the form ID.', 'aimf-secure-contact-form' ) . '</p>';
    }

    /**
     * Render a dynamic form from a form definition.
     *
     * @param array $form Form definition from AIMF_SCF_Forms.
     * @return string Form HTML.
     */
    private static function render_dynamic_form( $form ) {
        $nonce          = wp_create_nonce( AIMF_SCF_Handler::NONCE_ACTION );
        $ajax_url       = admin_url( 'admin-ajax.php' );
        $recaptcha_key  = AIMF_SCF_Captcha::get_recaptcha_site_key();
        $hcaptcha_key   = AIMF_SCF_Captcha::get_hcaptcha_site_key();
        $turnstile_key  = AIMF_SCF_Captcha::get_turnstile_site_key();
        $use_recaptcha  = AIMF_SCF_Captcha::is_recaptcha_configured();
        $use_hcaptcha   = AIMF_SCF_Captcha::is_hcaptcha_configured();
        $use_turnstile   = AIMF_SCF_Captcha::is_turnstile_configured();

        // Server-signed timestamp.
        $render_time = (string) time();
        $render_sig  = wp_hash( $render_time . 'ascf_render_time' );

        // Form settings.
        $settings      = isset( $form['settings'] ) ? $form['settings'] : AIMF_SCF_Forms::get_default_settings();
        $submit_text   = isset( $settings['submit_button_text'] ) && $settings['submit_button_text'] ? $settings['submit_button_text'] : __( 'Send Message', 'aimf-secure-contact-form' );

        ob_start();
        ?>
        <div class="ascf-form-wrapper" data-form-id="<?php echo esc_attr( $form['id'] ); ?>">
            <form id="ascf-contact-form" class="ascf-contact-form" method="post" novalidate>
                <!-- Honeypot (hidden from humans) -->
                <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                    <label for="ascf-company"><?php esc_html_e( 'Company (leave empty)', 'aimf-secure-contact-form' ); ?></label>
                    <input type="text" name="ascf_company" id="ascf-company" tabindex="-1" autocomplete="off" />
                </div>

                <!-- Hidden fields -->
                <input type="hidden" name="action" value="ascf_submit_form" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
                <input type="hidden" name="ascf_captcha_token" id="ascf-captcha-token" value="" />
                <input type="hidden" name="ascf_js_token" id="ascf-js-token" value="" />
                <input type="hidden" name="ascf_form_loaded" id="ascf-form-loaded" value="<?php echo esc_attr( $render_time . '|' . $render_sig ); ?>" />
                <input type="hidden" name="ascf_form_id" value="<?php echo esc_attr( $form['id'] ); ?>" />

                <?php
                // Render each field from the form definition.
                foreach ( $form['fields'] as $field ) {
                    AIMF_SCF_Fields::render_field( $field, $form['id'] );
                }
                ?>

                <?php if ( $use_turnstile ) : ?>
                <!-- Cloudflare Turnstile widget -->
                <div class="ascf-field">
                    <div id="ascf-turnstile-container"></div>
                </div>
                <?php endif; ?>

                <!-- Submit -->
                <div class="ascf-field ascf-submit-row">
                    <button type="submit" id="ascf-submit-btn" class="ascf-submit-btn">
                        <span class="ascf-submit-text"><?php echo esc_html( $submit_text ); ?></span>
                        <span class="ascf-submit-spinner" aria-hidden="true" style="display:none;"></span>
                    </button>
                </div>

                <!-- Response area -->
                <div id="ascf-response" class="ascf-response" role="alert" aria-live="polite"></div>
            </form>
        </div>

        <?php
        // Output inline configuration for the public JS.
        $config = array(
            'ajaxUrl'          => $ajax_url,
            'nonce'            => $nonce,
            'formId'           => $form['id'],
            'recaptchaSiteKey' => $use_recaptcha ? $recaptcha_key : '',
            'hcaptchaSiteKey'  => $use_hcaptcha ? $hcaptcha_key : '',
            'turnstileSiteKey' => $use_turnstile ? $turnstile_key : '',
            'turnstileAction'  => AIMF_SCF_Captcha::TURNSTILE_ACTION,
            'hasRecaptcha'     => $use_recaptcha,
            'hasHcaptcha'      => $use_hcaptcha,
            'hasTurnstile'     => $use_turnstile,
        );
        ?>
        <script type="text/javascript" data-noptimize="1" data-cfasync="false">
            window.ascf_config = <?php echo wp_json_encode( $config ); ?>;
        </script>

        <?php
        // Enqueue CAPTCHA scripts if configured.
        if ( $use_turnstile ) :
            ?>
            <script src="<?php echo esc_url( 'https://challenges.cloudflare.com/turnstile/v0/api.js' ); ?>" data-noptimize="1" data-cfasync="false" async defer></script>
            <?php
        endif;

        if ( $use_recaptcha ) :
            ?>
            <script src="<?php echo esc_url( 'https://www.google.com/recaptcha/api.js?render=' . $recaptcha_key ); ?>" async defer></script>
            <?php
        endif;

        if ( $use_hcaptcha ) :
            ?>
            <script src="<?php echo esc_url( 'https://js.hcaptcha.com/1/api.js' ); ?>" async defer></script>
            <?php
        endif;

        return ob_get_clean();
    }

    /**
     * Render the legacy hardcoded contact form (fallback for form ID 1).
     *
     * @return string Form HTML.
     */
    private static function render_legacy_form() {
        $nonce          = wp_create_nonce( AIMF_SCF_Handler::NONCE_ACTION );
        $ajax_url       = admin_url( 'admin-ajax.php' );
        $recaptcha_key  = AIMF_SCF_Captcha::get_recaptcha_site_key();
        $hcaptcha_key   = AIMF_SCF_Captcha::get_hcaptcha_site_key();
        $turnstile_key  = AIMF_SCF_Captcha::get_turnstile_site_key();
        $use_recaptcha  = AIMF_SCF_Captcha::is_recaptcha_configured();
        $use_hcaptcha   = AIMF_SCF_Captcha::is_hcaptcha_configured();
        $use_turnstile   = AIMF_SCF_Captcha::is_turnstile_configured();

        $render_time = (string) time();
        $render_sig  = wp_hash( $render_time . 'ascf_render_time' );

        ob_start();
        ?>
        <div class="ascf-form-wrapper" data-form-id="1">
            <form id="ascf-contact-form" class="ascf-contact-form" method="post" novalidate>
                <!-- Honeypot (hidden from humans) -->
                <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                    <label for="ascf-company"><?php esc_html_e( 'Company (leave empty)', 'aimf-secure-contact-form' ); ?></label>
                    <input type="text" name="ascf_company" id="ascf-company" tabindex="-1" autocomplete="off" />
                </div>

                <!-- Hidden fields -->
                <input type="hidden" name="action" value="ascf_submit_form" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
                <input type="hidden" name="ascf_captcha_token" id="ascf-captcha-token" value="" />
                <input type="hidden" name="ascf_js_token" id="ascf-js-token" value="" />
                <input type="hidden" name="ascf_form_loaded" id="ascf-form-loaded" value="<?php echo esc_attr( $render_time . '|' . $render_sig ); ?>" />
                <input type="hidden" name="ascf_form_id" value="1" />

                <!-- Name -->
                <div class="ascf-field">
                    <label for="ascf-name"><?php esc_html_e( 'Name', 'aimf-secure-contact-form' ); ?> <span class="ascf-required">*</span></label>
                    <input type="text" id="ascf-name" name="ascf_name" class="ascf-input" placeholder="<?php esc_attr_e( 'Your name', 'aimf-secure-contact-form' ); ?>" required maxlength="100" />
                </div>

                <!-- Email -->
                <div class="ascf-field">
                    <label for="ascf-email"><?php esc_html_e( 'Email', 'aimf-secure-contact-form' ); ?> <span class="ascf-required">*</span></label>
                    <input type="email" id="ascf-email" name="ascf_email" class="ascf-input" placeholder="<?php esc_attr_e( 'your@email.com', 'aimf-secure-contact-form' ); ?>" required />
                </div>

                <!-- Service dropdown -->
                <div class="ascf-field">
                    <label for="ascf-service"><?php esc_html_e( "I'm interested in...", 'aimf-secure-contact-form' ); ?> <span class="ascf-required">*</span></label>
                    <select id="ascf-service" name="ascf_service" class="ascf-select" required>
                        <option value=""><?php esc_html_e( 'Select a service...', 'aimf-secure-contact-form' ); ?></option>
                        <?php foreach ( self::$services as $service ) : ?>
                            <option value="<?php echo esc_attr( $service ); ?>"><?php echo esc_html( $service ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Message -->
                <div class="ascf-field">
                    <label for="ascf-message"><?php esc_html_e( 'Message', 'aimf-secure-contact-form' ); ?> <span class="ascf-required">*</span></label>
                    <textarea id="ascf-message" name="ascf_message" class="ascf-textarea" rows="5" placeholder="<?php esc_attr_e( 'Tell me about your project or question...', 'aimf-secure-contact-form' ); ?>" required maxlength="5000"></textarea>
                </div>

                <?php if ( $use_turnstile ) : ?>
                <!-- Cloudflare Turnstile widget -->
                <div class="ascf-field">
                    <div id="ascf-turnstile-container"></div>
                </div>
                <?php endif; ?>

                <!-- Submit -->
                <div class="ascf-field ascf-submit-row">
                    <button type="submit" id="ascf-submit-btn" class="ascf-submit-btn">
                        <span class="ascf-submit-text"><?php esc_html_e( 'Send Message', 'aimf-secure-contact-form' ); ?></span>
                        <span class="ascf-submit-spinner" aria-hidden="true" style="display:none;"></span>
                    </button>
                </div>

                <!-- Response area -->
                <div id="ascf-response" class="ascf-response" role="alert" aria-live="polite"></div>
            </form>
        </div>

        <?php
        $config = array(
            'ajaxUrl'          => $ajax_url,
            'nonce'            => $nonce,
            'formId'           => 1,
            'recaptchaSiteKey' => $use_recaptcha ? $recaptcha_key : '',
            'hcaptchaSiteKey'  => $use_hcaptcha ? $hcaptcha_key : '',
            'turnstileSiteKey' => $use_turnstile ? $turnstile_key : '',
            'turnstileAction'  => AIMF_SCF_Captcha::TURNSTILE_ACTION,
            'hasRecaptcha'     => $use_recaptcha,
            'hasHcaptcha'      => $use_hcaptcha,
            'hasTurnstile'     => $use_turnstile,
        );
        ?>
        <script type="text/javascript" data-noptimize="1" data-cfasync="false">
            window.ascf_config = <?php echo wp_json_encode( $config ); ?>;
        </script>

        <?php
        if ( $use_turnstile ) :
            ?>
            <script src="<?php echo esc_url( 'https://challenges.cloudflare.com/turnstile/v0/api.js' ); ?>" data-noptimize="1" data-cfasync="false" async defer></script>
            <?php
        endif;

        if ( $use_recaptcha ) :
            ?>
            <script src="<?php echo esc_url( 'https://www.google.com/recaptcha/api.js?render=' . $recaptcha_key ); ?>" async defer></script>
            <?php
        endif;

        if ( $use_hcaptcha ) :
            ?>
            <script src="<?php echo esc_url( 'https://js.hcaptcha.com/1/api.js' ); ?>" async defer></script>
            <?php
        endif;

        return ob_get_clean();
    }
}
