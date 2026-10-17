import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { viteStaticCopy } from 'vite-plugin-static-copy'
import { resolve } from 'path'
import crypto from 'node:crypto'

if (typeof crypto.hash !== 'function') {
    crypto.hash = (algorithm, data, encoding = 'hex') => {
        const hash = crypto.createHash(algorithm)
        hash.update(typeof data === 'string' ? data : Buffer.from(data))
        return hash.digest(encoding)
    }
}

// Get WordPress site URL from environment or use default
const wpSiteUrl = process.env.WP_SITE_URL || 'http://localhost'

export default defineConfig({
    plugins: [
        vue(),
        viteStaticCopy({
            targets: [
                {
                    src: 'resources/images/**/*',
                    dest: 'images',
                    rename: { stripBase: 2 },
                },
            ],
        }),
    ],
    base: process.env.NODE_ENV === 'development' ? '/' : '/wp-content/plugins/runcache/assets/',
    build: {
        outDir: 'assets',
        emptyOutDir: true,
        manifest: true,
        target: 'esnext', // Ensure ES modules output
        minify: 'terser',
        terserOptions: {
            compress: {
                drop_console: true,
                drop_debugger: true,
            },
        },
        rollupOptions: {
            input: {
                main: resolve(__dirname, 'resources/js/main.js'),
            },
            output: {
                format: 'es', // Ensure ES module format
                entryFileNames: 'js/[name].js',
                chunkFileNames: 'js/[name]-[hash].js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name.endsWith('.css')) {
                        return 'css/main[extname]'
                    }
                    return 'assets/[name]-[hash][extname]'
                },
                manualChunks: (id) => {
                    // Keep page components as separate chunks
                    if (id.includes('pages/Dashboard')) return 'dashboard'
                    if (id.includes('pages/Purger')) return 'purger'
                    if (id.includes('pages/Preloader')) return 'preloader'
                    if (id.includes('pages/Rules')) return 'rules'
                    // Bundle everything else (including UI components) into main
                    return 'main'
                },
            },
        },
        cssCodeSplit: false, // Force all CSS into single file
    },
    server: {
        host: '0.0.0.0', // Allow external connections in Docker
        cors: true,
        port: 5174, // Unique port to avoid conflicts with other Vite projects
        strictPort: true,
        allowedHosts: ['localhost', 'vite', '127.0.0.1', '0.0.0.0', '192.168.64.1'], // Allow Docker and Multipass VM access
        hmr: {
            protocol: 'ws',
            host: 'localhost',
            port: 5174,
            clientPort: 5174,
        },
        headers: {
            'Access-Control-Allow-Origin': '*',
        },
        watch: {
            usePolling: true, // Better file watching in Docker
        },
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            '@/lib': resolve(__dirname, 'resources/js/lib'),
            '@/components': resolve(__dirname, 'resources/js/components'),
        },
    },
})
