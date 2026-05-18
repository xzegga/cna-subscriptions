<?php
/**
 * Activador del Plugin - Crea las tablas de base de datos
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Activator
{

    /**
     * Ejecuta la creación de tablas al activar el plugin
     */
    public static function activate()
    {
        // Cargar clases necesarias antes de activación
        require_once CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Core/class-cna-migrator.php';
        require_once CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Admin/class-cna-categories.php';

        CNA_Migrator::migrate();

        self::create_tables();

        // Inicializar variaciones por defecto si no existen
        self::initialize_default_variations();
    }

    /**
     * Inicializa las variaciones por defecto
     */
    private static function initialize_default_variations()
    {
        $variations = get_option('cna_product_variations', array());

        if (empty($variations)) {
            $default_variations = array(
                'small' => array('name' => 'Pequeño', 'slug' => 'small', 'order' => 1),
                'medium' => array('name' => 'Mediano', 'slug' => 'medium', 'order' => 2),
                'large' => array('name' => 'Grande', 'slug' => 'large', 'order' => 3),
            );
            update_option('cna_product_variations', $default_variations);
        }
    }

    /**
     * Crea las tablas personalizadas usando dbDelta
     */
    private static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_prefix = $wpdb->prefix;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. Tabla: wp_cna_payment_gateways
        $sql_gateways = "CREATE TABLE {$table_prefix}cna_payment_gateways (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            slug varchar(50) NOT NULL,
            is_active tinyint(1) DEFAULT 0,
            settings_json longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active)
        ) $charset_collate;";

        dbDelta($sql_gateways);

        // Inicializar Pagadito como gateway por defecto si no existe
        $existing_pagadito = $wpdb->get_var(
            "SELECT id FROM {$table_prefix}cna_payment_gateways WHERE slug = 'pagadito'"
        );

        if (!$existing_pagadito) {
            // Migrar configuración existente de wp_options a la tabla
            $uid = get_option('cna_pagadito_uid', '');
            $wsk = get_option('cna_pagadito_wsk', '');
            $sandbox = get_option('cna_pagadito_sandbox', '1');
            $fee = get_option('cna_pasarela_fee', '0.06');

            $settings = json_encode(array(
                'uid' => $uid,
                'wsk' => $wsk,
                'sandbox' => $sandbox === '1',
                'fee' => $fee,
            ), JSON_UNESCAPED_UNICODE);

            $wpdb->insert(
                $table_prefix . 'cna_payment_gateways',
                array(
                    'name' => 'Pagadito',
                    'slug' => 'pagadito',
                    'is_active' => !empty($uid) && !empty($wsk) ? 1 : 0,
                    'settings_json' => $settings,
                ),
                array('%s', '%s', '%d', '%s')
            );
        }

        // 2. Tabla: wp_cna_shipping_zones
        $sql_zones = "CREATE TABLE {$table_prefix}cna_shipping_zones (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_active (is_active)
        ) $charset_collate;";

        dbDelta($sql_zones);

        // 3. Tabla: wp_cna_shipping_locations
        $sql_locations = "CREATE TABLE {$table_prefix}cna_shipping_locations (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            zone_id bigint(20) UNSIGNED NOT NULL,
            country varchar(100) DEFAULT 'El Salvador',
            department varchar(100) NOT NULL,
            municipality varchar(100) NOT NULL,
            district varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY zone_id (zone_id),
            KEY location (country, department, municipality, district)
        ) $charset_collate;";

        dbDelta($sql_locations);

        // 4. Tabla: wp_cna_subscriptions (Maestra)
        $sql_subscriptions = "CREATE TABLE {$table_prefix}cna_subscriptions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            product_id bigint(20) UNSIGNED NOT NULL,
            status varchar(50) DEFAULT 'pending',
            pagadito_token varchar(255) DEFAULT NULL,
            is_auto_renew tinyint(1) DEFAULT 1,
            next_renewal_date date DEFAULT NULL,
            shipping_address_json longtext,
            variant_details longtext,
            shipping_cost_unit decimal(10,2) DEFAULT 0.00,
            unit_price decimal(10,2) DEFAULT 0.00,
            product_subtotal decimal(10,2) DEFAULT 0.00,
            advance_amount decimal(10,2) DEFAULT 0.00,
            shipping_total decimal(10,2) DEFAULT 0.00,
            annual_fee decimal(10,2) DEFAULT 0.00,
            net_amount decimal(10,2) DEFAULT 0.00,
            fee_amount decimal(10,2) DEFAULT 0.00,
            total_with_fee decimal(10,2) DEFAULT 0.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY product_id (product_id),
            KEY status (status),
            KEY next_renewal_date (next_renewal_date)
        ) $charset_collate;";

        dbDelta($sql_subscriptions);

        // 5. Tabla: wp_cna_pickup_stores
        $sql_stores = "CREATE TABLE {$table_prefix}cna_pickup_stores (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            address text NOT NULL,
            department varchar(100) DEFAULT NULL,
            municipality varchar(100) DEFAULT NULL,
            district varchar(100) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            hours text DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_active (is_active)
        ) $charset_collate;";

        dbDelta($sql_stores);

        // 6. Tabla: wp_cna_deliveries (Detalle)
        $sql_deliveries = "CREATE TABLE {$table_prefix}cna_deliveries (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id bigint(20) UNSIGNED NOT NULL,
            scheduled_date date NOT NULL,
            payment_status varchar(50) DEFAULT 'pending',
            amount_to_collect decimal(10,2) DEFAULT 0.00,
            delivery_status varchar(50) DEFAULT 'scheduled',
            delivered_at datetime DEFAULT NULL,
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY subscription_id (subscription_id),
            KEY scheduled_date (scheduled_date),
            KEY delivery_status (delivery_status)
        ) $charset_collate;";

        dbDelta($sql_deliveries);

        // 7. Tabla: wp_cna_audit_logs (Logs de Auditoría)
        $sql_audit_logs = "CREATE TABLE {$table_prefix}cna_audit_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            severity varchar(20) DEFAULT 'medium',
            user_id bigint(20) UNSIGNED DEFAULT 0,
            ip_address varchar(45) DEFAULT NULL,
            data_json longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY severity (severity),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql_audit_logs);

        // 8. Tabla: wp_cna_email_templates
        $sql_email_templates = "CREATE TABLE {$table_prefix}cna_email_templates (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slug varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            recipient_type enum('customer', 'admin') NOT NULL DEFAULT 'customer',
            subject varchar(255) NOT NULL,
            body_html longtext NOT NULL,
            is_enabled tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY recipient_type (recipient_type),
            KEY is_enabled (is_enabled)
        ) $charset_collate;";

        dbDelta($sql_email_templates);

        // 9. Tabla: wp_cna_email_logs
        $sql_email_logs = "CREATE TABLE {$table_prefix}cna_email_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            template_slug varchar(100) NOT NULL,
            recipient_email varchar(255) NOT NULL,
            recipient_user_id bigint(20) UNSIGNED,
            subscription_id bigint(20) UNSIGNED,
            status enum('sent', 'failed', 'pending') DEFAULT 'sent',
            error_message text,
            sent_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY template_slug (template_slug),
            KEY recipient_email (recipient_email),
            KEY subscription_id (subscription_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql_email_logs);

        // 8. Tabla: wp_cna_user_addresses (Direcciones de entrega de usuarios)
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

        // Datos por defecto
        self::create_default_data();
    }

    /**
     * Crea datos por defecto al activar el plugin
     */
    private static function create_default_data()
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // 1. Crear zona de envío por defecto: "Zona Metropolitana"
        $existing_zone = $wpdb->get_var(
            "SELECT id FROM {$table_prefix}cna_shipping_zones WHERE name = 'Zona Metropolitana'"
        );

        if (!$existing_zone) {
            $zone_id = $wpdb->insert(
                $table_prefix . 'cna_shipping_zones',
                array('name' => 'Zona Metropolitana', 'is_active' => 1),
                array('%s', '%d')
            );

            if ($zone_id) {
                $zone_id = $wpdb->insert_id;

                // Asignar distritos: Comasagua y Santa Tecla (La Libertad)
                $districts = array('Comasagua', 'Santa Tecla');
                foreach ($districts as $district) {
                    $wpdb->insert(
                        $table_prefix . 'cna_shipping_locations',
                        array(
                            'zone_id' => $zone_id,
                            'country' => 'El Salvador',
                            'department' => 'La Libertad',
                            'municipality' => 'La Libertad Sur',
                            'district' => $district,
                        ),
                        array('%d', '%s', '%s', '%s', '%s')
                    );
                }
            }
        }

        // Crear tabla de categorías de suscripción
        CNA_Categories::create_table();

        // Insertar email templates por defecto
        self::seed_email_templates();

        // 2. Crear tienda de recogida por defecto: "The Green Corner"
        $existing_store = $wpdb->get_var(
            "SELECT id FROM {$table_prefix}cna_pickup_stores WHERE name = 'The Green Corner'"
        );

        if (!$existing_store) {
            $default_hours = array(
                'monday' => array('open' => '08:00', 'close' => '18:00'),
                'tuesday' => array('open' => '08:00', 'close' => '18:00'),
                'wednesday' => array('open' => '08:00', 'close' => '18:00'),
                'thursday' => array('open' => '08:00', 'close' => '18:00'),
                'friday' => array('open' => '08:00', 'close' => '18:00'),
                'saturday' => array('open' => '08:00', 'close' => '18:00'),
                'sunday' => array('open' => '09:00', 'close' => '17:00'),
            );
            $hours_json = json_encode($default_hours, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $wpdb->insert(
                $table_prefix . 'cna_pickup_stores',
                array(
                    'name' => 'The Green Corner',
                    'address' => 'Calle La Reforma 227, San Salvador',
                    'phone' => '+503 2508 3392',
                    'hours' => $hours_json,
                    'is_active' => 1,
                ),
                array('%s', '%s', '%s', '%s', '%d')
            );
        }
    }

    /**
     * Inserta los templates de email predefinidos
     */
    private static function seed_email_templates()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_email_templates';

        // Verificar si ya existen templates
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        if (intval($count) > 0) {
            return; // Ya hay templates, no insertar
        }

        // Cargar el migrador solo para usar los templates
        require_once CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Core/class-cna-migrator.php';
        $templates = CNA_Migrator::get_default_templates();

        foreach ($templates as $template) {
            $wpdb->insert(
                $table_name,
                $template,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d')
            );
        }
    }

    /**
     * Desactivación del plugin (limpieza opcional)
     */
    public static function deactivate()
    {
        // Limpiar eventos de cron programados
        CNA_Cron::clear_scheduled_events();

        // Por ahora no eliminamos las tablas para preservar datos
        // Si se necesita limpieza completa, usar uninstall.php
    }
}
