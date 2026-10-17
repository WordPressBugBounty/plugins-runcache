<?php

namespace RunCache\Support;

use RunCache\Core\PageCache;
use RunCache\Core\Setting;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the WordPress drop-in files.
 */
class Dropin
{
    private Logger $logger;
    private Setting $setting;
    private string $advanced_cache_dropin;
    private string $advanced_cache_source;
    private string $object_cache_dropin;
    private string $object_cache_source;
    private string $page_cache_signature = 'Advanced cache drop-in router';
    private string $object_cache_signature = 'RunCache Object Cache Drop-in';

    /**
     * Drop-in template marker names. Each marker is a single-line comment in
     * includes/runcache-page-cache.php that the generator replaces.
     */
    private const DROPIN_MARKER_NOTE = 'generation-note';
    private const DROPIN_MARKER_CONFIG = 'runcache-config';
    private const DROPIN_MARKER_FILTER = 'runcache-filter';
    private const DROPIN_MARKER_HANDLER = 'page-cache-handler';

    /**
     * Provider types routed to the PHP file cache handler.
     */
    private const PHP_HANDLER_TYPES = ['apache', 'nginx', 'php'];

    /**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();

        $this->advanced_cache_dropin = WP_CONTENT_DIR . '/advanced-cache.php';
        $this->advanced_cache_source = RUNCACHE_PLUGIN_DIR . '/includes/runcache-page-cache.php';
        $this->object_cache_dropin = WP_CONTENT_DIR . '/object-cache.php';
        $this->object_cache_source = RUNCACHE_PLUGIN_DIR . '/includes/object-cache.php';
    }

    /**
     * Installs the page cache drop-in.
     *
     * @return bool
     */
    public function install_page_cache_dropin(): bool
    {
        $this->logger->info('Installing page cache dropin', ['component' => 'Dropin', 'dropin' => 'page']);

        if (!file_exists($this->advanced_cache_source)) {
            $this->logger->error('Source template not found', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'path' => $this->advanced_cache_source
            ]);
            return false;
        }

        $this->setting->write_config_file();

        $dropin_content = $this->generate_page_cache_dropin_content();
        if ($dropin_content === false) {
            return false;
        }

        // Check if target file exists
        if (file_exists($this->advanced_cache_dropin)) {
            $existing_content = file_get_contents($this->advanced_cache_dropin);

            if ($existing_content === false) {
                $this->logger->error('Could not read existing drop-in file', ['component' => 'Dropin', 'dropin' => 'page']);
                return false;
            }

            // Check if file is managed by RunCache
            if (strpos($existing_content, $this->page_cache_signature) === false) {
                // Not managed by RunCache. Back it up first.
                $backup_name = 'advanced-cache-backup-' . gmdate('Y-m-d-His') . '.php';
                $backup_path = dirname($this->advanced_cache_dropin) . '/' . $backup_name;

                $backup_success = copy($this->advanced_cache_dropin, $backup_path);

                if ($backup_success) {
                    $this->logger->info('Backed up existing drop-in', ['component' => 'Dropin', 'dropin' => 'page', 'backup' => $backup_name]);
                } else {
                    $this->logger->error('Failed to backup existing drop-in', ['component' => 'Dropin', 'dropin' => 'page', 'backup' => $backup_name]);
                }
            }
            // Managed by RunCache: always overwrite so the drop-in is refreshed
            // on plugin update. No early return here.
        }

        if (!$this->install_dropin_file($dropin_content, $this->advanced_cache_dropin)) {
            $this->logger->error('Failed to install dropin file', ['component' => 'Dropin', 'dropin' => 'page']);
            return false;
        }

        $this->logger->info('Page cache dropin installed', ['component' => 'Dropin', 'dropin' => 'page']);
        return true;
    }

    /**
     * Generates the advanced-cache.php content from the template.
     * The output is self-contained and runs without the plugin directory.
     *
     * @return string|false The drop-in content, or false on failure.
     */
    private function generate_page_cache_dropin_content(): string|false
    {
        $template = file_get_contents($this->advanced_cache_source);
        if ($template === false) {
            $this->logger->error('Could not read drop-in template', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'path' => $this->advanced_cache_source
            ]);
            return false;
        }

        // The template carries a usage note for developers. The generated
        // drop-in must not contain it.
        $template = $this->drop_inlined_block($template, 'template-usage-note');
        if ($template === false) {
            return false;
        }

        $page_cache_type = $this->get_current_page_cache_type();

        $config_section = $this->build_config_section();
        if ($config_section === false) {
            return false;
        }

        $filter_section = $this->build_filter_section();
        if ($filter_section === false) {
            return false;
        }

        $handler_section = $this->build_handler_section($page_cache_type);
        if ($handler_section === false) {
            return false;
        }

        $version = defined('RUNCACHE_VERSION') ? RUNCACHE_VERSION : 'unknown';
        $note = '// Generated by RunCache ' . $version . ' for provider: ' . $page_cache_type . ".\n"
            . '// Do not edit this file. Template: includes/runcache-page-cache.php.';

        $sections = [
            self::DROPIN_MARKER_NOTE => $note,
            self::DROPIN_MARKER_CONFIG => $config_section,
            self::DROPIN_MARKER_FILTER => $filter_section,
            self::DROPIN_MARKER_HANDLER => $handler_section,
        ];

        // Normalize each section to exactly one trailing newline so the
        // spacing between sections stays uniform.
        foreach ($sections as $marker => $section) {
            $sections[$marker] = rtrim($section) . "\n";
        }

        $content = $template;
        foreach ($sections as $marker => $section) {
            $content = $this->replace_template_marker($content, $marker, $section);
            if ($content === false) {
                return false;
            }
        }

        // Safety net: assembly must not leave marker comment lines behind.
        if (str_contains($content, '// [RUNCACHE_INLINE:')) {
            $this->logger->error('Drop-in generation left an unresolved template marker', [
                'component' => 'Dropin',
                'dropin' => 'page'
            ]);
            return false;
        }

        // Keep the spacing uniform: at most one blank line between sections.
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        if ($content === null) {
            $this->logger->error('Could not normalize drop-in spacing', [
                'component' => 'Dropin',
                'dropin' => 'page'
            ]);
            return false;
        }

        return $content;
    }

    /**
     * Replace one marker line in the drop-in template
     *
     * @param string $template Template content.
     * @param string $marker Marker name without the RUNCACHE_INLINE prefix.
     * @param string $section Replacement content.
     * @return string|false New content, or false when the marker is missing.
     */
    private function replace_template_marker(string $template, string $marker, string $section): string|false
    {
        // The pattern also consumes the marker line's newline. This
        // keeps the section spacing uniform.
        $pattern = '/^[ \t]*\/\/[ \t]*\[RUNCACHE_INLINE:[ \t]*'
            . preg_quote($marker, '/')
            . '[ \t]*\][ \t]*\r?\n?/m';

        // preg_replace_callback inserts the body literally. Otherwise
        // the replacement would read backslashes and dollar signs as references.
        $result = preg_replace_callback(
            $pattern,
            static function () use ($section): string {
                return $section;
            },
            $template,
            1,
            $count
        );

        if ($result === null || $count !== 1) {
            $this->logger->error('Could not replace drop-in template marker', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'marker' => $marker
            ]);
            return false;
        }

        return $result;
    }

    /**
     * Builds the config section for the generated drop-in.
     * Inlines runcache-config.php. Embeds the rule defaults as a literal array.
     *
     * @return string|false Section content, or false on failure.
     */
    private function build_config_section(): string|false
    {
        $body = $this->extract_inline_body('runcache-config.php');
        if ($body === false) {
            return false;
        }

        $body = $this->inline_rule_defaults($body);
        if ($body === false) {
            return false;
        }

        return "// ===== Config loader (includes/runcache-config.php) =====\n\n"
            . $body;
    }

    /**
     * Replace the rule-defaults require block with a literal array
     *
     * @param string $body Extracted body of runcache-config.php.
     * @return string|false New body, or false when the marker block is missing.
     */
    private function inline_rule_defaults(string $body): string|false
    {
        $pattern = '#/\*\s*\[RUNCACHE_INLINE_DATA:\s*runcache-rule-defaults\]\s*\*/'
            . '.*?'
            . '/\*\s*\[/RUNCACHE_INLINE_DATA:\s*runcache-rule-defaults\]\s*\*/#s';

        $defaults_path = RUNCACHE_PLUGIN_DIR . 'includes/runcache-rule-defaults.php';
        if (!is_readable($defaults_path)) {
            $this->logger->error('Rule defaults file not readable', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'path' => $defaults_path
            ]);
            return false;
        }

        $rule_defaults = require $defaults_path;
        if (!is_array($rule_defaults)) {
            $this->logger->error('Rule defaults file did not return an array', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'path' => $defaults_path
            ]);
            return false;
        }

        // var_export builds the literal array embedded in the generated
        // drop-in. It serializes data. It is not debug output.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $exported = var_export($rule_defaults, true);

        // Normalize the exported literal: drop the trailing whitespace after
        // '=>' and pull 'array (' up onto the '=>' line.
        $exported = preg_replace('/[ \t]+$/m', '', $exported);
        $exported = preg_replace("/' =>\n([ \t]*)array \(/", "' => array (", $exported);
        if ($exported === null) {
            return false;
        }

        $replacement = '$runcache_fallback_rules = ' . $exported . ";\n";

        $result = preg_replace_callback(
            $pattern,
            static function () use ($replacement): string {
                return $replacement;
            },
            $body,
            -1,
            $count
        );

        if ($result === null || $count !== 1) {
            $this->logger->error('Could not replace rule defaults marker block', [
                'component' => 'Dropin',
                'dropin' => 'page'
            ]);
            return false;
        }

        return $result;
    }

    /**
     * Build the filter section for the generated drop-in
     *
     * Inlines runcache-filter.php and drops its require of the config file,
     * because the config section is already inlined above it.
     *
     * @return string|false Section content, or false on failure.
     */
    private function build_filter_section(): string|false
    {
        $body = $this->extract_inline_body('runcache-filter.php');
        if ($body === false) {
            return false;
        }

        // Drop the require of the config file: the config section is already
        // inlined above this section in the generated file.
        $body = $this->drop_inlined_block($body, 'runcache-config-require');
        if ($body === false) {
            return false;
        }

        return "// ===== Cache filter: runcache_should_cache (includes/runcache-filter.php) =====\n\n"
            . $body;
    }

    /**
     * Drops one RUNCACHE_DROP_WHEN_INLINED marker block from content.
     * The block wraps standalone-only code the generated file must not contain.
     *
     * @param string $content Content containing the marker block.
     * @param string $name Block name.
     * @param bool $required When false, missing blocks are left untouched.
     * @return string|false Content without the block, or false when a required block is missing.
     */
    private function drop_inlined_block(string $content, string $name, bool $required = true): string|false
    {
        $marker = '[RUNCACHE_DROP_WHEN_INLINED: ' . $name . ']';

        if (!$required && !str_contains($content, $marker)) {
            return $content;
        }

        $quoted = preg_quote($name, '#');

        // The pattern also removes the newline before the marker. This
        // keeps the blank line count uniform in the generated file.
        $pattern = '#(\r?\n)?/\*\s*\[RUNCACHE_DROP_WHEN_INLINED:\s*' . $quoted . '\]\s*\*/'
            . '.*?'
            . '/\*\s*\[\s*/RUNCACHE_DROP_WHEN_INLINED:\s*' . $quoted . '\]\s*\*/\r?\n?#s';

        $result = preg_replace_callback(
            $pattern,
            static function (): string {
                return '';
            },
            $content,
            -1,
            $count
        );

        if ($result === null || $count !== 1) {
            $this->logger->error('Could not drop inlined marker block', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'block' => $name
            ]);
            return false;
        }

        return $result;
    }

    /**
     * Extract and clean a handler body for inlining
     *
     * @param string $file Handler file name inside the includes directory.
     * @return string|false Cleaned body, or false on failure.
     */
    private function extract_handler_body(string $file): string|false
    {
        $body = $this->extract_inline_body($file);
        if ($body === false) {
            return false;
        }

        // Remove standalone-only blocks. The earlier sections of the
        // generated file provide their code.
        foreach (['handler-filter-guard', 'handler-cache-dir-define'] as $block) {
            $body = $this->drop_inlined_block($body, $block, false);
            if ($body === false) {
                return false;
            }
        }

        return $body;
    }

    /**
     * Builds the cache handler section for the active provider.
     * The handler runs only when it matches the runtime page_cache_type.
     *
     * @param string $page_cache_type Active provider type.
     * @return string|false Section content, or false on failure.
     */
    private function build_handler_section(string $page_cache_type): string|false
    {
        if ($page_cache_type === 'redis') {
            $body = $this->extract_handler_body('runcache-page-redis.php');
            if ($body === false) {
                return false;
            }

            // The banner text keeps the literal marker string that
            // Redis::remove_dropin() matches to recognize its drop-in.
            return "// ===== Handler: Redis-backed page cache drop-in (includes/runcache-page-redis.php) =====\n\n"
                . "if (\$runcache_page_cache_type !== 'redis') {\n"
                . "    // This drop-in was not generated for the current provider.\n"
                . "    return;\n"
                . "}\n\n"
                . $body;
        }

        if (in_array($page_cache_type, self::PHP_HANDLER_TYPES, true)) {
            $body = $this->extract_handler_body('runcache-page-php.php');
            if ($body === false) {
                return false;
            }

            $types = implode("', '", self::PHP_HANDLER_TYPES);

            return "// ===== Handler: PHP file cache (includes/runcache-page-php.php) =====\n\n"
                . "if (!in_array(\$runcache_page_cache_type, ['{$types}'], true)) {\n"
                . "    // This drop-in was not generated for the current provider.\n"
                . "    return;\n"
                . "}\n\n"
                . $body;
        }

        return "// ===== No handler: provider '{$page_cache_type}' does not use this drop-in =====\n";
    }

    /**
     * Gets the provider to generate the drop-in for.
     * Multisite shares one drop-in, so the main site provider wins.
     *
     * @return string Provider type, defaults to 'php'.
     */
    private function get_current_page_cache_type(): string
    {
        $settings = $this->setting->get('page_cache', null, true);

        $type = is_array($settings)
            && isset($settings['page_cache_type'])
            && is_string($settings['page_cache_type'])
            ? $settings['page_cache_type']
            : '';

        if ($type === '') {
            return 'php';
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '', $type);
        if ($sanitized === null || $sanitized === '') {
            return 'php';
        }

        return $sanitized;
    }

    /**
     * Extracts the inlinable PHP body of a drop-in include file.
     * The header strip is exact. A mismatch aborts the generation.
     *
     * @param string $relative File name inside the includes directory.
     * @return string|false The PHP body, or false on failure.
     */
    private function extract_inline_body(string $relative): string|false
    {
        $path = RUNCACHE_PLUGIN_DIR . 'includes/' . $relative;

        if (!is_readable($path)) {
            $this->logger->error('Drop-in include file not readable', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'path' => $path
            ]);
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            $this->logger->error('Could not read drop-in include file', [
                'component' => 'Dropin',
                'dropin' => 'page',
                'path' => $path
            ]);
            return false;
        }

        $context = ['component' => 'Dropin', 'dropin' => 'page', 'file' => $relative];

        // 1. Opening PHP tag.
        if (!str_starts_with($content, '<?php')) {
            $this->logger->error('Drop-in include file does not start with an opening PHP tag', $context);
            return false;
        }
        $body = ltrim(substr($content, strlen('<?php')));

        // 2. File docblock.
        if (str_starts_with($body, '/**')) {
            $docblock_end = strpos($body, '*/');
            if ($docblock_end === false) {
                $this->logger->error('Unterminated docblock in drop-in include file', $context);
                return false;
            }
            $body = ltrim(substr($body, $docblock_end + 2));
        }

        // 3. Leading phpcs:ignoreFile comment lines.
        while (preg_match('/^\/\/[ \t]*phpcs:ignoreFile[^\n]*\n/', $body, $matches) === 1) {
            $body = ltrim(substr($body, strlen($matches[0])));
        }

        // 4. Strict types declare. The generated file keeps the one declare
        // from the template, so this one must be removed.
        $declare = 'declare(strict_types=1);';
        if (!str_starts_with($body, $declare)) {
            $this->logger->error('Drop-in include file does not declare strict types before other code', $context);
            return false;
        }
        $body = ltrim(substr($body, strlen($declare)));

        // 5. ABSPATH guard block.
        $guard = "if (!defined('ABSPATH')) {";
        if (!str_starts_with($body, $guard)) {
            $this->logger->error('Drop-in include file does not start with the ABSPATH guard', $context);
            return false;
        }

        $guard_end = $this->find_balanced_brace_end($body, strlen($guard) - 1);
        if ($guard_end === -1) {
            $this->logger->error('Unterminated ABSPATH guard in drop-in include file', $context);
            return false;
        }

        $guard_block = substr($body, 0, $guard_end + 1);
        if (!str_contains($guard_block, 'exit;')) {
            $this->logger->error('ABSPATH guard in drop-in include file does not exit', $context);
            return false;
        }

        return ltrim(substr($body, $guard_end + 1));
    }

    /**
     * Find the closing brace matching the opening brace at a position
     *
     * @param string $text Text to scan.
     * @param int $start Index of the opening brace.
     * @return int Index of the matching closing brace, or -1 when unbalanced.
     */
    private function find_balanced_brace_end(string $text, int $start): int
    {
        $length = strlen($text);
        $depth = 0;

        for ($i = $start; $i < $length; $i++) {
            if ($text[$i] === '{') {
                $depth++;
                continue;
            }

            if ($text[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * Installs a drop-in file.
     *
     * Uses atomic write so readers never encounter a partially-written drop-in.
     *
     * @param string $content
     * @param string $target_file
     * @return bool
     */
    private function install_dropin_file(string $content, string $target_file): bool
    {
        $file = new File();

        if (!$file->atomic_write($target_file, $content)) {
            $this->logger->error('Could not write dropin file', [
                'component' => 'Dropin',
                'path' => $target_file
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check whether the page cache drop-in is installed and managed by RunCache.
     *
     * @return bool
     */
    public function is_page_cache_dropin_installed(): bool
    {
        if (!file_exists($this->advanced_cache_dropin)) {
            return false;
        }

        $content = file_get_contents($this->advanced_cache_dropin);
        if ($content === false) {
            return false;
        }

        return strpos($content, $this->page_cache_signature) !== false;
    }

    /**
     * Check whether the object cache drop-in is installed and managed by RunCache.
     *
     * @return bool
     */
    public function is_object_cache_dropin_installed(): bool
    {
        if (!file_exists($this->object_cache_dropin)) {
            return false;
        }

        $content = file_get_contents($this->object_cache_dropin);
        if ($content === false) {
            return false;
        }

        return strpos($content, $this->object_cache_signature) !== false;
    }

    /**
     * Refreshes managed drop-ins after a plugin update.
     * Runs on `upgrader_process_complete`. Replaces stale drop-in code.
     *
     * @param WP_Upgrader $upgrader   The upgrader instance.
     * @param array       $hook_extra Array of bulk item update data.
     * @return void
     */
    public static function refresh_on_update($upgrader, array $hook_extra): void
    {
        if (!isset($hook_extra['action']) || $hook_extra['action'] !== 'update') {
            return;
        }

        if (!isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
            return;
        }

        $updated = [];
        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $updated = $hook_extra['plugins'];
        } elseif (!empty($hook_extra['plugin']) && is_string($hook_extra['plugin'])) {
            $updated = [$hook_extra['plugin']];
        }

        if ($updated === []) {
            return;
        }

        $plugin_basename = defined('RUNCACHE_PLUGIN_BASENAME') ? RUNCACHE_PLUGIN_BASENAME : '';
        if ($plugin_basename === '') {
            return;
        }

        $is_self_updated = false;
        foreach ($updated as $plugin) {
            if ($plugin === $plugin_basename) {
                $is_self_updated = true;
                break;
            }
        }

        if (!$is_self_updated) {
            return;
        }

        $logger = new Logger();
        try {
            $dropin = new self($logger);

            // Refresh only drop-ins that RunCache already manages. Do not
            // install new ones. Enabling a feature is an admin action.
            if ($dropin->is_page_cache_dropin_installed()) {
                $dropin->install_page_cache_dropin();
            }

            if ($dropin->is_object_cache_dropin_installed()) {
                $dropin->install_object_cache_dropin();
            }
        } catch (\Throwable $e) {
            $logger->error('Failed to refresh drop-ins after plugin update', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Removes the page cache drop-in.
     *
     * @return bool
     */
    public function remove_page_cache_dropin(): bool
    {
        if (!file_exists($this->advanced_cache_dropin)) {
            return true;
        }

        $content = file_get_contents($this->advanced_cache_dropin);
        if ($content === false) {
            $this->logger->error('Could not read existing drop-in file', ['component' => 'Dropin', 'dropin' => 'page']);
            return false;
        }

        // Check if file is managed by RunCache
        if (strpos($content, $this->page_cache_signature) === false) {
            $this->logger->info('Drop-in is not ours, skipping removal', ['component' => 'Dropin', 'dropin' => 'page']);
            return true;
        }

        $file = new File();
        if (!$file->delete($this->advanced_cache_dropin)) {
            $this->logger->error('Could not remove dropin file', ['component' => 'Dropin', 'dropin' => 'page']);
            return false;
        }

        $this->logger->info('Page cache dropin removed', ['component' => 'Dropin', 'dropin' => 'page']);
        return true;
    }

    /**
     * Installs the object cache drop-in.
     *
     * @return bool
     */
    public function install_object_cache_dropin(): bool
    {
        $this->logger->info('Installing object cache dropin', ['component' => 'Dropin', 'dropin' => 'object']);

        if (!file_exists($this->object_cache_source)) {
            $this->logger->error('Source file not found', [
                'component' => 'Dropin',
                'dropin' => 'object',
                'path' => $this->object_cache_source
            ]);
            return false;
        }

        $this->setting->write_config_file();

        $source_content = file_get_contents($this->object_cache_source);
        if ($source_content === false) {
            $this->logger->error('Could not read source file', ['component' => 'Dropin', 'dropin' => 'object']);
            return false;
        }

        // Check if target file exists
        if (file_exists($this->object_cache_dropin)) {
            $existing_content = file_get_contents($this->object_cache_dropin);

            if ($existing_content === false) {
                $this->logger->error('Could not read existing drop-in file', ['component' => 'Dropin', 'dropin' => 'object']);
                return false;
            }

            // Check if file is managed by RunCache
            if (strpos($existing_content, $this->object_cache_signature) === false) {
                // Not managed by RunCache. Back it up first.
                $backup_name = 'object-cache-backup-' . gmdate('Y-m-d-His') . '.php';
                $backup_path = dirname($this->object_cache_dropin) . '/' . $backup_name;

                $backup_success = copy($this->object_cache_dropin, $backup_path);

                if ($backup_success) {
                    $this->logger->info('Backed up existing drop-in', ['component' => 'Dropin', 'dropin' => 'object', 'backup' => $backup_name]);
                } else {
                    $this->logger->error('Failed to backup existing drop-in', ['component' => 'Dropin', 'dropin' => 'object', 'backup' => $backup_name]);
                }
            }
            // Managed by RunCache: always overwrite so the drop-in is refreshed
            // on plugin update. No early return here.
        }

        if (!$this->install_dropin_file($source_content, $this->object_cache_dropin)) {
            $this->logger->error('Failed to install dropin file', ['component' => 'Dropin', 'dropin' => 'object']);
            return false;
        }

        $this->logger->info('Object cache dropin installed', ['component' => 'Dropin', 'dropin' => 'object']);
        return true;
    }

    /**
     * Removes the object cache drop-in.
     *
     * @return bool
     */
    public function remove_object_cache_dropin(): bool
    {
        if (!file_exists($this->object_cache_dropin)) {
            return true;
        }

        $content = file_get_contents($this->object_cache_dropin);
        if ($content === false) {
            $this->logger->error('Could not read existing drop-in file', ['component' => 'Dropin', 'dropin' => 'page']);
            return false;
        }

        // Check if file is managed by RunCache
        if (strpos($content, $this->object_cache_signature) === false) {
            $this->logger->info('Drop-in is not ours, skipping removal', ['component' => 'Dropin', 'dropin' => 'page']);
            return true;
        }

        $file = new File();
        if (!$file->delete($this->object_cache_dropin)) {
            $this->logger->error('Could not remove dropin file', ['component' => 'Dropin', 'dropin' => 'page']);
            return false;
        }

        $this->logger->info('Object cache dropin removed', ['component' => 'Dropin', 'dropin' => 'object']);
        return true;
    }

    /**
     * Write cache toggle marker file
     *
     * Creates a marker file in the cache directory to indicate
     * that a cache type is enabled for the current hostname.
     *
     * @param string $cache_type The cache type (e.g., 'nginx', 'runcloud-native')
     * @return void
     */
    public function write_cache_toggle(string $cache_type): void
    {
        $file = new File();
        $cache = new PageCache();
        $cache_dir = $cache->get_cache_directory();
        $hostname = $this->sanitize_hostname(Setting::get_site_host());
        $toggle_file = $cache_dir . '/.enabled-' . $cache_type . '-' . $hostname;

        $file->write($toggle_file, 'generated by RunCache');
    }

    /**
     * Remove cache toggle marker file
     *
     * Removes the marker file from the cache directory when
     * a cache type is disabled for the current hostname.
     *
     * @param string $cache_type The cache type (e.g., 'nginx', 'runcloud-native')
     * @return void
     */
    public function remove_cache_toggle(string $cache_type): void
    {
        $file = new File();
        $cache = new PageCache();
        $cache_dir = $cache->get_cache_directory();
        $hostname = $this->sanitize_hostname(Setting::get_site_host());
        $toggle_file = $cache_dir . '/.enabled-' . $cache_type . '-' . $hostname;

        if ($file->exists($toggle_file)) {
            $file->delete($toggle_file);
        }
    }

    /**
     * Sanitizes a hostname for use in a file name.
     * Blocks path traversal and injection. Keeps A-Z a-z 0-9 hyphen dot.
     *
     * @param string $hostname The hostname to sanitize
     * @return string Sanitized hostname
     */
    protected function sanitize_hostname(string $hostname): string
    {
        // Keep only valid hostname characters: A-Z a-z 0-9 hyphen dot.
        $hostname = preg_replace('/[^a-zA-Z0-9\-.]/', '', $hostname);

        // Collapse dot sequences to one dot. This blocks directory traversal.
        $hostname = preg_replace('/\.{2,}/', '.', $hostname);

        // Trim dots, hyphens, and slashes from both ends.
        $hostname = trim($hostname, '.-\\/');

        // Limit hostname length to prevent DoS
        if (strlen($hostname) > 253) {
            $hostname = substr($hostname, 0, 253);
        }

        return $hostname;
    }
}