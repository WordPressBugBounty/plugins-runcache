<template>
    <Card class="rce-mb-7 rce-rounded-t-none rce-border-t-0">
        <CardHeader>
            <div
                class="rce-flex rce-flex-col md:rce-flex-row md:rce-items-center rce-justify-between rce-relative">
                <CardTitle class="rce-mb-4 md:rce-mb-0">Object Cache</CardTitle>

                <div
                    class="rce-flex rce-flex-wrap-reverse md:rce-flex-wrap rce-items-center rce-gap-2 md:rce-gap-2 md:rce-absolute md:rce-right-0">
                    <Button
                        variant="link"
                        class="!rce-px-0 md:rce-px-4 rce-py-1! rce-h-auto"
                        v-if="objectCacheEnabled"
                        :disabled="isVerifyingObjectCache"
                        @click="verifyObjectCache(true)">
                        <LoaderCircle
                            v-if="isVerifyingObjectCache"
                            :size="20"
                            :stroke-width="1.5"
                            class="rce-animate-spin" />
                        {{ isVerifyingObjectCache ? 'Verifying...' : 'Verify Object Cache' }}
                    </Button>

                    <Badge :variant="getStatusMessage().variant">
                        {{ getStatusMessage().message }}
                    </Badge>
                </div>
            </div>
        </CardHeader>
        <CardContent class="rce-space-y-7">
            <div class="rce-flex rce-items-center rce-justify-between">
                <div class="rce-flex rce-items-center rce-space-x-4">
                    <Switch
                        :model-value="objectCacheEnabled && objectCacheType === 'redis'"
                        :disabled="isChangingObjectCache && targetObjectCache === 'redis'"
                        @update:model-value="toggleObjectCache('redis')" />
                    <div class="rce-space-y-0.5">
                        <h3 class="rce-text-base rce-font-medium">
                            {{ objectCacheEnabled ? 'Disable' : 'Enable' }} Redis Object Cache
                        </h3>
                        <p class="rce-text-base rce-text-gray-600">
                            Speed up your WordPress site by caching database queries and PHP objects
                            in memory, reducing load times and server load
                        </p>
                    </div>
                </div>
            </div>

            <!-- PHP Redis Warning -->
            <Alert v-if="!isPhpRedisAvailable && !isCheckingPhpRedis" variant="warning">
                <AlertTriangle class="rce-size-4" :stroke-width="2" />
                <AlertTitle>PHP Redis Warning</AlertTitle>
                <AlertDescription>
                    {{ phpRedisMessage }}
                </AlertDescription>
            </Alert>

            <!-- Redis Object Cache Instructions -->
            <div v-if="objectCacheEnabled && objectCacheType === 'redis' && !objectCacheVerified">
                <InstructionsForRedis @saved="handleRedisConnectionSaved" />
            </div>
        </CardContent>
    </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { LoaderCircle, AlertTriangle } from 'lucide-vue-next'
import { cacheApi } from '@/api/cache'
import { integrationsApi } from '@/api/integrations'
import InstructionsForRedis from '@/components/InstructionsForRedis.vue'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertTitle, AlertDescription } from './ui/alert'
import { usePagePreloader, runPreload } from '@/utils/pagePreloader'

const isCheckingPhpRedis = ref(true)
const isPhpRedisAvailable = ref(false)
const phpRedisMessage = ref('Checking Redis extension availability...')
const objectCacheEnabled = ref(false)
const objectCacheType = ref('')
const targetObjectCache = ref('')
const isChangingObjectCache = ref(false)
const isVerifyingObjectCache = ref(false)
const objectCacheVerified = ref(false)
const verificationRequest = ref(null)
const pagePreloader = usePagePreloader()

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

// Get Object Cache settings
const getObjectCacheSettings = async () => {
    try {
        const settings = await cacheApi.objectCache.get()

        objectCacheEnabled.value = Boolean(settings.enable)
        objectCacheType.value = settings.object_cache_type || ''

        return settings
    } catch (error) {
        console.error('Object Cache settings fetch error:', error)
    }
}

// Save Object Cache settings
const saveObjectCacheSettings = async ({
    enable = objectCacheEnabled.value,
    object_cache_type = objectCacheType.value,
    loadingState = null,
}) => {
    if (loadingState?.value) return null
    if (loadingState) loadingState.value = true

    const requestedType = object_cache_type || ''
    const requestedEnable = Boolean(enable)

    try {
        const settings = {
            enable: requestedEnable,
            object_cache_type: requestedType,
        }

        const savedSettings = await cacheApi.objectCache.save(settings)

        objectCacheEnabled.value = Boolean(savedSettings.enable)
        objectCacheType.value = savedSettings.object_cache_type || ''
        objectCacheVerified.value = Boolean(savedSettings.verified)

        return savedSettings
    } catch (error) {
        // Keep the setup form visible when enabling fails after the setting is saved.
        if (requestedEnable && requestedType === 'redis') {
            objectCacheEnabled.value = true
            objectCacheType.value = 'redis'
            objectCacheVerified.value = false
        }

        console.error('Save Object Cache settings error:', error)
        throw error
    } finally {
        if (loadingState) loadingState.value = false
    }
}

// Toggle Object Cache
const toggleObjectCache = async (type) => {
    try {
        targetObjectCache.value = type
        isChangingObjectCache.value = true

        const newSettings = {
            enable: !objectCacheEnabled.value,
            object_cache_type: type || '',
        }

        const savedSettings = await saveObjectCacheSettings(newSettings)

        if (savedSettings?.enable) {
            await verifyObjectCache(false, true)
        }
    } catch (error) {
        // The save handler preserves the Redis state so its setup instructions remain visible.
    } finally {
        isChangingObjectCache.value = false
        targetObjectCache.value = ''
    }
}

// Verify Object Cache setup
const verifyObjectCache = async (showToast = true, force = false) => {
    if (verificationRequest.value) {
        if (!force) return verificationRequest.value
        await verificationRequest.value
    }

    isVerifyingObjectCache.value = true
    verificationRequest.value = (async () => {
        try {
            const data = await cacheApi.objectCache.verify({ showToast })
            const { type, verified } = data

            objectCacheType.value = type || ''
            objectCacheVerified.value = Boolean(verified)

            return Boolean(verified)
        } catch (error) {
            console.error('Object Cache verification error:', error)
            objectCacheVerified.value = false
            return false
        } finally {
            isVerifyingObjectCache.value = false
            verificationRequest.value = null
        }
    })()

    return verificationRequest.value
}

// Handle Redis Object Cache connection saved
const handleRedisConnectionSaved = async () => {
    // Verify only after the save request has completed, then update the reactive UI state.
    await verifyObjectCache(false, true)
}

const getStatusMessage = () => {
    if (!objectCacheEnabled.value) {
        return { message: 'Disabled', variant: 'secondary' }
    }
    if (objectCacheVerified.value) {
        return { message: 'Enabled and Working', variant: 'success' }
    }
    if (objectCacheType.value) {
        return { message: 'Enabled - Configuration Required', variant: 'warning' }
    }

    return { message: 'Configuration Pending', variant: 'info' }
}

onMounted(async () => {
    await runPreload(pagePreloader, () => checkPhpRedisAvailability())
    await runPreload(pagePreloader, () => getObjectCacheSettings())
    await runPreload(pagePreloader, () => verifyObjectCache(false))
})
</script>
