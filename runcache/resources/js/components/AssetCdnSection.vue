<template>
    <Card class="rce-mb-7 rce-rounded-t-none rce-border-t-0">
        <CardHeader>
            <div
                class="rce-flex rce-flex-col md:rce-flex-row md:rce-items-center rce-justify-between rce-relative">
                <CardTitle class="rce-mb-4 md:rce-mb-0">Assets CDN</CardTitle>

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
                        {{ isVerifying ? 'Verifying...' : 'Verify CDN' }}
                    </Button>

                    <Badge :variant="statusMessage.variant">{{ statusMessage.message }}</Badge>
                </div>
            </div>
        </CardHeader>

        <CardContent class="rce-space-y-6">
            <div class="rce-space-y-1">
                <h3 class="rce-text-sm rce-font-medium rce-text-gray-700">Choose a CDN provider</h3>
                <p class="rce-text-sm rce-text-gray-500">
                    Enable one provider at a time to rewrite your static asset URLs through a CDN.
                </p>
            </div>

            <!-- CDN Provider Grid -->
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
                    <div class="rce-space-y-2">
                        <div class="rce-flex rce-items-start rce-justify-between rce-gap-3">
                            <div class="rce-flex rce-items-center rce-gap-3">
                                <Switch
                                    :model-value="enabled && activeType === option.value"
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

            <!-- Configuration Sections (Below Grid) -->
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
import { ref, computed, onMounted } from 'vue'
import { LoaderCircle } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import { integrationsApi } from '@/api/integrations'
import InstructionsForBunnyCDN from '@/components/InstructionsForBunnyCDN.vue'
import InstructionsForCustomCDN from '@/components/InstructionsForCustomCDN.vue'
import { usePagePreloader, runPreload } from '@/utils/pagePreloader'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { ASSET_CDN_CONFIG } from '@/utils/IntegrationConfig'

const enabled = ref(false)
const activeType = ref('')
const cdnVerified = ref(false)
const targetProvider = ref('')
const isChanging = ref(false)
const isVerifying = ref(false)

// CDN provider configuration - centralized configuration for all CDN providers
const pagePreloader = usePagePreloader()

const providers = computed(() => ({
    bunnycdn: {
        ...ASSET_CDN_CONFIG.bunnycdn,
        value: 'bunnycdn',
        disabled: false,
        disabledMessage: '',
        hide: false,
    },
    custom: {
        ...ASSET_CDN_CONFIG.custom,
        value: 'custom',
        disabled: false,
        disabledMessage: '',
        hide: false,
    },
}))

const configurationDefinitions = {
    bunnycdn: {
        component: InstructionsForBunnyCDN,
        isAvailable: () => enabled.value && activeType.value === 'bunnycdn' && !cdnVerified.value,
    },
    custom: {
        component: InstructionsForCustomCDN,
        isAvailable: () => enabled.value && activeType.value === 'custom' && !cdnVerified.value,
    },
}

const configurationSection = computed(() => {
    const definition = configurationDefinitions[activeType.value]

    if (!definition || !definition.isAvailable()) return null

    return {
        label: providers.value[activeType.value]?.label || activeType.value,
        component: definition.component,
        props: {},
        onSaved: handleConnectionSaved,
    }
})

const toggleProvider = async (type) => {
    if (isChanging.value) return

    const isDisabling = enabled.value && activeType.value === type

    targetProvider.value = type
    isChanging.value = true

    try {
        const payload = {
            enable: !isDisabling,
            cdn_type: isDisabling ? '' : type,
        }

        await saveSettings(payload)
        await refresh()

        if (!isDisabling) {
            try {
                await verify(false)
            } catch (error) {
                // Background verification is reflected in the status badge, not an error toast.
                console.error('Background CDN verification failed:', error)
            }
        } else {
            enabled.value = false
            activeType.value = ''
            cdnVerified.value = false
        }
    } catch (error) {
        console.error('Failed to toggle CDN:', error)
        toast.error('Failed to update Asset CDN settings. Please try again.')
    } finally {
        isChanging.value = false
        targetProvider.value = ''
    }
}

const statusMessage = computed(() => {
    if (!enabled.value) {
        return { message: 'Disabled', variant: 'secondary' }
    }

    if (cdnVerified.value) {
        return { message: 'Enabled and Working', variant: 'success' }
    }

    if (activeType.value) {
        return { message: 'Enabled - Configuration Required', variant: 'warning' }
    }

    return { message: 'Configuration Pending', variant: 'info' }
})

const refresh = async () => {
    try {
        const data = await integrationsApi.assetCdn.getSettings()

        enabled.value = Boolean(data.enable)
        activeType.value = enabled.value ? data.cdn_type || '' : ''
        if (typeof data.verified !== 'undefined') {
            cdnVerified.value = Boolean(data.verified)
        }
    } catch (error) {
        console.error('CDN settings fetch error:', error)
        toast.error('Unable to load Asset CDN settings. Please refresh and try again.')
    }
}

const verify = async (showToast = true) => {
    if (isVerifying.value) return
    isVerifying.value = true

    try {
        const data = await integrationsApi.assetCdn.verify({ showToast })
        const { type, verified } = data.cdn

        if (enabled.value) {
            activeType.value = type
            cdnVerified.value = Boolean(verified)
        }
    } catch (error) {
        console.error('CDN verification error:', error)
        throw error
    } finally {
        isVerifying.value = false
    }
}

const saveSettings = async ({ enable, cdn_type }) => {
    try {
        await integrationsApi.assetCdn.saveSettings({
            enable: Boolean(enable),
            cdn_type: cdn_type || '',
        })
    } catch (error) {
        console.error('Save CDN settings error:', error)
        throw error
    }
}

const handleConnectionSaved = async () => {
    try {
        await refresh()
        if (enabled.value) {
            await verify(false)
        }
    } catch (error) {
        console.error('Error refreshing Asset CDN settings:', error)
        toast.error('Failed to refresh Asset CDN settings. Please try again.')
    }
}

onMounted(async () => {
    await runPreload(pagePreloader, () => refresh())
    await runPreload(pagePreloader, () => verify(false))
})
</script>
