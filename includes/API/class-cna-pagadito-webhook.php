<?php
/**
 * Pagadito webhook signature verification.
 *
 * @see https://dev.pagadito.com/index.php?mod=docs&hac=mostrar&tema=webhooks#events
 * @package CNA_Subscriptions
 * @since 1.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Pagadito_Webhook {

    const HEADER_NOTIFICATION_ID = 'PAGADITO-NOTIFICATION-ID';
    const HEADER_NOTIFICATION_TIMESTAMP = 'PAGADITO-NOTIFICATION-TIMESTAMP';
    const HEADER_SIGNATURE = 'PAGADITO-SIGNATURE';
    const HEADER_AUTH_ALGO = 'PAGADITO-AUTH-ALGO';
    const HEADER_CERT_URL = 'PAGADITO-CERT-URL';

    const CERT_CACHE_TTL = 3600;

    /** Maximum seconds in the past a webhook timestamp may be (15 min by default). */
    const TIMESTAMP_MAX_AGE = 900;

    /** Maximum seconds in the future a webhook timestamp may be (2 min). */
    const TIMESTAMP_MAX_SKEW = 120;

    /**
     * Whether Pagadito signature verification is required.
     *
     * @return bool
     */
    public static function is_signature_required() {
        if (get_option('cna_pagadito_require_webhook_signature', '1') !== '1') {
            return false;
        }

        return true;
    }

    /**
     * Whether signature verification may be skipped (local dev only).
     *
     * @return bool
     */
    public static function can_skip_signature_verification() {
        if (self::is_signature_required()) {
            return false;
        }

        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        return $env === 'local' && defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Verifies Pagadito asymmetric webhook signature per official documentation.
     *
     * @param WP_REST_Request $request
     * @param string          $raw_body Raw HTTP body (must not be re-encoded JSON).
     * @return true|WP_Error
     */
    public static function verify_signature($request, $raw_body) {
        if (!is_string($raw_body)) {
            $raw_body = '';
        }

        $notification_id = self::get_header($request, self::HEADER_NOTIFICATION_ID);
        $notification_timestamp = self::get_header($request, self::HEADER_NOTIFICATION_TIMESTAMP);
        $auth_algo = self::get_header($request, self::HEADER_AUTH_ALGO);
        $cert_url = self::get_header($request, self::HEADER_CERT_URL);
        $signature_b64 = self::get_header($request, self::HEADER_SIGNATURE);

        $has_signature_headers = (
            $notification_id !== '' &&
            $notification_timestamp !== '' &&
            $auth_algo !== '' &&
            $cert_url !== '' &&
            $signature_b64 !== ''
        );

        if (!$has_signature_headers) {
            if (self::can_skip_signature_verification()) {
                CNA_Security::debug_log('CNA Pagadito webhook: firma omitida (modo desarrollo local)');
                return true;
            }

            return new WP_Error(
                'pagadito_signature_missing',
                __('Faltan cabeceras de firma de Pagadito', 'cna-subscriptions'),
                array('status' => 403)
            );
        }

        // Reject stale or future-dated notifications to prevent replay attacks.
        if ($notification_timestamp !== '') {
            $timestamp_freshness = self::check_timestamp_freshness($notification_timestamp);
            if (is_wp_error($timestamp_freshness)) {
                return $timestamp_freshness;
            }
        }

        if ($raw_body === '') {
            return new WP_Error(
                'pagadito_empty_body',
                __('Cuerpo del webhook vacío', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload) || empty($payload['id'])) {
            return new WP_Error(
                'pagadito_invalid_payload',
                __('Payload de webhook inválido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $event_id = (string) $payload['id'];
        $wsk = self::get_merchant_wsk();
        if ($wsk === '') {
            return new WP_Error(
                'pagadito_wsk_missing',
                __('WSK de Pagadito no configurado', 'cna-subscriptions'),
                array('status' => 500)
            );
        }

        if (!self::is_allowed_cert_url($cert_url)) {
            return new WP_Error(
                'pagadito_invalid_cert_url',
                __('URL de certificado Pagadito no permitida', 'cna-subscriptions'),
                array('status' => 403)
            );
        }

        $crc32_signed = self::body_crc32($raw_body);
        $crc32_unsigned = self::body_crc32_unsigned($raw_body);

        $data_signed = $notification_id . '|' . $notification_timestamp . '|' . $event_id . '|' . $crc32_signed . '|' . $wsk;
        $signature = base64_decode($signature_b64, true);
        if ($signature === false || $signature === '') {
            return new WP_Error(
                'pagadito_invalid_signature',
                __('Firma Pagadito inválida', 'cna-subscriptions'),
                array('status' => 403)
            );
        }

        $public_key = self::get_public_key_from_cert_url($cert_url);
        if (is_wp_error($public_key)) {
            return $public_key;
        }

        $verify_algo = self::normalize_openssl_algorithm($auth_algo);
        if ($verify_algo === null) {
            return new WP_Error(
                'pagadito_invalid_auth_algo',
                __('Algoritmo de firma Pagadito no soportado', 'cna-subscriptions'),
                array('status' => 403)
            );
        }

        $result = openssl_verify($data_signed, $signature, $public_key, $verify_algo);

        // On 64-bit PHP, crc32() returns a signed integer; Pagadito may expect the unsigned
        // representation. If the primary check fails, retry with the unsigned form.
        if ($result === 0 && $crc32_unsigned !== $crc32_signed) {
            $data_signed_alt = $notification_id . '|' . $notification_timestamp . '|' . $event_id . '|' . $crc32_unsigned . '|' . $wsk;
            $result_alt = openssl_verify($data_signed_alt, $signature, $public_key, $verify_algo);
            if ($result_alt === 1) {
                CNA_Security::debug_log('CNA Pagadito webhook: firma verificada con CRC32 sin signo (unsigned fallback)');
                if (PHP_VERSION_ID < 80000 && is_resource($public_key)) {
                    openssl_free_key($public_key);
                }
                return true;
            }
        }

        if (PHP_VERSION_ID < 80000 && is_resource($public_key)) {
            openssl_free_key($public_key);
        }

        if ($result === 1) {
            return true;
        }

        if ($result === 0) {
            CNA_Audit_Logger::log(
                CNA_Audit_Logger::EVENT_WEBHOOK_RECEIVED,
                array(
                    'subscription_id' => 0,
                    'error' => 'Firma Pagadito inválida',
                    'notification_id' => substr($notification_id, 0, 16),
                    'event_id' => substr($event_id, 0, 16),
                ),
                CNA_Audit_Logger::SEVERITY_CRITICAL
            );

            return new WP_Error(
                'pagadito_signature_invalid',
                __('Verificación de firma Pagadito fallida', 'cna-subscriptions'),
                array('status' => 403)
            );
        }

        return new WP_Error(
            'pagadito_signature_error',
            __('Error al verificar la firma Pagadito', 'cna-subscriptions'),
            array('status' => 500)
        );
    }

    /**
     * Validates that a webhook timestamp is within the acceptable freshness window.
     *
     * @param string $timestamp ISO-8601 or Unix timestamp string from the notification header.
     * @return true|WP_Error
     */
    private static function check_timestamp_freshness($timestamp) {
        $max_age = (int) apply_filters('cna_pagadito_webhook_max_age', self::TIMESTAMP_MAX_AGE);

        // Accept Unix epoch integers or ISO-8601 strings.
        if (ctype_digit(ltrim($timestamp, '-'))) {
            $ts = (int) $timestamp;
        } else {
            $ts = strtotime($timestamp);
        }

        if ($ts === false || $ts === 0) {
            return new WP_Error(
                'pagadito_timestamp_invalid',
                __('Timestamp de webhook inválido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $now = time();
        if ($ts < $now - $max_age) {
            return new WP_Error(
                'pagadito_timestamp_stale',
                __('Notificación de Pagadito expirada (posible replay)', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        if ($ts > $now + self::TIMESTAMP_MAX_SKEW) {
            return new WP_Error(
                'pagadito_timestamp_future',
                __('Timestamp de notificación Pagadito en el futuro', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        return true;
    }

    /**
     * Returns true if this Pagadito notification was already handled successfully.
     *
     * @param string $notification_id
     * @param string $event_id
     * @return bool
     */
    public static function is_notification_processed($notification_id, $event_id) {
        foreach (self::notification_cache_keys($notification_id, $event_id) as $transient_key) {
            if (get_transient($transient_key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks a notification as processed (call only after successful handling).
     *
     * @param string $notification_id
     * @param string $event_id
     */
    public static function mark_notification_processed($notification_id, $event_id) {
        foreach (self::notification_cache_keys($notification_id, $event_id) as $transient_key) {
            set_transient($transient_key, 1, WEEK_IN_SECONDS);
        }
    }

    /**
     * @param string $notification_id
     * @param string $event_id
     * @return string[]
     */
    private static function notification_cache_keys($notification_id, $event_id) {
        $notification_id = sanitize_text_field($notification_id);
        $event_id = sanitize_text_field($event_id);
        $keys = array();

        if ($notification_id !== '') {
            $keys[] = 'cna_pg_wh_' . md5('n_' . $notification_id);
        }
        if ($event_id !== '') {
            $keys[] = 'cna_pg_wh_' . md5('e_' . $event_id);
        }

        return $keys;
    }

    /**
     * CRC32 of raw body as a signed integer string (PHP default on 64-bit).
     *
     * @param string $raw_body
     * @return string
     */
    public static function body_crc32($raw_body) {
        return (string) crc32($raw_body);
    }

    /**
     * CRC32 of raw body as an unsigned integer string (for cross-platform compatibility).
     * On 64-bit PHP, crc32() can return negative values; Pagadito may expect the unsigned form.
     *
     * @param string $raw_body
     * @return string
     */
    public static function body_crc32_unsigned($raw_body) {
        return (string) sprintf('%u', crc32($raw_body));
    }

    /**
     * @param WP_REST_Request $request
     * @param string          $name
     * @return string
     */
    public static function get_header($request, $name) {
        if ($request instanceof WP_REST_Request) {
            $value = $request->get_header($name);
            if (!empty($value)) {
                return is_array($value) ? trim((string) $value[0]) : trim((string) $value);
            }
        }

        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$server_key])) {
            return trim(sanitize_text_field(wp_unslash($_SERVER[$server_key])));
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $header_name => $header_value) {
                    if (strcasecmp($header_name, $name) === 0) {
                        return trim((string) $header_value);
                    }
                }
            }
        }

        return '';
    }

    /**
     * @return string
     */
    private static function get_merchant_wsk() {
        $config = CNA_Payment_Helper::get_pagadito_config();
        return isset($config['wsk']) ? trim((string) $config['wsk']) : '';
    }

    /**
     * Only fetch certificates from Pagadito-controlled hosts over HTTPS.
     *
     * @param string $cert_url
     * @return bool
     */
    public static function is_allowed_cert_url($cert_url) {
        $cert_url = esc_url_raw($cert_url);
        if ($cert_url === '') {
            return false;
        }

        $parts = wp_parse_url($cert_url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        if (empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        return $host === 'pagadito.com' || substr($host, -strlen('.pagadito.com')) === '.pagadito.com';
    }

    /**
     * @param string $cert_url
     * @return resource|OpenSSLAsymmetricKey|WP_Error
     */
    private static function get_public_key_from_cert_url($cert_url) {
        $cache_key = 'cna_pg_cert_' . md5($cert_url);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            $public_key = openssl_pkey_get_public($cached);
            if ($public_key !== false) {
                return $public_key;
            }
        }

        $response = wp_remote_get(
            $cert_url,
            array(
                'timeout' => 15,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => array(
                    'Connection' => 'close',
                ),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'pagadito_cert_fetch_failed',
                __('No se pudo obtener el certificado de Pagadito', 'cna-subscriptions'),
                array('status' => 502)
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error(
                'pagadito_cert_fetch_failed',
                __('Certificado Pagadito no disponible', 'cna-subscriptions'),
                array('status' => 502)
            );
        }

        $cert_content = wp_remote_retrieve_body($response);
        if ($cert_content === '') {
            return new WP_Error(
                'pagadito_cert_empty',
                __('Certificado Pagadito vacío', 'cna-subscriptions'),
                array('status' => 502)
            );
        }

        set_transient($cache_key, $cert_content, self::CERT_CACHE_TTL);

        $public_key = openssl_pkey_get_public($cert_content);
        if ($public_key === false) {
            return new WP_Error(
                'pagadito_cert_invalid',
                __('Certificado Pagadito inválido', 'cna-subscriptions'),
                array('status' => 502)
            );
        }

        return $public_key;
    }

    /**
     * Maps PAGADITO-AUTH-ALGO values to openssl_verify algorithm constants.
     *
     * Pagadito's official example passes the header value directly to openssl_verify();
     * known aliases are normalized first, otherwise the raw value is used.
     *
     * @param string $auth_algo
     * @return int|string|null
     */
    private static function normalize_openssl_algorithm($auth_algo) {
        $auth_algo = trim($auth_algo);
        if ($auth_algo === '') {
            return null;
        }

        if (defined($auth_algo)) {
            return constant($auth_algo);
        }

        if (ctype_digit($auth_algo)) {
            return (int) $auth_algo;
        }

        $map = array(
            'sha256withrsaencryption' => OPENSSL_ALGO_SHA256,
            'sha1withrsaencryption' => OPENSSL_ALGO_SHA1,
            'sha256' => OPENSSL_ALGO_SHA256,
            'sha1' => OPENSSL_ALGO_SHA1,
        );

        $key = strtolower($auth_algo);
        if (isset($map[$key])) {
            return $map[$key];
        }

        return $auth_algo;
    }
}
