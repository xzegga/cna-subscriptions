<?php
/**
 * Normaliza y persiste datos de transacción de pasarelas de pago.
 *
 * @package CNA_Subscriptions
 * @since 1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Payment_Transaction {

    /**
     * Etiquetas de proveedores conocidos.
     *
     * @var array<string, string>
     */
    private static $provider_labels = array(
        'pagadito' => 'Pagadito',
    );

    /**
     * Guarda datos de transacción a partir del webhook o respuesta de pago.
     *
     * @param int    $subscription_id
     * @param string $provider_slug   ej: pagadito
     * @param array  $payload         Datos crudos del webhook
     * @return bool
     */
    public static function save_from_webhook($subscription_id, $provider_slug, array $payload) {
        $normalized = self::normalize($provider_slug, $payload);
        if (empty($normalized['fields'])) {
            return false;
        }

        return self::persist($subscription_id, $normalized);
    }

    /**
     * Obtiene filas listas para mostrar en admin.
     *
     * @param object $subscription Fila de cna_subscriptions
     * @return array{provider: string, provider_label: string, fields: array<int, array{label: string, value: string}>}
     */
    public static function get_display_data($subscription) {
        $empty = array(
            'provider' => '',
            'provider_label' => '',
            'fields' => array(),
        );

        if (empty($subscription->payment_transaction_json)) {
            return $empty;
        }

        $data = json_decode($subscription->payment_transaction_json, true);
        if (!is_array($data) || empty($data['fields'])) {
            return $empty;
        }

        return array(
            'provider' => $data['provider'] ?? '',
            'provider_label' => $data['provider_label'] ?? self::get_provider_label($data['provider'] ?? ''),
            'fields' => $data['fields'],
        );
    }

    /**
     * @param string $provider_slug
     * @param array  $payload
     * @return array
     */
    public static function normalize($provider_slug, array $payload) {
        $provider_slug = sanitize_key($provider_slug);

        switch ($provider_slug) {
            case 'pagadito':
                return self::normalize_pagadito($payload);
            default:
                return self::normalize_generic($provider_slug, $payload);
        }
    }

    /**
     * Aplana el JSON oficial del webhook Pagadito (evento + resource).
     *
     * @see https://dev.pagadito.com/index.php?mod=docs&hac=mostrar&tema=webhooks#events
     * @param array $payload
     * @return array
     */
    public static function flatten_pagadito_webhook(array $payload) {
        $resource = isset($payload['resource']) && is_array($payload['resource'])
            ? $payload['resource']
            : $payload;

        $flat = array_merge($payload, $resource);

        if (!empty($payload['id'])) {
            $flat['event_id'] = $payload['id'];
        }
        if (!empty($payload['event_type'])) {
            $flat['event_type'] = $payload['event_type'];
        }
        if (!empty($payload['event_create_timestamp'])) {
            $flat['event_create_timestamp'] = $payload['event_create_timestamp'];
        }

        if (isset($resource['amount']) && is_array($resource['amount'])) {
            if (isset($resource['amount']['total'])) {
                $flat['amount'] = $resource['amount']['total'];
            }
            if (isset($resource['amount']['currency'])) {
                $flat['currency'] = $resource['amount']['currency'];
            }
        }

        return $flat;
    }

    /**
     * @param array $payload
     * @return array
     */
    private static function normalize_pagadito(array $payload) {
        $payload = self::flatten_pagadito_webhook($payload);

        // Fecha/hora: update_timestamp = momento del pago; create_timestamp = registro inicial
        $datetime = self::pick_scalar($payload, array(
            'update_timestamp',
            'create_timestamp',
            'event_create_timestamp',
            'datetime',
            'date_trans',
        ));

        $date = '';
        $hour = '';

        if (!empty($datetime)) {
            $parsed = self::parse_datetime($datetime);
            $date = $parsed['date'];
            $hour = $parsed['hour'];
        }

        // Número de aprobación PG = resource.reference (documentación oficial)
        $approval = self::pick_scalar($payload, array(
            'reference',
            'referencia',
        ));

        // Tipo: event_type del webhook; estado en resource.status
        $event_type = self::pick_scalar($payload, array('event_type'));
        $status = self::pick_scalar($payload, array('status', 'estado'));

        $currency = self::pick_scalar($payload, array(
            'currency',
            'moneda',
        ));

        $ern = self::pick_scalar($payload, array('ern'));

        $fields = array();

        if ($date !== '') {
            $fields[] = array(
                'key' => 'date',
                'label' => __('Fecha', 'cna-subscriptions'),
                'value' => $date,
            );
        }

        if ($hour !== '') {
            $fields[] = array(
                'key' => 'hour',
                'label' => __('Hora', 'cna-subscriptions'),
                'value' => $hour,
            );
        }

        if ($approval !== '') {
            $fields[] = array(
                'key' => 'approval_number',
                'label' => __('Número de aprobación PG', 'cna-subscriptions'),
                'value' => $approval,
            );
        }

        if ($event_type !== '') {
            $fields[] = array(
                'key' => 'event_type',
                'label' => __('Tipo de evento', 'cna-subscriptions'),
                'value' => $event_type,
            );
        }

        if ($status !== '') {
            $fields[] = array(
                'key' => 'transaction_status',
                'label' => __('Estado de transacción', 'cna-subscriptions'),
                'value' => self::humanize_transaction_type($status),
            );
        }

        if ($ern !== '') {
            $fields[] = array(
                'key' => 'ern',
                'label' => __('Referencia de orden (ERN)', 'cna-subscriptions'),
                'value' => $ern,
            );
        }

        if ($currency !== '') {
            $fields[] = array(
                'key' => 'currency',
                'label' => __('Tipo de moneda digital', 'cna-subscriptions'),
                'value' => strtoupper($currency),
            );
        }

        $amount = self::pick_scalar($payload, array('amount', 'monto', 'total'));
        if ($amount !== '') {
            $fields[] = array(
                'key' => 'amount',
                'label' => __('Monto cobrado', 'cna-subscriptions'),
                'value' => is_numeric($amount) ? '$' . number_format((float) $amount, 2) : $amount,
            );
        }

        return array(
            'provider' => 'pagadito',
            'provider_label' => self::get_provider_label('pagadito'),
            'saved_at' => current_time('mysql'),
            'reference' => $approval,
            'fields' => $fields,
            'raw' => self::sanitize_raw_payload($payload),
        );
    }

    /**
     * @param string $provider_slug
     * @param array  $payload
     * @return array
     */
    private static function normalize_generic($provider_slug, array $payload) {
        $fields = array();
        $skip = array('token', 'wsk', 'custom_params');

        foreach ($payload as $key => $value) {
            if (in_array($key, $skip, true) || is_array($value) || is_object($value)) {
                continue;
            }
            $fields[] = array(
                'key' => sanitize_key($key),
                'label' => ucwords(str_replace('_', ' ', sanitize_key($key))),
                'value' => sanitize_text_field((string) $value),
            );
        }

        return array(
            'provider' => $provider_slug,
            'provider_label' => self::get_provider_label($provider_slug),
            'saved_at' => current_time('mysql'),
            'reference' => self::pick_scalar($payload, array('transaction_id', 'reference', 'id')),
            'fields' => $fields,
            'raw' => self::sanitize_raw_payload($payload),
        );
    }

    /**
     * @param int   $subscription_id
     * @param array $normalized
     * @return bool
     */
    private static function persist($subscription_id, array $normalized) {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'cna_subscriptions',
            array(
                'payment_transaction_json' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE),
            ),
            array('id' => $subscription_id),
            array('%s'),
            array('%d')
        );

        return $updated !== false;
    }

    /**
     * @param array $payload
     * @param array $keys
     * @return string
     */
    private static function pick_scalar(array $payload, array $keys) {
        foreach ($keys as $key) {
            if (!isset($payload[$key])) {
                continue;
            }
            $value = $payload[$key];
            if (is_scalar($value) && $value !== '') {
                return sanitize_text_field((string) $value);
            }
        }
        return '';
    }

    /**
     * @param string $datetime
     * @return array{date: string, hour: string}
     */
    private static function parse_datetime($datetime) {
        $result = array('date' => '', 'hour' => '');
        $timestamp = strtotime($datetime);
        if ($timestamp) {
            $result['date'] = wp_date('d/m/Y', $timestamp);
            $result['hour'] = wp_date('H:i', $timestamp);
        }
        return $result;
    }

    /**
     * @param string $type
     * @return string
     */
    private static function humanize_transaction_type($type) {
        $map = array(
            'payment_to_accredit' => __('Pago para acreditar', 'cna-subscriptions'),
            'payment to accredit' => __('Pago para acreditar', 'cna-subscriptions'),
            'completed' => __('Pago para acreditar', 'cna-subscriptions'),
            'verified' => __('Verificado', 'cna-subscriptions'),
            'rejected' => __('Rechazado', 'cna-subscriptions'),
            'expired' => __('Expirado', 'cna-subscriptions'),
            'approved' => __('Aprobado', 'cna-subscriptions'),
            'success' => __('Exitoso', 'cna-subscriptions'),
        );

        $key = strtolower(trim($type));
        return $map[$key] ?? $type;
    }

    /**
     * @param string $slug
     * @return string
     */
    private static function get_provider_label($slug) {
        return self::$provider_labels[$slug] ?? ucfirst($slug);
    }

    /**
     * @param array $payload
     * @return array
     */
    private static function sanitize_raw_payload(array $payload) {
        $sanitized = array();
        foreach ($payload as $key => $value) {
            $key = sanitize_key($key);
            if (in_array($key, array('token', 'wsk'), true)) {
                continue;
            }
            if (is_scalar($value)) {
                $sanitized[$key] = sanitize_text_field((string) $value);
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitize_raw_payload($value);
            }
        }
        return $sanitized;
    }
}
