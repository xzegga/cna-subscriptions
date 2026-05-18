<?php
/**
 * Clase Bridge para manejar el encolado de assets (DEV/PROD)
 * 
 * Detecta automáticamente si estamos en modo desarrollo (Vite dev server)
 * o en producción (archivos compilados con manifest.json)
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Assets {

    /**
     * Ruta base del plugin
     *
     * @var string
     */
    private $plugin_dir;

    /**
     * URL base del plugin
     *
     * @var string
     */
    private $plugin_url;

    /**
     * Puerto del servidor de desarrollo Vite
     *
     * @var int
     */
    private $vite_port = 5173;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_dir = plugin_dir_path(dirname(dirname(__FILE__)));
        $this->plugin_url = plugin_dir_url(dirname(dirname(__FILE__)));
    }

    /**
     * Inicializa los hooks de WordPress
     */
    public function init() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Encola los scripts de React según el modo (DEV/PROD)
     */
    public function enqueue_scripts() {
        if ($this->is_dev_mode()) {
            $this->enqueue_dev_assets();
        } else {
            $this->enqueue_prod_assets();
        }
    }

    /**
     * Detecta si estamos en modo desarrollo
     *
     * @return bool
     */
    private function is_dev_mode() {
        // Opción 1: Verificar si existe la constante CNA_DEV_MODE en wp-config.php
        if (defined('CNA_DEV_MODE') && CNA_DEV_MODE === true) {
            return true;
        }

        // Opción 2: Verificar si el manifest.json no existe
        // Vite genera el manifest en assets/.vite/manifest.json
        $manifest_path = $this->plugin_dir . 'assets/.vite/manifest.json';
        if (!file_exists($manifest_path)) {
            return true;
        }

        // Opción 3: Intentar conectar al servidor de desarrollo (opcional, más lento)
        // $dev_server = 'http://localhost:' . $this->vite_port;
        // $response = @wp_remote_get($dev_server . '/@vite/client', array('timeout' => 1));
        // if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        //     return true;
        // }

        return false;
    }

    /**
     * Encola assets en modo desarrollo (Vite dev server)
     */
    private function enqueue_dev_assets() {
        $dev_server = 'http://localhost:' . $this->vite_port;

        // Cargar el cliente de Vite para HMR (Hot Module Replacement)
        wp_enqueue_script(
            'cna-vite-client',
            $dev_server . '/@vite/client',
            array(),
            null,
            true
        );

        // Cargar el entry point de React (Vite lo compila al vuelo)
        wp_enqueue_script(
            'cna-react-app',
            $dev_server . '/src/main.tsx',
            array('cna-vite-client'),
            null,
            true
        );

        // Localizar script con datos de WordPress (nonce, etc.)
        wp_localize_script(
            'cna-react-app',
            'wpApiSettings',
            array(
                'nonce' => wp_create_nonce('wp_rest'),
                'restUrl' => rest_url('cna/v1/'),
            )
        );

        // Tipo módulo ES para que funcione correctamente
        add_filter('script_loader_tag', array($this, 'add_module_type'), 10, 3);
    }

    /**
     * Encola assets en modo producción (archivos compilados)
     */
    private function enqueue_prod_assets() {
        // Vite genera el manifest.json en assets/.vite/manifest.json
        $manifest_path = $this->plugin_dir . 'assets/.vite/manifest.json';

        if (!file_exists($manifest_path)) {
            error_log('CNA Subscriptions: manifest.json no encontrado en ' . $manifest_path);
            return;
        }

        $manifest = json_decode(file_get_contents($manifest_path), true);

        if (!$manifest || !isset($manifest['src/main.tsx'])) {
            error_log('CNA Subscriptions: manifest.json inválido o entrada src/main.tsx no encontrada');
            return;
        }

        $entry = $manifest['src/main.tsx'];

        // Encolar el archivo JavaScript compilado
        if (isset($entry['file'])) {
            wp_enqueue_script(
                'cna-react-app',
                $this->plugin_url . 'assets/' . $entry['file'],
                array(),
                CNA_SUBSCRIPTIONS_VERSION,
                true
            );
        }

        // Encolar los archivos CSS compilados (si existen)
        if (isset($entry['css']) && is_array($entry['css'])) {
            foreach ($entry['css'] as $css_file) {
                wp_enqueue_style(
                    'cna-react-style',
                    $this->plugin_url . 'assets/' . $css_file,
                    array(),
                    CNA_SUBSCRIPTIONS_VERSION
                );
            }
        }

        // Localizar script con datos de WordPress (nonce, etc.)
        wp_localize_script(
            'cna-react-app',
            'wpApiSettings',
            array(
                'nonce' => wp_create_nonce('wp_rest'),
                'restUrl' => rest_url('cna/v1/'),
            )
        );
    }

    /**
     * Agrega el atributo type="module" a los scripts en modo desarrollo
     *
     * @param string $tag    HTML del script
     * @param string $handle Handle del script
     * @param string $src    URL del script
     * @return string
     */
    public function add_module_type($tag, $handle, $src) {
        if (in_array($handle, array('cna-vite-client', 'cna-react-app'))) {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        }
        return $tag;
    }
}
