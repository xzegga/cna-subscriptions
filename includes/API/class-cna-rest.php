<?php
/**
 * Controlador REST API
 * Endpoints para el frontend React y webhooks de Pagadito
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_REST_Controller
{

    /**
     * Namespace de la API
     */
    const NAMESPACE = 'cna/v1';

    /**
     * Inicializa los endpoints
     */
    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Registra las rutas REST
     */
    public function register_routes()
    {
        // GET /shipping-rate
        register_rest_route(self::NAMESPACE , '/shipping-rate', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_shipping_rate'),
            'permission_callback' => '__return_true', // Público para el checkout
            'args' => array(
                'product_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ),
                'district' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'country' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => 'El Salvador',
                ),
                'department' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'municipality' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // GET /shipping-options (retorna home + pickup)
        register_rest_route(self::NAMESPACE , '/shipping-options', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_shipping_options'),
            'permission_callback' => '__return_true',
            'args' => array(
                'product_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ),
                'district' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'country' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => 'El Salvador',
                ),
                'department' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'municipality' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // GET /pickup-stores
        register_rest_route(self::NAMESPACE , '/pickup-stores', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pickup_stores'),
            'permission_callback' => '__return_true',
        ));

        // POST /create-order
        register_rest_route(self::NAMESPACE , '/create-order', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_order'),
            'permission_callback' => array($this, 'check_rate_limit'), // Rate limiting básico
        ));

        // GET /payment-return (Return URL de Pagadito)
        register_rest_route(self::NAMESPACE , '/payment-return', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_payment_return'),
            'permission_callback' => '__return_true', // Público, validación interna
        ));

        // POST /webhook/pagadito
        register_rest_route(self::NAMESPACE , '/webhook/pagadito', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_pagadito_webhook'),
            'permission_callback' => '__return_true', // Público, validación interna
            // Nota: La validación de origen del webhook debe implementarse según
            // la documentación específica de Pagadito (firma, IP whitelist, etc.)
        ));

        // GET /user/subscriptions
        register_rest_route(self::NAMESPACE , '/user/subscriptions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_subscriptions'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));

        // GET /subscriptions/(?P<id>\d+)/deliveries
        register_rest_route(self::NAMESPACE , '/subscriptions/(?P<id>\d+)/deliveries', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_subscription_deliveries'),
            'permission_callback' => array($this, 'check_user_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ),
            ),
        ));

        // POST /subscriptions/(?P<id>\d+)/toggle-renew
        register_rest_route(self::NAMESPACE , '/subscriptions/(?P<id>\d+)/toggle-renew', array(
            'methods' => 'POST',
            'callback' => array($this, 'toggle_subscription_renewal'),
            'permission_callback' => array($this, 'check_user_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ),
            ),
        ));

        // POST /subscriptions/(?P<id>\d+)/action
        register_rest_route(self::NAMESPACE , '/subscriptions/(?P<id>\d+)/action', array(
            'methods' => 'POST',
            'callback' => array($this, 'perform_subscription_action'),
            'permission_callback' => array($this, 'check_user_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ),
            ),
        ));

        // GET /locations
        register_rest_route(self::NAMESPACE , '/locations', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_locations_data'),
            'permission_callback' => '__return_true', // Público
        ));

        // POST /register
        register_rest_route(self::NAMESPACE , '/register', array(
            'methods' => 'POST',
            'callback' => array($this, 'register_user'),
            'permission_callback' => '__return_true', // Público, validación interna
        ));

        // POST /login
        register_rest_route(self::NAMESPACE , '/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'login_user'),
            'permission_callback' => '__return_true', // Público, validación interna
        ));

        // GET /user/data
        register_rest_route(self::NAMESPACE , '/user/data', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_current_user_data'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));

        // GET /gateway-fee
        register_rest_route(self::NAMESPACE , '/gateway-fee', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gateway_fee'),
            'permission_callback' => '__return_true', // Público para el checkout
        ));

        // GET /subscriptions/(?P<id>\d+)
        register_rest_route(self::NAMESPACE , '/subscriptions/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_subscription_details'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));

        // GET /user/addresses
        register_rest_route(self::NAMESPACE , '/user/addresses', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_addresses'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));

        // POST /user/addresses
        register_rest_route(self::NAMESPACE , '/user/addresses', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_user_address'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));

        // PUT /user/addresses/(?P<id>\d+)
        register_rest_route(self::NAMESPACE , '/user/addresses/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_user_address'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));
    }

    /**
     * Endpoint: GET /shipping-rate
     * Calcula el precio de envío para un producto en un distrito específico
     */
    public function get_shipping_rate($request)
    {
        $product_id = $request->get_param('product_id');
        $district = $request->get_param('district');
        $department = $request->get_param('department');
        $municipality = $request->get_param('municipality');

        // Buscar la zona del distrito
        $locations_helper = new CNA_Locations_Helper();
        $country = $request->get_param('country') ?: 'El Salvador';
        $zone_id = $locations_helper->find_zone_by_location($department, $municipality, $district, $country);

        if (!$zone_id) {
            return new WP_Error(
                'no_coverage',
                __('No hay cobertura de envío para esta ubicación', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        // Obtener precio de envío del producto para esta zona
        $shipping_prices = get_post_meta($product_id, '_cna_shipping_prices', true);

        if (!is_array($shipping_prices) || !isset($shipping_prices[$zone_id])) {
            return new WP_Error(
                'no_shipping_price',
                __('No hay precio de envío configurado para esta zona', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        // Obtener nombre de la zona
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $zone_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$table_prefix}cna_shipping_zones WHERE id = %d",
            $zone_id
        ));

        return rest_ensure_response(array(
            'price' => floatval($shipping_prices[$zone_id]),
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
        ));
    }

    /**
     * Endpoint: GET /shipping-options
     * Retorna todas las opciones de envío disponibles (home + pickup)
     */
    public function get_shipping_options($request)
    {
        $product_id = $request->get_param('product_id');
        $district = $request->get_param('district');
        $department = $request->get_param('department');
        $municipality = $request->get_param('municipality');
        $country = $request->get_param('country') ?: 'El Salvador';

        $options = array();

        // Opción 1: Envío a domicilio (si hay zona configurada)
        if ($district && $department && $municipality) {
            $locations_helper = new CNA_Locations_Helper();
            $zone_id = $locations_helper->find_zone_by_location($department, $municipality, $district, $country);

            if ($zone_id) {
                $shipping_prices = get_post_meta($product_id, '_cna_shipping_prices', true);

                if (is_array($shipping_prices) && isset($shipping_prices[$zone_id])) {
                    $options[] = array(
                        'type' => 'home',
                        'label' => __('Envío a domicilio', 'cna-subscriptions'),
                        'cost' => floatval($shipping_prices[$zone_id]),
                        'zone_id' => $zone_id,
                    );
                }
            }
        }

        // Opción 2: Recoger en tienda (siempre disponible)
        $options[] = array(
            'type' => 'pickup',
            'label' => __('Recoger en tienda', 'cna-subscriptions'),
            'cost' => 0.00,
        );

        return rest_ensure_response(array(
            'options' => $options,
        ));
    }

    /**
     * Endpoint: GET /pickup-stores
     * Retorna todas las tiendas de recogida activas
     */
    public function get_pickup_stores($request)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Usar prepare aunque la query sea estática (mejores prácticas de seguridad)
        $stores = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, address, department, municipality, district, phone, hours 
                 FROM {$table_prefix}cna_pickup_stores 
                 WHERE is_active = %d 
                 ORDER BY name ASC",
                1
            )
        );

        $stores_data = array();
        foreach ($stores as $store) {
            $stores_data[] = array(
                'id' => intval($store->id),
                'name' => sanitize_text_field($store->name),
                'address' => sanitize_text_field($store->address),
                'department' => sanitize_text_field($store->department),
                'municipality' => sanitize_text_field($store->municipality),
                'district' => sanitize_text_field($store->district),
                'phone' => sanitize_text_field($store->phone),
                'hours' => $store->hours, // JSON, se mantiene como está pero se valida en frontend
            );
        }

        return rest_ensure_response(array(
            'stores' => $stores_data,
        ));
    }

    /**
     * Endpoint: POST /create-order
     * Crea una suscripción y genera URL de pago en Pagadito
     */
    public function create_order($request)
    {
        // Log inicial de la petición
        error_log('CNA create_order: Iniciando creación de orden');
        error_log('CNA create_order: Request data: ' . print_r($request->get_json_params(), true));

        $data = $request->get_json_params();

        // Validar datos requeridos
        $required = array('product_id', 'user_id', 'variant', 'shipping');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $error_msg = sprintf('Campo requerido faltante: %s', $field);
                error_log('CNA create_order ERROR: ' . $error_msg);
                return new WP_Error(
                    'missing_field',
                    sprintf(__('Campo requerido faltante: %s', 'cna-subscriptions'), $field),
                    array('status' => 400)
                );
            }
        }

        $product_id = intval($data['product_id']);
        $user_id = intval($data['user_id']);
        $variant = $data['variant']; // {size, qty, frequency, advance_percent}
        $shipping = $data['shipping']; // {department, municipality, district, address, type}
        $billing = isset($data['billing']) ? $data['billing'] : null; // {address_1, city, state, reference}
        $user_metadata = isset($data['user_metadata']) ? $data['user_metadata'] : null; // {first_name, last_name, user_email, nationality}

        // Validar que el usuario existe y está autenticado
        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            // Si no hay usuario autenticado, verificar que el user_id sea válido
            $user = get_user_by('id', $user_id);
            if (!$user) {
                return new WP_Error(
                    'invalid_user',
                    __('Usuario no válido', 'cna-subscriptions'),
                    array('status' => 401)
                );
            }
        } else {
            // Si hay usuario autenticado, verificar que coincida con el user_id enviado
            if ($current_user_id !== $user_id) {
                return new WP_Error(
                    'unauthorized',
                    __('No autorizado para crear suscripciones para este usuario', 'cna-subscriptions'),
                    array('status' => 403)
                );
            }
        }

        // Verificar que el producto existe y está publicado
        if (get_post_type($product_id) !== 'cna_product') {
            return new WP_Error(
                'invalid_product',
                __('Producto no válido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $product_status = get_post_status($product_id);
        if ($product_status !== 'publish') {
            return new WP_Error(
                'product_not_available',
                __('El producto no está disponible para compra', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Permitir múltiples suscripciones del mismo producto
        // (Comentado: La validación de duplicados fue eliminada para permitir múltiples suscripciones)

        // Validar estructura y tipos de datos de variant
        if (!is_array($variant)) {
            return new WP_Error(
                'invalid_variant',
                __('Datos de variante inválidos', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $required_variant_fields = array('size', 'qty', 'frequency', 'advance_percent');
        foreach ($required_variant_fields as $field) {
            if (!isset($variant[$field])) {
                return new WP_Error(
                    'missing_variant_field',
                    sprintf(__('Campo requerido faltante en variante: %s', 'cna-subscriptions'), $field),
                    array('status' => 400)
                );
            }
        }

        // Validar tipos y rangos de variant
        $qty = intval($variant['qty']);
        $frequency = intval($variant['frequency']);
        $advance_percent = floatval($variant['advance_percent']);

        $min_qty = CNA_Product_Helper::get_min_qty($product_id);
        if ($qty < $min_qty || $qty > 100) {
            return new WP_Error(
                'invalid_quantity',
                sprintf(
                    __('La cantidad debe estar entre %d y 100', 'cna-subscriptions'),
                    $min_qty
                ),
                array('status' => 400)
            );
        }

        if ($frequency <= 0 || $frequency > 52) {
            return new WP_Error(
                'invalid_frequency',
                __('La frecuencia debe estar entre 1 y 52 semanas', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        if ($advance_percent < 0 || $advance_percent > 100) {
            return new WP_Error(
                'invalid_advance_percent',
                __('El porcentaje de anticipo debe estar entre 0 y 100', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Validar estructura de shipping
        if (!is_array($shipping)) {
            return new WP_Error(
                'invalid_shipping',
                __('Datos de envío inválidos', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        if (empty($shipping['type']) || !in_array($shipping['type'], array('home', 'pickup'))) {
            return new WP_Error(
                'invalid_shipping_type',
                __('Tipo de envío inválido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Calcular precios
        error_log('CNA create_order: Calculando totales de la orden');
        $prices = $this->calculate_order_totals($product_id, $variant, $shipping);

        if (is_wp_error($prices)) {
            $error_msg = $prices->get_error_message();
            error_log('CNA create_order ERROR cálculo: ' . $error_msg);
            error_log('CNA create_order ERROR cálculo code: ' . $prices->get_error_code());

            // Log de error en cálculo
            CNA_Audit_Logger::log(
                CNA_Audit_Logger::EVENT_AMOUNT_CALCULATED,
                array(
                    'subscription_id' => 0,
                    'product_id' => $product_id,
                    'user_id' => $user_id,
                    'error' => $error_msg,
                    'variant' => $variant,
                ),
                CNA_Audit_Logger::SEVERITY_HIGH
            );
            return $prices;
        }

        error_log('CNA create_order: Totales calculados: ' . print_r($prices, true));

        // Log de cálculo exitoso
        CNA_Audit_Logger::log(
            CNA_Audit_Logger::EVENT_AMOUNT_CALCULATED,
            array(
                'subscription_id' => 0,
                'product_id' => $product_id,
                'user_id' => $user_id,
                'amount' => $prices['total_with_fee'],
                'net_amount' => $prices['net_amount'],
                'fee_amount' => $prices['fee_amount'],
            ),
            CNA_Audit_Logger::SEVERITY_MEDIUM
        );

        // Actualizar metadatos del usuario si se enviaron
        // Se actualizan siempre para permitir edición de campos existentes
        if ($user_metadata && is_array($user_metadata)) {
            error_log('CNA create_order: Actualizando metadatos del usuario');
            if (isset($user_metadata['first_name']) && $user_metadata['first_name'] !== '') {
                update_user_meta($user_id, 'first_name', sanitize_text_field($user_metadata['first_name']));
            }
            if (isset($user_metadata['last_name']) && $user_metadata['last_name'] !== '') {
                update_user_meta($user_id, 'last_name', sanitize_text_field($user_metadata['last_name']));
            }
            if (isset($user_metadata['user_email']) && !empty($user_metadata['user_email']) && is_email($user_metadata['user_email'])) {
                wp_update_user(array(
                    'ID' => $user_id,
                    'user_email' => sanitize_email($user_metadata['user_email']),
                ));
            }
            if (isset($user_metadata['nationality']) && $user_metadata['nationality'] !== '') {
                update_user_meta($user_id, 'nationality', sanitize_text_field($user_metadata['nationality']));
            }
            // Guardar teléfono siempre que se envíe (incluso si ya existe, para permitir edición)
            if (isset($user_metadata['phone']) && $user_metadata['phone'] !== '') {
                update_user_meta($user_id, 'phone', sanitize_text_field($user_metadata['phone']));
                error_log('CNA create_order: Teléfono guardado/actualizado: ' . $user_metadata['phone']);
            }
        }

        // Si no se envió billing pero shipping.type es 'home', duplicar shipping como billing
        if (!$billing && $shipping['type'] === 'home' && !empty($shipping['address'])) {
            $billing = array(
                'address_1' => $shipping['address'],
                'city' => $shipping['municipality'],
                'state' => $shipping['department'],
                'country' => $shipping['country'] ?? 'El Salvador',
                'reference' => '',
            );
        }

        // Guardar datos de facturación en metadatos del usuario si están disponibles
        if ($billing && is_array($billing)) {
            update_user_meta($user_id, 'billing_address_1', sanitize_text_field($billing['address_1'] ?? ''));
            update_user_meta($user_id, 'billing_city', sanitize_text_field($billing['city'] ?? ''));
            update_user_meta($user_id, 'billing_state', sanitize_text_field($billing['state'] ?? ''));
            update_user_meta($user_id, 'billing_country', sanitize_text_field($billing['country'] ?? ''));
            if (!empty($billing['reference'])) {
                update_user_meta($user_id, 'billing_reference', sanitize_text_field($billing['reference']));
            }
        }

        // Guardar dirección de envío en la tabla de direcciones del usuario (si es envío a domicilio)
        if ($shipping['type'] === 'home' && !empty($shipping['address']) && !empty($shipping['department']) && !empty($shipping['municipality']) && !empty($shipping['district'])) {
            global $wpdb;
            $table_prefix = $wpdb->prefix;
            
            // Verificar si ya existe una dirección idéntica para este usuario
            $existing_address = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_prefix}cna_user_addresses 
                 WHERE user_id = %d 
                 AND department = %s 
                 AND municipality = %s 
                 AND district = %s 
                 AND address = %s 
                 LIMIT 1",
                $user_id,
                $shipping['department'],
                $shipping['municipality'],
                $shipping['district'],
                $shipping['address']
            ));

            // Si no existe, guardarla (la primera dirección se marca como default automáticamente)
            if (!$existing_address) {
                // Verificar si el usuario ya tiene una dirección por defecto
                $has_default = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_prefix}cna_user_addresses WHERE user_id = %d AND is_default = 1",
                    $user_id
                ));

                $is_default = $has_default == 0 ? 1 : 0; // Primera dirección es default

                $wpdb->insert(
                    $table_prefix . 'cna_user_addresses',
                    array(
                        'user_id' => $user_id,
                        'label' => 'Mi dirección',
                        'country' => $shipping['country'] ?? 'El Salvador',
                        'department' => sanitize_text_field($shipping['department']),
                        'municipality' => sanitize_text_field($shipping['municipality']),
                        'district' => sanitize_text_field($shipping['district']),
                        'address' => sanitize_textarea_field($shipping['address']),
                        'is_default' => $is_default,
                    ),
                    array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
                );
            }
        }

        // Crear suscripción en BD con estado 'pending'
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Incluir billing en shipping_address_json si existe
        $shipping_data_for_db = $shipping;
        if ($billing) {
            $shipping_data_for_db['billing'] = $billing;
        }

        // Guardar todos los totales calculados de forma inmutable
        $subscription_data = array(
            'user_id' => $user_id,
            'product_id' => $product_id,
            'status' => 'pending',
            'shipping_address_json' => wp_json_encode($shipping_data_for_db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'variant_details' => wp_json_encode($variant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'shipping_cost_unit' => $prices['shipping_unit'],
            'unit_price' => $prices['unit_price'],
            'product_subtotal' => $prices['product_subtotal'],
            'advance_amount' => $prices['advance_amount'],
            'shipping_total' => $prices['shipping_total'],
            'annual_fee' => $prices['annual_fee'],
            'net_amount' => $prices['net_amount'],
            'fee_amount' => $prices['fee_amount'],
            'total_with_fee' => $prices['total_with_fee'],
            'is_auto_renew' => isset($data['auto_renew']) ? (int) $data['auto_renew'] : 1,
        );

        error_log('CNA create_order: Intentando insertar suscripción en BD');
        $result = $wpdb->insert(
            $table_prefix . 'cna_subscriptions',
            $subscription_data,
            array('%d', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%d')
        );

        if (!$result) {
            $db_error = $wpdb->last_error;
            error_log('CNA create_order ERROR DB: ' . $db_error);
            error_log('CNA create_order ERROR DB Query: ' . $wpdb->last_query);
            return new WP_Error(
                'db_error',
                __('Error al crear la suscripción', 'cna-subscriptions'),
                array('status' => 500)
            );
        }

        $subscription_id = $wpdb->insert_id;
        error_log('CNA create_order: Suscripción creada con ID: ' . $subscription_id);

        // Log de creación de orden
        CNA_Audit_Logger::log(
            CNA_Audit_Logger::EVENT_ORDER_CREATED,
            array(
                'subscription_id' => $subscription_id,
                'product_id' => $product_id,
                'user_id' => $user_id,
                'amount' => $prices['total_with_fee'],
                'net_amount' => $prices['net_amount'],
                'status' => 'pending',
            ),
            CNA_Audit_Logger::SEVERITY_MEDIUM
        );

        // Generar y guardar fechas de entrega inmediatamente al crear la orden
        $this->create_delivery_dates_for_subscription($subscription_id, $variant, $product_id);

        // Enviar email de nueva suscripción al admin
        if (class_exists('CNA_Mailer')) {
            CNA_Mailer::send_admin_new_subscription($subscription_id);
        }

        // Verificar si Payment Sandbox está activo
        $payment_sandbox = get_option('cna_payment_sandbox', '0') === '1';
        
        if ($payment_sandbox) {
            // Modo Sandbox: Saltar Pagadito y emular respuesta exitosa
            error_log('CNA create_order: Payment Sandbox activo - Emulando respuesta exitosa');
            
            // Emular respuesta exitosa de Pagadito
            $mock_response = array(
                'success' => true,
                'code' => 'PG1002',
                'message' => 'Transacción creada exitosamente (Sandbox)',
                'redirect_url' => rest_url('cna/v1/payment-return?subscription_id=' . $subscription_id . '&status=success&sandbox=1'),
                'token' => 'sandbox_token_' . $subscription_id,
                'transaction_id' => 'SANDBOX_' . time() . '_' . $subscription_id,
                'approval_number' => 'SANDBOX_' . $subscription_id,
                'transaction_type' => 'payment_to_accredit',
                'currency' => 'USD',
                'date' => wp_date('d/m/Y'),
                'hour' => wp_date('H:i'),
            );
            
            // NO enviar email aquí - se enviará en process_successful_payment_sandbox que llama a process_successful_payment
            
            // Procesar pago exitoso directamente (como si viniera del webhook)
            $this->process_successful_payment_sandbox($subscription_id, $mock_response);
            
            // Retornar URL de "pago" exitoso
            return rest_ensure_response(array(
                'subscription_id' => $subscription_id,
                'payment_url' => $mock_response['redirect_url'],
                'totals' => $prices,
                'sandbox' => true,
            ));
        }

        // Crear transacción en Pagadito con tokenización
        $pagadito_client = new CNA_Pagadito_Client();

        $transaction_data = array(
            'amount' => $prices['total_with_fee'],
            'description' => sprintf(
                __('Suscripción #%d - %s', 'cna-subscriptions'),
                $subscription_id,
                get_the_title($product_id)
            ),
            'subscription_id' => $subscription_id,
            'custom_params' => array(
                'param1' => $subscription_id,  // subscription_id
                'param2' => $product_id,       // product_id
                'param3' => $user_id,          // user_id
            ),
        );

        // ERN = subscription_id (Pagadito lo devuelve como referencia al regresar del pago)
        $pagadito_ern = (string) $subscription_id;
        $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            array('pagadito_ern' => $pagadito_ern),
            array('id' => $subscription_id),
            array('%s'),
            array('%d')
        );

        error_log('CNA create_order: Llamando a Pagadito para crear transacción tokenizada');
        error_log('CNA create_order: Transaction data: ' . print_r($transaction_data, true));
        $pagadito_response = $pagadito_client->create_tokenized_transaction($transaction_data);

        if (is_wp_error($pagadito_response)) {
            $error_msg = $pagadito_response->get_error_message();
            error_log('CNA create_order ERROR Pagadito: ' . $error_msg);
            error_log('CNA create_order ERROR Pagadito Code: ' . $pagadito_response->get_error_code());
            $error_details = $pagadito_response->get_error_data();
            if (!empty($error_details['http'])) {
                error_log('CNA create_order ERROR Pagadito HTTP: ' . print_r($error_details['http'], true));
            }
            if (!empty($error_details['response'])) {
                error_log('CNA create_order ERROR Pagadito Response: ' . print_r($error_details['response'], true));
            }

            // Marcar suscripción como fallida
            $wpdb->update(
                $table_prefix . 'cna_subscriptions',
                array('status' => 'payment_failed'),
                array('id' => $subscription_id),
                array('%s'),
                array('%d')
            );

            // Log de error al crear transacción
            CNA_Audit_Logger::log(
                CNA_Audit_Logger::EVENT_PAYMENT_FAILED,
                array(
                    'subscription_id' => $subscription_id,
                    'product_id' => $product_id,
                    'user_id' => $user_id,
                    'error' => $error_msg,
                    'stage' => 'transaction_creation',
                ),
                CNA_Audit_Logger::SEVERITY_HIGH
            );

            return $pagadito_response;
        }

        error_log('CNA create_order: Respuesta de Pagadito recibida: ' . print_r($pagadito_response, true));
        $payment_url = CNA_Pagadito_Client::get_payment_url($pagadito_response);

        if (!$payment_url) {
            error_log('CNA create_order ERROR: No se pudo extraer payment_url de la respuesta de Pagadito');
            error_log('CNA create_order ERROR: Respuesta completa: ' . print_r($pagadito_response, true));
            return new WP_Error(
                'no_payment_url',
                __('No se pudo obtener la URL de pago', 'cna-subscriptions'),
                array('status' => 500)
            );
        }

        error_log('CNA create_order: Payment URL obtenida exitosamente: ' . $payment_url);

        // Guardar ern (número de orden de Pagadito) en la suscripción
        if (isset($pagadito_response['ern'])) {
            $wpdb->update(
                $table_prefix . 'cna_subscriptions',
                array('pagadito_ern' => sanitize_text_field($pagadito_response['ern'])),
                array('id' => $subscription_id),
                array('%s'),
                array('%d')
            );
            error_log('CNA create_order: ERN guardado: ' . $pagadito_response['ern']);
        }

        // Guardar ID de transacción de Pagadito (si viene en la respuesta)
        if (isset($pagadito_response['transaction_id'])) {
            update_post_meta($product_id, '_cna_pagadito_transaction_' . $subscription_id, $pagadito_response['transaction_id']);
        }

        // NO enviar email aquí - se enviará después de confirmar el pago en process_successful_payment

        return rest_ensure_response(array(
            'subscription_id' => $subscription_id,
            'payment_url' => $payment_url,
            'totals' => $prices,
        ));
    }

    /**
     * Resuelve una suscripción a partir de los parámetros del retorno de Pagadito.
     *
     * @param WP_REST_Request $request
     * @return object|null
     */
    private function resolve_subscription_from_payment_return($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cna_subscriptions';

        $subscription_id = intval($request->get_param('subscription_id'));
        if ($subscription_id > 0) {
            $subscription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $subscription_id
            ));
            if ($subscription) {
                return $subscription;
            }
        }

        // Pagadito puede enviar el ERN con distintos nombres de parámetro
        $ern_candidates = array(
            $request->get_param('ern'),
            $request->get_param('ERN'),
            $request->get_param('order_id'),
            $request->get_param('reference'),
            $request->get_param('ref'),
        );

        foreach ($ern_candidates as $ern) {
            if (empty($ern)) {
                continue;
            }

            $ern = sanitize_text_field($ern);

            $subscription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE pagadito_ern = %s",
                $ern
            ));
            if ($subscription) {
                return $subscription;
            }

            if (ctype_digit($ern)) {
                $subscription = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d",
                    intval($ern)
                ));
                if ($subscription) {
                    return $subscription;
                }
            }
        }

        // custom_params enviados en la URL de retorno
        $param1 = $request->get_param('param1');
        if (!empty($param1) && ctype_digit((string) $param1)) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                intval($param1)
            ));
        }

        return null;
    }

    /**
     * Endpoint: GET /payment-return
     * Maneja el retorno del usuario después del pago en Pagadito
     */
    public function handle_payment_return($request)
    {
        $status = $request->get_param('status');
        $transaction_id = $request->get_param('transaction_id');
        $token = $request->get_param('token');

        error_log('CNA payment_return: Retorno de Pagadito recibido');
        error_log('CNA payment_return: query_params=' . wp_json_encode($request->get_query_params()));

        $subscription = $this->resolve_subscription_from_payment_return($request);

        if (!$subscription) {
            error_log('CNA payment_return: No se pudo resolver la suscripción');
            wp_safe_redirect(home_url('/confirmacion-orden?error=subscription_not_found'));
            exit;
        }

        $subscription_id = $subscription->id;
        error_log('CNA payment_return: Suscripción resuelta ID=' . $subscription_id);

        if (empty($subscription->payment_transaction_json) && class_exists('CNA_Payment_Transaction')) {
            $return_payload = array_merge(
                $request->get_query_params(),
                array_filter(array(
                    'transaction_id' => $transaction_id,
                    'token' => $token,
                    'status' => $status,
                    'currency' => 'USD',
                ))
            );
            CNA_Payment_Transaction::save_from_webhook(
                $subscription_id,
                $this->resolve_payment_provider_slug(),
                $return_payload
            );
        }

        // Si el estado es cancelled o failed, redirigir a página de error/cancelación
        if ($status === 'cancelled' || $status === 'failed') {
            error_log('CNA payment_return: Pago cancelado o fallido');
            wp_safe_redirect(home_url('/confirmacion-orden?subscription_id=' . $subscription_id . '&status=cancelled'));
            exit;
        }

        // Para cualquier otro caso (success, completed, o sin status), redirigir a confirmación
        // Si la suscripción ya está activa, mostrar confirmación normal
        // Si aún está pendiente, mostrar mensaje de procesamiento
        if ($subscription->status === 'active') {
            error_log('CNA payment_return: Suscripción activa, redirigiendo a confirmación');
            wp_safe_redirect(home_url('/confirmacion-orden?subscription_id=' . $subscription_id));
            exit;
        } else {
            // El webhook aún no ha procesado, mostrar mensaje de espera
            error_log('CNA payment_return: Suscripción pendiente, mostrando estado de procesamiento');
            wp_safe_redirect(home_url('/confirmacion-orden?subscription_id=' . $subscription_id . '&status=processing'));
            exit;
        }
    }

    /**
     * Endpoint: POST /webhook/pagadito
     * Maneja las notificaciones de Pagadito
     */
    public function handle_pagadito_webhook($request)
    {
        // Validación de IP opcional (si está habilitada)
        $ip_validation_enabled = get_option('cna_pagadito_validate_ip', false);

        if ($ip_validation_enabled) {
            $allowed_ips = get_option('cna_pagadito_allowed_ips', '');
            $client_ip = $this->get_client_ip();

            if (!empty($allowed_ips)) {
                $allowed_ips_array = array_map('trim', explode("\n", $allowed_ips));
                $allowed_ips_array = array_filter($allowed_ips_array);

                if (!empty($allowed_ips_array) && !in_array($client_ip, $allowed_ips_array)) {
                    error_log('CNA webhook: IP no permitida: ' . $client_ip);

                    CNA_Audit_Logger::log(
                        CNA_Audit_Logger::EVENT_WEBHOOK_RECEIVED,
                        array(
                            'subscription_id' => 0,
                            'error' => 'IP no permitida',
                            'ip' => $client_ip,
                            'allowed_ips' => $allowed_ips_array,
                        ),
                        CNA_Audit_Logger::SEVERITY_HIGH
                    );

                    return new WP_Error(
                        'ip_not_allowed',
                        __('IP no permitida', 'cna-subscriptions'),
                        array('status' => 403)
                    );
                }
            }
        }

        // Validación básica: HTTPS (excepto en desarrollo local)
        if (!is_ssl() && wp_get_environment_type() !== 'local') {
            CNA_Audit_Logger::log(
                CNA_Audit_Logger::EVENT_WEBHOOK_RECEIVED,
                array(
                    'subscription_id' => 0,
                    'error' => 'Webhook recibido sin HTTPS',
                    'ip' => $this->get_client_ip(),
                ),
                CNA_Audit_Logger::SEVERITY_HIGH
            );

            return new WP_Error(
                'insecure_connection',
                __('El webhook debe recibirse por HTTPS', 'cna-subscriptions'),
                array('status' => 403)
            );
        }

        // Validación adicional: Verificar que el webhook tenga estructura mínima esperada
        // Esto ayuda a filtrar requests maliciosos básicos
        $headers = $request->get_headers();
        $user_agent = isset($headers['user_agent']) ? $headers['user_agent'][0] : '';

        // Si hay un User-Agent, verificar que no sea un bot común
        if (!empty($user_agent)) {
            $suspicious_agents = array('curl', 'wget', 'python', 'scanner', 'bot');
            $user_agent_lower = strtolower($user_agent);
            foreach ($suspicious_agents as $suspicious) {
                if (strpos($user_agent_lower, $suspicious) !== false && strpos($user_agent_lower, 'pagadito') === false) {
                    CNA_Audit_Logger::log(
                        CNA_Audit_Logger::EVENT_WEBHOOK_RECEIVED,
                        array(
                            'subscription_id' => 0,
                            'error' => 'User-Agent sospechoso: ' . substr($user_agent, 0, 50),
                            'ip' => $this->get_client_ip(),
                        ),
                        CNA_Audit_Logger::SEVERITY_HIGH
                    );

                    // No rechazar, solo loguear (puede ser legítimo)
                }
            }
        }

        $data = $request->get_json_params();

        // Si no viene JSON, intentar leer del body raw o parámetros POST
        if (empty($data)) {
            $body = $request->get_body();
            $data = json_decode($body, true);
        }
        if (empty($data) || !is_array($data)) {
            $data = $request->get_body_params();
        }
        if (empty($data) || !is_array($data)) {
            $data = $_POST;
        }

        if (!empty($data['custom_params']) && is_array($data['custom_params'])) {
            foreach ($data['custom_params'] as $param_key => $param_value) {
                if (!isset($data[$param_key])) {
                    $data[$param_key] = $param_value;
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('CNA webhook pagadito payload: ' . wp_json_encode(self::redact_webhook_payload($data)));
        }

        // Validar que venga información básica
        if (empty($data) || !is_array($data)) {
            return new WP_Error(
                'invalid_webhook',
                __('Datos de webhook inválidos', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Formato oficial: { id, event_type, event_create_timestamp, resource: { ern, status, reference, ... } }
        $resource = isset($data['resource']) && is_array($data['resource']) ? $data['resource'] : array();
        $flat = class_exists('CNA_Payment_Transaction')
            ? CNA_Payment_Transaction::flatten_pagadito_webhook($data)
            : array_merge($data, $resource);

        $transaction_id = isset($resource['reference']) ? $resource['reference'] : '';
        if (empty($transaction_id) && isset($data['transaction_id'])) {
            $transaction_id = $data['transaction_id'];
        }

        $status_raw = isset($resource['status']) ? $resource['status'] : ($data['status'] ?? '');
        $status = strtolower((string) $status_raw);

        // ERN = referencia de orden enviada al crear la transacción (subscription_id)
        $subscription_id = 0;
        if (!empty($resource['ern']) && ctype_digit((string) $resource['ern'])) {
            $subscription_id = intval($resource['ern']);
        } elseif (isset($data['custom_params']['param1'])) {
            $subscription_id = intval($data['custom_params']['param1']);
        } elseif (isset($flat['param1'])) {
            $subscription_id = intval($flat['param1']);
        }

        if (empty($subscription_id)) {
            return new WP_Error(
                'missing_subscription_id',
                __('ID de suscripción no encontrado', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
            $subscription_id
        ));

        if (!$subscription) {
            return new WP_Error(
                'subscription_not_found',
                __('Suscripción no encontrada', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        // Procesar según el estado (oficial: COMPLETED, VERIFIED, REJECTED, EXPIRED)
        if (in_array($status, array('completed', 'verified', 'approved', 'success'), true)) {
            return $this->process_successful_payment($subscription, $data);
        } elseif (in_array($status, array('failed', 'rejected', 'cancelled', 'expired'), true)) {
            return $this->process_failed_payment($subscription, $data);
        }

        // Estado desconocido, solo loguear
        return rest_ensure_response(array(
            'message' => __('Webhook recibido pero estado no procesado', 'cna-subscriptions'),
            'status' => $status,
        ));
    }

    /**
     * Procesa un pago exitoso en modo Sandbox
     * Versión simplificada que no requiere objeto subscription
     */
    private function process_successful_payment_sandbox($subscription_id, $webhook_data)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
            $subscription_id
        ));

        if (!$subscription) {
            error_log('CNA process_successful_payment_sandbox: Suscripción no encontrada');
            return false;
        }

        // Procesar pago exitoso
        return $this->process_successful_payment($subscription, $webhook_data);
    }

    /**
     * Crea las fechas de entrega para una suscripción
     * 
     * @param int $subscription_id ID de la suscripción
     * @param array|null $variant Detalles de la variante (si es null, se obtiene de la BD)
     * @param int|null $product_id ID del producto (si es null, se obtiene de la suscripción)
     * @return int Número de entregas creadas
     */
    private function create_delivery_dates_for_subscription($subscription_id, $variant = null, $product_id = null)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción si no tenemos product_id
        if ($product_id === null) {
            $subscription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                $subscription_id
            ));

            if (!$subscription) {
                error_log('CNA create_delivery_dates_for_subscription: Suscripción #' . $subscription_id . ' no encontrada');
                return 0;
            }

            $product_id = $subscription->product_id;

            // Si no se proporcionó variant, obtenerlo de la suscripción
            if ($variant === null) {
                $variant_json = $subscription->variant_details;
                $variant = json_decode($variant_json, true, 512, JSON_UNESCAPED_UNICODE);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $variant = json_decode($variant_json, true);
                }
            }
        } else {
            // Si tenemos product_id pero no variant, obtenerlo de la suscripción
            if ($variant === null) {
                $subscription = $wpdb->get_row($wpdb->prepare(
                    "SELECT variant_details FROM {$table_prefix}cna_subscriptions WHERE id = %d",
                    $subscription_id
                ));

                if ($subscription) {
                    $variant = json_decode($subscription->variant_details, true, 512, JSON_UNESCAPED_UNICODE);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $variant = json_decode($subscription->variant_details, true);
                    }
                }
            }
        }

        if (!$variant || !is_array($variant)) {
            error_log('CNA create_delivery_dates_for_subscription: No se pudieron obtener los detalles de la variante para suscripción #' . $subscription_id);
            return 0;
        }

        // Obtener configuración de días del producto
        $delivery_day = intval(get_post_meta($product_id, '_cna_delivery_day', true));
        $order_cutoff = intval(get_post_meta($product_id, '_cna_order_cutoff', true));

        // Valores por defecto si no están configurados (Jueves=4, Miércoles=2)
        if (empty($delivery_day) && $delivery_day !== '0') {
            $delivery_day = 4; // Jueves
        }
        if (empty($order_cutoff) && $order_cutoff !== '0') {
            $order_cutoff = 2; // Miércoles
        }

        // Calcular fechas de entrega
        $delivery_dates = CNA_Scheduler::calculate_delivery_dates(
            'now',
            intval($variant['qty']),
            intval($variant['frequency']),
            $delivery_day,
            $order_cutoff
        );

        if (empty($delivery_dates)) {
            error_log('CNA create_delivery_dates_for_subscription: No se calcularon fechas de entrega para suscripción #' . $subscription_id);
            return 0;
        }

        // Calcular fecha de renovación
        $last_delivery = end($delivery_dates);
        $next_renewal = CNA_Scheduler::calculate_next_renewal_date(
            $last_delivery,
            intval($variant['frequency'])
        );

        // Actualizar fecha de renovación
        $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            array('next_renewal_date' => $next_renewal),
            array('id' => $subscription_id),
            array('%s'),
            array('%d')
        );

        // Calcular monto a cobrar por entrega
        $advance_percent = floatval($variant['advance_percent']);

        // Obtener precio unitario de la variación usando el nuevo helper
        $unit_price = CNA_Product_Helper::get_variation_price($product_id, strtolower($variant['size']));
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

        // Crear registros de entregas
        $deliveries_created = 0;
        foreach ($delivery_dates as $date) {
            $insert_result = $wpdb->insert(
                $table_prefix . 'cna_deliveries',
                array(
                    'subscription_id' => $subscription_id,
                    'scheduled_date' => $date,
                    'payment_status' => ($advance_percent >= 100) ? 'paid' : 'pending',
                    'amount_to_collect' => $amount_per_delivery,
                    'delivery_status' => 'scheduled',
                ),
                array('%d', '%s', '%s', '%f', '%s')
            );
            
            if ($insert_result !== false) {
                $deliveries_created++;
            } else {
                error_log('CNA create_delivery_dates_for_subscription: Error al insertar entrega para fecha ' . $date . ' - ' . $wpdb->last_error);
            }
        }
        
        error_log(sprintf(
            'CNA create_delivery_dates_for_subscription: Suscripción #%d - %d entregas creadas de %d fechas calculadas',
            $subscription_id,
            $deliveries_created,
            count($delivery_dates)
        ));

        return $deliveries_created;
    }

    /**
     * Procesa un pago exitoso
     */
    private function process_successful_payment($subscription, $webhook_data)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Extraer token si viene en la respuesta
        $token = CNA_Pagadito_Client::extract_token($webhook_data);

        // Actualizar suscripción
        $update_data = array(
            'status' => 'active',
        );

        if ($token) {
            // Encriptar token antes de guardarlo
            $encrypted_token = CNA_Token_Encryption::encrypt($token);
            if ($encrypted_token) {
                $update_data['pagadito_token'] = $encrypted_token;

                // Log de almacenamiento de token
                CNA_Audit_Logger::log(
                    CNA_Audit_Logger::EVENT_TOKEN_STORED,
                    array(
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'product_id' => $subscription->product_id,
                    ),
                    CNA_Audit_Logger::SEVERITY_CRITICAL
                );
            }
        }

        $format = array_fill(0, count($update_data), '%s');

        $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            $update_data,
            array('id' => $subscription->id),
            $format,
            array('%d')
        );

        $provider_slug = $this->resolve_payment_provider_slug();
        if (class_exists('CNA_Payment_Transaction')) {
            CNA_Payment_Transaction::save_from_webhook(
                $subscription->id,
                $provider_slug,
                is_array($webhook_data) ? $webhook_data : array()
            );
        }

        // Log de pago exitoso
        $resource = isset($webhook_data['resource']) && is_array($webhook_data['resource'])
            ? $webhook_data['resource']
            : array();
        $transaction_id = $resource['reference'] ?? ($webhook_data['transaction_id'] ?? '');
        CNA_Audit_Logger::log(
            CNA_Audit_Logger::EVENT_PAYMENT_SUCCESS,
            array(
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'product_id' => $subscription->product_id,
                'transaction_id' => substr($transaction_id, 0, 10) . '...',
                'status' => 'active',
            ),
            CNA_Audit_Logger::SEVERITY_CRITICAL
        );

        // Generar fechas de entrega (solo si no existen ya)
        // Verificar si ya existen entregas para esta suscripción
        $existing_deliveries = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_prefix}cna_deliveries WHERE subscription_id = %d",
            $subscription->id
        ));

        if ($existing_deliveries == 0) {
            // No hay entregas existentes, crearlas
            $this->create_delivery_dates_for_subscription($subscription->id, null, $subscription->product_id);
        } else {
            error_log(sprintf(
                'CNA process_successful_payment: Suscripción #%d ya tiene %d entregas, no se crearán duplicados',
                $subscription->id,
                $existing_deliveries
            ));
        }

        // Enviar emails de confirmación
        if (class_exists('CNA_Mailer')) {
            // Email al cliente confirmando pago exitoso
            CNA_Mailer::send_payment_success($subscription->id);
            
            // Email al admin notificando pago recibido
            CNA_Mailer::send_admin_payment_received($subscription->id);
        }

        // Obtener número de entregas creadas
        $deliveries_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_prefix}cna_deliveries WHERE subscription_id = %d",
            $subscription->id
        ));

        return rest_ensure_response(array(
            'message' => __('Pago procesado exitosamente', 'cna-subscriptions'),
            'subscription_id' => $subscription->id,
            'deliveries_created' => intval($deliveries_count),
        ));
    }

    /**
     * Procesa un pago fallido
     */
    private function process_failed_payment($subscription, $webhook_data)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            array('status' => 'payment_failed'),
            array('id' => $subscription->id),
            array('%s'),
            array('%d')
        );

        $provider_slug = $this->resolve_payment_provider_slug();
        if (class_exists('CNA_Payment_Transaction')) {
            $failed_payload = is_array($webhook_data) ? $webhook_data : array();
            if (empty($failed_payload['status'])) {
                $failed_payload['status'] = 'failed';
            }
            CNA_Payment_Transaction::save_from_webhook($subscription->id, $provider_slug, $failed_payload);
        }

        // Log de pago fallido
        $transaction_id = isset($webhook_data['transaction_id']) ? $webhook_data['transaction_id'] : '';
        CNA_Audit_Logger::log(
            CNA_Audit_Logger::EVENT_PAYMENT_FAILED,
            array(
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'product_id' => $subscription->product_id,
                'transaction_id' => substr($transaction_id, 0, 10) . '...',
                'status' => 'payment_failed',
            ),
            CNA_Audit_Logger::SEVERITY_HIGH
        );

        // Enviar emails de alerta
        if (class_exists('CNA_Mailer')) {
            $error_reason = isset($webhook_data['message']) ? $webhook_data['message'] : '';
            
            // Email al cliente notificando pago fallido
            CNA_Mailer::send_payment_failed($subscription->id, $error_reason);
            
            // Email al admin alertando pago fallido
            CNA_Mailer::send_admin_payment_failed($subscription->id, $error_reason);
        }

        return rest_ensure_response(array(
            'message' => __('Pago fallido registrado', 'cna-subscriptions'),
            'subscription_id' => $subscription->id,
        ));
    }

    /**
     * Calcula los totales de una orden
     *
     * @param int $product_id
     * @param array $variant
     * @param array $shipping
     * @return array|WP_Error
     */
    private function calculate_order_totals($product_id, $variant, $shipping)
    {
        // Obtener precio base según variación usando el nuevo helper
        $size = strtolower($variant['size']);
        $unit_price = CNA_Product_Helper::get_variation_price($product_id, $size);

        if ($unit_price === false || $unit_price <= 0) {
            return new WP_Error(
                'invalid_price',
                __('Precio del producto no configurado para esta variación', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $qty = intval($variant['qty']);
        $advance_percent = floatval($variant['advance_percent']);

        // Subtotal del producto
        $product_subtotal = $unit_price * $qty;

        // Monto de anticipo
        $advance_amount = $product_subtotal * ($advance_percent / 100);

        // Fee anual
        $annual_fee = floatval(get_post_meta($product_id, '_cna_annual_fee', true));

        // Costo de envío
        $shipping_unit = 0;
        if ($shipping['type'] === 'home') {
            // Obtener precio de envío
            $locations_helper = new CNA_Locations_Helper();
            $country = isset($shipping['country']) ? $shipping['country'] : 'El Salvador';
            $zone_id = $locations_helper->find_zone_by_location(
                $shipping['department'],
                $shipping['municipality'],
                $shipping['district'],
                $country
            );

            if ($zone_id) {
                $shipping_prices = get_post_meta($product_id, '_cna_shipping_prices', true);
                if (is_array($shipping_prices) && isset($shipping_prices[$zone_id])) {
                    $shipping_unit = floatval($shipping_prices[$zone_id]);
                }
            }
        } elseif ($shipping['type'] === 'pickup') {
            // Recoger en tienda siempre es $0.00
            $shipping_unit = 0.00;

            // Validar que la tienda existe y está activa
            if (isset($shipping['store_id'])) {
                global $wpdb;
                $table_prefix = $wpdb->prefix;
                $store = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$table_prefix}cna_pickup_stores WHERE id = %d AND is_active = 1",
                    intval($shipping['store_id'])
                ));

                if (!$store) {
                    return new WP_Error(
                        'invalid_store',
                        __('La tienda seleccionada no es válida o no está disponible', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }
            }
        }

        $shipping_total = $shipping_unit * $qty;

        // Neto esperado
        $net_amount = $advance_amount + $shipping_total + $annual_fee;

        // Validar que el monto neto sea positivo
        if ($net_amount <= 0) {
            return new WP_Error(
                'invalid_net_amount',
                __('El monto neto debe ser mayor a cero', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Validar límites de monto (máximo $10,000 por transacción)
        if ($net_amount > 10000) {
            return new WP_Error(
                'amount_too_high',
                __('El monto excede el límite máximo permitido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $gateway_totals = CNA_Payment_Helper::calculate_gateway_totals($net_amount);
        if (is_wp_error($gateway_totals)) {
            return $gateway_totals;
        }

        $pasarela_fee = $gateway_totals['fee_percent'];
        $pasarela_fee_fixed = $gateway_totals['fee_fixed'];
        $total_with_fee = $gateway_totals['total_with_fee'];

        // Validar que el total con fee no exceda límites razonables
        if ($total_with_fee > 15000) {
            return new WP_Error(
                'total_too_high',
                __('El total excede el límite máximo permitido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        return array(
            'unit_price' => $unit_price,
            'qty' => $qty,
            'product_subtotal' => $product_subtotal,
            'advance_percent' => $advance_percent,
            'advance_amount' => $advance_amount,
            'annual_fee' => $annual_fee,
            'shipping_unit' => $shipping_unit,
            'shipping_total' => $shipping_total,
            'net_amount' => $net_amount,
            'pasarela_fee' => $pasarela_fee,
            'pasarela_fee_fixed' => $pasarela_fee_fixed,
            'fee_amount' => $gateway_totals['fee_amount'],
            'total_with_fee' => $total_with_fee,
        );
    }

    /**
     * Verifica rate limiting básico para endpoints públicos
     * 
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_rate_limit($request)
    {
        // Rate limiting básico: máximo 10 requests por minuto por IP
        $ip = $this->get_client_ip();
        $transient_key = 'cna_rate_limit_' . md5($ip);
        $requests = get_transient($transient_key);

        if ($requests === false) {
            // Primera request, crear contador
            set_transient($transient_key, 1, 60); // 60 segundos
            return true;
        }

        if ($requests >= 10) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Demasiadas solicitudes. Por favor, intenta de nuevo en un momento.', 'cna-subscriptions'),
                array('status' => 429)
            );
        }

        // Incrementar contador
        set_transient($transient_key, $requests + 1, 60);
        return true;
    }

    /**
     * Slug de la pasarela activa (extensible a futuros métodos).
     *
     * @return string
     */
    private function resolve_payment_provider_slug()
    {
        $gateway = CNA_Payment_Helper::get_active_gateway();
        if ($gateway && !empty($gateway->slug)) {
            return sanitize_key($gateway->slug);
        }

        return 'pagadito';
    }

    /**
     * Elimina datos sensibles del payload antes de loguear.
     *
     * @param array $payload
     * @return array
     */
    private static function redact_webhook_payload(array $payload)
    {
        $redacted = $payload;
        foreach (array('token', 'wsk') as $sensitive) {
            if (isset($redacted[$sensitive])) {
                $redacted[$sensitive] = '[redacted]';
            }
        }
        return $redacted;
    }

    /**
     * Obtiene la IP real del cliente
     * 
     * @return string
     */
    private function get_client_ip()
    {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field($_SERVER[$key]);
                // Si es X-Forwarded-For, tomar la primera IP
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
     * Verifica permisos del usuario para endpoints protegidos
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_user_permission($request)
    {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado para acceder a este recurso', 'cna-subscriptions'),
                array('status' => 401)
            );
        }
        return true;
    }

    /**
     * Endpoint: GET /user/subscriptions
     * Obtiene las suscripciones del usuario actual
     */
    public function get_user_subscriptions($request)
    {
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, p.post_title as product_name 
             FROM {$table_prefix}cna_subscriptions s
             LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
             WHERE s.user_id = %d 
             ORDER BY s.created_at DESC",
            $user_id
        ));

        $safe_subscriptions = array();
        foreach ($subscriptions as $row) {
            $safe_subscriptions[] = $this->format_subscription_for_user($row);
        }

        return rest_ensure_response(array(
            'subscriptions' => $safe_subscriptions,
        ));
    }

    /**
     * Formato seguro de suscripción para el cliente (sin token de pago).
     *
     * @param object $subscription
     * @return array
     */
    private function format_subscription_for_user($subscription)
    {
        return array(
            'id' => isset($subscription->id) ? (int) $subscription->id : 0,
            'user_id' => isset($subscription->user_id) ? (int) $subscription->user_id : 0,
            'product_id' => isset($subscription->product_id) ? (int) $subscription->product_id : 0,
            'product_name' => isset($subscription->product_name) ? $subscription->product_name : '',
            'status' => isset($subscription->status) ? $subscription->status : '',
            'is_auto_renew' => !empty($subscription->is_auto_renew) ? 1 : 0,
            'has_payment_token' => !empty($subscription->pagadito_token),
            'next_renewal_date' => isset($subscription->next_renewal_date) ? $subscription->next_renewal_date : '',
            'shipping_address_json' => isset($subscription->shipping_address_json) ? $subscription->shipping_address_json : '',
            'variant_details' => isset($subscription->variant_details) ? $subscription->variant_details : '',
            'shipping_cost_unit' => isset($subscription->shipping_cost_unit) ? floatval($subscription->shipping_cost_unit) : 0,
            'created_at' => isset($subscription->created_at) ? $subscription->created_at : '',
            'updated_at' => isset($subscription->updated_at) ? $subscription->updated_at : '',
        );
    }

    /**
     * Endpoint: GET /subscriptions/{id}/deliveries
     * Obtiene las entregas de una suscripción
     */
    public function get_subscription_deliveries($request)
    {
        $subscription_id = intval($request->get_param('id'));
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Verificar que la suscripción pertenece al usuario
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d AND user_id = %d",
            $subscription_id,
            $user_id
        ));

        if (!$subscription) {
            return new WP_Error(
                'subscription_not_found',
                __('Suscripción no encontrada', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        $deliveries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_deliveries 
             WHERE subscription_id = %d 
             ORDER BY scheduled_date ASC",
            $subscription_id
        ));

        $normalized = array();
        foreach ($deliveries as $row) {
            $normalized[] = array(
                'id' => isset($row->id) ? (int) $row->id : 0,
                'subscription_id' => isset($row->subscription_id) ? (int) $row->subscription_id : 0,
                'scheduled_date' => isset($row->scheduled_date) ? $row->scheduled_date : '',
                'payment_status' => isset($row->payment_status) ? $row->payment_status : '',
                'amount_to_collect' => isset($row->amount_to_collect) ? floatval($row->amount_to_collect) : 0.0,
                'delivery_status' => isset($row->delivery_status) ? $row->delivery_status : '',
                'delivered_at' => isset($row->delivered_at) ? $row->delivered_at : null,
                'notes' => isset($row->notes) ? $row->notes : null,
                'created_at' => isset($row->created_at) ? $row->created_at : null,
                'updated_at' => isset($row->updated_at) ? $row->updated_at : null,
            );
        }

        return rest_ensure_response(array(
            'deliveries' => $normalized,
        ));
    }

    /**
     * Endpoint: POST /subscriptions/{id}/toggle-renew
     * Activa o desactiva la auto-renovación de una suscripción
     */
    public function toggle_subscription_renewal($request)
    {
        $data = $request->get_json_params();
        $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : false;
        $action = $enabled ? 'enable_auto_renew' : 'disable_auto_renew';

        $request->set_param('action', $action);
        return $this->perform_subscription_action($request);
    }

    /**
     * Endpoint: POST /subscriptions/{id}/action
     * Acciones del cliente: pausar, reactivar, cancelar, auto-renovación.
     */
    public function perform_subscription_action($request)
    {
        $subscription_id = intval($request->get_param('id'));
        $user_id = get_current_user_id();
        $data = $request->get_json_params();
        $action = isset($data['action']) ? sanitize_text_field($data['action']) : '';

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        $allowed_actions = array(
            'pause',
            'activate',
            'cancel',
            'enable_auto_renew',
            'disable_auto_renew',
        );

        if (!in_array($action, $allowed_actions, true)) {
            return new WP_Error(
                'invalid_action',
                __('Acción no válida', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d AND user_id = %d",
            $subscription_id,
            $user_id
        ));

        if (!$subscription) {
            return new WP_Error(
                'subscription_not_found',
                __('Suscripción no encontrada', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        $result = $this->apply_subscription_action($subscription, $action, 'customer');

        if (is_wp_error($result)) {
            return $result;
        }

        $updated = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
            $subscription_id
        ));

        return rest_ensure_response(array(
            'success' => true,
            'message' => $result['message'],
            'status' => $updated->status,
            'is_auto_renew' => !empty($updated->is_auto_renew) ? 1 : 0,
            'subscription' => $this->format_subscription_for_user($updated),
        ));
    }

    /**
     * Aplica una acción de suscripción (compartida con lógica de administración).
     *
     * @param object $subscription
     * @param string $action
     * @param string $by admin|customer
     * @return array|WP_Error
     */
    private function apply_subscription_action($subscription, $action, $by = 'customer')
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $subscription_id = (int) $subscription->id;

        switch ($action) {
            case 'disable_auto_renew':
                if (empty($subscription->is_auto_renew)) {
                    return new WP_Error(
                        'invalid_state',
                        __('La renovación automática ya está desactivada', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                $updated = $wpdb->update(
                    $table_prefix . 'cna_subscriptions',
                    array('is_auto_renew' => 0),
                    array('id' => $subscription_id),
                    array('%d'),
                    array('%d')
                );

                if ($updated === false) {
                    return new WP_Error(
                        'update_failed',
                        __('Error al actualizar', 'cna-subscriptions'),
                        array('status' => 500)
                    );
                }

                if (class_exists('CNA_Audit_Logger')) {
                    CNA_Audit_Logger::log(
                        'subscription_updated',
                        array(
                            'subscription_id' => $subscription_id,
                            'action' => 'auto_renew_disabled',
                            'by' => $by,
                            'user_id' => get_current_user_id(),
                        ),
                        CNA_Audit_Logger::SEVERITY_MEDIUM
                    );
                }

                return array(
                    'message' => __('Renovación automática desactivada. Las entregas actuales no se modifican.', 'cna-subscriptions'),
                );

            case 'enable_auto_renew':
                if (!empty($subscription->is_auto_renew)) {
                    return new WP_Error(
                        'invalid_state',
                        __('La renovación automática ya está activa', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                if ($subscription->status !== 'active') {
                    return new WP_Error(
                        'invalid_state',
                        __('Solo puedes activar la auto-renovación en suscripciones activas', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                if (empty($subscription->pagadito_token)) {
                    return new WP_Error(
                        'missing_token',
                        __('No hay método de pago guardado. Completa un pago en Pagadito primero.', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                $updated = $wpdb->update(
                    $table_prefix . 'cna_subscriptions',
                    array('is_auto_renew' => 1),
                    array('id' => $subscription_id),
                    array('%d'),
                    array('%d')
                );

                if ($updated === false) {
                    return new WP_Error(
                        'update_failed',
                        __('Error al actualizar', 'cna-subscriptions'),
                        array('status' => 500)
                    );
                }

                if (class_exists('CNA_Audit_Logger')) {
                    CNA_Audit_Logger::log(
                        'subscription_updated',
                        array(
                            'subscription_id' => $subscription_id,
                            'action' => 'auto_renew_enabled',
                            'by' => $by,
                            'user_id' => get_current_user_id(),
                        ),
                        CNA_Audit_Logger::SEVERITY_MEDIUM
                    );
                }

                return array(
                    'message' => __('Renovación automática activada', 'cna-subscriptions'),
                );

            case 'activate':
                if ($subscription->status === 'active') {
                    return new WP_Error(
                        'invalid_state',
                        __('La suscripción ya está activa', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                if ($subscription->status !== 'paused') {
                    return new WP_Error(
                        'invalid_state',
                        __('Solo puedes reactivar suscripciones pausadas', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                $new_status = 'active';
                $action_message = __('Suscripción reactivada', 'cna-subscriptions');
                break;

            case 'pause':
                if ($subscription->status !== 'active') {
                    return new WP_Error(
                        'invalid_state',
                        __('Solo puedes pausar suscripciones activas', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                $new_status = 'paused';
                $action_message = __('Suscripción pausada', 'cna-subscriptions');
                break;

            case 'cancel':
                if ($subscription->status === 'cancelled') {
                    return new WP_Error(
                        'invalid_state',
                        __('La suscripción ya está cancelada', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                if (!in_array($subscription->status, array('active', 'paused', 'pending', 'payment_failed'), true)) {
                    return new WP_Error(
                        'invalid_state',
                        __('No se puede cancelar esta suscripción en su estado actual', 'cna-subscriptions'),
                        array('status' => 400)
                    );
                }

                $new_status = 'cancelled';
                $action_message = __('Suscripción cancelada', 'cna-subscriptions');
                break;

            default:
                return new WP_Error(
                    'invalid_action',
                    __('Acción no válida', 'cna-subscriptions'),
                    array('status' => 400)
                );
        }

        if (!isset($new_status)) {
            return new WP_Error(
                'internal_error',
                __('Error interno al procesar la acción', 'cna-subscriptions'),
                array('status' => 500)
            );
        }

        $updated = $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            array('status' => $new_status),
            array('id' => $subscription_id),
            array('%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error(
                'update_failed',
                __('Error al actualizar', 'cna-subscriptions'),
                array('status' => 500)
            );
        }

        if (class_exists('CNA_Audit_Logger')) {
            CNA_Audit_Logger::log(
                'subscription_status_changed',
                array(
                    'subscription_id' => $subscription_id,
                    'from' => $subscription->status,
                    'to' => $new_status,
                    'by' => $by,
                    'user_id' => get_current_user_id(),
                ),
                CNA_Audit_Logger::SEVERITY_MEDIUM
            );
        }

        if (class_exists('CNA_Mailer')) {
            CNA_Mailer::send_subscription_status_changed($subscription_id, $new_status, $action_message);
        }

        return array('message' => $action_message);
    }

    /**
     * Endpoint: GET /locations
     * Obtiene solo las ubicaciones configuradas en las zonas de envío
     */
    public function get_locations_data($request)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener solo las ubicaciones que están configuradas en zonas de envío activas
        $locations = $wpdb->get_results(
            "SELECT DISTINCT 
                sl.country,
                sl.department,
                sl.municipality,
                sl.district
            FROM {$table_prefix}cna_shipping_locations sl
            INNER JOIN {$table_prefix}cna_shipping_zones sz ON sl.zone_id = sz.id
            WHERE sz.is_active = 1
            ORDER BY sl.country, sl.department, sl.municipality, sl.district"
        );

        // Transformar estructura para facilitar uso en frontend
        $departments = array();
        $municipalities = array();
        $districts = array();

        foreach ($locations as $location) {
            $dept = $location->department;
            $muni = $location->municipality;
            $dist = $location->district;

            // Agregar departamento si no existe
            if (!in_array($dept, $departments)) {
                $departments[] = $dept;
            }

            // Agregar municipio si no existe en ese departamento
            if (!isset($municipalities[$dept])) {
                $municipalities[$dept] = array();
            }
            if (!in_array($muni, $municipalities[$dept])) {
                $municipalities[$dept][] = $muni;
            }

            // Agregar distrito si no existe en ese municipio
            if (!isset($districts[$dept])) {
                $districts[$dept] = array();
            }
            if (!isset($districts[$dept][$muni])) {
                $districts[$dept][$muni] = array();
            }
            if (!in_array($dist, $districts[$dept][$muni])) {
                $districts[$dept][$muni][] = $dist;
            }
        }

        // Ordenar arrays
        sort($departments);
        foreach ($municipalities as $dept => &$munis) {
            sort($munis);
        }
        foreach ($districts as $dept => &$munis) {
            foreach ($munis as $muni => &$dists) {
                sort($dists);
            }
        }

        return rest_ensure_response(array(
            'departments' => $departments,
            'municipalities' => $municipalities,
            'districts' => $districts,
        ));
    }

    /**
     * Endpoint: POST /register
     * Registra un nuevo usuario
     */
    public function register_user($request)
    {
        $data = $request->get_json_params();

        // Validar datos requeridos
        if (empty($data['email']) || empty($data['password'])) {
            return new WP_Error(
                'missing_fields',
                __('El correo electrónico y la contraseña son requeridos', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $email = sanitize_email($data['email']);
        $password = $data['password'];
        $first_name = isset($data['first_name']) ? sanitize_text_field($data['first_name']) : '';
        $last_name = isset($data['last_name']) ? sanitize_text_field($data['last_name']) : '';

        // Validar email
        if (!is_email($email)) {
            return new WP_Error(
                'invalid_email',
                __('El correo electrónico no es válido', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Validar contraseña
        if (strlen($password) < 6) {
            return new WP_Error(
                'weak_password',
                __('La contraseña debe tener al menos 6 caracteres', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Verificar si el email ya existe
        if (email_exists($email)) {
            return new WP_Error(
                'email_exists',
                __('Este correo electrónico ya está registrado', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        // Crear usuario
        $username = sanitize_user($email, true);
        // Si el username ya existe, agregar un número
        $original_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            return new WP_Error(
                'registration_failed',
                $user_id->get_error_message(),
                array('status' => 400)
            );
        }

        // Enviar email de bienvenida
        if (class_exists('CNA_Mailer')) {
            CNA_Mailer::send_welcome_email($user_id);
            
            // Enviar email al admin notificando nuevo usuario
            CNA_Mailer::send_admin_new_user($user_id);
        }

        // Actualizar metadatos del usuario
        if ($first_name) {
            update_user_meta($user_id, 'first_name', $first_name);
        }
        if ($last_name) {
            update_user_meta($user_id, 'last_name', $last_name);
        }

        // Establecer nombre para mostrar
        $display_name = trim($first_name . ' ' . $last_name);
        if (empty($display_name)) {
            $display_name = $username;
        }
        wp_update_user(array(
            'ID' => $user_id,
            'display_name' => $display_name,
        ));

        // Log de auditoría
        CNA_Audit_Logger::log(
            'user_registered',
            array(
                'user_id' => $user_id,
                'email' => $email,
                'ip' => $this->get_client_ip(),
            ),
            CNA_Audit_Logger::SEVERITY_MEDIUM
        );

        return rest_ensure_response(array(
            'success' => true,
            'user_id' => $user_id,
            'message' => __('Usuario registrado exitosamente', 'cna-subscriptions'),
        ));
    }

    /**
     * Endpoint: POST /login
     * Inicia sesión de usuario
     */
    public function login_user($request)
    {
        $data = $request->get_json_params();

        // Validar datos requeridos
        if (empty($data['email']) || empty($data['password'])) {
            return new WP_Error(
                'missing_fields',
                __('El correo electrónico y la contraseña son requeridos', 'cna-subscriptions'),
                array('status' => 400)
            );
        }

        $email = sanitize_email($data['email']);
        $password = $data['password'];

        // Obtener usuario por email
        $user = get_user_by('email', $email);
        if (!$user) {
            return new WP_Error(
                'invalid_credentials',
                __('Correo electrónico o contraseña incorrectos', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        // Verificar contraseña
        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            // Log de intento fallido
            CNA_Audit_Logger::log(
                'login_failed',
                array(
                    'user_id' => $user->ID,
                    'email' => $email,
                    'ip' => $this->get_client_ip(),
                    'reason' => 'invalid_password',
                ),
                CNA_Audit_Logger::SEVERITY_MEDIUM
            );

            return new WP_Error(
                'invalid_credentials',
                __('Correo electrónico o contraseña incorrectos', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        // Establecer cookies de autenticación
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, isset($data['remember']) ? $data['remember'] : false);

        // Log de auditoría
        CNA_Audit_Logger::log(
            'user_logged_in',
            array(
                'user_id' => $user->ID,
                'email' => $email,
                'ip' => $this->get_client_ip(),
            ),
            CNA_Audit_Logger::SEVERITY_MEDIUM
        );

        return rest_ensure_response(array(
            'success' => true,
            'user_id' => $user->ID,
            'user' => array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
            ),
            'message' => __('Sesión iniciada exitosamente', 'cna-subscriptions'),
        ));
    }

    /**
     * Endpoint: GET /user/data
     * Obtiene los metadatos del usuario actual
     */
    public function get_current_user_data($request)
    {
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error(
                'user_not_found',
                __('Usuario no encontrado', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        return rest_ensure_response(array(
            'first_name' => get_user_meta($user_id, 'first_name', true) ?: '',
            'last_name' => get_user_meta($user_id, 'last_name', true) ?: '',
            'user_email' => $user->user_email ?: '',
            'nationality' => get_user_meta($user_id, 'nationality', true) ?: '',
            'phone' => get_user_meta($user_id, 'phone', true) ?: '',
        ));
    }

    /**
     * Endpoint: GET /gateway-fee
     * Obtiene el fee de la pasarela activa
     */
    public function get_gateway_fee($request)
    {
        $fee = CNA_Payment_Helper::get_gateway_fee();
        $fee_fixed = CNA_Payment_Helper::get_gateway_fee_fixed();
        return rest_ensure_response(array(
            'fee' => $fee,
            'fee_percent' => $fee * 100,
            'fee_fixed' => $fee_fixed,
        ));
    }

    /**
     * Endpoint: GET /subscriptions/(?P<id>\d+)
     * Obtiene los detalles de una suscripción
     */
    public function get_subscription_details($request)
    {
        $subscription_id = intval($request->get_param('id'));
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado para acceder a este recurso', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d AND user_id = %d",
            $subscription_id,
            $user_id
        ));

        if (!$subscription) {
            return new WP_Error(
                'subscription_not_found',
                __('Suscripción no encontrada', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        // Decodificar JSON con soporte UTF-8
        $variant_details = json_decode($subscription->variant_details, true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $variant_details = json_decode($subscription->variant_details, true);
        }

        $shipping_address = json_decode($subscription->shipping_address_json, true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $shipping_address = json_decode($subscription->shipping_address_json, true);
        }

        // Obtener nombre del producto
        $product_name = get_the_title($subscription->product_id);

        // Obtener primera fecha de entrega
        $first_delivery = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(scheduled_date) FROM {$table_prefix}cna_deliveries WHERE subscription_id = %d",
            $subscription_id
        ));

        return rest_ensure_response(array(
            'id' => $subscription->id,
            'product_name' => $product_name,
            'status' => $subscription->status,
            'total_with_fee' => floatval($subscription->total_with_fee),
            'unit_price' => floatval($subscription->unit_price),
            'product_subtotal' => floatval($subscription->product_subtotal),
            'shipping_total' => floatval($subscription->shipping_total),
            'annual_fee' => floatval($subscription->annual_fee),
            'fee_amount' => floatval($subscription->fee_amount),
            'variant_details' => $variant_details ?: array(),
            'shipping_address' => $shipping_address ?: array(),
            'created_at' => $subscription->created_at,
            'next_renewal_date' => $subscription->next_renewal_date,
            'first_delivery_date' => $first_delivery,
        ));
    }

    /**
     * Endpoint: GET /user/addresses
     * Obtiene las direcciones de entrega del usuario
     */
    public function get_user_addresses($request)
    {
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado para acceder a este recurso', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $addresses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_user_addresses WHERE user_id = %d ORDER BY is_default DESC, created_at DESC",
            $user_id
        ), ARRAY_A);

        return rest_ensure_response(array(
            'addresses' => $addresses,
        ));
    }

    /**
     * Endpoint: POST /user/addresses
     * Guarda una nueva dirección de entrega del usuario
     */
    public function save_user_address($request)
    {
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado para acceder a este recurso', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        $data = $request->get_json_params();

        // Validar campos requeridos
        $required = array('department', 'municipality', 'district', 'address');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error(
                    'missing_field',
                    sprintf(__('Campo requerido faltante: %s', 'cna-subscriptions'), $field),
                    array('status' => 400)
                );
            }
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $label = sanitize_text_field($data['label'] ?? 'Mi dirección');
        $country = sanitize_text_field($data['country'] ?? 'El Salvador');
        $department = sanitize_text_field($data['department']);
        $municipality = sanitize_text_field($data['municipality']);
        $district = sanitize_text_field($data['district']);
        $address = sanitize_textarea_field($data['address']);
        $is_default = isset($data['is_default']) ? (int) $data['is_default'] : 0;

        // Si se marca como default, desmarcar las demás
        if ($is_default) {
            $wpdb->update(
                $table_prefix . 'cna_user_addresses',
                array('is_default' => 0),
                array('user_id' => $user_id),
                array('%d'),
                array('%d')
            );
        }

        // Guardar dirección
        $result = $wpdb->insert(
            $table_prefix . 'cna_user_addresses',
            array(
                'user_id' => $user_id,
                'label' => $label,
                'country' => $country,
                'department' => $department,
                'municipality' => $municipality,
                'district' => $district,
                'address' => $address,
                'is_default' => $is_default,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );

        if (!$result) {
            return new WP_Error(
                'db_error',
                __('Error al guardar la dirección', 'cna-subscriptions'),
                array('status' => 500)
            );
        }

        $address_id = $wpdb->insert_id;

        return rest_ensure_response(array(
            'success' => true,
            'address_id' => $address_id,
            'message' => __('Dirección guardada exitosamente', 'cna-subscriptions'),
        ));
    }

    /**
     * Endpoint: PUT /user/addresses/(?P<id>\d+)
     * Actualiza una dirección de entrega existente
     */
    public function update_user_address($request)
    {
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return new WP_Error(
                'unauthorized',
                __('Debes estar autenticado para acceder a este recurso', 'cna-subscriptions'),
                array('status' => 401)
            );
        }

        $address_id = intval($request->get_param('id'));
        $data = $request->get_json_params();

        // Validar campos requeridos
        $required = array('department', 'municipality', 'district', 'address');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error(
                    'missing_field',
                    sprintf(__('Campo requerido faltante: %s', 'cna-subscriptions'), $field),
                    array('status' => 400)
                );
            }
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Verificar que la dirección pertenece al usuario
        $existing_address = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_user_addresses WHERE id = %d AND user_id = %d",
            $address_id,
            $user_id
        ));

        if (!$existing_address) {
            return new WP_Error(
                'address_not_found',
                __('Dirección no encontrada', 'cna-subscriptions'),
                array('status' => 404)
            );
        }

        $label = sanitize_text_field($data['label'] ?? $existing_address->label);
        $country = sanitize_text_field($data['country'] ?? $existing_address->country);
        $department = sanitize_text_field($data['department']);
        $municipality = sanitize_text_field($data['municipality']);
        $district = sanitize_text_field($data['district']);
        $address = sanitize_textarea_field($data['address']);
        $is_default = isset($data['is_default']) ? (int) $data['is_default'] : $existing_address->is_default;

        // Si se marca como default, desmarcar las demás
        if ($is_default) {
            $wpdb->update(
                $table_prefix . 'cna_user_addresses',
                array('is_default' => 0),
                array('user_id' => $user_id),
                array('%d'),
                array('%d')
            );
        }

        // Actualizar dirección
        $result = $wpdb->update(
            $table_prefix . 'cna_user_addresses',
            array(
                'label' => $label,
                'country' => $country,
                'department' => $department,
                'municipality' => $municipality,
                'district' => $district,
                'address' => $address,
                'is_default' => $is_default,
            ),
            array('id' => $address_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error(
                'db_error',
                __('Error al actualizar la dirección', 'cna-subscriptions'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Dirección actualizada exitosamente', 'cna-subscriptions'),
        ));
    }
}
