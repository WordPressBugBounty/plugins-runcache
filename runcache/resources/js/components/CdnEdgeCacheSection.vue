<template>
    <Card class="rce-mb-7 rce-rounded-t-none rce-border-t-0">
        <CardHeader>
            <div
                class="rce-flex rce-flex-col md:rce-flex-row md:rce-items-center rce-justify-between rce-relative">
                <CardTitle class="rce-mb-4 md:rce-mb-0">CDN Edge Cache</CardTitle>

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
                        {{ isVerifying ? 'Verifying...' : 'Verify Edge Cache' }}
                    </Button>

                    <Badge :variant="statusMessage.variant">
                        {{ statusMessage.message }}
                    </Badge>
                </div>
            </div>
        </CardHeader>

        <CardContent class="rce-space-y-6">
            <div class="rce-space-y-1">
                <h3 class="rce-text-sm rce-font-medium rce-text-gray-700">
                    Choose an edge cache provider
                </h3>
                <p class="rce-text-sm rce-text-gray-500">
                    Cache complete webpages at the CDN edge. Only one edge cache provider can be
                    enabled at a time.
                </p>
            </div>

            <div class="rce-grid rce-grid-cols-1 md:rce-grid-cols-2 rce-gap-4">
                <div
                    v-for="option in providers"
                    :key="option.value"
                    :class="[
                        'rce-rounded-xl rce-border rce-p-6 rce-transition-all',
                        enabled && activeType === option.value
                            ? 'rce-border-primary-500 rce-ring-[1.5px] rce-ring-primary-500/20 rce-ring-offset-[1.5px]'
                            : 'rce-border-gray-200',
                        option.disabled ? 'rce-opacity-60 rce-cursor-not-allowed' : '',
                        option.hide ? 'rce-hidden' : '',
                    ]">
                    <div class="rce-space-y-3">
                        <div class="rce-flex rce-items-center rce-justify-between rce-gap-3">
                            <div class="rce-flex rce-items-center rce-gap-3">
                                <Switch
                                    :model-value="enabled && activeType === option.value"
                                    :disabled="option.disabled || isChanging"
                                    @update:model-value="toggleProvider(option.value)" />
                                <h3 class="rce-text-sm rce-font-semibold rce-text-gray-900">
                                    {{ option.label }}
                                </h3>
                                <LoaderCircle
                                    v-if="isChanging && targetProvider === option.value"
                                    :size="16"
                                    class="rce-animate-spin rce-text-gray-500" />
                            </div>
                            <Badge
                                variant="success"
                                :class="[
                                    'rce-shrink-0',
                                    !(enabled && activeType === option.value)
                                        ? 'rce-invisible'
                                        : '',
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

            <div v-if="configurationSection" class="rce-mt-6">
                <h3 class="rce-text-base rce-font-semibold rce-text-gray-900 rce-mb-4">
                    {{ configurationSection.label }} Configuration
                </h3>
                <component
                    :is="configurationSection.component"
                    v-bind="configurationSection.props"
                    @saved="configurationSection.onSaved" />
            </div>
        </CardContent>
    </Card>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { LoaderCircle } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import { integrationsApi } from '@/api/integrations'
import InstructionsForCloudflare from '@/components/InstructionsForCloudflare.vue'
import InstructionsForCloudflareApo from '@/components/InstructionsForCloudflareApo.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { EDGE_CACHE_CONFIG } from '@/utils/IntegrationConfig'

const enabled = ref(false)
const activeType = ref('')
const cloudflareSettings = ref({})
const apoSettings = ref({})
const edgeVerified = ref(false)
const isChanging = ref(false)
const targetProvider = ref('')
const failedEnableProvider = ref('')

const providers = computed(() => ({
    cloudflare: {
        ...EDGE_CACHE_CONFIG.cloudflare,
        value: 'cloudflare',
        disabled: false,
        disabledMessage: '',
        hide: false,
    },
    'cloudflare-apo': {
        ...EDGE_CACHE_CONFIG['cloudflare-apo'],
        value: 'cloudflare-apo',
        disabled: false,
        disabledMessage: '',
        hide: false,
    },
}))

const activeProvider = computed(() => providers.value[activeType.value])
const isVerifying = ref(false)

const configurationDefinitions = {
    cloudflare: {
        component: InstructionsForCloudflare,
        isAvailable: () =>
            (enabled.value && activeType.value === 'cloudflare' && !edgeVerified.value) ||
            failedEnableProvider.value === 'cloudflare',
    },
    'cloudflare-apo': {
        component: InstructionsForCloudflareApo,
        isAvailable: () =>
            (enabled.value && activeType.value === 'cloudflare-apo' && !edgeVerified.value) ||
            failedEnableProvider.value === 'cloudflare-apo',
    },
}

const configurationSection = computed(() => {
    // A failed enable reverts the option, so the section keys off the failed
    // provider to keep the credentials form visible for a retry.
    const sectionType = failedEnableProvider.value || activeType.value
    const definition = configurationDefinitions[sectionType]

    if (!definition || !definition.isAvailable()) return null

    return {
        label: providers.value[sectionType]?.label || sectionType,
        component: definition.component,
        props: {
            settings: sectionType === 'cloudflare' ? cloudflareSettings.value : apoSettings.value,
        },
        onSaved: handleConnectionSaved,
    }
})

const toggleProvider = async (type) => {
    if (isChanging.value) return

    const isDisabling = enabled.value && activeType.value === type
    isChanging.value = true
    targetProvider.value = type

    try {
        // The server replaces the whole provider map, so send both providers.
        // Stored credentials survive a disable and the re-enable reuses them.
        const payload = {
            enable: !isDisabling,
            edge_type: isDisabling ? '' : type,
            provider: {
                cloudflare: cloudflareSettings.value,
                'cloudflare-apo': apoSettings.value,
            },
        }

        await saveSettings(payload)
        await refresh()

        if (!isDisabling) {
            try {
                await verify(false)
            } catch (error) {
                // Background verification is reflected in the status badge, not an error toast.
                console.error('Background CDN edge cache verification failed:', error)
            }
        } else {
            enabled.value = false
            activeType.value = ''
            edgeVerified.value = false
        }
    } catch (error) {
        console.error('Failed to toggle CDN edge cache:', error)
        // Keep the credentials form open so the user can fix the key and retry.
        if (!isDisabling) {
            failedEnableProvider.value = type
        }
        if (!error.toastHandled) {
            toast.error('Failed to update CDN Edge Cache settings. Please try again.')
        }
    } finally {
        isChanging.value = false
        targetProvider.value = ''
    }
}

const statusMessage = computed(() => {
    if (!enabled.value) {
        return { message: 'Disabled', variant: 'secondary' }
    }

    if (edgeVerified.value) {
        return { message: 'Enabled and Working', variant: 'success' }
    }

    return { message: 'Enabled - Configuration Required', variant: 'warning' }
})

const refresh = async () => {
    try {
        const edgeSettings = await integrationsApi.edgeCdn.getSettings()

        failedEnableProvider.value = ''
        enabled.value = Boolean(edgeSettings.enable)
        activeType.value = enabled.value ? edgeSettings.edge_type || '' : ''
        cloudflareSettings.value = edgeSettings.provider?.cloudflare || {}
        apoSettings.value = edgeSettings.provider?.['cloudflare-apo'] || {}
    } catch (error) {
        console.error('CDN edge cache settings fetch error:', error)
        toast.error('Unable to load CDN Edge Cache settings. Please refresh and try again.')
    }
}

const verify = async (showToast = true) => {
    if (isVerifying.value) return
    isVerifying.value = true

    try {
        const verification = await integrationsApi.edgeCdn.verify({ showToast })
        edgeVerified.value = enabled.value && Boolean(verification.edge?.verified)
    } catch (error) {
        console.error('CDN edge cache verification error:', error)
        throw error
    } finally {
        isVerifying.value = false
    }
}

const saveSettings = async (settings) => {
    try {
        await integrationsApi.edgeCdn.saveSettings(
            {
                enable: Boolean(settings.enable),
                edge_type: settings.edge_type || '',
                provider: settings.provider,
            },
            { showToast: true }
        )
    } catch (error) {
        console.error('Save CDN edge cache settings error:', error)
        throw error
    }
}

const handleConnectionSaved = async () => {
    try {
        await refresh()
        if (enabled.value && activeType.value) {
            await verify(false)
        }
    } catch (error) {
        console.error('Error refreshing CDN edge cache settings:', error)
        toast.error('Failed to refresh CDN Edge Cache settings. Please try again.')
    }
}

onMounted(async () => {
    await refresh()
    await verify(false)
})
</script>
