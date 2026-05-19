<?php
/**
 * Helper para obtener información de métodos de pago
 * Funciones auxiliares para trabajar con payment gateways
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Payment_Helper {

    /**
     * Normalizes a numeric value as a decimal string for JSON storage (avoids float noise in DB).
     *
     * @param mixed $value
     * @param int   $decimals Max decimal places to keep.
     * @return string e.g. "0.05", "0.25"
     */
    public static function format_decimal_for_storage($value, $decimals = 2) {
        if (!is_numeric($value)) {
            return '0';
        }

        $n = max(0, (float) $value);
        $formatted = number_format($n, $decimals, '.', '');

        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Parses a stored decimal string (or legacy float) for calculations.
     *
     * @param mixed $value
     * @return float
     */
    public static function parse_decimal($value) {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Obtiene un gateway por su slug
     *
     * @param string $slug Slug del gateway (ej: 'pagadito')
     * @return object|false Objeto del gateway o false si no se encuentra
     */
    public static function get_gateway($slug) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $gateway = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_payment_gateways WHERE slug = %s",
            $slug
        ));

        if (!$gateway) {
            return false;
        }

        // Decodificar settings JSON con soporte UTF-8
        if (!empty($gateway->settings_json)) {
            $gateway->settings = json_decode($gateway->settings_json, true, 512, JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $gateway->settings = json_decode($gateway->settings_json, true);
            }
        } else {
            $gateway->settings = array();
        }

        return $gateway;
    }

    /**
     * Obtiene el gateway activo (el primero que encuentre activo)
     *
     * @return object|false Objeto del gateway o false si no hay ninguno activo
     */
    public static function get_active_gateway() {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $gateway = $wpdb->get_row(
            "SELECT * FROM {$table_prefix}cna_payment_gateways WHERE is_active = 1 LIMIT 1"
        );

        if (!$gateway) {
            return false;
        }

        // Decodificar settings JSON con soporte UTF-8
        if (!empty($gateway->settings_json)) {
            $gateway->settings = json_decode($gateway->settings_json, true, 512, JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $gateway->settings = json_decode($gateway->settings_json, true);
            }
        } else {
            $gateway->settings = array();
        }

        return $gateway;
    }

    /**
     * Obtiene la configuración de Pagadito (compatibilidad hacia atrás)
     * 
     * @return array Array con uid, wsk, sandbox, fee
     */
    public static function get_pagadito_config() {
        $gateway = self::get_gateway('pagadito');
        
        if (!$gateway || !$gateway->is_active) {
            // Retornar valores por defecto si no está configurado
            return array(
                'uid' => '',
                'wsk' => '',
                'sandbox' => true,
                'fee' => '0.06',
                'fee_fixed' => '0',
            );
        }

        // Prefer the encrypted WSK (wsk_enc); fall back to plaintext for pre-migration rows.
        $wsk = '';
        if (!empty($gateway->settings['wsk_enc']) && class_exists('CNA_Token_Encryption')) {
            $decrypted = CNA_Token_Encryption::decrypt($gateway->settings['wsk_enc']);
            $wsk = $decrypted !== false ? $decrypted : '';
        }
        if ($wsk === '' && !empty($gateway->settings['wsk'])) {
            $wsk = $gateway->settings['wsk'];
        }

        return array(
            'uid' => $gateway->settings['uid'] ?? '',
            'wsk' => $wsk,
            'sandbox' => $gateway->settings['sandbox'] ?? true,
            'fee' => self::format_decimal_for_storage($gateway->settings['fee'] ?? '0.06', 4),
            'fee_fixed' => self::format_decimal_for_storage($gateway->settings['fee_fixed'] ?? '0', 2),
        );
    }

    /**
     * Obtiene el fee porcentual de la pasarela activa
     *
     * @return float Fee como decimal (ej: 0.05 para 5%)
     */
    public static function get_gateway_fee() {
        $gateway = self::get_active_gateway();

        if (!$gateway) {
            return 0.06;
        }

        if ($gateway->slug === 'pagadito') {
            return self::parse_decimal($gateway->settings['fee'] ?? '0.06');
        }

        return 0.06;
    }

    /**
     * Obtiene el fee fijo de la pasarela activa (por transacción)
     *
     * @return float Monto fijo en la moneda de la tienda (ej: 0.25)
     */
    public static function get_gateway_fee_fixed() {
        $gateway = self::get_active_gateway();

        if (!$gateway) {
            return 0.0;
        }

        if ($gateway->slug === 'pagadito') {
            return self::parse_decimal($gateway->settings['fee_fixed'] ?? '0');
        }

        return 0.0;
    }

    /**
     * Calcula el total a cobrar para recibir exactamente el monto neto tras comisiones.
     * Pagadito retiene: (total * fee_percent) + fee_fixed
     * Comercio recibe: total - (total * fee_percent) - fee_fixed = net_amount
     *
     * @param float $net_amount Monto que debe recibir el comercio
     * @return array{fee_percent: float, fee_fixed: float, net_amount: float, fee_amount: float, total_with_fee: float}|WP_Error
     */
    public static function calculate_gateway_totals($net_amount) {
        $fee_percent = self::get_gateway_fee();
        $fee_fixed = self::get_gateway_fee_fixed();
        $net_amount = floatval($net_amount);

        if ($fee_percent >= 1 || $fee_percent < 0) {
            return new WP_Error(
                'invalid_gateway_fee',
                __('Configuración de fee de pasarela inválida', 'cna-subscriptions')
            );
        }

        if ($fee_fixed < 0) {
            return new WP_Error(
                'invalid_gateway_fee_fixed',
                __('Configuración de fee fijo de pasarela inválida', 'cna-subscriptions')
            );
        }

        if ($net_amount <= 0) {
            return array(
                'fee_percent' => $fee_percent,
                'fee_fixed' => $fee_fixed,
                'net_amount' => 0.0,
                'fee_amount' => 0.0,
                'total_with_fee' => 0.0,
            );
        }

        $total_with_fee = round(($net_amount + $fee_fixed) / (1 - $fee_percent), 2);
        $fee_amount = round($total_with_fee - $net_amount, 2);

        return array(
            'fee_percent' => $fee_percent,
            'fee_fixed' => $fee_fixed,
            'net_amount' => $net_amount,
            'fee_amount' => $fee_amount,
            'total_with_fee' => $total_with_fee,
        );
    }
}
