<?php

namespace RunCache\Support;

/**
 * File utilities using native PHP filesystem operations.
 */
class File
{
    /**
     * Cached WordPress filesystem instance, populated once per request.
     *
     * @var \WP_Filesystem_Base|null
     */
    private static ?\WP_Filesystem_Base $wp_fs = null;

    private const LOCK_ATTEMPTS = 10;
    private const LOCK_RETRY_MICROSECONDS = 50000;

    /**
     * Optional logger. Only the lock methods log degradation events.
     * Every other method stays silent.
     *
     * @var Logger|null
     */
    private ?Logger $logger = null;

    /**
     * Initialize file functionality.
     *
     * @param Logger|null $logger
     */
    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Initialize file functionality.
     *
     * @return void
     */
    public function init(): void
    {
    }

    /**
     * Write content to a file.
     *
     * @param string $path File path
     * @param string $content Content to write
     * @return bool
     */
    public function write(string $path, string $content): bool
    {
        $ok = @file_put_contents($path, $content) !== false;
        if ($ok) {
            // Keep cache files readable by the web server without making them writable.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Runtime cache files use local permissions and WP_Filesystem is not available on every request.
            @chmod($path, 0644);
        }

        return $ok;
    }

    /**
     * Read content from a file.
     *
     * @param string $path File path
     * @return string|false
     */
    public function read(string $path): string|false
    {
        return @file_get_contents($path);
    }

    /**
     * Read a file through the WordPress Filesystem API.
     *
     * Returns false when the API is unavailable or fails to initialize.
     *
     * @param string $path File path
     * @return string|false
     */
    public function wpread(string $path): string|false
    {
        $fs = $this->get_filesystem();

        return $fs ? $fs->get_contents($path) : false;
    }

    /**
     * Get the cached WordPress filesystem instance.
     *
     * @return \WP_Filesystem_Base|null
     */
    private function get_filesystem(): ?\WP_Filesystem_Base
    {
        if (self::$wp_fs instanceof \WP_Filesystem_Base) {
            return self::$wp_fs;
        }

        if (!defined('ABSPATH')) {
            return null;
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;

        if (!WP_Filesystem()) {
            return null;
        }

        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            self::$wp_fs = $wp_filesystem;

            return self::$wp_fs;
        }

        return null;
    }

    /**
     * Delete a file or directory.
     *
     * @param string $path File or directory path
     * @param bool $recursive Recursively delete directory contents
     * @return bool
     */
    public function delete(string $path, bool $recursive = false): bool
    {
        if (!@file_exists($path) && !@is_link($path)) {
            return false;
        }

        if (@is_dir($path) && !@is_link($path)) {
            if (!$recursive) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Runtime cache directories are local and WP_Filesystem may require credentials.
                return @rmdir($path);
            }

            return $this->delete_dir_recursive($path);
        }

        if (@is_link($path)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Unlink symlinks directly so recursive cleanup never follows them.
            return @unlink($path);
        }

        if (function_exists('wp_delete_file')) {
            wp_delete_file($path);
            return !@file_exists($path);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback for early/runtime contexts where the WordPress wrapper is unavailable.
        return @unlink($path);
    }

    /**
     * Recursively delete a directory and all contents.
     *
     * Symlinks are unlinked, not followed, to avoid infinite recursion.
     *
     * @param string $dir Directory path
     * @return bool
     */
    private function delete_dir_recursive(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Runtime cache directories are local and WP_Filesystem may require credentials.
            return @rmdir($dir);
        }

        $success = true;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . DIRECTORY_SEPARATOR . $entry;

            if (@is_link($full)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Unlink symlinks directly so recursive cleanup never follows them.
                if (!@unlink($full)) {
                    $success = false;
                }
            } elseif (@is_dir($full)) {
                if (!$this->delete_dir_recursive($full)) {
                    $success = false;
                }
            } else {
                if (function_exists('wp_delete_file')) {
                    wp_delete_file($full);
                    if (@file_exists($full)) {
                        $success = false;
                    }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback for early/runtime contexts where the WordPress wrapper is unavailable.
                } elseif (!@unlink($full)) {
                    $success = false;
                }
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Runtime cache directories are local and WP_Filesystem may require credentials.
        return @rmdir($dir) && $success;
    }

    /**
     * Atomically write content to a file.
     *
     * Writes to a unique temporary file in the same directory and then renames
     * it into place. On POSIX filesystems rename() is atomic, so readers never
     * see a partially-written file.
     *
     * @param string $path Destination path.
     * @param string $content Content to write.
     * @return bool True on success, false on failure.
     */
    public function atomic_write(string $path, string $content): bool
    {
        $dir = dirname($path);

        // Create the temp file in the same directory so rename() is atomic.
        $tmp_path = @tempnam($dir, 'rctmp');
        if ($tmp_path === false) {
            return false;
        }

        if (@file_put_contents($tmp_path, $content) === false) {
            $this->delete($tmp_path);
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Atomic cache files use local permissions and WP_Filesystem is not available on every request.
        @chmod($tmp_path, 0644);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename is required for atomic cache replacement.
        if (!@rename($tmp_path, $path)) {
            $this->delete($tmp_path);
            return false;
        }

        return true;
    }

    /**
     * Update a PHP `return array(...)` config file under a best-effort lock.
     *
     * One writer holds an advisory lock across the read-merge-write cycle.
     * Lock failures degrade to last-writer-wins. They never fail the write.
     *
     * @param string $path Path to a PHP file holding one `return array(...)`.
     * @param callable $mutate Receives the current array, returns the new array.
     * @return bool True when the write succeeded, lock held or not.
     * @throws \Throwable Re-throws mutator exceptions after lock release.
     */
    public function locked_update(string $path, callable $mutate): bool
    {
        $handle = $this->acquire_lock($this->lock_path_for($path));

        try {
            // The read happens inside the lock so a concurrent writer's
            // change cannot be lost between read and write.
            $updated = $mutate($this->read_php_array($path));

            if (!is_array($updated)) {
                return false;
            }

            if (!$this->atomic_write($path, $this->render_php_config($updated))) {
                return false;
            }

            $this->invalidate_opcache($path);

            return true;
        } finally {
            $this->release_lock($handle);
        }
    }

    /**
     * Build the lock file path for a config file.
     *
     * This is the only place that builds the lock path. Callers such as
     * uninstall() call it. Lock and data file share one filesystem.
     *
     * @param string $path Data file path.
     * @return string Lock file path.
     */
    public function lock_path_for(string $path): string
    {
        return dirname($path) . '/.' . basename($path) . '.lock';
    }

    /**
     * Try to acquire an exclusive non-blocking lock with a bounded retry.
     *
     * The retry is bounded and non-blocking. PHP has no timeout for a
     * blocking flock(), so a hung holder would hang the request forever.
     *
     * @param string $lock_path Lock file path.
     * @return resource|null Lock handle, or null when locking is unavailable.
     */
    private function acquire_lock(string $lock_path)
    {
        if (!function_exists('flock')) {
            $this->logger?->info('File lock unavailable: flock() is disabled; continuing unlocked', [
                'lock' => $lock_path,
            ]);
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Lock files use local fopen; WP_Filesystem cannot hand out lock handles.
        $handle = @fopen($lock_path, 'c');
        if ($handle === false) {
            $this->logger?->warning('File lock could not be created; continuing unlocked', [
                'lock' => $lock_path,
            ]);
            return null;
        }

        for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                // Widen the mode after the lock. The FPM user and the CLI
                // user can differ, and the file holds no data.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Lock files use local permissions.
                @chmod($lock_path, 0666);

                return $handle;
            }

            usleep(self::LOCK_RETRY_MICROSECONDS);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local lock file handle.
        @fclose($handle);
        $this->logger?->warning('File lock stayed busy; continuing unlocked', [
            'lock' => $lock_path,
        ]);

        return null;
    }

    /**
     * Release the lock. Safe to call with a null handle.
     *
     * @param resource|null $handle Lock handle from acquire_lock().
     * @return void
     */
    private function release_lock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Local lock file handle.
        @flock($handle, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local lock file handle.
        @fclose($handle);
    }

    /**
     * Read a PHP file that contains one `return array(...)`.
     *
     * A missing, unreadable, or corrupt file starts from an empty array so a
     * later write self-heals the file. Corrupt files log a warning.
     *
     * @param string $path Config file path.
     * @return array
     */
    private function read_php_array(string $path): array
    {
        if (!@is_readable($path)) {
            return [];
        }

        $loaded = null;
        $parse_error = null;
        $ob_level = ob_get_level();

        ob_start();
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_include -- The PHP config file must execute to return its array.
            $loaded = @include $path;
        } catch (\Throwable $exception) {
            $parse_error = $exception;
        } finally {
            // Discard stray output a corrupted file may emit on include.
            // Close only our own buffer, never an outer one.
            if (ob_get_level() > $ob_level) {
                ob_end_clean();
            }
        }

        if ($parse_error !== null) {
            $this->logger?->warning('PHP config file could not be parsed; starting from an empty array', [
                'path' => $path,
                'error' => $parse_error->getMessage(),
            ]);

            return [];
        }

        if (!is_array($loaded)) {
            $this->logger?->warning('PHP config file did not return an array; starting from an empty array', [
                'path' => $path,
            ]);

            return [];
        }

        return $loaded;
    }

    /**
     * Render a PHP config file body: open tag, ABSPATH guard, one return.
     *
     * This is the only place that composes the file text. var_export
     * serializes data only, so plugin-written content can never contain code.
     *
     * @param array $data Config data to store.
     * @return string File content.
     */
    private function render_php_config(array $data): string
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export builds the config file literal; it serializes data only.
        $exported = var_export($data, true);

        return "<?php\n"
            . "if (!defined('ABSPATH')) {\n"
            . "    return;\n"
            . "}\n"
            . 'return ' . $exported . ";\n";
    }

    /**
     * Refresh OPcache for a rewritten PHP file. Never a hard dependency.
     *
     * @param string $path Rewritten file path.
     * @return void
     */
    private function invalidate_opcache(string $path): void
    {
        if (!function_exists('wp_opcache_invalidate')) {
            return;
        }

        wp_opcache_invalidate($path, true);
    }

    /**
     * Copy a file.
     *
     * @param string $source Source file
     * @param string $destination Destination file
     * @param bool $overwrite Overwrite existing file
     * @return bool
     */
    public function copy(string $source, string $destination, bool $overwrite = false): bool
    {
        if (!$overwrite && @file_exists($destination)) {
            return false;
        }

        return @copy($source, $destination);
    }

    /**
     * Check if a file or directory is writable.
     *
     * @param string $path File or directory path
     * @return bool
     */
    public function is_writable(string $path): bool
    {
        if (!@file_exists($path)) {
            // Path doesn't exist, check if parent is writable
            $parent_dir = dirname($path);
            if ($parent_dir === $path) {
                return false;
            }
            return $this->is_writable($parent_dir);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- This checks local runtime cache permissions without initializing WP_Filesystem.
        return @is_writable($path);
    }

    /**
     * Check if a file exists.
     *
     * @param string $path File path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return @file_exists($path);
    }

    /**
     * Create a directory.
     *
     * @param string $path Directory path
     * @return bool
     */
    public function mkdir(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Recursive local cache creation must work during runtime without filesystem credentials.
        return @mkdir($path, 0755, true);
    }

    /**
     * Get the RunCache config directory inside the uploads folder.
     *
     * @return string|false Directory path with trailing slash, or false when the uploads directory cannot be resolved.
     */
    public function get_config_dir(): string|false
    {
        if (!function_exists('wp_upload_dir')) {
            return false;
        }

        // Never create date-based subdirectories; we only need basedir.
        $uploads = wp_upload_dir(null, false);

        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            return false;
        }

        return trailingslashit(wp_normalize_path($uploads['basedir'])) . 'runcache/';
    }

    /**
     * Ensure the RunCache config directory exists with protection files.
     *
     * Creates uploads/runcache/ when missing and writes an index.html
     * sentinel plus an .htaccess deny file so generated configuration
     * files are not exposed directly on Apache or LiteSpeed servers.
     *
     * @return string|false Directory path with trailing slash, or false on failure.
     */
    public function ensure_config_dir(): string|false
    {
        $dir = $this->get_config_dir();

        if ($dir === false) {
            return false;
        }

        if (!$this->mkdir($dir)) {
            return false;
        }

        $index_file = $dir . 'index.html';
        if (!$this->exists($index_file)) {
            $this->write($index_file, '<!-- Silence is golden - RunCache protected directory -->');
        }

        $htaccess_file = $dir . '.htaccess';
        if (!$this->exists($htaccess_file)) {
            $this->write(
                $htaccess_file,
                "# RunCache: deny direct access to generated configuration files\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order deny,allow\n"
                . "    Deny from all\n"
                . "</IfModule>\n"
            );
        }

        return $dir;
    }

    /**
     * Get file size.
     *
     * @param string $path File path
     * @return int
     */
    public function size(string $path): int
    {
        $size = @filesize($path);
        return $size !== false ? (int) $size : 0;
    }

    /**
     * List files in directory.
     *
     * @param string $path Directory path
     * @param bool $recursive Recursively list subdirectories
     * @param bool $include_hidden Include hidden files
     * @return array|false
     */
    public function dirlist(string $path, bool $recursive = false, bool $include_hidden = true): array|false
    {
        if (!is_dir($path)) {
            return false;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }

        $result = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (!$include_hidden && str_starts_with($name, '.')) {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $name;
            $is_dir = @is_dir($full) && !@is_link($full);

            $entry = [
                'name' => $name,
                'type' => $is_dir ? 'd' : 'f',
                'size' => (int) ($is_dir ? 0 : @filesize($full)),
                'lastmodunix' => (int) @filemtime($full),
            ];

            if ($recursive && $is_dir) {
                $sub = $this->dirlist($full, true, $include_hidden);
                $entry['files'] = is_array($sub) ? $sub : [];
            }

            $result[$name] = $entry;
        }

        return $result;
    }

    /**
     * Check if we have both read and write permissions for a file.
     *
     * Tests permissions by attempting to actually read the file.
     *
     * @param string $path File path to check
     * @return array Returns array with 'readable', 'writable', and 'success' keys
     *               readable: bool - if file can be read
     *               writable: bool - if file can be written
     *               success: bool - if both checks pass
     */
    public function check_permissions(string $path): array
    {
        $result = [
            'readable' => false,
            'writable' => false,
            'success' => false,
        ];

        if (!$this->exists($path)) {
            return $result;
        }

        $content = $this->read($path);
        $result['readable'] = $content !== false;

        $result['writable'] = $this->is_writable($path);

        $result['success'] = $result['readable'] && $result['writable'];

        return $result;
    }

    /**
     * Locate wp-config.php — checks both ABSPATH and one directory above.
     *
     * @return string|false The normalized path to wp-config.php, or false if not found
     */
    public function locate_wp_config(): string|false
    {
        $primary = wp_normalize_path(ABSPATH . 'wp-config.php');
        if ($this->exists($primary)) {
            return $primary;
        }

        $alt = wp_normalize_path(dirname(ABSPATH) . '/wp-config.php');
        if ($this->exists($alt) && !$this->exists(dirname(ABSPATH) . '/wp-settings.php')) {
            return $alt;
        }

        return false;
    }
}
