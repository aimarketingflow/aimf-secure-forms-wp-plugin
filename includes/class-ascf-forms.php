<?php
/**
 * Form CRUD management using WordPress options.
 *
 * Stores form definitions as a serialized array in the 'ascf_forms' option.
 * Each form has: id, title, fields[], settings[], created_at, updated_at.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AIMF_SCF_Forms
 */
class AIMF_SCF_Forms {

    /**
     * Option key for storing all forms.
     */
    const OPTION_KEY = 'ascf_forms';

    /**
     * Get all forms.
     *
     * @return array Array of form definitions.
     */
    public static function get_all_forms() {
        $forms = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $forms ) ) {
            return array();
        }
        return $forms;
    }

    /**
     * Get a single form by ID.
     *
     * @param int $id Form ID.
     * @return array|null Form definition or null if not found.
     */
    public static function get_form( $id ) {
        $forms = self::get_all_forms();
        if ( isset( $forms[ $id ] ) ) {
            return $forms[ $id ];
        }
        return null;
    }

    /**
     * Save (create or update) a form.
     *
     * @param array $form_data Form definition.
     * @return int The form ID.
     */
    public static function save_form( $form_data ) {
        $forms = self::get_all_forms();

        // Generate ID if not set.
        if ( empty( $form_data['id'] ) ) {
            $form_data['id'] = self::get_next_id();
        }

        $id = (int) $form_data['id'];

        if ( isset( $forms[ $id ] ) ) {
            $form_data['created_at'] = $forms[ $id ]['created_at'];
        } else {
            $form_data['created_at'] = current_time( 'mysql' );
        }
        $form_data['updated_at'] = current_time( 'mysql' );

        $forms[ $id ] = self::sanitize_form( $form_data );

        update_option( self::OPTION_KEY, $forms, false );

        return $id;
    }

    /**
     * Delete a form by ID.
     *
     * @param int $id Form ID.
     * @return bool True on success.
     */
    public static function delete_form( $id ) {
        $forms = self::get_all_forms();
        if ( ! isset( $forms[ $id ] ) ) {
            return false;
        }
        // Prevent deletion of Form ID 1 (the migrated default contact form).
        if ( 1 === (int) $id ) {
            return false;
        }
        unset( $forms[ $id ] );
        update_option( self::OPTION_KEY, $forms, false );
        return true;
    }

    /**
     * Duplicate a form.
     *
     * @param int $id Form ID to duplicate.
     * @return int|false New form ID or false on failure.
     */
    public static function duplicate_form( $id ) {
        $form = self::get_form( $id );
        if ( ! $form ) {
            return false;
        }
        unset( $form['id'], $form['created_at'], $form['updated_at'] );
        $form['title'] = $form['title'] . ' (Copy)';
        return self::save_form( $form );
    }

    /**
     * Get the next available form ID.
     *
     * @return int
     */
    private static function get_next_id() {
        $forms = self::get_all_forms();
        if ( empty( $forms ) ) {
            return 1;
        }
        return max( array_keys( $forms ) ) + 1;
    }

    /**
     * Sanitize a form definition.
     *
     * @param array $form Raw form data.
     * @return array Sanitized form data.
     */
    private static function sanitize_form( $form ) {
        $sanitized = array(
            'id'         => isset( $form['id'] ) ? absint( $form['id'] ) : 0,
            'title'      => isset( $form['title'] ) ? sanitize_text_field( $form['title'] ) : '',
            'fields'     => array(),
            'settings'   => array(),
            'created_at' => isset( $form['created_at'] ) ? sanitize_text_field( $form['created_at'] ) : current_time( 'mysql' ),
            'updated_at' => isset( $form['updated_at'] ) ? sanitize_text_field( $form['updated_at'] ) : current_time( 'mysql' ),
        );

        // Sanitize fields.
        if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $sanitized['fields'][] = self::sanitize_field( $field );
            }
        }

        // Sanitize settings.
        if ( isset( $form['settings'] ) && is_array( $form['settings'] ) ) {
            $sanitized['settings'] = self::sanitize_settings( $form['settings'] );
        } else {
            $sanitized['settings'] = self::get_default_settings();
        }

        return $sanitized;
    }

    /**
     * Sanitize a single field definition.
     *
     * @param array $field Raw field data.
     * @return array Sanitized field.
     */
    private static function sanitize_field( $field ) {
        $allowed_types = array( 'text', 'email', 'textarea', 'select', 'radio', 'checkbox', 'number' );
        $type          = isset( $field['type'] ) && in_array( $field['type'], $allowed_types, true ) ? $field['type'] : 'text';

        $sanitized = array(
            'id'            => isset( $field['id'] ) ? sanitize_text_field( $field['id'] ) : 'fld_' . wp_generate_password( 8, false, false ),
            'type'          => $type,
            'label'         => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
            'required'      => ! empty( $field['required'] ),
            'placeholder'   => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
            'default_value' => isset( $field['default_value'] ) ? sanitize_text_field( $field['default_value'] ) : '',
            'description'   => isset( $field['description'] ) ? sanitize_text_field( $field['description'] ) : '',
            'css_class'      => isset( $field['css_class'] ) ? sanitize_text_field( $field['css_class'] ) : '',
            'options'        => array(),
            'min'            => isset( $field['min'] ) ? sanitize_text_field( $field['min'] ) : '',
            'max'            => isset( $field['max'] ) ? sanitize_text_field( $field['max'] ) : '',
        );

        // Sanitize options for select/radio/checkbox.
        if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && isset( $field['options'] ) && is_array( $field['options'] ) ) {
            foreach ( $field['options'] as $opt ) {
                $opt_text = is_string( $opt ) ? $opt : ( isset( $opt['value'] ) ? $opt['value'] : '' );
                $opt_text = sanitize_text_field( $opt_text );
                if ( '' !== $opt_text ) {
                    $sanitized['options'][] = $opt_text;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize form settings.
     *
     * @param array $settings Raw settings.
     * @return array Sanitized settings.
     */
    private static function sanitize_settings( $settings ) {
        $defaults = self::get_default_settings();
        return array(
            'notification_email' => isset( $settings['notification_email'] ) ? sanitize_email( $settings['notification_email'] ) : $defaults['notification_email'],
            'success_message'    => isset( $settings['success_message'] ) ? sanitize_text_field( $settings['success_message'] ) : $defaults['success_message'],
            'submit_button_text' => isset( $settings['submit_button_text'] ) ? sanitize_text_field( $settings['submit_button_text'] ) : $defaults['submit_button_text'],
        );
    }

    /**
     * Get default form settings.
     *
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'notification_email' => '',
            'success_message'    => __( 'Thank you for your message. We will get back to you shortly.', 'aimf-secure-contact-form' ),
            'submit_button_text' => __( 'Send Message', 'aimf-secure-contact-form' ),
        );
    }

    /**
     * Migrate the existing default contact form to Form ID 1.
     *
     * Creates a form definition matching the hardcoded contact form:
     * Name (text, required), Email (email, required), Service (select, required), Message (textarea, required).
     *
     * @return void
     */
    public static function migrate_default_form() {
        $forms = self::get_all_forms();
        if ( isset( $forms[1] ) ) {
            return; // Already migrated.
        }

        $default_form = array(
            'id'       => 1,
            'title'    => __( 'Default Form', 'aimf-secure-contact-form' ),
            'fields'   => array(
                array(
                    'id'            => 'fld_name',
                    'type'          => 'text',
                    'label'         => __( 'Name', 'aimf-secure-contact-form' ),
                    'required'      => true,
                    'placeholder'    => __( 'Your name', 'aimf-secure-contact-form' ),
                    'default_value'  => '',
                    'description'    => '',
                    'css_class'      => '',
                ),
                array(
                    'id'            => 'fld_email',
                    'type'          => 'email',
                    'label'         => __( 'Email', 'aimf-secure-contact-form' ),
                    'required'      => true,
                    'placeholder'    => __( 'Your email', 'aimf-secure-contact-form' ),
                    'default_value'  => '',
                    'description'    => '',
                    'css_class'      => '',
                ),
                array(
                    'id'            => 'fld_service',
                    'type'          => 'select',
                    'label'         => __( 'Service', 'aimf-secure-contact-form' ),
                    'required'      => true,
                    'placeholder'    => '',
                    'default_value'  => '',
                    'description'    => '',
                    'css_class'      => '',
                    'options'        => array(
                        __( 'General Inquiry', 'aimf-secure-contact-form' ),
                        __( 'Security Assessment', 'aimf-secure-contact-form' ),
                        __( 'Penetration Testing', 'aimf-secure-contact-form' ),
                        __( 'Incident Response', 'aimf-secure-contact-form' ),
                        __( 'Compliance & Audit', 'aimf-secure-contact-form' ),
                        __( 'Other', 'aimf-secure-contact-form' ),
                    ),
                ),
                array(
                    'id'            => 'fld_message',
                    'type'          => 'textarea',
                    'label'         => __( 'Message', 'aimf-secure-contact-form' ),
                    'required'      => true,
                    'placeholder'    => __( 'Your message', 'aimf-secure-contact-form' ),
                    'default_value'  => '',
                    'description'    => '',
                    'css_class'      => '',
                ),
            ),
            'settings' => array(
                'notification_email' => get_option( 'ascf_notification_email', '' ),
                'success_message'    => __( 'Thank you for your message. We will get back to you shortly.', 'aimf-secure-contact-form' ),
                'submit_button_text' => __( 'Send Message', 'aimf-secure-contact-form' ),
            ),
        );

        self::save_form( $default_form );
    }
}
