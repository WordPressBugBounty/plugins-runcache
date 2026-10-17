<?php

namespace RunCache\Integrations;

use RunCache\Core\Purger;
use RunCache\Core\Setting;
use RunCache\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce integration handler
 */
class WooCommerce
{
    private Purger $purger;
    private array $default_settings = [];

    public function __construct(?Logger $logger = null, ?Setting $setting = null)
    {
        $this->purger = new Purger($logger, null, $setting);
    }

    /**
     * Initialize integration
     *
     * @return void
     */
    public function init(): void
    {
        $this->default_settings = $this->purger->get_settings();

        if ($this->default_settings['content_publish_onn']) {
            add_action('woocommerce_product_set_stock', [$this, 'purge_woo_product']);
            add_action('woocommerce_variation_set_stock', [$this, 'purge_woo_product']);
            add_action('woocommerce_rest_insert_product_object', [$this, 'purge_woo_product']);
            add_action('woocommerce_save_product_variation', [$this, 'purge_woo_product_variation']);
        }
    }

    /**
     * Purge WooCommerce product cache.
     *
     * Stock hooks pass a WC_Product object, while some integrations may pass
     * the product ID directly.
     *
     * @param mixed $product Product object or product ID.
     * @return void
     */
    public function purge_woo_product($product): void
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $product_id = (int) $product->get_id();
        } else {
            $product_id = (int) $product;
        }

        if ($product_id > 0) {
            $this->purger->purge_post($product_id);
        }
    }

    /**
     * Purge WooCommerce product variation cache
     *
     * @param int $variation_id Variation ID
     * @return void
     */
    public function purge_woo_product_variation(int $variation_id): void
    {
        $product_id = wp_get_post_parent_id($variation_id);

        $this->purger->purge_post($product_id);
    }
}
