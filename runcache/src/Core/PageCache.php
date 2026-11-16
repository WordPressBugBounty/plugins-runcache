<?php
namespace RunCache\Core;

use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Support\Dropin;
use RunCache\Support\Environment;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Server;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page cache management and utilities
 */
class PageCache
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private array $default_settings = [
        'enable' => true,
        'page_cache_type' => 'php',
    ];

    /**
     * @param Logger|null $logger
     * @param Validator|null $validator
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Validator $validator = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->validator = $validator ?? new Validator();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize cache functionality
     *
     * @return void
     */
    public function init(): void
    {
        $this->ensure_cache_directory();

        add_action('wp_ajax_runcache_get_cache_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_cache_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_runcache_verify_cache', [$this, 'ajax_verify_cache']);
        add_action('wp_ajax_runcache_request_cache_type', [$this, 'ajax_request_cache_type']);
    }

    /**
     * Get current cache provider type
     *
     * @return string
     */
    public function get_cache_type(): string
    {
        return $this->get_settings()['page_cache_type'];
    }

    /**
     * Get cache provider by key
     *
     * @param string $key
     * @return CacheProvider|false
     */
    public function get_cache_provider(string $key): CacheProvider|false
    {
        if (empty($key)) {
            return false;
        }

        $cache_providers = apply_filters('runcache_cache_providers', []);

        if (isset($cache_providers[$key]) && $cache_providers[$key] instanceof CacheProvider) {
            return $cache_providers[$key];
        }

        $this->logger->warning('Provider not found', [
            'component' => 'PageCache',
            'key' => $key,
            'available' => array_keys($cache_providers)
        ]);

        return false;
    }

    /**
     * Get cache settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $settings = wp_parse_args($this->setting->get('page_cache'), $this->default_settings);

        // Read-only: the server type comes from storage, so no HTTP probe runs here.
        $settings['server_type'] = (new Server())->get_server_type();
        // ABSPATH is the core dir on Bedrock, so the root comes from the layout profile.
        $environment = Environment::instance();
        $settings['document_root'] = $environment->get_document_root();

        $config_dir = (new File())->get_config_dir();
        $settings['config_dir'] = $config_dir !== false ? untrailingslashit($config_dir) : $environment->get_document_root();

        $settings['wp_cache'] = self::wp_cache_status();

        $settings['site_url'] = home_url();
        $settings['site_name'] = get_bloginfo('name');
        $settings['admin_url'] = admin_url();

        return apply_filters('runcache_cache_settings', $settings);
    }

    /**
     * Save cache settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        // Read the previous settings from the raw stored option.
        // The enriched getter computes server_type and must stay off this path.
        $raw_stored = $this->setting->get('page_cache');
        $stored_settings = is_array($raw_stored) ? $raw_stored : [];
        $previous_settings = wp_parse_args($stored_settings, $this->default_settings);

        $previous_provider = $this->get_cache_provider($previous_settings['page_cache_type']);

        // Merge incoming settings on top of the stored settings plus defaults.
        // Partial updates (e.g. ['enable' => false]) keep the stored page_cache_type.
        $settings = wp_parse_args($settings, $previous_settings);

        // Allow plugins to validate/modify settings before save
        $settings = apply_filters('runcache_cache_settings_before_save', $settings, $previous_settings);

        $result = $this->setting->set('page_cache', $settings);

        $new_provider = $this->get_cache_provider($settings['page_cache_type']);

        $dropin = new Dropin();

        if ($settings['enable']) {
            if ($previous_settings['page_cache_type'] !== $settings['page_cache_type']) {
                // Disable previous provider
                if ($previous_provider) {
                    $previous_provider->disable();
                }
                // Remove toggle for previous cache type
                $dropin->remove_cache_toggle($previous_settings['page_cache_type']);
            }

            // Enable new provider
            if ($new_provider) {
                $enabled = $new_provider->enable();
                if (!$enabled) {
                    $this->logger->error('Failed to enable provider', [
                        'component' => 'PageCache',
                        'provider' => $settings['page_cache_type'],
                    ]);
                }
                // Create toggle for new cache type
                $dropin->write_cache_toggle($settings['page_cache_type']);
            }
        } else {
            // Disable current provider
            if ($previous_provider) {
                $previous_provider->disable();
            }
            // Remove toggle for current cache type
            $dropin->remove_cache_toggle($previous_settings['page_cache_type']);
        }

        do_action('runcache_cache_settings_saved', $settings);

        return $result;
    }

    /**
     * Verify cache setup
     *
     * @return array
     */
    public function verify_cache(): array
    {
        $settings = $this->get_settings();

        if (!$settings['enable']) {
            return [
                'type' => $this->default_settings['page_cache_type'],
                'message' => 'Page cache is disabled',
                'verified' => false
            ];
        }

        $provider = $this->get_cache_provider($settings['page_cache_type']);

        if (!$provider) {
            return [
                'type' => $this->default_settings['page_cache_type'],
                'message' => 'Cache provider not found',
                'verified' => false
            ];
        }

        $verified = $provider->verify();

        if (is_array($verified)) {
            return [
                'type' => $settings['page_cache_type'],
                'message' => $verified['message'],
                'verified' => $verified['verified']
            ];
        }

        return [
            'type' => $settings['page_cache_type'],
            'message' => '',
            'verified' => $verified
        ];
    }

    /**
     * Get cache directory path
     *
     * @return string
     */
    public function get_cache_directory(): string
    {
        return RUNCACHE_CACHE_DIRECTORY;
    }

    /**
     * Get cache file path from URL path
     *
     * @param string $path
     * @return string|false
     */
    public function get_cache_file_path($path): string|false
    {
        $path = $this->validator->sanitize_url_pattern($path);
        if ($path === false) {
            $this->logger->error('Invalid cache path', ['path' => $path]);
            return false;
        }

        $file_path = $this->get_cache_directory() . $path . '.html';
        if (!$this->validator->validate_file_path($file_path)) {
            $this->logger->error('Invalid cache file path', ['path' => $file_path]);
            return false;
        }

        return $file_path;
    }

    /**
     * Get cache meta file path from URL path
     *
     * @param string $path
     * @return string|false
     */
    public function get_cache_meta_path($path): string|false
    {
        $path = $this->validator->sanitize_url_pattern($path);
        if ($path === false) {
            $this->logger->error('Invalid cache path', ['path' => $path]);
            return false;
        }

        $file_path = $this->get_cache_directory() . $path . '.meta';
        if (!$this->validator->validate_file_path($file_path)) {
            $this->logger->error('Invalid cache file path', ['path' => $file_path]);
            return false;
        }

        return $file_path;
    }

    /**
     * Ensure cache directory exists
     *
     * @return bool
     */
    public function ensure_cache_directory(): bool
    {
        $cache_dir = $this->get_cache_directory();

        $file = new File();

        if (!$file->exists($cache_dir)) {
            $created = $file->mkdir($cache_dir);

            if (!$created) {
                $this->logger->error('Failed to create cache directory', [
                    'path' => $cache_dir
                ]);
                return false;
            }

            // The file may be served directly, so an empty body discloses nothing.
            $file->write($cache_dir . 'index.html', '');

            $htaccess_content = "Options -Indexes\n<FilesMatch \"\\.meta$\">\n    Require all denied\n</FilesMatch>\n";
            $file->write($cache_dir . '.htaccess', $htaccess_content);

            $web_config_content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <security>\n            <requestFiltering>\n                <fileExtensions allowUnlisted=\"true\">\n                    <add fileExtension=\".meta\" allowed=\"false\" />\n                </fileExtensions>\n            </requestFiltering>\n        </security>\n    </system.webServer>\n</configuration>\n";
            $file->write($cache_dir . 'web.config', $web_config_content);
        }

        return true;
    }

    /**
     * Enable WP_CACHE constant in wp-config.php
     *
     * Bedrock stubs and site config files are site-owned, so the plugin
     * never writes them there; WP_CACHE stays a guided operator step.
     *
     * @return bool
     */
    public function enable_wp_cache(): bool
    {
        $environment = Environment::instance();

        if ($environment->is_bedrock()) {
            return $this->verify_bedrock_wp_cache();
        }

        $file = new File();

        // Writes go only to the config file the layout marks writable.
        $wp_config_path = $environment->get_writable_config_path();
        if ($wp_config_path === null) {
            $this->logger->error('wp-config.php not found');
            return false;
        }

        // Read current content
        $config_content = $file->read($wp_config_path);
        if ($config_content === false) {
            $this->logger->error('Failed to read wp-config.php', ['path' => $wp_config_path]);
            return false;
        }

        // Add or update WP_CACHE constant
        $wp_cache_line = "define('WP_CACHE', true); // Added by RunCache";
        if (preg_match('/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\)\s*;/', $config_content)) {
            // Replace existing WP_CACHE line
            $config_content = preg_replace(
                '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\)\s*;.*/',
                $wp_cache_line,
                $config_content
            );
        } else {
            // Add new constant after <?php
            $config_content = preg_replace(
                '/^<\?php/',
                "<?php\n\n" . $wp_cache_line,
                $config_content
            );
        }

        return $this->write_wp_config($wp_config_path, $config_content);
    }

    /**
     * Check WP_CACHE on Bedrock without writing any config file.
     *
     * Constants are already loaded, so a read-only scan suffices and
     * tailors the logged instruction.
     *
     * @return bool True when WP_CACHE is effectively enabled.
     */
    private function verify_bedrock_wp_cache(): bool
    {
        if (defined('WP_CACHE') && WP_CACHE) {
            $this->logger->info('WP_CACHE is enabled by site configuration', ['layout' => 'bedrock']);
            return true;
        }

        $defined_in = $this->find_bedrock_wp_cache_definition();
        $this->logger->warning('WP_CACHE is not enabled; the page cache needs one operator step', [
            'layout' => 'bedrock',
            'wp_cache_defined_in' => '' !== $defined_in ? $defined_in : 'nowhere',
            'instruction' => "Add Config::define('WP_CACHE', true); to config/application.php",
        ]);

        return false;
    }

    /**
     * Find where the site config defines WP_CACHE (read-only).
     *
     * The scan stays read-only because .env and site config files are
     * operator-owned.
     *
     * @return string Relative file path, or '' when not found.
     */
    private function find_bedrock_wp_cache_definition(): string
    {
        $config_dir = Environment::instance()->get_document_root() . '/config';
        $candidates = [
            $config_dir . '/application.php' => 'config/application.php',
        ];

        $env_files = glob($config_dir . '/environments/*.php');
        if (is_array($env_files)) {
            foreach ($env_files as $env_file) {
                $candidates[$env_file] = 'config/environments/' . basename($env_file);
            }
        }

        $file = new File();
        foreach ($candidates as $path => $relative) {
            $content = $file->read($path);
            if (!is_string($content) || $content === '') {
                continue;
            }

            $defines_wp_cache = preg_match(
                '/(Config::define|define)\s*\(\s*[\'"]WP_CACHE[\'"]/',
                $content
            );

            if ($defines_wp_cache === 1) {
                return $relative;
            }
        }

        return '';
    }

    /**
     * Summarize the effective WP_CACHE state for the dashboard.
     *
     * The check is live, so one status covers a failed classic config
     * write and the guided Bedrock operator step.
     *
     * @return array<string, mixed>
     */
    public static function wp_cache_status(): array
    {
        $environment = Environment::instance();
        $active = defined('WP_CACHE') && WP_CACHE;

        if ($environment->is_bedrock()) {
            return [
                'active' => $active,
                // Site-owned config stays unwritten.
                'writable' => null,
                'layout' => Environment::LAYOUT_BEDROCK,
                // Bedrock keeps config at the project root, above the docroot.
                'config_path' => dirname($environment->get_document_root()) . '/config/application.php',
                'line' => "Config::define('WP_CACHE', true);",
            ];
        }

        $config_path = $environment->get_writable_config_path();

        return [
            'active' => $active,
            'writable' => is_string($config_path) ? (new File())->is_writable($config_path) : null,
            'layout' => Environment::LAYOUT_CLASSIC,
            'config_path' => $config_path,
            'line' => "define('WP_CACHE', true);",
        ];
    }

    /**
     * Disable WP_CACHE constant in wp-config.php
     *
     * Site-owned defines are never removed; drop-in removal keeps
     * deactivation reliable because a lingering define loads nothing.
     *
     * @return bool
     */
    public function disable_wp_cache(): bool
    {
        $environment = Environment::instance();

        if ($environment->is_bedrock()) {
            $this->logger->info('WP_CACHE left to site configuration; drop-in removal ensures nothing loads', [
                'layout' => 'bedrock',
            ]);
            return true;
        }

        $file = new File();

        // Writes go only to the config file the layout marks writable.
        $wp_config_path = $environment->get_writable_config_path();
        if ($wp_config_path === null) {
            $this->logger->error('wp-config.php not found');
            return false;
        }

        // Read current content
        $config_content = $file->read($wp_config_path);
        if ($config_content === false) {
            $this->logger->error('Failed to read wp-config.php', ['path' => $wp_config_path]);
            return false;
        }

        // Remove WP_CACHE constant
        if (preg_match('/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\)\s*;/', $config_content)) {
            $config_content = preg_replace(
                '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\)\s*;.*\n?/',
                '',
                $config_content
            );
        }

        return $this->write_wp_config($wp_config_path, $config_content);
    }

    /**
     * Write config content to wp-config.php with backup and restore
     *
     * @param string $wp_config_path
     * @param string $config_content
     * @return bool
     */
    private function write_wp_config(string $wp_config_path, string $config_content): bool
    {
        $file = new File();

        // Check permissions before attempting any modifications
        $permission_check = $file->check_permissions($wp_config_path);
        if (!$permission_check['success']) {
            $this->logger->error('wp-config.php permission check failed', [
                'path' => $wp_config_path,
                'readable' => $permission_check['readable'],
                'writable' => $permission_check['writable']
            ]);
            return false;
        }

        $this->logger->info('wp-config.php permissions check passed', ['path' => $wp_config_path]);

        $backup_path = $wp_config_path . '.runcache-backup.php';

        // Delete existing backup if present
        if ($file->exists($backup_path)) {
            $this->logger->info('Removing existing wp-config.php backup');
            if (!$file->delete($backup_path)) {
                $this->logger->error('Failed to remove existing wp-config.php backup', [
                    'path' => $wp_config_path,
                    'backup' => $backup_path
                ]);
                return false;
            }
        }

        // Create backup
        if (!$file->copy($wp_config_path, $backup_path, true)) {
            $this->logger->error('Failed to create wp-config.php backup', [
                'path' => $wp_config_path,
                'backup' => $backup_path
            ]);
            return false;
        }

        $this->logger->info('wp-config.php backup created successfully');

        // Verify file is writable
        if (!$file->is_writable($wp_config_path)) {
            $this->logger->error('wp-config.php is not writable', ['path' => $wp_config_path]);
            $file->delete($backup_path);
            return false;
        }

        $this->logger->info('Attempting to write wp-config.php', ['content_length' => strlen($config_content)]);

        try {
            // Write new content
            if (!$file->write($wp_config_path, $config_content)) {
                $this->logger->error('Failed to update wp-config.php', [
                    'path' => $wp_config_path,
                    'backup_exists' => $file->exists($backup_path)
                ]);

                // Restore from backup
                $this->logger->info('Attempting to restore wp-config.php from backup');
                if (!$file->copy($backup_path, $wp_config_path, true)) {
                    $this->logger->error('Failed to restore wp-config.php from backup', ['backup_path' => $backup_path]);
                }

                return false;
            }

            $this->logger->info('wp-config.php updated successfully');

            return true;
        } finally {
            $file->delete($backup_path);
        }
    }

    /**
     * Request cache type from homepage headers
     *
     * Uses the public domain first so that CDN / edge-cached responses are
     * detected correctly. If the public request does not expose the origin
     * cache type header (for example because a CDN returns a challenge page,
     * strips headers, or serves an edge-only response), fall back to an
     * internal loopback request that reaches the origin web server directly.
     *
     * @return string|null
     */
    public function request_cache_type(): string|null
    {
        $server = new Server();
        $homepage_url = home_url('/');

        // First try the public URL to detect CDN / edge cache responses.
        $headers = $server->get_headers($homepage_url, ['timeout' => 10], true);

        if ($headers === null) {
            return null;
        }

        // If the public request did not expose the origin cache type header,
        // fall back to an internal loopback request. This bypasses CDN edges,
        // bot challenges, and proxies that hide or rewrite origin headers while
        // preserving the public Host header so the correct vhost is served.
        if (empty($headers['x-runcache-type'])) {
            $internal_headers = $server->get_headers($homepage_url, ['timeout' => 10], false);

            if (is_array($internal_headers) && !empty($internal_headers['x-runcache-type'])) {
                $headers = $internal_headers;
            }
        }

        $cache_type = $headers['x-runcache-type'] ?? '';

        if (is_array($cache_type)) {
            $cache_type = $cache_type[0];
        }

        return strtolower($cache_type);
    }

    /**
     * Enable page caching
     *
     * @return void
     */
    public function enable(): void
    {
        $settings = $this->get_settings();
        $settings['enable'] = true;
        $this->setting->set('page_cache', $settings);
    }

    /**
     * Disable page caching
     *
     * @return void
     */
    public function disable(): void
    {
        $settings = $this->get_settings();
        $settings['enable'] = false;
        $this->setting->set('page_cache', $settings);
    }

    /**
     * AJAX handler for getting cache settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $this->validator->validate_ajax_request();
        wp_send_json_success($this->get_settings());
    }

    /**
     * AJAX handler for saving cache settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $this->validator->validate_ajax_request();
        $settings = $this->validator->validate_ajax_settings();

        $result = $this->save_settings($settings);

        $result
            ? wp_send_json_success($this->get_settings())
            : wp_send_json_error(['message' => 'Failed to save settings']);
    }

    /**
     * AJAX handler for verifying cache
     *
     * @return void
     */
    public function ajax_verify_cache(): void
    {
        $this->validator->validate_ajax_request();
        wp_send_json_success($this->verify_cache());
    }

    /**
     * AJAX handler for requesting cache type
     *
     * @return void
     */
    public function ajax_request_cache_type(): void
    {
        $this->validator->validate_ajax_request();
        wp_send_json_success([
            'cache_type' => $this->request_cache_type()
        ]);
    }
}
