<?php
/**
 * RunCache layout profile.
 *
 * Single source of layout facts (classic vs Bedrock). Every layout-
 * dependent value is read from here, never recomputed by consumers.
 *
 * @package RunCache
 */

declare(strict_types=1);

namespace RunCache\Support;

use RunCache\Integrations\Multisite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Layout profile for the active WordPress install.
 *
 * Detection is structural and runs once per request. The classic
 * layout is the safe default on any uncertain signal.
 */
final class Environment
{
    public const LAYOUT_CLASSIC = 'classic';
    public const LAYOUT_BEDROCK = 'bedrock';

    /**
     * Docroot-level endpoint that stays unprefixed on every layout.
     */
    private const WELL_KNOWN_ENDPOINT = '\.well-known';

    /**
     * Core endpoints that only exist under the admin/system URL prefix.
     *
     * @var string[]
     */
    private const ADMIN_ENDPOINTS = [
        'wp-admin',
        'wp-login\.php',
        'wp-cron\.php',
        'wp-signup\.php',
        'wp-activate\.php',
        'wp-comments-post\.php',
    ];

    /**
     * Front-end system endpoints that exist at the site root on both
     * layouts and directly under the core prefix on Bedrock.
     *
     * @var string[]
     */
    private const WP_SYSTEM_ENDPOINTS = [
        'wp-json',
        'xmlrpc\.php',
        'index\.php/feed',
        'feed/?',
        'wp-json/',
    ];

    /**
     * Shipped WooCommerce endpoints for the static no-cache floor.
     *
     * @var string[]
     */
    private const WOO_ENDPOINTS = [
        'cart',
        'checkout',
        'my-account',
    ];

    /**
     * Cached profile instance. Facts are computed once per request.
     *
     * @var Environment|null
     */
    private static ?Environment $instance = null;

    /**
     * Memoized child-site prefixes, so the site query runs once.
     *
     * @var array<string[]>|null
     */
    private ?array $child_prefix_cache = null;

    /**
     * Derived layout facts.
     *
     * @var array<string, mixed>
     */
    private array $facts;

    /**
     * Private constructor; use instance().
     */
    private function __construct()
    {
        $this->facts = $this->detect();
        $this->log_facts();
    }

    /**
     * Get the shared per-request profile instance.
     */
    public static function instance(): Environment
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get the detected layout name.
     */
    public function get_layout(): string
    {
        return $this->facts['layout'];
    }

    /**
     * Whether the active layout is Bedrock.
     */
    public function is_bedrock(): bool
    {
        return self::LAYOUT_BEDROCK === $this->facts['layout'];
    }

    /**
     * Real document root (filesystem, no trailing slash).
     */
    public function get_document_root(): string
    {
        return $this->facts['document_root'];
    }

    /**
     * Content directory URL path with leading slash, no trailing slash.
     */
    public function get_content_url_path(): string
    {
        return $this->facts['content_url_path'];
    }

    /**
     * Content directory (filesystem, no trailing slash).
     */
    public function get_content_dir(): string
    {
        return $this->facts['content_dir'];
    }

    /**
     * Admin/system URL prefix with leading and trailing slash.
     */
    public function get_admin_url_prefix(): string
    {
        return $this->facts['admin_url_prefix'];
    }

    /**
     * Config file WordPress actually loads (read-only fact).
     *
     * On Bedrock this is the do-not-edit stub; consumers must never
     * write it. Use get_writable_config_path() before any write.
     */
    public function get_wp_config_path(): ?string
    {
        return $this->facts['wp_config_path'];
    }

    /**
     * Config file the plugin may write, or null when none may be written.
     */
    public function get_writable_config_path(): ?string
    {
        return $this->facts['writable_config_path'];
    }

    /**
     * Path of the .htaccess file the web server actually reads.
     */
    public function get_htaccess_path(): string
    {
        return $this->facts['htaccess_path'];
    }

    /**
     * RunCache cache root (filesystem, no trailing slash).
     */
    public function get_cache_root(): string
    {
        return $this->facts['cache_root'];
    }

    /**
     * Admin/system exclusion regex body for server conf templates.
     *
     * Each core endpoint is prefixed individually so the pattern never
     * loosely matches content URLs under the core prefix.
     */
    public function get_admin_system_pattern(): string
    {
        return $this->facts['admin_system_pattern'];
    }

    /**
     * Front-end system exclusion regex body for server conf templates.
     */
    public function get_wp_system_pattern(): string
    {
        return $this->facts['wp_system_pattern'];
    }

    /**
     * Static no-cache floor regex body for the WooCommerce endpoints.
     *
     * On a subdirectory network the floor covers the main URL space and
     * every registered child-site path, so one artifact protects all
     * sites no matter which site ran the enable.
     */
    public function get_woo_floor_pattern(): string
    {
        return $this->facts['woo_floor_pattern'];
    }

    /**
     * Replace layout tokens plus caller extras in a conf template.
     *
     * Standard values are escaped for their target syntax. Callers own
     * the escaping of extra values.
     *
     * @param string                $template Template contents.
     * @param array<string, string> $extra    Extra token => value pairs.
     */
    public function substitute(string $template, array $extra = []): string
    {
        $tokens = [
            '{{CACHE_ROOT_FS}}' => $this->escape_conf_value($this->get_cache_root()),
            '{{CONTENT_URL_PATH}}' => $this->escape_conf_value($this->get_content_url_path()),
            '{{ADMIN_SYSTEM_PATTERN}}' => $this->get_admin_system_pattern(),
            '{{WP_SYSTEM_PATTERN}}' => $this->get_wp_system_pattern(),
            '{{WOO_FLOOR_PATTERN}}' => $this->get_woo_floor_pattern(),
            '{{DOCUMENT_ROOT}}' => $this->escape_conf_value($this->get_document_root()),
        ];

        return str_replace(array_keys($tokens), array_values($tokens), strtr($template, $extra));
    }

    /**
     * Detect the layout and derive every fact from WordPress constants.
     *
     * @return array<string, mixed>
     */
    private function detect(): array
    {
        $wp_dir = $this->normalize_path(ABSPATH);
        $content_dir = $this->normalize_path(WP_CONTENT_DIR);

        $is_bedrock = $this->detect_bedrock($wp_dir, $content_dir);

        $document_root = $is_bedrock ? dirname($wp_dir) : $wp_dir;
        $located_config = (new File())->locate_wp_config();
        $wp_config_path = is_string($located_config) ? $located_config : null;

        return [
            'layout' => $is_bedrock ? self::LAYOUT_BEDROCK : self::LAYOUT_CLASSIC,
            'document_root' => $document_root,
            'content_url_path' => $this->detect_content_url_path(),
            'content_dir' => $content_dir,
            'admin_url_prefix' => $this->detect_admin_url_prefix(),
            'wp_config_path' => $wp_config_path,
            // The Bedrock stub and site-owned config must never be written.
            'writable_config_path' => $is_bedrock ? null : $wp_config_path,
            'htaccess_path' => $document_root . '/.htaccess',
            'cache_root' => $this->detect_cache_root(),
            'admin_system_pattern' => $this->build_pattern(self::ADMIN_ENDPOINTS, true),
            'wp_system_pattern' => $this->build_wp_system_pattern($is_bedrock),
            'woo_floor_pattern' => $this->build_woo_floor_pattern(),
        ];
    }

    /**
     * Decide Bedrock from structure only: content dir outside the core
     * directory plus the Bedrock config stub. Anything else stays classic.
     *
     * @param string $wp_dir      Normalized core directory (ABSPATH).
     * @param string $content_dir Normalized content directory.
     */
    private function detect_bedrock(string $wp_dir, string $content_dir): bool
    {
        if ('' === $wp_dir || '' === $content_dir) {
            return false;
        }

        $content_inside_core = str_starts_with($content_dir . '/', $wp_dir . '/');
        if ($content_inside_core) {
            return false;
        }

        return $this->is_bedrock_stub($wp_dir);
    }

    /**
     * Check the config file one level above the core directory for the
     * Bedrock stub shape (autoload + config/application.php + wp-settings).
     *
     * @param string $wp_dir Normalized core directory (ABSPATH).
     */
    private function is_bedrock_stub(string $wp_dir): bool
    {
        $stub_path = dirname($wp_dir) . '/wp-config.php';
        $file = new File();
        if (!$file->exists($stub_path)) {
            return false;
        }

        $content = $file->read($stub_path);
        if (!is_string($content) || '' === $content) {
            return false;
        }

        $requires_application = str_contains($content, 'config/application.php');
        $requires_autoload = str_contains($content, 'vendor/autoload.php');
        $requires_settings = str_contains($content, 'wp-settings.php');

        return $requires_application || ($requires_autoload && $requires_settings);
    }

    /**
     * Derive the content URL path from WP_CONTENT_URL.
     */
    private function detect_content_url_path(): string
    {
        $path = wp_parse_url(WP_CONTENT_URL, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return '/wp-content';
        }

        $path = $this->escape_conf_value($path);

        return '' !== $path ? '/' . ltrim($path, '/') : '/wp-content';
    }

    /**
     * Derive the admin/system URL prefix from the site_url() path.
     */
    private function detect_admin_url_prefix(): string
    {
        $path = wp_parse_url(site_url(), PHP_URL_PATH);
        $path = is_string($path) ? trim($path, '/') : '';

        return '' === $path ? '/' : '/' . $path . '/';
    }

    /**
     * Derive the cache root from the plugin constant, with a constant-free
     * fallback for early contexts.
     */
    private function detect_cache_root(): string
    {
        if (defined('RUNCACHE_CACHE_DIRECTORY')) {
            return $this->normalize_path(RUNCACHE_CACHE_DIRECTORY);
        }

        return $this->normalize_path(WP_CONTENT_DIR) . '/cache/runcache';
    }

    /**
     * Build a regex body that covers every site on the install.
     *
     * A single site gets its own prefix only (the root form when the
     * site sits at the domain root). A subdirectory network gets the
     * root form plus every registered site prefix, so the artifact is
     * identical no matter which site ran the enable.
     *
     * @param string[] $endpoints   Regex endpoint bodies.
     * @param bool     $prefix_core Whether core endpoints get the prefix.
     */
    private function build_pattern(array $endpoints, bool $prefix_core): string
    {
        if (!$prefix_core) {
            return self::WELL_KNOWN_ENDPOINT . '|' . implode('|', $endpoints);
        }

        $network_prefixes = $this->network_site_prefixes();
        if ([] !== $network_prefixes) {
            return self::WELL_KNOWN_ENDPOINT . '|'
                . implode('|', $this->network_complete_forms($endpoints, $network_prefixes));
        }

        $prefix = trim(wp_parse_url(site_url(), PHP_URL_PATH) ?? '', '/');
        if ('' === $prefix) {
            return self::WELL_KNOWN_ENDPOINT . '|' . implode('|', $endpoints);
        }

        return self::WELL_KNOWN_ENDPOINT . '|' . implode('|', $this->prefixed_endpoints($endpoints, $prefix));
    }

    /**
     * Build the front-end system pattern. Root-level forms are kept on
     * both layouts so no existing exclusion is weakened. A subdirectory
     * network adds every registered site prefix, and Bedrock adds the
     * direct core-prefixed forms.
     *
     * @param bool $is_bedrock Whether the layout is Bedrock.
     */
    private function build_wp_system_pattern(bool $is_bedrock): string
    {
        $root_forms = implode('|', self::WP_SYSTEM_ENDPOINTS);

        $network_prefixes = $this->network_site_prefixes();
        if ([] !== $network_prefixes) {
            return implode('|', $this->network_complete_forms(self::WP_SYSTEM_ENDPOINTS, $network_prefixes));
        }

        if (!$is_bedrock) {
            return $root_forms;
        }

        $prefix = trim(wp_parse_url(site_url(), PHP_URL_PATH) ?? '', '/');
        if ('' === $prefix) {
            return $root_forms;
        }

        return $root_forms . '|' . implode('|', $this->prefixed_endpoints(self::WP_SYSTEM_ENDPOINTS, $prefix));
    }

    /**
     * Build the WooCommerce no-cache floor for server conf templates.
     *
     * A single site keeps the root forms only. A subdirectory network
     * adds every registered child-site prefix, so pre-existing cache
     * files under child paths are excluded at the server layer too.
     */
    private function build_woo_floor_pattern(): string
    {
        $network_prefixes = $this->network_site_prefixes();
        if ([] === $network_prefixes) {
            return implode('|', self::WOO_ENDPOINTS);
        }

        return implode('|', $this->network_complete_forms(self::WOO_ENDPOINTS, $network_prefixes));
    }

    /**
     * Join root forms and every site-prefixed form into one body.
     *
     * The root form always stays present, so an emission change can
     * never remove an exclusion that exists today.
     *
     * @param string[] $endpoints       Root-level endpoint bodies.
     * @param string[] $network_prefixes Raw site path prefixes, '' for the root.
     * @return string[] Unique endpoint forms.
     */
    private function network_complete_forms(array $endpoints, array $network_prefixes): array
    {
        $forms = $endpoints;
        foreach ($network_prefixes as $prefix) {
            $forms = array_merge($forms, $this->prefixed_endpoints($endpoints, $prefix));
        }

        return array_values(array_unique($forms));
    }

    /**
     * Prefix each endpoint body with one site path.
     *
     * An empty path keeps the root form. Slashes in the path are
     * escaped once, here, for the regex context of the templates.
     *
     * @param string[] $endpoints Endpoint bodies.
     * @param string   $prefix    Raw URL path prefix, '' for the root.
     * @return string[]
     */
    private function prefixed_endpoints(array $endpoints, string $prefix): array
    {
        if ('' === $prefix) {
            return $endpoints;
        }

        $escaped = str_replace('/', '\/', $prefix);

        return array_map(
            static fn (string $endpoint): string => $escaped . '\/' . $endpoint,
            $endpoints
        );
    }

    /**
     * URL path prefixes for network-complete conf patterns.
     *
     * Holds the primary site's URL path ('' on classic, the core
     * directory on Bedrock) plus every registered child-site path.
     * Empty for single sites and subdomain networks, so their output
     * is unchanged.
     *
     * @return string[] Raw path prefixes; '' means the URL root.
     */
    private function network_site_prefixes(): array
    {
        $child_prefixes = $this->child_site_prefixes();
        if ([] === $child_prefixes) {
            return [];
        }

        return array_merge([$this->main_site_prefix()], $child_prefixes);
    }

    /**
     * URL path prefix of the network's primary site, '' when none.
     *
     * Read from the primary site, never from the current context, so
     * the pattern is independent of the site that runs the enable.
     */
    private function main_site_prefix(): string
    {
        if (!function_exists('get_network') || !function_exists('get_site_url')) {
            return '';
        }

        $network = get_network();
        $site_id = is_object($network) && isset($network->site_id) ? (int) $network->site_id : 0;
        $url = $site_id > 0 ? (string) get_site_url($site_id) : (string) site_url();
        $path = wp_parse_url($url, PHP_URL_PATH);

        return is_string($path) ? trim($path, '/') : '';
    }

    /**
     * Registered child-site path regexes of a subdirectory network.
     *
     * The bodies carry raw slashes; `prefixed_endpoints()` escapes them
     * once. Empty for single sites and subdomain networks.
     *
     * @return string[] preg-quoted path bodies.
     */
    private function child_site_prefixes(): array
    {
        if (null !== $this->child_prefix_cache) {
            return $this->child_prefix_cache;
        }

        $prefixes = [];
        foreach (Multisite::child_site_paths() as $entry) {
            $regex = (string) ($entry['regex'] ?? '');
            if ('' === $regex) {
                continue;
            }

            $prefixes[] = $regex;
        }

        $this->child_prefix_cache = $prefixes;

        return $prefixes;
    }

    /**
     * Keep only path-safe characters so generated conf values cannot
     * break out of their nginx/Apache syntax context.
     *
     * @param string $value Raw derived value.
     * @return string Path-safe value.
     */
    private function escape_conf_value(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._\/-]/', '', $value);
    }

    /**
     * Normalize a filesystem path without a trailing slash.
     *
     * @param string $path Raw filesystem path.
     * @return string Normalized path.
     */
    private function normalize_path(string $path): string
    {
        return untrailingslashit(wp_normalize_path($path));
    }

    /**
     * Log the derived facts once at info level.
     *
     * .env is never read and no secret value enters the log.
     */
    private function log_facts(): void
    {
        (new Logger())->info('RunCache layout facts derived', [
            'component' => 'Environment',
            'layout' => $this->facts['layout'],
            'document_root' => $this->facts['document_root'],
            'content_url_path' => $this->facts['content_url_path'],
            'admin_url_prefix' => $this->facts['admin_url_prefix'],
            'wp_config_path' => $this->facts['wp_config_path'],
            'htaccess_path' => $this->facts['htaccess_path'],
            'cache_root' => $this->facts['cache_root'],
        ]);
    }
}
