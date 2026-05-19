<?php
/**
 * Assets compartidos del admin (modales, etc.)
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Admin_Assets {

    /**
     * Inicializa hooks.
     */
    public function init() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Encola modal en pantallas del plugin.
     *
     * @param string $hook
     */
    public function enqueue_scripts($hook) {
        if (!$this->should_enqueue($hook)) {
            return;
        }

        $plugin_url = plugin_dir_url(CNA_SUBSCRIPTIONS_PLUGIN_FILE);
        $version = CNA_SUBSCRIPTIONS_VERSION;

        wp_enqueue_style(
            'cna-admin-modal',
            $plugin_url . 'assets/admin/cna-admin-modal.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'cna-admin-modal',
            $plugin_url . 'assets/admin/cna-admin-modal.js',
            array('jquery'),
            $version,
            true
        );

        wp_localize_script(
            'cna-admin-modal',
            'cnaAdminModalL10n',
            array(
                'cancel' => __('Cancelar', 'cna-subscriptions'),
                'confirm' => __('Confirmar', 'cna-subscriptions'),
                'accept' => __('Aceptar', 'cna-subscriptions'),
                'confirmTitle' => __('Confirmar acción', 'cna-subscriptions'),
                'noticeTitle' => __('Aviso', 'cna-subscriptions'),
                'successTitle' => __('Éxito', 'cna-subscriptions'),
                'errorTitle' => __('Error', 'cna-subscriptions'),
                'processing' => __('Procesando...', 'cna-subscriptions'),
                'confirmDefault' => __('¿Deseas continuar?', 'cna-subscriptions'),
                'connectionError' => __('Error de conexión', 'cna-subscriptions'),
            )
        );
    }

    /**
     * @param string $hook
     * @return bool
     */
    private function should_enqueue($hook) {
        if (strpos($hook, 'cna_product') !== false) {
            return true;
        }

        global $post_type;
        if (($hook === 'post.php' || $hook === 'post-new.php') && $post_type === 'cna_product') {
            return true;
        }

        return false;
    }
}
