<template>
    <!-- Loading State -->
    <PageSkeleton v-if="isLoading" :card-count="5" />

    <!-- Error State -->
    <Alert variant="destructive" v-else-if="errorMessage">
        <TriangleAlert class="rce-size-4" :stroke-width="2" />
        <AlertTitle>Error</AlertTitle>
        <AlertDescription>{{ errorMessage }}</AlertDescription>
    </Alert>

    <!-- Content -->
    <div v-else class="rce-space-y-6">
        <!-- Header -->
        <div>
            <h2 class="rce-text-2xl rce-font-bold rce-text-gray-900">Cache Rules Settings</h2>
            <p class="rce-mt-1 rce-text-sm rce-text-gray-600">
                Configure which pages, cookies, and requests should be excluded from or included in
                RunCache's caching system.
            </p>
        </div>

        <form id="rules-form" @submit.prevent="saveSettings" class="rce-space-y-7">
            <!-- URL Exclusion Rules -->
            <Card>
                <CardHeader>
                    <CardTitle>URL Path Exclusion</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="exclude_url_onn" v-model="settings.exclude_url_onn" />
                            <Label
                                for="exclude_url_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Exclude URL Path
                            </Label>
                        </div>

                        <div v-show="settings.exclude_url_onn" class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="exclude_url_mch"
                                v-model="settings.exclude_url_mch"
                                auto-resize
                                :min-height="100"
                                :max-height="200"
                                placeholder="/checkout/" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Exclude page cache based on matching URL path, one per line.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Cookie Rules -->
            <Card>
                <CardHeader>
                    <CardTitle>Cookie Rules</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="exclude_cookie_onn" v-model="settings.exclude_cookie_onn" />
                            <Label
                                for="exclude_cookie_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Exclude Cookie
                            </Label>
                        </div>

                        <div v-show="settings.exclude_cookie_onn" class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="exclude_cookie_mch"
                                v-model="settings.exclude_cookie_mch"
                                auto-resize
                                :min-height="100"
                                :max-height="200"
                                placeholder="tracking_" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Exclude page cache based on matching cookie name, one per line.
                            </p>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="exclude_auto_onn" v-model="settings.exclude_auto_onn" />
                            <Label
                                for="exclude_auto_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Exclude DONOTCACHEPAGE constant
                            </Label>
                        </div>
                        <p class="rce-text-sm rce-text-gray-500">
                            Automatically exclude page cache when DONOTCACHEPAGE constant is
                            detected.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Browser & IP Rules -->
            <Card>
                <CardHeader>
                    <CardTitle>Browser & IP Rules</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="exclude_browser_onn"
                                v-model="settings.exclude_browser_onn" />
                            <Label
                                for="exclude_browser_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Exclude Browser User-Agent
                            </Label>
                        </div>

                        <div v-show="settings.exclude_browser_onn" class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="exclude_browser_mch"
                                v-model="settings.exclude_browser_mch"
                                auto-resize
                                :min-height="80"
                                :max-height="150"
                                placeholder="googlebot" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Exclude page cache based on matching browser user-agent, one per
                                line.
                            </p>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="exclude_visitorip_onn"
                                v-model="settings.exclude_visitorip_onn" />
                            <Label
                                for="exclude_visitorip_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Exclude Visitor IP Address
                            </Label>
                        </div>

                        <div
                            v-show="settings.exclude_visitorip_onn"
                            class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="exclude_visitorip_mch"
                                v-model="settings.exclude_visitorip_mch"
                                auto-resize
                                :min-height="80"
                                :max-height="150"
                                placeholder="8.8.8.8" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Exclude page cache based on matching visitor IP address, one per
                                line.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Query String Rules -->
            <Card>
                <CardHeader>
                    <CardTitle>Query String Rules</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="rce-text-sm rce-text-gray-500 rce-mb-4">
                        By default a page with query string will not be cached.
                    </p>

                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="ignore_query_onn" v-model="settings.ignore_query_onn" />
                            <Label
                                for="ignore_query_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Ignore Cache Query String
                            </Label>
                        </div>

                        <div v-show="settings.ignore_query_onn" class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="ignore_query_mch"
                                v-model="settings.ignore_query_mch"
                                auto-resize
                                :min-height="90"
                                :max-height="180"
                                placeholder="utm_source" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Ignore listed query parameter names, one per line. They are removed
                                from the cache key, so their values do not create separate cache
                                entries.
                            </p>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="allow_query_onn" v-model="settings.allow_query_onn" />
                            <Label
                                for="allow_query_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Include Cache Query String
                            </Label>
                        </div>

                        <div v-show="settings.allow_query_onn" class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="allow_query_mch"
                                v-model="settings.allow_query_mch"
                                auto-resize
                                :min-height="80"
                                :max-height="150"
                                placeholder="country" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Allow listed query parameter names, one per line. Only these
                                parameters are accepted, and each value creates a separate cache
                                entry.
                            </p>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="exclude_query_onn" v-model="settings.exclude_query_onn" />
                            <Label
                                for="exclude_query_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Exclude Cache Query String
                            </Label>
                        </div>

                        <div v-show="settings.exclude_query_onn" class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="exclude_query_mch"
                                v-model="settings.exclude_query_mch"
                                auto-resize
                                :min-height="80"
                                :max-height="150"
                                placeholder="nocache" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Prevent page cache when any listed query parameter name exists, one
                                per line. The request bypasses cache regardless of the parameter
                                value.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Additional Cache Key -->
            <Card>
                <CardHeader>
                    <CardTitle>Additional Cache Key</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="cache_key_extra_onn"
                                v-model="settings.cache_key_extra_onn" />
                            <Label
                                for="cache_key_extra_onn"
                                class="rce-text-sm rce-font-medium rce-text-gray-700">
                                Set Additional Cache Key
                            </Label>
                        </div>

                        <div v-show="settings.cache_key_extra_onn" class="rce-ml-12 rce-space-y-3">
                            <Input
                                type="text"
                                id="cache_key_extra_var"
                                v-model="settings.cache_key_extra_var" />
                            <p class="rce-text-sm rce-text-gray-500">
                                Enable this option to add additional cache keys. The input should be
                                a recognized and valid NGINX variables.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Floating Save Button -->
            <FloatingSaveButton
                :show="hasChanges"
                :is-saving="isSaving"
                @save="saveSettings"
                @undo="undoChanges" />
        </form>
    </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { settingsApi } from '@/api/settings'
import { useFloatingSave } from '@/utils/useFloatingSave'
import { useMinimumLoading } from '@/utils/useMinimumLoading'
import FloatingSaveButton from '@/components/FloatingSaveButton.vue'
import PageSkeleton from '@/components/PageSkeleton.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { TriangleAlert } from 'lucide-vue-next'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import { toast } from 'vue-sonner'

// Query keys for rules settings
const rulesQueryKeys = {
    all: ['settings', 'rules'],
    settings: () => [...rulesQueryKeys.all, 'settings'],
}

/**
 * Composable for rules settings query
 */
function useRulesSettingsQuery() {
    return useQuery({
        queryKey: rulesQueryKeys.settings(),
        queryFn: () => settingsApi.rules.get(),
        staleTime: 5 * 60 * 1000, // 5 minutes
        retry: 2,
    })
}

/**
 * Composable for saving rules settings mutation
 */
function useSaveRulesSettingsMutation() {
    const queryClient = useQueryClient()

    return useMutation({
        mutationFn: (settings) => settingsApi.rules.save(settings),
        onSuccess: (data) => {
            // Update the cache with the new data immediately
            queryClient.setQueryData(rulesQueryKeys.settings(), data)
            // No invalidation - we trust the server response
        },
        onError: (_error) => {
            // Error toast is handled by API client, no need to show it here
        },
    })
}

// Reactive settings are populated exclusively from the backend response.
const settings = ref({})

// Use TanStack Query for settings
const { data: fetchedSettings, isLoading: queryIsLoading, error } = useRulesSettingsQuery()
const isLoading = useMinimumLoading(queryIsLoading)

// Use TanStack Query for mutation
const saveMutation = useSaveRulesSettingsMutation()

const normalizeBoolean = (value) => {
    if (typeof value === 'boolean') return value
    if (typeof value === 'number') return value === 1

    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase()
        if (['1', 'true', 'yes', 'on'].includes(normalized)) return true
        if (['0', 'false', 'no', 'off', ''].includes(normalized)) return false
    }

    return false
}

// Use floating save composable before using its functions
const { hasChanges, storeOriginalSettings, undoChanges, markAsSaved, isInitialized } =
    useFloatingSave(settings)

// Watch for data changes and update local settings
watch(
    fetchedSettings,
    (newData) => {
        // Extract settings from API response
        const actualSettings = newData?.settings || newData || {}

        if (
            !actualSettings ||
            typeof actualSettings !== 'object' ||
            Array.isArray(actualSettings)
        ) {
            return
        }

        settings.value = { ...actualSettings }

        // Normalize legacy toggle values before initializing change tracking.
        Object.keys(settings.value).forEach((key) => {
            if (key.endsWith('_onn')) {
                settings.value[key] = normalizeBoolean(settings.value[key])
            }
        })

        if (newData !== undefined && !isInitialized.value) {
            storeOriginalSettings()
        }
    },
    { immediate: true }
)

const saveSettings = async () => {
    if (saveMutation.isPending.value) return

    try {
        await saveMutation.mutateAsync(settings.value)
        markAsSaved()
        toast.success('Cache rules settings saved successfully!')
    } catch (error) {
        console.error('Save rules settings error:', error)
    }
}

// Computed properties for loading states
const isSaving = computed(() => saveMutation.isPending.value)

// Error handling
const errorMessage = computed(() => {
    if (error.value) {
        return error.value.message || 'Failed to load rules settings'
    }
    if (saveMutation.error.value) {
        return saveMutation.error.value.message || 'Failed to save rules settings'
    }
    return null
})
</script>
