<?php
/**
 * Template para single-cna_product
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
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <header class="entry-header">
                <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
            </header>

            <div class="entry-content">
                <div class="cna-product-layout">
                    <div class="cna-product-layout__media">
                        <?php
                        if (has_post_thumbnail()) {
                            the_post_thumbnail('large', array('class' => 'cna-product-image'));
                        }
                        ?>

                        <div class="cna-product-description">
                            <?php the_content(); ?>
                        </div>
                    </div>

                    <div class="cna-product-layout__config">
                        <!-- React Island: ProductConfigurator -->
                        <div
                            id="cna-product-app"
                            data-product-id="<?php echo esc_attr($product_id); ?>"
                            data-product-name="<?php echo esc_attr(get_the_title($product_id)); ?>"
                            data-product-image="<?php echo esc_attr(has_post_thumbnail($product_id) ? get_the_post_thumbnail_url($product_id, 'medium') : ''); ?>"
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
                </div>
            </div>
        </article>
    </main>
</div>

<?php
get_footer();
