<?php
/**
 * Form Builder admin page.
 *
 * Provides a drag-and-drop interface for creating and editing forms.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AIMF_SCF_Builder
 */
class AIMF_SCF_Builder {

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_pages' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_builder_assets' ) );
        add_action( 'wp_ajax_ascf_save_form', array( __CLASS__, 'ajax_save_form' ) );
        add_action( 'wp_ajax_ascf_delete_form', array( __CLASS__, 'ajax_delete_form' ) );
        add_action( 'wp_ajax_ascf_duplicate_form', array( __CLASS__, 'ajax_duplicate_form' ) );
    }

    /**
     * Register admin sub-pages under the existing AIMF menu.
     */
    public static function register_admin_pages() {
        // The main submissions page is registered by class-ascf-admin.php as the top-level menu.
        // We add sub-pages for the builder.
        add_submenu_page(
            'aimf-scf',
            __( 'Form Builder', 'aimf-secure-contact-form' ),
            __( 'Form Builder', 'aimf-secure-contact-form' ),
            'manage_options',
            'aimf-scf-builder',
            array( __CLASS__, 'render_builder_page' )
        );

        add_submenu_page(
            'aimf-scf',
            __( 'Edit Form', 'aimf-secure-contact-form' ),
            __( 'Edit Form', 'aimf-secure-contact-form' ),
            'manage_options',
            'aimf-scf-edit',
            array( __CLASS__, 'render_edit_page' )
        );
    }

    /**
     * Enqueue builder assets on builder pages.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_builder_assets( $hook ) {
        // The submenu pages will have hooks like "aimf-secure-contact-form_page_aimf-scf-builder".
        if ( false === strpos( $hook, 'aimf-scf-builder' ) && false === strpos( $hook, 'aimf-scf-edit' ) ) {
            return;
        }

        // Also load the base admin CSS for the brand header.
        wp_enqueue_style(
            'aimf-scf-admin',
            AIMF_SCF_PLUGIN_URL . 'assets/ascf-admin.css',
            array(),
            AIMF_SCF_VERSION
        );

        wp_enqueue_style(
            'aimf-scf-builder',
            AIMF_SCF_PLUGIN_URL . 'assets/ascf-builder.css',
            array(),
            AIMF_SCF_VERSION
        );

        wp_enqueue_script(
            'aimf-scf-builder',
            AIMF_SCF_PLUGIN_URL . 'assets/ascf-builder.js',
            array(),
            AIMF_SCF_VERSION,
            true
        );

        // Pass data to the builder JS.
        wp_localize_script( 'aimf-scf-builder', 'ascf_builder', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'ascf_builder_nonce' ),
            'fieldTypes' => AIMF_SCF_Fields::get_field_types(),
            'i18n'      => array(
                'confirmDelete' => __( 'Are you sure you want to delete this form? This cannot be undone.', 'aimf-secure-contact-form' ),
                'confirmRemoveField' => __( 'Remove this field?', 'aimf-secure-contact-form' ),
                'saving'        => __( 'Saving...', 'aimf-secure-contact-form' ),
                'saved'         => __( 'Form saved successfully!', 'aimf-secure-contact-form' ),
                'saveError'     => __( 'Error saving form. Please try again.', 'aimf-secure-contact-form' ),
                'addField'      => __( 'Add Field', 'aimf-secure-contact-form' ),
                'fieldLabel'    => __( 'Field Label', 'aimf-secure-contact-form' ),
                'placeholder'   => __( 'Placeholder', 'aimf-secure-contact-form' ),
                'defaultValue'  => __( 'Default Value', 'aimf-secure-contact-form' ),
                'description'   => __( 'Description', 'aimf-secure-contact-form' ),
                'required'       => __( 'Required', 'aimf-secure-contact-form' ),
                'options'        => __( 'Options (one per line)', 'aimf-secure-contact-form' ),
                'cssClass'       => __( 'CSS Class', 'aimf-secure-contact-form' ),
                'min'            => __( 'Min', 'aimf-secure-contact-form' ),
                'max'            => __( 'Max', 'aimf-secure-contact-form' ),
                'cancel'         => __( 'Cancel', 'aimf-secure-contact-form' ),
                'save'           => __( 'Save Form', 'aimf-secure-contact-form' ),
                'title'          => __( 'Form Title', 'aimf-secure-contact-form' ),
                'shortcode'      => __( 'Shortcode', 'aimf-secure-contact-form' ),
                'copyShortcode'  => __( 'Copy Shortcode', 'aimf-secure-contact-form' ),
                'copied'         => __( 'Copied!', 'aimf-secure-contact-form' ),
                'noFields'       => __( 'No fields yet. Click a field type above to add it.', 'aimf-secure-contact-form' ),
                'settings'       => __( 'Settings', 'aimf-secure-contact-form' ),
                'notificationEmail' => __( 'Notification Email (leave blank for site admin)', 'aimf-secure-contact-form' ),
                'successMessage'    => __( 'Success Message', 'aimf-secure-contact-form' ),
                'submitButtonText'  => __( 'Submit Button Text', 'aimf-secure-contact-form' ),
            ),
        ) );
    }

    /**
     * Render the form builder list page.
     */
    public static function render_builder_page() {
        // Handle creation of new form via query param.
        if ( isset( $_GET['action'] ) && 'new' === $_GET['action'] && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ascf_new_form' ) ) {
                // Create a blank form and redirect to edit.
                $new_id = AIMF_SCF_Forms::save_form( array(
                    'title'    => __( 'Untitled Form', 'aimf-secure-contact-form' ),
                    'fields'   => array(),
                    'settings' => AIMF_SCF_Forms::get_default_settings(),
                ) );
                wp_safe_redirect( admin_url( 'admin.php?page=aimf-scf-edit&form_id=' . $new_id ) );
                exit;
            }
        }

        $forms = AIMF_SCF_Forms::get_all_forms();
        $new_nonce = wp_create_nonce( 'ascf_new_form' );
        ?>
        <div class="wrap ascf-builder-wrap">
            <div class="ascf-brand-header">
                <img src="<?php echo esc_url( AIMF_SCF_PLUGIN_URL . 'assets/aimf-logo.svg' ); ?>" alt="AIMF Security" class="ascf-brand-logo" />
                <h1><?php esc_html_e( 'Form Builder', 'aimf-secure-contact-form' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=aimf-scf-builder&action=new&_wpnonce=' . $new_nonce ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Form', 'aimf-secure-contact-form' ); ?></a>
                </h1>
            </div>

            <table class="wp-list-table widefat fixed striped ascf-forms-table">
                <thead>
                    <tr>
                        <th width="60"><?php esc_html_e( 'ID', 'aimf-secure-contact-form' ); ?></th>
                        <th><?php esc_html_e( 'Title', 'aimf-secure-contact-form' ); ?></th>
                        <th width="100"><?php esc_html_e( 'Fields', 'aimf-secure-contact-form' ); ?></th>
                        <th width="250"><?php esc_html_e( 'Shortcode', 'aimf-secure-contact-form' ); ?></th>
                        <th width="150"><?php esc_html_e( 'Updated', 'aimf-secure-contact-form' ); ?></th>
                        <th width="200"><?php esc_html_e( 'Actions', 'aimf-secure-contact-form' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $forms ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No forms found.', 'aimf-secure-contact-form' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $forms as $form ) : ?>
                        <tr>
                            <td><?php echo esc_html( $form['id'] ); ?></td>
                            <td><strong><?php echo esc_html( $form['title'] ); ?></strong></td>
                            <td><?php echo esc_html( count( $form['fields'] ) ); ?></td>
                            <td><code>[aimf_form id="<?php echo esc_attr( $form['id'] ); ?>"]</code></td>
                            <td><?php echo esc_html( $form['updated_at'] ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=aimf-scf-edit&form_id=' . $form['id'] ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'aimf-secure-contact-form' ); ?></a>
                                <?php if ( 1 !== (int) $form['id'] ) : ?>
                                    <button class="button button-small ascf-duplicate-btn" data-form-id="<?php echo esc_attr( $form['id'] ); ?>"><?php esc_html_e( 'Duplicate', 'aimf-secure-contact-form' ); ?></button>
                                    <button class="button button-small button-link-delete ascf-delete-btn" data-form-id="<?php echo esc_attr( $form['id'] ); ?>"><?php esc_html_e( 'Delete', 'aimf-secure-contact-form' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the form editor page.
     */
    public static function render_edit_page() {
        $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
        $form    = AIMF_SCF_Forms::get_form( $form_id );

        if ( ! $form ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Form not found', 'aimf-secure-contact-form' ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=aimf-scf-builder' ) ) . '" class="button">' . esc_html__( 'Back to Form Builder', 'aimf-secure-contact-form' ) . '</a></p></div>';
            return;
        }

        $field_types = AIMF_SCF_Fields::get_field_types();
        ?>
        <div class="wrap ascf-builder-wrap ascf-edit-wrap">
            <div class="ascf-brand-header">
                <img src="<?php echo esc_url( AIMF_SCF_PLUGIN_URL . 'assets/aimf-logo.svg' ); ?>" alt="AIMF Security" class="ascf-brand-logo" />
                <h1><?php esc_html_e( 'Edit Form', 'aimf-secure-contact-form' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=aimf-scf-builder' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Forms', 'aimf-secure-contact-form' ); ?></a>
                </h1>
            </div>

            <div class="ascf-builder-grid">
                <!-- Left: Field types palette -->
                <div class="ascf-builder-palette">
                    <h3><?php esc_html_e( 'Add Fields', 'aimf-secure-contact-form' ); ?></h3>
                    <div class="ascf-field-types">
                        <?php foreach ( $field_types as $type => $info ) : ?>
                            <button type="button" class="ascf-field-type-btn" data-type="<?php echo esc_attr( $type ); ?>">
                                <span class="ascf-field-icon ascf-icon-<?php echo esc_attr( $info['icon'] ); ?>"></span>
                                <?php echo esc_html( $info['label'] ); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Center: Form canvas -->
                <div class="ascf-builder-canvas">
                    <div class="ascf-form-header">
                        <input type="text" id="ascf-form-title" value="<?php echo esc_attr( $form['title'] ); ?>" placeholder="<?php esc_attr_e( 'Form Title', 'aimf-secure-contact-form' ); ?>" />
                        <div class="ascf-shortcode-box">
                            <label><?php esc_html_e( 'Shortcode:', 'aimf-secure-contact-form' ); ?></label>
                            <input type="text" readonly value='[aimf_form id="<?php echo esc_attr( $form['id'] ); ?>"]' id="ascf-shortcode-input" />
                            <button type="button" class="button" id="ascf-copy-shortcode"><?php esc_html_e( 'Copy', 'aimf-secure-contact-form' ); ?></button>
                        </div>
                    </div>

                    <div class="ascf-fields-container" id="ascf-fields-container">
                        <!-- Fields are rendered here by JS -->
                        <p class="ascf-no-fields"><?php esc_html_e( 'No fields yet. Click a field type on the left to add it.', 'aimf-secure-contact-form' ); ?></p>
                    </div>

                    <div class="ascf-builder-actions">
                        <button type="button" class="button button-primary button-large" id="ascf-save-form"><?php esc_html_e( 'Save Form', 'aimf-secure-contact-form' ); ?></button>
                        <span class="ascf-save-status" id="ascf-save-status"></span>
                    </div>
                </div>

                <!-- Right: Field settings panel -->
                <div class="ascf-builder-settings" id="ascf-field-settings">
                    <h3><?php esc_html_e( 'Field Settings', 'aimf-secure-contact-form' ); ?></h3>
                    <p class="ascf-settings-empty"><?php esc_html_e( 'Click a field to edit its settings.', 'aimf-secure-contact-form' ); ?></p>
                </div>
            </div>

            <!-- Hidden form data for JS initialization -->
            <script type="application/json" id="ascf-form-data"><?php echo wp_json_encode( $form ); ?></script>
        </div>
        <?php
    }

    /**
     * AJAX handler: Save form.
     */
    public static function ajax_save_form() {
        check_ajax_referer( 'ascf_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aimf-secure-contact-form' ) ), 403 );
        }

        $form_json = isset( $_POST['form_data'] ) ? wp_unslash( $_POST['form_data'] ) : '';
        $form_data = json_decode( $form_json, true );

        if ( null === $form_data || ! is_array( $form_data ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form data.', 'aimf-secure-contact-form' ) ), 400 );
        }

        $form_id = AIMF_SCF_Forms::save_form( $form_data );

        wp_send_json_success( array(
            'id'        => $form_id,
            'message'   => __( 'Form saved successfully!', 'aimf-secure-contact-form' ),
            'shortcode' => '[aimf_form id="' . $form_id . '"]',
        ) );
    }

    /**
     * AJAX handler: Delete form.
     */
    public static function ajax_delete_form() {
        check_ajax_referer( 'ascf_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aimf-secure-contact-form' ) ), 403 );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'aimf-secure-contact-form' ) ), 400 );
        }

        $result = AIMF_SCF_Forms::delete_form( $form_id );
        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Cannot delete this form (Form ID 1 is protected).', 'aimf-secure-contact-form' ) ), 400 );
        }

        wp_send_json_success( array( 'message' => __( 'Form deleted.', 'aimf-secure-contact-form' ) ) );
    }

    /**
     * AJAX handler: Duplicate form.
     */
    public static function ajax_duplicate_form() {
        check_ajax_referer( 'ascf_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aimf-secure-contact-form' ) ), 403 );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'aimf-secure-contact-form' ) ), 400 );
        }

        $new_id = AIMF_SCF_Forms::duplicate_form( $form_id );
        if ( ! $new_id ) {
            wp_send_json_error( array( 'message' => __( 'Failed to duplicate form.', 'aimf-secure-contact-form' ) ), 500 );
        }

        wp_send_json_success( array(
            'id'      => $new_id,
            'message' => __( 'Form duplicated.', 'aimf-secure-contact-form' ),
            'editUrl' => admin_url( 'admin.php?page=aimf-scf-edit&form_id=' . $new_id ),
        ) );
    }
}
