<?php
/**
 * Template Minimalista para single-cna_product
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

get_header();

$product_id = get_the_ID();
$variations = CNA_Product_Helper::get_product_variations($product_id);
$annual_fee = CNA_Product_Helper::get_annual_fee($product_id);

// Obtener frecuencias múltiples
$frequencies_json = get_post_meta($product_id, '_cna_product_frequencies', true);
$frequencies = array();
if (!empty($frequencies_json)) {
    $frequencies = json_decode($frequencies_json, true);
    if (!is_array($frequencies)) {
        $frequencies = array();
    }
}

// Si no hay frecuencias, crear una por defecto
if (empty($frequencies)) {
    $frequencies = array(
        array('amount' => '1', 'unit' => 'weeks', 'label' => 'Cada semana', 'weeks' => 1)
    );
}
?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <article id="post-<?php the_ID(); ?>" <?php post_class('cna-product-minimal'); ?>>
            <div class="entry-content" style="max-width: 800px; margin: 0 auto; padding: 2rem;">
                <header class="entry-header" style="text-align: center; margin-bottom: 2rem;">
                    <?php the_title('<h1 class="entry-title" style="font-size: 2rem; margin-bottom: 1rem;">', '</h1>'); ?>
                </header>

                <?php
                if (has_post_thumbnail()) {
                    echo '<div style="margin-bottom: 2rem; text-align: center;">';
                    the_post_thumbnail('large', array('class' => 'cna-product-image', 'style' => 'max-width: 100%; height: auto;'));
                    echo '</div>';
                }
                ?>

                <div class="cna-product-description" style="margin-bottom: 2rem; line-height: 1.6;">
                    <?php the_content(); ?>
                </div>

                <!-- React Island: ProductConfigurator -->
                <div
                    id="cna-product-app"
                    data-product-id="<?php echo esc_attr($product_id); ?>"
                    data-variations="<?php echo esc_attr(wp_json_encode($variations, JSON_UNESCAPED_UNICODE)); ?>"
                    data-annual-fee="<?php echo esc_attr($annual_fee); ?>"
                    data-frequencies="<?php echo esc_attr(wp_json_encode($frequencies, JSON_UNESCAPED_UNICODE)); ?>"
                ></div>
                <?php
                // Agregar user ID para verificación de autenticación
                $current_user_id = get_current_user_id();
                if ($current_user_id > 0) {
                    echo '<span id="cna-user-id" style="display:none;">' . esc_html($current_user_id) . '</span>';
                }
                ?>
            </div>
        </article>
    </main>
</div>

<?php
get_footer();
