<?php
/**
 * Sistema de Cron Jobs para Renovación Automática
 * Ejecuta cobros automáticos cuando las suscripciones necesitan renovarse
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Cron {

    /**
     * Hook name del cron job
     */
    const HOOK_NAME = 'cna_daily_renewal_check';

    /**
     * Inicializa el sistema de cron
     */
    public function init() {
        // Registrar el hook de cron
        add_action(self::HOOK_NAME, array($this, 'process_renewals'));

        // Programar el cron si no está programado
        if (!wp_next_scheduled(self::HOOK_NAME)) {
            // Ejecutar diariamente a las 2:00 AM
            wp_schedule_event(time(), 'daily', self::HOOK_NAME);
        }
    }

    /**
     * Procesa las renovaciones pendientes
     * Se ejecuta diariamente
     */
    public function process_renewals() {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Buscar suscripciones que necesitan renovación hoy
        $today = current_time('Y-m-d');
        
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions 
             WHERE status = 'active' 
             AND is_auto_renew = 1 
             AND next_renewal_date = %s
             AND pagadito_token IS NOT NULL
             AND pagadito_token != ''",
            $today
        ));

        if (empty($subscriptions)) {
            error_log('CNA Cron: No hay suscripciones para renovar hoy');
            return;
        }

        error_log(sprintf('CNA Cron: Procesando %d suscripciones para renovar', count($subscriptions)));

        foreach ($subscriptions as $subscription) {
            $this->process_single_renewal($subscription);
        }
    }

    /**
     * Procesa la renovación de una suscripción individual
     *
     * @param object $subscription Suscripción de la BD
     */
    private function process_single_renewal($subscription) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        error_log(sprintf('CNA Cron: Procesando renovación para suscripción #%d', $subscription->id));

        // Obtener detalles del producto y variante
        // Decodificar JSON con soporte UTF-8
        $variant_details = json_decode($subscription->variant_details, true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Si falla, intentar sin el flag (compatibilidad)
            $variant_details = json_decode($subscription->variant_details, true);
        }
        $product_id = $subscription->product_id;

        // Calcular monto a cobrar
        $amount = $this->calculate_renewal_amount($subscription, $variant_details, $product_id);

        if (is_wp_error($amount)) {
            error_log('CNA Cron Error: ' . $amount->get_error_message());
            $this->mark_renewal_failed($subscription->id, $amount->get_error_message());
            return;
        }

        // Desencriptar token antes de usarlo
        $decrypted_token = CNA_Token_Encryption::decrypt($subscription->pagadito_token);
        
        if (!$decrypted_token) {
            error_log('CNA Cron Error: No se pudo desencriptar el token para suscripción #' . $subscription->id);
            $this->mark_renewal_failed($subscription->id, 'Error al desencriptar token');
            return;
        }

        // Log de uso de token
        CNA_Audit_Logger::log(
            CNA_Audit_Logger::EVENT_TOKEN_USED,
            array(
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'product_id' => $subscription->product_id,
                'amount' => $amount,
                'purpose' => 'renewal',
            ),
            CNA_Audit_Logger::SEVERITY_CRITICAL
        );

        // Realizar cobro con token
        $pagadito_client = new CNA_Pagadito_Client();
        
        $description = sprintf(
            __('Renovación automática - Suscripción #%d', 'cna-subscriptions'),
            $subscription->id
        );

        $charge_result = $pagadito_client->charge_with_token(
            $decrypted_token,
            $amount,
            $description,
            array(
                'subscription_id' => $subscription->id,
                'renewal' => '1',
            )
        );

        if (is_wp_error($charge_result)) {
            error_log('CNA Cron Error: Fallo al cobrar con token - ' . $charge_result->get_error_message());
            $this->mark_renewal_failed($subscription->id, $charge_result->get_error_message());
            return;
        }

        // Verificar que el cobro fue exitoso
        $charge_status = isset($charge_result['status']) ? strtolower($charge_result['status']) : '';
        
        if (!in_array($charge_status, array('completed', 'approved', 'success'))) {
            error_log('CNA Cron Error: Cobro rechazado - Status: ' . $charge_status);
            
            // Log de renovación fallida
            CNA_Audit_Logger::log(
                CNA_Audit_Logger::EVENT_RENEWAL_FAILED,
                array(
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'product_id' => $subscription->product_id,
                    'amount' => $amount,
                    'status' => $charge_status,
                    'reason' => 'Cobro rechazado por Pagadito',
                ),
                CNA_Audit_Logger::SEVERITY_HIGH
            );
            
            $this->mark_renewal_failed($subscription->id, 'Cobro rechazado por Pagadito');
            return;
        }

        // Log de renovación exitosa
        CNA_Audit_Logger::log(
            CNA_Audit_Logger::EVENT_RENEWAL_SUCCESS,
            array(
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'product_id' => $subscription->product_id,
                'amount' => $amount,
            ),
            CNA_Audit_Logger::SEVERITY_CRITICAL
        );

        // Cobro exitoso - Extender suscripción
        $this->extend_subscription($subscription, $variant_details);
    }

    /**
     * Calcula el monto a cobrar en la renovación
     * Incluye lógica del Fee Anual (solo si es aniversario)
     *
     * @param object $subscription
     * @param array $variant_details
     * @param int $product_id
     * @return float|WP_Error
     */
    private function calculate_renewal_amount($subscription, $variant_details, $product_id) {
        // Obtener precio base usando el nuevo helper
        $size = strtolower($variant_details['size']);
        $unit_price = CNA_Product_Helper::get_variation_price($product_id, $size);

        if ($unit_price === false || $unit_price <= 0) {
            return new WP_Error(
                'invalid_price',
                __('Precio del producto no configurado para esta variación', 'cna-subscriptions')
            );
        }

        $qty = intval($variant_details['qty']);
        $advance_percent = floatval($variant_details['advance_percent']);

        // Subtotal del producto
        $product_subtotal = $unit_price * $qty;
        $advance_amount = $product_subtotal * ($advance_percent / 100);

        // Verificar si aplica Fee Anual
        $annual_fee = 0;
        $should_charge_annual_fee = $this->should_charge_annual_fee($subscription);

        if ($should_charge_annual_fee) {
            $annual_fee = floatval(get_post_meta($product_id, '_cna_annual_fee', true));
        }

        // Costo de envío (siempre se cobra 100% por adelantado)
        $shipping_total = floatval($subscription->shipping_cost_unit) * $qty;

        // Neto esperado
        $net_amount = $advance_amount + $shipping_total + $annual_fee;

        // Reverse Fee Calculation
        $pasarela_fee = CNA_Payment_Helper::get_gateway_fee();
        $total_with_fee = $net_amount / (1 - $pasarela_fee);

        return $total_with_fee;
    }

    /**
     * Determina si se debe cobrar el Fee Anual
     * Regla: Solo si la renovación coincide con el aniversario (mismo mes y día)
     *
     * @param object $subscription
     * @return bool
     */
    private function should_charge_annual_fee($subscription) {
        if (empty($subscription->created_at)) {
            return false;
        }

        $created_date = new DateTime($subscription->created_at);
        $renewal_date = new DateTime($subscription->next_renewal_date);

        // Verificar si es aniversario (mismo mes y día)
        return ($created_date->format('m-d') === $renewal_date->format('m-d'));
    }

    /**
     * Extiende la suscripción después de un cobro exitoso
     *
     * @param object $subscription
     * @param array $variant_details
     */
    private function extend_subscription($subscription, $variant_details) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Calcular nueva fecha de renovación
        $frequency_weeks = intval($variant_details['frequency']);
        $qty = intval($variant_details['qty']);

        // Obtener última entrega programada
        $last_delivery = $wpdb->get_var($wpdb->prepare(
            "SELECT scheduled_date FROM {$table_prefix}cna_deliveries 
             WHERE subscription_id = %d 
             ORDER BY scheduled_date DESC 
             LIMIT 1",
            $subscription->id
        ));

        if ($last_delivery) {
            $next_renewal = CNA_Scheduler::calculate_next_renewal_date($last_delivery, $frequency_weeks);
        } else {
            // Si no hay entregas previas, calcular desde hoy
            $next_renewal = CNA_Scheduler::calculate_next_renewal_date(
                current_time('Y-m-d'),
                $frequency_weeks
            );
        }

        // Actualizar fecha de renovación
        $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            array('next_renewal_date' => $next_renewal),
            array('id' => $subscription->id),
            array('%s'),
            array('%d')
        );

        // Obtener configuración de días del producto
        $delivery_day = intval(get_post_meta($subscription->product_id, '_cna_delivery_day', true));
        $order_cutoff = intval(get_post_meta($subscription->product_id, '_cna_order_cutoff', true));
        
        // Valores por defecto si no están configurados (Jueves=4, Miércoles=2)
        if (empty($delivery_day) && $delivery_day !== '0') {
            $delivery_day = 4; // Jueves
        }
        if (empty($order_cutoff) && $order_cutoff !== '0') {
            $order_cutoff = 2; // Miércoles
        }
        
        // Generar nuevas entregas
        $delivery_dates = CNA_Scheduler::calculate_delivery_dates(
            $last_delivery ?: 'now',
            $qty,
            $frequency_weeks,
            $delivery_day,
            $order_cutoff
        );

        $advance_percent = floatval($variant_details['advance_percent']);
        $size = strtolower($variant_details['size']);
        $unit_price = CNA_Product_Helper::get_variation_price($subscription->product_id, $size);
        
        if ($unit_price === false) {
            $unit_price = 0;
        }

        // Calcular monto a cobrar por entrega (si no pagó 100%)
        // Si pagó 50% de anticipo, cada entrega debe cobrar el 50% restante del precio unitario
        // Si pagó 100%, no hay monto a cobrar (amount_to_collect = 0)
        $amount_per_delivery = 0;
        if ($advance_percent < 100) {
            $remaining_percent = (100 - $advance_percent) / 100;
            // El monto a cobrar por entrega es el porcentaje restante del precio unitario
            // Cada entrega corresponde a una canasta, por lo que no se divide por qty
            $amount_per_delivery = $unit_price * $remaining_percent;
        }

        foreach ($delivery_dates as $date) {
            $wpdb->insert(
                $table_prefix . 'cna_deliveries',
                array(
                    'subscription_id' => $subscription->id,
                    'scheduled_date' => $date,
                    'payment_status' => ($advance_percent >= 100) ? 'paid' : 'pending',
                    'amount_to_collect' => $amount_per_delivery,
                    'delivery_status' => 'scheduled',
                ),
                array('%d', '%s', '%s', '%f', '%s')
            );
        }

        // Enviar email de confirmación (implementar después)
        // wp_mail(...);

        error_log(sprintf(
            'CNA Cron: Suscripción #%d renovada exitosamente. Próxima renovación: %s. Entregas creadas: %d',
            $subscription->id,
            $next_renewal,
            count($delivery_dates)
        ));
    }

    /**
     * Marca una renovación como fallida
     *
     * @param int $subscription_id
     * @param string $error_message
     */
    private function mark_renewal_failed($subscription_id, $error_message) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener datos de la suscripción para el log
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
            $subscription_id
        ));

        $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            array('status' => 'payment_failed'),
            array('id' => $subscription_id),
            array('%s'),
            array('%d')
        );

        // Log de renovación fallida
        if ($subscription) {
            CNA_Audit_Logger::log(
                CNA_Audit_Logger::EVENT_RENEWAL_FAILED,
                array(
                    'subscription_id' => $subscription_id,
                    'user_id' => $subscription->user_id,
                    'product_id' => $subscription->product_id,
                    'error' => $error_message,
                ),
                CNA_Audit_Logger::SEVERITY_HIGH
            );
        }

        // Enviar email de alerta (implementar después)
        // wp_mail(...);

        error_log(sprintf(
            'CNA Cron: Suscripción #%d marcada como payment_failed. Error: %s',
            $subscription_id,
            $error_message
        ));
    }

    /**
     * Limpia el cron al desactivar el plugin
     */
    public static function clear_scheduled_events() {
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
    }
}
