<?php
/**
 * Plugin Name: CNA Subscriptions
 * Description: Sistema de suscripciones y entregas a medida.
 * Version: 1.0.0
 * Author: Tu Nombre
 * Text Domain: cna-subscriptions
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('CNA_SUBSCRIPTIONS_VERSION', '1.0.0');
define('CNA_SUBSCRIPTIONS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CNA_SUBSCRIPTIONS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CNA_SUBSCRIPTIONS_PLUGIN_FILE', __FILE__);

/**
 * Autoloader simple para las clases del plugin
 */
spl_autoload_register(function ($class) {
    // Solo cargar clases que empiecen con CNA_
    if (strpos($class, 'CNA_') !== 0) {
        return;
    }

    // Convertir nombre de clase a nombre de archivo
    // CNA_Assets -> class-cna-assets.php
    // CNA_REST_Controller -> class-cna-rest-controller.php
    $class_name = str_replace('CNA_', '', $class);
    $class_name = str_replace('_', '-', strtolower($class_name));
    $file_name = 'class-' . $class_name . '.php';

    // Buscar en las carpetas principales
    $directories = array(
        CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Core/',
        CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Admin/',
        CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/API/',
        CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Model/',
    );

    foreach ($directories as $directory) {
        $file_path = $directory . $file_name;
        if (file_exists($file_path)) {
            require_once $file_path;
            return;
        }
    }
}, true, true); // true, true para agregar al inicio de la cola y lanzar excepciones

/**
 * Hooks de activación y desactivación
 * Nota: Cargamos el activador directamente para evitar problemas de autoloader
 */
require_once CNA_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/Core/class-cna-activator.php';
register_activation_hook(CNA_SUBSCRIPTIONS_PLUGIN_FILE, array('CNA_Activator', 'activate'));
register_deactivation_hook(CNA_SUBSCRIPTIONS_PLUGIN_FILE, array('CNA_Activator', 'deactivate'));

/**
 * Carga las clases principales del plugin
 * Función helper para asegurar que las clases estén disponibles
 */
function cna_subscriptions_load_classes()
{
    $classes = array(
        'CNA_Assets' => 'includes/Core/class-cna-assets.php',
        'CNA_Migrator' => 'includes/Core/class-cna-migrator.php',
        'CNA_Post_Type' => 'includes/Admin/class-cna-post-type.php',
        'CNA_Settings' => 'includes/Admin/class-cna-settings.php',
        'CNA_Subscriptions_Admin' => 'includes/Admin/class-cna-subscriptions-admin.php',
        'CNA_Categories' => 'includes/Admin/class-cna-categories.php',
        'CNA_Mailer' => 'includes/Mailer/class-cna-mailer.php',
        'CNA_REST_Controller' => 'includes/API/class-cna-rest.php',
        'CNA_Cron' => 'includes/Core/class-cna-cron.php',
        'CNA_Scheduler' => 'includes/Model/class-cna-scheduler.php',
        'CNA_Locations_Helper' => 'includes/Model/class-cna-locations-helper.php',
        'CNA_Pagadito_Client' => 'includes/API/class-cna-pagadito-client.php',
        'CNA_Product_Helper' => 'includes/Model/class-cna-product-helper.php',
        'CNA_Payment_Helper' => 'includes/Model/class-cna-payment-helper.php',
        'CNA_Audit_Logger' => 'includes/Model/class-cna-audit-logger.php',
        'CNA_Token_Encryption' => 'includes/Model/class-cna-token-encryption.php',
    );

    foreach ($classes as $class_name => $file_path) {
        if (!class_exists($class_name)) {
            $full_path = CNA_SUBSCRIPTIONS_PLUGIN_DIR . $file_path;
            if (file_exists($full_path)) {
                require_once $full_path;
            }
        }
    }
}

/**
 * Inicializa el plugin
 */
function cna_subscriptions_init()
{
    // Cargar clases manualmente como respaldo
    cna_subscriptions_load_classes();

    // Cargar la clase de assets (Bridge PHP)
    if (class_exists('CNA_Assets')) {
        $assets = new CNA_Assets();
        $assets->init();
    }

    // Registrar Custom Post Type
    if (class_exists('CNA_Post_Type')) {
        $post_type = new CNA_Post_Type();
        $post_type->init();
    }

    // Inicializar página de Ajustes (solo en admin)
    if (is_admin() && class_exists('CNA_Settings')) {
        $settings = new CNA_Settings();
        $settings->init();
    }

    if (is_admin() && class_exists('CNA_Subscriptions_Admin')) {
        $subscriptions_admin = new CNA_Subscriptions_Admin();
        $subscriptions_admin->init();
    }

    // Registrar endpoints REST API
    if (class_exists('CNA_REST_Controller')) {
        $rest_controller = new CNA_REST_Controller();
        $rest_controller->init();
    }

    // Inicializar sistema de Cron para renovaciones
    if (class_exists('CNA_Cron')) {
        $cron = new CNA_Cron();
        $cron->init();
    }
}
add_action('plugins_loaded', 'cna_subscriptions_init');

/**
 * Shortcode para el wizard de checkout
 * Uso: [cna_checkout]
 */
function cna_checkout_shortcode($atts)
{
    $user_id = get_current_user_id();
    if ($user_id === 0) {
        return '<p>' . __('Debes estar autenticado para finalizar tu suscripción.', 'cna-subscriptions') . '</p>';
    }

    return '<div id="cna-checkout-app"></div><span id="cna-user-id" style="display:none;">' . esc_html($user_id) . '</span>';
}
add_shortcode('cna_checkout', 'cna_checkout_shortcode');

/**
 * Shortcode para el dashboard de Mi Cuenta
 * Uso: [cna_my_account]
 */
function cna_my_account_shortcode($atts)
{
    $user_id = get_current_user_id();
    if ($user_id === 0) {
        return '<p>' . __('Debes estar autenticado para ver tus suscripciones.', 'cna-subscriptions') . '</p>';
    }

    return '<div id="cna-my-account"></div><span id="cna-user-id" style="display:none;">' . esc_html($user_id) . '</span>';
}
add_shortcode('cna_my_account', 'cna_my_account_shortcode');

/**
 * Shortcode para confirmación de orden
 * Uso: [cna_order_confirmation]
 */
function cna_order_confirmation_shortcode($atts)
{
    $user_id = get_current_user_id();
    if ($user_id === 0) {
        return '<p>' . __('Debes estar autenticado para ver la confirmación de tu orden.', 'cna-subscriptions') . '</p>';
    }

    return '<div id="cna-order-confirmation"></div><span id="cna-user-id" style="display:none;">' . esc_html($user_id) . '</span>';
}
add_shortcode('cna_order_confirmation', 'cna_order_confirmation_shortcode');

/**
 * Shortcode para página de login interna
 * Uso: [cna_login]
 */
function cna_login_shortcode($atts)
{
    // No ejecutar redirects durante peticiones AJAX o REST API
    if (wp_doing_ajax() || defined('REST_REQUEST') && REST_REQUEST) {
        return '';
    }

    // Si ya está autenticado, redirigir solo si no es AJAX
    if (is_user_logged_in() && !wp_doing_ajax()) {
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : home_url();
        // Solo redirigir si estamos en el frontend, no durante guardado de posts
        if (!is_admin() && !wp_doing_ajax()) {
            wp_safe_redirect($redirect_to);
            exit;
        }
        return '<p>' . __('Ya estás autenticado.', 'cna-subscriptions') . '</p>';
    }

    $atts = shortcode_atts(array(
        'redirect_to' => isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : home_url(),
    ), $atts);

    $login_errors = '';
    $login_message = '';

    // Procesar login si se envió el formulario (solo en frontend, no durante AJAX)
    if (isset($_POST['cna_login_submit']) && !wp_doing_ajax()) {
        $username = isset($_POST['log']) ? sanitize_user($_POST['log']) : '';
        $password = isset($_POST['pwd']) ? $_POST['pwd'] : '';
        $remember = isset($_POST['rememberme']) ? true : false;

        if (empty($username) || empty($password)) {
            $login_errors = __('Por favor, completa todos los campos.', 'cna-subscriptions');
        } else {
            $creds = array(
                'user_login' => $username,
                'user_password' => $password,
                'remember' => $remember,
            );

            $user = wp_signon($creds, false);

            if (is_wp_error($user)) {
                $login_errors = $user->get_error_message();
            } else {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID, $remember);
                // Solo redirigir si no es AJAX
                if (!wp_doing_ajax()) {
                    wp_safe_redirect($atts['redirect_to']);
                    exit;
                }
            }
        }
    }

    // Mostrar mensajes de registro si vienen de la URL
    if (isset($_GET['registered']) && $_GET['registered'] === 'true') {
        $login_message = __('Registro exitoso. Por favor, inicia sesión.', 'cna-subscriptions');
    }

    ob_start();
    ?>
    <div class="cna-login-page"
        style="max-width: 450px; margin: 2rem auto; padding: 2rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="text-align: center; margin-bottom: 1.5rem;"><?php _e('Iniciar Sesión', 'cna-subscriptions'); ?></h2>

        <?php if ($login_message): ?>
            <div class="cna-login-message"
                style="padding: 0.75rem; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724; margin-bottom: 1rem;">
                <?php echo esc_html($login_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($login_errors): ?>
            <div class="cna-login-error"
                style="padding: 0.75rem; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24; margin-bottom: 1rem;">
                <?php echo esc_html($login_errors); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" class="cna-login-form">
            <p>
                <label for="cna_user_login" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">
                    <?php _e('Correo electrónico o Usuario', 'cna-subscriptions'); ?>
                </label>
                <input type="text" name="log" id="cna_user_login" class="input"
                    value="<?php echo isset($_POST['log']) ? esc_attr($_POST['log']) : ''; ?>" required
                    style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;" />
            </p>

            <p>
                <label for="cna_user_pass" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">
                    <?php _e('Contraseña', 'cna-subscriptions'); ?>
                </label>
                <input type="password" name="pwd" id="cna_user_pass" class="input" required
                    style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;" />
            </p>

            <p>
                <label style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" name="rememberme" id="cna_rememberme" value="forever" />
                    <?php _e('Recordarme', 'cna-subscriptions'); ?>
                </label>
            </p>

            <p>
                <input type="submit" name="cna_login_submit" class="button button-primary"
                    value="<?php esc_attr_e('Iniciar Sesión', 'cna-subscriptions'); ?>"
                    style="width: 100%; padding: 0.75rem; font-size: 1rem;" />
            </p>

            <p style="text-align: center; margin-top: 1rem;">
                <a href="<?php echo esc_url(wp_lostpassword_url()); ?>">
                    <?php _e('¿Olvidaste tu contraseña?', 'cna-subscriptions'); ?>
                </a>
            </p>

            <p style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #ddd;">
                <?php _e('¿No tienes cuenta?', 'cna-subscriptions'); ?>
                <a href="<?php echo esc_url(wp_registration_url()); ?>">
                    <?php _e('Regístrate aquí', 'cna-subscriptions'); ?>
                </a>
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('cna_login', 'cna_login_shortcode');