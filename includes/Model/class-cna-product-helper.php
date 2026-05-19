<?php
/**
 * Helper para obtener información de productos
 * Funciones auxiliares para trabajar con productos de suscripción
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Product_Helper {

    /**
     * Obtiene las variaciones de un producto específico
     *
     * @param int $product_id ID del producto
     * @return array Array de variaciones con name, description, price, slug
     */
    public static function get_product_variations($product_id) {
        $variations_json = get_post_meta($product_id, '_cna_product_variations', true);
        
        if (empty($variations_json)) {
            return array();
        }
        
        // Decodificar JSON con soporte UTF-8
        $variations = json_decode($variations_json, true, 512, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Si falla, intentar sin el flag (compatibilidad)
            $variations = json_decode($variations_json, true);
        }
        
        if (!is_array($variations)) {
            return array();
        }

        return $variations;
    }

    /**
     * Obtiene el precio de una variación específica por su slug o nombre
     *
     * @param int $product_id ID del producto
     * @param string $variation_identifier Slug o nombre de la variación
     * @return float|false Precio o false si no se encuentra
     */
    public static function get_variation_price($product_id, $variation_identifier) {
        $variations = self::get_product_variations($product_id);
        
        foreach ($variations as $variation) {
            if ($variation['slug'] === $variation_identifier || 
                strtolower($variation['name']) === strtolower($variation_identifier)) {
                return floatval($variation['price']);
            }
        }

        return false;
    }

    /**
     * Obtiene una variación específica por su slug o nombre
     *
     * @param int $product_id ID del producto
     * @param string $variation_identifier Slug o nombre de la variación
     * @return array|false Datos de la variación o false si no se encuentra
     */
    public static function get_variation($product_id, $variation_identifier) {
        $variations = self::get_product_variations($product_id);
        
        foreach ($variations as $variation) {
            if ($variation['slug'] === $variation_identifier || 
                strtolower($variation['name']) === strtolower($variation_identifier)) {
                return $variation;
            }
        }

        return false;
    }

    /**
     * Verifica si un producto tiene variaciones
     *
     * @param int $product_id ID del producto
     * @return bool
     */
    public static function has_variations($product_id) {
        $variations = self::get_product_variations($product_id);
        return !empty($variations);
    }

    /**
     * Obtiene el Fee Anual de un producto
     *
     * @param int $product_id ID del producto
     * @return float
     */
    public static function get_annual_fee($product_id) {
        return floatval(get_post_meta($product_id, '_cna_annual_fee', true));
    }

    /**
     * Obtiene la cantidad mínima de entregas de un producto
     *
     * @param int $product_id ID del producto
     * @return int
     */
    public static function get_min_qty($product_id) {
        $min = intval(get_post_meta($product_id, '_cna_min_qty', true));
        return $min > 0 ? $min : 4;
    }
}
