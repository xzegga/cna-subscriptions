<?php
/**
 * Template Destacado para single-cna_product
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
$min_qty = CNA_Product_Helper::get_min_qty($product_id);

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
        <article id="post-<?php the_ID(); ?>" <?php post_class('cna-product-featured'); ?>>
            <?php if (has_post_thumbnail()): ?>
                <div class="cna-featured-image" style="width: 100%; height: 400px; overflow: hidden; margin-bottom: 2rem; position: relative;">
                    <?php 
                    the_post_thumbnail('full', array('style' => 'width: 100%; height: 100%; object-fit: cover;'));
                    ?>
                    <div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(transparent, rgba(0,0,0,0.7)); padding: 3rem 2rem 2rem;">
                        <?php the_title('<h1 class="entry-title" style="color: #fff; font-size: 2.5rem; margin: 0;">', '</h1>'); ?>
                    </div>
                </div>
            <?php else: ?>
                <header class="entry-header" style="text-align: center; padding: 3rem 2rem;">
                    <?php the_title('<h1 class="entry-title" style="font-size: 2.5rem; margin-bottom: 1rem;">', '</h1>'); ?>
                </header>
            <?php endif; ?>

            <div class="entry-content" style="max-width: 1000px; margin: 0 auto; padding: 0 2rem 3rem;">
                <div class="cna-product-description" style="margin-bottom: 2rem; font-size: 1.1rem; line-height: 1.8;">
                    <?php the_content(); ?>
                </div>

                <!-- React Island: ProductConfigurator -->
                <div
                    id="cna-product-app"
                    data-product-id="<?php echo esc_attr($product_id); ?>"
                    data-variations="<?php echo esc_attr(wp_json_encode($variations, JSON_UNESCAPED_UNICODE)); ?>"
                    data-annual-fee="<?php echo esc_attr($annual_fee); ?>"
                    data-frequencies="<?php echo esc_attr(wp_json_encode($frequencies, JSON_UNESCAPED_UNICODE)); ?>"
                    data-min-qty="<?php echo esc_attr($min_qty); ?>"
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
