<?php
/**
 * Plugin Name: AIMF Secure Forms
 * Plugin URI: https://aimfsecurity.com
 * Description: A lightweight, security-hardened form builder with CSRF protection, honeypot, rate limiting, CAPTCHA support, anti-replay tokens, and admin submissions.
 * Version: 1.1.1
 * Author: AIMF Security
 * Author URI: https://aimfsecurity.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aimf-secure-contact-form
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Constants.
define( 'AIMF_SCF_VERSION', '1.1.1' );
define( 'AIMF_SCF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIMF_SCF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIMF_SCF_TABLE_NAME', 'aimf_secure_contact_submissions' );

/**
 * Class AIMF_Secure_Contact_Form
 *
 * Main plugin class.
 */
class AIMF_Secure_Contact_Form {

    /**
     * Plugin instance.
     *
     * @var AIMF_Secure_Contact_Form|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return AIMF_Secure_Contact_Form
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->includes();
        $this->init();
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-captcha.php';
        require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-handler.php';
        require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-form.php';
        require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-admin.php';
        require_once AIMF_SCF_PLUGIN_DIR . 'includes/class-ascf-updater.php';
    }

    /**
     * Initialize hooks and classes.
     */
    private function init() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'init', array( $this, 'register_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_assets' ) );

        AIMF_SCF_Handler::init();
        AIMF_SCF_Admin::init();
        AIMF_SCF_Updater::init();
    }

    /**
     * Register the shortcode.
     */
    public function register_shortcode() {
        add_shortcode( 'aimf_contact_form', array( 'AIMF_SCF_Form', 'render' ) );
    }

    /**
     * Enqueue public assets.
     */
    public function enqueue_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'aimf_contact_form' ) ) {
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
     * Enqueue admin assets.
     *
     * @param string $hook The current admin page.
     */
    public function admin_enqueue_assets( $hook ) {
        if ( 'toplevel_page_aimf-scf' !== $hook ) {
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
     * Plugin activation: create custom table.
     */
    public function activate() {
        global $wpdb;
        $table_name      = $wpdb->prefix . AIMF_SCF_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            service varchar(255) NOT NULL,
            message longtext NOT NULL,
            ip_address varchar(100) NOT NULL,
            user_agent text NOT NULL,
            captcha_score decimal(4,2) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        set_transient( 'aimf_scf_activated', true, 60 );
    }

    /**
     * Plugin deactivation: clean up transient.
     */
    public function deactivate() {
        delete_transient( 'aimf_scf_activated' );
    }
}

// Initialize.
AIMF_Secure_Contact_Form::instance();
