import { toast } from 'vue-sonner'

/**
 * Make an AJAX call to WordPress admin-ajax.php
 *
 * @param {string} action - WordPress AJAX action name
 * @param {Object} params - Additional parameters to send
 * @returns {Promise} - Resolves with the response data
 */
async function ajaxRequest(action, params = {}) {
    // Wait for WordPress to be ready
    if (!window.wp || !window.runcacheAjax) {
        throw new Error('WordPress is not properly initialized')
    }

    const nonce = window.runcacheAjax?.nonce || ''
    const ajaxUrl = window.runcacheAjax?.ajaxurl || '/wp-admin/admin-ajax.php'

    try {
        const body = new URLSearchParams({
            action,
            _ajax_nonce: nonce,
            ...params,
        }).toString()

        const response = await fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        })

        if (!response.ok) {
            // WordPress puts the useful message in the JSON body, even on 4xx codes.
            let data = null
            try {
                data = await response.json()
            } catch (parseError) {
                data = null
            }

            if (data && typeof data === 'object' && data.success === false && data.data?.message) {
                throw new Error(data.data.message)
            }

            throw new Error(`HTTP error! status: ${response.status}`)
        }

        const data = await response.json()

        // Handle both WordPress-style responses and our custom responses
        if (data === 0 || data === -1) {
            throw new Error('Invalid nonce or insufficient permissions')
        }

        // If data is already in the correct format, return it
        if (typeof data === 'object' && 'success' in data) {
            return data
        }

        // Otherwise, wrap it in a success response
        return {
            success: true,
            data,
        }
    } catch (error) {
        console.error('AJAX Error:', error)
        throw error
    }
}

/**
 * Base API Client
 * Provides standardized request/response handling for all API modules
 *
 * This module handles low-level HTTP communication with WordPress backend,
 * including response processing, error handling, and toast notifications.
 *
 * It's designed to be used by higher-level API modules (cache.js, settings.js, etc.)
 * and TanStack Query composables.
 */

/**
 * Make a standardized API request with automatic toast handling
 * @param {string} action - WordPress AJAX action name
 * @param {Object} params - Additional parameters to send
 * @param {Object} options - Request options
 * @param {boolean} options.showToast - Whether to show automatic toasts (default: true)
 * @param {boolean} options.silent - Whether to suppress all toasts (default: false)
 * @returns {Promise} - Resolves with normalized response data
 */
export async function apiRequest(action, params = {}, options = {}) {
    const { showToast = true, silent = false } = options

    try {
        const response = await ajaxRequest(action, params)

        // Handle successful responses
        if (response?.success) {
            const data = response.data || response

            // Show appropriate toast if enabled and message exists
            if (!silent && showToast && data?.message) {
                const message = data.message.toLowerCase()

                // Check if message indicates configuration or reload is needed (warning)
                const isConfigurationMessage =
                    message.includes('please configure') ||
                    message.includes('configuration required') ||
                    message.includes('please reload')

                // Check if message indicates verification failure (error)
                const isVerificationFailure =
                    message.includes('verification failed') ||
                    message.includes('connection failed') ||
                    message.includes('failed. please check')

                if (isConfigurationMessage) {
                    toast.warning(data.message)
                } else if (isVerificationFailure) {
                    toast.error(data.message)
                } else {
                    toast.success(data.message)
                }
            }

            return data
        }

        // Handle error responses
        const errorMessage = response?.data?.message || `Failed to execute ${action}`

        // Show error toast if enabled
        const error = new Error(errorMessage)

        if (!silent && showToast) {
            toast.error(errorMessage)
            error.toastHandled = true
        }

        throw error
    } catch (error) {
        // Avoid showing the same API error twice: once in the response branch and again here.
        if (!silent && showToast && !error.toastHandled) {
            toast.error(error.message || 'An unexpected error occurred')
            error.toastHandled = true
        }

        throw error
    }
}

/**
 * GET request pattern for data fetching (usually silent)
 * @param {string} action - WordPress AJAX action name
 * @param {Object} options - Request options
 * @returns {Promise} - Resolves with response data
 */
export async function apiGet(action, options = {}) {
    // GET requests are typically silent unless explicitly requested to show toasts
    const defaultOptions = { showToast: false, ...options }
    return apiRequest(action, {}, defaultOptions)
}

/**
 * POST request pattern for data mutations (shows toasts by default)
 * @param {string} action - WordPress AJAX action name
 * @param {Object} data - Data to send (will be JSON stringified for settings)
 * @param {Object} options - Request options
 * @returns {Promise} - Resolves with response data
 */
export async function apiPost(action, data, options = {}) {
    const params = data ? { settings: JSON.stringify(data) } : {}
    // POST requests show toasts by default since they're usually user actions
    const defaultOptions = { showToast: true, ...options }
    return apiRequest(action, params, defaultOptions)
}

/**
 * Custom request for non-standard parameter formats (shows toasts by default)
 * @param {string} action - WordPress AJAX action name
 * @param {Object} params - Raw parameters to send
 * @param {Object} options - Request options
 * @returns {Promise} - Resolves with response data
 */
export async function apiCustom(action, params = {}, options = {}) {
    // Custom requests show toasts by default since they're usually user actions
    const defaultOptions = { showToast: true, ...options }
    return apiRequest(action, params, defaultOptions)
}

/**
 * Main API client object
 * Provides all request methods in a single object for convenience
 */
export const apiClient = {
    request: apiRequest,
    get: apiGet,
    post: apiPost,
    custom: apiCustom,
}

/**
 * Default export for convenience
 * Can be imported as: import apiClient from '@/api/client'
 */
export default apiClient
