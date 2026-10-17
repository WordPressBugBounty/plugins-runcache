<template>
    <Card class="rce-mb-7 rce-rounded-t-none rce-border-t-0">
        <CardHeader>
            <div
                class="rce-flex rce-flex-col md:rce-flex-row md:rce-items-center rce-justify-between rce-relative">
                <CardTitle class="rce-mb-4 md:rce-mb-0">Full Page Cache</CardTitle>

                <div
                    class="rce-flex rce-flex-wrap-reverse md:rce-flex-wrap rce-items-center rce-gap-2 md:rce-gap-2 md:rce-absolute md:rce-right-0">
                    <Button
                        variant="link"
                        class="!rce-px-0 md:rce-px-4 rce-py-1! rce-h-auto"
                        v-if="enabled"
                        :disabled="isVerifying"
                        @click="verify(true)">
                        <LoaderCircle
                            v-if="isVerifying"
                            :size="20"
                            :stroke-width="1.5"
                            class="rce-animate-spin" />
                        {{ isVerifying ? 'Verifying...' : 'Verify Cache' }}
                    </Button>

                    <Badge :variant="statusMessage.variant">
                        {{ statusMessage.message }}
                    </Badge>
                </div>
            </div>
        </CardHeader>

        <CardContent class="rce-space-y-6">
            <!-- Cache Type Selection -->
            <div v-if="enabled" class="rce-space-y-6">
                <!-- Server-specific Alerts -->
                <template v-for="(alert, key) in serverAlerts" :key="key">
                    <Alert v-if="!alert.hide" variant="info" class="rce-mb-4">
                        <Zap class="rce-mt-0.5 rce-size-4" :stroke-width="2" />
                        <AlertTitle>{{ alert.title }}</AlertTitle>
                        <AlertDescription
                            class="rce-mt-0.5 rce-flex md:rce-flex-row rce-flex-col md:rce-items-center rce-gap-2">
                            <div>{{ alert.description }}</div>
                            <a
                                v-if="alert.link"
                                :href="alert.link"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="rce-font-medium rce-underline-offset-4 rce-py-2 rce-shrink-0 rce-text-sky-500 hover:rce-text-sky-600 rce-underline">
                                {{ alert.linkText }}
                            </a>
                        </AlertDescription>
                    </Alert>
                </template>

                <div class="rce-space-y-1">
                    <h3 class="rce-text-sm rce-font-medium rce-text-gray-700">
                        Choose a Cache Type
                    </h3>
                    <p class="rce-text-sm rce-text-gray-500">
                        Choose your preferred caching method
                    </p>
                </div>

                <!-- Cache Type Grid -->
                <div class="rce-grid rce-grid-cols-1 md:rce-grid-cols-3 rce-gap-6">
                    <div
                        v-for="option in providers"
                        :key="option.value"
                        :class="[
                            'rce-rounded-xl rce-border rce-p-6 rce-transition-all',
                            option.value === activeType
                                ? 'rce-border-primary-500 rce-ring-[1.5px] rce-ring-primary-500/20 rce-ring-offset-[1.5px]'
                                : 'rce-border-gray-200',
                            option.disabled ? 'rce-opacity-60 rce-cursor-not-allowed' : '',
                            option.hide ? 'rce-hidden' : '',
                        ]">
                        <div class="rce-space-y-4">
                            <div
                                class="rce-flex rce-items-center rce-justify-between rce-gap-3 rce-relative">
                                <div class="rce-flex rce-items-center rce-gap-3">
                                    <Switch
                                        :model-value="option.value === activeType"
                                        :disabled="option.disabled || isChanging"
                                        @update:model-value="toggleProvider(option.value)" />
                                    <div class="rce-flex rce-items-center rce-gap-2">
                                        <h3 class="rce-text-sm rce-font-semibold rce-text-gray-900">
                                            {{ option.label }}
                                        </h3>
                                        <LoaderCircle
                                            v-if="isChanging && targetProvider === option.value"
                                            :size="16"
                                            class="rce-animate-spin rce-text-gray-500" />
                                    </div>
                                </div>
                                <Badge
                                    variant="success"
                                    :class="[
                                        'rce-shrink-0',
                                        option.value !== activeType ? 'rce-invisible' : '',
                                    ]">
                                    Active
                                </Badge>
                            </div>
                            <p class="rce-text-base rce-text-gray-600 rce-leading-relaxed">
                                {{ option.description }}
                            </p>
                            <p
                                v-if="option.disabled && option.disabledMessage"
                                class="rce-text-xs rce-text-red-500 rce-font-medium">
                                {{ option.disabledMessage }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Configuration Sections (Below Grid) -->
                <div
                    v-if="
                        enabled &&
                        activeType !== 'php' &&
                        cacheVerified === false &&
                        configurationSection
                    "
                    class="rce-mt-6">
                    <component
                        :is="configurationSection.component"
                        v-bind="configurationSection.props"
                        @saved="configurationSection.onSaved" />
                </div>
            </div>
        </CardContent>
    </Card>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { LoaderCircle, Zap } from 'lucide-vue-next'
import { toast } from 'vue-sonner'

import { cacheApi } from '@/api/cache'
import { integrationsApi } from '@/api/integrations'
import InstructionsForApache from '@/components/InstructionsForApache.vue'
import InstructionsForFastcgi from '@/components/InstructionsForFastcgi.vue'
import InstructionsForLitespeed from '@/components/InstructionsForLitespeed.vue'
import InstructionsForNginx from '@/components/InstructionsForNginx.vue'
import InstructionsForRedis from '@/components/InstructionsForRedis.vue'
import InstructionsForRuncloud from '@/components/InstructionsForRuncloud.vue'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { getCacheEnableToastMessage } from '@/utils/cacheEnableToast'
import { PAGE_CACHE_CONFIG } from '@/utils/IntegrationConfig'
import { checkPreloadStatus } from '@/utils/preloader'
import { detectServerType } from '@/utils/serverDetect'
import { runPreload, usePagePreloader } from '@/utils/pagePreloader'

const enabled = ref(false)
const activeType = ref('php')
const pagePreloader = usePagePreloader()

const selectedCacheType = ref('php')
const targetProvider = ref('')
const cacheVerified = ref(null)
const isChanging = ref(false)
const isVerifying = ref(false)
const serverType = ref('')
const documentRoot = ref('')
const configDir = ref('')
const webappName = ref('')
const siteUrl = ref('')
const siteName = ref('')
const isCheckingPhpRedis = ref(true)
const isPhpRedisAvailable = ref(false)
const phpRedisMessage = ref('Checking Redis extension availability...')
const runcloudConnection = ref(false)

const isNginxServer = computed(() => serverType.value === 'nginx')
const isRunCloudServer = computed(() => serverType.value === 'nginx-rc')
// Unknown server type: show generic instructions for the active cache type.
const isServerUnknown = computed(() => serverType.value === '')
const hasRunCloudConnection = computed(() => isRunCloudServer.value && runcloudConnection.value)
const supportsRunCloudConfiguration = computed(
    () => isRunCloudServer.value && !runcloudConnection.value
)

// Cache type configuration - combine shared metadata with reactive page-cache state.
const providers = computed(() => {
    const baseOptions = {
        disableLabel: 'Disable',
        enableLabel: 'Enable',
        disabled: false,
        disabledMessage: '',
    }

    return {
        php: {
            ...PAGE_CACHE_CONFIG.php,
            ...baseOptions,
            disableLabel: '',
            hide: true,
        },
        'runcloud-native': {
            ...PAGE_CACHE_CONFIG['runcloud-native'],
            ...baseOptions,
            hide: !hasRunCloudConnection.value,
        },
        'runcloud-srcache': {
            ...PAGE_CACHE_CONFIG['runcloud-srcache'],
            ...baseOptions,
            hide: !hasRunCloudConnection.value,
        },
        nginx: {
            ...PAGE_CACHE_CONFIG.nginx,
            ...baseOptions,
            hide: !isNginxServer.value && !supportsRunCloudConfiguration.value,
        },
        fastcgi: {
            ...PAGE_CACHE_CONFIG.fastcgi,
            ...baseOptions,
            hide: !isNginxServer.value && !supportsRunCloudConfiguration.value,
        },
        apache: {
            ...PAGE_CACHE_CONFIG.apache,
            ...baseOptions,
            hide: serverType.value !== 'apache',
        },
        litespeed: {
            ...PAGE_CACHE_CONFIG.litespeed,
            ...baseOptions,
            hide: serverType.value !== 'litespeed',
        },
        redis: {
            ...PAGE_CACHE_CONFIG.redis,
            ...baseOptions,
            disabled: !isPhpRedisAvailable.value,
            disabledMessage: !isPhpRedisAvailable.value
                ? '⚠️ Please install php-redis extension.'
                : '',
            hide: false,
        },
    }
})

// Configuration components and their server-specific props
const configurationDefinitions = {
    redis: {
        component: InstructionsForRedis,
        props: () => ({}),
        onSaved: (...args) => handleConnectionSaved(...args),
        isAvailable: () => true,
    },
    nginx: {
        component: () =>
            supportsRunCloudConfiguration.value ? InstructionsForRuncloud : InstructionsForNginx,
        props: () =>
            supportsRunCloudConfiguration.value
                ? {
                      webappName: webappName.value,
                      configDir: configDir.value,
                  }
                : { configDir: configDir.value },
        isAvailable: () => isNginxServer.value || isRunCloudServer.value || isServerUnknown.value,
    },
    fastcgi: {
        component: InstructionsForFastcgi,
        props: () => ({
            configDir: configDir.value,
            siteUrl: siteUrl.value,
            siteName: siteName.value,
        }),
        isAvailable: () =>
            isNginxServer.value || supportsRunCloudConfiguration.value || isServerUnknown.value,
    },
    apache: {
        component: InstructionsForApache,
        props: () => ({}),
        isAvailable: () => true,
    },
    litespeed: {
        component: InstructionsForLitespeed,
        props: () => ({}),
        isAvailable: () => true,
    },
    'runcloud-native': {
        component: InstructionsForRuncloud,
        props: () => ({
            webappName: webappName.value,
            configDir: configDir.value,
        }),
        isAvailable: () => isRunCloudServer.value || isServerUnknown.value,
    },
    'runcloud-srcache': {
        component: InstructionsForRuncloud,
        props: () => ({
            webappName: webappName.value,
            configDir: configDir.value,
        }),
        isAvailable: () => isRunCloudServer.value || isServerUnknown.value,
    },
}

const configurationSection = computed(() => {
    const definition = configurationDefinitions[activeType.value]

    if (!definition || !definition.isAvailable()) return null

    return {
        component:
            typeof definition.component === 'function'
                ? definition.component()
                : definition.component,
        props: definition.props(),
        onSaved: definition.onSaved,
    }
})

// Server alerts configuration
const serverAlerts = computed(() => ({
    'nginx-rc': {
        serverType: 'nginx-rc',
        title: 'RunCloud Server Detected',
        description:
            'We recommend configuring caching from your RunCloud Dashboard → Performance section. This will install an exclusive optimized version of the plugin with pre-configured settings tailored for RunCloud servers.',
        link: 'https://manage.runcloud.io/webapplications',
        linkText: 'Open RunCloud Dashboard',
        hide: !supportsRunCloudConfiguration.value,
    },
    nginx: {
        serverType: 'nginx',
        title: 'NGINX Server Detected',
        description:
            'NGINX FastCGI Cache is recommended for best performance, but requires more advanced configuration. For a simpler setup, use NGINX Page Cache.',
        link: '',
        linkText: '',
        hide: !isNginxServer.value,
    },
    apache: {
        serverType: 'apache',
        title: 'Recommended for Apache servers',
        description:
            'Apache Cache is the recommended caching solution for Apache servers. It provides the fastest, most reliable performance with easy management.',
        link: '',
        linkText: '',
        hide: serverType.value !== 'apache',
    },
    litespeed: {
        serverType: 'litespeed',
        title: 'Recommended for LiteSpeed servers',
        description:
            'LiteSpeed Cache is the recommended caching solution for LiteSpeed servers. It provides the fastest, most reliable performance with easy management.',
        link: '',
        linkText: '',
        hide: serverType.value !== 'litespeed',
    },
}))

const toggleProvider = async (type) => {
    if (isChanging.value) return

    const targetType = type === activeType.value && type !== 'php' ? 'php' : type

    if (targetType === activeType.value && enabled.value) return

    selectedCacheType.value = targetType
    await applyCacheType()
}

// Apply selected cache type
const waitForCacheSettingsToApply = () => new Promise((resolve) => setTimeout(resolve, 1000))

const applyCacheType = async () => {
    const nextCacheType = selectedCacheType.value

    if (isChanging.value || !nextCacheType) return

    try {
        targetProvider.value = nextCacheType
        isChanging.value = true

        const newSettings = {
            enable: true,
            page_cache_type: nextCacheType,
        }

        await saveSettings(newSettings)
        await waitForCacheSettingsToApply()
        await verify(false)
    } catch (error) {
        console.error('Failed to apply cache type:', error)
        toast.error('Failed to update Full Page Cache settings. Please try again.')
    } finally {
        isChanging.value = false
        targetProvider.value = ''
    }
}

// Status message computed
const statusMessage = computed(() => {
    if (!enabled.value) {
        return { message: 'Disabled', variant: 'secondary' }
    }
    if (cacheVerified.value === null) {
        return { message: 'Checking configuration...', variant: 'secondary' }
    }
    if (cacheVerified.value === true) {
        return {
            message: `${providers.value[activeType.value]?.label || activeType.value} is Enabled and Working`,
            variant: 'success',
        }
    }
    if (activeType.value) {
        return {
            message: `${providers.value[activeType.value]?.label || activeType.value} - Configuration Required`,
            variant: 'warning',
        }
    }
    return { message: 'Configuration Pending', variant: 'secondary' }
})

// Check PHP Redis availability
const checkPhpRedisAvailability = async () => {
    isCheckingPhpRedis.value = true
    try {
        const data = await integrationsApi.system.checkPhpRedis()
        isPhpRedisAvailable.value = data.is_available
        phpRedisMessage.value = data.message
    } catch (error) {
        isPhpRedisAvailable.value = false
        phpRedisMessage.value =
            'Failed to check phpredis availability. Please contact your system administrator.'
        console.error('Failed to check phpredis:', error)
    } finally {
        isCheckingPhpRedis.value = false
    }
}

// Get server type and document root
// Removed - server info now comes from cache settings

// Process settings data into reactive refs
const processSettings = (data) => {
    if (!data) return

    enabled.value = Boolean(data.enable)
    activeType.value = enabled.value ? data.page_cache_type || 'php' : 'php'
    selectedCacheType.value = activeType.value
    runcloudConnection.value = Boolean(data.runcloud?.connected)

    // Set server information
    serverType.value = data.server_type || ''
    documentRoot.value = data.document_root || ''
    configDir.value = data.config_dir || documentRoot.value
    webappName.value = documentRoot.value.split('/').filter(Boolean).pop() || ''
    siteUrl.value = data.site_url || ''
    siteName.value = data.site_name || ''

    // Silent background detect when the server type is unknown.
    // The option grid reacts to serverType once the value lands.
    if (!data.server_type) {
        detectServerType().then((info) => {
            if (!info) return

            serverType.value = info.server_type || ''
            documentRoot.value = info.document_root || documentRoot.value
            configDir.value = info.config_dir || documentRoot.value
            webappName.value = documentRoot.value.split('/').filter(Boolean).pop() || ''
            siteUrl.value = info.site_url || siteUrl.value
            siteName.value = info.site_name || siteName.value
        })
    }

    return data
}

// Refresh settings from API (used after saving or when no cached settings available)
const refresh = async () => {
    try {
        const data = await cacheApi.getSettings()
        processSettings(data)
        return data
    } catch (error) {
        console.error('Settings refresh error:', error)
        toast.error('Unable to load Full Page Cache settings. Please refresh and try again.')
    }
}

// Verify cache setup
const verify = async (showToast = true) => {
    if (isVerifying.value) return
    isVerifying.value = true

    try {
        const data = await integrationsApi.pageCache.verify({ showToast })

        if (typeof data.enable !== 'undefined') {
            enabled.value = Boolean(data.enable)
        }
        activeType.value = enabled.value ? data.type || 'php' : 'php'
        cacheVerified.value = data.verified

        // Show success toast if showToast is true and verification succeeded
        if (showToast && data.verified) {
            const cacheTypeName = PAGE_CACHE_CONFIG[activeType.value]?.label || activeType.value
            toast.success(`${cacheTypeName} verification completed successfully!`)
        }
    } catch (error) {
        console.error('Cache verification error:', error)
        toast.error(
            'Full Page Cache verification failed. Please check your configuration and try again.'
        )
        throw error
    } finally {
        isVerifying.value = false
    }
}

// Save cache settings
const saveSettings = async ({
    enable = enabled.value,
    page_cache_type = activeType.value,
    loadingState = null,
}) => {
    if (loadingState?.value) return null
    if (loadingState) loadingState.value = true

    try {
        const settings = await cacheApi.saveSettings({
            enable,
            page_cache_type,
        })

        enabled.value = Boolean(settings.enable)
        const previousCache = activeType.value
        activeType.value = enabled.value ? settings.page_cache_type || 'php' : 'php'
        cacheVerified.value = Boolean(settings.verified)

        // Show appropriate toast notification based on cache state
        const toastNotification = getCacheEnableToastMessage({
            enable,
            pageCacheType: activeType.value,
            previousCache,
            cacheVerified: cacheVerified.value,
        })
        toast[toastNotification.type](toastNotification.message)

        return settings
    } catch (error) {
        console.error('Save settings error:', error)
        throw error
    } finally {
        if (loadingState) loadingState.value = false
    }
}

const handleConnectionSaved = async () => {
    try {
        await refresh()
        await waitForCacheSettingsToApply()
        // Verify cache after saving Redis configuration to update status
        await verify(false)
    } catch (error) {
        console.error('Error refreshing Full Page Cache settings:', error)
        toast.error('Failed to refresh Full Page Cache settings. Please try again.')
    }
}

onMounted(async () => {
    await runPreload(pagePreloader, () => refresh())
    await runPreload(pagePreloader, () => checkPhpRedisAvailability())
    await runPreload(pagePreloader, () => verify(false))
    await runPreload(pagePreloader, () => checkPreloadStatus())
})
</script>
