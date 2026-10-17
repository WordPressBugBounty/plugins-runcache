<?php

/**
 * Shared Rules dashboard defaults.
 *
 * This file is the single source of truth for both the configurable
 * dashboard rules and the hardcoded (mandatory) exclusion rules.
 *
 * It is structured under two top-level keys:
 *   - 'hardcoded': mandatory safety exclusions (always active, using the same
 *     feature-family keys as the dashboard rules).
 *   - 'rules': the flat, configurable dashboard rules (toggles + match lists).
 *
 * This file must remain independent of WordPress because it is loaded by the
 * early page-cache configuration loader before WordPress is available.
 *
 * @return array{
 *   hardcoded: array<string, list<string>>,
 *   rules: array<string, mixed>,
 * }
 */
return [
    'hardcoded' => [
        'exclude_url' => [
            '/.well-known',
            '/wp-admin',
            '/wp-login.php',
            '/wp-cron.php',
            '/wp-signup.php',
            '/wp-activate.php',
            '/wp-comments-post.php',
            '/wp-json',
            '/xmlrpc.php',
            '/feed',
            '/index.php/feed',
            '*sitemap*.xml',
            // Anchored: plain '/cart' also matched '/product/cartier-...'.
            '^/cart(/|$)',
            '^/checkout(/|$)',
            '^/my-account(/|$)',
        ],
        'exclude_cookie' => [
            'wordpress_logged_in',
            'wordpress_sec_',
            'wp-postpass',
            'comment_author',
            'wp-settings-',
        ],
        'exclude_query' => [
            'feed',
        ],
    ],
    'rules' => [
        'exclude_url_onn' => true,
        'exclude_url_mch' => [
            'index.php',
            '/login*',
            '/wp-json/',
            '/feed/',
            '*sitemap*.xml',
        ],
        'exclude_cookie_onn' => true,
        'exclude_cookie_mch' => [
            'wordpress_sec_[a-f0-9]+',
            'woocommerce_cart_hash',
            'woocommerce_items_in_cart',
            'wp_woocommerce_session',
            'wc_cart_hash',
            'edd_items_in_cart',
            'wp-customize',
            'customize_changeset_uuid',
            'woocommerce_recently_viewed',
        ],
        'exclude_auto_onn' => true,
        'exclude_browser_onn' => false,
        'exclude_browser_mch' => [
            'bot',
            'crawl',
            'spider',
            'slurp',
        ],
        'exclude_visitorip_onn' => false,
        'exclude_visitorip_mch' => [],
        'ignore_query_onn' => true,
        'ignore_query_mch' => [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'gclid',
            'fbclid',
            'msclkid',
            'dclid',
        ],
        'allow_query_onn' => false,
        'allow_query_mch' => [],
        'exclude_query_onn' => true,
        'exclude_query_mch' => [
            'preview',
            'customize_changeset_uuid',
            'wc-ajax',
            'add-to-cart',
            'wc_api',
            'edd_action',
            's=',
            'bricks',
            'et_fb',
            'ct_builder',
            'fl_builder',
            'vc_editable',
            'brizy-edit',
            'tve',
            'elementor-preview',
        ],
        'cache_key_extra_onn' => false,
        'cache_key_extra_var' => '',
    ],
];
