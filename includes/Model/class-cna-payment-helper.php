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
            );
        }

        return array(
            'uid' => $gateway->settings['uid'] ?? '',
            'wsk' => $gateway->settings['wsk'] ?? '',
            'sandbox' => $gateway->settings['sandbox'] ?? true,
            'fee' => $gateway->settings['fee'] ?? '0.06',
        );
    }

    /**
     * Obtiene el fee de la pasarela activa
     *
     * @return float Fee como decimal (ej: 0.06 para 6%)
     */
    public static function get_gateway_fee() {
        $gateway = self::get_active_gateway();
        
        if (!$gateway) {
            return 0.06; // Valor por defecto
        }

        // Para Pagadito
        if ($gateway->slug === 'pagadito') {
            return floatval($gateway->settings['fee'] ?? '0.06');
        }

        // Para otros gateways, retornar 0.06 por defecto
        return 0.06;
    }
}
