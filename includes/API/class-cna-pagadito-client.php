<?php
/**
 * Cliente de Pagadito API
 * Maneja la comunicación con Pagadito usando APIPG (REST API)
 *
 * Basado en la documentación: https://dev.pagadito.com/index.php?mod=docs
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Pagadito_APIPG')) {
    class Pagadito_APIPG
    {
        const API_URL_PRODUCTION = 'https://comercios.pagadito.com/apipg/charges.php';
        const API_URL_SANDBOX = 'https://sandbox.pagadito.com/comercios/apipg/charges.php';

        private $uid;
        private $wsk;
        private $apipg;
        private $apipg_sandbox;
        private $format_return;
        private $response;
        private $sandbox_mode;
        private $op_connect_key;
        private $op_exec_trans_key;
        private $op_get_status_key;
        private $details;
        private $custom_params;
        private $currency;
        private $allow_pending_payments;
        private $return_url;
        private $cancel_url;
        private $last_http_response;

        public function __construct($uid, $wsk)
        {
            $this->uid = $uid;
            $this->wsk = $wsk;
            $this->config();
        }

        private function config()
        {
            $this->apipg = self::API_URL_PRODUCTION;
            $this->apipg_sandbox = self::API_URL_SANDBOX;
            $this->format_return = 'json';
            $this->sandbox_mode = false;
            $this->op_connect_key = 'f3f191ce3326905ff4403bb05b0de150';
            $this->op_exec_trans_key = '41216f8caf94aaa598db137e36d4673e';
            $this->op_get_status_key = '0b50820c65b0de71ce78f6221a5cf876';
            $this->details = array();
            $this->custom_params = array();
            $this->currency = 'USD';
            $this->allow_pending_payments = 'false';
            $this->return_url = '';
            $this->cancel_url = '';
            $this->last_http_response = array(
                'status_code' => null,
                'body' => null,
                'headers' => array(),
                'error' => null,
            );
        }

        private function get_api_url()
        {
            return $this->sandbox_mode ? $this->apipg_sandbox : $this->apipg;
        }

        public function mode_sandbox_on()
        {
            $this->sandbox_mode = true;
        }

        public function change_format_json()
        {
            $this->format_return = 'json';
        }

        public function change_currency_usd()
        {
            $this->currency = 'USD';
        }

        public function change_currency($currency)
        {
            $currency = strtoupper(sanitize_text_field($currency));
            $allowed = array('USD', 'GTQ', 'HNL', 'NIO', 'CRC', 'PAB', 'DOP');
            if (in_array($currency, $allowed, true)) {
                $this->currency = $currency;
            }
        }

        public function connect()
        {
            $params = array(
                'operation' => $this->op_connect_key,
                'uid' => $this->uid,
                'wsk' => $this->wsk,
                'format_return' => $this->format_return,
            );

            $this->response = $this->call($params);
            return $this->get_rs_code() === 'PG1001';
        }

        public function exec_trans($ern)
        {
            if ($this->get_rs_code() !== 'PG1001') {
                return array(
                    'success' => false,
                    'code' => 'PG1001_NOT_CONNECTED',
                    'message' => 'No se pudo establecer conexión con Pagadito',
                    'ern' => $ern,
                );
            }

            // === DEBUG: Log detallado para desarrolladores de Pagadito ===
            if (function_exists('error_log')) {
                error_log('====== PAGADITO DEBUG START ======');
                error_log('CNA Pagadito: Raw details array BEFORE json_encode:');
                error_log(print_r($this->details, true));
                error_log('CNA Pagadito: Raw custom_params array BEFORE json_encode:');
                error_log(print_r($this->custom_params, true));
            }

            // Seguir el formato exacto del SDK oficial (ver docs APIPG)
            $details_json = json_encode($this->details, JSON_UNESCAPED_UNICODE);
            $custom_params_json = json_encode($this->custom_params, JSON_UNESCAPED_UNICODE);

            if (function_exists('error_log')) {
                error_log('CNA Pagadito: details JSON (with JSON_UNESCAPED_UNICODE): ' . $details_json);
                error_log('CNA Pagadito: details JSON hex dump: ' . bin2hex($details_json));
                error_log('CNA Pagadito: custom_params JSON: ' . $custom_params_json);
                error_log('CNA Pagadito: Calculated amount: ' . $this->calc_amount());
            }

            $params = array(
                'operation' => $this->op_exec_trans_key,
                'token' => $this->get_rs_value(),
                'ern' => $ern,
                // Monto siempre con 2 decimales como string
                'amount' => number_format($this->calc_amount(), 2, '.', ''),
                // IMPORTANTE: JSON_UNESCAPED_UNICODE para que ó no sea \u00f3
                'details' => $details_json,
                'custom_params' => $custom_params_json,
                'currency' => $this->currency,
                'format_return' => $this->format_return,
                'allow_pending_payments' => $this->allow_pending_payments,
            );

            if (!empty($this->return_url)) {
                $params['return_url'] = $this->return_url;
            }

            if (!empty($this->cancel_url)) {
                $params['cancel_url'] = $this->cancel_url;
            }

            if (function_exists('error_log')) {
                error_log('CNA Pagadito: All params BEFORE call():');
                error_log(print_r($params, true));
            }

            $this->response = $this->call($params);
            $response_code = $this->get_rs_code();
            $response_message = $this->get_rs_message();
            $response_value = $this->get_rs_value();

            $result = array(
                'code' => $response_code,
                'message' => $response_message,
                'value' => $response_value,
                'raw' => $this->response,
            );

            if ($response_code === 'PG1002') {
                if (function_exists('error_log')) {
                    error_log('CNA Pagadito: SUCCESS PG1002 - Transaction created');
                    error_log('CNA Pagadito: Redirect URL: ' . urldecode($response_value));
                    error_log('====== PAGADITO DEBUG END (SUCCESS) ======');
                }
                return array_merge($result, array(
                    'success' => true,
                    'redirect_url' => urldecode($response_value),
                    'token' => $response_value,
                    'ern' => $ern,
                ));
            }

            // Log auxiliar para depurar errores de formato (PG2002, etc.)
            if (function_exists('error_log')) {
                error_log('CNA Pagadito: ERROR ' . $response_code . ': ' . $response_message);
                error_log('CNA Pagadito: HTTP Response Code: ' . $this->last_http_response['status_code']);
                error_log('CNA Pagadito: HTTP Response Body: ' . $this->last_http_response['body']);
                error_log('====== PAGADITO DEBUG END (ERROR) ======');
            }

            return array_merge(array('success' => false), $result);
        }

        public function set_return_url($url)
        {
            $this->return_url = esc_url_raw($url);
        }

        public function set_cancel_url($url)
        {
            $this->cancel_url = esc_url_raw($url);
        }

        public function get_status($token_trans)
        {
            if ($this->get_rs_code() !== 'PG1001') {
                return false;
            }

            $params = array(
                'operation' => $this->op_get_status_key,
                'token' => $this->get_rs_value(),
                'token_trans' => sanitize_text_field($token_trans),
                'format_return' => $this->format_return,
            );

            $this->response = $this->call($params);
            return $this->get_rs_code() === 'PG1003';
        }

        public function add_detail($quantity, $description, $price, $url_product = '')
        {
            // Pagadito requiere que el precio tenga máximo 2 decimales.
            // Enviar el precio como string formateado evita problemas de precisión binaria.
            $formatted_price = number_format(floatval($price), 2, '.', '');
            $this->details[] = array(
                'quantity' => max(1, intval($quantity)),
                'description' => sanitize_text_field($description),
                'price' => $formatted_price, // string "108.51"
                'url_product' => sanitize_text_field($url_product),
            );
        }

        public function set_custom_param($code, $value)
        {
            $code = sanitize_key($code);
            $this->custom_params[$code] = sanitize_text_field($value);
        }

        public function enable_pending_payments()
        {
            $this->allow_pending_payments = 'true';
        }

        private function call($params)
        {
            $endpoint = $this->get_api_url();

            if (empty($endpoint)) {
                $this->last_http_response = array(
                    'status_code' => null,
                    'body' => null,
                    'headers' => array(),
                    'error' => __('Endpoint no configurado', 'cna-subscriptions'),
                );
                return null;
            }

            // Replicar el comportamiento exacto de la clase oficial Pagadito:
            // format_post_vars() codifica cada valor con urlencode(), incluyendo caracteres especiales como [ ]
            $body = $this->format_post_vars($params);

            if (function_exists('error_log')) {
                error_log('CNA Pagadito HTTP Request:');
                error_log('  Endpoint: ' . $endpoint);
                error_log('  Method: POST');
                error_log('  Content-Type: application/x-www-form-urlencoded');
                error_log('  Body (URL-encoded): ' . $body);
                error_log('  Body length: ' . strlen($body) . ' bytes');
            }

            $response = wp_remote_post($endpoint, array(
                'body' => $body,
                'timeout' => 30,
                'sslverify' => true,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
            ));

            if (is_wp_error($response)) {
                $this->last_http_response = array(
                    'status_code' => null,
                    'body' => null,
                    'headers' => array(),
                    'error' => $response->get_error_message(),
                );
                if (function_exists('error_log')) {
                    error_log('CNA Pagadito HTTP Error: ' . $response->get_error_message());
                }
                return null;
            }

            $body = wp_remote_retrieve_body($response);
            $status_code = wp_remote_retrieve_response_code($response);
            $response_headers = wp_remote_retrieve_headers($response);

            $this->last_http_response = array(
                'status_code' => $status_code,
                'body' => $body,
                'headers' => (array) $response_headers,
                'error' => null,
            );

            if (function_exists('error_log')) {
                error_log('CNA Pagadito HTTP Response:');
                error_log('  Status Code: ' . $status_code);
                error_log('  Body: ' . $body);
                error_log('  Body length: ' . strlen($body) . ' bytes');
                error_log('  Content-Type: ' . ($response_headers['content-type'] ?? 'not set'));
            }

            return $this->decode_response($body);
        }

        /**
         * Formatea variables POST exactamente como el SDK oficial de Pagadito.
         * Esto es crítico para que los datos lleguen en el formato correcto a Pagadito.
         * 
         * @param array $vars Variables a formatear
         * @return string Variables formateadas para POST
         */
        private function format_post_vars($vars)
        {
            $formatted_vars = '';

            if (function_exists('error_log')) {
                error_log('CNA Pagadito format_post_vars encoding each parameter:');
            }

            foreach ($vars as $key => $value) {
                $original_value = $value;
                // Convertir a string si no lo es (por ejemplo, booleanos)
                $value = (string) $value;
                $encoded_value = urlencode($value);

                if (function_exists('error_log')) {
                    error_log('  ' . $key . ':');
                    error_log('    Original: ' . substr($original_value, 0, 200) . (strlen($original_value) > 200 ? '...' : ''));
                    error_log('    String: ' . substr($value, 0, 200) . (strlen($value) > 200 ? '...' : ''));
                    error_log('    URL-encoded: ' . substr($encoded_value, 0, 200) . (strlen($encoded_value) > 200 ? '...' : ''));
                    if ($key === 'details' || $key === 'custom_params') {
                        error_log('    Hex dump: ' . substr(bin2hex($value), 0, 200));
                    }
                }

                $formatted_vars .= $key . '=' . $encoded_value . '&';
            }
            $formatted_vars = rtrim($formatted_vars, '&');

            if (function_exists('error_log')) {
                error_log('CNA Pagadito format_post_vars FINAL output:');
                error_log('  Length: ' . strlen($formatted_vars) . ' bytes');
                error_log('  Full string: ' . $formatted_vars);
            }

            return $formatted_vars;
        }

        private function calc_amount()
        {
            $amount = 0;
            foreach ($this->details as $detail) {
                $amount += floatval($detail['quantity']) * floatval($detail['price']);
            }
            // Redondear a 2 decimales (evitar problemas de precisión de punto flotante)
            return round($amount, 2);
        }

        private function decode_response($response)
        {
            switch ($this->format_return) {
                case 'php':
                    return unserialize($response);
                case 'xml':
                    return simplexml_load_string($response);
                case 'json':
                default:
                    return json_decode($response);
            }
        }

        private function return_attr_response($attr)
        {
            if (is_object($this->response) && property_exists($this->response, $attr)) {
                return $this->response->$attr;
            }
            return null;
        }

        private function return_attr_value($attr)
        {
            if (!$this->return_attr_response('value')) {
                return null;
            }

            switch ($this->format_return) {
                case 'json':
                    if (is_object($this->response->value) && property_exists($this->response->value, $attr)) {
                        return $this->response->value->$attr;
                    }
                    break;
                case 'php':
                    if (is_array($this->response->value) && array_key_exists($attr, $this->response->value)) {
                        return $this->response->value[$attr];
                    }
                    break;
                case 'xml':
                    if (is_object($this->response->value) && property_exists($this->response->value, $attr)) {
                        return $this->response->value->$attr;
                    }
                    break;
            }

            return null;
        }

        public function get_rs_code()
        {
            return $this->return_attr_response('code');
        }

        public function get_rs_message()
        {
            return $this->return_attr_response('message');
        }

        public function get_rs_value()
        {
            return $this->return_attr_response('value');
        }

        public function get_last_http_response()
        {
            return $this->last_http_response;
        }
    }
}

class CNA_Pagadito_Client
{
    const CHARGE_TOKEN_PRODUCTION = 'https://comercios.pagadito.com/apipg/charge_token.php';
    const CHARGE_TOKEN_SANDBOX = 'https://sandbox.pagadito.com/apipg/charge_token.php';
    const STATUS_URL_PRODUCTION = 'https://comercios.pagadito.com/apipg/status.php';
    const STATUS_URL_SANDBOX = 'https://sandbox.pagadito.com/apipg/status.php';

    private $uid;
    private $wsk;
    private $sandbox;

    public function __construct($uid = null, $wsk = null, $sandbox = null)
    {
        $config = CNA_Payment_Helper::get_pagadito_config();
        $this->uid = $uid ?: $config['uid'];
        $this->wsk = $wsk ?: $config['wsk'];
        $this->sandbox = $sandbox !== null ? (bool) $sandbox : (bool) $config['sandbox'];
    }

    public function create_transaction($data)
    {
        return $this->create_tokenized_transaction($data);
    }

    public function create_tokenized_transaction($data)
    {
        $client = $this->build_client();
        if (is_wp_error($client)) {
            return $client;
        }

        $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
        if ($amount <= 0) {
            return new WP_Error(
                'missing_amount',
                __('Monto inválido para la transacción', 'cna-subscriptions')
            );
        }

        $quantity = max(1, isset($data['qty']) ? intval($data['qty']) : (isset($data['quantity']) ? intval($data['quantity']) : 1));
        $description = isset($data['description']) ? sanitize_text_field($data['description']) : '';
        $currency = isset($data['currency']) ? $data['currency'] : 'USD';
        $ern = isset($data['ern']) ? sanitize_text_field($data['ern']) : null;

        $client->change_format_json();
        $client->change_currency($currency);

        // IMPORTANTE: Agregar cantidad=1 con el monto total, para que Pagadito calcule correctamente
        // No pasar la cantidad multiplicada por el precio en un único detalle
        $client->add_detail(1, $description, $amount);

        $custom_params = is_array($data['custom_params'] ?? null) ? $data['custom_params'] : array();
        // Pagadito requiere que custom_params use param1, param2, param3
        // Si no están en custom_params, intentar obtenerlos de los campos directos
        if (!isset($custom_params['param1']) && isset($data['subscription_id'])) {
            $custom_params['param1'] = $data['subscription_id'];
        }
        if (!isset($custom_params['param2']) && isset($data['product_id'])) {
            $custom_params['param2'] = $data['product_id'];
        }
        if (!isset($custom_params['param3']) && isset($data['user_id'])) {
            $custom_params['param3'] = $data['user_id'];
        }

        foreach ($custom_params as $key => $value) {
            if ($value !== '') {
                $client->set_custom_param($key, $value);
            }
        }

        $subscription_id = isset($data['subscription_id']) ? intval($data['subscription_id']) : 0;
        if ($subscription_id > 0) {
            $return_base = rest_url('cna/v1/payment-return');
            $client->set_return_url(
                add_query_arg(array('subscription_id' => $subscription_id), $return_base)
            );
            $client->set_cancel_url(
                add_query_arg(
                    array(
                        'subscription_id' => $subscription_id,
                        'status' => 'cancelled',
                    ),
                    $return_base
                )
            );
        }

        if (!$client->connect()) {
            return new WP_Error(
                'pagadito_connection_failed',
                __('No se pudo establecer conexión con Pagadito', 'cna-subscriptions'),
                array('http' => $client->get_last_http_response())
            );
        }

        $ern = $ern ?: (string) ($data['subscription_id'] ?? uniqid('cna_', true));
        $response = $client->exec_trans(sanitize_text_field($ern));

        if (empty($response['success'])) {
            return new WP_Error(
                'pagadito_api_error',
                $response['message'] ?? __('Error al comunicarse con Pagadito', 'cna-subscriptions'),
                array(
                    'response' => $response,
                    'http' => $client->get_last_http_response(),
                )
            );
        }

        return $response;
    }

    public function charge_with_token($token, $amount, $description = '', $custom_params = array())
    {
        $params = array(
            'uid' => $this->uid,
            'wsk' => $this->wsk,
            'token' => sanitize_text_field($token),
            'amount' => number_format($amount, 2, '.', ''),
            'description' => sanitize_text_field($description),
        );

        if (!empty($custom_params) && is_array($custom_params)) {
            foreach ($custom_params as $key => $value) {
                $params['custom_' . sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        $charge_url = $this->sandbox ? self::CHARGE_TOKEN_SANDBOX : self::CHARGE_TOKEN_PRODUCTION;

        $response = wp_remote_post($charge_url, array(
            'body' => $params,
            'timeout' => 30,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $result = json_decode($body, true);

        if ($status_code !== 200 || !$result) {
            return new WP_Error(
                'pagadito_token_charge_error',
                __('Error al realizar cobro con token', 'cna-subscriptions'),
                array('status' => $status_code, 'body' => $body)
            );
        }

        return $result;
    }

    public function get_transaction_status($transaction_id)
    {
        $params = array(
            'uid' => $this->uid,
            'wsk' => $this->wsk,
            'transaction_id' => sanitize_text_field($transaction_id),
        );

        $status_url = $this->sandbox ? self::STATUS_URL_SANDBOX : self::STATUS_URL_PRODUCTION;
        $response = wp_remote_get(add_query_arg($params, $status_url), array(
            'timeout' => 30,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    public function validate_credentials()
    {
        if (empty($this->uid) || empty($this->wsk)) {
            return new WP_Error(
                'missing_credentials',
                __('Las credenciales de Pagadito no están configuradas', 'cna-subscriptions')
            );
        }

        return true;
    }

    public static function get_payment_url($response)
    {
        if (is_wp_error($response) || !is_array($response)) {
            return false;
        }

        if (isset($response['payment_url'])) {
            return esc_url_raw($response['payment_url']);
        }

        if (isset($response['url'])) {
            return esc_url_raw($response['url']);
        }

        if (isset($response['redirect_url'])) {
            return esc_url_raw($response['redirect_url']);
        }

        return false;
    }

    public static function extract_token($response)
    {
        if (is_wp_error($response) || !is_array($response)) {
            return false;
        }

        if (isset($response['token'])) {
            return sanitize_text_field($response['token']);
        }

        if (isset($response['resource']['token'])) {
            return sanitize_text_field($response['resource']['token']);
        }

        if (isset($response['card_token'])) {
            return sanitize_text_field($response['card_token']);
        }

        return false;
    }

    private function build_client()
    {
        if (empty($this->uid) || empty($this->wsk)) {
            return new WP_Error(
                'missing_credentials',
                __('Las credenciales de Pagadito no están configuradas', 'cna-subscriptions')
            );
        }

        $client = new Pagadito_APIPG($this->uid, $this->wsk);
        if ($this->sandbox) {
            $client->mode_sandbox_on();
        }

        return $client;
    }
}
