<?php
/**
 * Página de Ajustes del Plugin
 * Gestiona credenciales de Pagadito, fee de pasarela y zonas de envío
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Settings
{

    /**
     * Inicializa los hooks
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'ensure_migrations'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Asegura que las migraciones se ejecuten
     * Se ejecuta cada vez que se carga el admin para verificar
     */
    public function ensure_migrations()
    {
        require_once CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Core/class-cna-migrator.php';
        CNA_Migrator::migrate();
    }

    /**
     * Agrega la página de ajustes al menú
     */
    public function add_settings_page()
    {
        // Agregar "Ajustes" después de "Suscripciones" (posición 10)
        add_submenu_page(
            'edit.php?post_type=cna_product',
            __('Ajustes', 'cna-subscriptions'),
            __('Ajustes', 'cna-subscriptions'),
            'manage_options',
            'cna-settings',
            array($this, 'render_settings_page'),
            10
        );
    }

    /**
     * Registra las opciones de configuración
     */
    public function register_settings()
    {
        // Credenciales Pagadito
        register_setting('cna_settings_group', 'cna_pagadito_uid');
        register_setting('cna_settings_group', 'cna_pagadito_wsk');
        register_setting('cna_settings_group', 'cna_pagadito_sandbox', array('default' => '1'));
        register_setting('cna_settings_group', 'cna_pasarela_fee', array('default' => '0.06'));

        // Configuración General
        register_setting('cna_settings_group', 'cna_payment_sandbox', array('default' => '0'));
        register_setting('cna_settings_group', 'cna_admin_email', array('default' => get_option('admin_email')));

        // Zonas (se manejan manualmente en BD)
    }

    /**
     * Encola scripts para el admin
     */
    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'cna_product_page_cna-settings') {
            return;
        }

        wp_enqueue_script('jquery');
    }

    /**
     * Renderiza la página de ajustes
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <h1><?php _e('Ajustes de Suscripciones', 'cna-subscriptions'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?post_type=cna_product&page=cna-settings&tab=general"
                    class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'cna-subscriptions'); ?>
                        </a>
                        <a href="?post_type=cna_product&page=cna-settings&tab=payments"
                            class="nav-tab <?php echo $active_tab === 'payments' ? 'nav-tab-active' : ''; ?>">
                            <?php _e('Métodos de Pago', 'cna-subscriptions'); ?>
                        </a>
                        <a href="?post_type=cna_product&page=cna-settings&tab=zones" 
                           class="nav-tab <?php echo $active_tab === 'zones' ? 'nav-tab-active' : ''; ?>">
                            <?php _e('Zonas de Envío', 'cna-subscriptions'); ?>
                        </a>
                        <a href="?post_type=cna_product&page=cna-settings&tab=stores" 
                           class="nav-tab <?php echo $active_tab === 'stores' ? 'nav-tab-active' : ''; ?>">
                            <?php _e('Tiendas de Recogida', 'cna-subscriptions'); ?>
                        </a>
                        <a href="?post_type=cna_product&page=cna-settings&tab=categories" 
                           class="nav-tab <?php echo $active_tab === 'categories' ? 'nav-tab-active' : ''; ?>">
                            <?php _e('Categorías', 'cna-subscriptions'); ?>
                        </a>
                        <a href="?post_type=cna_product&page=cna-settings&tab=emails" 
                           class="nav-tab <?php echo $active_tab === 'emails' ? 'nav-tab-active' : ''; ?>">
                            <?php _e('Email Templates', 'cna-subscriptions'); ?>
                        </a>
                    </nav>

                    <div class="tab-content">
                        <?php if ($active_tab === 'general'): ?>
                                <?php $this->render_general_tab(); ?>
                        <?php elseif ($active_tab === 'payments'): ?>
                                <?php $this->render_payments_tab(); ?>
                        <?php elseif ($active_tab === 'zones'): ?>
                                <?php $this->render_zones_tab(); ?>
                        <?php elseif ($active_tab === 'stores'): ?>
                                <?php $this->render_stores_tab(); ?>
                        <?php elseif ($active_tab === 'categories'): ?>
                                <?php $this->render_categories_tab(); ?>
                        <?php elseif ($active_tab === 'emails'): ?>
                                <?php $this->render_emails_tab(); ?>
                        <?php else: ?>
                                <?php $this->render_general_tab(); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
    }

    /**
     * Renderiza el tab de General
     */
    private function render_general_tab()
    {
        // Procesar guardado si viene del formulario
        if (isset($_POST['cna_general_submit']) && check_admin_referer('cna_general_settings', 'cna_general_nonce')) {
            update_option('cna_payment_sandbox', isset($_POST['cna_payment_sandbox']) ? '1' : '0');
            update_option('cna_admin_email', sanitize_email($_POST['cna_admin_email']));
            
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Configuración guardada exitosamente.', 'cna-subscriptions') . '</p></div>';
        }

        $payment_sandbox = get_option('cna_payment_sandbox', '0');
        $admin_email = get_option('cna_admin_email', get_option('admin_email'));
        ?>
        <div class="cna-general-settings">
            <h2><?php _e('Configuración General', 'cna-subscriptions'); ?></h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('cna_general_settings', 'cna_general_nonce'); ?>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="cna_payment_sandbox"><?php _e('Payment Sandbox', 'cna-subscriptions'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input 
                                        type="checkbox" 
                                        id="cna_payment_sandbox" 
                                        name="cna_payment_sandbox" 
                                        value="1" 
                                        <?php checked($payment_sandbox, '1'); ?>
                                    />
                                    <?php _e('Activar Payment Sandbox', 'cna-subscriptions'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Cuando está activo, el sistema saltará el pago real de Pagadito y emulará una respuesta exitosa. Útil para pruebas y desarrollo.', 'cna-subscriptions'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cna_admin_email"><?php _e('Administration Email', 'cna-subscriptions'); ?></label>
                            </th>
                            <td>
                                <input 
                                    type="email" 
                                    id="cna_admin_email" 
                                    name="cna_admin_email" 
                                    value="<?php echo esc_attr($admin_email); ?>" 
                                    class="regular-text"
                                    required
                                />
                                <p class="description">
                                    <?php _e('Email del administrador que recibirá las notificaciones del sistema (nuevas suscripciones, pagos recibidos, errores, etc.).', 'cna-subscriptions'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <input 
                        type="submit" 
                        name="cna_general_submit" 
                        class="button button-primary" 
                        value="<?php esc_attr_e('Guardar Cambios', 'cna-subscriptions'); ?>"
                    />
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Renderiza el tab de Pagos
     */
    private function render_payments_tab()
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener todos los gateways
        $gateways = $wpdb->get_results(
            "SELECT * FROM {$table_prefix}cna_payment_gateways ORDER BY name ASC"
        );

        // Gateway activo seleccionado
        $active_gateway_slug = isset($_GET['gateway']) ? sanitize_text_field($_GET['gateway']) : '';
        $active_gateway = null;

        if ($active_gateway_slug) {
            foreach ($gateways as $gateway) {
                if ($gateway->slug === $active_gateway_slug) {
                    $active_gateway = $gateway;
                    break;
                }
            }
        }
        ?>
                <div class="cna-payment-gateways-manager">
                    <h2><?php _e('Métodos de Pago', 'cna-subscriptions'); ?></h2>
                    <p class="description">
                        <?php _e('Gestiona los métodos de pago disponibles. Activa o desactiva cada método y configura sus opciones específicas.', 'cna-subscriptions'); ?>
                    </p>

                    <div style="display: flex; gap: 20px; margin-top: 20px;">
                        <!-- Lista de Gateways -->
                        <div style="flex: 0 0 300px;">
                            <h3><?php _e('Métodos Disponibles', 'cna-subscriptions'); ?></h3>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Método', 'cna-subscriptions'); ?></th>
                                        <th><?php _e('Estado', 'cna-subscriptions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($gateways)): ?>
                                            <tr>
                                                <td colspan="2"><?php _e('No hay métodos de pago configurados.', 'cna-subscriptions'); ?></td>
                                            </tr>
                                    <?php else: ?>
                                            <?php foreach ($gateways as $gateway):
                                                $settings = !empty($gateway->settings_json) ? json_decode($gateway->settings_json, true, 512, JSON_UNESCAPED_UNICODE) : array();
                                                if (json_last_error() !== JSON_ERROR_NONE) {
                                                    $settings = json_decode($gateway->settings_json, true);
                                                }
                                                ?>
                                                    <tr class="<?php echo $active_gateway_slug === $gateway->slug ? 'active' : ''; ?>">
                                                        <td>
                                                            <strong>
                                                                <a href="?post_type=cna_product&page=cna-settings&tab=payments&gateway=<?php echo esc_attr($gateway->slug); ?>">
                                                                    <?php echo esc_html($gateway->name); ?>
                                                                </a>
                                                            </strong>
                                                        </td>
                                                        <td>
                                                            <?php if ($gateway->is_active): ?>
                                                                    <span style="color: green;"><?php _e('Activo', 'cna-subscriptions'); ?></span>
                                                            <?php else: ?>
                                                                    <span style="color: #999;"><?php _e('Inactivo', 'cna-subscriptions'); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                            <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Configuración del Gateway -->
                        <div style="flex: 1;">
                            <?php if ($active_gateway): ?>
                                    <?php $this->render_gateway_settings($active_gateway); ?>
                            <?php else: ?>
                                    <div class="postbox">
                                        <div class="inside">
                                            <p><?php _e('Selecciona un método de pago de la lista para configurarlo.', 'cna-subscriptions'); ?></p>
                                        </div>
                                    </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php
    }

    /**
     * Renderiza la configuración de un gateway específico
     */
    private function render_gateway_settings($gateway)
    {
        $settings = !empty($gateway->settings_json) ? json_decode($gateway->settings_json, true, 512, JSON_UNESCAPED_UNICODE) : array();
        if (json_last_error() !== JSON_ERROR_NONE) {
            $settings = json_decode($gateway->settings_json, true);
        }

        if (!is_array($settings)) {
            $settings = array();
        }
        ?>
                <div class="postbox">
                    <div class="inside">
                        <h3><?php echo esc_html($gateway->name); ?></h3>
                
                        <form method="post" action="">
                            <?php wp_nonce_field('cna_gateway_action', 'cna_gateway_nonce'); ?>
                            <input type="hidden" name="cna_action" value="update_gateway" />
                            <input type="hidden" name="gateway_id" value="<?php echo esc_attr($gateway->id); ?>" />
                    
                            <?php if ($gateway->slug === 'pagadito'): ?>
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="gateway_is_active"><?php _e('Activar Método', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" 
                                                           id="gateway_is_active" 
                                                           name="gateway_is_active" 
                                                           value="1" 
                                                           <?php checked($gateway->is_active, 1); ?> />
                                                    <?php _e('Activar este método de pago', 'cna-subscriptions'); ?>
                                                </label>
                                            </td>
                                        </tr>
                                            <th scope="row">
                                                <label for="pagadito_uid"><?php _e('UID Pagadito', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <input type="text" 
                                                       id="pagadito_uid" 
                                                       name="pagadito_uid" 
                                                       value="<?php echo esc_attr($settings['uid'] ?? ''); ?>" 
                                                       class="regular-text" />
                                                <p class="description"><?php _e('Usuario ID de tu cuenta Pagadito', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="pagadito_wsk"><?php _e('WSK Pagadito', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <input type="password" 
                                                       id="pagadito_wsk" 
                                                       name="pagadito_wsk" 
                                                       value="<?php echo esc_attr($settings['wsk'] ?? ''); ?>" 
                                                       class="regular-text" />
                                                <p class="description"><?php _e('Web Service Key (Token de seguridad)', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="pagadito_sandbox"><?php _e('Modo Sandbox', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" 
                                                           id="pagadito_sandbox" 
                                                           name="pagadito_sandbox" 
                                                           value="1" 
                                                           <?php checked($settings['sandbox'] ?? true, true); ?> />
                                                    <?php _e('Activar modo Sandbox (Pruebas)', 'cna-subscriptions'); ?>
                                                </label>
                                                <p class="description"><?php _e('Desactiva esta opción para usar el modo Producción', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="pagadito_fee"><?php _e('Fee Pasarela (%)', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <input type="number" 
                                                       id="pagadito_fee" 
                                                       name="pagadito_fee" 
                                                       value="<?php echo esc_attr($settings['fee'] ?? '0.06'); ?>" 
                                                       step="0.001" 
                                                       min="0" 
                                                       max="1" 
                                                       class="small-text" />
                                                <p class="description"><?php _e('Porcentaje de comisión de la pasarela (ej: 0.06 = 6%)', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="pagadito_validate_ip"><?php _e('Validar IP de Webhook', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" 
                                                           id="pagadito_validate_ip" 
                                                           name="pagadito_validate_ip" 
                                                           value="1" 
                                                           <?php checked(get_option('cna_pagadito_validate_ip', false), true); ?> />
                                                    <?php _e('Activar validación de IP para webhooks', 'cna-subscriptions'); ?>
                                                </label>
                                                <p class="description"><?php _e('Si está activado, solo se aceptarán webhooks desde las IPs configuradas abajo.', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="pagadito_allowed_ips"><?php _e('IPs Permitidas', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <textarea 
                                                    id="pagadito_allowed_ips" 
                                                    name="pagadito_allowed_ips" 
                                                    rows="5" 
                                                    class="large-text code"
                                                    placeholder="Ejemplo:&#10;192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea(get_option('cna_pagadito_allowed_ips', '')); ?></textarea>
                                                <p class="description">
                                                    <?php _e('Ingresa una IP por línea. Solo se aplicará si la validación de IP está activada.', 'cna-subscriptions'); ?><br>
                                                    <strong><?php _e('URLs de Pagadito:', 'cna-subscriptions'); ?></strong><br>
                                                    <?php _e('Return URL:', 'cna-subscriptions'); ?> <code><?php echo esc_html(rest_url('cna/v1/payment-return')); ?></code><br>
                                                    <?php _e('Webhook URL:', 'cna-subscriptions'); ?> <code><?php echo esc_html(rest_url('cna/v1/webhook/pagadito')); ?></code>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                            <?php else: ?>
                                    <p><?php _e('Configuración para este método de pago no disponible aún.', 'cna-subscriptions'); ?></p>
                            <?php endif; ?>
                    
                            <?php submit_button(__('Guardar Configuración', 'cna-subscriptions')); ?>
                        </form>
                    </div>
                </div>
                <?php
    }

    /**
     * Maneja las acciones de gateways (actualizar configuración, activar/desactivar)
     */
    private function handle_gateway_action()
    {
        if (!isset($_POST['cna_gateway_nonce']) || !wp_verify_nonce($_POST['cna_gateway_nonce'], 'cna_gateway_action')) {
            wp_die(__('Error de seguridad', 'cna-subscriptions'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Sin permisos', 'cna-subscriptions'));
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $action = sanitize_text_field($_POST['cna_action']);

        if ($action === 'update_gateway') {
            $gateway_id = intval($_POST['gateway_id']);
            $gateway = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_payment_gateways WHERE id = %d",
                $gateway_id
            ));

            if (!$gateway) {
                add_settings_error('cna_gateways', 'gateway_not_found', __('Método de pago no encontrado', 'cna-subscriptions'));
                return;
            }

            $is_active = isset($_POST['gateway_is_active']) ? 1 : 0;
            $settings = array();

            // Configuración específica según el gateway
            if ($gateway->slug === 'pagadito') {
                $settings = array(
                    'uid' => sanitize_text_field($_POST['pagadito_uid'] ?? ''),
                    'wsk' => sanitize_text_field($_POST['pagadito_wsk'] ?? ''),
                    'sandbox' => isset($_POST['pagadito_sandbox']) && $_POST['pagadito_sandbox'] === '1',
                    'fee' => floatval($_POST['pagadito_fee'] ?? '0.06'),
                );

                // Guardar opciones de validación de IP (en wp_options, no en settings_json)
                $validate_ip = isset($_POST['pagadito_validate_ip']) && $_POST['pagadito_validate_ip'] === '1';
                update_option('cna_pagadito_validate_ip', $validate_ip);

                $allowed_ips = isset($_POST['pagadito_allowed_ips']) ? sanitize_textarea_field($_POST['pagadito_allowed_ips']) : '';
                update_option('cna_pagadito_allowed_ips', $allowed_ips);
            }

            $settings_json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $wpdb->update(
                $table_prefix . 'cna_payment_gateways',
                array(
                    'is_active' => $is_active,
                    'settings_json' => $settings_json,
                ),
                array('id' => $gateway_id),
                array('%d', '%s'),
                array('%d')
            );

            add_settings_error('cna_gateways', 'gateway_updated', __('Configuración guardada exitosamente', 'cna-subscriptions'), 'updated');
        }
    }

    /**
     * Renderiza el tab de Zonas
     */
    private function render_zones_tab()
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $locations_helper = new CNA_Locations_Helper();

        // Obtener todas las zonas
        $zones = $wpdb->get_results(
            "SELECT * FROM {$table_prefix}cna_shipping_zones ORDER BY name ASC"
        );

        // Obtener departamentos para el selector (El Salvador)
        $departments = $locations_helper->get_departments('El Salvador');
        ?>
                <div class="cna-zones-manager">
                    <h2><?php _e('Gestor de Zonas de Envío', 'cna-subscriptions'); ?></h2>

                    <!-- Formulario para crear nueva zona -->
                    <div class="postbox" style="margin-top: 20px;">
                        <div class="inside">
                            <h3><?php _e('Crear Nueva Zona', 'cna-subscriptions'); ?></h3>
                            <form method="post" action="">
                                <?php wp_nonce_field('cna_zone_action', 'cna_zone_nonce'); ?>
                                <input type="hidden" name="cna_action" value="create_zone" />
                                <table class="form-table">
                                    <tr>
                                        <th><label for="zone_name"><?php _e('Nombre de la Zona', 'cna-subscriptions'); ?></label></th>
                                        <td>
                                            <input type="text" 
                                                   id="zone_name" 
                                                   name="zone_name" 
                                                   class="regular-text" 
                                                   required />
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label><?php _e('Asignar Distritos', 'cna-subscriptions'); ?></label></th>
                                        <td>
                                            <div id="location-selector">
                                                <select id="zone_country" name="zone_country" class="location-select">
                                                    <option value="El Salvador" selected><?php _e('El Salvador', 'cna-subscriptions'); ?></option>
                                                </select>

                                                <select id="zone_department" name="zone_department" class="location-select" disabled>
                                                    <option value=""><?php _e('Seleccionar Departamento', 'cna-subscriptions'); ?></option>
                                                    <?php foreach ($departments as $dept): ?>
                                                            <option value="<?php echo esc_attr($dept); ?>"><?php echo esc_html($dept); ?></option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <select id="zone_municipality" name="zone_municipality" class="location-select" disabled>
                                                    <option value=""><?php _e('Seleccionar Municipio', 'cna-subscriptions'); ?></option>
                                                </select>

                                                <select id="zone_district" name="zone_district[]" class="location-select" multiple size="5" disabled>
                                                    <option value=""><?php _e('Seleccionar Distrito(s)', 'cna-subscriptions'); ?></option>
                                                </select>
                                            </div>
                                            <p class="description">
                                                <?php _e('Puedes seleccionar múltiples distritos manteniendo presionada la tecla Ctrl (Cmd en Mac)', 'cna-subscriptions'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                <?php submit_button(__('Crear Zona', 'cna-subscriptions')); ?>
                            </form>
                        </div>
                    </div>

                    <!-- Lista de zonas existentes -->
                    <h3><?php _e('Zonas Existentes', 'cna-subscriptions'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('ID', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Nombre', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Estado', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Distritos', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Acciones', 'cna-subscriptions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($zones)): ?>
                                    <tr>
                                        <td colspan="5"><?php _e('No hay zonas configuradas.', 'cna-subscriptions'); ?></td>
                                    </tr>
                            <?php else: ?>
                                    <?php foreach ($zones as $zone):
                                        $zone_locations = $wpdb->get_results($wpdb->prepare(
                                            "SELECT * FROM {$table_prefix}cna_shipping_locations WHERE zone_id = %d",
                                            $zone->id
                                        ));
                                        ?>
                                            <tr>
                                                <td><?php echo esc_html($zone->id); ?></td>
                                                <td><strong><?php echo esc_html($zone->name); ?></strong></td>
                                                <td>
                                                    <?php if ($zone->is_active): ?>
                                                            <span style="color: green;"><?php _e('Activa', 'cna-subscriptions'); ?></span>
                                                    <?php else: ?>
                                                            <span style="color: red;"><?php _e('Inactiva', 'cna-subscriptions'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (empty($zone_locations)): ?>
                                                            <em><?php _e('Sin distritos asignados', 'cna-subscriptions'); ?></em>
                                                    <?php else: ?>
                                                            <?php echo esc_html(count($zone_locations)); ?>                     <?php _e('distrito(s)', 'cna-subscriptions'); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="post" style="display: inline;">
                                                        <?php wp_nonce_field('cna_zone_action', 'cna_zone_nonce'); ?>
                                                        <input type="hidden" name="cna_action" value="toggle_zone" />
                                                        <input type="hidden" name="zone_id" value="<?php echo esc_attr($zone->id); ?>" />
                                                        <button type="submit" class="button button-small">
                                                            <?php echo $zone->is_active ? __('Desactivar', 'cna-subscriptions') : __('Activar', 'cna-subscriptions'); ?>
                                                        </button>
                                                    </form>
                                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php _e('¿Estás seguro de eliminar esta zona?', 'cna-subscriptions'); ?>');">
                                                        <?php wp_nonce_field('cna_zone_action', 'cna_zone_nonce'); ?>
                                                        <input type="hidden" name="cna_action" value="delete_zone" />
                                                        <input type="hidden" name="zone_id" value="<?php echo esc_attr($zone->id); ?>" />
                                                        <button type="submit" class="button button-small button-link-delete">
                                                            <?php _e('Eliminar', 'cna-subscriptions'); ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <script>
                // Script para selects en cascada: País -> Departamento -> Municipio -> Distritos
                jQuery(document).ready(function($) {
                    // Cargar datos de ubicaciones desde el helper PHP
                    var locationsData = <?php echo wp_json_encode($locations_helper->get_all_locations()); ?>;
            
                    // Habilitar departamento cuando se selecciona país
                    $('#zone_country').on('change', function() {
                        var country = $(this).val();
                        if (country && locationsData[country]) {
                            var departments = Object.keys(locationsData[country]);
                            $('#zone_department').empty().append('<option value=""><?php echo esc_js(__('Seleccionar Departamento', 'cna-subscriptions')); ?></option>');
                            $.each(departments, function(i, dept) {
                                $('#zone_department').append('<option value="' + dept + '">' + dept + '</option>');
                            });
                            $('#zone_department').prop('disabled', false);
                        } else {
                            $('#zone_department').prop('disabled', true);
                            $('#zone_municipality').prop('disabled', true);
                            $('#zone_district').prop('disabled', true);
                        }
                        // Reset municipios y distritos
                        $('#zone_municipality').empty().append('<option value=""><?php echo esc_js(__('Seleccionar Municipio', 'cna-subscriptions')); ?></option>').prop('disabled', true);
                        $('#zone_district').empty().prop('disabled', true);
                    });
            
                    // Cargar departamentos al inicio (El Salvador está seleccionado por defecto)
                    $('#zone_country').trigger('change');
            
                    // Cuando cambia el departamento
                    $('#zone_department').on('change', function() {
                        var country = $('#zone_country').val();
                        var dept = $(this).val();
                        var municipalities = locationsData[country] && locationsData[country][dept] || {};
                
                        $('#zone_municipality').empty().append('<option value=""><?php echo esc_js(__('Seleccionar Municipio', 'cna-subscriptions')); ?></option>');
                        $('#zone_district').empty().prop('disabled', true);
                
                        if (dept && municipalities) {
                            $.each(municipalities, function(muni, districts) {
                                $('#zone_municipality').append('<option value="' + muni + '">' + muni + '</option>');
                            });
                            $('#zone_municipality').prop('disabled', false);
                        } else {
                            $('#zone_municipality').prop('disabled', true);
                        }
                    });
            
                    // Cuando cambia el municipio
                    $('#zone_municipality').on('change', function() {
                        var country = $('#zone_country').val();
                        var dept = $('#zone_department').val();
                        var muni = $(this).val();
                        var districts = locationsData[country] && locationsData[country][dept] && locationsData[country][dept][muni] || [];
                
                        $('#zone_district').empty();
                        if (muni && districts.length) {
                            $.each(districts, function(i, district) {
                                $('#zone_district').append('<option value="' + district + '">' + district + '</option>');
                            });
                            $('#zone_district').prop('disabled', false);
                        } else {
                            $('#zone_district').prop('disabled', true);
                        }
                    });
                });
                </script>
                <?php
    }

    /**
     * Renderiza el tab de Tiendas de Recogida
     */
    private function render_stores_tab()
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener todas las tiendas
        $stores = $wpdb->get_results(
            "SELECT * FROM {$table_prefix}cna_pickup_stores ORDER BY name ASC"
        );
        ?>
                <div class="cna-stores-manager">
                    <h2><?php _e('Tiendas de Recogida', 'cna-subscriptions'); ?></h2>
                    <p class="description">
                        <?php _e('Gestiona las tiendas donde los clientes pueden recoger sus productos. La opción de recoger en tienda siempre está disponible y no tiene costo adicional.', 'cna-subscriptions'); ?>
                    </p>

                    <!-- Formulario para crear/editar tienda -->
                    <div class="postbox" style="margin-top: 20px;">
                        <div class="inside">
                            <h3><?php _e('Agregar Nueva Tienda', 'cna-subscriptions'); ?></h3>
                            <form method="post" action="" id="store-form">
                                <?php wp_nonce_field('cna_store_action', 'cna_store_nonce'); ?>
                                <input type="hidden" name="cna_action" value="create_store" />
                                <table class="form-table">
                                    <tr>
                                        <th><label for="store_name"><?php _e('Nombre de la Tienda', 'cna-subscriptions'); ?></label></th>
                                        <td>
                                            <input type="text" 
                                                   id="store_name" 
                                                   name="store_name" 
                                                   class="regular-text" 
                                                   required />
                                            <p class="description"><?php _e('Ej: Tienda Centro, Tienda Metrocentro', 'cna-subscriptions'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="store_address"><?php _e('Dirección', 'cna-subscriptions'); ?></label></th>
                                        <td>
                                            <textarea id="store_address" 
                                                      name="store_address" 
                                                      class="large-text" 
                                                      rows="2" 
                                                      required></textarea>
                                            <p class="description"><?php _e('Dirección completa de la tienda', 'cna-subscriptions'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="store_phone"><?php _e('Teléfono', 'cna-subscriptions'); ?></label></th>
                                        <td>
                                            <input type="text" 
                                                   id="store_phone" 
                                                   name="store_phone" 
                                                   class="regular-text" />
                                            <p class="description"><?php _e('Teléfono de contacto (opcional)', 'cna-subscriptions'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label><?php _e('Horarios', 'cna-subscriptions'); ?></label></th>
                                        <td>
                                            <div id="store-hours-container">
                                                <?php
                                                $days = array(
                                                    'monday' => __('Lunes', 'cna-subscriptions'),
                                                    'tuesday' => __('Martes', 'cna-subscriptions'),
                                                    'wednesday' => __('Miércoles', 'cna-subscriptions'),
                                                    'thursday' => __('Jueves', 'cna-subscriptions'),
                                                    'friday' => __('Viernes', 'cna-subscriptions'),
                                                    'saturday' => __('Sábado', 'cna-subscriptions'),
                                                    'sunday' => __('Domingo', 'cna-subscriptions'),
                                                );
                                                foreach ($days as $day_key => $day_label):
                                                    ?>
                                                        <div class="store-hour-row" style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">
                                                            <label style="display: inline-block; width: 120px; font-weight: bold;">
                                                                <?php echo esc_html($day_label); ?>
                                                            </label>
                                                            <label style="display: inline-block; margin-left: 10px;">
                                                                <input type="checkbox" 
                                                                       class="store-day-closed" 
                                                                       name="store_hours[<?php echo esc_attr($day_key); ?>][closed]" 
                                                                       value="1" />
                                                                <?php _e('Cerrado', 'cna-subscriptions'); ?>
                                                            </label>
                                                            <div class="store-hour-fields" style="display: inline-block; margin-left: 20px;">
                                                                <label>
                                                                    <?php _e('Abre a las', 'cna-subscriptions'); ?>
                                                                    <input type="time" 
                                                                           name="store_hours[<?php echo esc_attr($day_key); ?>][open]" 
                                                                           class="store-hour-open" 
                                                                           value="08:00" />
                                                                </label>
                                                                <label style="margin-left: 10px;">
                                                                    <?php _e('Cierra a las', 'cna-subscriptions'); ?>
                                                                    <input type="time" 
                                                                           name="store_hours[<?php echo esc_attr($day_key); ?>][close]" 
                                                                           class="store-hour-close" 
                                                                           value="18:00" />
                                                                </label>
                                                            </div>
                                                        </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <p class="description"><?php _e('Configura los horarios de atención para cada día de la semana', 'cna-subscriptions'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                                <?php submit_button(__('Agregar Tienda', 'cna-subscriptions')); ?>
                            </form>
                        </div>
                    </div>

                    <!-- Lista de tiendas existentes -->
                    <h3><?php _e('Tiendas Existentes', 'cna-subscriptions'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('ID', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Nombre', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Dirección', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Teléfono', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Horarios', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Estado', 'cna-subscriptions'); ?></th>
                                <th><?php _e('Acciones', 'cna-subscriptions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stores)): ?>
                                    <tr>
                                        <td colspan="7"><?php _e('No hay tiendas configuradas.', 'cna-subscriptions'); ?></td>
                                    </tr>
                            <?php else: ?>
                                    <?php foreach ($stores as $store):
                                        $hours_json = !empty($store->hours) ? json_decode($store->hours, true, 512, JSON_UNESCAPED_UNICODE) : array();
                                        if (json_last_error() !== JSON_ERROR_NONE) {
                                            $hours_json = json_decode($store->hours, true);
                                        }
                                        if (!is_array($hours_json)) {
                                            $hours_json = array();
                                        }
                                        $hours_display = $this->format_store_hours($hours_json);
                                        ?>
                                            <tr>
                                                <td><?php echo esc_html($store->id); ?></td>
                                                <td><strong><?php echo esc_html($store->name); ?></strong></td>
                                                <td><?php echo esc_html($store->address); ?></td>
                                                <td><?php echo esc_html($store->phone ?: '-'); ?></td>
                                                <td>
                                                    <?php if (!empty($hours_display)): ?>
                                                            <small><?php echo esc_html($hours_display); ?></small>
                                                    <?php else: ?>
                                                            <em><?php _e('Sin horarios configurados', 'cna-subscriptions'); ?></em>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($store->is_active): ?>
                                                            <span style="color: green;"><?php _e('Activa', 'cna-subscriptions'); ?></span>
                                                    <?php else: ?>
                                                            <span style="color: red;"><?php _e('Inactiva', 'cna-subscriptions'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="post" style="display: inline;">
                                                        <?php wp_nonce_field('cna_store_action', 'cna_store_nonce'); ?>
                                                        <input type="hidden" name="cna_action" value="toggle_store" />
                                                        <input type="hidden" name="store_id" value="<?php echo esc_attr($store->id); ?>" />
                                                        <button type="submit" class="button button-small">
                                                            <?php echo $store->is_active ? __('Desactivar', 'cna-subscriptions') : __('Activar', 'cna-subscriptions'); ?>
                                                        </button>
                                                    </form>
                                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php _e('¿Estás seguro de eliminar esta tienda?', 'cna-subscriptions'); ?>');">
                                                        <?php wp_nonce_field('cna_store_action', 'cna_store_nonce'); ?>
                                                        <input type="hidden" name="cna_action" value="delete_store" />
                                                        <input type="hidden" name="store_id" value="<?php echo esc_attr($store->id); ?>" />
                                                        <button type="submit" class="button button-small button-link-delete">
                                                            <?php _e('Eliminar', 'cna-subscriptions'); ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    // Manejar checkboxes de "Cerrado"
                    $('.store-day-closed').on('change', function() {
                        var $row = $(this).closest('.store-hour-row');
                        var $fields = $row.find('.store-hour-fields');
                
                        if ($(this).is(':checked')) {
                            $fields.hide();
                            $fields.find('input').prop('disabled', true);
                        } else {
                            $fields.show();
                            $fields.find('input').prop('disabled', false);
                        }
                    });

                    // Inicializar estado de campos según checkboxes
                    $('.store-day-closed').each(function() {
                        if ($(this).is(':checked')) {
                            $(this).trigger('change');
                        }
                    });
                });
                </script>
                <?php
    }

    /**
     * Formatea los horarios de una tienda para mostrar
     */
    private function format_store_hours($hours_json)
    {
        if (empty($hours_json) || !is_array($hours_json)) {
            return '';
        }

        $days_labels = array(
            'monday' => __('Lun', 'cna-subscriptions'),
            'tuesday' => __('Mar', 'cna-subscriptions'),
            'wednesday' => __('Mié', 'cna-subscriptions'),
            'thursday' => __('Jue', 'cna-subscriptions'),
            'friday' => __('Vie', 'cna-subscriptions'),
            'saturday' => __('Sáb', 'cna-subscriptions'),
            'sunday' => __('Dom', 'cna-subscriptions'),
        );

        $formatted = array();
        foreach ($hours_json as $day => $schedule) {
            if (isset($schedule['closed']) && ($schedule['closed'] === true || $schedule['closed'] === '1' || $schedule['closed'] === 1)) {
                $formatted[] = $days_labels[$day] . ': ' . __('Cerrado', 'cna-subscriptions');
            } elseif (isset($schedule['open']) && isset($schedule['close'])) {
                $open_time = $schedule['open'];
                $close_time = $schedule['close'];
                // Formatear hora (asumiendo formato HH:MM)
                $open_formatted = date('g:i A', strtotime($open_time));
                $close_formatted = date('g:i A', strtotime($close_time));
                $formatted[] = $days_labels[$day] . ': ' . $open_formatted . ' - ' . $close_formatted;
            }
        }

        return implode(', ', $formatted);
    }

    /**
     * Maneja las acciones de zonas (crear, activar/desactivar, eliminar)
     */
    private function handle_zone_action()
    {
        if (!isset($_POST['cna_zone_nonce']) || !wp_verify_nonce($_POST['cna_zone_nonce'], 'cna_zone_action')) {
            wp_die(__('Error de seguridad', 'cna-subscriptions'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Sin permisos', 'cna-subscriptions'));
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $action = sanitize_text_field($_POST['cna_action']);

        switch ($action) {
            case 'create_zone':
                $zone_name = sanitize_text_field($_POST['zone_name']);
                if (empty($zone_name)) {
                    add_settings_error('cna_zones', 'zone_name_empty', __('El nombre de la zona es requerido', 'cna-subscriptions'));
                    return;
                }

                $zone_id = $wpdb->insert(
                    $table_prefix . 'cna_shipping_zones',
                    array('name' => $zone_name, 'is_active' => 1),
                    array('%s', '%d')
                );

                if ($zone_id) {
                    $zone_id = $wpdb->insert_id;
                    // Asignar distritos
                    if (isset($_POST['zone_district']) && is_array($_POST['zone_district'])) {
                        $country = sanitize_text_field($_POST['zone_country'] ?? 'El Salvador');
                        $department = sanitize_text_field($_POST['zone_department']);
                        $municipality = sanitize_text_field($_POST['zone_municipality']);

                        foreach ($_POST['zone_district'] as $district) {
                            $district = sanitize_text_field($district);
                            if (!empty($district)) {
                                $wpdb->insert(
                                    $table_prefix . 'cna_shipping_locations',
                                    array(
                                        'zone_id' => $zone_id,
                                        'country' => $country,
                                        'department' => $department,
                                        'municipality' => $municipality,
                                        'district' => $district
                                    ),
                                    array('%d', '%s', '%s', '%s', '%s')
                                );
                            }
                        }
                    }
                    add_settings_error('cna_zones', 'zone_created', __('Zona creada exitosamente', 'cna-subscriptions'), 'updated');
                }
                break;

            case 'toggle_zone':
                $zone_id = intval($_POST['zone_id']);
                $current_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT is_active FROM {$table_prefix}cna_shipping_zones WHERE id = %d",
                    $zone_id
                ));
                $new_status = $current_status ? 0 : 1;

                $wpdb->update(
                    $table_prefix . 'cna_shipping_zones',
                    array('is_active' => $new_status),
                    array('id' => $zone_id),
                    array('%d'),
                    array('%d')
                );
                break;

            case 'delete_zone':
                $zone_id = intval($_POST['zone_id']);
                $wpdb->delete(
                    $table_prefix . 'cna_shipping_zones',
                    array('id' => $zone_id),
                    array('%d')
                );
                // Las locations se eliminan automáticamente por CASCADE
                break;
        }
    }

    /**
     * Maneja los envíos de formularios antes de renderizar la página
     * Se ejecuta en admin_init para evitar problemas con headers
     */
    public function handle_form_submissions()
    {
        if (!isset($_POST['cna_action'])) {
            return;
        }

        // Procesar acciones para email templates
        if ($_POST['cna_action'] === 'update_email_template') {
            $this->handle_email_template_update();
            return;
        }

        // Procesar acciones para zonas, gateways, tiendas y categorías
        if (strpos($_POST['cna_action'], 'gateway') !== false) {
            $this->handle_gateway_action();
        } elseif (strpos($_POST['cna_action'], 'store') !== false) {
            $this->handle_store_action();
        } elseif (strpos($_POST['cna_action'], 'category') !== false) {
            $this->handle_category_action();
        } else {
            $this->handle_zone_action();
        }
    }

    /**
     * Maneja acciones de categorías (crear, actualizar, eliminar)
     */
    private function handle_category_action()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Verificar nonce
        if (!isset($_POST['cna_category_nonce']) || !wp_verify_nonce($_POST['cna_category_nonce'], 'cna_category_nonce')) {
            wp_die(__('Verificación de seguridad fallida', 'cna-subscriptions'));
        }

        $action = sanitize_text_field($_POST['cna_action']);
        $categories = new CNA_Categories();

        switch ($action) {
            case 'create_category':
                $name = sanitize_text_field($_POST['category_name'] ?? '');
                $slug = sanitize_title($_POST['category_slug'] ?? '');
                $description = sanitize_textarea_field($_POST['category_description'] ?? '');
                $color = sanitize_text_field($_POST['category_color'] ?? '#000000');

                if (!empty($name)) {
                    $result = $categories->create(array(
                        'name' => $name,
                        'slug' => $slug,
                        'description' => $description,
                        'color' => $color,
                    ));

                    if (!is_wp_error($result)) {
                        wp_redirect(add_query_arg(
                            array('post_type' => 'cna_product', 'page' => 'cna-settings', 'tab' => 'categories', 'msg' => 'created'),
                            admin_url('edit.php')
                        ));
                        exit;
                    }
                }
                break;

            case 'update_category':
                $category_id = intval($_POST['category_id'] ?? 0);
                $name = sanitize_text_field($_POST['category_name'] ?? '');
                $description = sanitize_textarea_field($_POST['category_description'] ?? '');
                $color = sanitize_text_field($_POST['category_color'] ?? '#000000');

                if ($category_id && !empty($name)) {
                    $result = $categories->update($category_id, array(
                        'name' => $name,
                        'description' => $description,
                        'color' => $color,
                    ));

                    if ($result !== false && !is_wp_error($result)) {
                        wp_redirect(add_query_arg(
                            array('post_type' => 'cna_product', 'page' => 'cna-settings', 'tab' => 'categories', 'msg' => 'updated'),
                            admin_url('edit.php')
                        ));
                        exit;
                    }
                }
                break;

            case 'delete_category':
                $category_id = intval($_POST['category_id'] ?? 0);

                if ($category_id) {
                    $result = $categories->delete($category_id);

                    if ($result !== false && !is_wp_error($result)) {
                        wp_redirect(add_query_arg(
                            array('post_type' => 'cna_product', 'page' => 'cna-settings', 'tab' => 'categories', 'msg' => 'deleted'),
                            admin_url('edit.php')
                        ));
                        exit;
                    }
                }
                break;
        }
    }

    /**
     * Renderiza el tab de Categorías
     */
    private function render_categories_tab()
    {
        $categories = new CNA_Categories();
        $all_categories = $categories->get_all('display_order');
        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_category = $edit_id > 0 ? $categories->get_by_id($edit_id) : null;
        $msg = isset($_GET['msg']) ? sanitize_text_field($_GET['msg']) : '';

        ?>
                <div class="wrap">
                    <div style="display: flex; gap: 20px; margin-top: 20px;">
                        <!-- Formulario de Categoría -->
                        <div style="flex: 1;">
                            <div class="postbox" style="padding: 20px;">
                                <h3><?php echo $edit_category ? __('Editar Categoría', 'cna-subscriptions') : __('Nueva Categoría', 'cna-subscriptions'); ?></h3>

                                <?php if ($msg === 'created'): ?>
                                        <div class="notice notice-success"><p><?php _e('Categoría creada exitosamente', 'cna-subscriptions'); ?></p></div>
                                <?php elseif ($msg === 'updated'): ?>
                                        <div class="notice notice-success"><p><?php _e('Categoría actualizada exitosamente', 'cna-subscriptions'); ?></p></div>
                                <?php elseif ($msg === 'deleted'): ?>
                                        <div class="notice notice-success"><p><?php _e('Categoría eliminada exitosamente', 'cna-subscriptions'); ?></p></div>
                                <?php endif; ?>

                                <form method="POST">
                                    <?php wp_nonce_field('cna_category_nonce', 'cna_category_nonce'); ?>

                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="category_name"><?php _e('Nombre', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <input 
                                                    type="text" 
                                                    id="category_name" 
                                                    name="category_name" 
                                                    class="regular-text" 
                                                    value="<?php echo $edit_category ? esc_attr($edit_category['name']) : ''; ?>" 
                                                    required
                                                />
                                                <p class="description"><?php _e('Nombre visible de la categoría', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="category_slug"><?php _e('Slug', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <input 
                                                    type="text" 
                                                    id="category_slug" 
                                                    name="category_slug" 
                                                    class="regular-text" 
                                                    value="<?php echo $edit_category ? esc_attr($edit_category['slug']) : ''; ?>"
                                                    <?php echo $edit_category ? 'readonly' : ''; ?>
                                                />
                                                <p class="description"><?php _e('Identificador único (auto-generado si está vacío)', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="category_color"><?php _e('Color', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <input 
                                                    type="color" 
                                                    id="category_color" 
                                                    name="category_color" 
                                                    value="<?php echo $edit_category ? esc_attr($edit_category['color']) : '#000000'; ?>" 
                                                />
                                                <p class="description"><?php _e('Color para identificar la categoría en el frontend', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="category_description"><?php _e('Descripción', 'cna-subscriptions'); ?></label>
                                            </th>
                                            <td>
                                                <textarea 
                                                    id="category_description" 
                                                    name="category_description" 
                                                    rows="4" 
                                                    class="large-text"
                                                ><?php echo $edit_category ? esc_textarea($edit_category['description']) : ''; ?></textarea>
                                                <p class="description"><?php _e('Descripción opcional de la categoría', 'cna-subscriptions'); ?></p>
                                            </td>
                                        </tr>
                                    </table>

                                    <p>
                                        <input 
                                            type="hidden" 
                                            name="cna_action" 
                                            value="<?php echo $edit_category ? 'update_category' : 'create_category'; ?>" 
                                        />
                                        <?php if ($edit_category): ?>
                                                <input type="hidden" name="category_id" value="<?php echo esc_attr($edit_category['id']); ?>" />
                                        <?php endif; ?>
                                        <input type="submit" class="button button-primary" value="<?php echo $edit_category ? __('Actualizar', 'cna-subscriptions') : __('Crear Categoría', 'cna-subscriptions'); ?>" />
                                        <?php if ($edit_category): ?>
                                                <a href="?post_type=cna_product&page=cna-settings&tab=categories" class="button"><?php _e('Cancelar', 'cna-subscriptions'); ?></a>
                                        <?php endif; ?>
                                    </p>
                                </form>
                            </div>
                        </div>

                        <!-- Lista de Categorías -->
                        <div style="flex: 1;">
                            <div class="postbox" style="padding: 20px;">
                                <h3><?php _e('Categorías', 'cna-subscriptions'); ?></h3>

                                <?php if (empty($all_categories)): ?>
                                        <p style="color: #999;"><?php _e('No hay categorías creadas aún', 'cna-subscriptions'); ?></p>
                                <?php else: ?>
                                        <table class="widefat" style="margin-top: 20px;">
                                            <thead>
                                                <tr>
                                                    <th><?php _e('Nombre', 'cna-subscriptions'); ?></th>
                                                    <th><?php _e('Slug', 'cna-subscriptions'); ?></th>
                                                    <th><?php _e('Color', 'cna-subscriptions'); ?></th>
                                                    <th><?php _e('Acciones', 'cna-subscriptions'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($all_categories as $category): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?php echo esc_html($category['name']); ?></strong>
                                                                <?php if (!empty($category['description'])): ?>
                                                                        <br><small><?php echo esc_html($category['description']); ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo esc_html($category['slug']); ?></td>
                                                            <td>
                                                                <span style="display: inline-block; width: 30px; height: 30px; background-color: <?php echo esc_attr($category['color']); ?>; border: 1px solid #ccc; border-radius: 3px;" title="<?php echo esc_attr($category['color']); ?>"></span>
                                                            </td>
                                                            <td>
                                                                <a href="?post_type=cna_product&page=cna-settings&tab=categories&edit=<?php echo $category['id']; ?>" class="button button-small"><?php _e('Editar', 'cna-subscriptions'); ?></a>
                                                                <form method="POST" style="display: inline;">
                                                                    <?php wp_nonce_field('cna_category_nonce', 'cna_category_nonce'); ?>
                                                                    <input type="hidden" name="cna_action" value="delete_category" />
                                                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>" />
                                                                    <input 
                                                                        type="submit" 
                                                                        class="button button-small button-link-delete" 
                                                                        value="<?php _e('Eliminar', 'cna-subscriptions'); ?>"
                                                                        onclick="return confirm('<?php echo esc_attr(__('¿Está seguro?', 'cna-subscriptions')); ?>');"
                                                                    />
                                                                </form>
                                                            </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
    }

    /**
     * Maneja la actualización de email templates
     */
    private function handle_email_template_update()
    {
        if (!isset($_POST['cna_email_template_nonce']) || !wp_verify_nonce($_POST['cna_email_template_nonce'], 'cna_email_template_action')) {
            wp_die(__('Error de seguridad', 'cna-subscriptions'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Sin permisos', 'cna-subscriptions'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_email_templates';
        $template_id = intval($_POST['template_id']);

        // Permitir bloques <style> para conservar el formato de los correos
        $allowed_tags = wp_kses_allowed_html('post');
        $allowed_tags['style'] = array();

        $result = $wpdb->update(
            $table_name,
            array(
                'subject' => sanitize_text_field($_POST['template_subject']),
                'body_html' => wp_kses($_POST['template_body'], $allowed_tags),
                'is_enabled' => isset($_POST['template_enabled']) ? 1 : 0,
            ),
            array('id' => $template_id),
            array('%s', '%s', '%d'),
            array('%d')
        );

        if ($result !== false) {
            $redirect = add_query_arg(
                array(
                    'post_type' => 'cna_product',
                    'page' => 'cna-settings',
                    'tab' => 'emails',
                    'msg' => 'updated'
                ),
                admin_url('edit.php')
            );
            wp_redirect($redirect);
            exit;
        }
    }

    /**
     * Renderiza el tab de Email Templates
     */
    private function render_emails_tab()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_email_templates';

        // Verificar si la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

        if (!$table_exists) {
            echo '<div class="notice notice-error"><p>';
            _e('La tabla de email templates no ha sido creada. Por favor reactive el plugin.', 'cna-subscriptions');
            echo '</p></div>';
            return;
        }

        // Detectar acción
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        // Obtener todos los templates
        $templates = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id ASC", ARRAY_A);

        // Actualizar plantillas antiguas que aún usan <style> a la versión inline
        if (!empty($templates)) {
            require_once CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Core/class-cna-migrator.php';
            $defaults = CNA_Migrator::get_default_templates();
            $defaults_by_slug = array();
            foreach ($defaults as $tpl) {
                $defaults_by_slug[$tpl['slug']] = $tpl;
            }

            foreach ($templates as $tpl) {
                if (strpos($tpl['body_html'], '<style') !== false && isset($defaults_by_slug[$tpl['slug']])) {
                    $wpdb->update(
                        $table_name,
                        array('body_html' => $defaults_by_slug[$tpl['slug']]['body_html']),
                        array('id' => $tpl['id']),
                        array('%s'),
                        array('%d')
                    );
                }
            }

            // recargar después de posibles updates
            $templates = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id ASC", ARRAY_A);
        }

        // Renderizar según acción
        if ($action === 'edit' && $template_id) {
            $this->render_email_template_editor($template_id);
        } elseif ($action === 'preview' && $template_id) {
            $this->render_email_template_preview($template_id);
        } else {
            $this->render_email_templates_list($templates);
        }
    }

    /**
     * Renderiza la lista de email templates
     */
    private function render_email_templates_list($templates)
    {
        ?>
                <div class="wrap">
                    <div class="email-templates-container" style="margin-top: 20px;">
                        <h2><?php _e('Email Templates', 'cna-subscriptions'); ?></h2>
                
                        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
                                <div class="notice notice-success is-dismissible">
                                    <p><?php _e('Template actualizado correctamente', 'cna-subscriptions'); ?></p>
                                </div>
                        <?php endif; ?>

                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Nombre', 'cna-subscriptions'); ?></th>
                                    <th><?php _e('Slug', 'cna-subscriptions'); ?></th>
                                    <th><?php _e('Tipo', 'cna-subscriptions'); ?></th>
                                    <th><?php _e('Estado', 'cna-subscriptions'); ?></th>
                                    <th><?php _e('Acciones', 'cna-subscriptions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($templates): ?>
                                        <?php foreach ($templates as $template): ?>
                                                <tr>
                                                    <td><strong><?php echo esc_html($template['name']); ?></strong></td>
                                                    <td><code><?php echo esc_html($template['slug']); ?></code></td>
                                                    <td>
                                                        <?php
                                                        $type = strpos($template['slug'], 'admin_') === 0 ? 'Admin' : 'Customer';
                                                        echo esc_html($type);
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="dashicons <?php echo $template['is_enabled'] ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                                                        <?php echo $template['is_enabled'] ? __('Habilitado', 'cna-subscriptions') : __('Deshabilitado', 'cna-subscriptions'); ?>
                                                    </td>
                                                    <td>
                                                        <a href="?post_type=cna_product&page=cna-settings&tab=emails&action=edit&id=<?php echo esc_attr($template['id']); ?>" class="button button-small">
                                                            <?php _e('Editar', 'cna-subscriptions'); ?>
                                                        </a>
                                                        <a href="?post_type=cna_product&page=cna-settings&tab=emails&action=preview&id=<?php echo esc_attr($template['id']); ?>" class="button button-small" target="_blank">
                                                            <?php _e('Preview', 'cna-subscriptions'); ?>
                                                        </a>
                                                    </td>
                                                </tr>
                                        <?php endforeach; ?>
                                <?php else: ?>
                                        <tr>
                                            <td colspan="5"><?php _e('No hay templates disponibles', 'cna-subscriptions'); ?></td>
                                        </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <p style="margin-top: 20px; color: #666; font-size: 12px;">
                            <strong><?php _e('Placeholders disponibles:', 'cna-subscriptions'); ?></strong><br>
                            <code>{customer_name}</code>, <code>{customer_email}</code>, <code>{product_name}</code>, 
                            <code>{subscription_id}</code>, <code>{total_amount}</code>, <code>{payment_url}</code>,
                            <code>{first_delivery_date}</code>, <code>{product_frequency}</code>, <code>{support_contact}</code>
                        </p>
                    </div>
                </div>
                <?php
    }

    /**
     * Renderiza el editor de email template
     */
    private function render_email_template_editor($template_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_email_templates';

        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $template_id
        ), ARRAY_A);

        if (!$template) {
            echo '<div class="notice notice-error"><p>' . __('Template no encontrado', 'cna-subscriptions') . '</p></div>';
            return;
        }
        ?>
                <div class="wrap">
                    <h2>
                        <?php _e('Editar Template:', 'cna-subscriptions'); ?> 
                        <?php echo esc_html($template['name']); ?>
                        <a href="?post_type=cna_product&page=cna-settings&tab=emails" class="page-title-action"><?php _e('← Volver', 'cna-subscriptions'); ?></a>
                    </h2>

                    <form method="post" action="" style="max-width: 900px;">
                        <?php wp_nonce_field('cna_email_template_action', 'cna_email_template_nonce'); ?>
                        <input type="hidden" name="cna_action" value="update_email_template" />
                        <input type="hidden" name="template_id" value="<?php echo esc_attr($template['id']); ?>" />

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Información', 'cna-subscriptions'); ?></label>
                                </th>
                                <td>
                                    <p><strong><?php _e('Slug:', 'cna-subscriptions'); ?></strong> <code><?php echo esc_html($template['slug']); ?></code></p>
                                    <p><strong><?php _e('Tipo:', 'cna-subscriptions'); ?></strong> <?php echo esc_html(ucfirst($template['recipient_type'])); ?></p>
                                    <?php if (!empty($template['description'])): ?>
                                            <p class="description"><?php echo esc_html($template['description']); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="template_enabled"><?php _e('Estado', 'cna-subscriptions'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               id="template_enabled" 
                                               name="template_enabled" 
                                               value="1" 
                                               <?php checked($template['is_enabled'], 1); ?> />
                                        <?php _e('Template habilitado', 'cna-subscriptions'); ?>
                                    </label>
                                    <p class="description"><?php _e('Si está deshabilitado, este email no se enviará', 'cna-subscriptions'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="template_subject"><?php _e('Asunto', 'cna-subscriptions'); ?></label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="template_subject" 
                                           name="template_subject" 
                                           value="<?php echo esc_attr($template['subject']); ?>" 
                                           class="large-text" 
                                           required />
                                    <p class="description"><?php _e('Puedes usar placeholders como {customer_name}, {product_name}, etc.', 'cna-subscriptions'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="template_body"><?php _e('Contenido HTML', 'cna-subscriptions'); ?></label>
                                </th>
                                <td>
                                    <?php
                                    wp_editor(
                                        $template['body_html'],
                                        'template_body',
                                        array(
                                            'textarea_name' => 'template_body',
                                            'textarea_rows' => 20,
                                            'media_buttons' => false,
                                            'teeny' => false,
                                            'tinymce' => array(
                                                'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,forecolor,backcolor',
                                                'toolbar2' => 'alignleft,aligncenter,alignright,outdent,indent,undo,redo,removeformat,code',
                                            ),
                                        )
                                    );
                                    ?>
                                    <p class="description"><?php _e('Código HTML del email. Usa placeholders para datos dinámicos.', 'cna-subscriptions'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <div style="margin-top: 20px;">
                            <input type="submit" class="button button-primary button-large" value="<?php _e('Guardar Cambios', 'cna-subscriptions'); ?>" />
                            <a href="?post_type=cna_product&page=cna-settings&tab=emails&action=preview&id=<?php echo esc_attr($template['id']); ?>" class="button button-large" target="_blank">
                                <?php _e('Ver Preview', 'cna-subscriptions'); ?>
                            </a>
                            <a href="?post_type=cna_product&page=cna-settings&tab=emails" class="button button-large"><?php _e('Cancelar', 'cna-subscriptions'); ?></a>
                        </div>
                    </form>

                    <div style="margin-top: 30px; padding: 15px; background: #f0f0f1; border-left: 4px solid #72aee6;">
                        <h3><?php _e('Placeholders Disponibles', 'cna-subscriptions'); ?></h3>
                        <div style="columns: 2; column-gap: 20px;">
                            <p><code>{customer_name}</code> - Nombre del cliente</p>
                            <p><code>{customer_email}</code> - Email del cliente</p>
                            <p><code>{customer_phone}</code> - Teléfono del cliente</p>
                            <p><code>{product_name}</code> - Nombre del producto</p>
                            <p><code>{product_qty}</code> - Cantidad</p>
                            <p><code>{product_frequency}</code> - Frecuencia de entrega</p>
                            <p><code>{subscription_id}</code> - ID de suscripción</p>
                            <p><code>{total_amount}</code> - Monto total</p>
                            <p><code>{payment_url}</code> - URL de pago</p>
                            <p><code>{first_delivery_date}</code> - Primera fecha de entrega</p>
                            <p><code>{support_contact}</code> - Contacto de soporte</p>
                            <p><code>{account_link}</code> - Enlace a cuenta de usuario</p>
                        </div>
                    </div>
                </div>
                <?php
    }

    /**
     * Renderiza el preview del email template con datos de ejemplo
     */
    private function render_email_template_preview($template_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_email_templates';

        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $template_id
        ), ARRAY_A);

        if (!$template) {
            wp_die(__('Template no encontrado', 'cna-subscriptions'));
        }

        // Datos de ejemplo para reemplazar placeholders
        $sample_data = array(
            '{customer_name}' => 'Juan Pérez',
            '{customer_email}' => 'juan.perez@example.com',
            '{customer_phone}' => '+502 1234-5678',
            '{user_name}' => 'Juan Pérez',
            '{user_email}' => 'juan.perez@example.com',
            '{product_name}' => 'Canasta Semanal de Verduras',
            '{product_qty}' => '1',
            '{product_frequency}' => 'Semanal',
            '{subscription_id}' => '12345',
            '{total_amount}' => '150.00',
            '{amount_paid}' => '150.00',
            '{amount}' => '150.00',
            '{payment_url}' => home_url('/completar-pago/12345'),
            '{retry_url}' => home_url('/reintentar-pago/12345'),
            '{first_delivery_date}' => date('d/m/Y', strtotime('+7 days')),
            '{next_deliveries}' => date('d/m/Y', strtotime('+7 days')) . ', ' . date('d/m/Y', strtotime('+14 days')),
            '{support_contact}' => get_option('admin_email'),
            '{account_link}' => home_url('/mi-cuenta'),
            '{login_link}' => wp_login_url(),
            '{reactivation_link}' => home_url('/mi-cuenta/suscripciones'),
            '{dashboard_link}' => admin_url('edit.php?post_type=cna_product'),
            '{user_profile_link}' => admin_url('user-edit.php?user_id=1'),
            '{registration_date}' => date('d/m/Y'),
            '{new_transaction_id}' => 'TXN-' . rand(10000, 99999),
            '{renewal_amount}' => '150.00',
            '{renewal_period}' => date('d/m/Y') . ' - ' . date('d/m/Y', strtotime('+30 days')),
            '{delivery_count}' => '4',
            '{error_reason}' => 'Fondos insuficientes',
            '{error_message}' => 'Error al procesar el webhook de Pagadito',
            '{timestamp}' => date('Y-m-d H:i:s'),
            '{webhook_data}' => 'token=ABC123, status=COMPLETED',
        );

        // Reemplazar placeholders en subject y body
        $subject = str_replace(array_keys($sample_data), array_values($sample_data), $template['subject']);
        $body = str_replace(array_keys($sample_data), array_values($sample_data), $template['body_html']);

        // Renderizar preview
        ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title><?php echo esc_html($subject); ?></title>
                    <style>
                        body {
                            margin: 0;
                            padding: 20px;
                            background-color: #f5f5f5;
                            font-family: Arial, sans-serif;
                        }
                        .preview-header {
                            background: #fff;
                            padding: 20px;
                            margin-bottom: 20px;
                            border-left: 4px solid #2196F3;
                        }
                        .preview-header h1 {
                            margin: 0 0 10px 0;
                            font-size: 18px;
                            color: #2196F3;
                        }
                        .preview-header p {
                            margin: 5px 0;
                            color: #666;
                        }
                        .preview-content {
                            background: #fff;
                            padding: 0;
                        }
                    </style>
                </head>
                <body>
                    <div class="preview-header">
                        <h1><?php _e('PREVIEW - Email Template', 'cna-subscriptions'); ?></h1>
                        <p><strong><?php _e('Template:', 'cna-subscriptions'); ?></strong> <?php echo esc_html($template['name']); ?></p>
                        <p><strong><?php _e('Asunto:', 'cna-subscriptions'); ?></strong> <?php echo esc_html($subject); ?></p>
                        <p><em><?php _e('Los datos mostrados son ejemplos de cómo se verá el email con información real.', 'cna-subscriptions'); ?></em></p>
                    </div>
                    <div class="preview-content">
                        <?php echo $body; // Already sanitized with wp_kses_post when saved ?>
                    </div>
                </body>
                </html>
                <?php
                exit; // Importante: detener ejecución para mostrar solo el preview
    }
}

