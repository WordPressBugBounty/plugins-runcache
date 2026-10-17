<?php
/**
 * Plugin Name: RunCache – Full-Page, Object, and Edge Caching
 * Plugin URI: https://runcloud.io/runcache
 * Description: Supercharge your WordPress site with lightning-fast page loads. RunCache delivers instant page speeds through intelligent caching, automatic content updates, and simple one-click optimization.
 * Version: 1.2.0
 * Author: RunCloud
 * Author URI: https://runcloud.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: runcache
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 8.0
 * Network: true
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RUNCACHE_VERSION', '1.2.0');
define('RUNCACHE_PLUGIN_NAME', 'RunCache');
define('RUNCACHE_PLUGIN_FILE', __FILE__);
define('RUNCACHE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RUNCACHE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RUNCACHE_PLUGIN_BASENAME', plugin_basename(__FILE__));

if (!defined('RUNCACHE_CACHE_DIRECTORY')) {
    define('RUNCACHE_CACHE_DIRECTORY', WP_CONTENT_DIR . '/cache/runcache/');
}

// Generated per-site cache config, shared by all sites on multisite.
if (!defined('RUNCACHE_CONFIG_FILE')) {
    define('RUNCACHE_CONFIG_FILE', WP_CONTENT_DIR . '/runcache-config.php');
}

define('RUNCACHE_CACHE_TYPE_PHP', 'PHP');
define('RUNCACHE_CACHE_TYPE_APACHE', 'Apache');
define('RUNCACHE_CACHE_TYPE_NGINX', 'NGINX');
define('RUNCACHE_CACHE_TYPE_FASTCGI', 'NGINX FastCGI');
define('RUNCACHE_CACHE_TYPE_LITESPEED', 'LiteSpeed');
define('RUNCACHE_CACHE_TYPE_REDIS', 'Redis');
define('RUNCACHE_CACHE_TYPE_CLOUDFLARE', 'Cloudflare');
define('RUNCACHE_CACHE_TYPE_CLOUDFLARE_APO', 'Cloudflare APO');

// Debug mode - set to true to enable detailed logging
if (!defined('RUNCACHE_DEBUG')) {
    define('RUNCACHE_DEBUG', false);
}

// Autoloader for PHP classes (PSR-4 compliant)
spl_autoload_register(function ($class) {
    $prefix = 'RunCache\\';

    // Only handle our plugin classes using PHP 8.0+ syntax
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    // Convert namespace to file path
    $relative_class = substr($class, strlen($prefix));
    $file = RUNCACHE_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize the plugin
function runcache_init() {
    // WP core sends its own nonce for heartbeat pings; this is a read-only
    // gate that skips heavy initialization and processes no form data.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only heartbeat gate.
    $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
    if ('heartbeat' === $action) {
        // Skip RunCache initialization for heartbeat requests
        return;
    }

    // Initialize main plugin class
    (new \RunCache\RunCache())->init();
}

add_action('plugins_loaded', 'runcache_init');

// Register lifecycle hooks
\RunCache\RunCache::register_initial_hooks();
