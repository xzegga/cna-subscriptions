<?php
/**
 * Gestor de Categorías de Suscripciones
 * Maneja CRUD de categorías para clasificar productos
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Categories
{

    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cna_subscription_categories';
    }

    /**
     * Crea la tabla de categorías en la BD
     */
    public static function create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cna_subscription_categories';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL UNIQUE,
            description LONGTEXT,
            color VARCHAR(7) DEFAULT '#000000',
            icon VARCHAR(255),
            display_order INT(11) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_slug (slug),
            INDEX idx_order (display_order)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Obtiene todas las categorías
     *
     * @param string $orderby Campo para ordenar
     * @return array
     */
    public function get_all($orderby = 'display_order')
    {
        global $wpdb;

        $allowed_orderby = array('display_order', 'name', 'id', 'created_at');
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'display_order';

        // No prepare needed since orderby is whitelisted
        $query = "SELECT * FROM {$this->table_name} ORDER BY {$orderby} ASC";

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Obtiene una categoría por ID
     *
     * @param int $id ID de la categoría
     * @return array|null
     */
    public function get_by_id($id)
    {
        global $wpdb;

        $id = intval($id);
        $query = $wpdb->prepare(
            "SELECT * FROM " . $this->table_name . " WHERE id = %d",
            $id
        );

        return $wpdb->get_row($query, ARRAY_A);
    }

    /**
     * Obtiene una categoría por slug
     *
     * @param string $slug Slug de la categoría
     * @return array|null
     */
    public function get_by_slug($slug)
    {
        global $wpdb;

        $slug = sanitize_text_field($slug);
        $query = $wpdb->prepare(
            "SELECT * FROM " . $this->table_name . " WHERE slug = %s",
            $slug
        );

        return $wpdb->get_row($query, ARRAY_A);
    }

    /**
     * Crea una nueva categoría
     *
     * @param array $data Datos de la categoría
     * @return int|WP_Error ID de la categoría creada o error
     */
    public function create($data)
    {
        global $wpdb;

        // Validaciones
        if (empty($data['name'])) {
            return new WP_Error(
                'missing_name',
                __('El nombre de la categoría es requerido', 'cna-subscriptions')
            );
        }

        $name = sanitize_text_field($data['name']);
        $slug = isset($data['slug']) && !empty($data['slug'])
            ? sanitize_title($data['slug'])
            : sanitize_title($name);

        // Verificar si el slug ya existe
        if ($this->slug_exists($slug)) {
            return new WP_Error(
                'slug_exists',
                __('El slug ya existe. Por favor usa uno diferente', 'cna-subscriptions')
            );
        }

        $insert_data = array(
            'name' => $name,
            'slug' => $slug,
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'color' => isset($data['color']) && preg_match('/^#[0-9A-F]{6}$/i', $data['color']) ? $data['color'] : '#000000',
            'icon' => isset($data['icon']) ? sanitize_text_field($data['icon']) : '',
            'display_order' => isset($data['display_order']) ? intval($data['display_order']) : 0,
        );

        $result = $wpdb->insert($this->table_name, $insert_data, array('%s', '%s', '%s', '%s', '%s', '%d'));

        if ($result === false) {
            return new WP_Error(
                'db_insert_error',
                __('Error al crear la categoría en la BD', 'cna-subscriptions')
            );
        }

        return $wpdb->insert_id;
    }

    /**
     * Actualiza una categoría
     *
     * @param int $id ID de la categoría
     * @param array $data Datos a actualizar
     * @return bool|WP_Error
     */
    public function update($id, $data)
    {
        global $wpdb;

        $id = intval($id);
        $category = $this->get_by_id($id);

        if (!$category) {
            return new WP_Error(
                'category_not_found',
                __('Categoría no encontrada', 'cna-subscriptions')
            );
        }

        $update_data = array();

        if (isset($data['name']) && !empty($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }

        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
        }

        if (isset($data['color']) && preg_match('/^#[0-9A-F]{6}$/i', $data['color'])) {
            $update_data['color'] = $data['color'];
        }

        if (isset($data['icon'])) {
            $update_data['icon'] = sanitize_text_field($data['icon']);
        }

        if (isset($data['display_order'])) {
            $update_data['display_order'] = intval($data['display_order']);
        }

        if (empty($update_data)) {
            return true;
        }

        $result = $wpdb->update($this->table_name, $update_data, array('id' => $id), array('%s', '%s', '%s', '%s', '%d'), array('%d'));

        return $result !== false;
    }

    /**
     * Elimina una categoría
     *
     * @param int $id ID de la categoría
     * @return bool|WP_Error
     */
    public function delete($id)
    {
        global $wpdb;

        $id = intval($id);
        $category = $this->get_by_id($id);

        if (!$category) {
            return new WP_Error(
                'category_not_found',
                __('Categoría no encontrada', 'cna-subscriptions')
            );
        }

        // Limpiar referencias en cna_product postmeta
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} 
                WHERE meta_key = '_cna_product_category' 
                AND meta_value = %d",
                $id
            )
        );

        $result = $wpdb->delete($this->table_name, array('id' => $id), array('%d'));

        return $result !== false;
    }

    /**
     * Verifica si un slug ya existe
     *
     * @param string $slug Slug a verificar
     * @param int $exclude_id ID a excluir de la búsqueda
     * @return bool
     */
    public function slug_exists($slug, $exclude_id = 0)
    {
        global $wpdb;

        $slug = sanitize_text_field($slug);

        if ($exclude_id > 0) {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM " . $this->table_name . " WHERE slug = %s AND id != %d",
                $slug,
                intval($exclude_id)
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM " . $this->table_name . " WHERE slug = %s",
                $slug
            );
        }

        return intval($wpdb->get_var($query)) > 0;
    }

    /**
     * Obtiene el nombre de una categoría por ID
     *
     * @param int $category_id ID de la categoría
     * @return string Nombre de la categoría
     */
    public function get_name($category_id)
    {
        $category = $this->get_by_id($category_id);
        return $category ? $category['name'] : '';
    }

    /**
     * Reordena las categorías
     *
     * @param array $order Array de IDs en nuevo orden
     * @return bool
     */
    public function reorder($order)
    {
        global $wpdb;

        foreach ($order as $index => $id) {
            $wpdb->update(
                $this->table_name,
                array('display_order' => $index),
                array('id' => intval($id)),
                array('%d'),
                array('%d')
            );
        }

        return true;
    }
}
