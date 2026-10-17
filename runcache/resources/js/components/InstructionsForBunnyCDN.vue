<template>
    <div class="rce-space-y-7">
        <Alert variant="info">
            <Info class="rce-size-4" :stroke-width="2" />
            <AlertTitle>BunnyCDN Cache Setup</AlertTitle>
            <AlertDescription class="rce-space-y-3">
                <p>
                    Configure your BunnyCDN API key and pull zone so RunCache can serve media assets
                    from BunnyCDN's global CDN. Once connected, BunnyCDN will automatically cache
                    static files such as CSS, JavaScript, images, and fonts for faster delivery.
                </p>
                <ol class="rce-list-decimal rce-ml-5 rce-space-y-1 rce-text-sm rce-text-gray-600">
                    <li>
                        In the Bunny.net dashboard, open
                        <span class="rce-font-medium">Account → Access Keys</span>
                        .
                    </li>
                    <li>Create or copy an API key with permission to manage Pull Zones.</li>
                    <li>
                        Paste the key below. RunCache will fetch your pull zones automatically after
                        you leave the field.
                    </li>
                    <li>Select the pull zone that should deliver this site's assets.</li>
                </ol>
            </AlertDescription>
        </Alert>

        <div>
            <h4 class="rce-text-sm rce-font-medium rce-text-foreground rce-mb-4">
                BunnyCDN Connection Settings
            </h4>
            <form @submit.prevent="saveSettings" class="rce-space-y-7">
                <!-- API Key -->
                <div class="rce-space-y-2">
                    <Label for="bunnycdn-api-key">API Key</Label>
                    <Input
                        id="bunnycdn-api-key"
                        v-model="settings.api_key"
                        type="password"
                        :placeholder="settings.api_key ? '••••••••' : 'Enter your BunnyCDN API key'"
                        autocomplete="off"
                        @keyup.enter.prevent="handleApiKeyBlur"
                        @blur="handleApiKeyBlur" />
                    <p class="rce-text-sm rce-text-muted-foreground">
                        Find your API key in the
                        <a
                            href="https://dash.bunny.net/account/api-key"
                            target="_blank"
                            class="rce-text-blue-600 hover:rce-text-blue-800 rce-underline">
                            BunnyCDN Account Settings
                        </a>
                    </p>
                </div>

                <!-- Pull Zone Selection -->
                <div v-if="shouldDisplayPullZoneSection" class="rce-space-y-2">
                    <Label for="bunnycdn-pull-zone">Pull Zone</Label>
                    <template v-if="isLoadingPullZones">
                        <div
                            class="rce-flex rce-items-center rce-space-x-2 rce-text-sm rce-text-muted-foreground">
                            <LoaderCircle class="rce-h-4 rce-w-4 rce-animate-spin" />
                            <span>Loading pull zones...</span>
                        </div>
                    </template>
                    <template v-else>
                        <div v-if="showPullZoneSelect" class="rce-flex">
                            <Select v-model="settings.pull_zone_id" :disabled="isLoadingPullZones">
                                <SelectTrigger id="bunnycdn-pull-zone" class="rce-flex-1">
                                    <SelectValue placeholder="Select a pull zone" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="zone in pullZones"
                                        :key="zone.Id"
                                        :value="String(zone.Id)">
                                        {{ formatZoneDisplay(zone) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div
                            v-else-if="selectedPullZoneLabel"
                            class="rce-rounded-md rce-border rce-border-gray-200 rce-bg-gray-50 rce-px-3 rce-py-2 rce-text-sm rce-text-gray-700">
                            Selected pull zone: {{ selectedPullZoneLabel }}
                        </div>
                        <p v-else class="rce-text-sm rce-text-muted-foreground">
                            No pull zones found for this API key.
                        </p>
                    </template>
                    <p class="rce-text-sm rce-text-muted-foreground">
                        RunCache uses the selected pull zone to purge and serve your site's static
                        assets via BunnyCDN.
                    </p>
                </div>

                <!-- Allowed Extensions -->
                <div class="rce-space-y-2">
                    <Label for="bunnycdn-extensions">Allowed File Extensions</Label>
                    <Textarea
                        id="bunnycdn-extensions"
                        v-model="allowedExtensionsText"
                        rows="3"
                        placeholder="css, js, jpg, png, mp4, *"
                        class="rce-font-mono rce-text-sm" />
                    <p class="rce-text-sm rce-text-muted-foreground">
                        Separate extensions with commas or spaces. Use
                        <span class="rce-font-mono">*</span>
                        to allow all file types. Defaults include CSS, JS, images, and font formats.
                    </p>
                </div>

                <!-- Save Button -->
                <div class="rce-pt-2">
                    <Button type="submit" :disabled="isSaving || !isValid">
                        <LoaderCircle
                            v-if="isSaving"
                            :size="20"
                            :stroke-width="1.5"
                            class="rce-animate-spin" />
                        {{ isSaving ? 'Saving...' : 'Save Settings' }}
                    </Button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { LoaderCircle, Info } from 'lucide-vue-next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { integrationsApi } from '@/api/integrations'

// Query keys for CDN
const cdnQueryKeys = {
    settings: () => ['infrastructure', 'cdn', 'settings'],
    verification: () => ['infrastructure', 'cdn', 'verification'],
}
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import { Alert, AlertTitle, AlertDescription } from './ui/alert'

// Define props and emits
const emit = defineEmits(['saved'])

// Form state
const settings = reactive({
    api_key: '',
    pull_zone_id: '',
    hostname: '',
    allowed_extensions: [],
})

const allowedExtensionsText = ref('')
const defaultAllowedExtensions = [
    'jpg',
    'jpeg',
    'png',
    'gif',
    'ico',
    'mp4',
    'css',
    'js',
    'svg',
    'woff',
    'woff2',
    'ttf',
    'eot',
]

const parseAllowedExtensions = (text) => {
    if (!text) return [...defaultAllowedExtensions]

    const parts = text
        .split(/[,\s]+/)
        .map((line) => line.trim().toLowerCase().replace(/^\./, ''))
        .filter((entry) => entry !== '')

    if (parts.includes('*')) {
        return ['*']
    }

    if (parts.length === 0) {
        return [...defaultAllowedExtensions]
    }

    const unique = Array.from(new Set(parts))
    return unique
}

// Query functions
function useCdnSettingsQuery() {
    return useQuery({
        queryKey: cdnQueryKeys.settings(),
        queryFn: () => integrationsApi.assetCdn.getSettings(),
        staleTime: 5 * 60 * 1000, // 5 minutes
        retry: 2,
    })
}

function useCdnVerificationQuery() {
    return useQuery({
        queryKey: cdnQueryKeys.verification(),
        queryFn: () => integrationsApi.assetCdn.verify(),
        staleTime: 2 * 60 * 1000, // 2 minutes
        retry: 1,
        enabled: false, // Manual query
    })
}

function useSaveCdnSettingsMutation() {
    const queryClient = useQueryClient()

    return useMutation({
        mutationFn: (settings) => integrationsApi.bunnycdn.saveSettings(settings),
        onSuccess: (data) => {
            // Invalidate related queries
            queryClient.invalidateQueries({ queryKey: cdnQueryKeys.settings() })
            queryClient.invalidateQueries({ queryKey: cdnQueryKeys.verification() })

            // Toast is handled by API client
        },
        onError: (error) => {
            // Error toast is handled by API client
        },
    })
}

// Queries
const { data: cdnSettings, isLoading: isLoadingCdnSettings } = useCdnSettingsQuery()

// Mutation
const saveMutation = useSaveCdnSettingsMutation()

const isSaving = computed(() => saveMutation.isPending.value)

// Reactive states
const pullZones = ref([])
const isLoadingPullZones = ref(false)

const isValid = computed(() => {
    return settings.api_key && settings.pull_zone_id
})

// Update local form state from the CDN settings query.
const updateSettings = (data) => {
    if (!data) return

    const providerData = data.provider?.bunnycdn || {}
    settings.api_key = providerData.api_key || data.api_key || ''
    // Normalize to string to keep type consistent with the saved option.
    settings.pull_zone_id = String(providerData.pull_zone_id || data.pull_zone_id || '')
    settings.hostname = providerData.hostname || data.hostname || ''
    settings.allowed_extensions = providerData.allowed_extensions || data.allowed_extensions || []
    allowedExtensionsText.value = (
        settings.allowed_extensions && settings.allowed_extensions.length > 0
            ? settings.allowed_extensions
            : defaultAllowedExtensions
    ).join(', ')
}

// Watch CDN settings data
watch(
    cdnSettings,
    (data) => {
        updateSettings(data)
    },
    { immediate: true }
)

watch(allowedExtensionsText, (text) => {
    settings.allowed_extensions = parseAllowedExtensions(text)
})

// Watch for pull zone changes and update hostname
watch(
    () => settings.pull_zone_id,
    (newId) => {
        if (newId) {
            const selectedZone = pullZones.value.find((zone) => String(zone.Id) === newId)
            if (selectedZone && selectedZone.Hostnames && selectedZone.Hostnames.length > 0) {
                settings.hostname = selectedZone.Hostnames[0].Value
            } else {
                settings.hostname = ''
            }
        } else {
            settings.hostname = ''
        }
    }
)

// Watch save mutation success
watch(
    () => saveMutation.isSuccess.value,
    (isSuccess) => {
        if (isSuccess) {
            emit('saved')
        }
    }
)

const formatZoneDisplay = (zone) => {
    return zone.Name || zone.Id || 'Unknown Zone'
}

const selectedPullZoneLabel = computed(() => {
    const zone = pullZones.value.find((zone) => String(zone.Id) === settings.pull_zone_id)
    return zone ? formatZoneDisplay(zone) : ''
})

const showPullZoneSelect = computed(() => pullZones.value.length > 1)

const shouldDisplayPullZoneSection = computed(() => {
    return (
        isLoadingPullZones.value || Boolean(selectedPullZoneLabel.value) || showPullZoneSelect.value
    )
})

const handleApiKeyBlur = async () => {
    if (!settings.api_key) {
        pullZones.value = []
        settings.pull_zone_id = ''
        return
    }

    isLoadingPullZones.value = true

    try {
        const data = await integrationsApi.bunnycdn.getPullZones(settings.api_key)
        if (data?.pull_zones) {
            pullZones.value = data.pull_zones
            // Auto-select if only one zone available
            if (!settings.pull_zone_id && data.pull_zones.length === 1) {
                settings.pull_zone_id = String(data.pull_zones[0].Id)
            }
        }
    } catch (error) {
        console.error('Failed to fetch BunnyCDN pull zones:', error)
        pullZones.value = []
        settings.pull_zone_id = ''
    } finally {
        isLoadingPullZones.value = false
    }
}

const saveSettings = () => {
    if (!isValid.value) return

    // Save both CDN configuration AND credentials for functionality
    const resolvedExtensions =
        settings.allowed_extensions && settings.allowed_extensions.length > 0
            ? settings.allowed_extensions
            : defaultAllowedExtensions
    const fullSettings = {
        enable: true,
        cdn_type: 'bunnycdn',
        hostname: settings.hostname,
        api_key: settings.api_key,
        pull_zone_id: settings.pull_zone_id,
        allowed_extensions: resolvedExtensions,
    }

    saveMutation.mutate(fullSettings)
}
</script>
