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
            return;
        }

        if ($this->enqueue_prod_assets()) {
            return;
        }

        error_log('CNA Subscriptions: no se encontraron assets compilados ni modo dev disponible.');
    }

    /**
     * Modo desarrollo: solo cuando CNA_DEV_MODE está activo en wp-config.php.
     * No inferir dev mode por ausencia de manifest (evita localhost:5173 en producción).
     *
     * @return bool
     */
    private function is_dev_mode() {
        return defined('CNA_DEV_MODE') && CNA_DEV_MODE === true;
    }

    /**
     * Ruta del manifest generado por Vite.
     *
     * @return string
     */
    private function get_manifest_path() {
        return $this->plugin_dir . 'assets/.vite/manifest.json';
    }

    /**
     * Resuelve el JS/CSS compilado cuando no hay manifest (p. ej. zip sin carpeta .vite).
     *
     * @return array{js: string, css: string}|null
     */
    private function resolve_compiled_assets_without_manifest() {
        $js_files = glob($this->plugin_dir . 'assets/js/main-*.js');
        if (empty($js_files)) {
            return null;
        }

        usort($js_files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $css_files = glob($this->plugin_dir . 'assets/css/main-*.css');
        usort($css_files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return array(
            'js' => 'js/' . basename($js_files[0]),
            'css' => !empty($css_files) ? 'css/' . basename($css_files[0]) : '',
        );
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
     *
     * @return bool True si se encolaron assets.
     */
    private function enqueue_prod_assets() {
        $js_file = '';
        $css_files = array();

        $manifest_path = $this->get_manifest_path();
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            if ($manifest && isset($manifest['src/main.tsx'])) {
                $entry = $manifest['src/main.tsx'];
                if (!empty($entry['file'])) {
                    $js_file = $entry['file'];
                }
                if (!empty($entry['css']) && is_array($entry['css'])) {
                    $css_files = $entry['css'];
                }
            } else {
                error_log('CNA Subscriptions: manifest.json inválido en ' . $manifest_path);
            }
        } else {
            error_log('CNA Subscriptions: manifest.json no encontrado en ' . $manifest_path . '; usando fallback por glob.');
            $fallback = $this->resolve_compiled_assets_without_manifest();
            if ($fallback) {
                $js_file = $fallback['js'];
                if ($fallback['css'] !== '') {
                    $css_files = array($fallback['css']);
                }
            }
        }

        if ($js_file === '') {
            return false;
        }

        wp_enqueue_script(
            'cna-react-app',
            $this->plugin_url . 'assets/' . $js_file,
            array(),
            CNA_SUBSCRIPTIONS_VERSION,
            true
        );

        foreach ($css_files as $css_file) {
            wp_enqueue_style(
                'cna-react-style',
                $this->plugin_url . 'assets/' . $css_file,
                array(),
                CNA_SUBSCRIPTIONS_VERSION
            );
        }

        wp_localize_script(
            'cna-react-app',
            'wpApiSettings',
            array(
                'nonce' => wp_create_nonce('wp_rest'),
                'restUrl' => rest_url('cna/v1/'),
            )
        );

        return true;
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
