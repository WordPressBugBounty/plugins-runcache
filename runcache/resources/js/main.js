import { createApp } from 'vue'
import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query'
import App from '@/App.vue'
import router from '@/router.js'
import '../css/main.css'

// Get base URL from WordPress
const baseUrl = window.runcacheBaseUrl || '/wp-content/plugins/runcache/assets/'

// Derive fallback site URL for relative purge paths
const defaultSiteUrl = (() => {
    if (typeof window === 'undefined') {
        return '/'
    }

    if (window.location?.origin) {
        return window.location.origin
    }

    return `${window.location.protocol}//${window.location.host}`
})()

// Ensure runcacheAjax exists with defaults
if (!window.runcacheAjax) {
    window.runcacheAjax = {
        ajaxurl: '/wp-admin/admin-ajax.php',
        nonce: '',
        siteUrl: defaultSiteUrl,
        strings: {
            loading: 'Loading...',
            error: 'An error occurred',
            success: 'Success!',
        },
    }
} else if (!window.runcacheAjax.siteUrl) {
    window.runcacheAjax.siteUrl = defaultSiteUrl
}

// Function to initialize Vue app
function initializeApp() {
    // Check if target element exists
    const targetElement = document.getElementById('runcache-admin-app')
    if (!targetElement) {
        return
    }

    // Clear any existing content to prevent hydration issues
    targetElement.innerHTML = ''

    try {
        // Create QueryClient with proper defaults
        const queryClient = new QueryClient({
            defaultOptions: {
                queries: {
                    staleTime: 5 * 60 * 1000, // 5 minutes
                    gcTime: 10 * 60 * 1000, // 10 minutes (previously cacheTime)
                    retry: (failureCount, error) => {
                        // Don't retry on validation errors
                        if (
                            error?.message?.includes('validation') ||
                            error?.message?.includes('invalid')
                        ) {
                            return false
                        }
                        return failureCount < 2
                    },
                    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
                    refetchOnWindowFocus: false,
                    refetchOnReconnect: true,
                },
                mutations: {
                    retry: false,
                },
            },
        })

        // Create Vue app
        const app = createApp(App)

        // Configure app
        app.config.globalProperties.$baseUrl = baseUrl

        // Add error handler for Vue app
        app.config.errorHandler = (err, _instance, info) => {
            // Only log errors in development
            if (process.env.NODE_ENV === 'development') {
                console.error('Vue error:', err, info)
            }
        }

        // Suppress hydration warnings for WordPress admin interface
        app.config.warnHandler = (msg, _instance, trace) => {
            // Suppress hydration mismatch warnings in WordPress admin
            if (msg.includes('Hydration') || msg.includes('mismatch')) {
                return
            }
            if (process.env.NODE_ENV === 'development') {
                console.warn('Vue warning:', msg, trace)
            }
        }

        // Use router
        app.use(router)

        // Use TanStack Query with configured QueryClient
        app.use(VueQueryPlugin, { queryClient })

        // Mount app
        app.mount('#runcache-admin-app')
    } catch (error) {
        // Keep the fallback response generic; detailed errors stay in the console.
        console.error('Failed to load RunCache admin interface:', error)
        targetElement.innerHTML = `
            <div style="padding:rce- rce-20px; background:rce- rce-#fff; border:rce- rce-1px rce-solid rce-#ccd0d4; border-radius:rce- rce-4px;">
                <h3>RunCache Admin</h3>
                <p>There was an error loading the admin interface. Please refresh the page.</p>
                <p><small>Please check the browser console or server logs for details.</small></p>
            </div>
        `
    }
}

// Wait for DOM to be ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeApp)
} else {
    // DOM is already ready
    initializeApp()
}
