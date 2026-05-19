<?php
/**
 * Custom Post Type: cna_product
 * Registra el tipo de post para productos de suscripción
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Post_Type
{

    /**
     * Inicializa los hooks
     */
    public function init()
    {
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));

        // Desactivar editor de bloques (Gutenberg) solo para cna_product
        add_filter('use_block_editor_for_post_type', array($this, 'disable_block_editor'), 10, 2);

        // Encolar scripts para el admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Template para single-cna_product
        add_filter('single_template', array($this, 'get_single_template'));
    }

    /**
     * Encola scripts para el admin
     */
    public function enqueue_admin_scripts($hook)
    {
        global $post_type;

        // Solo en el editor de cna_product
        if (($hook === 'post.php' || $hook === 'post-new.php') && $post_type === 'cna_product') {
            wp_enqueue_script('jquery');
        }
    }

    /**
     * Registra el Custom Post Type
     */
    public function register_post_type()
    {
        $labels = array(
            'name' => _x('Suscripciones', 'Post Type General Name', 'cna-subscriptions'),
            'singular_name' => _x('Suscripción', 'Post Type Singular Name', 'cna-subscriptions'),
            'menu_name' => __('Suscripciones', 'cna-subscriptions'),
            'name_admin_bar' => __('Suscripción', 'cna-subscriptions'),
            'archives' => __('Archivo de Suscripciones', 'cna-subscriptions'),
            'attributes' => __('Atributos de Suscripción', 'cna-subscriptions'),
            'parent_item_colon' => __('Suscripción Padre:', 'cna-subscriptions'),
            'all_items' => __('Todos los Productos', 'cna-subscriptions'),
            'add_new_item' => __('Agregar Producto', 'cna-subscriptions'),
            'add_new' => __('Agregar Producto', 'cna-subscriptions'),
            'new_item' => __('Nueva Suscripción', 'cna-subscriptions'),
            'edit_item' => __('Editar Suscripción', 'cna-subscriptions'),
            'update_item' => __('Actualizar Suscripción', 'cna-subscriptions'),
            'view_item' => __('Ver Suscripción', 'cna-subscriptions'),
            'view_items' => __('Ver Suscripciones', 'cna-subscriptions'),
            'search_items' => __('Buscar Suscripción', 'cna-subscriptions'),
        );

        $args = array(
            'label' => __('Suscripción', 'cna-subscriptions'),
            'description' => __('Productos de suscripción con entregas recurrentes', 'cna-subscriptions'),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'thumbnail', 'excerpt'),
            'hierarchical' => false,
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 20,
            'menu_icon' => 'dashicons-cart',
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => true,
            'can_export' => true,
            'has_archive' => true,
            'exclude_from_search' => false,
            'publicly_queryable' => true,
            'capability_type' => 'post',
            'show_in_rest' => false, // Desactivado para usar editor clásico
            'rewrite' => array('slug' => 'suscripcion'),
        );

        register_post_type('cna_product', $args);
    }

    /**
     * Desactiva el editor de bloques solo para cna_product
     *
     * @param bool $use_block_editor Si usar el editor de bloques
     * @param string $post_type Tipo de post
     * @return bool
     */
    public function disable_block_editor($use_block_editor, $post_type)
    {
        if ($post_type === 'cna_product') {
            return false; // Usar editor clásico
        }
        return $use_block_editor; // Mantener comportamiento por defecto para otros tipos
    }

    /**
     * Agrega los metaboxes
     */
    public function add_meta_boxes()
    {
        add_meta_box(
            'cna_product_category',
            __('Categoría de Suscripción', 'cna-subscriptions'),
            array($this, 'render_category_meta_box'),
            'cna_product',
            'side',
            'high'
        );

        add_meta_box(
            'cna_product_variations',
            __('Variaciones del Producto', 'cna-subscriptions'),
            array($this, 'render_variations_meta_box'),
            'cna_product',
            'normal',
            'high'
        );

        add_meta_box(
            'cna_product_shipping',
            __('Tarifas de Envío por Zona', 'cna-subscriptions'),
            array($this, 'render_shipping_meta_box'),
            'cna_product',
            'normal',
            'high'
        );

        add_meta_box(
            'cna_product_template',
            __('Plantilla de Visualización', 'cna-subscriptions'),
            array($this, 'render_template_meta_box'),
            'cna_product',
            'side',
            'default'
        );
    }

    /**
     * Renderiza el metabox de categoría del producto
     */
    public function render_category_meta_box($post)
    {
        wp_nonce_field('cna_product_meta_box', 'cna_product_meta_box_nonce');

        $category_id = get_post_meta($post->ID, '_cna_product_category', true);
        $categories = new CNA_Categories();
        $all_categories = $categories->get_all('display_order');

        ?>
        <div style="padding: 10px 0;">
            <select name="cna_product_category" id="cna_product_category">
                <option value="">-- <?php _e('Seleccionar categoría', 'cna-subscriptions'); ?> --</option>
                <?php foreach ($all_categories as $cat): ?>
                    <option value="<?php echo esc_attr($cat['id']); ?>" <?php selected($category_id, $cat['id']); ?>>
                        <?php echo esc_html($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description">
                <?php _e('Selecciona la categoría para clasificar este producto. Puedes crear nuevas categorías en Ajustes.', 'cna-subscriptions'); ?>
                <br>
                <a href="<?php echo add_query_arg(array('post_type' => 'cna_product', 'page' => 'cna-settings', 'tab' => 'categories'), admin_url('edit.php')); ?>"
                    target="_blank">
                    <?php _e('Ir a Categorías', 'cna-subscriptions'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Renderiza el metabox de variaciones del producto
     */
    public function render_variations_meta_box($post)
    {
        wp_nonce_field('cna_product_meta_box', 'cna_product_meta_box_nonce');

        // Obtener variaciones del producto
        $variations_json = get_post_meta($post->ID, '_cna_product_variations', true);
        if (!empty($variations_json)) {
            // Decodificar JSON con soporte UTF-8
            $variations = json_decode($variations_json, true, 512, JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Si falla, intentar sin el flag (compatibilidad)
                $variations = json_decode($variations_json, true);
            }
        } else {
            $variations = array();
        }

        if (!is_array($variations)) {
            $variations = array();
        }

        $annual_fee = get_post_meta($post->ID, '_cna_annual_fee', true);

        ?>
        <div id="cna-variations-container">
            <p class="description">
                <?php _e('Define las variaciones de este producto. Cada variación debe tener un nombre, descripción y precio.', 'cna-subscriptions'); ?>
            </p>

            <!-- Template para nueva variación (oculto) -->
            <div id="cna-variation-template" style="display: none;">
                <div class="cna-variation-item"
                    style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; background: #f9f9f9;">
                    <table class="form-table" style="margin: 0;">
                        <tr>
                            <th style="width: 150px;"><label><?php _e('Nombre', 'cna-subscriptions'); ?></label></th>
                            <td>
                                <input type="text" name="cna_variations[__INDEX__][name]" class="regular-text variation-name"
                                    placeholder="<?php _e('Ej: Pequeño, Mediano, Grande', 'cna-subscriptions'); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('Descripción', 'cna-subscriptions'); ?></label></th>
                            <td>
                                <textarea name="cna_variations[__INDEX__][description]" class="large-text" rows="2"
                                    placeholder="<?php _e('Descripción de la variación', 'cna-subscriptions'); ?>"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('Precio', 'cna-subscriptions'); ?></label></th>
                            <td>
                                <input type="number" name="cna_variations[__INDEX__][price]" step="0.01" min="0"
                                    class="regular-text variation-price" placeholder="0.00" />
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <button type="button" class="button button-small cna-remove-variation">
                                    <?php _e('Eliminar', 'cna-subscriptions'); ?>
                                </button>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Variaciones existentes -->
            <div id="cna-variations-list">
                <?php if (empty($variations)): ?>
                    <p class="description">
                        <?php _e('No hay variaciones definidas. Haz clic en "Agregar Variación" para comenzar.', 'cna-subscriptions'); ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($variations as $index => $variation): ?>
                        <div class="cna-variation-item"
                            style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; background: #f9f9f9;">
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th style="width: 150px;"><label><?php _e('Nombre', 'cna-subscriptions'); ?></label></th>
                                    <td>
                                        <input type="text" name="cna_variations[<?php echo esc_attr($index); ?>][name]"
                                            value="<?php echo esc_attr($variation['name'] ?? ''); ?>"
                                            class="regular-text variation-name" />
                                    </td>
                                </tr>
                                <tr>
                                    <th><label><?php _e('Descripción', 'cna-subscriptions'); ?></label></th>
                                    <td>
                                        <textarea name="cna_variations[<?php echo esc_attr($index); ?>][description]" class="large-text"
                                            rows="2"><?php echo esc_textarea($variation['description'] ?? ''); ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label><?php _e('Precio', 'cna-subscriptions'); ?></label></th>
                                    <td>
                                        <input type="number" name="cna_variations[<?php echo esc_attr($index); ?>][price]"
                                            value="<?php echo esc_attr($variation['price'] ?? ''); ?>" step="0.01" min="0"
                                            class="regular-text variation-price" />
                                    </td>
                                </tr>
                                <tr>
                                    <th></th>
                                    <td>
                                        <button type="button" class="button button-small cna-remove-variation">
                                            <?php _e('Eliminar', 'cna-subscriptions'); ?>
                                        </button>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Botón para agregar variación -->
            <p>
                <button type="button" id="cna-add-variation" class="button">
                    <?php _e('+ Agregar Variación', 'cna-subscriptions'); ?>
                </button>
            </p>

            <!-- Configuración de Entregas -->
            <hr style="margin: 20px 0;">
            <h3><?php _e('Configuración de Entregas', 'cna-subscriptions'); ?></h3>
            <?php
            $delivery_day = get_post_meta($post->ID, '_cna_delivery_day', true);
            $order_cutoff = get_post_meta($post->ID, '_cna_order_cutoff', true);
            $min_qty = CNA_Product_Helper::get_min_qty($post->ID);

            // Obtener frecuencias (ahora es un array)
            $frequencies_json = get_post_meta($post->ID, '_cna_product_frequencies', true);
            $frequencies = array();
            if (!empty($frequencies_json)) {
                $frequencies = json_decode($frequencies_json, true);
                if (!is_array($frequencies)) {
                    $frequencies = array();
                }
            }

            // Valores por defecto si no están configurados
            if ($delivery_day === '' || $delivery_day === false) {
                $delivery_day = '4'; // Jueves por defecto
            }
            if ($order_cutoff === '' || $order_cutoff === false) {
                $order_cutoff = '3'; // Miércoles por defecto
            }

            // Si no hay frecuencias, crear una por defecto
            if (empty($frequencies)) {
                $frequencies = array(
                    array('amount' => '1', 'unit' => 'weeks', 'label' => 'Cada semana')
                );
            }
            ?>
            <table class="form-table">
                <tr>
                    <th><label for="cna_min_qty"><?php _e('Cantidad Mínima de Entregas', 'cna-subscriptions'); ?></label></th>
                    <td>
                        <input type="number" id="cna_min_qty" name="cna_min_qty"
                            value="<?php echo esc_attr($min_qty); ?>" min="1" max="100" class="small-text" />
                        <p class="description">
                            <?php _e('Número mínimo de entregas que el cliente debe seleccionar al suscribirse', 'cna-subscriptions'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cna_delivery_day"><?php _e('Día de Entrega', 'cna-subscriptions'); ?></label></th>
                    <td>
                        <select id="cna_delivery_day" name="cna_delivery_day" class="regular-text">
                            <option value="0" <?php selected($delivery_day, '0'); ?>>
                                <?php _e('Domingo', 'cna-subscriptions'); ?></option>
                            <option value="1" <?php selected($delivery_day, '1'); ?>><?php _e('Lunes', 'cna-subscriptions'); ?>
                            </option>
                            <option value="2" <?php selected($delivery_day, '2'); ?>><?php _e('Martes', 'cna-subscriptions'); ?>
                            </option>
                            <option value="3" <?php selected($delivery_day, '3'); ?>>
                                <?php _e('Miércoles', 'cna-subscriptions'); ?></option>
                            <option value="4" <?php selected($delivery_day, '4'); ?>><?php _e('Jueves', 'cna-subscriptions'); ?>
                            </option>
                            <option value="5" <?php selected($delivery_day, '5'); ?>>
                                <?php _e('Viernes', 'cna-subscriptions'); ?></option>
                            <option value="6" <?php selected($delivery_day, '6'); ?>><?php _e('Sábado', 'cna-subscriptions'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e('Día de la semana en que se realizarán las entregas', 'cna-subscriptions'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cna_order_cutoff"><?php _e('Día de Corte (Cutoff)', 'cna-subscriptions'); ?></label></th>
                    <td>
                        <select id="cna_order_cutoff" name="cna_order_cutoff" class="regular-text">
                            <option value="0" <?php selected($order_cutoff, '0'); ?>>
                                <?php _e('Domingo', 'cna-subscriptions'); ?></option>
                            <option value="1" <?php selected($order_cutoff, '1'); ?>><?php _e('Lunes', 'cna-subscriptions'); ?>
                            </option>
                            <option value="2" <?php selected($order_cutoff, '2'); ?>><?php _e('Martes', 'cna-subscriptions'); ?>
                            </option>
                            <option value="3" <?php selected($order_cutoff, '3'); ?>>
                                <?php _e('Miércoles', 'cna-subscriptions'); ?></option>
                            <option value="4" <?php selected($order_cutoff, '4'); ?>><?php _e('Jueves', 'cna-subscriptions'); ?>
                            </option>
                            <option value="5" <?php selected($order_cutoff, '5'); ?>>
                                <?php _e('Viernes', 'cna-subscriptions'); ?></option>
                            <option value="6" <?php selected($order_cutoff, '6'); ?>><?php _e('Sábado', 'cna-subscriptions'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e('Día límite para recibir pedidos. Si se ordena antes de este día, la primera entrega será en el día de entrega de esta semana. Si se ordena en o después de este día, la primera entrega será en el día de entrega de la próxima semana.', 'cna-subscriptions'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Frecuencias de Entrega', 'cna-subscriptions'); ?></label></th>
                    <td>
                        <p class="description">
                            <?php _e('Define las opciones de frecuencia que los clientes podrán seleccionar. Ejemplo: Cada semana, Cada 2 semanas, Cada mes, etc.', 'cna-subscriptions'); ?>
                        </p>

                        <!-- Template para nueva frecuencia (oculto) -->
                        <script type="text/html" id="cna-frequency-template">
                                    <div class="cna-frequency-item" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; background: #f9f9f9;">
                                        <table class="form-table" style="margin: 0;">
                                            <tr>
                                                <th style="width: 150px;"><label><?php _e('Cada', 'cna-subscriptions'); ?></label></th>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 10px;">
                                                        <input type="number" 
                                                               name="cna_frequencies[__INDEX__][amount]" 
                                                               value="1" 
                                                               min="1" 
                                                               max="12"
                                                               class="small-text frequency-amount" 
                                                               style="width: 60px;" />
                                                        <select name="cna_frequencies[__INDEX__][unit]" class="regular-text frequency-unit" style="width: 150px;">
                                                            <option value="weeks"><?php _e('Semana(s)', 'cna-subscriptions'); ?></option>
                                                            <option value="months"><?php _e('Mes(es)', 'cna-subscriptions'); ?></option>
                                                        </select>
                                                        <button type="button" class="button button-small cna-remove-frequency">
                                                            <?php _e('Eliminar', 'cna-subscriptions'); ?>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </script>

                        <!-- Lista de frecuencias existentes -->
                        <div id="cna-frequencies-list">
                            <?php if (empty($frequencies)): ?>
                                <p class="description">
                                    <?php _e('No hay frecuencias definidas. Haz clic en "Agregar Frecuencia" para comenzar.', 'cna-subscriptions'); ?>
                                </p>
                            <?php else: ?>
                                <?php foreach ($frequencies as $index => $frequency): ?>
                                    <div class="cna-frequency-item"
                                        style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; background: #f9f9f9;">
                                        <table class="form-table" style="margin: 0;">
                                            <tr>
                                                <th style="width: 150px;"><label><?php _e('Cada', 'cna-subscriptions'); ?></label></th>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 10px;">
                                                        <input type="number"
                                                            name="cna_frequencies[<?php echo esc_attr($index); ?>][amount]"
                                                            value="<?php echo esc_attr($frequency['amount'] ?? '1'); ?>" min="1"
                                                            max="12" class="small-text frequency-amount" style="width: 60px;" />
                                                        <select name="cna_frequencies[<?php echo esc_attr($index); ?>][unit]"
                                                            class="regular-text frequency-unit" style="width: 150px;">
                                                            <option value="weeks" <?php selected($frequency['unit'] ?? 'weeks', 'weeks'); ?>><?php _e('Semana(s)', 'cna-subscriptions'); ?></option>
                                                            <option value="months" <?php selected($frequency['unit'] ?? 'weeks', 'months'); ?>><?php _e('Mes(es)', 'cna-subscriptions'); ?></option>
                                                        </select>
                                                        <button type="button" class="button button-small cna-remove-frequency">
                                                            <?php _e('Eliminar', 'cna-subscriptions'); ?>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Botón para agregar frecuencia -->
                        <p>
                            <button type="button" id="cna-add-frequency" class="button">
                                <?php _e('+ Agregar Frecuencia', 'cna-subscriptions'); ?>
                            </button>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Fee Anual -->
            <hr style="margin: 20px 0;">
            <table class="form-table">
                <tr>
                    <th><label for="cna_annual_fee"><?php _e('Fee Anual', 'cna-subscriptions'); ?></label></th>
                    <td>
                        <input type="number" id="cna_annual_fee" name="cna_annual_fee"
                            value="<?php echo esc_attr($annual_fee); ?>" step="0.01" min="0" class="regular-text" />
                        <p class="description">
                            <?php _e('Cargo anual que se aplica al primer pago y se renueva anualmente', 'cna-subscriptions'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var variationIndex = <?php echo count($variations); ?>;
                var frequencyIndex = <?php echo count($frequencies); ?>;

                // Agregar nueva variación
                $('#cna-add-variation').on('click', function (e) {
                    e.preventDefault();
                    var template = $('#cna-variation-template').html();
                    template = template.replace(/__INDEX__/g, variationIndex);
                    $('#cna-variations-list').append(template);
                    variationIndex++;
                });

                // Eliminar variación
                $(document).on('click', '.cna-remove-variation', function (e) {
                    e.preventDefault();
                    var $item = $(this).closest('.cna-variation-item');
                    CNAAdminModal.confirm({
                        title: '<?php echo esc_js(__('Eliminar variación', 'cna-subscriptions')); ?>',
                        message: '<?php echo esc_js(__('¿Estás seguro de eliminar esta variación?', 'cna-subscriptions')); ?>',
                        variant: 'danger',
                        confirmLabel: '<?php echo esc_js(__('Sí, eliminar', 'cna-subscriptions')); ?>',
                        onConfirm: function () {
                            $item.remove();
                        }
                    });
                });

                // Agregar nueva frecuencia
                $('#cna-add-frequency').on('click', function (e) {
                    e.preventDefault();
                    var template = $('#cna-frequency-template').html();
                    template = template.replace(/__INDEX__/g, frequencyIndex);
                    $('#cna-frequencies-list').append(template);
                    frequencyIndex++;
                });

                // Eliminar frecuencia
                $(document).on('click', '.cna-remove-frequency', function (e) {
                    e.preventDefault();
                    var $item = $(this).closest('.cna-frequency-item');
                    CNAAdminModal.confirm({
                        title: '<?php echo esc_js(__('Eliminar frecuencia', 'cna-subscriptions')); ?>',
                        message: '<?php echo esc_js(__('¿Estás seguro de eliminar esta frecuencia?', 'cna-subscriptions')); ?>',
                        variant: 'danger',
                        confirmLabel: '<?php echo esc_js(__('Sí, eliminar', 'cna-subscriptions')); ?>',
                        onConfirm: function () {
                            $item.remove();
                        }
                    });
                });

                // Limpiar campos required de variaciones eliminadas antes del submit
                $(document).on('submit', '#post', function (e) {
                    // Remover atributos required de campos en variaciones que están ocultas o eliminadas
                    $('.cna-variation-item').each(function () {
                        var $item = $(this);
                        if ($item.is(':hidden') || !$item.closest('#cna-variations-list').length) {
                            $item.find('[required]').removeAttr('required');
                        }
                    });

                    // Remover required de campos vacíos en variaciones que no tienen datos
                    $('#cna-variations-list .cna-variation-item').each(function () {
                        var $item = $(this);
                        var $name = $item.find('.variation-name');
                        var $price = $item.find('.variation-price');

                        // Si el nombre está vacío, remover required del precio
                        if (!$name.val() || $name.val().trim() === '') {
                            $price.removeAttr('required');
                            $name.removeAttr('required');
                        }

                        // Si el precio está vacío, remover required del nombre
                        if (!$price.val() || $price.val() === '0' || $price.val() === '') {
                            $name.removeAttr('required');
                            $price.removeAttr('required');
                        }
                    });

                    // No prevenir el submit - dejar que WordPress maneje el guardado
                    return true;
                });
            });
        </script>
        <?php
    }

    /**
     * Renderiza el metabox de tarifas de envío
     */
    public function render_shipping_meta_box($post)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        // Obtener todas las zonas activas
        $zones = $wpdb->get_results(
            "SELECT id, name FROM {$table_prefix}cna_shipping_zones WHERE is_active = 1 ORDER BY name ASC"
        );

        // Obtener precios guardados para este producto
        $shipping_prices = get_post_meta($post->ID, '_cna_shipping_prices', true);
        if (!is_array($shipping_prices)) {
            $shipping_prices = array();
        }

        ?>
        <p class="description">
            <?php _e('Asigna un precio de envío para cada zona. Si no se asigna precio, esa zona no estará disponible para este producto.', 'cna-subscriptions'); ?>
        </p>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Zona', 'cna-subscriptions'); ?></th>
                    <th><?php _e('Precio por Unidad', 'cna-subscriptions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($zones)): ?>
                    <tr>
                        <td colspan="2">
                            <p><?php _e('No hay zonas configuradas. Ve a Suscripciones > Ajustes para crear zonas.', 'cna-subscriptions'); ?>
                            </p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($zones as $zone):
                        $price = isset($shipping_prices[$zone->id]) ? $shipping_prices[$zone->id] : '';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($zone->name); ?></strong></td>
                            <td>
                                <input type="number" name="cna_shipping_prices[<?php echo esc_attr($zone->id); ?>]"
                                    value="<?php echo esc_attr($price); ?>" step="0.01" min="0" placeholder="0.00"
                                    class="regular-text" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Guarda los datos de los metaboxes
     */
    public function save_meta_boxes($post_id)
    {
        // Verificar autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        // Verificar revisiones
        if (wp_is_post_revision($post_id)) {
            return $post_id;
        }

        // Verificar que sea el post type correcto
        // Para posts nuevos, verificar desde $_POST
        $post_type = '';
        if (isset($_POST['post_type'])) {
            $post_type = $_POST['post_type'];
        } elseif ($post_id) {
            $post_type = get_post_type($post_id);
        }

        if ($post_type !== 'cna_product') {
            return $post_id;
        }

        // Verificar permisos
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }

        // Verificar nonce - solo procesar si el nonce existe y es válido
        // Si no existe el nonce, no procesar los metaboxes pero no bloquear el guardado del post
        if (!isset($_POST['cna_product_meta_box_nonce'])) {
            return $post_id; // No procesar metaboxes si no hay nonce, pero permitir que WordPress guarde el post
        }

        if (!wp_verify_nonce($_POST['cna_product_meta_box_nonce'], 'cna_product_meta_box')) {
            return $post_id; // Nonce inválido, no procesar pero no bloquear guardado
        }

        // Guardar categoría del producto
        if (isset($_POST['cna_product_category'])) {
            $category_id = intval($_POST['cna_product_category']);
            if ($category_id > 0) {
                update_post_meta($post_id, '_cna_product_category', $category_id);
            } else {
                delete_post_meta($post_id, '_cna_product_category');
            }
        }

        // Guardar variaciones del producto
        if (isset($_POST['cna_variations']) && is_array($_POST['cna_variations'])) {
            $variations = array();

            foreach ($_POST['cna_variations'] as $variation_data) {
                // Validar que tenga al menos nombre o precio
                $name = isset($variation_data['name']) ? trim($variation_data['name']) : '';
                $price = isset($variation_data['price']) ? floatval($variation_data['price']) : 0;

                // Solo guardar si tiene nombre Y precio válido
                if (!empty($name) && $price > 0) {
                    $variations[] = array(
                        'name' => sanitize_text_field($name),
                        'description' => sanitize_textarea_field($variation_data['description'] ?? ''),
                        'price' => $price,
                        'slug' => sanitize_title($name), // Generar slug automático
                    );
                }
            }

            // Guardar como JSON con soporte UTF-8 completo
            // Usar json_encode con flags para preservar caracteres Unicode
            $json_variations = json_encode($variations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            update_post_meta($post_id, '_cna_product_variations', $json_variations);
        } else {
            // Si no hay variaciones en POST, eliminar el meta
            delete_post_meta($post_id, '_cna_product_variations');
        }

        // Guardar cantidad mínima de entregas
        if (isset($_POST['cna_min_qty'])) {
            $min_qty = intval($_POST['cna_min_qty']);
            if ($min_qty >= 1 && $min_qty <= 100) {
                update_post_meta($post_id, '_cna_min_qty', $min_qty);
            }
        }

        // Guardar Día de Entrega
        if (isset($_POST['cna_delivery_day'])) {
            $delivery_day = intval($_POST['cna_delivery_day']);
            if ($delivery_day >= 0 && $delivery_day <= 6) {
                update_post_meta($post_id, '_cna_delivery_day', $delivery_day);
            }
        }

        // Guardar Día de Corte
        if (isset($_POST['cna_order_cutoff'])) {
            $order_cutoff = intval($_POST['cna_order_cutoff']);
            if ($order_cutoff >= 0 && $order_cutoff <= 6) {
                update_post_meta($post_id, '_cna_order_cutoff', $order_cutoff);
            }
        }

        // Guardar frecuencias múltiples
        if (isset($_POST['cna_frequencies']) && is_array($_POST['cna_frequencies'])) {
            $frequencies = array();

            foreach ($_POST['cna_frequencies'] as $frequency_data) {
                $amount = isset($frequency_data['amount']) ? intval($frequency_data['amount']) : 0;
                $unit = isset($frequency_data['unit']) ? sanitize_text_field($frequency_data['unit']) : '';

                // Solo guardar si tiene cantidad válida y unidad válida
                if ($amount >= 1 && $amount <= 12 && in_array($unit, array('weeks', 'months'))) {
                    // Generar label para facilitar uso en frontend
                    $label = '';
                    if ($unit === 'weeks') {
                        $label = $amount === 1 ? __('Cada semana', 'cna-subscriptions') : sprintf(__('Cada %d semanas', 'cna-subscriptions'), $amount);
                    } else {
                        $label = $amount === 1 ? __('Cada mes', 'cna-subscriptions') : sprintf(__('Cada %d meses', 'cna-subscriptions'), $amount);
                    }

                    $frequencies[] = array(
                        'amount' => $amount,
                        'unit' => $unit,
                        'label' => $label,
                        'weeks' => $unit === 'months' ? $amount * 4 : $amount, // Convertir a semanas para cálculos
                    );
                }
            }

            // Guardar como JSON con soporte UTF-8 completo
            if (!empty($frequencies)) {
                $json_frequencies = json_encode($frequencies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                update_post_meta($post_id, '_cna_product_frequencies', $json_frequencies);
            } else {
                // Si no hay frecuencias válidas, eliminar el meta
                delete_post_meta($post_id, '_cna_product_frequencies');
            }
        } else {
            // Si no hay frecuencias en POST, eliminar el meta
            delete_post_meta($post_id, '_cna_product_frequencies');
        }

        // Guardar Fee Anual
        if (isset($_POST['cna_annual_fee'])) {
            update_post_meta($post_id, '_cna_annual_fee', sanitize_text_field($_POST['cna_annual_fee']));
        }

        // Guardar tarifas de envío
        if (isset($_POST['cna_shipping_prices']) && is_array($_POST['cna_shipping_prices'])) {
            $shipping_prices = array();
            foreach ($_POST['cna_shipping_prices'] as $zone_id => $price) {
                $shipping_prices[sanitize_key($zone_id)] = floatval($price);
            }
            update_post_meta($post_id, '_cna_shipping_prices', $shipping_prices);
        }

        // Guardar plantilla seleccionada
        if (isset($_POST['cna_product_template'])) {
            $template = sanitize_text_field($_POST['cna_product_template']);
            $available_templates = $this->get_available_templates();

            if (isset($available_templates[$template]) || $template === 'custom') {
                update_post_meta($post_id, '_cna_product_template', $template);
            } else {
                update_post_meta($post_id, '_cna_product_template', 'default');
            }
        }

        // Guardar plantilla personalizada
        if (isset($_POST['cna_product_custom_template'])) {
            $custom_template = sanitize_text_field($_POST['cna_product_custom_template']);
            if (!empty($custom_template)) {
                update_post_meta($post_id, '_cna_product_custom_template', $custom_template);
            } else {
                delete_post_meta($post_id, '_cna_product_custom_template');
            }
        }

        // Siempre retornar el post_id para no bloquear el guardado
        return $post_id;
    }

    /**
     * Renderiza el metabox de selección de plantilla
     */
    public function render_template_meta_box($post)
    {
        wp_nonce_field('cna_product_meta_box', 'cna_product_meta_box_nonce');

        // Obtener plantilla seleccionada
        $selected_template = get_post_meta($post->ID, '_cna_product_template', true);
        if (empty($selected_template)) {
            $selected_template = 'default';
        }

        // Plantillas disponibles
        $available_templates = $this->get_available_templates();
        ?>
        <div class="cna-template-selector">
            <p class="description">
                <?php _e('Selecciona la plantilla que se usará para mostrar este producto en el frontend.', 'cna-subscriptions'); ?>
            </p>

            <label for="cna_product_template" style="display: block; margin-bottom: 8px; font-weight: 600;">
                <?php _e('Plantilla:', 'cna-subscriptions'); ?>
            </label>

            <select name="cna_product_template" id="cna_product_template" style="width: 100%;">
                <?php foreach ($available_templates as $template_key => $template_info): ?>
                    <option value="<?php echo esc_attr($template_key); ?>" <?php selected($selected_template, $template_key); ?>>
                        <?php echo esc_html($template_info['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($selected_template !== 'default'): ?>
                <p class="description" style="margin-top: 8px;">
                    <strong><?php _e('Descripción:', 'cna-subscriptions'); ?></strong><br>
                    <?php echo esc_html($available_templates[$selected_template]['description']); ?>
                </p>
            <?php endif; ?>

            <p class="description" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                <strong><?php _e('Plantilla personalizada:', 'cna-subscriptions'); ?></strong><br>
                <?php _e('Para usar una plantilla personalizada del tema, usa el formato:', 'cna-subscriptions'); ?><br>
                <code>theme:single-cna_product-custom.php</code><br>
                <?php _e('O desde el plugin:', 'cna-subscriptions'); ?><br>
                <code>plugin:templates/custom-template.php</code>
            </p>

            <label for="cna_product_custom_template" style="display: block; margin-top: 12px; font-weight: 600;">
                <?php _e('Ruta personalizada:', 'cna-subscriptions'); ?>
            </label>
            <input type="text" name="cna_product_custom_template" id="cna_product_custom_template"
                value="<?php echo esc_attr(get_post_meta($post->ID, '_cna_product_custom_template', true)); ?>"
                placeholder="theme:single-cna_product-custom.php" style="width: 100%;" class="regular-text" />
            <p class="description">
                <?php _e('Deja vacío para usar la plantilla seleccionada arriba.', 'cna-subscriptions'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Obtiene las plantillas disponibles
     *
     * @return array Array de plantillas disponibles
     */
    private function get_available_templates()
    {
        return array(
            'default' => array(
                'name' => __('Por Defecto', 'cna-subscriptions'),
                'description' => __('Plantilla estándar con imagen, descripción y configurador de producto.', 'cna-subscriptions'),
                'file' => 'single-cna_product.php',
            ),
            'minimal' => array(
                'name' => __('Minimalista', 'cna-subscriptions'),
                'description' => __('Diseño limpio y minimalista, enfocado en el contenido esencial.', 'cna-subscriptions'),
                'file' => 'single-cna_product-minimal.php',
            ),
            'featured' => array(
                'name' => __('Destacado', 'cna-subscriptions'),
                'description' => __('Plantilla con imagen destacada grande y diseño moderno.', 'cna-subscriptions'),
                'file' => 'single-cna_product-featured.php',
            ),
            'compact' => array(
                'name' => __('Compacta', 'cna-subscriptions'),
                'description' => __('Diseño compacto ideal para listados o páginas con múltiples productos.', 'cna-subscriptions'),
                'file' => 'single-cna_product-compact.php',
            ),
        );
    }

    /**
     * Obtiene el template para single-cna_product
     *
     * @param string $template Template actual
     * @return string Template a usar
     */
    public function get_single_template($template)
    {
        global $post;

        if ($post && $post->post_type === 'cna_product') {
            // Verificar si hay una plantilla personalizada
            $custom_template = get_post_meta($post->ID, '_cna_product_custom_template', true);

            if (!empty($custom_template)) {
                // Plantilla personalizada especificada
                if (strpos($custom_template, 'theme:') === 0) {
                    // Plantilla del tema
                    $template_path = str_replace('theme:', '', $custom_template);
                    $theme_template = locate_template(array($template_path));
                    if ($theme_template) {
                        return $theme_template;
                    }
                } elseif (strpos($custom_template, 'plugin:') === 0) {
                    // Plantilla del plugin
                    $template_path = str_replace('plugin:', '', $custom_template);
                    $plugin_template = CNA_SUBSCRIPTIONS_PLUGIN_DIR . $template_path;
                    if (file_exists($plugin_template)) {
                        return $plugin_template;
                    }
                } else {
                    // Ruta absoluta o relativa directa
                    if (file_exists($custom_template)) {
                        return $custom_template;
                    }
                    // Intentar como ruta relativa del tema
                    $theme_template = locate_template(array($custom_template));
                    if ($theme_template) {
                        return $theme_template;
                    }
                }
            }

            // Usar plantilla seleccionada
            $selected_template = get_post_meta($post->ID, '_cna_product_template', true);
            if (empty($selected_template)) {
                $selected_template = 'default';
            }

            $available_templates = $this->get_available_templates();

            if (isset($available_templates[$selected_template])) {
                $template_file = $available_templates[$selected_template]['file'];
                $plugin_template = CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'templates/' . $template_file;

                if (file_exists($plugin_template)) {
                    return $plugin_template;
                }
            }

            // Fallback a plantilla por defecto
            $default_template = CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'templates/single-cna_product.php';
            if (file_exists($default_template)) {
                return $default_template;
            }
        }

        return $template;
    }
}
