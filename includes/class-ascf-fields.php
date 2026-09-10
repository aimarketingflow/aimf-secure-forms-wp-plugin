<?php
/**
 * Field type registry — rendering, sanitization, and validation.
 *
 * Supports: text, email, textarea, select, radio, checkbox, number.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AIMF_SCF_Fields
 */
class AIMF_SCF_Fields {

    /**
     * Get all available field types with labels and icons.
     *
     * @return array
     */
    public static function get_field_types() {
        return array(
            'text'     => array( 'label' => __( 'Single Line', 'aimf-secure-contact-form' ), 'icon' => 'text' ),
            'email'    => array( 'label' => __( 'Email', 'aimf-secure-contact-form' ), 'icon' => 'email' ),
            'textarea' => array( 'label' => __( 'Paragraph', 'aimf-secure-contact-form' ), 'icon' => 'textarea' ),
            'select'   => array( 'label' => __( 'Dropdown', 'aimf-secure-contact-form' ), 'icon' => 'select' ),
            'radio'    => array( 'label' => __( 'Multiple Choice', 'aimf-secure-contact-form' ), 'icon' => 'radio' ),
            'checkbox' => array( 'label' => __( 'Checkboxes', 'aimf-secure-contact-form' ), 'icon' => 'checkbox' ),
            'number'   => array( 'label' => __( 'Number', 'aimf-secure-contact-form' ), 'icon' => 'number' ),
        );
    }

    /**
     * Render a field on the frontend.
     *
     * @param array $field  Field definition.
     * @param int   $form_id Form ID (for namespacing field names).
     * @return void
     */
    public static function render_field( $field, $form_id ) {
        $name        = 'ascf_field_' . $field['id'];
        $field_id   = 'ascf-field-' . $field['id'];
        $type        = $field['type'];
        $label       = $field['label'];
        $required    = ! empty( $field['required'] );
        $placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
        $default     = isset( $field['default_value'] ) ? $field['default_value'] : '';
        $desc        = isset( $field['description'] ) ? $field['description'] : '';
        $css_class   = isset( $field['css_class'] ) ? $field['css_class'] : '';
        $req_span    = $required ? ' <span class="ascf-required">*</span>' : '';
        $req_attr    = $required ? 'required' : '';

        echo '<div class="ascf-field ascf-field-' . esc_attr( $type ) . ' ' . esc_attr( $css_class ) . '">';

        // Label (all fields except checkbox group get a top label).
        if ( 'checkbox' !== $type ) {
            echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . $req_span . '</label>';
        }

        switch ( $type ) {
            case 'text':
                echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" class="ascf-input" placeholder="' . esc_attr( $placeholder ) . '" value="' . esc_attr( $default ) . '" ' . $req_attr . ' maxlength="255" />';
                break;

            case 'email':
                echo '<input type="email" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" class="ascf-input" placeholder="' . esc_attr( $placeholder ) . '" value="' . esc_attr( $default ) . '" ' . $req_attr . ' maxlength="255" />';
                break;

            case 'textarea':
                echo '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" class="ascf-input" placeholder="' . esc_attr( $placeholder ) . '" ' . $req_attr . ' rows="5" maxlength="5000">' . esc_textarea( $default ) . '</textarea>';
                break;

            case 'select':
                echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" class="ascf-input" ' . $req_attr . '>';
                if ( $placeholder ) {
                    echo '<option value="">' . esc_html( $placeholder ) . '</option>';
                }
                if ( ! empty( $field['options'] ) ) {
                    foreach ( $field['options'] as $opt ) {
                        $sel = ( $default === $opt ) ? 'selected' : '';
                        echo '<option value="' . esc_attr( $opt ) . '" ' . $sel . '>' . esc_html( $opt ) . '</option>';
                    }
                }
                echo '</select>';
                break;

            case 'radio':
                if ( ! empty( $field['options'] ) ) {
                    echo '<div class="ascf-radio-group" role="radiogroup">';
                    foreach ( $field['options'] as $i => $opt ) {
                        $opt_id = $field_id . '-' . $i;
                        $checked = ( $default === $opt ) ? 'checked' : '';
                        echo '<div class="ascf-radio-item">';
                        echo '<input type="radio" id="' . esc_attr( $opt_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt ) . '" ' . $checked . ' ' . $req_attr . ' />';
                        echo '<label for="' . esc_attr( $opt_id ) . '">' . esc_html( $opt ) . '</label>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                break;

            case 'checkbox':
                // Checkbox group label goes above the group.
                echo '<label>' . esc_html( $label ) . $req_span . '</label>';
                if ( ! empty( $field['options'] ) ) {
                    echo '<div class="ascf-checkbox-group">';
                    foreach ( $field['options'] as $i => $opt ) {
                        $opt_id = $field_id . '-' . $i;
                        echo '<div class="ascf-checkbox-item">';
                        echo '<input type="checkbox" id="' . esc_attr( $opt_id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $opt ) . '" />';
                        echo '<label for="' . esc_attr( $opt_id ) . '">' . esc_html( $opt ) . '</label>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                break;

            case 'number':
                $min = isset( $field['min'] ) && '' !== $field['min'] ? 'min="' . esc_attr( $field['min'] ) . '"' : '';
                $max = isset( $field['max'] ) && '' !== $field['max'] ? 'max="' . esc_attr( $field['max'] ) . '"' : '';
                echo '<input type="number" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" class="ascf-input" placeholder="' . esc_attr( $placeholder ) . '" value="' . esc_attr( $default ) . '" ' . $min . ' ' . $max . ' ' . $req_attr . ' />';
                break;
        }

        // Description.
        if ( $desc ) {
            echo '<span class="ascf-field-desc">' . esc_html( $desc ) . '</span>';
        }

        echo '</div>';
    }

    /**
     * Sanitize a field value based on its type.
     *
     * @param array $field Field definition.
     * @param mixed $value Raw submitted value.
     * @return mixed Sanitized value.
     */
    public static function sanitize_value( $field, $value ) {
        $type = $field['type'];

        switch ( $type ) {
            case 'text':
                return sanitize_text_field( $value );

            case 'email':
                return sanitize_email( $value );

            case 'textarea':
                return sanitize_textarea_field( $value );

            case 'select':
            case 'radio':
                // Value must be one of the defined options.
                $options = isset( $field['options'] ) ? $field['options'] : array();
                $value   = sanitize_text_field( $value );
                if ( in_array( $value, $options, true ) ) {
                    return $value;
                }
                return '';

            case 'checkbox':
                // Array of selected options.
                if ( ! is_array( $value ) ) {
                    return array();
                }
                $options  = isset( $field['options'] ) ? $field['options'] : array();
                $clean    = array();
                foreach ( $value as $v ) {
                    $v = sanitize_text_field( $v );
                    if ( in_array( $v, $options, true ) ) {
                        $clean[] = $v;
                    }
                }
                return $clean;

            case 'number':
                return is_numeric( $value ) ? floatval( $value ) : '';

            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Validate a field value.
     *
     * @param array $field Field definition.
     * @param mixed $value Sanitized value.
     * @return bool|string True if valid, error message string if invalid.
     */
    public static function validate_value( $field, $value ) {
        $required = ! empty( $field['required'] );
        $label    = $field['label'];
        $type     = $field['type'];

        // Required check.
        if ( $required ) {
            $is_empty = false;
            if ( 'checkbox' === $type ) {
                $is_empty = empty( $value ) || ( is_array( $value ) && count( $value ) === 0 );
            } elseif ( '' === $value || null === $value ) {
                $is_empty = true;
            }
            if ( $is_empty ) {
                /* translators: %s: field label */
                return sprintf( __( '%s is required.', 'aimf-secure-contact-form' ), $label );
            }
        }

        // Type-specific validation.
        if ( '' !== $value && null !== $value && ! ( is_array( $value ) && empty( $value ) ) ) {
            if ( 'email' === $type && ! is_email( $value ) ) {
                return __( 'Please enter a valid email address.', 'aimf-secure-contact-form' );
            }
            if ( 'number' === $type && ! is_numeric( $value ) ) {
                /* translators: %s: field label */
                return sprintf( __( '%s must be a number.', 'aimf-secure-contact-form' ), $label );
            }
        }

        return true;
    }

    /**
     * Get a human-readable summary of a field value for admin display / email.
     *
     * @param array $field Field definition.
     * @param mixed $value Sanitized value.
     * @return string
     */
    public static function format_value( $field, $value ) {
        if ( 'checkbox' === $field['type'] && is_array( $value ) ) {
            return implode( ', ', $value );
        }
        if ( is_array( $value ) ) {
            return implode( ', ', $value );
        }
        return (string) $value;
    }
}
