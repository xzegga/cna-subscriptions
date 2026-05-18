<?php
/**
 * Sistema de Emails - Gestiona el envío de emails
 * Carga templates de BD y reemplaza placeholders dinámicos
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Mailer
{

    /**
     * Obtiene el email del administrador desde los ajustes
     *
     * @return string Email del administrador
     */
    private static function get_admin_email()
    {
        $admin_email = get_option('cna_admin_email', '');
        if (empty($admin_email)) {
            $admin_email = get_option('admin_email');
        }
        return $admin_email;
    }

    /**
     * Obtiene un template de la BD
     *
     * @param string $slug Identificador del template
     * @return array|null Template encontrado o null
     */
    private static function get_template($slug)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_email_templates';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE slug = %s AND is_enabled = 1",
                sanitize_text_field($slug)
            ),
            ARRAY_A
        );
    }

    /**
     * Reemplaza placeholders en el contenido
     *
     * @param string $content Contenido con placeholders
     * @param array $variables Variables para reemplazar
     * @return string Contenido procesado
     */
    private static function process_placeholders($content, $variables)
    {
        foreach ($variables as $placeholder => $value) {
            $placeholder_tag = '{' . $placeholder . '}';
            $content = str_replace($placeholder_tag, esc_html($value), $content);
        }
        return $content;
    }

    /**
     * Envía un email y registra el envío
     *
     * @param string $template_slug Slug del template
     * @param string $recipient_email Email del destinatario
     * @param array $variables Variables para placeholders
     * @param int|null $user_id ID del usuario (opcional)
     * @param int|null $subscription_id ID de suscripción (opcional)
     * @return bool|WP_Error
     */
    private static function send_email($template_slug, $recipient_email, $variables = array(), $user_id = null, $subscription_id = null)
    {
        // Obtener template
        $template = self::get_template($template_slug);

        if (!$template) {
            return new WP_Error(
                'template_not_found',
                sprintf(__('Template de email no encontrado: %s', 'cna-subscriptions'), $template_slug)
            );
        }

        // Procesar placeholders en asunto y body
        $subject = self::process_placeholders($template['subject'], $variables);
        $body = self::process_placeholders($template['body_html'], $variables);

        // Headers para HTML
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Enviar email
        $result = wp_mail($recipient_email, $subject, $body, $headers);

        // Registrar envío en logs
        self::log_email($template_slug, $recipient_email, $result, $user_id, $subscription_id, $result ? null : 'Unknown error');

        return $result;
    }

    /**
     * Registra un email enviado en la BD
     *
     * @param string $template_slug Template slug
     * @param string $recipient_email Email destinatario
     * @param bool $success Si se envió exitosamente
     * @param int|null $user_id Usuario ID
     * @param int|null $subscription_id Suscripción ID
     * @param string|null $error_message Mensaje de error
     */
    private static function log_email($template_slug, $recipient_email, $success, $user_id = null, $subscription_id = null, $error_message = null)
    {
        global $wpdb;
        $logs_table = $wpdb->prefix . 'cna_email_logs';

        $wpdb->insert(
            $logs_table,
            array(
                'template_slug' => $template_slug,
                'recipient_email' => $recipient_email,
                'recipient_user_id' => $user_id,
                'subscription_id' => $subscription_id,
                'status' => $success ? 'sent' : 'failed',
                'error_message' => $error_message,
                'sent_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );
    }

    /**
     * ========== EMAILS AL CLIENTE ==========
     */

    /**
     * Email: Confirmación de Suscripción Pendiente
     * Se envía cuando la suscripción se crea y está pendiente de pago
     * 
     * @param int $subscription_id ID de la suscripción
     * @param string $payment_url URL de pago (opcional)
     */
    public static function send_pending_subscription($subscription_id, $payment_url = '')
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener datos de suscripción
        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        // Obtener datos del usuario
        $user = get_user_by('ID', $subscription['user_id']);
        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        // Obtener datos del producto
        $product_name = get_the_title($subscription['product_id']);

        // Decodificar variant_details para obtener qty y frequency
        $variant_details = json_decode($subscription['variant_details'], true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $variant_details = json_decode($subscription['variant_details'], true);
        }
        
        // Variables para placeholders
        $variables = array(
            'customer_name' => $user->display_name,
            'product_name' => $product_name,
            'subscription_id' => $subscription['id'],
            'total_amount' => number_format(floatval($subscription['total_with_fee'] ?? $subscription['total_amount'] ?? 0), 2),
            'payment_url' => $payment_url ?: home_url('/finalizar-suscripcion'),
            'account_link' => home_url('/mi-cuenta/'),
            'product_qty' => $variant_details['qty'] ?? 'N/A',
            'product_frequency' => $variant_details['frequency'] ?? 'N/A',
            'support_contact' => self::get_admin_email(),
        );

        return self::send_email(
            'pending_subscription',
            $user->user_email,
            $variables,
            $user->ID,
            $subscription_id
        );
    }

    /**
     * Email: Confirmación de Pago Exitoso
     * Se envía cuando el pago se procesa correctamente
     */
    public static function send_payment_success($subscription_id)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        $product_name = get_the_title($subscription['product_id']);

        // Decodificar variant_details
        $variant_details = json_decode($subscription['variant_details'], true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $variant_details = json_decode($subscription['variant_details'], true);
        }
        
        // Obtener primera fecha de entrega
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $first_delivery = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(scheduled_date) FROM {$table_prefix}cna_deliveries WHERE subscription_id = %d",
            $subscription['id']
        ));
        
        $variables = array(
            'customer_name' => $user->display_name,
            'product_name' => $product_name,
            'subscription_id' => $subscription['id'],
            'amount_paid' => '$' . number_format(floatval($subscription['total_with_fee'] ?? $subscription['total_amount'] ?? 0), 2),
            'first_delivery_date' => $first_delivery ? date('d/m/Y', strtotime($first_delivery)) : 'N/A',
            'product_frequency' => $variant_details['frequency'] ?? 'N/A',
            'account_link' => home_url('/mi-cuenta/'),
            'support_contact' => self::get_admin_email(),
        );

        return self::send_email(
            'payment_success',
            $user->user_email,
            $variables,
            $user->ID,
            $subscription_id
        );
    }

    /**
     * Email: Fallo de Pago
     * Se envía cuando el pago es rechazado
     */
    public static function send_payment_failed($subscription_id, $error_reason = '')
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        $product_name = get_the_title($subscription['product_id']);

        $variables = array(
            'customer_name' => $user->display_name,
            'product_name' => $product_name,
            'subscription_id' => $subscription['id'],
            'retry_url' => home_url('/mi-cuenta/'),
            'support_contact' => self::get_admin_email(),
        );

        return self::send_email(
            'payment_failed',
            $user->user_email,
            $variables,
            $user->ID,
            $subscription_id
        );
    }

    /**
     * Email: Cambio de Estado de Suscripción
     * Se envía cuando el administrador cambia el estado de una suscripción
     */
    public static function send_subscription_status_changed($subscription_id, $new_status, $action_message = '')
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        $product_name = get_the_title($subscription['product_id']);

        // Obtener etiqueta del estado
        $status_labels = array(
            'active' => __('Activa', 'cna-subscriptions'),
            'pending' => __('Pendiente', 'cna-subscriptions'),
            'cancelled' => __('Cancelada', 'cna-subscriptions'),
            'paused' => __('Pausada', 'cna-subscriptions'),
            'payment_failed' => __('Pago Fallido', 'cna-subscriptions'),
        );
        $status_label = $status_labels[$new_status] ?? $new_status;

        $variables = array(
            'customer_name' => $user->display_name,
            'product_name' => $product_name,
            'subscription_id' => $subscription['id'],
            'new_status' => $status_label,
            'action_message' => $action_message ?: sprintf(__('El estado de tu suscripción ha sido cambiado a: %s', 'cna-subscriptions'), $status_label),
            'account_link' => home_url('/mi-cuenta/'),
            'support_contact' => self::get_admin_email(),
        );

        return self::send_email(
            'subscription_status_changed',
            $user->user_email,
            $variables,
            $user->ID,
            $subscription_id
        );
    }

    /**
     * Email: Bienvenida al Registrarse
     * Se envía a nuevos usuarios
     */
    public static function send_welcome_email($user_id)
    {
        $user = get_user_by('ID', intval($user_id));

        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        $variables = array(
            'user_name' => $user->display_name,
            'login_link' => home_url('/mi-cuenta/'),
            'first_product_link' => home_url('/suscripcion/'),
            'support_contact' => self::get_admin_email(),
        );

        return self::send_email(
            'welcome_new_user',
            $user->user_email,
            $variables,
            $user->ID
        );
    }

    /**
     * Email: Suscripción Cancelada
     * Se envía cuando el usuario cancela su suscripción
     */
    public static function send_subscription_cancelled($subscription_id)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        $product_name = get_the_title($subscription['product_id']);

        $variables = array(
            'customer_name' => $user->display_name,
            'product_name' => $product_name,
            'subscription_id' => $subscription['id'],
            'reactivation_link' => home_url('/mi-cuenta/'),
            'support_contact' => self::get_admin_email(),
        );

        return self::send_email(
            'subscription_cancelled',
            $user->user_email,
            $variables,
            $user->ID,
            $subscription_id
        );
    }

    /**
     * Email: Confirmación de Renovación
     * Se envía cuando la suscripción se renueva automáticamente
     */
    public static function send_subscription_renewed($subscription_id)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        $product_name = get_the_title($subscription['product_id']);

        $variables = array(
            'customer_name' => $user->display_name,
            'product_name' => $product_name,
            'subscription_id' => $subscription['id'],
            'new_transaction_id' => 'TXN-' . $subscription['id'],
            'renewal_amount' => '$' . number_format(floatval($subscription['total_with_fee'] ?? $subscription['total_amount'] ?? 0), 2),
            'renewal_period' => date('Y-m-d', strtotime('+1 month')),
            'next_deliveries' => 'Próximas 3 entregas programadas',
            'account_link' => home_url('/mi-cuenta/'),
            'support_contact' => self::get_admin_email(),
        );

        return self::send_email(
            'subscription_renewed',
            $user->user_email,
            $variables,
            $user->ID,
            $subscription_id
        );
    }

    /**
     * ========== EMAILS AL ADMIN ==========
     */

    /**
     * Email: Nueva Suscripción Creada
     * Notificación al admin
     */
    public static function send_admin_new_subscription($subscription_id)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        $product_name = get_the_title($subscription['product_id']);
        $admin_email = self::get_admin_email();

        // Decodificar variant_details
        $variant_details = json_decode($subscription['variant_details'], true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $variant_details = json_decode($subscription['variant_details'], true);
        }
        
        $variables = array(
            'subscription_id' => $subscription['id'],
            'product_name' => $product_name,
            'customer_name' => $user ? $user->display_name : 'N/A',
            'customer_email' => $user ? $user->user_email : 'N/A',
            'customer_phone' => get_user_meta($subscription['user_id'], 'phone', true) ?: 'N/A',
            'total_amount' => '$' . number_format(floatval($subscription['total_with_fee'] ?? $subscription['total_amount'] ?? 0), 2),
            'product_qty' => $variant_details['qty'] ?? 'N/A',
            'dashboard_link' => admin_url('edit.php?post_type=cna_product&page=cna-subscriptions'),
        );

        return self::send_email(
            'admin_new_subscription',
            $admin_email,
            $variables,
            null,
            $subscription_id
        );
    }

    /**
     * Email: Pago Recibido
     * Notificación al admin
     */
    public static function send_admin_payment_received($subscription_id)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        $product_name = get_the_title($subscription['product_id']);
        $admin_email = self::get_admin_email();

        // Decodificar variant_details
        $variant_details = json_decode($subscription['variant_details'], true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $variant_details = json_decode($subscription['variant_details'], true);
        }
        
        $variables = array(
            'subscription_id' => $subscription['id'],
            'product_name' => $product_name,
            'customer_name' => $user ? $user->display_name : 'N/A',
            'amount' => '$' . number_format(floatval($subscription['total_with_fee'] ?? $subscription['total_amount'] ?? 0), 2),
            'delivery_count' => $variant_details['qty'] ?? 1,
            'dashboard_link' => admin_url('edit.php?post_type=cna_product&page=cna-subscriptions'),
        );

        return self::send_email(
            'admin_payment_received',
            $admin_email,
            $variables,
            null,
            $subscription_id
        );
    }

    /**
     * Email: Alerta de Pago Fallido
     * Notificación al admin
     */
    public static function send_admin_payment_failed($subscription_id, $error_reason = '')
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        $product_name = get_the_title($subscription['product_id']);
        $admin_email = self::get_admin_email();

        $variables = array(
            'subscription_id' => $subscription['id'],
            'product_name' => $product_name,
            'customer_name' => $user ? $user->display_name : 'N/A',
            'amount' => '$' . number_format(floatval($subscription['total_with_fee'] ?? $subscription['total_amount'] ?? 0), 2),
            'error_reason' => $error_reason ?: 'Error desconocido',
            'dashboard_link' => admin_url('edit.php?post_type=cna_product&page=cna-subscriptions'),
        );

        return self::send_email(
            'admin_payment_failed',
            $admin_email,
            $variables,
            null,
            $subscription_id
        );
    }

    /**
     * Email: Nuevo Usuario Registrado
     * Notificación al admin
     */
    public static function send_admin_new_user($user_id)
    {
        $user = get_user_by('ID', intval($user_id));

        if (!$user) {
            return new WP_Error('user_not_found', __('Usuario no encontrado', 'cna-subscriptions'));
        }

        $admin_email = self::get_admin_email();

        $variables = array(
            'user_name' => $user->display_name,
            'user_email' => $user->user_email,
            'registration_date' => $user->user_registered,
            'user_profile_link' => admin_url('user-edit.php?user_id=' . $user->ID),
        );

        return self::send_email(
            'admin_new_user',
            $admin_email,
            $variables,
            $user->ID
        );
    }

    /**
     * Email: Suscripción Cancelada (Admin)
     * Notificación al admin
     */
    public static function send_admin_subscription_cancelled($subscription_id)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                intval($subscription_id)
            ),
            ARRAY_A
        );

        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $user = get_user_by('ID', $subscription['user_id']);
        $product_name = get_the_title($subscription['product_id']);
        $admin_email = self::get_admin_email();

        $variables = array(
            'subscription_id' => $subscription['id'],
            'product_name' => $product_name,
            'customer_name' => $user ? $user->display_name : 'N/A',
            'dashboard_link' => admin_url('edit.php?post_type=cna_product&page=cna-subscriptions'),
        );

        return self::send_email(
            'admin_subscription_cancelled',
            $admin_email,
            $variables,
            null,
            $subscription_id
        );
    }

    /**
     * Email: Error en Webhook
     * Notificación técnica al admin
     */
    public static function send_admin_webhook_error($error_message, $subscription_id = null, $webhook_data = '')
    {
        $admin_email = self::get_admin_email();

        $variables = array(
            'error_message' => $error_message,
            'subscription_id' => $subscription_id ?: 'N/A',
            'webhook_data' => substr($webhook_data, 0, 500), // Limitar a 500 chars
            'timestamp' => current_time('Y-m-d H:i:s'),
        );

        return self::send_email(
            'admin_webhook_error',
            $admin_email,
            $variables,
            null,
            $subscription_id
        );
    }
}
