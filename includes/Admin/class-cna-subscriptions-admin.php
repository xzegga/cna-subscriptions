<?php
/**
 * Admin list view for suscripciones
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Subscriptions_Admin {

    /**
     * Inicializa los hooks
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_dashboard_page'), 4);
        add_action('admin_menu', array($this, 'add_submenu_page'), 5);
        add_action('admin_menu', array($this, 'reorder_submenu'), 999);
        add_action('wp_ajax_cna_update_delivery_status', array($this, 'ajax_update_delivery_status'));
        add_action('wp_ajax_cna_update_subscription_status', array($this, 'ajax_update_subscription_status'));
        add_action('wp_ajax_cna_delete_subscription', array($this, 'ajax_delete_subscription'));
        add_action('wp_ajax_cna_generate_deliveries', array($this, 'ajax_generate_deliveries'));
        add_action('wp_ajax_cna_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
        add_action('admin_notices', array($this, 'maybe_show_webhook_signature_notice'));
    }

    /**
     * Adds SRI integrity and crossorigin attributes to CDN scripts loaded by the dashboard.
     *
     * @param string $tag    HTML script tag.
     * @param string $handle Script handle.
     * @return string
     */
    public function add_cdn_sri_attributes($tag, $handle) {
        $sri = array(
            'chartjs'  => 'sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g',
            'date-fns' => 'sha384-5CPBdnlOIkNjgUKr8IrHYml0ypGpSl75c+3mN7/Ye/uB3sZ28V5A54Hwb3I+ltKM',
        );

        if (isset($sri[$handle])) {
            $tag = str_replace(
                ' src=',
                ' integrity="' . esc_attr($sri[$handle]) . '" crossorigin="anonymous" src=',
                $tag
            );
        }

        return $tag;
    }

    /**
     * Shows an admin warning when Pagadito webhook signature verification is disabled outside local env.
     */
    public function maybe_show_webhook_signature_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_option('cna_pagadito_require_webhook_signature', '1') === '1') {
            return;
        }

        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if ($env === 'local') {
            return;
        }

        $settings_url = admin_url('edit.php?post_type=cna_product&page=cna-settings');
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Seguridad CNA:', 'cna-subscriptions') . '</strong> ';
        echo esc_html__('La verificación de firma del webhook de Pagadito está desactivada. Actívala en ', 'cna-subscriptions');
        echo '<a href="' . esc_url($settings_url) . '">' . esc_html__('Ajustes CNA', 'cna-subscriptions') . '</a>.';
        echo '</p></div>';
    }

    /**
     * Agrega la página del Dashboard al menú del post type
     */
    public function add_dashboard_page() {
        add_submenu_page(
            'edit.php?post_type=cna_product',
            __('Tablero', 'cna-subscriptions'),
            __('Tablero', 'cna-subscriptions'),
            'manage_options',
            'cna-dashboard',
            array($this, 'render_dashboard_page')
        );
    }

    /**
     * Agrega la página de Suscripciones al menú del post type
     */
    public function add_submenu_page() {
        add_submenu_page(
            'edit.php?post_type=cna_product',
            __('Suscripciones', 'cna-subscriptions'),
            __('Suscripciones', 'cna-subscriptions'),
            'manage_options',
            'cna-subscriptions',
            array($this, 'render_subscriptions_page')
        );
    }

    /**
     * Reordena el menú para que "Tablero" y "Suscripciones" aparezcan primero
     */
    public function reorder_submenu() {
        global $submenu;
        
        $menu_slug = 'edit.php?post_type=cna_product';
        
        if (!isset($submenu[$menu_slug])) {
            return;
        }
        
        // Buscar los índices de "Tablero" y "Suscripciones"
        $dashboard_index = false;
        $dashboard_item = null;
        $subscriptions_index = false;
        $subscriptions_item = null;
        
        foreach ($submenu[$menu_slug] as $index => $item) {
            if (isset($item[2])) {
                if ($item[2] === 'cna-dashboard') {
                    $dashboard_index = $index;
                    $dashboard_item = $item;
                } elseif ($item[2] === 'cna-subscriptions') {
                    $subscriptions_index = $index;
                    $subscriptions_item = $item;
                }
            }
        }
        
        // Remover ambos items si existen
        if ($dashboard_index !== false && $dashboard_item !== null) {
            unset($submenu[$menu_slug][$dashboard_index]);
        }
        if ($subscriptions_index !== false && $subscriptions_item !== null) {
            unset($submenu[$menu_slug][$subscriptions_index]);
        }
        
        // Reindexar el array
        $submenu[$menu_slug] = array_values($submenu[$menu_slug]);
        
        // Insertar en el orden correcto: primero Tablero, luego Suscripciones
        if ($dashboard_item !== null) {
            array_splice($submenu[$menu_slug], 0, 0, array($dashboard_item));
        }
        if ($subscriptions_item !== null) {
            // Si ya insertamos dashboard, insertar después de él, sino al principio
            $insert_position = ($dashboard_item !== null) ? 1 : 0;
            array_splice($submenu[$menu_slug], $insert_position, 0, array($subscriptions_item));
        }
    }

    /**
     * Renderiza la vista principal de Suscripciones
     */
    public function render_subscriptions_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Verificar si se solicita ver detalles
        if (isset($_GET['view']) && $_GET['view'] === 'details' && isset($_GET['subscription_id'])) {
            $this->render_subscription_details_page(intval($_GET['subscription_id']));
            return;
        }

        $filters = $this->get_filters_from_request();

        if (isset($_GET['export']) && $_GET['export'] === '1') {
            $this->export_csv($filters);
        }

        $list_data = $this->query_subscriptions($filters);
        $rows = $this->prepare_rows($list_data['items']);
        $total = $list_data['total'];
        $per_page = $filters['per_page'];

        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                <?php _e('Suscripciones', 'cna-subscriptions'); ?>
                <a class="page-title-action" href="<?php echo esc_url($this->get_export_url($filters)); ?>">
                    <?php _e('Exporta', 'cna-subscriptions'); ?>
                </a>
            </h1>

            <?php $this->render_tabs($filters); ?>
            <form class="cna-subscriptions-filters" method="get" style="margin:1.5rem 0;">
                <?php echo $this->render_filter_fields($filters); ?>
            </form>

            <div class="cna-subscriptions-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Producto', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Cliente', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Creada', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Inicia', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Tipo', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Autorenovable', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Próxima renovación', 'cna-subscriptions'); ?></th>
                            <th><?php _e('Acciones', 'cna-subscriptions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="9">
                                    <?php _e('Sin suscripciones que mostrar.', 'cna-subscriptions'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['id']); ?></td>
                                    <td><?php echo esc_html($row['product']); ?></td>
                                    <td><?php echo esc_html($row['cliente']); ?></td>
                                    <td><?php echo esc_html($row['creada']); ?></td>
                                    <td><?php echo esc_html($row['inicia']); ?></td>
                                    <td><?php echo esc_html($row['tipo']); ?></td>
                                    <td><?php echo esc_html($row['autorenovable']); ?></td>
                                    <td><?php echo esc_html($row['proxima_renovacion']); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url($this->get_subscription_detail_url($row['id'])); ?>" 
                                           class="button button-small">
                                            <?php _e('Ver Detalles', 'cna-subscriptions'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php echo $this->render_pagination($filters, $total); ?>
        </div>
        <?php
    }

    /**
     * Obtiene los filtros desde la request
     *
     * @return array
     */
    private function get_filters_from_request() {
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $allowed_status = array('all', 'active', 'pending', 'payment_failed');
        if (!in_array($status, $allowed_status, true)) {
            $status = 'all';
        }

        $date_range = isset($_GET['date_range']) ? sanitize_text_field($_GET['date_range']) : 'all';
        $allowed_ranges = array('all', 'last_7', 'last_30', 'this_month');
        if (!in_array($date_range, $allowed_ranges, true)) {
            $date_range = 'all';
        }

        $shipping_type = isset($_GET['shipping_type']) ? sanitize_text_field($_GET['shipping_type']) : '';
        if (!in_array($shipping_type, array('', 'home', 'pickup'), true)) {
            $shipping_type = '';
        }

        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;

        return compact('status', 'date_range', 'shipping_type', 'search', 'paged', 'per_page');
    }

    /**
     * Construye la URL para exportar
     *
     * @param array $filters
     * @return string
     */
    private function get_export_url($filters) {
        $args = array(
            'post_type' => 'cna_product',
            'page' => 'cna-subscriptions',
            'status' => $filters['status'],
            'date_range' => $filters['date_range'],
            'shipping_type' => $filters['shipping_type'],
            'search' => $filters['search'],
            'export' => '1',
        );

        return esc_url(add_query_arg($args, admin_url('edit.php')));
    }

    /**
     * Realiza la exportación CSV
     *
     * @param array $filters
     * @return void
     */
    private function export_csv($filters) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $export_filters = $filters;
        $export_filters['per_page'] = 1000;
        $export_filters['paged'] = 1;

        $rows = $this->prepare_rows($this->query_subscriptions($export_filters)['items']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cna-suscripciones.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, array(
            'ID',
            'Producto',
            'Cliente',
            'Creada',
            'Inicia',
            'Tipo',
            'Autorenovable',
            'Próxima renovación',
        ));

        foreach ($rows as $row) {
            fputcsv($output, array_values($row));
        }

        fclose($output);
        exit;
    }

    /**
     * Consulta las suscripciones según los filtros
     *
     * @param array $filters
     * @return array
     */
    private function query_subscriptions($filters) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        $subscriptions_table = $table_prefix . 'cna_subscriptions';
        $deliveries_table = $table_prefix . 'cna_deliveries';
        $users_table = $wpdb->users;
        $posts_table = $wpdb->posts;

        $where = array('s.id > 0');
        $params = array();

        if ($filters['status'] !== 'all') {
            $type = 'all';
            switch ($filters['status']) {
                case 'active':
                    $type = 'active';
                    break;
                case 'pending':
                    $type = 'pending';
                    break;
                case 'payment_failed':
                    $type = 'payment_failed';
                    break;
            }
            if ($type !== 'all') {
                $where[] = 's.status = %s';
                $params[] = $type;
            }
        }

        $start_date = $this->get_start_date_from_range($filters['date_range']);
        if ($start_date) {
            $where[] = 's.created_at >= %s';
            $params[] = $start_date;
        }

        if (!empty($filters['shipping_type'])) {
            $value = '"type":"' . $filters['shipping_type'] . '"';
            $where[] = 's.shipping_address_json LIKE %s';
            $params[] = '%' . $wpdb->esc_like($value) . '%';
        }

        if (!empty($filters['search'])) {
            $search_value = trim($filters['search']);
            $like = '%' . $wpdb->esc_like($search_value) . '%';

            if (is_numeric($search_value)) {
                $where[] = '(s.id = %d OR u.display_name LIKE %s OR p.post_title LIKE %s)';
                $params[] = intval($search_value);
                $params[] = $like;
                $params[] = $like;
            } else {
                $where[] = '(u.display_name LIKE %s OR p.post_title LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }
        }

        $where_sql = implode(' AND ', $where);
        $join = "
            LEFT JOIN {$users_table} u ON u.ID = s.user_id
            LEFT JOIN {$posts_table} p ON p.ID = s.product_id
            LEFT JOIN (
                SELECT subscription_id, MIN(scheduled_date) AS first_delivery
                FROM {$deliveries_table}
                GROUP BY subscription_id
            ) d ON d.subscription_id = s.id
        ";

        $count_sql = "SELECT COUNT(DISTINCT s.id) FROM {$subscriptions_table} s {$join} WHERE {$where_sql}";
        $count_query = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($count_sql), $params));
        $total = intval($wpdb->get_var($count_query));

        $offset = max(0, ($filters['paged'] - 1) * $filters['per_page']);
        $items_sql = "
            SELECT s.*, u.display_name AS client_name, p.post_title AS product_name, d.first_delivery
            FROM {$subscriptions_table} s
            {$join}
            WHERE {$where_sql}
            ORDER BY s.created_at DESC
            LIMIT %d OFFSET %d
        ";

        $query_params = array_merge($params, array($filters['per_page'], $offset));
        $items_query = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($items_sql), $query_params));
        $items = $wpdb->get_results($items_query);

        return array(
            'items' => $items,
            'total' => $total,
        );
    }

    /**
     * Prepara los datos para renderizar o exportar
     *
     * @param array $items
     * @return array
     */
    private function prepare_rows($items) {
        $rows = array();
        foreach ($items as $item) {
            $shipping = $this->decode_json($item->shipping_address_json);
            $shipping_type_key = $shipping['type'] ?? 'home';
            $rows[] = array(
                'id' => $item->id,
                'product' => $item->product_name ?: __('Producto eliminado', 'cna-subscriptions'),
                'cliente' => $item->client_name ?: __('Cliente eliminado', 'cna-subscriptions'),
                'creada' => $this->format_datetime($item->created_at),
                'inicia' => $this->format_date($item->first_delivery),
                'tipo' => $this->get_shipping_type_label($shipping_type_key),
                'autorenovable' => $item->is_auto_renew ? __('Sí', 'cna-subscriptions') : __('No', 'cna-subscriptions'),
                'proxima_renovacion' => $this->format_date($item->next_renewal_date),
                'shipping_type_key' => $shipping_type_key,
                'status' => $item->status,
            );
        }
        return $rows;
    }

    /**
     * Devuelve la etiqueta del tipo de envío
     *
     * @param string $type
     * @return string
     */
    private function get_shipping_type_label($type) {
        switch ($type) {
            case 'pickup':
                return __('Retiro en tienda', 'cna-subscriptions');
            case 'home':
            default:
                return __('Domicilio', 'cna-subscriptions');
        }
    }

    /**
     * Decodifica JSON de manera segura
     *
     * @param string|null $json
     * @return array
     */
    private function decode_json($json) {
        if (empty($json)) {
            return array();
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }

        return $decoded;
    }

    /**
     * Formatea fechas
     *
     * @param string|null $value
     * @return string
     */
    private function format_date($value) {
        if (empty($value)) {
            return '—';
        }

        return date_i18n('d/m/Y', strtotime($value));
    }

    /**
     * Formatea fecha y hora (p. ej. columna Creada).
     *
     * @param string|null $value
     * @return string
     */
    private function format_datetime($value) {
        if (empty($value)) {
            return '—';
        }

        return date_i18n('d/m/Y H:i', strtotime($value));
    }

    /**
     * Obtiene la fecha inicial para usar en filtros
     *
     * @param string $range
     * @return string|null
     */
    private function get_start_date_from_range($range) {
        $timestamp = current_time('timestamp');

        switch ($range) {
            case 'last_7':
                $timestamp -= DAY_IN_SECONDS * 7;
                break;
            case 'last_30':
                $timestamp -= DAY_IN_SECONDS * 30;
                break;
            case 'this_month':
                $timestamp = strtotime(date('Y-m-01 00:00:00', $timestamp));
                break;
            default:
                return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Renderiza las pestañas de estado
     *
     * @param array $filters
     */
    private function render_tabs($filters) {
        $tabs = array(
            'all' => __('Toda', 'cna-subscriptions'),
            'active' => __('Activa', 'cna-subscriptions'),
        );
        ?>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="<?php echo esc_url($this->get_tab_url($filters, $key)); ?>"
                   class="nav-tab <?php echo $filters['status'] === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </h2>
        <?php
    }

    /**
     * Construye la URL de una pestaña
     */
    private function get_tab_url($filters, $tab) {
        $args = array(
            'post_type' => 'cna_product',
            'page' => 'cna-subscriptions',
            'status' => $tab,
            'date_range' => $filters['date_range'],
            'shipping_type' => $filters['shipping_type'],
            'search' => $filters['search'],
        );

        return esc_url(add_query_arg($args, admin_url('edit.php')));
    }

    /**
     * Renderiza los campos del formulario de filtros
     *
     * @param array $filters
     * @return string
     */
    private function render_filter_fields($filters) {
        ob_start();
        ?>
        <input type="hidden" name="post_type" value="cna_product">
        <input type="hidden" name="page" value="cna-subscriptions">
        <div style="display:flex; align-items:flex-end; gap:1rem; flex-wrap:wrap;">
            <div>
                <label><?php _e('Buscar', 'cna-subscriptions'); ?></label>
                <input type="text" name="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('ID o cliente', 'cna-subscriptions'); ?>" class="regular-text">
            </div>
            <div>
                <label><?php _e('Rango de fechas', 'cna-subscriptions'); ?></label>
                <select name="date_range">
                    <option value="all" <?php selected($filters['date_range'], 'all'); ?>><?php _e('Todas las fechas', 'cna-subscriptions'); ?></option>
                    <option value="last_7" <?php selected($filters['date_range'], 'last_7'); ?>><?php _e('Últimos 7 días', 'cna-subscriptions'); ?></option>
                    <option value="last_30" <?php selected($filters['date_range'], 'last_30'); ?>><?php _e('Últimos 30 días', 'cna-subscriptions'); ?></option>
                    <option value="this_month" <?php selected($filters['date_range'], 'this_month'); ?>><?php _e('Este mes', 'cna-subscriptions'); ?></option>
                </select>
            </div>
            <div>
                <label><?php _e('Tipo de envío', 'cna-subscriptions'); ?></label>
                <select name="shipping_type">
                    <option value="" <?php selected($filters['shipping_type'], ''); ?>><?php _e('Todos los tipos', 'cna-subscriptions'); ?></option>
                    <option value="home" <?php selected($filters['shipping_type'], 'home'); ?>><?php _e('Domicilio', 'cna-subscriptions'); ?></option>
                    <option value="pickup" <?php selected($filters['shipping_type'], 'pickup'); ?>><?php _e('Retiro en tienda', 'cna-subscriptions'); ?></option>
                </select>
            </div>
            <div>
                <button type="submit" class="button button-primary"><?php _e('Filtrar', 'cna-subscriptions'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza la paginación
     *
     * @param array $filters
     * @param int $total
     * @return string
     */
    private function render_pagination($filters, $total) {
        $total_pages = (int) ceil($total / $filters['per_page']);
        if ($total_pages <= 1) {
            return '';
        }

        $pagination = paginate_links(array(
            'base' => esc_url(add_query_arg('paged', '%#%')),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $total_pages,
            'current' => max(1, $filters['paged']),
        ));

        if (!$pagination) {
            return '';
        }

        return '<div class="tablenav"><div class="tablenav-pages">' . $pagination . '</div></div>';
    }

    /**
     * Información del producto (catálogo, sin cobro con tarjeta).
     */
    private function render_subscription_product_info_section($subscription, $variant_details, $qty, $unit_price, $product_subtotal, $advance_percent, $has_partial_advance, $remaining_per_delivery) {
        $pending_product_total = max(0, $product_subtotal - ($product_subtotal * ($advance_percent / 100)));

        ob_start();
        ?>
        <div class="cna-detail-block cna-detail-block--info">
            <h3 class="cna-detail-block__title"><?php _e('Información del producto', 'cna-subscriptions'); ?></h3>
            <p class="cna-detail-block__hint"><?php _e('Datos de la suscripción y precios de catálogo. No incluye el cobro con tarjeta.', 'cna-subscriptions'); ?></p>
            <dl class="cna-info-dl">
                <dt><?php _e('Producto', 'cna-subscriptions'); ?></dt>
                <dd><?php echo esc_html($subscription->product_name); ?></dd>
                <dt><?php _e('Tamaño', 'cna-subscriptions'); ?></dt>
                <dd><?php echo esc_html($variant_details['size'] ?? '—'); ?></dd>
                <dt><?php _e('Frecuencia', 'cna-subscriptions'); ?></dt>
                <dd><?php echo esc_html(($variant_details['frequency'] ?? '—') . ' ' . __('semanas', 'cna-subscriptions')); ?></dd>
                <dt><?php _e('Cantidad de canastas', 'cna-subscriptions'); ?></dt>
                <dd><?php echo esc_html($qty); ?></dd>
                <dt><?php _e('Precio unitario (catálogo)', 'cna-subscriptions'); ?></dt>
                <dd>$<?php echo number_format($unit_price, 2); ?></dd>
                <dt><?php _e('Valor total del producto', 'cna-subscriptions'); ?></dt>
                <dd><strong>$<?php echo number_format($product_subtotal, 2); ?></strong></dd>
                <?php if ($has_partial_advance) : ?>
                <dt><?php _e('Anticipo contratado', 'cna-subscriptions'); ?></dt>
                <dd><?php echo esc_html(number_format($advance_percent, 0)); ?>%</dd>
                <dt><?php _e('Pendiente del producto (no cobrado ahora)', 'cna-subscriptions'); ?></dt>
                <dd>
                    $<?php echo number_format($pending_product_total, 2); ?>
                    <span class="cna-detail-block__muted">
                        <?php
                        printf(
                            esc_html__('Se cobra $%s por canasta en cada entrega (%d entregas).', 'cna-subscriptions'),
                            number_format($remaining_per_delivery, 2),
                            intval($qty)
                        );
                        ?>
                    </span>
                </dd>
                <?php endif; ?>
            </dl>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Resumen del cobro inicial con pasarela.
     */
    private function render_subscription_charge_summary_section($advance_amount, $shipping_total, $annual_fee, $net_amount, $fee_amount, $total_with_fee, $advance_percent, $has_partial_advance) {
        ob_start();
        ?>
        <div class="cna-detail-block cna-detail-block--charge">
            <h3 class="cna-detail-block__title"><?php _e('Cobro inicial con tarjeta', 'cna-subscriptions'); ?></h3>
            <p class="cna-detail-block__hint"><?php _e('Montos incluidos en el pago procesado por la pasarela al activar la suscripción.', 'cna-subscriptions'); ?></p>
            <table class="cna-charge-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <?php
                            if ($has_partial_advance) {
                                printf(esc_html__('Anticipo del producto (%s%%)', 'cna-subscriptions'), esc_html(number_format($advance_percent, 0)));
                            } else {
                                esc_html_e('Producto (100% anticipo)', 'cna-subscriptions');
                            }
                            ?>
                        </th>
                        <td>$<?php echo number_format($advance_amount, 2); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Envío (100% anticipo)', 'cna-subscriptions'); ?></th>
                        <td>$<?php echo number_format($shipping_total, 2); ?></td>
                    </tr>
                    <?php if ($annual_fee > 0) : ?>
                    <tr>
                        <th scope="row"><?php _e('Cuota anual', 'cna-subscriptions'); ?></th>
                        <td>$<?php echo number_format($annual_fee, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="cna-charge-table__subtotal">
                        <th scope="row"><?php _e('Neto a recibir (antes de fee pasarela)', 'cna-subscriptions'); ?></th>
                        <td><strong>$<?php echo number_format($net_amount, 2); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Fee pasarela (cargado al cliente)', 'cna-subscriptions'); ?></th>
                        <td>$<?php echo number_format($fee_amount, 2); ?></td>
                    </tr>
                    <tr class="cna-charge-table__total">
                        <th scope="row"><?php _e('Total cobrado al cliente', 'cna-subscriptions'); ?></th>
                        <td><strong>$<?php echo number_format($total_with_fee, 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Bloque de detalle de transacción Pagadito (debajo del desglose de cobro).
     */
    private function render_payment_transaction_section($payment_transaction, $subscription) {
        $fields = $payment_transaction['fields'] ?? array();
        $provider_label = $payment_transaction['provider_label'] ?? '';
        $has_webhook_detail = !empty($fields);

        if (empty($fields)) {
            if (!empty($subscription->pagadito_ern)) {
                $fields[] = array(
                    'label' => __('Referencia de orden (ERN)', 'cna-subscriptions'),
                    'value' => $subscription->pagadito_ern,
                );
            }
            if (floatval($subscription->total_with_fee) > 0) {
                $fields[] = array(
                    'label' => __('Total cobrado', 'cna-subscriptions'),
                    'value' => '$' . number_format(floatval($subscription->total_with_fee), 2),
                );
            }
            if (!empty($subscription->created_at)) {
                $fields[] = array(
                    'label' => __('Fecha de registro', 'cna-subscriptions'),
                    'value' => $this->format_datetime($subscription->created_at),
                );
            }
            $provider_label = __('Pagadito', 'cna-subscriptions');
        }

        $show_section = floatval($subscription->total_with_fee) > 0
            || !empty($subscription->pagadito_ern)
            || $has_webhook_detail
            || in_array($subscription->status, array('active', 'pending', 'payment_failed'), true);

        if (!$show_section || empty($fields)) {
            return '';
        }

        if ($provider_label === '') {
            $provider_label = __('Pasarela de pago', 'cna-subscriptions');
        }

        ob_start();
        ?>
        <div class="cna-payment-transaction-section">
            <div class="cna-payment-transaction-header">
                <?php echo esc_html($provider_label); ?>
            </div>
            <h3 class="cna-payment-transaction-title"><?php _e('Detalle de transacción Pagadito', 'cna-subscriptions'); ?></h3>
            <?php if (!$has_webhook_detail) : ?>
                <p class="cna-payment-transaction-notice">
                    <?php _e('Los datos completos del comprobante Pagadito (número de aprobación PG, hora exacta, etc.) aparecerán cuando el webhook confirme el pago. Abajo se muestra la información registrada en el sistema.', 'cna-subscriptions'); ?>
                </p>
            <?php endif; ?>
            <table class="cna-payment-transaction-table">
                <tbody>
                    <?php foreach ($fields as $field) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($field['label']); ?></th>
                            <td><?php echo esc_html($field['value']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * URL del listado de suscripciones en el admin.
     *
     * @param array $extra_args Query args opcionales (filtros, paginación, etc.).
     * @return string
     */
    private function get_subscriptions_list_url($extra_args = array()) {
        $args = array_merge(
            array(
                'post_type' => 'cna_product',
                'page' => 'cna-subscriptions',
            ),
            $extra_args
        );

        unset($args['view'], $args['subscription_id']);

        return add_query_arg($args, admin_url('edit.php'));
    }

    /**
     * Construye la URL para ver detalles de una suscripción
     *
     * @param int $subscription_id
     * @return string
     */
    private function get_subscription_detail_url($subscription_id) {
        return esc_url(
            add_query_arg(
                array(
                    'post_type' => 'cna_product',
                    'page' => 'cna-subscriptions',
                    'view' => 'details',
                    'subscription_id' => $subscription_id,
                ),
                admin_url('edit.php')
            )
        );
    }

    /**
     * Líneas de texto para dirección de facturación.
     *
     * @param array $billing_address
     * @return array
     */
    private function format_billing_address_lines($billing_address) {
        if (!is_array($billing_address)) {
            return array();
        }

        $lines = array();
        if (!empty($billing_address['address_1'])) {
            $lines[] = $billing_address['address_1'];
        }
        $city_state = array_filter(array(
            $billing_address['city'] ?? '',
            $billing_address['state'] ?? '',
        ));
        if (!empty($city_state)) {
            $lines[] = implode(', ', $city_state);
        }
        if (!empty($billing_address['country'])) {
            $lines[] = $billing_address['country'];
        }
        if (!empty($billing_address['reference'])) {
            $lines[] = __('Referencia:', 'cna-subscriptions') . ' ' . $billing_address['reference'];
        }

        return $lines;
    }

    /**
     * HTML de factura solo para impresión (no visible en pantalla).
     */
    private function render_invoice_print_document(
        $subscription,
        $subscription_id,
        $variant_details,
        $shipping_address,
        $billing_address,
        $user_first_name,
        $user_last_name,
        $user_phone,
        $qty,
        $unit_price,
        $product_subtotal,
        $advance_percent,
        $has_partial_advance,
        $advance_amount,
        $shipping_total,
        $annual_fee,
        $fee_amount,
        $total_with_fee
    ) {
        $billing_lines = $this->format_billing_address_lines($billing_address);
        $shipping_lines = array();

        if (($shipping_address['type'] ?? '') === 'home') {
            $shipping_lines = array_filter(array(
                $shipping_address['address'] ?? '',
                trim(implode(', ', array_filter(array(
                    $shipping_address['district'] ?? '',
                    $shipping_address['municipality'] ?? '',
                    $shipping_address['department'] ?? '',
                )))),
            ));
        }

        $payment_status_label = in_array($subscription->status, array('active', 'completed'), true)
            ? __('Pagada', 'cna-subscriptions')
            : $this->get_status_label($subscription->status);

        ob_start();
        ?>
        <div id="cna-invoice-print-document" class="cna-invoice-print">
            <header class="cna-invoice-print__brand">
                <h1 class="cna-invoice-print__title"><?php echo esc_html(get_bloginfo('name')); ?></h1>
                <p class="cna-invoice-print__doc-type"><?php esc_html_e('Factura de compra', 'cna-subscriptions'); ?></p>
            </header>

            <section class="cna-invoice-print__meta">
                <div class="cna-invoice-print__meta-col">
                    <p><strong><?php esc_html_e('Número de orden:', 'cna-subscriptions'); ?></strong> <?php echo esc_html($subscription_id); ?></p>
                    <p><strong><?php esc_html_e('Fecha:', 'cna-subscriptions'); ?></strong> <?php echo esc_html($this->format_datetime($subscription->created_at)); ?></p>
                </div>
                <div class="cna-invoice-print__meta-col cna-invoice-print__meta-col--right">
                    <p><strong><?php esc_html_e('Estado del pago:', 'cna-subscriptions'); ?></strong> <?php echo esc_html($payment_status_label); ?></p>
                    <?php if (!empty($subscription->pagadito_ern)) : ?>
                        <p><strong><?php esc_html_e('Referencia:', 'cna-subscriptions'); ?></strong> <?php echo esc_html($subscription->pagadito_ern); ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="cna-invoice-print__section">
                <h2 class="cna-invoice-print__section-title"><?php esc_html_e('Cliente', 'cna-subscriptions'); ?></h2>
                <p class="cna-invoice-print__line"><strong><?php echo esc_html(trim($user_first_name . ' ' . $user_last_name)); ?></strong></p>
                <p class="cna-invoice-print__line"><?php echo esc_html($subscription->user_email); ?></p>
                <?php if ($user_phone) : ?>
                    <p class="cna-invoice-print__line"><?php echo esc_html($user_phone); ?></p>
                <?php endif; ?>

                <?php if (!empty($billing_lines)) : ?>
                    <h3 class="cna-invoice-print__subsection-title"><?php esc_html_e('Dirección de facturación', 'cna-subscriptions'); ?></h3>
                    <?php foreach ($billing_lines as $line) : ?>
                        <p class="cna-invoice-print__line"><?php echo esc_html($line); ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($shipping_lines)) : ?>
                    <h3 class="cna-invoice-print__subsection-title"><?php esc_html_e('Dirección de entrega', 'cna-subscriptions'); ?></h3>
                    <?php foreach ($shipping_lines as $line) : ?>
                        <p class="cna-invoice-print__line"><?php echo esc_html($line); ?></p>
                    <?php endforeach; ?>
                <?php elseif (($shipping_address['type'] ?? '') === 'pickup') : ?>
                    <h3 class="cna-invoice-print__subsection-title"><?php esc_html_e('Entrega', 'cna-subscriptions'); ?></h3>
                    <p class="cna-invoice-print__line"><?php esc_html_e('Retiro en tienda', 'cna-subscriptions'); ?></p>
                <?php endif; ?>
            </section>

            <section class="cna-invoice-print__section">
                <h2 class="cna-invoice-print__section-title"><?php esc_html_e('Detalle del pedido', 'cna-subscriptions'); ?></h2>
                <table class="cna-invoice-print__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Descripción', 'cna-subscriptions'); ?></th>
                            <th class="cna-invoice-print__num"><?php esc_html_e('Cant.', 'cna-subscriptions'); ?></th>
                            <th class="cna-invoice-print__num"><?php esc_html_e('P. unit.', 'cna-subscriptions'); ?></th>
                            <th class="cna-invoice-print__num"><?php esc_html_e('Importe', 'cna-subscriptions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <?php echo esc_html($subscription->product_name); ?>
                                <?php if (!empty($variant_details['size'])) : ?>
                                    <br><span class="cna-invoice-print__muted"><?php echo esc_html($variant_details['size']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="cna-invoice-print__num"><?php echo esc_html($qty); ?></td>
                            <td class="cna-invoice-print__num">$<?php echo number_format($unit_price, 2); ?></td>
                            <td class="cna-invoice-print__num">$<?php echo number_format($product_subtotal, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="cna-invoice-print__section cna-invoice-print__totals">
                <h2 class="cna-invoice-print__section-title"><?php esc_html_e('Resumen del pago', 'cna-subscriptions'); ?></h2>
                <table class="cna-invoice-print__totals-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <?php
                                if ($has_partial_advance) {
                                    printf(
                                        esc_html__('Anticipo del producto (%s%%)', 'cna-subscriptions'),
                                        esc_html(number_format($advance_percent, 0))
                                    );
                                } else {
                                    esc_html_e('Producto (anticipo)', 'cna-subscriptions');
                                }
                                ?>
                            </th>
                            <td>$<?php echo number_format($advance_amount, 2); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Envío', 'cna-subscriptions'); ?></th>
                            <td>$<?php echo number_format($shipping_total, 2); ?></td>
                        </tr>
                        <?php if ($annual_fee > 0) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Cuota anual', 'cna-subscriptions'); ?></th>
                            <td>$<?php echo number_format($annual_fee, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($fee_amount > 0) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Comisión de pasarela', 'cna-subscriptions'); ?></th>
                            <td>$<?php echo number_format($fee_amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="cna-invoice-print__grand-total">
                            <th scope="row"><?php esc_html_e('Total pagado', 'cna-subscriptions'); ?></th>
                            <td><strong>$<?php echo number_format($total_with_fee, 2); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <footer class="cna-invoice-print__footer">
                <p><?php esc_html_e('Documento generado desde el sistema de suscripciones.', 'cna-subscriptions'); ?></p>
            </footer>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza la página de detalles de una suscripción
     *
     * @param int $subscription_id
     */
    private function render_subscription_details_page($subscription_id) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción completa
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email, p.post_title as product_name, p.ID as product_id
             FROM {$table_prefix}cna_subscriptions s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
             WHERE s.id = %d",
            $subscription_id
        ));

        if (!$subscription) {
            wp_die(__('Suscripción no encontrada', 'cna-subscriptions'));
        }

        // Decodificar JSON
        $variant_details = $this->decode_json($subscription->variant_details);
        $shipping_address = $this->decode_json($subscription->shipping_address_json);
        $billing_address = $this->decode_json($subscription->billing_address_json ?? '{}');

        // Obtener datos del usuario
        $user_meta = get_user_meta($subscription->user_id);
        $user_phone = $user_meta['phone'][0] ?? '';
        $user_first_name = $user_meta['first_name'][0] ?? $subscription->display_name;
        $user_last_name = $user_meta['last_name'][0] ?? '';

        // Obtener imagen del producto
        $product_image = get_the_post_thumbnail_url($subscription->product_id, 'medium');
        if (!$product_image) {
            $product_image = wc_placeholder_img_src('medium');
        }

        // Obtener entregas
        $deliveries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_deliveries 
             WHERE subscription_id = %d 
             ORDER BY scheduled_date ASC",
            $subscription_id
        ));

        // Obtener tienda de recogida si aplica
        $store = null;
        if ($shipping_address['type'] === 'pickup' && !empty($shipping_address['store_id'])) {
            $store = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_prefix}cna_pickup_stores WHERE id = %d",
                intval($shipping_address['store_id'])
            ));
        }

        // Desglose de precios (anticipo vs valor total del producto)
        $qty = max(1, intval($variant_details['qty'] ?? 1));
        $advance_percent = floatval($variant_details['advance_percent'] ?? 100);
        $advance_percent = ($advance_percent === 50.0) ? 50 : 100;

        $product_subtotal = floatval($subscription->product_subtotal);
        $unit_price = floatval($subscription->unit_price);
        $advance_amount = floatval($subscription->advance_amount);
        if ($advance_amount <= 0 && $product_subtotal > 0) {
            $advance_amount = $product_subtotal * ($advance_percent / 100);
        }

        $has_partial_advance = $advance_percent < 100;
        $remaining_per_delivery = $has_partial_advance
            ? $unit_price * ((100 - $advance_percent) / 100)
            : 0;

        $shipping_total = floatval($subscription->shipping_total);
        $annual_fee = floatval($subscription->annual_fee);
        $fee_amount = floatval($subscription->fee_amount);
        $net_amount = floatval($subscription->net_amount);
        if ($net_amount <= 0) {
            $net_amount = $advance_amount + $shipping_total + $annual_fee;
        }
        $total_with_fee = floatval($subscription->total_with_fee);

        $payment_transaction = class_exists('CNA_Payment_Transaction')
            ? CNA_Payment_Transaction::get_display_data($subscription)
            : array('provider' => '', 'provider_label' => '', 'fields' => array());

        ?>
        <?php echo $this->get_details_page_styles(); ?>
        <div class="wrap cna-subscription-details">
            <!-- Header con Navegación -->
            <div class="cna-page-header">
                <a href="<?php echo esc_url($this->get_subscriptions_list_url()); ?>" 
                   class="cna-back-link">
                    <svg class="cna-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php _e('Volver al listado', 'cna-subscriptions'); ?>
                </a>
            </div>

            <!-- Contenedor Principal Card Style -->
            <div class="cna-main-card" id="cna-subscription-detail-card">
                <!-- Encabezado de la Card -->
                <div class="cna-card-header">
                    <div class="cna-header-content">
                        <h1 class="cna-subscription-title">
                            <?php _e('Suscripción', 'cna-subscriptions'); ?>
                            <span class="cna-subscription-id">#<?php echo esc_html($subscription_id); ?></span>
                        </h1>
                        <span class="cna-status-badge cna-status-<?php echo esc_attr($subscription->status); ?>">
                            <?php echo esc_html($this->get_status_label($subscription->status)); ?>
                        </span>
                    </div>
                    <div class="cna-btn-group">
                        <button type="button" onclick="window.print()" class="cna-btn-print">
                            <svg class="cna-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 9V2H18V9M6 18H4C3.46957 18 2.96086 17.7893 2.58579 17.4142C2.21071 17.0391 2 16.5304 2 16V11C2 10.4696 2.21071 9.96086 2.58579 9.58579C2.96086 9.21071 3.46957 9 4 9H20C20.5304 9 21.0391 9.21071 21.4142 9.58579C21.7893 9.96086 22 10.4696 22 11V16C22 16.5304 21.7893 17.0391 21.4142 17.4142C21.0391 17.7893 20.5304 18 20 18H18M6 14H18V22H6V14Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?php _e('Imprimir Factura', 'cna-subscriptions'); ?>
                        </button>

                        <button type="button" id="cna-delete-subscription" class="cna-btn-delete-outline" 
                                data-subscription-id="<?php echo esc_attr($subscription_id); ?>">
                            <?php _e('Eliminar Suscripción', 'cna-subscriptions'); ?>
                        </button>
                    </div>
                </div>

                <!-- Contenido Principal: Layout 60/40 -->
                <div class="cna-card-content">
                    <!-- Columna Izquierda (60%) -->
                    <div class="cna-left-column">
                        <!-- Información de la Empresa -->
                        <div class="cna-company-section">
                            <h2 class="cna-company-name">Detalle de la Suscripción</h2>
                        </div>

                        <!-- Producto e Imagen -->
                        <div class="cna-product-section">
                            <?php if ($product_image): ?>
                            <div class="cna-product-image-container">
                                <img src="<?php echo esc_url($product_image); ?>" 
                                     alt="<?php echo esc_attr($subscription->product_name); ?>" 
                                     class="cna-product-image">
                            </div>
                            <?php endif; ?>
                            <div class="cna-product-info">
                                <h3 class="cna-product-title"><?php echo esc_html($subscription->product_name); ?></h3>
                                <div class="cna-product-tags">
                                    <span class="cna-tag"><?php _e('Tamaño:', 'cna-subscriptions'); ?> <?php echo esc_html($variant_details['size'] ?? 'N/A'); ?></span>
                                    <span class="cna-tag"><?php _e('Frecuencia:', 'cna-subscriptions'); ?> <?php echo esc_html($variant_details['frequency'] ?? 'N/A'); ?> <?php _e('semanas', 'cna-subscriptions'); ?></span>
                                    <span class="cna-tag"><?php _e('Anticipo:', 'cna-subscriptions'); ?> <?php echo esc_html($advance_percent); ?>%</span>
                                </div>
                            </div>
                        </div>

                        <div class="cna-pricing-section">
                            <?php
                            echo $this->render_subscription_product_info_section(
                                $subscription,
                                $variant_details,
                                $qty,
                                $unit_price,
                                $product_subtotal,
                                $advance_percent,
                                $has_partial_advance,
                                $remaining_per_delivery
                            );
                            echo $this->render_subscription_charge_summary_section(
                                $advance_amount,
                                $shipping_total,
                                $annual_fee,
                                $net_amount,
                                $fee_amount,
                                $total_with_fee,
                                $advance_percent,
                                $has_partial_advance
                            );
                            echo $this->render_payment_transaction_section($payment_transaction, $subscription);
                            ?>
                        </div>
                    </div>

                    <!-- Columna Derecha (40%) -->
                    <div class="cna-right-column">

                    <!-- Acciones -->
                        <div class="cna-actions-section">
                            <h3 class="cna-section-heading"><?php _e('Acciones', 'cna-subscriptions'); ?></h3>
                            <div class="cna-actions-content">
                                <p class="cna-actions-auto-renew-status">
                                    <?php _e('Renovación automática:', 'cna-subscriptions'); ?>
                                    <strong><?php echo !empty($subscription->is_auto_renew) ? esc_html__('Activa', 'cna-subscriptions') : esc_html__('Desactivada', 'cna-subscriptions'); ?></strong>
                                    <?php if (!empty($subscription->next_renewal_date)) : ?>
                                        <span class="cna-actions-auto-renew-date">
                                            (<?php printf(esc_html__('próxima: %s', 'cna-subscriptions'), esc_html($this->format_date($subscription->next_renewal_date))); ?>)
                                        </span>
                                    <?php endif; ?>
                                </p>
                                <label class="cna-select-label"><?php _e('Acción:', 'cna-subscriptions'); ?></label>
                                <select id="cna-subscription-action" class="cna-select-modern">
                                    <option value=""><?php _e('Seleccionar acción...', 'cna-subscriptions'); ?></option>
                                    <optgroup label="<?php echo esc_attr__('Estado de la suscripción', 'cna-subscriptions'); ?>">
                                        <option value="activate" <?php echo $subscription->status === 'active' ? 'disabled' : ''; ?>>
                                            <?php _e('Activar suscripción', 'cna-subscriptions'); ?>
                                        </option>
                                        <option value="pause" <?php echo $subscription->status === 'paused' ? 'disabled' : ''; ?>>
                                            <?php _e('Pausar suscripción', 'cna-subscriptions'); ?>
                                        </option>
                                        <option value="cancel" <?php echo $subscription->status === 'cancelled' ? 'disabled' : ''; ?>>
                                            <?php _e('Cancelar suscripción', 'cna-subscriptions'); ?>
                                        </option>
                                        <option value="renew">
                                            <?php _e('Renovar suscripción', 'cna-subscriptions'); ?>
                                        </option>
                                    </optgroup>
                                    <optgroup label="<?php echo esc_attr__('Renovación automática', 'cna-subscriptions'); ?>">
                                        <option value="disable_auto_renew" <?php echo empty($subscription->is_auto_renew) ? 'disabled' : ''; ?>>
                                            <?php _e('Desactivar auto-renovación (mantener entregas actuales)', 'cna-subscriptions'); ?>
                                        </option>
                                        <option value="enable_auto_renew" <?php echo !empty($subscription->is_auto_renew) ? 'disabled' : ''; ?>>
                                            <?php _e('Activar auto-renovación', 'cna-subscriptions'); ?>
                                        </option>
                                    </optgroup>
                                </select>
                            </div>
                        </div>
                        <!-- Datos del Cliente -->
                        <div class="cna-customer-section">
                            <h3 class="cna-section-heading"><?php _e('Datos del Cliente', 'cna-subscriptions'); ?></h3>
                            <div class="cna-customer-info">
                                <div class="cna-info-item">
                                    <svg class="cna-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M20 21V19C20 17.6046 18.2091 16 16 16H8C5.79086 16 4 17.6046 4 19V21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span class="cna-info-text"><?php echo esc_html($user_first_name . ' ' . $user_last_name); ?></span>
                                </div>
                                <div class="cna-info-item">
                                    <svg class="cna-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3 8L10.89 13.26C11.2187 13.4793 11.6049 13.5963 12 13.5963C12.3951 13.5963 12.7813 13.4793 13.11 13.26L21 8M5 19H19C19.5304 19 20.0391 18.7893 20.4142 18.4142C20.7893 18.0391 21 17.5304 21 17V7C21 6.46957 20.7893 5.96086 20.4142 5.58579C20.0391 5.21071 19.5304 5 19 5H5C4.46957 5 3.96086 5.21071 3.58579 5.58579C3.21071 5.96086 3 6.46957 3 7V17C3 17.5304 3.21071 18.0391 3.58579 18.4142C3.96086 18.7893 4.46957 19 5 19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span class="cna-info-text"><?php echo esc_html($subscription->user_email); ?></span>
                                </div>
                                <?php if ($user_phone): ?>
                                <div class="cna-info-item">
                                    <svg class="cna-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3 5C3 3.89543 3.89543 3 5 3H8.27924C8.70967 3 9.09181 3.27543 9.22792 3.68377L10.7257 8.17721C10.8831 8.64932 10.6694 9.16531 10.2243 9.38787L7.96701 10.5165C9.06925 12.9612 11.0388 14.9308 13.4835 16.033L14.6121 13.7757C14.8347 13.3306 15.3507 13.1169 15.8228 13.2743L20.3162 14.7721C20.7246 14.9082 21 15.2903 21 15.7208V19C21 20.1046 20.1046 21 19 21H18C9.71573 21 3 14.2843 3 6V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span class="cna-info-text"><?php echo esc_html($user_phone); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Dirección de Entrega -->
                        <div class="cna-shipping-section">
                            <h3 class="cna-section-heading"><?php _e('Dirección de Entrega', 'cna-subscriptions'); ?></h3>
                            <div class="cna-shipping-info">
                                <div class="cna-shipping-type-badge">
                                    <?php if ($shipping_address['type'] === 'pickup'): ?>
                                        <svg class="cna-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M3 21H21M5 21V7L12 3L19 7V21M9 9V21M15 9V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        <span><?php _e('Retiro en Tienda', 'cna-subscriptions'); ?></span>
                                    <?php else: ?>
                                        <svg class="cna-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M3 12L5 10M5 10L12 3L19 10M5 10V20C5 20.5304 5.21071 21.0391 5.58579 21.4142C5.96086 21.7893 6.46957 22 7 22H17C17.5304 22 18.0391 21.7893 18.4142 21.4142C18.7893 21.0391 19 20.5304 19 20V10M19 10L21 12M9 16H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        <span><?php _e('Entrega a Domicilio', 'cna-subscriptions'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="cna-address-text">
                                    <?php if ($shipping_address['type'] === 'home'): ?>
                                        <svg class="cna-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M12 13C13.6569 13 15 11.6569 15 10C15 8.34315 13.6569 7 12 7C10.3431 7 9 8.34315 9 10C9 11.6569 10.3431 13 12 13Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        <span><?php 
                                            echo esc_html(implode(', ', array_filter([
                                                $shipping_address['address'] ?? '',
                                                $shipping_address['district'] ?? '',
                                                $shipping_address['municipality'] ?? '',
                                                $shipping_address['department'] ?? '',
                                            ])));
                                        ?></span>
                                    <?php elseif ($store): ?>
                                        <div class="cna-store-info">
                                            <strong><?php echo esc_html($store->name); ?></strong>
                                            <span><?php echo esc_html($store->address); ?></span>
                                            <?php if ($store->phone): ?>
                                                <span class="cna-store-phone">
                                                    <svg class="cna-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M3 5C3 3.89543 3.89543 3 5 3H8.27924C8.70967 3 9.09181 3.27543 9.22792 3.68377L10.7257 8.17721C10.8831 8.64932 10.6694 9.16531 10.2243 9.38787L7.96701 10.5165C9.06925 12.9612 11.0388 14.9308 13.4835 16.033L14.6121 13.7757C14.8347 13.3306 15.3507 13.1169 15.8228 13.2743L20.3162 14.7721C20.7246 14.9082 21 15.2903 21 15.7208V19C21 20.1046 20.1046 21 19 21H18C9.71573 21 3 14.2843 3 6V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                    <?php echo esc_html($store->phone); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        

                       
                    </div>                    
                </div>

                 <!-- Fechas de Entrega -->
                 <h3 class="cna-section-heading dates"><?php _e('Fechas de Entrega', 'cna-subscriptions'); ?></h3>
                 <div class="cna-deliveries-section">                    
                    <div class="cna-deliveries-content">
                        <?php if (empty($deliveries)): ?>
                            <div class="cna-empty-deliveries">
                                <svg class="cna-icon-large" width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M8 2V6M16 2V6M3 10H21M5 4H19C20.1046 4 21 4.89543 21 6V20C21 21.1046 20.1046 22 19 22H5C3.89543 22 3 21.1046 3 20V6C3 4.89543 3.89543 4 5 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <p><?php _e('No hay entregas programadas.', 'cna-subscriptions'); ?></p>
                                <?php if ($subscription->status === 'active' || $subscription->status === 'pending'): ?>
                                    <button type="button" id="cna-generate-deliveries" class="cna-btn-primary-modern" 
                                            data-subscription-id="<?php echo esc_attr($subscription_id); ?>">
                                        <?php _e('Generar Fechas', 'cna-subscriptions'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="cna-deliveries-list-modern">
                                <?php foreach ($deliveries as $delivery): ?>
                                    <div class="cna-delivery-card">
                                        <div class="cna-delivery-date-modern">
                                            <span class="cna-date-day-modern"><?php echo date('d', strtotime($delivery->scheduled_date)); ?></span>
                                            <span class="cna-date-month-modern"><?php echo date_i18n('M', strtotime($delivery->scheduled_date)); ?></span>
                                        </div>
                                        <div class="cna-delivery-details">
                                            <div class="cna-delivery-date-text">Fecha de entrega: <?php echo esc_html($this->format_date($delivery->scheduled_date)); ?></div>
                                            <div class="cna-delivery-amount-text">Valor a colectar: $<?php echo number_format(floatval($delivery->amount_to_collect), 2); ?></div>
                                            <span class="cna-delivery-status-badge cna-delivery-status-<?php echo esc_attr($delivery->delivery_status); ?>">
                                                <?php echo esc_html($this->get_delivery_status_label($delivery->delivery_status)); ?>
                                            </span>
                                        </div>
                                        <?php 
                                        $options = $this->get_delivery_status_options($shipping_address['type'], $delivery->delivery_status);
                                        $options_count = count($options);
                                        
                                        if ($options_count === 0):
                                            // No hay opciones disponibles
                                            echo '<span class="cna-delivery-no-action">' . __('Sin acciones disponibles', 'cna-subscriptions') . '</span>';
                                        elseif ($shipping_address['type'] === 'home' && $options_count === 1):
                                            // Para entrega a domicilio con una sola opción: mostrar botón
                                            $option_value = array_key_first($options);
                                            $option_label = $options[$option_value];
                                            ?>
                                            <button type="button" 
                                                    class="cna-delivery-action-btn" 
                                                    data-delivery-id="<?php echo esc_attr($delivery->id); ?>"
                                                    data-status="<?php echo esc_attr($option_value); ?>">
                                                <?php echo esc_html($option_label); ?>
                                            </button>
                                        <?php else:
                                            // Para retiro en tienda o múltiples opciones: siempre mostrar dropdown
                                            ?>
                                            <select class="cna-delivery-action-modern" 
                                                    data-delivery-id="<?php echo esc_attr($delivery->id); ?>"
                                                    data-current-status="<?php echo esc_attr($delivery->delivery_status); ?>"
                                                    data-shipping-type="<?php echo esc_attr($shipping_address['type']); ?>">
                                                <option value=""><?php _e('Cambiar estado', 'cna-subscriptions'); ?></option>
                                                <?php 
                                                foreach ($options as $value => $label):
                                                ?>
                                                    <option value="<?php echo esc_attr($value); ?>">
                                                        <?php echo esc_html($label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php
        echo $this->render_invoice_print_document(
            $subscription,
            $subscription_id,
            $variant_details,
            $shipping_address,
            $billing_address,
            $user_first_name,
            $user_last_name,
            $user_phone,
            $qty,
            $unit_price,
            $product_subtotal,
            $advance_percent,
            $has_partial_advance,
            $advance_amount,
            $shipping_total,
            $annual_fee,
            $fee_amount,
            $total_with_fee
        );
        ?>

        <script>
        jQuery(document).ready(function($) {
            var deliveryConfirm = {
                title: '<?php echo esc_js(__('Cambiar estado de entrega', 'cna-subscriptions')); ?>',
                message: '<?php echo esc_js(__('¿Estás seguro de cambiar el estado de esta entrega?', 'cna-subscriptions')); ?>',
                confirmLabel: '<?php echo esc_js(__('Sí, cambiar', 'cna-subscriptions')); ?>'
            };

            var subscriptionActionConfirm = {
                activate: {
                    title: '<?php echo esc_js(__('Activar suscripción', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('La suscripción quedará activa y continuará según la programación. ¿Deseas continuar?', 'cna-subscriptions')); ?>',
                    variant: 'default',
                    confirmLabel: '<?php echo esc_js(__('Sí, activar', 'cna-subscriptions')); ?>'
                },
                pause: {
                    title: '<?php echo esc_js(__('Pausar suscripción', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('Se pausará la suscripción. No habrá cobros automáticos hasta reactivarla. ¿Deseas continuar?', 'cna-subscriptions')); ?>',
                    variant: 'default',
                    confirmLabel: '<?php echo esc_js(__('Sí, pausar', 'cna-subscriptions')); ?>'
                },
                cancel: {
                    title: '<?php echo esc_js(__('Cancelar suscripción', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('Se cancelará la suscripción. No se realizarán cobros ni entregas futuras. ¿Estás seguro?', 'cna-subscriptions')); ?>',
                    variant: 'danger',
                    confirmLabel: '<?php echo esc_js(__('Sí, cancelar', 'cna-subscriptions')); ?>'
                },
                renew: {
                    title: '<?php echo esc_js(__('Renovar suscripción', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('Se activará la suscripción y se actualizará la próxima fecha de renovación según la frecuencia. ¿Deseas continuar?', 'cna-subscriptions')); ?>',
                    variant: 'default',
                    confirmLabel: '<?php echo esc_js(__('Sí, renovar', 'cna-subscriptions')); ?>'
                },
                disable_auto_renew: {
                    title: '<?php echo esc_js(__('Desactivar auto-renovación', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('Se desactivará el cobro automático del próximo ciclo. Las entregas ya programadas de este período se mantienen. ¿Continuar?', 'cna-subscriptions')); ?>',
                    variant: 'default',
                    confirmLabel: '<?php echo esc_js(__('Sí, desactivar', 'cna-subscriptions')); ?>'
                },
                enable_auto_renew: {
                    title: '<?php echo esc_js(__('Activar auto-renovación', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('Se activará el cobro automático en la próxima fecha de renovación (si hay token de pago guardado). ¿Continuar?', 'cna-subscriptions')); ?>',
                    variant: 'default',
                    confirmLabel: '<?php echo esc_js(__('Sí, activar', 'cna-subscriptions')); ?>'
                }
            };

            function updateDeliveryStatus(deliveryId, newStatus, onError) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cna_update_delivery_status',
                        delivery_id: deliveryId,
                        status: newStatus,
                        nonce: '<?php echo wp_create_nonce('cna_update_delivery_status'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            CNAAdminModal.error(
                                response.data || '<?php echo esc_js(__('Error al actualizar el estado', 'cna-subscriptions')); ?>',
                                onError
                            );
                        }
                    },
                    error: function() {
                        CNAAdminModal.error(
                            (window.cnaAdminModalL10n && cnaAdminModalL10n.connectionError) || '<?php echo esc_js(__('Error de conexión', 'cna-subscriptions')); ?>',
                            onError
                        );
                    }
                });
            }

            $('.cna-delivery-action-modern').on('change', function() {
                var $select = $(this);
                var deliveryId = $select.data('delivery-id');
                var newStatus = $select.val();

                if (!newStatus) return;

                CNAAdminModal.confirm(Object.assign({}, deliveryConfirm, {
                    onConfirm: function() {
                        updateDeliveryStatus(deliveryId, newStatus, function() {
                            $select.val('');
                        });
                    },
                    onCancel: function() {
                        $select.val('');
                    }
                }));
            });

            $('.cna-delivery-action-btn').on('click', function() {
                var $btn = $(this);
                var deliveryId = $btn.data('delivery-id');
                var newStatus = $btn.data('status');

                if (!newStatus) return;

                CNAAdminModal.confirm(Object.assign({}, deliveryConfirm, {
                    onConfirm: function() {
                        $btn.prop('disabled', true).text('<?php echo esc_js(__('Procesando...', 'cna-subscriptions')); ?>');
                        updateDeliveryStatus(deliveryId, newStatus, function() {
                            $btn.prop('disabled', false).text($btn.data('original-text') || '<?php echo esc_js(__('Entregada en domicilio', 'cna-subscriptions')); ?>');
                        });
                    }
                }));
            });

            $('#cna-subscription-action').on('change', function() {
                var $select = $(this);
                var action = $select.val();

                if (!action) return;

                var copy = subscriptionActionConfirm[action] || {
                    title: '<?php echo esc_js(__('Confirmar acción', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('¿Estás seguro de realizar esta acción?', 'cna-subscriptions')); ?>',
                    variant: 'default',
                    confirmLabel: '<?php echo esc_js(__('Confirmar', 'cna-subscriptions')); ?>'
                };

                CNAAdminModal.confirm({
                    title: copy.title,
                    message: copy.message,
                    variant: copy.variant,
                    confirmLabel: copy.confirmLabel,
                    onConfirm: function() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'cna_update_subscription_status',
                                subscription_id: <?php echo intval($subscription_id); ?>,
                                action_type: action,
                                nonce: '<?php echo wp_create_nonce('cna_update_subscription_status'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    CNAAdminModal.success(
                                        response.data || '<?php echo esc_js(__('Acción realizada correctamente', 'cna-subscriptions')); ?>',
                                        function() { location.reload(); }
                                    );
                                } else {
                                    CNAAdminModal.error(
                                        response.data || '<?php echo esc_js(__('Error al realizar la acción', 'cna-subscriptions')); ?>',
                                        function() { $select.val(''); }
                                    );
                                }
                            },
                            error: function() {
                                CNAAdminModal.error(
                                    (window.cnaAdminModalL10n && cnaAdminModalL10n.connectionError) || '<?php echo esc_js(__('Error de conexión', 'cna-subscriptions')); ?>',
                                    function() { $select.val(''); }
                                );
                            }
                        });
                    },
                    onCancel: function() {
                        $select.val('');
                    }
                });
            });

            $('#cna-delete-subscription').on('click', function() {
                var subscriptionId = $(this).data('subscription-id');

                CNAAdminModal.confirm({
                    title: '<?php echo esc_js(__('Eliminar suscripción', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('¿Estás seguro de eliminar esta suscripción? Esta acción no se puede deshacer.', 'cna-subscriptions')); ?>',
                    variant: 'danger',
                    confirmLabel: '<?php echo esc_js(__('Sí, eliminar', 'cna-subscriptions')); ?>',
                    onConfirm: function() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'cna_delete_subscription',
                                subscription_id: subscriptionId,
                                nonce: '<?php echo wp_create_nonce('cna_delete_subscription'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    var listUrl = (response.data && response.data.redirect)
                                        ? response.data.redirect
                                        : '<?php echo esc_js($this->get_subscriptions_list_url()); ?>';
                                    CNAAdminModal.success(
                                        '<?php echo esc_js(__('Suscripción eliminada correctamente', 'cna-subscriptions')); ?>',
                                        function() {
                                            window.location.href = listUrl;
                                        }
                                    );
                                } else {
                                    CNAAdminModal.error(
                                        response.data || '<?php echo esc_js(__('Error al eliminar la suscripción', 'cna-subscriptions')); ?>'
                                    );
                                }
                            },
                            error: function() {
                                CNAAdminModal.error(
                                    (window.cnaAdminModalL10n && cnaAdminModalL10n.connectionError) || '<?php echo esc_js(__('Error de conexión', 'cna-subscriptions')); ?>'
                                );
                            }
                        });
                    }
                });
            });

            $('#cna-generate-deliveries').on('click', function() {
                var subscriptionId = $(this).data('subscription-id');
                var $button = $(this);

                CNAAdminModal.confirm({
                    title: '<?php echo esc_js(__('Generar fechas de entrega', 'cna-subscriptions')); ?>',
                    message: '<?php echo esc_js(__('¿Generar fechas de entrega para esta suscripción?', 'cna-subscriptions')); ?>',
                    confirmLabel: '<?php echo esc_js(__('Sí, generar', 'cna-subscriptions')); ?>',
                    onConfirm: function() {
                        $button.prop('disabled', true).text('<?php echo esc_js(__('Generando...', 'cna-subscriptions')); ?>');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'cna_generate_deliveries',
                                subscription_id: subscriptionId,
                                nonce: '<?php echo wp_create_nonce('cna_generate_deliveries'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    CNAAdminModal.success(
                                        '<?php echo esc_js(__('Fechas de entrega generadas correctamente', 'cna-subscriptions')); ?>',
                                        function() { location.reload(); }
                                    );
                                } else {
                                    CNAAdminModal.error(
                                        response.data || '<?php echo esc_js(__('Error al generar las fechas de entrega', 'cna-subscriptions')); ?>',
                                        function() {
                                            $button.prop('disabled', false).text('<?php echo esc_js(__('Generar Fechas de Entrega', 'cna-subscriptions')); ?>');
                                        }
                                    );
                                }
                            },
                            error: function() {
                                CNAAdminModal.error(
                                    (window.cnaAdminModalL10n && cnaAdminModalL10n.connectionError) || '<?php echo esc_js(__('Error de conexión', 'cna-subscriptions')); ?>',
                                    function() {
                                        $button.prop('disabled', false).text('<?php echo esc_js(__('Generar Fechas de Entrega', 'cna-subscriptions')); ?>');
                                    }
                                );
                            }
                        });
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Obtiene las opciones de estado para una entrega según el tipo de envío
     */
    private function get_delivery_status_options($shipping_type, $current_status) {
        $options = array();
        
        if ($shipping_type === 'home') {
            // Para entrega a domicilio: solo un cambio posible
            if ($current_status !== 'delivered_home') {
                $options['delivered_home'] = __('Entregada en domicilio', 'cna-subscriptions');
            }
        } else {
            // Para retiro en tienda: dos estados posibles
            if ($current_status === 'scheduled' || $current_status === 'pending') {
                // Cuando está programada/pendiente, mostrar ambas opciones:
                // 1. Despachada a tienda (la empresa envió a la tienda)
                $options['dispatched_to_store'] = __('Despachada a tienda', 'cna-subscriptions');
                // 2. Entregada a cliente en tienda (el cliente retiró - puede saltarse el paso intermedio)
                $options['delivered_to_customer'] = __('Entregada a cliente en tienda', 'cna-subscriptions');
            } elseif ($current_status === 'dispatched_to_store') {
                // Si ya está despachada a tienda, solo mostrar la opción de entregada al cliente
                $options['delivered_to_customer'] = __('Entregada a cliente en tienda', 'cna-subscriptions');
            }
        }
        
        return $options;
    }

    /**
     * Obtiene la etiqueta del estado de entrega
     */
    private function get_delivery_status_label($status) {
        $labels = array(
            'scheduled' => __('Programada', 'cna-subscriptions'),
            'pending' => __('Pendiente', 'cna-subscriptions'),
            'delivered_home' => __('Entregada en domicilio', 'cna-subscriptions'),
            'dispatched_to_store' => __('Despachada a tienda', 'cna-subscriptions'),
            'delivered_to_customer' => __('Entregada a cliente en tienda', 'cna-subscriptions'),
            'cancelled' => __('Cancelada', 'cna-subscriptions'),
        );
        return $labels[$status] ?? $status;
    }

    /**
     * Obtiene la etiqueta del estado de suscripción
     */
    private function get_status_label($status) {
        $labels = array(
            'active' => __('Activa', 'cna-subscriptions'),
            'pending' => __('Pendiente', 'cna-subscriptions'),
            'cancelled' => __('Cancelada', 'cna-subscriptions'),
            'paused' => __('Pausada', 'cna-subscriptions'),
            'payment_failed' => __('Pago Fallido', 'cna-subscriptions'),
        );
        return $labels[$status] ?? $status;
    }

    /**
     * Obtiene los estilos CSS para la página de detalles
     */
    private function get_details_page_styles() {
        return '
        <style id="cna-subscription-details-styles">
        /* ============================================
           VARIABLES CSS - Colores y Espaciado
           ============================================ */
        :root {
            --cna-primary: #4f46e5;
            --cna-primary-hover: #4338ca;
            --cna-danger: #ef4444;
            --cna-danger-hover: #dc2626;
            --cna-text-primary: #111827;
            --cna-text-secondary: #6b7280;
            --cna-text-muted: #9ca3af;
            --cna-border: #e5e7eb;
            --cna-border-light: #f3f4f6;
            --cna-bg: #ffffff;
            --cna-bg-secondary: #f9fafb;
            --cna-bg-page: #f5f7fa;
            --cna-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --cna-shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --cna-shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --cna-radius: 12px;
            --cna-radius-sm: 8px;
            --cna-spacing-xs: 8px;
            --cna-spacing-sm: 12px;
            --cna-spacing-md: 16px;
            --cna-spacing-lg: 24px;
            --cna-spacing-xl: 32px;
            --cna-font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        }

        /* ============================================
           RESET Y BASE
           ============================================ */
        .cna-subscription-details {
            font-family: var(--cna-font-family);
            max-width: 1400px;
            margin: 0 auto;
            padding: var(--cna-spacing-lg);
        }

        /* ============================================
           HEADER DE NAVEGACIÓN
           ============================================ */
        .cna-page-header {
            margin-bottom: var(--cna-spacing-lg);
        }
        .cna-back-link {
            display: inline-flex;
            align-items: center;
            gap: var(--cna-spacing-xs);
            color: var(--cna-text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .cna-back-link:hover {
            color: var(--cna-text-primary);
        }
        .cna-back-link .cna-icon {
            width: 18px;
            height: 18px;
            color: currentColor;
        }

        /* ============================================
           CONTENEDOR PRINCIPAL (CARD STYLE)
           ============================================ */
        .cna-main-card {
            background: var(--cna-bg);
            border-radius: var(--cna-radius);
            box-shadow: var(--cna-shadow-lg);
            overflow: hidden;
        }

        /* Encabezado de la Card */
        .cna-card-header {
            padding: var(--cna-spacing-xl);
            border-bottom: 1px solid var(--cna-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--cna-bg-secondary);
        }
        .cna-header-content {
            display: flex;
            align-items: center;
            gap: var(--cna-spacing-md);
        }
        .cna-subscription-title {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
            color: var(--cna-text-primary);
            display: flex;
            align-items: baseline;
            gap: var(--cna-spacing-sm);
        }
        .cna-subscription-id {
            font-size: 24px;
            font-weight: 500;
            color: var(--cna-text-secondary);
        }
        .cna-status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .cna-status-active { background: #d1fae5; color: #065f46; }
        .cna-status-pending { background: #fef3c7; color: #92400e; }
        .cna-status-cancelled { background: #fee2e2; color: #991b1b; }
        .cna-status-paused { background: #dbeafe; color: #1e40af; }
        .cna-status-payment_failed { background: #fee2e2; color: #991b1b; }

        .cna-btn-print {
            flex: 1;
            display: flex;
            align-items: center;
            gap: var(--cna-spacing-xs);
            padding: 10px 18px;
            background: var(--cna-primary);
            color: #ffffff;
            border: none;
            border-radius: var(--cna-radius-sm);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .cna-btn-print:hover {
            background: var(--cna-primary-hover);
            transform: translateY(-1px);
            box-shadow: var(--cna-shadow-md);
        }
        .cna-btn-print .cna-icon {
            width: 18px;
            height: 18px;
            color: currentColor;
        }

        /* ============================================
           LAYOUT PRINCIPAL (60/40)
           ============================================ */
        .cna-card-content {
            display: grid;
            grid-template-columns: 60% 40%;
            gap: var(--cna-spacing-xl);
            padding: var(--cna-spacing-xl);
        }

        /* ============================================
           COLUMNA IZQUIERDA (60%)
           ============================================ */
        .cna-left-column {
            display: flex;
            flex-direction: column;
            gap: var(--cna-spacing-lg);
        }

        /* Información de la Empresa */
        .cna-company-section {
            padding-bottom: var(--cna-spacing-lg);
            border-bottom: 2px solid var(--cna-border);
        }
        .cna-company-name {
            margin: 0 0 var(--cna-spacing-xs) 0;
            font-size: 28px;
            font-weight: 700;
            color: var(--cna-text-primary);
        }
        .cna-company-description {
            margin: 0;
            font-size: 15px;
            color: var(--cna-text-secondary);
            line-height: 1.6;
        }

        /* Producto e Imagen */
        .cna-product-section {
            display: flex;
            gap: var(--cna-spacing-lg);
            align-items: flex-start;
        }
        .cna-product-image-container {
            flex-shrink: 0;
        }
        .cna-product-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: var(--cna-radius-sm);
            border: 2px solid var(--cna-border);
        }
        .cna-product-info {
            flex: 1;
        }
        .cna-product-title {
            margin: 0 0 var(--cna-spacing-md) 0;
            font-size: 24px;
            font-weight: 600;
            color: var(--cna-text-primary);
        }
        .cna-product-tags {
            display: flex;
            gap: var(--cna-spacing-sm);
            flex-wrap: wrap;
        }
        .cna-tag {
            padding: 6px 12px;
            background: #e0e7ff;
            color: #4338ca;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 500;
        }

        /* Desglose: información vs cobro */
        .cna-pricing-section {
            margin-top: var(--cna-spacing-lg);
            display: flex;
            flex-direction: column;
            gap: var(--cna-spacing-lg);
        }
        .cna-detail-block {
            border: 1px solid var(--cna-border-light);
            border-radius: 8px;
            padding: var(--cna-spacing-lg);
            background: #fff;
        }
        .cna-detail-block--info {
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        .cna-detail-block--charge {
            border-color: #bfdbfe;
            background: #f8fbff;
        }
        .cna-detail-block__title {
            margin: 0 0 0.35rem;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--cna-text-primary);
        }
        .cna-detail-block__hint {
            margin: 0 0 1rem;
            font-size: 13px;
            color: var(--cna-text-secondary);
        }
        .cna-detail-block__muted {
            display: block;
            margin-top: 0.25rem;
            font-size: 12px;
            font-weight: 400;
            color: var(--cna-text-secondary);
        }
        .cna-info-dl {
            display: grid;
            grid-template-columns: minmax(140px, 38%) 1fr;
            gap: 0.65rem 1rem;
            margin: 0;
        }
        .cna-info-dl dt {
            margin: 0;
            font-weight: 600;
            color: var(--cna-text-secondary);
            font-size: 14px;
        }
        .cna-info-dl dd {
            margin: 0;
            color: var(--cna-text-primary);
            font-size: 15px;
        }
        .cna-charge-table {
            width: 100%;
            border-collapse: collapse;
        }
        .cna-charge-table th,
        .cna-charge-table td {
            padding: 0.55rem 0;
            border-bottom: 1px solid var(--cna-border-light);
            font-size: 15px;
        }
        .cna-charge-table th {
            text-align: left;
            font-weight: 500;
            color: var(--cna-text-secondary);
            width: 70%;
        }
        .cna-charge-table td {
            text-align: right;
            font-weight: 600;
            color: var(--cna-text-primary);
            white-space: nowrap;
        }
        .cna-charge-table__subtotal th,
        .cna-charge-table__subtotal td {
            border-top: 1px dashed var(--cna-border);
            padding-top: 0.75rem;
        }
        .cna-charge-table__total th,
        .cna-charge-table__total td {
            border-bottom: none;
            font-size: 1.05rem;
            color: #1d4ed8;
            padding-top: 0.5rem;
        }
        .cna-pricing-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: var(--cna-spacing-lg);
        }
        .cna-pricing-table thead {
            border-bottom: 2px solid var(--cna-border);
        }
        .cna-pricing-table th {
            padding: var(--cna-spacing-md) 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--cna-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .cna-pricing-table tbody tr {
            border-bottom: 1px solid var(--cna-border-light);
        }
        .cna-pricing-table tbody tr:last-child {
            border-bottom: none;
        }
        .cna-pricing-table td {
            padding: var(--cna-spacing-md) 0;
            font-size: 15px;
            color: var(--cna-text-primary);
        }
        .cna-text-left { text-align: left; }
        .cna-text-center { text-align: center; }
        .cna-text-right { text-align: right; }

        /* Totales */
        .cna-totals-section {
            margin-top: var(--cna-spacing-lg);
            padding-top: var(--cna-spacing-lg);
            margin-left: 55%;
        }
        .cna-total-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--cna-spacing-sm) 0;
            font-size: 15px;
        }
        .cna-total-line--info .cna-total-label,
        .cna-total-line--info .cna-total-value {
            color: var(--cna-text-secondary);
        }
        .cna-total-line--charge .cna-total-value {
            font-weight: 600;
            color: var(--cna-text-primary);
        }
        .cna-total-line--note {
            padding-top: 0;
            font-size: 13px;
        }
        .cna-total-line--note .cna-total-label,
        .cna-total-line--note .cna-total-value {
            color: var(--cna-text-secondary);
            font-weight: 400;
        }
        .cna-total-line--subtotal {
            border-top: 1px dashed var(--cna-border);
            margin-top: var(--cna-spacing-xs);
            padding-top: var(--cna-spacing-sm);
        }
        .cna-total-line--subtotal .cna-total-label,
        .cna-total-line--subtotal .cna-total-value {
            font-weight: 600;
        }
        .cna-total-note-detail {
            font-size: 12px;
            color: var(--cna-text-secondary);
        }
        .cna-pricing-table-note {
            margin-top: 4px;
            font-size: 12px;
            color: var(--cna-text-secondary);
            font-style: italic;
        }
        .cna-total-label {
            color: var(--cna-text-secondary);
            font-weight: 500;
        }
        .cna-total-value {
            color: var(--cna-text-primary);
            font-weight: 500;
        }
        .cna-total-divider {
            height: 1px;
            background: var(--cna-border);
            margin: var(--cna-spacing-md) 0;
        }
        .cna-total-final {
            padding: var(--cna-spacing-md) 0;
        }
        .cna-total-final .cna-total-label {
            font-size: 18px;
            font-weight: 600;
            color: var(--cna-text-primary);
        }
        .cna-total-amount {
            font-size: 24px;
            font-weight: 700;
            color: var(--wp-admin-theme-color);
        }

        /* ============================================
           COLUMNA DERECHA (40%)
           ============================================ */
        .cna-right-column {
            display: flex;
            flex-direction: column;
            gap: var(--cna-spacing-xl);
            margin-right: var(--cna-spacing-xl);
        }

        /* Secciones */
        .cna-section-heading {
            margin: 0 0 var(--cna-spacing-md) 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--cna-text-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
            .cna-section-heading.dates {
                margin-left: var(--cna-spacing-lg);
            }

        /* Datos del Cliente */
        .cna-customer-section {            
            padding: 0 var(--cna-spacing-md);
            padding-bottom: var(--cna-spacing-lg);
            border-bottom: 1px solid var(--cna-border);
        }
        .cna-customer-info {
            display: flex;
            flex-direction: column;
            gap: var(--cna-spacing-md);
        }
        .cna-info-item {
            display: flex;
            align-items: center;
            gap: var(--cna-spacing-sm);
            color: var(--cna-text-primary);
        }
        .cna-info-item .cna-icon {
            width: 18px;
            height: 18px;
            color: var(--cna-text-secondary);
            flex-shrink: 0;
        }
        .cna-info-text {
            font-size: 15px;
            line-height: 1.5;
            color: var(--cna-text-primary);
        }

        /* Dirección de Entrega */
        .cna-shipping-section {
            padding: 0 var(--cna-spacing-md);
            padding-bottom: var(--cna-spacing-lg);
            border-bottom: 1px solid var(--cna-border);
        }
        .cna-shipping-info {
            display: flex;
            flex-direction: column;
            gap: var(--cna-spacing-md);
        }
        .cna-shipping-type-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--cna-spacing-xs);
            padding: 8px 14px;
            background: var(--cna-bg-secondary);
            border-radius: var(--cna-radius-sm);
            font-size: 14px;
            font-weight: 500;
            color: var(--cna-text-primary);
        }
        .cna-shipping-type-badge .cna-icon {
            width: 18px;
            height: 18px;
            color: var(--cna-primary);
        }
        .cna-address-text {
            display: flex;
            align-items: flex-start;
            gap: var(--cna-spacing-sm);
            color: var(--cna-text-secondary);
            font-size: 14px;
            line-height: 1.7;
        }
        .cna-address-text .cna-icon {
            width: 18px;
            height: 18px;
            color: var(--cna-text-secondary);
            margin-top: 2px;
            flex-shrink: 0;
        }
        .cna-store-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .cna-store-info strong {
            color: var(--cna-text-primary);
            font-weight: 600;
        }
        .cna-store-phone {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            color: var(--cna-text-secondary);
        }
        .cna-store-phone .cna-icon {
            width: 16px;
            height: 16px;
        }

        /* Acciones */
        .cna-actions-section {
            padding: 0 var(--cna-spacing-md);
            padding-bottom: var(--cna-spacing-lg);
            border-bottom: 1px solid var(--cna-border);
            border-top: 2px solid var(--cna-border);
            padding-top: var(--cna-spacing-lg);
            margin-top: 51px;
        }
        .cna-actions-content {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: var(--cna-spacing-md);
            align-items: flex-end;
        }
        .cna-actions-auto-renew-status {
            flex: 0 0 100%;
            margin: 0 0 var(--cna-spacing-xs);
            font-size: 14px;
            color: var(--cna-text-secondary);
        }
        .cna-actions-auto-renew-status strong {
            color: var(--cna-text-primary);
        }
        .cna-actions-auto-renew-date {
            font-size: 13px;
        }
        .cna-select-label {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
            color: var(--cna-text-primary);
            margin-bottom: var(--cna-spacing-xs);
        }
        .cna-select-modern {
            flex: 1.5;
            padding: 12px 16px;
            border: 1px solid var(--cna-border);
            border-radius: var(--cna-radius-sm);
            font-size: 14px;
            font-family: var(--cna-font-family);
            background: var(--cna-bg);
            color: var(--cna-text-primary);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .cna-select-modern:hover {
            border-color: var(--cna-text-muted);
        }
        .cna-select-modern:focus {
            outline: none;
            border-color: var(--cna-primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .cna-btn-delete-outline {
            flex: 1.5;
            padding: 10px 16px;
            background: transparent;
            color: var(--cna-danger);
            border: 1px solid var(--cna-danger);
            border-radius: var(--cna-radius-sm);
            font-size: 14px;
            font-weight: 500;
            font-family: var(--cna-font-family);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .cna-btn-delete-outline:hover {
            background: var(--cna-danger);
            color: #ffffff;
        }

        /* Detalle de transacción de pago */
        .cna-payment-transaction-section {
            margin: 0;
            padding: 0;
            max-width: none;
            border: 1px solid #99f6e4;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .cna-payment-transaction-notice {
            margin: 0 1rem 0.75rem;
            padding: 0.65rem 0.75rem;
            font-size: 13px;
            color: #0f766e;
            background: #f0fdfa;
            border-radius: 4px;
            border-left: 3px solid #14b8a6;
        }
        .cna-payment-transaction-header {
            background: #0d9488;
            color: #fff;
            text-align: center;
            font-weight: 600;
            padding: 0.65rem 1rem;
            border-radius: 4px 4px 0 0;
            font-size: 1rem;
        }
        .cna-payment-transaction-title {
            color: #0d9488;
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
            padding: 1rem 1rem 0.75rem;
            border-bottom: 2px solid #0d9488;
        }
        .cna-payment-transaction-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
            padding: 0 1rem 1rem;
        }
        .cna-payment-transaction-table tbody {
            display: table;
            width: calc(100% - 2rem);
            margin: 0 1rem 1rem;
        }
        .cna-payment-transaction-table th {
            text-align: left;
            font-weight: 600;
            color: #334155;
            padding: 0.5rem 1rem 0.5rem 0;
            vertical-align: top;
            width: 48%;
        }
        .cna-payment-transaction-table td {
            color: #0f172a;
            padding: 0.5rem 0;
            word-break: break-word;
        }

        /* Fechas de Entrega */
        .cna-deliveries-section {
            padding: 0 var(--cna-spacing-lg);
            padding-top: var(--cna-spacing-xl);
            margin: var(--cna-spacing-lg);
            background: var(--cna-bg-secondary);
        }
        .cna-btn-group {
            display: flex;
            flex-direction: row;
            gap: var(--cna-spacing-md);
        }
        .cna-deliveries-content {
            /* Contenedor para el contenido */
        }
        .cna-empty-deliveries {
            text-align: center;
            padding: var(--cna-spacing-xl);
        }
        .cna-empty-deliveries .cna-icon-large {
            width: 48px;
            height: 48px;
            color: var(--cna-text-muted);
            margin-bottom: var(--cna-spacing-md);
        }
        .cna-empty-deliveries p {
            margin: 0 0 var(--cna-spacing-lg) 0;
            color: var(--cna-text-secondary);
            font-size: 14px;
        }
        .cna-btn-primary-modern {
            padding: 10px 20px;
            background: var(--cna-primary);
            color: #ffffff;
            border: none;
            border-radius: var(--cna-radius-sm);
            font-size: 14px;
            font-weight: 500;
            font-family: var(--cna-font-family);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .cna-btn-primary-modern:hover {
            background: var(--cna-primary-hover);
            transform: translateY(-1px);
            box-shadow: var(--cna-shadow-md);
        }
        .cna-deliveries-list-modern {
            display: flex;
            flex-direction: column;
            gap: var(--cna-spacing-md);
        }
            .cna-deliveries-content:last-child {
                border-bottom: none;
            }                        
        .cna-delivery-card {
            display: flex;
            align-items: center;
            gap: var(--cna-spacing-md);
            padding: var(--cna-spacing-md);
            border-bottom: 1px solid var(--cna-border);
            transition: all 0.2s ease;
        }
        .cna-delivery-card:hover {
            background: #f3f4f6;
            border-color: var(--cna-text-muted);
        }
        .cna-delivery-date-modern {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background: var(--cna-bg);
            border: 2px solid var(--cna-border);
            border-radius: var(--cna-radius-sm);
            padding: 8px;
            flex-shrink: 0;
        }
        .cna-date-day-modern {
            font-size: 20px;
            font-weight: 700;
            color: var(--cna-text-primary);
            line-height: 1;
        }
        .cna-date-month-modern {
            font-size: 11px;
            font-weight: 500;
            color: var(--cna-text-secondary);
            text-transform: uppercase;
            line-height: 1;
            margin-top: 4px;
        }
        .cna-delivery-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .cna-delivery-date-text {
            font-size: 15px;
            font-weight: 500;
            color: var(--cna-text-primary);
        }
        .cna-delivery-amount-text {
            font-size: 13px;
            color: var(--cna-text-secondary);
        }
        .cna-delivery-status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
            width: fit-content;
        }
        .cna-delivery-action-modern {
            padding: 8px 12px;
            border: 1px solid var(--cna-border);
            border-radius: var(--cna-radius-sm);
            font-size: 13px;
            font-family: var(--cna-font-family);
            background: var(--cna-bg);
            color: var(--cna-text-primary);
            cursor: pointer;
            min-width: 140px;
            transition: border-color 0.2s ease;
        }
        .cna-delivery-action-modern:hover {
            border-color: var(--cna-text-muted);
        }
        .cna-delivery-action-modern:focus {
            outline: none;
            border-color: var(--cna-primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* Estados de Entrega */
        .cna-delivery-status-scheduled { background: #fef3c7; color: #92400e; }
        .cna-delivery-status-pending { background: #dbeafe; color: #1e40af; }
        .cna-delivery-status-delivered_home { background: #d1fae5; color: #065f46; }
        .cna-delivery-status-dispatched_to_store { background: #fed7aa; color: #c2410c; }
        .cna-delivery-status-delivered_to_customer { background: #d1fae5; color: #065f46; }
        
        /* Botón de acción única (outlined) */
        .cna-delivery-action-btn {
            padding: 8px 16px;
            border: 2px solid var(--cna-primary);
            border-radius: var(--cna-radius-sm);
            font-size: 13px;
            font-family: var(--cna-font-family);
            font-weight: 500;
            background: transparent;
            color: var(--cna-primary);
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 140px;
        }
        .cna-delivery-action-btn:hover {
            background: var(--cna-primary);
            color: white;
            border-color: var(--cna-primary);
            box-shadow: 0 2px 4px rgba(79, 70, 229, 0.2);
        }
        .cna-delivery-action-btn:active {
            transform: translateY(1px);
            box-shadow: 0 1px 2px rgba(79, 70, 229, 0.2);
        }
        .cna-delivery-action-btn:disabled {
            background: transparent;
            border-color: var(--cna-text-muted);
            color: var(--cna-text-muted);
            cursor: not-allowed;
            box-shadow: none;
        }
        
        /* Mensaje cuando no hay acciones */
        .cna-delivery-no-action {
            padding: 8px 12px;
            font-size: 13px;
            color: var(--cna-text-muted);
            font-style: italic;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 1200px) {
            .cna-card-content {
                grid-template-columns: 1fr;
            }
            .cna-product-section {
                flex-direction: column;
            }
            .cna-product-image {
                width: 100%;
                max-width: 300px;
            }
        }

        @media (max-width: 768px) {
            .cna-subscription-details {
                padding: var(--cna-spacing-md);
            }
            .cna-card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--cna-spacing-md);
            }
            .cna-subscription-title {
                font-size: 24px;
            }
            .cna-subscription-id {
                font-size: 20px;
            }
            .cna-card-content {
                padding: var(--cna-spacing-md);
            }
        }

        /* ============================================
           INVOICE PRINT (hidden on screen)
           ============================================ */
        .cna-invoice-print {
            display: none;
        }

        /* ============================================
           PRINT STYLES — solo documento de factura
           ============================================ */
        @media print {
            @page {
                size: A4;
                margin: 14mm 12mm;
            }

            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            #wpadminbar,
            #adminmenumain,
            #adminmenuback,
            #wpfooter,
            #screen-meta,
            #screen-meta-links,
            .notice,
            .update-nag,
            .cna-page-header,
            #cna-subscription-detail-card,
            .cna-subscription-details > .cna-main-card {
                display: none !important;
            }

            #wpcontent,
            #wpbody,
            #wpbody-content,
            .wrap {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .cna-subscription-details {
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            #cna-invoice-print-document,
            .cna-invoice-print {
                display: block !important;
                visibility: visible !important;
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                background: #fff !important;
                color: #111 !important;
                font-family: Georgia, "Times New Roman", Times, serif, sans-serif;
                font-size: 11pt;
                line-height: 1.45;
            }

            .cna-invoice-print__brand {
                border-bottom: 2px solid #1b4332;
                padding-bottom: 10px;
                margin-bottom: 16px;
            }

            .cna-invoice-print__title {
                margin: 0 0 4px;
                font-size: 20pt;
                color: #1b4332;
            }

            .cna-invoice-print__doc-type {
                margin: 0;
                font-size: 11pt;
                color: #444;
            }

            .cna-invoice-print__meta {
                display: flex;
                justify-content: space-between;
                gap: 24px;
                margin-bottom: 18px;
            }

            .cna-invoice-print__meta-col {
                flex: 1;
            }

            .cna-invoice-print__meta-col--right {
                text-align: right;
            }

            .cna-invoice-print__meta p {
                margin: 0 0 6px;
            }

            .cna-invoice-print__section {
                margin-bottom: 18px;
                page-break-inside: avoid;
            }

            .cna-invoice-print__section-title {
                margin: 0 0 8px;
                font-size: 12pt;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: #1b4332;
                border-bottom: 1px solid #ccc;
                padding-bottom: 4px;
            }

            .cna-invoice-print__subsection-title {
                margin: 12px 0 4px;
                font-size: 10pt;
                color: #333;
            }

            .cna-invoice-print__line {
                margin: 0 0 3px;
            }

            .cna-invoice-print__table,
            .cna-invoice-print__totals-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 6px;
            }

            .cna-invoice-print__table th,
            .cna-invoice-print__table td,
            .cna-invoice-print__totals-table th,
            .cna-invoice-print__totals-table td {
                border: 1px solid #ccc;
                padding: 7px 8px;
                text-align: left;
                vertical-align: top;
            }

            .cna-invoice-print__table thead th {
                background: #f3f6f4;
                font-weight: 700;
            }

            .cna-invoice-print__num {
                text-align: right;
                white-space: nowrap;
            }

            .cna-invoice-print__muted {
                color: #666;
                font-size: 9pt;
            }

            .cna-invoice-print__totals-table th {
                width: 70%;
            }

            .cna-invoice-print__totals-table td {
                text-align: right;
                white-space: nowrap;
            }

            .cna-invoice-print__grand-total th,
            .cna-invoice-print__grand-total td {
                font-size: 12pt;
                border-top: 2px solid #1b4332;
                background: #f3f6f4;
            }

            .cna-invoice-print__footer {
                margin-top: 24px;
                padding-top: 8px;
                border-top: 1px solid #ddd;
                font-size: 9pt;
                color: #666;
                text-align: center;
            }
        }
        </style>
        ';
    }

    /**
     * Handler AJAX para actualizar estado de entrega
     */
    public function ajax_update_delivery_status() {
        check_ajax_referer('cna_update_delivery_status', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos', 'cna-subscriptions'));
        }

        $delivery_id = intval($_POST['delivery_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        if (!$delivery_id || !$status) {
            wp_send_json_error(__('Datos inválidos', 'cna-subscriptions'));
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Validar estado (incluyendo los nuevos estados para retiro en tienda)
        $valid_statuses = array(
            'scheduled', 
            'pending', 
            'delivered_home', 
            'dispatched_to_store',      // Despachada a tienda
            'delivered_to_customer',    // Entregada a cliente en tienda
            'cancelled'
        );
        if (!in_array($status, $valid_statuses, true)) {
            wp_send_json_error(__('Estado inválido', 'cna-subscriptions'));
        }

        // Obtener entrega
        $delivery = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_deliveries WHERE id = %d",
            $delivery_id
        ));

        if (!$delivery) {
            wp_send_json_error(__('Entrega no encontrada', 'cna-subscriptions'));
        }

        // Actualizar estado
        $updated = $wpdb->update(
            $table_prefix . 'cna_deliveries',
            array('delivery_status' => $status),
            array('id' => $delivery_id),
            array('%s'),
            array('%d')
        );

        if ($updated === false) {
            wp_send_json_error(__('Error al actualizar', 'cna-subscriptions'));
        }

        // Registrar en audit log
        if (class_exists('CNA_Audit_Logger')) {
            CNA_Audit_Logger::log(
                'delivery_status_changed',
                sprintf(__('Estado de entrega #%d cambiado a %s', 'cna-subscriptions'), $delivery_id, $status),
                get_current_user_id(),
                null,
                $delivery->subscription_id
            );
        }

        wp_send_json_success(__('Estado actualizado correctamente', 'cna-subscriptions'));
    }

    /**
     * Handler AJAX para actualizar estado de suscripción
     */
    public function ajax_update_subscription_status() {
        check_ajax_referer('cna_update_subscription_status', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos', 'cna-subscriptions'));
        }

        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $action = sanitize_text_field($_POST['action_type'] ?? '');

        if (!$subscription_id || !$action) {
            wp_send_json_error(__('Datos inválidos', 'cna-subscriptions'));
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
            $subscription_id
        ));

        if (!$subscription) {
            wp_send_json_error(__('Suscripción no encontrada', 'cna-subscriptions'));
        }

        $new_status = $subscription->status;
        $action_message = '';

        switch ($action) {
            case 'disable_auto_renew':
                if (empty($subscription->is_auto_renew)) {
                    wp_send_json_error(__('La renovación automática ya está desactivada', 'cna-subscriptions'));
                }

                $updated = $wpdb->update(
                    $table_prefix . 'cna_subscriptions',
                    array('is_auto_renew' => 0),
                    array('id' => $subscription_id),
                    array('%d'),
                    array('%d')
                );

                if ($updated === false) {
                    wp_send_json_error(__('Error al actualizar', 'cna-subscriptions'));
                }

                if (class_exists('CNA_Audit_Logger')) {
                    CNA_Audit_Logger::log(
                        'subscription_updated',
                        array(
                            'subscription_id' => $subscription_id,
                            'action' => 'auto_renew_disabled',
                            'by' => 'admin',
                            'admin_user_id' => get_current_user_id(),
                        ),
                        CNA_Audit_Logger::SEVERITY_MEDIUM
                    );
                }

                wp_send_json_success(__('Renovación automática desactivada. Las entregas actuales no se modifican.', 'cna-subscriptions'));
                return;

            case 'enable_auto_renew':
                if (!empty($subscription->is_auto_renew)) {
                    wp_send_json_error(__('La renovación automática ya está activa', 'cna-subscriptions'));
                }

                if ($subscription->status !== 'active') {
                    wp_send_json_error(__('Solo se puede activar la auto-renovación en suscripciones activas', 'cna-subscriptions'));
                }

                if (empty($subscription->pagadito_token)) {
                    wp_send_json_error(__('No hay token de pago guardado. El cliente debe completar un pago en Pagadito primero.', 'cna-subscriptions'));
                }

                $updated = $wpdb->update(
                    $table_prefix . 'cna_subscriptions',
                    array('is_auto_renew' => 1),
                    array('id' => $subscription_id),
                    array('%d'),
                    array('%d')
                );

                if ($updated === false) {
                    wp_send_json_error(__('Error al actualizar', 'cna-subscriptions'));
                }

                if (class_exists('CNA_Audit_Logger')) {
                    CNA_Audit_Logger::log(
                        'subscription_updated',
                        array(
                            'subscription_id' => $subscription_id,
                            'action' => 'auto_renew_enabled',
                            'by' => 'admin',
                            'admin_user_id' => get_current_user_id(),
                        ),
                        CNA_Audit_Logger::SEVERITY_MEDIUM
                    );
                }

                wp_send_json_success(__('Renovación automática activada', 'cna-subscriptions'));
                return;

            case 'activate':
                $new_status = 'active';
                $action_message = __('Suscripción activada', 'cna-subscriptions');
                break;
            case 'pause':
                $new_status = 'paused';
                $action_message = __('Suscripción pausada', 'cna-subscriptions');
                break;
            case 'cancel':
                $new_status = 'cancelled';
                $action_message = __('Suscripción cancelada', 'cna-subscriptions');
                break;
            case 'renew':
                // Para renovación, activar y actualizar fecha de renovación
                $new_status = 'active';
                $variant_details = json_decode($subscription->variant_details, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $variant_details = json_decode($subscription->variant_details, true);
                }
                
                // Calcular próxima renovación basada en frecuencia
                $frequency = intval($variant_details['frequency'] ?? 4);
                $next_renewal = date('Y-m-d H:i:s', strtotime("+{$frequency} weeks"));
                
                $wpdb->update(
                    $table_prefix . 'cna_subscriptions',
                    array(
                        'status' => $new_status,
                        'next_renewal_date' => $next_renewal,
                    ),
                    array('id' => $subscription_id),
                    array('%s', '%s'),
                    array('%d')
                );
                $action_message = __('Suscripción renovada', 'cna-subscriptions');
                
                // Enviar email de notificación
                if (class_exists('CNA_Mailer')) {
                    CNA_Mailer::send_subscription_status_changed($subscription_id, $new_status, $action_message);
                }
                
                wp_send_json_success($action_message);
                return;
        }

        if ($new_status === $subscription->status) {
            wp_send_json_error(__('El estado no ha cambiado', 'cna-subscriptions'));
        }

        // Actualizar estado
        $updated = $wpdb->update(
            $table_prefix . 'cna_subscriptions',
            array('status' => $new_status),
            array('id' => $subscription_id),
            array('%s'),
            array('%d')
        );

        if ($updated === false) {
            wp_send_json_error(__('Error al actualizar', 'cna-subscriptions'));
        }

        if ($new_status === 'active') {
            $existing_deliveries = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_prefix}cna_deliveries WHERE subscription_id = %d",
                $subscription_id
            ));
            if ($existing_deliveries === 0 && class_exists('CNA_Scheduler')) {
                $created = CNA_Scheduler::provision_subscription_deliveries($subscription_id);
                if ($created > 0) {
                    $action_message .= ' ' . sprintf(
                        __('(%d entregas programadas)', 'cna-subscriptions'),
                        $created
                    );
                }
            }
        }

        // Registrar en audit log
        if (class_exists('CNA_Audit_Logger')) {
            CNA_Audit_Logger::log(
                'subscription_status_changed',
                sprintf(__('Estado de suscripción #%d cambiado de %s a %s', 'cna-subscriptions'), 
                    $subscription_id, $subscription->status, $new_status),
                get_current_user_id(),
                null,
                $subscription_id
            );
        }

        // Enviar email de notificación
        if (class_exists('CNA_Mailer')) {
            CNA_Mailer::send_subscription_status_changed($subscription_id, $new_status, $action_message);
        }

        wp_send_json_success($action_message);
    }

    /**
     * Handler AJAX para eliminar suscripción
     */
    public function ajax_delete_subscription() {
        check_ajax_referer('cna_delete_subscription', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos', 'cna-subscriptions'));
        }

        $subscription_id = intval($_POST['subscription_id'] ?? 0);

        if (!$subscription_id) {
            wp_send_json_error(__('ID de suscripción inválido', 'cna-subscriptions'));
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
            $subscription_id
        ));

        if (!$subscription) {
            wp_send_json_error(__('Suscripción no encontrada', 'cna-subscriptions'));
        }

        // Eliminar entregas asociadas
        $wpdb->delete(
            $table_prefix . 'cna_deliveries',
            array('subscription_id' => $subscription_id),
            array('%d')
        );

        // Eliminar suscripción
        $deleted = $wpdb->delete(
            $table_prefix . 'cna_subscriptions',
            array('id' => $subscription_id),
            array('%d')
        );

        if ($deleted === false) {
            wp_send_json_error(__('Error al eliminar la suscripción', 'cna-subscriptions'));
        }

        // Registrar en audit log
        if (class_exists('CNA_Audit_Logger')) {
            CNA_Audit_Logger::log(
                'subscription_deleted',
                sprintf(__('Suscripción #%d eliminada', 'cna-subscriptions'), $subscription_id),
                get_current_user_id(),
                null,
                $subscription_id
            );
        }

        wp_send_json_success(array(
            'message' => __('Suscripción eliminada correctamente', 'cna-subscriptions'),
            'redirect' => $this->get_subscriptions_list_url(),
        ));
    }

    /**
     * Handler AJAX para generar fechas de entrega
     */
    public function ajax_generate_deliveries() {
        check_ajax_referer('cna_generate_deliveries', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos', 'cna-subscriptions'));
        }

        $subscription_id = intval($_POST['subscription_id'] ?? 0);

        if (!$subscription_id) {
            wp_send_json_error(__('ID de suscripción inválido', 'cna-subscriptions'));
        }

        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener suscripción
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}cna_subscriptions WHERE id = %d",
            $subscription_id
        ));

        if (!$subscription) {
            wp_send_json_error(__('Suscripción no encontrada', 'cna-subscriptions'));
        }

        // Verificar si ya hay entregas
        $existing_deliveries = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_prefix}cna_deliveries WHERE subscription_id = %d",
            $subscription_id
        ));

        if ($existing_deliveries > 0) {
            wp_send_json_error(__('Ya existen entregas para esta suscripción', 'cna-subscriptions'));
        }

        // Decodificar variant_details
        $variant_details = json_decode($subscription->variant_details, true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $variant_details = json_decode($subscription->variant_details, true);
        }

        // Obtener configuración de días del producto
        $delivery_day = intval(get_post_meta($subscription->product_id, '_cna_delivery_day', true));
        $order_cutoff = intval(get_post_meta($subscription->product_id, '_cna_order_cutoff', true));

        // Valores por defecto
        if (empty($delivery_day) && $delivery_day !== '0') {
            $delivery_day = 4; // Jueves
        }
        if (empty($order_cutoff) && $order_cutoff !== '0') {
            $order_cutoff = 2; // Miércoles
        }

        // Calcular fechas de entrega
        $delivery_dates = CNA_Scheduler::calculate_delivery_dates(
            'now',
            intval($variant_details['qty'] ?? 1),
            intval($variant_details['frequency'] ?? 4),
            $delivery_day,
            $order_cutoff
        );

        if (empty($delivery_dates)) {
            wp_send_json_error(__('No se pudieron calcular las fechas de entrega', 'cna-subscriptions'));
        }

        // Obtener precio unitario
        $unit_price = CNA_Product_Helper::get_variation_price($subscription->product_id, strtolower($variant_details['size'] ?? ''));
        if ($unit_price === false) {
            $unit_price = 0;
        }

        // Calcular monto a cobrar por entrega
        // Si pagó 50% de anticipo, cada entrega debe cobrar el 50% restante del precio unitario
        // Si pagó 100%, no hay monto a cobrar (amount_to_collect = 0)
        $advance_percent = floatval($variant_details['advance_percent'] ?? 0);
        $amount_per_delivery = 0;
        if ($advance_percent < 100) {
            $remaining_percent = (100 - $advance_percent) / 100;
            // El monto a cobrar por entrega es el porcentaje restante del precio unitario
            // Cada entrega corresponde a una canasta, por lo que no se divide por qty
            $amount_per_delivery = $unit_price * $remaining_percent;
        }

        // Crear registros de entregas
        $created = 0;
        foreach ($delivery_dates as $date) {
            $inserted = $wpdb->insert(
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
            if ($inserted !== false) {
                $created++;
            }
        }

        // Registrar en audit log
        if (class_exists('CNA_Audit_Logger')) {
            CNA_Audit_Logger::log(
                'deliveries_generated',
                sprintf(__('Fechas de entrega generadas para suscripción #%d (%d entregas)', 'cna-subscriptions'), $subscription_id, $created),
                get_current_user_id(),
                null,
                $subscription_id
            );
        }

        wp_send_json_success(sprintf(__('Se generaron %d fechas de entrega correctamente', 'cna-subscriptions'), $created));
    }

    /**
     * Renderiza la página del Dashboard
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Encolar Chart.js desde CDN con SRI para proteger contra compromiso de CDN.
        // Hashes SHA-384 verificados contra chart.js@4.4.0 y date-fns@2.30.0.
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true);
        wp_enqueue_script('date-fns', 'https://cdn.jsdelivr.net/npm/date-fns@2.30.0/index.min.js', array(), '2.30.0', true);

        // Add SRI integrity + crossorigin attributes via filter.
        add_filter('script_loader_tag', array($this, 'add_cdn_sri_attributes'), 10, 2);
        ?>
        <div class="wrap cna-dashboard">
            <h1><?php _e('Tablero', 'cna-subscriptions'); ?></h1>

            <!-- Filtros -->
            <div class="cna-dashboard-filters">
                <select id="cna-date-range" class="cna-filter-select">
                    <option value="7"><?php _e('Últimos 7 días', 'cna-subscriptions'); ?></option>
                    <option value="30" selected><?php _e('Últimos 30 días', 'cna-subscriptions'); ?></option>
                    <option value="90"><?php _e('Últimos 90 días', 'cna-subscriptions'); ?></option>
                    <option value="custom"><?php _e('Personalizado', 'cna-subscriptions'); ?></option>
                </select>
                <div id="cna-custom-dates" style="display: none;">
                    <input type="date" id="cna-date-from" class="cna-filter-date">
                    <input type="date" id="cna-date-to" class="cna-filter-date">
                    <button type="button" id="cna-apply-custom" class="button"><?php _e('Aplicar', 'cna-subscriptions'); ?></button>
                </div>
                <select id="cna-product-filter" class="cna-filter-select">
                    <option value=""><?php _e('Todos los productos', 'cna-subscriptions'); ?></option>
                    <?php
                    $products = get_posts(array(
                        'post_type' => 'cna_product',
                        'posts_per_page' => -1,
                        'post_status' => 'publish'
                    ));
                    foreach ($products as $product) {
                        echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <!-- Tarjetas de KPIs -->
            <div class="cna-kpi-cards">
                <div class="cna-kpi-card">
                    <div class="cna-kpi-icon" style="background: #3b82f6;">💰</div>
                    <div class="cna-kpi-content">
                        <div class="cna-kpi-label"><?php _e('Ingresos Totales', 'cna-subscriptions'); ?></div>
                        <div class="cna-kpi-value" id="kpi-total-revenue">$0.00</div>
                        <div class="cna-kpi-change" id="kpi-revenue-change">-</div>
                    </div>
                </div>
                <div class="cna-kpi-card">
                    <div class="cna-kpi-icon" style="background: #10b981;">📊</div>
                    <div class="cna-kpi-content">
                        <div class="cna-kpi-label"><?php _e('Suscripciones Activas', 'cna-subscriptions'); ?></div>
                        <div class="cna-kpi-value" id="kpi-active-subscriptions">0</div>
                        <div class="cna-kpi-change" id="kpi-subscriptions-change">-</div>
                    </div>
                </div>
                <div class="cna-kpi-card">
                    <div class="cna-kpi-icon" style="background: #f59e0b;">📦</div>
                    <div class="cna-kpi-content">
                        <div class="cna-kpi-label"><?php _e('Entregas Programadas', 'cna-subscriptions'); ?></div>
                        <div class="cna-kpi-value" id="kpi-scheduled-deliveries">0</div>
                        <div class="cna-kpi-change"><?php _e('Próximos 7 días', 'cna-subscriptions'); ?></div>
                    </div>
                </div>
                <div class="cna-kpi-card">
                    <div class="cna-kpi-icon" style="background: #ef4444;">⚠️</div>
                    <div class="cna-kpi-content">
                        <div class="cna-kpi-label"><?php _e('Pendientes de Pago', 'cna-subscriptions'); ?></div>
                        <div class="cna-kpi-value" id="kpi-pending-payment">$0.00</div>
                        <div class="cna-kpi-change"><?php _e('Entregas pendientes', 'cna-subscriptions'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Gráficos principales -->
            <div class="cna-charts-grid">
                <div class="cna-chart-card">
                    <h3><?php _e('Ingresos en el Tiempo', 'cna-subscriptions'); ?></h3>
                    <canvas id="chart-revenue-timeline"></canvas>
                </div>
                <div class="cna-chart-card">
                    <h3><?php _e('Estado de Suscripciones', 'cna-subscriptions'); ?></h3>
                    <canvas id="chart-subscription-status"></canvas>
                </div>
                <div class="cna-chart-card">
                    <h3><?php _e('Nuevas Suscripciones', 'cna-subscriptions'); ?></h3>
                    <canvas id="chart-new-subscriptions"></canvas>
                </div>
                <div class="cna-chart-card">
                    <h3><?php _e('Entregas por Estado', 'cna-subscriptions'); ?></h3>
                    <canvas id="chart-deliveries-status"></canvas>
                </div>
                <div class="cna-chart-card">
                    <h3><?php _e('Ingresos por Producto', 'cna-subscriptions'); ?></h3>
                    <canvas id="chart-revenue-by-product"></canvas>
                </div>
                <div class="cna-chart-card">
                    <h3><?php _e('Métodos de Entrega', 'cna-subscriptions'); ?></h3>
                    <canvas id="chart-delivery-methods"></canvas>
                </div>
            </div>

            <!-- Tabla de Entregas Pendientes -->
            <div class="cna-table-card">
                <h3><?php _e('Entregas Pendientes de Pago', 'cna-subscriptions'); ?></h3>
                <div id="cna-pending-deliveries-table">
                    <p class="cna-loading"><?php _e('Cargando...', 'cna-subscriptions'); ?></p>
                </div>
            </div>

            <!-- Tendencias de Cancelación -->
            <div class="cna-chart-card cna-chart-full">
                <h3><?php _e('Tendencias de Cancelación', 'cna-subscriptions'); ?></h3>
                <canvas id="chart-cancellation-trends"></canvas>
            </div>
        </div>

        <style>
        <?php echo $this->get_dashboard_styles(); ?>
        </style>

        <script>
        jQuery(document).ready(function($) {
            const dashboard = {
                charts: {},
                dateRange: 30,
                dateFrom: null,
                dateTo: null,
                productId: null,

                init: function() {
                    this.loadData();
                    this.setupFilters();
                },

                setupFilters: function() {
                    const self = this;
                    $('#cna-date-range').on('change', function() {
                        if ($(this).val() === 'custom') {
                            $('#cna-custom-dates').show();
                        } else {
                            $('#cna-custom-dates').hide();
                            self.dateRange = parseInt($(this).val());
                            self.dateFrom = null;
                            self.dateTo = null;
                            self.loadData();
                        }
                    });

                    $('#cna-apply-custom').on('click', function() {
                        self.dateFrom = $('#cna-date-from').val();
                        self.dateTo = $('#cna-date-to').val();
                        if (self.dateFrom && self.dateTo) {
                            self.loadData();
                        }
                    });

                    $('#cna-product-filter').on('change', function() {
                        self.productId = $(this).val() || null;
                        self.loadData();
                    });
                },

                loadData: function() {
                    const self = this;
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'cna_get_dashboard_data',
                            date_range: self.dateRange,
                            date_from: self.dateFrom,
                            date_to: self.dateTo,
                            product_id: self.productId,
                            nonce: '<?php echo wp_create_nonce('cna_dashboard_data'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                self.updateKPIs(response.data.kpis);
                                self.renderCharts(response.data.charts);
                                self.renderPendingDeliveries(response.data.pending_deliveries);
                            }
                        }
                    });
                },

                updateKPIs: function(kpis) {
                    $('#kpi-total-revenue').text('$' + parseFloat(kpis.total_revenue).toLocaleString('es-SV', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                    $('#kpi-revenue-change').html(this.formatChange(kpis.revenue_change));
                    $('#kpi-active-subscriptions').text(kpis.active_subscriptions);
                    $('#kpi-subscriptions-change').html(this.formatChange(kpis.subscriptions_change));
                    $('#kpi-scheduled-deliveries').text(kpis.scheduled_deliveries);
                    $('#kpi-pending-payment').text('$' + parseFloat(kpis.pending_payment).toLocaleString('es-SV', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                },

                formatChange: function(change) {
                    if (!change || change === 0) return '-';
                    const sign = change > 0 ? '+' : '';
                    const color = change > 0 ? '#10b981' : '#ef4444';
                    return '<span style="color: ' + color + '">' + sign + change.toFixed(1) + '%</span>';
                },

                renderCharts: function(chartsData) {
                    // Ingresos en el Tiempo
                    this.renderLineChart('chart-revenue-timeline', {
                        labels: chartsData.revenue_timeline.labels,
                        datasets: [{
                            label: 'Ingresos Totales',
                            data: chartsData.revenue_timeline.data,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4
                        }]
                    });

                    // Estado de Suscripciones
                    this.renderDoughnutChart('chart-subscription-status', {
                        labels: chartsData.subscription_status.labels,
                        data: chartsData.subscription_status.data,
                        colors: ['#10b981', '#f59e0b', '#ef4444', '#6b7280']
                    });

                    // Nuevas Suscripciones
                    this.renderBarChart('chart-new-subscriptions', {
                        labels: chartsData.new_subscriptions.labels,
                        data: chartsData.new_subscriptions.data,
                        color: '#3b82f6'
                    });

                    // Entregas por Estado
                    this.renderStackedBarChart('chart-deliveries-status', {
                        labels: chartsData.deliveries_status.labels,
                        datasets: chartsData.deliveries_status.datasets
                    });

                    // Ingresos por Producto
                    this.renderHorizontalBarChart('chart-revenue-by-product', {
                        labels: chartsData.revenue_by_product.labels,
                        data: chartsData.revenue_by_product.data,
                        color: '#10b981'
                    });

                    // Métodos de Entrega
                    this.renderDoughnutChart('chart-delivery-methods', {
                        labels: chartsData.delivery_methods.labels,
                        data: chartsData.delivery_methods.data,
                        colors: ['#3b82f6', '#f59e0b']
                    });

                    // Tendencias de Cancelación
                    this.renderLineChart('chart-cancellation-trends', {
                        labels: chartsData.cancellation_trends.labels,
                        datasets: [{
                            label: 'Cancelaciones',
                            data: chartsData.cancellation_trends.data,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            tension: 0.4
                        }]
                    });
                },

                renderLineChart: function(canvasId, config) {
                    const ctx = document.getElementById(canvasId);
                    if (this.charts[canvasId]) {
                        this.charts[canvasId].destroy();
                    }
                    this.charts[canvasId] = new Chart(ctx, {
                        type: 'line',
                        data: config,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: true }
                            }
                        }
                    });
                },

                renderDoughnutChart: function(canvasId, config) {
                    const ctx = document.getElementById(canvasId);
                    if (this.charts[canvasId]) {
                        this.charts[canvasId].destroy();
                    }
                    this.charts[canvasId] = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: config.labels,
                            datasets: [{
                                data: config.data,
                                backgroundColor: config.colors
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom' }
                            }
                        }
                    });
                },

                renderBarChart: function(canvasId, config) {
                    const ctx = document.getElementById(canvasId);
                    if (this.charts[canvasId]) {
                        this.charts[canvasId].destroy();
                    }
                    this.charts[canvasId] = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: config.labels,
                            datasets: [{
                                label: 'Nuevas Suscripciones',
                                data: config.data,
                                backgroundColor: config.color
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            }
                        }
                    });
                },

                renderStackedBarChart: function(canvasId, config) {
                    const ctx = document.getElementById(canvasId);
                    if (this.charts[canvasId]) {
                        this.charts[canvasId].destroy();
                    }
                    this.charts[canvasId] = new Chart(ctx, {
                        type: 'bar',
                        data: config,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { stacked: true },
                                y: { stacked: true }
                            },
                            plugins: {
                                legend: { position: 'bottom' }
                            }
                        }
                    });
                },

                renderHorizontalBarChart: function(canvasId, config) {
                    const ctx = document.getElementById(canvasId);
                    if (this.charts[canvasId]) {
                        this.charts[canvasId].destroy();
                    }
                    this.charts[canvasId] = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: config.labels,
                            datasets: [{
                                label: 'Ingresos',
                                data: config.data,
                                backgroundColor: config.color
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            }
                        }
                    });
                },

                renderPendingDeliveries: function(deliveries) {
                    if (deliveries.length === 0) {
                        $('#cna-pending-deliveries-table').html('<p><?php _e('No hay entregas pendientes de pago', 'cna-subscriptions'); ?></p>');
                        return;
                    }

                    let html = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
                    html += '<th><?php _e('Fecha', 'cna-subscriptions'); ?></th>';
                    html += '<th><?php _e('Suscripción', 'cna-subscriptions'); ?></th>';
                    html += '<th><?php _e('Cliente', 'cna-subscriptions'); ?></th>';
                    html += '<th><?php _e('Monto a Cobrar', 'cna-subscriptions'); ?></th>';
                    html += '<th><?php _e('Estado', 'cna-subscriptions'); ?></th>';
                    html += '</tr></thead><tbody>';

                    deliveries.forEach(function(delivery) {
                        html += '<tr>';
                        html += '<td>' + delivery.scheduled_date + '</td>';
                        html += '<td>#' + delivery.subscription_id + '</td>';
                        html += '<td>' + delivery.customer_name + '</td>';
                        html += '<td>$' + parseFloat(delivery.amount_to_collect).toLocaleString('es-SV', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>';
                        html += '<td><span class="cna-status-badge">' + delivery.delivery_status + '</span></td>';
                        html += '</tr>';
                    });

                    html += '</tbody></table>';
                    $('#cna-pending-deliveries-table').html(html);
                }
            };

            dashboard.init();
        });
        </script>
        <?php
    }

    /**
     * Obtiene los estilos del dashboard
     */
    private function get_dashboard_styles() {
        return '
        .cna-dashboard {
            padding: 20px;
        }
        .cna-dashboard-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            align-items: center;
            flex-wrap: wrap;
        }
        .cna-filter-select, .cna-filter-date {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .cna-kpi-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .cna-kpi-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .cna-kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        .cna-kpi-content {
            flex: 1;
        }
        .cna-kpi-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .cna-kpi-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .cna-kpi-change {
            font-size: 12px;
            color: #999;
        }
        .cna-charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .cna-chart-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .cna-chart-card h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 18px;
        }
        .cna-chart-card canvas {
            max-height: 300px;
        }
        .cna-chart-full {
            grid-column: 1 / -1;
        }
        .cna-table-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .cna-table-card h3 {
            margin-top: 0;
            margin-bottom: 20px;
        }
        ';
    }

    /**
     * Handler AJAX para obtener datos del dashboard
     */
    public function ajax_get_dashboard_data() {
        check_ajax_referer('cna_dashboard_data', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos', 'cna-subscriptions'));
        }

        $date_range = intval($_POST['date_range'] ?? 30);
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $product_id = intval($_POST['product_id'] ?? 0);

        // Calcular fechas
        if ($date_from && $date_to) {
            $start_date = $date_from;
            $end_date = $date_to;
        } else {
            $end_date = date('Y-m-d');
            $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
        }

        $data = array(
            'kpis' => $this->get_dashboard_kpis($start_date, $end_date, $product_id),
            'charts' => $this->get_dashboard_charts($start_date, $end_date, $product_id),
            'pending_deliveries' => $this->get_pending_deliveries($product_id)
        );

        wp_send_json_success($data);
    }

    /**
     * Obtiene los KPIs del dashboard
     */
    private function get_dashboard_kpis($start_date, $end_date, $product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Fechas para comparación (período anterior)
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24);
        $prev_start = date('Y-m-d', strtotime($start_date . " -{$days_diff} days"));
        $prev_end = $start_date;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        // Ingresos totales (período actual)
        $total_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_with_fee), 0) 
             FROM {$table_prefix}cna_subscriptions s
             WHERE s.status = 'active' 
             AND DATE(s.created_at) BETWEEN %s AND %s
             {$product_filter}",
            $start_date, $end_date
        ));

        // Ingresos totales (período anterior)
        $prev_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_with_fee), 0) 
             FROM {$table_prefix}cna_subscriptions s
             WHERE s.status = 'active' 
             AND DATE(s.created_at) BETWEEN %s AND %s
             {$product_filter}",
            $prev_start, $prev_end
        ));

        $revenue_change = $prev_revenue > 0 ? (($total_revenue - $prev_revenue) / $prev_revenue) * 100 : 0;

        // Suscripciones activas
        $active_subscriptions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$table_prefix}cna_subscriptions s
             WHERE s.status = 'active'
             AND DATE(s.created_at) <= %s
             {$product_filter}",
            $end_date
        ));

        // Suscripciones activas (período anterior)
        $prev_active = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$table_prefix}cna_subscriptions s
             WHERE s.status = 'active'
             AND DATE(s.created_at) <= %s
             {$product_filter}",
            $prev_end
        ));

        $subscriptions_change = $prev_active > 0 ? (($active_subscriptions - $prev_active) / $prev_active) * 100 : 0;

        // Entregas programadas (próximos 7 días)
        $scheduled_deliveries = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$table_prefix}cna_deliveries d
             INNER JOIN {$table_prefix}cna_subscriptions s ON d.subscription_id = s.id
             WHERE d.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             AND d.delivery_status IN ('scheduled', 'pending')
             " . ($product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '')
        ));

        // Pendientes de pago
        $pending_payment = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(d.amount_to_collect), 0)
             FROM {$table_prefix}cna_deliveries d
             INNER JOIN {$table_prefix}cna_subscriptions s ON d.subscription_id = s.id
             WHERE d.payment_status = 'pending'
             AND d.amount_to_collect > 0
             " . ($product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '')
        ));

        return array(
            'total_revenue' => floatval($total_revenue),
            'revenue_change' => floatval($revenue_change),
            'active_subscriptions' => intval($active_subscriptions),
            'subscriptions_change' => floatval($subscriptions_change),
            'scheduled_deliveries' => intval($scheduled_deliveries),
            'pending_payment' => floatval($pending_payment)
        );
    }

    /**
     * Obtiene los datos para los gráficos
     */
    private function get_dashboard_charts($start_date, $end_date, $product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        // Ingresos en el tiempo
        $revenue_timeline = $this->get_revenue_timeline($start_date, $end_date, $product_id);

        // Estado de suscripciones
        $subscription_status = $this->get_subscription_status_distribution($product_id);

        // Nuevas suscripciones
        $new_subscriptions = $this->get_new_subscriptions_timeline($start_date, $end_date, $product_id);

        // Entregas por estado
        $deliveries_status = $this->get_deliveries_by_status($start_date, $end_date, $product_id);

        // Ingresos por producto
        $revenue_by_product = $this->get_revenue_by_product($start_date, $end_date);

        // Métodos de entrega
        $delivery_methods = $this->get_delivery_methods_distribution($product_id);

        // Tendencias de cancelación
        $cancellation_trends = $this->get_cancellation_trends($start_date, $end_date, $product_id);

        return array(
            'revenue_timeline' => $revenue_timeline,
            'subscription_status' => $subscription_status,
            'new_subscriptions' => $new_subscriptions,
            'deliveries_status' => $deliveries_status,
            'revenue_by_product' => $revenue_by_product,
            'delivery_methods' => $delivery_methods,
            'cancellation_trends' => $cancellation_trends
        );
    }

    /**
     * Obtiene ingresos en el tiempo
     */
    private function get_revenue_timeline($start_date, $end_date, $product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(s.created_at) as date, SUM(s.total_with_fee) as revenue
             FROM {$table_prefix}cna_subscriptions s
             WHERE s.status = 'active'
             AND DATE(s.created_at) BETWEEN %s AND %s
             {$product_filter}
             GROUP BY DATE(s.created_at)
             ORDER BY date ASC",
            $start_date, $end_date
        ));

        $labels = array();
        $data = array();

        $current = strtotime($start_date);
        $end = strtotime($end_date);

        while ($current <= $end) {
            $date_str = date('Y-m-d', $current);
            $labels[] = date('d/m', $current);
            
            $revenue = 0;
            foreach ($results as $row) {
                if ($row->date === $date_str) {
                    $revenue = floatval($row->revenue);
                    break;
                }
            }
            $data[] = $revenue;
            
            $current = strtotime('+1 day', $current);
        }

        return array('labels' => $labels, 'data' => $data);
    }

    /**
     * Obtiene distribución de estados de suscripciones
     */
    private function get_subscription_status_distribution($product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" WHERE product_id = %d", $product_id) : '';

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count
             FROM {$table_prefix}cna_subscriptions
             {$product_filter}
             GROUP BY status"
        );

        $labels = array();
        $data = array();

        foreach ($results as $row) {
            $labels[] = $this->get_status_label($row->status);
            $data[] = intval($row->count);
        }

        return array('labels' => $labels, 'data' => $data);
    }

    /**
     * Obtiene línea de tiempo de nuevas suscripciones
     */
    private function get_new_subscriptions_timeline($start_date, $end_date, $product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(s.created_at) as date, COUNT(*) as count
             FROM {$table_prefix}cna_subscriptions s
             WHERE DATE(s.created_at) BETWEEN %s AND %s
             {$product_filter}
             GROUP BY DATE(s.created_at)
             ORDER BY date ASC",
            $start_date, $end_date
        ));

        $labels = array();
        $data = array();

        $current = strtotime($start_date);
        $end = strtotime($end_date);

        while ($current <= $end) {
            $date_str = date('Y-m-d', $current);
            $labels[] = date('d/m', $current);
            
            $count = 0;
            foreach ($results as $row) {
                if ($row->date === $date_str) {
                    $count = intval($row->count);
                    break;
                }
            }
            $data[] = $count;
            
            $current = strtotime('+1 day', $current);
        }

        return array('labels' => $labels, 'data' => $data);
    }

    /**
     * Obtiene entregas por estado
     */
    private function get_deliveries_by_status($start_date, $end_date, $product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        // Agrupar por semana
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                YEARWEEK(d.scheduled_date) as week,
                d.delivery_status,
                COUNT(*) as count
             FROM {$table_prefix}cna_deliveries d
             INNER JOIN {$table_prefix}cna_subscriptions s ON d.subscription_id = s.id
             WHERE d.scheduled_date BETWEEN %s AND %s
             {$product_filter}
             GROUP BY YEARWEEK(d.scheduled_date), d.delivery_status
             ORDER BY week ASC",
            $start_date, $end_date
        ));

        $weeks = array();
        $statuses = array('scheduled', 'pending', 'delivered_home', 'delivered_to_customer', 'dispatched_to_store', 'cancelled');

        foreach ($results as $row) {
            $week = $row->week;
            if (!isset($weeks[$week])) {
                $weeks[$week] = array();
                foreach ($statuses as $status) {
                    $weeks[$week][$status] = 0;
                }
            }
            $weeks[$week][$row->delivery_status] = intval($row->count);
        }

        $labels = array();
        $datasets = array();
        $colors = array(
            'scheduled' => '#f59e0b',
            'pending' => '#3b82f6',
            'delivered_home' => '#10b981',
            'delivered_to_customer' => '#10b981',
            'dispatched_to_store' => '#f59e0b',
            'cancelled' => '#ef4444'
        );

        foreach ($weeks as $week => $data) {
            $labels[] = 'Sem ' . substr($week, 4);
        }

        foreach ($statuses as $status) {
            $status_data = array();
            foreach ($weeks as $week => $data) {
                $status_data[] = $data[$status] ?? 0;
            }
            if (array_sum($status_data) > 0) {
                $datasets[] = array(
                    'label' => $this->get_delivery_status_label($status),
                    'data' => $status_data,
                    'backgroundColor' => $colors[$status] ?? '#6b7280'
                );
            }
        }

        return array('labels' => $labels, 'datasets' => $datasets);
    }

    /**
     * Obtiene ingresos por producto
     */
    private function get_revenue_by_product($start_date, $end_date) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.product_id, p.post_title as product_name, SUM(s.total_with_fee) as revenue
             FROM {$table_prefix}cna_subscriptions s
             INNER JOIN {$table_prefix}posts p ON s.product_id = p.ID
             WHERE s.status = 'active'
             AND DATE(s.created_at) BETWEEN %s AND %s
             GROUP BY s.product_id
             ORDER BY revenue DESC
             LIMIT 5",
            $start_date, $end_date
        ));

        $labels = array();
        $data = array();

        foreach ($results as $row) {
            $labels[] = $row->product_name;
            $data[] = floatval($row->revenue);
        }

        return array('labels' => $labels, 'data' => $data);
    }

    /**
     * Obtiene distribución de métodos de entrega
     */
    private function get_delivery_methods_distribution($product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        $results = $wpdb->get_results(
            "SELECT shipping_address_json
             FROM {$table_prefix}cna_subscriptions s
             WHERE s.status = 'active'
             {$product_filter}"
        );

        $home_count = 0;
        $pickup_count = 0;

        foreach ($results as $row) {
            $shipping_data = json_decode($row->shipping_address_json, true);
            if (isset($shipping_data['type'])) {
                if ($shipping_data['type'] === 'home') {
                    $home_count++;
                } else {
                    $pickup_count++;
                }
            }
        }

        return array(
            'labels' => array('Entrega a Domicilio', 'Recoger en Tienda'),
            'data' => array($home_count, $pickup_count)
        );
    }

    /**
     * Obtiene tendencias de cancelación
     */
    private function get_cancellation_trends($start_date, $end_date, $product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(s.updated_at) as date, COUNT(*) as count
             FROM {$table_prefix}cna_subscriptions s
             WHERE s.status = 'cancelled'
             AND DATE(s.updated_at) BETWEEN %s AND %s
             {$product_filter}
             GROUP BY DATE(s.updated_at)
             ORDER BY date ASC",
            $start_date, $end_date
        ));

        $labels = array();
        $data = array();

        $current = strtotime($start_date);
        $end = strtotime($end_date);

        while ($current <= $end) {
            $date_str = date('Y-m-d', $current);
            $labels[] = date('d/m', $current);
            
            $count = 0;
            foreach ($results as $row) {
                if ($row->date === $date_str) {
                    $count = intval($row->count);
                    break;
                }
            }
            $data[] = $count;
            
            $current = strtotime('+1 day', $current);
        }

        return array('labels' => $labels, 'data' => $data);
    }

    /**
     * Obtiene entregas pendientes de pago
     */
    private function get_pending_deliveries($product_id = 0) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $product_filter = $product_id > 0 ? $wpdb->prepare(" AND s.product_id = %d", $product_id) : '';

        $results = $wpdb->get_results(
            "SELECT 
                d.id,
                d.subscription_id,
                d.scheduled_date,
                d.amount_to_collect,
                d.delivery_status,
                u.display_name as customer_name
             FROM {$table_prefix}cna_deliveries d
             INNER JOIN {$table_prefix}cna_subscriptions s ON d.subscription_id = s.id
             INNER JOIN {$table_prefix}users u ON s.user_id = u.ID
             WHERE d.payment_status = 'pending'
             AND d.amount_to_collect > 0
             {$product_filter}
             ORDER BY d.scheduled_date ASC
             LIMIT 20"
        );

        $deliveries = array();
        foreach ($results as $row) {
            $deliveries[] = array(
                'id' => intval($row->id),
                'subscription_id' => intval($row->subscription_id),
                'scheduled_date' => $row->scheduled_date,
                'amount_to_collect' => floatval($row->amount_to_collect),
                'delivery_status' => $this->get_delivery_status_label($row->delivery_status),
                'customer_name' => $row->customer_name
            );
        }

        return $deliveries;
    }
}
