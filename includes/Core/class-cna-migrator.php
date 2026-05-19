<?php
/**
 * Sistema de Migraciones Idempotent
 * Maneja la creación y actualización de tablas de forma segura
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Migrator
{

    const DB_VERSION = '1.0.5';
    const VERSION_OPTION = 'cna_subscriptions_db_version';

    /**
     * Ejecuta todas las migraciones pendientes
     */
    public static function migrate()
    {
        $current_version = get_option(self::VERSION_OPTION, '0.0.0');

        // Ejecutar migraciones en orden
        if (version_compare($current_version, '1.0.0', '<')) {
            self::migration_1_0_0();
        }

        if (version_compare($current_version, '1.0.1', '<')) {
            self::migration_1_0_1();
        }

        if (version_compare($current_version, '1.0.2', '<')) {
            self::migration_1_0_2();
        }

        if (version_compare($current_version, '1.0.3', '<')) {
            self::migration_1_0_3();
        }

        if (version_compare($current_version, '1.0.4', '<')) {
            self::migration_1_0_4();
        }

        if (version_compare($current_version, '1.0.5', '<')) {
            self::migration_1_0_5();
        }

        // Siempre reparar columnas faltantes (dbDelta y versiones saltadas no son fiables).
        self::ensure_subscriptions_table_columns();

        // Actualizar versión
        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * @return bool True si pagadito_ern existe en la tabla de suscripciones.
     */
    public static function subscriptions_table_has_pagadito_ern()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_subscriptions';

        if (!self::table_exists($table_name)) {
            return false;
        }

        $columns = self::get_table_columns($table_name);

        return in_array('pagadito_ern', $columns, true);
    }

    /**
     * Asegura columnas requeridas en cna_subscriptions (idempotente, sin depender de la versión guardada).
     */
    public static function ensure_subscriptions_table_columns()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_subscriptions';

        if (!self::table_exists($table_name)) {
            self::log_migration('Tabla no existe aún, se omiten columnas: ' . $table_name);
            return;
        }

        $columns = self::get_table_columns($table_name);
        if ($columns === array()) {
            self::log_migration('No se pudieron leer columnas de ' . $table_name);
            return;
        }

        $decimal_columns = array(
            'unit_price' => 'decimal(10,2) DEFAULT 0.00',
            'product_subtotal' => 'decimal(10,2) DEFAULT 0.00',
            'advance_amount' => 'decimal(10,2) DEFAULT 0.00',
            'shipping_total' => 'decimal(10,2) DEFAULT 0.00',
            'annual_fee' => 'decimal(10,2) DEFAULT 0.00',
            'net_amount' => 'decimal(10,2) DEFAULT 0.00',
            'fee_amount' => 'decimal(10,2) DEFAULT 0.00',
            'total_with_fee' => 'decimal(10,2) DEFAULT 0.00',
        );

        foreach ($decimal_columns as $column => $definition) {
            self::add_column_if_missing($table_name, $column, $definition, $columns);
        }

        self::add_column_if_missing($table_name, 'pagadito_ern', 'varchar(50) DEFAULT NULL', $columns);
        self::add_column_if_missing($table_name, 'payment_transaction_json', 'longtext DEFAULT NULL', $columns);

        if (in_array('pagadito_ern', $columns, true)) {
            self::add_index_if_missing($table_name, 'idx_pagadito_ern', 'pagadito_ern');
        }
    }

    /**
     * Migración 1.0.5: Reparar pagadito_ern cuando la BD quedó en 1.0.4 sin esa columna.
     */
    private static function migration_1_0_5()
    {
        self::ensure_subscriptions_table_columns();
    }

    /**
     * @param string $table_name
     * @return bool
     */
    private static function table_exists($table_name)
    {
        global $wpdb;

        $like = $wpdb->esc_like($table_name);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        return is_string($found) && $found === $table_name;
    }

    /**
     * @param string $table_name
     * @return string[]
     */
    private static function get_table_columns($table_name)
    {
        global $wpdb;

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`", 0);

        return is_array($columns) ? $columns : array();
    }

    /**
     * @param string   $table_name
     * @param string   $column
     * @param string   $definition
     * @param string[] $columns
     */
    private static function add_column_if_missing($table_name, $column, $definition, &$columns)
    {
        if (in_array($column, $columns, true)) {
            return;
        }

        global $wpdb;

        $sql = "ALTER TABLE `{$table_name}` ADD COLUMN `{$column}` {$definition}";
        $result = $wpdb->query($sql);

        if ($result === false) {
            self::log_migration('Error ADD COLUMN ' . $column . ': ' . $wpdb->last_error);
            return;
        }

        $columns[] = $column;
        self::log_migration('Columna agregada: ' . $column);
    }

    /**
     * @param string $table_name
     * @param string $index_name
     * @param string $column
     */
    private static function add_index_if_missing($table_name, $index_name, $column)
    {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s",
            $index_name
        ));

        if (!empty($existing)) {
            return;
        }

        $result = $wpdb->query("ALTER TABLE `{$table_name}` ADD INDEX `{$index_name}` (`{$column}`)");

        if ($result === false) {
            self::log_migration('Error ADD INDEX ' . $index_name . ': ' . $wpdb->last_error);
            return;
        }

        self::log_migration('Índice agregado: ' . $index_name);
    }

    /**
     * @param string $message
     */
    private static function log_migration($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CNA Subscriptions][migration] ' . $message);
        }
    }

    /**
     * Migración 1.0.1: Agregar campos de totales calculados a cna_subscriptions
     */
    private static function migration_1_0_1()
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $table_name = $table_prefix . 'cna_subscriptions';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Verificar si las columnas ya existen
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        
        $new_columns = array(
            'unit_price' => 'decimal(10,2) DEFAULT 0.00',
            'product_subtotal' => 'decimal(10,2) DEFAULT 0.00',
            'advance_amount' => 'decimal(10,2) DEFAULT 0.00',
            'shipping_total' => 'decimal(10,2) DEFAULT 0.00',
            'annual_fee' => 'decimal(10,2) DEFAULT 0.00',
            'net_amount' => 'decimal(10,2) DEFAULT 0.00',
            'fee_amount' => 'decimal(10,2) DEFAULT 0.00',
            'total_with_fee' => 'decimal(10,2) DEFAULT 0.00',
        );

        foreach ($new_columns as $column => $definition) {
            if (!in_array($column, $columns)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    /**
     * Migración 1.0.2: Crear tabla de direcciones de usuarios
     */
    private static function migration_1_0_2()
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql_user_addresses = "CREATE TABLE {$table_prefix}cna_user_addresses (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            label varchar(255) DEFAULT 'Mi dirección',
            country varchar(100) DEFAULT 'El Salvador',
            department varchar(255) NOT NULL,
            municipality varchar(255) NOT NULL,
            district varchar(255) NOT NULL,
            address text NOT NULL,
            is_default tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY is_default (is_default)
        ) $charset_collate;";

        dbDelta($sql_user_addresses);
    }

    /**
     * Migración 1.0.3: Agregar campo pagadito_ern a cna_subscriptions
     */
    private static function migration_1_0_3()
    {
        self::ensure_subscriptions_table_columns();
    }

    /**
     * Migración 1.0.4: JSON de transacción de pago (multi-pasarela)
     */
    private static function migration_1_0_4()
    {
        self::ensure_subscriptions_table_columns();
    }

    /**
     * Migración 1.0.0: Crear tablas iniciales del plugin
     */
    private static function migration_1_0_0()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_prefix = $wpdb->prefix;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. Tabla de Templates de Email
        $sql_templates = "CREATE TABLE {$table_prefix}cna_email_templates (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            recipient_type ENUM('customer', 'admin') NOT NULL DEFAULT 'customer',
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT NOT NULL,
            is_enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_slug (slug),
            INDEX idx_recipient_type (recipient_type),
            INDEX idx_enabled (is_enabled)
        ) $charset_collate;";

        $result_templates = dbDelta($sql_templates);
        
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('CNA Migration - Email Templates Table: ' . print_r($result_templates, true));
        }

        // 2. Tabla de Logs de Emails
        $sql_logs = "CREATE TABLE {$table_prefix}cna_email_logs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            template_slug VARCHAR(100) NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_user_id BIGINT(20) UNSIGNED,
            subscription_id BIGINT(20) UNSIGNED,
            status ENUM('sent', 'failed', 'pending') DEFAULT 'sent',
            error_message TEXT,
            sent_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_template_slug (template_slug),
            INDEX idx_recipient_email (recipient_email),
            INDEX idx_subscription_id (subscription_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) $charset_collate;";

        $result_logs = dbDelta($sql_logs);
        
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('CNA Migration - Email Logs Table: ' . print_r($result_logs, true));
        }

        // 3. Insertar templates predefinidos (solo si la tabla está vacía)
        self::seed_email_templates();
    }

    /**
     * Siembra los templates de email predefinidos
     * Solo si la tabla está vacía (idempotent)
     */
    private static function seed_email_templates()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_email_templates';

        // Verificar si la tabla existe primero
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        
        if (!$table_exists) {
            // La tabla no existe aún, no hay nada que hacer
            return;
        }

        // Verificar si ya existen templates con un query más seguro
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        if (intval($count) > 0) {
            return; // Ya hay templates, no insertar
        }

        $templates = self::get_default_templates();

        foreach ($templates as $template) {
            $wpdb->insert(
                $table_name,
                $template,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d')
            );
        }
    }

    /**
     * Obtiene los templates predefinidos
     * Retorna array de templates para insertar
     *
     * @return array
     */
    public static function get_default_templates()
    {
        return array(
            // ========== EMAILS AL CLIENTE ==========

            // 1. Confirmación de Suscripción Pendiente
            array(
                'slug' => 'pending_subscription',
                'name' => 'Confirmación de Suscripción Pendiente',
                'description' => 'Se envía al cliente cuando crea una suscripción y debe realizar el pago',
                'recipient_type' => 'customer',
                'subject' => '¡Tu suscripción {product_name} está lista para pago!',
                'body_html' => self::get_template_pending_subscription(),
                'is_enabled' => 1,
            ),

            // 2. Confirmación de Pago Exitoso
            array(
                'slug' => 'payment_success',
                'name' => 'Confirmación de Pago Exitoso',
                'description' => 'Se envía al cliente cuando su pago es confirmado exitosamente',
                'recipient_type' => 'customer',
                'subject' => '¡Pago confirmado! Tu suscripción a {product_name} está activa',
                'body_html' => self::get_template_payment_success(),
                'is_enabled' => 1,
            ),

            // 3. Fallo de Pago
            array(
                'slug' => 'payment_failed',
                'name' => 'Fallo de Pago',
                'description' => 'Se envía al cliente cuando su pago es rechazado',
                'recipient_type' => 'customer',
                'subject' => 'Pago rechazado para tu suscripción {product_name}',
                'body_html' => self::get_template_payment_failed(),
                'is_enabled' => 1,
            ),

            // 4. Bienvenida al Registrarse
            array(
                'slug' => 'welcome_new_user',
                'name' => 'Bienvenida al Registrarse',
                'description' => 'Se envía a nuevos usuarios cuando se registran en la plataforma',
                'recipient_type' => 'customer',
                'subject' => '¡Bienvenido a Canasta Campesina!',
                'body_html' => self::get_template_welcome_user(),
                'is_enabled' => 1,
            ),

            // 4.5. Cambio de Estado de Suscripción
            array(
                'slug' => 'subscription_status_changed',
                'name' => 'Cambio de Estado de Suscripción',
                'description' => 'Se envía al cliente cuando el administrador cambia el estado de su suscripción',
                'recipient_type' => 'customer',
                'subject' => 'Actualización de tu suscripción a {product_name}',
                'body_html' => self::get_template_subscription_status_changed(),
                'is_enabled' => 1,
            ),

            // 5. Suscripción Cancelada (admin decidirá si enviar)
            array(
                'slug' => 'subscription_cancelled',
                'name' => 'Suscripción Cancelada',
                'description' => 'Se envía al cliente cuando cancela su suscripción (futuro)',
                'recipient_type' => 'customer',
                'subject' => 'Tu suscripción a {product_name} ha sido cancelada',
                'body_html' => self::get_template_subscription_cancelled(),
                'is_enabled' => 0,
            ),

            // 6. Confirmación de Renovación (futuro - cron)
            array(
                'slug' => 'subscription_renewed',
                'name' => 'Confirmación de Renovación',
                'description' => 'Se envía cuando la suscripción se renueva automáticamente (futuro)',
                'recipient_type' => 'customer',
                'subject' => 'Tu suscripción a {product_name} ha sido renovada',
                'body_html' => self::get_template_subscription_renewed(),
                'is_enabled' => 0,
            ),

            // ========== EMAILS AL ADMIN ==========

            // 7. Nueva Suscripción Creada
            array(
                'slug' => 'admin_new_subscription',
                'name' => 'Nueva Suscripción Creada',
                'description' => 'Notificación al admin cuando se crea una nueva suscripción',
                'recipient_type' => 'admin',
                'subject' => '[Admin] Nueva suscripción: {product_name} por {customer_name}',
                'body_html' => self::get_template_admin_new_subscription(),
                'is_enabled' => 1,
            ),

            // 8. Pago Recibido
            array(
                'slug' => 'admin_payment_received',
                'name' => 'Pago Recibido',
                'description' => 'Notificación al admin cuando recibe un pago exitoso',
                'recipient_type' => 'admin',
                'subject' => '[Admin] Pago recibido: {customer_name} - {product_name}',
                'body_html' => self::get_template_admin_payment_received(),
                'is_enabled' => 1,
            ),

            // 9. Alerta de Pago Fallido
            array(
                'slug' => 'admin_payment_failed',
                'name' => 'Alerta de Pago Fallido',
                'description' => 'Notificación al admin cuando un pago falla',
                'recipient_type' => 'admin',
                'subject' => '[Admin] ALERTA: Pago fallido para {customer_name}',
                'body_html' => self::get_template_admin_payment_failed(),
                'is_enabled' => 1,
            ),

            // 10. Nuevo Usuario Registrado
            array(
                'slug' => 'admin_new_user',
                'name' => 'Nuevo Usuario Registrado',
                'description' => 'Notificación al admin cuando se registra un nuevo usuario',
                'recipient_type' => 'admin',
                'subject' => '[Admin] Nuevo usuario registrado: {user_name}',
                'body_html' => self::get_template_admin_new_user(),
                'is_enabled' => 1,
            ),

            // 11. Suscripción Cancelada (admin)
            array(
                'slug' => 'admin_subscription_cancelled',
                'name' => 'Suscripción Cancelada (Admin)',
                'description' => 'Notificación al admin cuando un usuario cancela su suscripción (futuro)',
                'recipient_type' => 'admin',
                'subject' => '[Admin] Suscripción cancelada: {customer_name} - {product_name}',
                'body_html' => self::get_template_admin_subscription_cancelled(),
                'is_enabled' => 0,
            ),

            // 12. Alerta de Error en Webhook
            array(
                'slug' => 'admin_webhook_error',
                'name' => 'Alerta de Error en Webhook',
                'description' => 'Notificación técnica al admin cuando hay error en webhook de Pagadito',
                'recipient_type' => 'admin',
                'subject' => '[Admin] ERROR de Webhook: Fallo de procesamiento',
                'body_html' => self::get_template_admin_webhook_error(),
                'is_enabled' => 1,
            ),
        );
    }

    // ========== TEMPLATES HTML ==========

    private static function get_template_pending_subscription()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333333; background-color: #f5f5f5; margin: 0; padding: 0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #4CAF50; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#4CAF50; margin:0; font-size:24px;">¡Tu suscripción está lista!</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;">Hola {customer_name},</p>
            <p style="margin:0 0 15px 0;">Hemos recibido tu solicitud de suscripción a <strong>{product_name}</strong>.</p>
            
            <h3 style="margin:20px 0 10px 0; color:#333;">Detalles de tu pedido:</h3>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>Suscripción ID:</strong> {subscription_id}</li>
                <li><strong>Producto:</strong> {product_name}</li>
                <li><strong>Cantidad:</strong> {product_qty}</li>
                <li><strong>Frecuencia:</strong> {product_frequency}</li>
                <li><strong>Monto Total:</strong> ${total_amount}</li>
            </ul>

            <p style="margin:0 0 15px 0;">Tu suscripción ha sido confirmada y está activa. Las entregas comenzarán pronto.</p>
            
            <a href="{account_link}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Mis Suscripciones</a>

            <p style="margin:10px 0 0 0;">Si tienes preguntas, no dudes en contactarnos a <strong>{support_contact}</strong></p>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">&copy; Canasta Campesina. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_payment_success()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333333; background-color: #f5f5f5; margin: 0; padding: 0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #4CAF50; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#4CAF50; margin:0; font-size:24px;">¡Pago Confirmado! 🎉</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;">Hola {customer_name},</p>
            
            <div style="background-color:#4CAF50; color:#ffffff; padding:10px 15px; border-radius:5px; display:inline-block; margin:0 0 20px 0;">✓ Tu suscripción está ACTIVA</div>
            
            <p style="margin:0 0 15px 0;">Gracias por tu compra. Tu suscripción a <strong>{product_name}</strong> está ahora activa.</p>
            
            <h3 style="margin:20px 0 10px 0; color:#333;">Detalles de tu suscripción:</h3>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>Suscripción ID:</strong> {subscription_id}</li>
                <li><strong>Monto pagado:</strong> ${amount_paid}</li>
                <li><strong>Primera entrega:</strong> {first_delivery_date}</li>
                <li><strong>Frecuencia de entregas:</strong> {product_frequency}</li>
            </ul>

            <h3 style="margin:20px 0 10px 0; color:#333;">¿Qué sigue?</h3>
            <p style="margin:0 0 15px 0;">Tu primera entrega llegará en la fecha indicada. Recibirás un email de confirmación con los detalles de cada entrega.</p>
            
            <a href="{account_link}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Mi Cuenta</a>

            <p style="margin:10px 0 0 0;">Si necesitas ayuda, contacta a <strong>{support_contact}</strong></p>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">&copy; Canasta Campesina. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_payment_failed()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333333; background-color: #f5f5f5; margin: 0; padding: 0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #ff9800; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#ff9800; margin:0; font-size:24px;">Pago no procesado</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;">Hola {customer_name},</p>
            
            <div style="background-color:#fff3cd; border-left:4px solid #ff9800; padding:15px; margin:20px 0;">
                <strong>⚠️ Tu pago no pudo ser procesado</strong>
            </div>
            
            <p style="margin:0 0 15px 0;">Intentamos procesar tu pago para la suscripción a <strong>{product_name}</strong>, pero fue rechazado.</p>
            
            <h3 style="margin:20px 0 10px 0; color:#333;">¿Qué pasó?</h3>
            <p style="margin:0 0 10px 0;">Las razones comunes incluyen:</p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li>Fondos insuficientes</li>
                <li>Datos incorrectos de la tarjeta</li>
                <li>Restricciones de la entidad bancaria</li>
            </ul>

            <h3 style="margin:20px 0 10px 0; color:#333;">¿Qué hacer ahora?</h3>
            <p style="margin:0 0 15px 0;">Puedes reintentar el pago usando otro método de pago o actualizando tus datos bancarios.</p>
            
            <a href="{retry_url}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Reintentar Pago</a>

            <p style="margin:10px 0 0 0;">Si continúas teniendo problemas, contacta a nuestro equipo de soporte en <strong>{support_contact}</strong></p>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">&copy; Canasta Campesina. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_welcome_user()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333333; background-color: #f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #4CAF50; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#4CAF50; margin:0; font-size:24px;">¡Bienvenido a Canasta Campesina!</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;">Hola {user_name},</p>
            
            <p style="margin:0 0 15px 0;">¡Gracias por crear tu cuenta con nosotros! Estamos emocionados de tenerte como parte de nuestra comunidad.</p>
            
            <h3 style="margin:20px 0 10px 0; color:#333;">¿Qué puedes hacer ahora?</h3>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li>Explorar nuestros productos de suscripción</li>
                <li>Crear tu primera suscripción</li>
                <li>Gestionar tus entregas</li>
            </ul>

            <p style="margin:0 0 10px 0;">Para acceder a tu cuenta, usa el siguiente enlace:</p>
            <a href="{login_link}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:10px 5px 10px 0;">Ir a Mi Cuenta</a>

            <h3 style="margin:20px 0 10px 0; color:#333;">¿Necesitas ayuda?</h3>
            <p style="margin:0 0 10px 0;">Visita nuestras preguntas frecuentes o contacta a nuestro equipo en <strong>{support_contact}</strong></p>

            <p style="margin:10px 0 0 0;">¡Gracias por elegirnos!</p>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">&copy; Canasta Campesina. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_subscription_status_changed()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333333; background-color: #f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #4CAF50; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#4CAF50; margin:0; font-size:24px;">Actualización de tu Suscripción</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;">Hola {customer_name},</p>
            
            <p style="margin:0 0 15px 0;">Te informamos que el estado de tu suscripción a <strong>{product_name}</strong> ha sido actualizado.</p>
            
            <div style="background:#f9f9f9; padding:15px; border-radius:5px; margin:20px 0;">
                <p style="margin:0 0 10px 0;"><strong>Detalles de la suscripción:</strong></p>
                <ul style="padding-left:20px; margin:0;">
                    <li><strong>Suscripción ID:</strong> {subscription_id}</li>
                    <li><strong>Nuevo Estado:</strong> {new_status}</li>
                </ul>
            </div>

            <p style="margin:15px 0;">{action_message}</p>

            <p style="margin:0 0 15px 0;">Puedes ver los detalles completos de tu suscripción en tu cuenta:</p>
            <a href="{account_link}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Mi Cuenta</a>

            <p style="margin:10px 0 0 0;">Si tienes preguntas sobre este cambio, no dudes en contactarnos a <strong>{support_contact}</strong></p>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">&copy; Canasta Campesina. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_subscription_cancelled()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333333; background-color: #f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #f44336; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#f44336; margin:0; font-size:24px;">Suscripción Cancelada</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;">Hola {customer_name},</p>
            
            <p style="margin:0 0 15px 0;">Tu suscripción a <strong>{product_name}</strong> ha sido cancelada exitosamente.</p>
            
            <h3 style="margin:20px 0 10px 0; color:#333;">¿Qué significa esto?</h3>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li>No recibirás más entregas</li>
                <li>Tu acceso a la suscripción finalizará en la próxima fecha de renovación</li>
                <li>Los pagos automáticos han sido desactivados</li>
            </ul>

            <h3 style="margin:20px 0 10px 0; color:#333;">¿Cambió de opinión?</h3>
            <p style="margin:0 0 15px 0;">Puedes reactivar tu suscripción en cualquier momento desde tu cuenta.</p>
            
            <a href="{reactivation_link}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Reactivar Suscripción</a>

            <p style="margin:10px 0 0 0;">¿Hay algo que podamos mejorar? Nos encantaría saber tu opinión. Contáctanos en <strong>{support_contact}</strong></p>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">&copy; Canasta Campesina. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_subscription_renewed()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333333; background-color: #f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #4CAF50; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#4CAF50; margin:0; font-size:24px;">¡Tu suscripción ha sido renovada! 🔄</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;">Hola {customer_name},</p>
            
            <p style="margin:0 0 15px 0;">Tu suscripción a <strong>{product_name}</strong> ha sido renovada automáticamente.</p>
            
            <h3 style="margin:20px 0 10px 0; color:#333;">Detalles de la renovación:</h3>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>Número de transacción:</strong> {new_transaction_id}</li>
                <li><strong>Monto cobrado:</strong> ${renewal_amount}</li>
                <li><strong>Período activo:</strong> {renewal_period}</li>
            </ul>

            <h3 style="margin:20px 0 10px 0; color:#333;">Próximas entregas:</h3>
            <p style="margin:0 0 15px 0;">{next_deliveries}</p>

            <a href="{account_link}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Detalles</a>

            <p style="margin:10px 0 0 0;">¡Gracias por tu confianza continua en Canasta Campesina!</p>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">&copy; Canasta Campesina. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>';
    }

    // ========== TEMPLATES ADMIN ==========

    private static function get_template_admin_new_subscription()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color:#333333; background-color:#f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #2196F3; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#2196F3; margin:0; font-size:18px;">Nueva Suscripción Creada</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;"><strong>Información de la suscripción:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>ID:</strong> {subscription_id}</li>
                <li><strong>Producto:</strong> {product_name}</li>
                <li><strong>Cantidad:</strong> {product_qty}</li>
                <li><strong>Monto total:</strong> ${total_amount}</li>
            </ul>

            <p style="margin:0 0 10px 0;"><strong>Información del cliente:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>Nombre:</strong> {customer_name}</li>
                <li><strong>Email:</strong> {customer_email}</li>
                <li><strong>Teléfono:</strong> {customer_phone}</li>
            </ul>

            <p style="margin:0 0 15px 0;"><strong>Estado:</strong> Pendiente de pago</p>
            
            <a href="{dashboard_link}" style="background-color:#2196F3; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver en Dashboard</a>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">Este es un email automático. No responder a este email.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_admin_payment_received()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color:#333333; background-color:#f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #4CAF50; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#4CAF50; margin:0; font-size:18px;">Pago Recibido ✓</h1>
        </div>
        <div style="line-height:1.6;">
            <div style="background-color:#c8e6c9; border-left:4px solid #4CAF50; padding:15px; margin:20px 0;">
                <strong>Pago exitoso procesado</strong>
            </div>

            <p style="margin:0 0 10px 0;"><strong>Detalles de la transacción:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>ID Suscripción:</strong> {subscription_id}</li>
                <li><strong>Cliente:</strong> {customer_name}</li>
                <li><strong>Producto:</strong> {product_name}</li>
                <li><strong>Monto:</strong> ${amount}</li>
                <li><strong>Entregas a preparar:</strong> {delivery_count}</li>
            </ul>

            <p style="margin:0 0 10px 0;"><strong>Próximas acciones:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li>Suscripción activada</li>
                <li>Preparar primera entrega</li>
                <li>Programar entregas futuras</li>
            </ul>
            
            <a href="{dashboard_link}" style="background-color:#4CAF50; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Detalles</a>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">Este es un email automático. No responder a este email.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_admin_payment_failed()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color:#333333; background-color:#f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #f44336; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#f44336; margin:0; font-size:18px;">ALERTA: Pago Fallido</h1>
        </div>
        <div style="line-height:1.6;">
            <div style="background-color:#ffcdd2; border-left:4px solid #f44336; padding:15px; margin:20px 0;">
                <strong>⚠️ El pago de una suscripción fue rechazado</strong>
            </div>

            <p style="margin:0 0 10px 0;"><strong>Detalles del problema:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>ID Suscripción:</strong> {subscription_id}</li>
                <li><strong>Cliente:</strong> {customer_name}</li>
                <li><strong>Producto:</strong> {product_name}</li>
                <li><strong>Monto:</strong> ${amount}</li>
                <li><strong>Razón:</strong> {error_reason}</li>
            </ul>

            <p style="margin:0 0 10px 0;"><strong>Recomendaciones:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li>Contactar al cliente para resolver</li>
                <li>Ofrecer métodos de pago alternativos</li>
                <li>Considerar reintentos automáticos</li>
            </ul>

            <a href="{dashboard_link}" style="background-color:#f44336; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Detalles</a>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">Este es un email automático. No responder a este email.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_admin_new_user()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color:#333333; background-color:#f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #2196F3; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#2196F3; margin:0; font-size:18px;">Nuevo Usuario Registrado</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;"><strong>Información del nuevo usuario:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>Nombre:</strong> {user_name}</li>
                <li><strong>Email:</strong> {user_email}</li>
                <li><strong>Fecha de registro:</strong> {registration_date}</li>
            </ul>

            <a href="{user_profile_link}" style="background-color:#2196F3; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Perfil</a>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">Este es un email automático. No responder a este email.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_admin_subscription_cancelled()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; color:#333333; background-color:#f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #ff9800; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#ff9800; margin:0; font-size:18px;">Suscripción Cancelada</h1>
        </div>
        <div style="line-height:1.6;">
            <p style="margin:0 0 10px 0;"><strong>Una suscripción ha sido cancelada:</strong></p>
            <ul style="padding-left:20px; margin:0 0 15px 0;">
                <li><strong>ID Suscripción:</strong> {subscription_id}</li>
                <li><strong>Cliente:</strong> {customer_name}</li>
                <li><strong>Producto:</strong> {product_name}</li>
            </ul>

            <a href="{dashboard_link}" style="background-color:#2196F3; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:5px; display:inline-block; margin:20px 0;">Ver Detalles</a>
        </div>
        <div style="border-top:1px solid #dddddd; padding-top:20px; margin-top:20px; font-size:12px; color:#999999;">
            <p style="margin:0;">Este es un email automático. No responder a este email.</p>
        </div>
    </div>
</body>
</html>';
    }

    private static function get_template_admin_webhook_error()
    {
        return '<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: monospace; color:#333333; background-color:#f5f5f5; margin:0; padding:0;">
    <div style="max-width:600px; margin:0 auto; background-color:#ffffff; padding:20px; border-radius:5px;">
        <div style="border-bottom:2px solid #f44336; padding-bottom:20px; margin-bottom:20px;">
            <h1 style="color:#f44336; margin:0; font-size:18px;">ERROR: Fallo de Webhook</h1>
        </div>
        <div style="background-color:#ffcdd2; border-left:4px solid #f44336; padding:15px; margin:20px 0;">
            <strong>⚠️ Error técnico en procesar webhook de Pagadito</strong>
        </div>

        <p style="margin:0 0 10px 0;"><strong>Detalles del error:</strong></p>
        <div style="background-color:#f5f5f5; border:1px solid #dddddd; padding:10px; border-radius:3px; overflow-x:auto;">
            {error_message}
        </div>

        <p style="margin:15px 0 10px 0;"><strong>Información adicional:</strong></p>
        <ul style="padding-left:20px; margin:0 0 15px 0;">
            <li><strong>Timestamp:</strong> {timestamp}</li>
            <li><strong>Subscription ID:</strong> {subscription_id}</li>
        </ul>

        <p style="margin:0 0 10px 0;"><strong>Datos recibidos (parcial):</strong></p>
        <div style="background-color:#f5f5f5; border:1px solid #dddddd; padding:10px; border-radius:3px; overflow-x:auto;">
            {webhook_data}
        </div>

        <p style="margin:15px 0 0 0;">Por favor revisa los logs para más detalles.</p>
    </div>
    <div style="border-top:1px solid #dddddd; padding-top:20px; margin:20px auto 0 auto; max-width:600px; font-size:12px; color:#999999;">
        <p style="margin:0; text-align:center;">Este es un email automático de error técnico.</p>
    </div>
</body>
</html>';
    }
}
