<?php
/**
 * Security helpers for CNA Subscriptions.
 *
 * @package CNA_Subscriptions
 * @since 1.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Security {

    /**
     * Validates the WordPress REST nonce for cookie-authenticated requests.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function verify_rest_nonce($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (empty($nonce)) {
            $nonce = $request->get_param('_wpnonce');
        }

        if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'rest_cookie_invalid_nonce',
                __('Sesión inválida. Recarga la página e inténtalo de nuevo.', 'cna-subscriptions'),
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Rate limit by IP for public endpoints.
     *
     * @param string $action
     * @param int    $max_requests
     * @param int    $window_seconds
     * @return true|WP_Error
     */
    public static function rate_limit_by_ip($action, $max_requests = 10, $window_seconds = 60) {
        $ip = self::get_client_ip();
        return self::apply_rate_limit('cna_rl_' . sanitize_key($action) . '_' . md5($ip), $max_requests, $window_seconds);
    }

    /**
     * Rate limit by email address for auth endpoints (prevents distributed IP attacks).
     *
     * @param string $action
     * @param string $email
     * @param int    $max_requests
     * @param int    $window_seconds
     * @return true|WP_Error
     */
    public static function rate_limit_by_email($action, $email, $max_requests = 5, $window_seconds = 900) {
        $key = 'cna_rle_' . sanitize_key($action) . '_' . md5(strtolower(trim($email)));
        return self::apply_rate_limit($key, $max_requests, $window_seconds);
    }

    /**
     * Core rate-limit logic shared by IP and email limiters.
     *
     * @param string $transient_key
     * @param int    $max_requests
     * @param int    $window_seconds
     * @return true|WP_Error
     */
    private static function apply_rate_limit($transient_key, $max_requests, $window_seconds) {
        $requests = get_transient($transient_key);

        if ($requests === false) {
            set_transient($transient_key, 1, $window_seconds);
            return true;
        }

        if ((int) $requests >= $max_requests) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Demasiadas solicitudes. Por favor, intenta de nuevo en un momento.', 'cna-subscriptions'),
                array('status' => 429)
            );
        }

        set_transient($transient_key, (int) $requests + 1, $window_seconds);
        return true;
    }

    /**
     * Returns the real client IP address.
     *
     * Proxy/CDN headers are only trusted when REMOTE_ADDR is in the configured list of
     * trusted proxy IPs (filter: `cna_trusted_proxy_ips`). This prevents IP spoofing via
     * crafted headers on direct connections.
     *
     * @return string
     */
    public static function get_client_ip() {
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $remote_addr = filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';

        $trusted_proxies = apply_filters('cna_trusted_proxy_ips', array());

        if (!empty($trusted_proxies) && in_array($remote_addr, (array) $trusted_proxies, true)) {
            // Only trust forwarding headers when the direct connection comes from a known proxy.
            $forwarding_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR');
            foreach ($forwarding_keys as $key) {
                if (empty($_SERVER[$key])) {
                    continue;
                }
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $remote_addr;
    }

    /**
     * Whether payment sandbox bypass is allowed (never on production).
     *
     * @return bool
     */
    public static function is_payment_sandbox_allowed() {
        if (get_option('cna_payment_sandbox', '0') !== '1') {
            return false;
        }

        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if ($env === 'production') {
            return false;
        }

        return defined('WP_DEBUG') && WP_DEBUG;
    }

    // Removed: get_webhook_secret() and verify_webhook_secret() — replaced by
    // CNA_Pagadito_Webhook::verify_signature() (asymmetric PAGADITO-SIGNATURE verification).

    /**
     * Validates variant fields against product configuration (server-side pricing rules).
     *
     * @param int   $product_id
     * @param array $variant
     * @return true|WP_Error
     */
    public static function validate_variant_for_product($product_id, $variant) {
        if (!is_array($variant)) {
            return new WP_Error(
                'invalid_variant',
                __('Datos de variante inválidos', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $size = isset($variant['size']) ? strtolower(sanitize_text_field($variant['size'])) : '';
        if ($size === '' || CNA_Product_Helper::get_variation_price($product_id, $size) === false) {
            return new WP_Error(
                'invalid_variant_size',
                __('Tamaño o variación de producto no válido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $advance_percent = floatval($variant['advance_percent'] ?? 100);
        if (!in_array($advance_percent, array(50.0, 100.0), true)) {
            return new WP_Error(
                'invalid_advance_percent',
                __('El porcentaje de anticipo no está permitido para este producto', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $frequency_weeks = intval($variant['frequency'] ?? 0);
        $allowed_weeks = self::get_allowed_frequency_weeks($product_id);
        if (!in_array($frequency_weeks, $allowed_weeks, true)) {
            return new WP_Error(
                'invalid_frequency',
                __('La frecuencia seleccionada no está permitida para este producto', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        return true;
    }

    /**
     * @param int $product_id
     * @return int[]
     */
    public static function get_allowed_frequency_weeks($product_id) {
        $frequencies_json = get_post_meta($product_id, '_cna_product_frequencies', true);
        $weeks = array();

        if (!empty($frequencies_json)) {
            $frequencies = json_decode($frequencies_json, true);
            if (is_array($frequencies)) {
                foreach ($frequencies as $frequency) {
                    $amount = intval($frequency['amount'] ?? 0);
                    $unit = isset($frequency['unit']) ? strtolower($frequency['unit']) : 'weeks';
                    if ($amount <= 0) {
                        continue;
                    }
                    if (in_array($unit, array('week', 'weeks', 'semana', 'semanas'), true)) {
                        $weeks[] = $amount;
                    } elseif (in_array($unit, array('month', 'months', 'mes', 'meses'), true)) {
                        $weeks[] = $amount * 4;
                    }
                }
            }
        }

        $weeks = array_values(array_unique(array_filter($weeks)));
        if (empty($weeks)) {
            $weeks = array(1);
        }

        sort($weeks);
        return $weeks;
    }

    /**
     * Validates the shipping payload before order creation.
     *
     * For `home` delivery: requires department/municipality/district, a resolvable shipping zone,
     * and a price configured for that zone in the product.
     * For `pickup` delivery: requires a store_id that maps to an active pickup store.
     *
     * @param int   $product_id
     * @param array $shipping
     * @return true|WP_Error
     */
    public static function validate_shipping_for_order($product_id, $shipping) {
        if (!is_array($shipping) || empty($shipping['type'])) {
            return new WP_Error('invalid_shipping', __('Datos de envío inválidos', 'cna-subscriptions'), array('status' => 400));
        }

        $type = $shipping['type'];

        if ($type === 'home') {
            foreach (array('department', 'municipality', 'district') as $field) {
                if (empty($shipping[$field])) {
                    return new WP_Error(
                        'missing_shipping_field',
                        sprintf(__('Campo de envío requerido: %s', 'cna-subscriptions'), $field),
                        array('status' => 400)
                    );
                }
            }

            $locations_helper = new CNA_Locations_Helper();
            $country = isset($shipping['country']) ? $shipping['country'] : 'El Salvador';
            $zone_id = $locations_helper->find_zone_by_location(
                $shipping['department'],
                $shipping['municipality'],
                $shipping['district'],
                $country
            );

            if (!$zone_id) {
                return new WP_Error(
                    'invalid_shipping_zone',
                    __('No se encontró una zona de envío para la ubicación seleccionada', 'cna-subscriptions'),
                    array('status' => 400)
                );
            }

            $shipping_prices = get_post_meta($product_id, '_cna_shipping_prices', true);
            if (!is_array($shipping_prices) || !isset($shipping_prices[$zone_id]) || floatval($shipping_prices[$zone_id]) <= 0) {
                return new WP_Error(
                    'shipping_price_not_configured',
                    __('El envío a domicilio no está disponible para la ubicación seleccionada', 'cna-subscriptions'),
                    array('status' => 400)
                );
            }

            return true;
        }

        if ($type === 'pickup') {
            if (empty($shipping['store_id'])) {
                return new WP_Error(
                    'missing_store_id',
                    __('Debes seleccionar una tienda para recoger tu pedido', 'cna-subscriptions'),
                    array('status' => 400)
                );
            }

            global $wpdb;
            $store = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cna_pickup_stores WHERE id = %d AND is_active = 1",
                intval($shipping['store_id'])
            ));

            if (!$store) {
                return new WP_Error(
                    'invalid_store',
                    __('La tienda seleccionada no es válida o no está disponible', 'cna-subscriptions'),
                    array('status' => 400)
                );
            }

            return true;
        }

        return new WP_Error('invalid_shipping_type', __('Tipo de envío inválido', 'cna-subscriptions'), array('status' => 400));
    }

    /**
     * @param string $message
     * @param mixed  $context
     */
    public static function debug_log($message, $context = null) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if ($context !== null) {
            error_log($message . ' ' . wp_json_encode($context));
            return;
        }

        error_log($message);
    }
}
