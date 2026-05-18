<?php
/**
 * Helper para Encriptación de Tokens
 * Encripta y desencripta tokens usando wp_salt()
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Token_Encryption {

    /**
     * Método de encriptación
     */
    const METHOD = 'AES-256-CBC';

    /**
     * Encripta un token usando wp_salt()
     *
     * @param string $token Token a encriptar
     * @return string|false Token encriptado o false en caso de error
     */
    public static function encrypt($token) {
        if (empty($token)) {
            return false;
        }

        // Obtener salt de WordPress
        $key = self::get_encryption_key();
        $iv_length = openssl_cipher_iv_length(self::METHOD);
        $iv = openssl_random_pseudo_bytes($iv_length);

        if ($iv === false) {
            return false;
        }

        $encrypted = openssl_encrypt($token, self::METHOD, $key, 0, $iv);

        if ($encrypted === false) {
            return false;
        }

        // Combinar IV y datos encriptados (IV se necesita para desencriptar)
        $encrypted_data = base64_encode($iv . $encrypted);

        return $encrypted_data;
    }

    /**
     * Desencripta un token
     *
     * @param string $encrypted_token Token encriptado
     * @return string|false Token desencriptado o false en caso de error
     */
    public static function decrypt($encrypted_token) {
        if (empty($encrypted_token)) {
            return false;
        }

        // Decodificar base64
        $data = base64_decode($encrypted_token, true);

        if ($data === false) {
            return false;
        }

        $key = self::get_encryption_key();
        $iv_length = openssl_cipher_iv_length(self::METHOD);

        // Extraer IV (primeros bytes)
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        if (strlen($iv) !== $iv_length || empty($encrypted)) {
            return false;
        }

        $decrypted = openssl_decrypt($encrypted, self::METHOD, $key, 0, $iv);

        return $decrypted;
    }

    /**
     * Obtiene la clave de encriptación basada en wp_salt()
     *
     * @return string
     */
    private static function get_encryption_key() {
        // Usar múltiples salts de WordPress para mayor seguridad
        $salt1 = defined('AUTH_SALT') ? AUTH_SALT : '';
        $salt2 = defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '';
        $salt3 = defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '';
        $salt4 = defined('NONCE_SALT') ? NONCE_SALT : '';

        // Combinar salts y crear hash
        $combined = $salt1 . $salt2 . $salt3 . $salt4 . 'cna_subscriptions_token_encryption';
        
        // Generar clave de 32 bytes (requerido para AES-256)
        return hash('sha256', $combined, true);
    }

    /**
     * Verifica si un token encriptado es válido
     *
     * @param string $encrypted_token
     * @return bool
     */
    public static function is_valid($encrypted_token) {
        $decrypted = self::decrypt($encrypted_token);
        return $decrypted !== false && !empty($decrypted);
    }
}
