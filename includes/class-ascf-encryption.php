<?php
/**
 * Encrypted export support.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIMF_SCF_Encryption {

    const CERTIFICATE_SHA256 = 'f1aa5b79df60b0918fdbf40734b37169b03d6d43d51a8b9ca7ad8dcfb128686c';

    public static function encrypt_export( $payload, $purpose ) {
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_public_encrypt' ) ) {
            return new WP_Error( 'openssl_unavailable', __( 'OpenSSL is required for encrypted exports.', 'aimf-secure-contact-form' ) );
        }

        $certificate_path = AIMF_SCF_PLUGIN_DIR . 'assets/aimf-export-cert.pem';
        $certificate = is_readable( $certificate_path ) ? file_get_contents( $certificate_path ) : false;
        if ( false === $certificate ) {
            return new WP_Error( 'certificate_unavailable', __( 'The export encryption certificate is unavailable.', 'aimf-secure-contact-form' ) );
        }

        $fingerprint = openssl_x509_fingerprint( $certificate, 'sha256' );
        if ( false === $fingerprint || ! hash_equals( self::CERTIFICATE_SHA256, strtolower( $fingerprint ) ) ) {
            return new WP_Error( 'certificate_mismatch', __( 'The export encryption certificate failed validation.', 'aimf-secure-contact-form' ) );
        }

        $public_key = openssl_pkey_get_public( $certificate );
        $key_details = $public_key ? openssl_pkey_get_details( $public_key ) : false;
        if ( ! $key_details || OPENSSL_KEYTYPE_RSA !== $key_details['type'] || $key_details['bits'] < 2048 ) {
            return new WP_Error( 'invalid_public_key', __( 'The export certificate must contain an RSA key of at least 2048 bits.', 'aimf-secure-contact-form' ) );
        }

        $plaintext = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
        if ( false === $plaintext ) {
            return new WP_Error( 'encoding_failed', __( 'Unable to encode export data.', 'aimf-secure-contact-form' ) );
        }

        try {
            $data_key = random_bytes( 32 );
            $iv       = random_bytes( 12 );
        } catch ( Exception $exception ) {
            return new WP_Error( 'random_failed', __( 'Unable to generate export encryption material.', 'aimf-secure-contact-form' ) );
        }

        $aad = wp_json_encode( array(
            'format'      => 'aimf-secure-forms-encrypted',
            'version'     => 1,
            'purpose'     => sanitize_key( $purpose ),
            'created_at'  => gmdate( 'c' ),
            'certificate' => self::CERTIFICATE_SHA256,
        ), JSON_UNESCAPED_SLASHES );

        $tag = '';
        $ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $data_key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );
        if ( false === $ciphertext ) {
            return new WP_Error( 'encryption_failed', __( 'Unable to encrypt export data.', 'aimf-secure-contact-form' ) );
        }

        $encrypted_key = '';
        if ( ! openssl_public_encrypt( $data_key, $encrypted_key, $public_key, OPENSSL_PKCS1_OAEP_PADDING ) ) {
            return new WP_Error( 'key_wrap_failed', __( 'Unable to protect the export encryption key.', 'aimf-secure-contact-form' ) );
        }

        $envelope = array(
            'format'        => 'aimf-secure-forms-encrypted',
            'version'       => 1,
            'content_cipher' => 'AES-256-GCM',
            'key_cipher'    => 'RSA-2048-OAEP',
            'certificate_sha256' => self::CERTIFICATE_SHA256,
            'aad'           => base64_encode( $aad ),
            'encrypted_key' => base64_encode( $encrypted_key ),
            'iv'            => base64_encode( $iv ),
            'tag'           => base64_encode( $tag ),
            'ciphertext'    => base64_encode( $ciphertext ),
        );

        $encoded = wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === $encoded ) {
            return new WP_Error( 'envelope_failed', __( 'Unable to encode the encrypted export.', 'aimf-secure-contact-form' ) );
        }

        return $encoded;
    }
}
