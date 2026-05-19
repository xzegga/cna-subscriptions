<?php
/**
 * Shortcodes y redirecciones para páginas de login y checkout.
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CNA_Pages
{
    const LOGIN_SLUG = 'iniciar-sesion';
    const CHECKOUT_SLUG = 'finalizar-suscripcion';

    public function init()
    {
        add_shortcode('cna_login', array($this, 'render_login_shortcode'));
        add_shortcode('cna_checkout', array($this, 'render_checkout_shortcode'));

        add_filter('login_redirect', array($this, 'filter_login_redirect'), 10, 3);
        add_filter('body_class', array($this, 'add_login_page_body_class'));
        add_action('template_redirect', array($this, 'redirect_logged_in_from_login_page'));
        add_action('template_redirect', array($this, 'guard_checkout_page'));
    }

    /**
     * Body class para estilos de login y checkout (integración con el tema).
     *
     * @param string[] $classes
     * @return string[]
     */
    public function add_login_page_body_class($classes)
    {
        if (is_page(self::LOGIN_SLUG)) {
            $classes[] = 'cna-has-login-app';
        }

        if (is_page(self::CHECKOUT_SLUG)) {
            $classes[] = 'cna-has-checkout-app';
        }

        return $classes;
    }

    /**
     * Convierte redirect_to relativo en URL absoluta del sitio.
     *
     * @param string $redirect_to
     * @return string
     */
    public static function normalize_redirect_url($redirect_to)
    {
        $redirect_to = wp_unslash((string) $redirect_to);
        $redirect_to = trim($redirect_to);

        if ($redirect_to === '') {
            return '';
        }

        if (strpos($redirect_to, '/') === 0 && strpos($redirect_to, '//') !== 0) {
            return home_url($redirect_to);
        }

        return $redirect_to;
    }

    /**
     * URL de checkout con barra final consistente.
     *
     * @return string
     */
    public static function get_checkout_url()
    {
        return home_url('/' . self::CHECKOUT_SLUG . '/');
    }

    /**
     * URL de login con redirect_to opcional.
     *
     * @param string $redirect_to
     * @return string
     */
    public static function get_login_url($redirect_to = '')
    {
        $url = home_url('/' . self::LOGIN_SLUG . '/');
        if ($redirect_to !== '') {
            $url = add_query_arg('redirect_to', rawurlencode(self::normalize_redirect_url($redirect_to)), $url);
        }
        return $url;
    }

    /**
     * @param string $redirect_to
     * @param string $requested_redirect_to
     * @param WP_User|WP_Error $user
     * @return string
     */
    public function filter_login_redirect($redirect_to, $requested_redirect_to, $user)
    {
        if (is_wp_error($user) || empty($requested_redirect_to)) {
            return $redirect_to;
        }

        $normalized = self::normalize_redirect_url($requested_redirect_to);
        $validated = wp_validate_redirect($normalized, home_url('/mi-cuenta/'));

        return $validated ? $validated : $redirect_to;
    }

    /**
     * Si el usuario ya tiene sesión, no dejarlo en la página de login vacía.
     */
    public function redirect_logged_in_from_login_page()
    {
        if (!is_user_logged_in() || !is_page(self::LOGIN_SLUG)) {
            return;
        }

        $redirect = isset($_GET['redirect_to']) ? self::normalize_redirect_url($_GET['redirect_to']) : '';
        if ($redirect === '') {
            $redirect = self::get_checkout_url();
        }

        $validated = wp_validate_redirect($redirect, home_url('/mi-cuenta/'));
        if ($validated) {
            wp_safe_redirect($validated);
            exit;
        }
    }

    /**
     * Protege la página de checkout: requiere sesión activa.
     */
    public function guard_checkout_page()
    {
        if (!is_page(self::CHECKOUT_SLUG) || is_user_logged_in()) {
            return;
        }

        wp_safe_redirect(self::get_login_url(self::get_checkout_url()));
        exit;
    }

    /**
     * Shortcode [cna_login]: monta el formulario React de autenticación.
     *
     * @return string
     */
    public function render_login_shortcode()
    {
        if (is_user_logged_in()) {
            $redirect = isset($_GET['redirect_to'])
                ? self::normalize_redirect_url($_GET['redirect_to'])
                : self::get_checkout_url();
            $validated = wp_validate_redirect($redirect, home_url('/mi-cuenta/'));
            if ($validated) {
                wp_safe_redirect($validated);
                exit;
            }
        }

        $redirect_to = isset($_GET['redirect_to'])
            ? esc_attr(self::normalize_redirect_url($_GET['redirect_to']))
            : esc_attr(self::get_checkout_url());

        return '<div id="cna-login-app" data-redirect-to="' . $redirect_to . '"></div>';
    }

    /**
     * Shortcode [cna_checkout]: monta el wizard de checkout para usuarios autenticados.
     *
     * @return string
     */
    public function render_checkout_shortcode()
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Debes iniciar sesión para continuar.', 'cna-subscriptions') . '</p>';
        }

        $user_id = get_current_user_id();
        ob_start();
        ?>
        <span id="cna-user-id" style="display:none;"><?php echo esc_html((string) $user_id); ?></span>
        <div id="cna-checkout-app"></div>
        <?php
        return ob_get_clean();
    }
}
