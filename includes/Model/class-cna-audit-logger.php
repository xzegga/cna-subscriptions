<?php
/**
 * Sistema de Logging de Auditoría
 * Registra todas las transacciones financieras y acciones críticas
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Audit_Logger {

    /**
     * Tipos de eventos de auditoría
     */
    const EVENT_ORDER_CREATED = 'order_created';
    const EVENT_PAYMENT_SUCCESS = 'payment_success';
    const EVENT_PAYMENT_FAILED = 'payment_failed';
    const EVENT_RENEWAL_SUCCESS = 'renewal_success';
    const EVENT_RENEWAL_FAILED = 'renewal_failed';
    const EVENT_SUBSCRIPTION_ACTIVATED = 'subscription_activated';
    const EVENT_SUBSCRIPTION_CANCELLED = 'subscription_cancelled';
    const EVENT_TOKEN_STORED = 'token_stored';
    const EVENT_TOKEN_USED = 'token_used';
    const EVENT_WEBHOOK_RECEIVED = 'webhook_received';
    const EVENT_AMOUNT_CALCULATED = 'amount_calculated';

    /**
     * Niveles de severidad
     */
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Registra un evento de auditoría
     *
     * @param string $event_type Tipo de evento (constante de la clase)
     * @param array $data Datos del evento
     * @param string $severity Nivel de severidad
     * @return int|false ID del log insertado o false en caso de error
     */
    public static function log($event_type, $data = array(), $severity = self::SEVERITY_MEDIUM) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Sanitizar datos antes de guardar (no guardar tokens completos, passwords, etc.)
        $sanitized_data = self::sanitize_audit_data($data);

        $log_entry = array(
            'event_type' => sanitize_key($event_type),
            'severity' => sanitize_key($severity),
            'user_id' => get_current_user_id(),
            'ip_address' => self::get_client_ip(),
            'data_json' => wp_json_encode($sanitized_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => current_time('mysql'),
        );

        $result = $wpdb->insert(
            $table_prefix . 'cna_audit_logs',
            $log_entry,
            array('%s', '%s', '%d', '%s', '%s', '%s')
        );

        if ($result) {
            $log_id = $wpdb->insert_id;
            
            // Si es un evento crítico, también loguear en error_log
            if ($severity === self::SEVERITY_CRITICAL) {
                error_log(sprintf(
                    'CNA Audit [CRITICAL] %s: %s',
                    $event_type,
                    wp_json_encode($sanitized_data)
                ));
            }

            return $log_id;
        }

        return false;
    }

    /**
     * Sanitiza datos de auditoría removiendo información sensible
     *
     * @param array $data
     * @return array
     */
    private static function sanitize_audit_data($data) {
        if (!is_array($data)) {
            return array();
        }

        $sanitized = array();
        $sensitive_keys = array('token', 'wsk', 'password', 'secret', 'key', 'api_key', 'private_key');

        foreach ($data as $key => $value) {
            $key_lower = strtolower($key);
            
            // Si es un campo sensible, solo guardar los primeros caracteres
            $is_sensitive = false;
            foreach ($sensitive_keys as $sensitive) {
                if (strpos($key_lower, $sensitive) !== false) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ($is_sensitive && is_string($value) && strlen($value) > 8) {
                $sanitized[$key] = substr($value, 0, 4) . '...' . substr($value, -4);
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitize_audit_data($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Obtiene la IP real del cliente
     *
     * @return string
     */
    private static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field($_SERVER[$key]);
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Obtiene logs de auditoría con filtros opcionales
     *
     * @param array $args Argumentos de filtro
     * @return array
     */
    public static function get_logs($args = array()) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $defaults = array(
            'event_type' => '',
            'severity' => '',
            'user_id' => 0,
            'subscription_id' => 0,
            'limit' => 100,
            'offset' => 0,
            'order_by' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $prepare_values = array();

        if (!empty($args['event_type'])) {
            $where[] = 'event_type = %s';
            $prepare_values[] = $args['event_type'];
        }

        if (!empty($args['severity'])) {
            $where[] = 'severity = %s';
            $prepare_values[] = $args['severity'];
        }

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $prepare_values[] = intval($args['user_id']);
        }

        if (!empty($args['subscription_id'])) {
            // Buscar en data_json
            $where[] = 'data_json LIKE %s';
            $prepare_values[] = '%"subscription_id":' . intval($args['subscription_id']) . '%';
        }

        $where_clause = implode(' AND ', $where);
        $order_by = sanitize_sql_orderby($args['order_by'] . ' ' . $args['order']);
        $limit = intval($args['limit']);
        $offset = intval($args['offset']);

        $query = "SELECT * FROM {$table_prefix}cna_audit_logs 
                  WHERE {$where_clause} 
                  ORDER BY {$order_by} 
                  LIMIT %d OFFSET %d";

        $prepare_values[] = $limit;
        $prepare_values[] = $offset;

        if (!empty($prepare_values)) {
            $query = $wpdb->prepare($query, $prepare_values);
        }

        return $wpdb->get_results($query);
    }

    /**
     * Limpia logs antiguos (más de X días)
     *
     * @param int $days Días a mantener
     * @return int Número de registros eliminados
     */
    public static function clean_old_logs($days = 365) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_prefix}cna_audit_logs WHERE created_at < %s",
            $date_threshold
        ));

        return $deleted;
    }
}
