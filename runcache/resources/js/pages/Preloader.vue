<template>
    <!-- Loading State -->
    <PageSkeleton v-if="isLoading" :card-count="3" />

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
            <h2 class="rce-text-2xl rce-font-bold rce-text-gray-900">Cache Preloader Settings</h2>
            <p class="rce-mt-1 rce-text-sm rce-text-gray-600">
                Configure how RunCache preloads your website's cache to ensure optimal performance.
            </p>
        </div>

        <form id="preloader-form" @submit.prevent="saveSettings" class="rce-space-y-7">
            <!-- Single Post/Page Preload -->
            <Card>
                <CardHeader>
                    <CardTitle>Preload Post/Page/CPT Cache</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="preload_post_onn" v-model="settings.preload_post_onn" />
                            <Label for="preload_post_onn" class="rce-text-sm rce-text-gray-700">
                                Automatically preload cache of a single post/page/CPT after purge
                                post/page/CPT cache
                            </Label>
                        </div>
                        <p class="rce-text-sm rce-text-gray-500">
                            Preload post/page cache will generate the cache of a single post/page
                            after purge post/page/CPT is triggered.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Preload All Cache -->
            <Card>
                <CardHeader>
                    <CardTitle>Preload All Cache</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <!-- Preload Speed -->
                        <div class="rce-space-y-3">
                            <div class="rce-flex rce-items-center rce-space-x-2">
                                <span class="rce-text-sm rce-text-gray-700">Speed:</span>
                                <Input
                                    type="number"
                                    id="preload_speed"
                                    v-model="settings.preload_speed"
                                    min="1"
                                    max="120"
                                    class="rce-w-24" />
                                <span class="rce-text-sm rce-text-gray-600">
                                    posts/pages/CPT per minute
                                </span>
                            </div>
                            <p class="rce-text-sm rce-text-gray-500">
                                Number of URLs the background worker preloads per minute. Lower
                                values reduce server load; higher values finish faster. Maximum:
                                120.
                            </p>
                        </div>

                        <!-- Auto Preload -->
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="preload_onn" v-model="settings.preload_onn" />
                            <Label for="preload_onn" class="rce-text-sm rce-text-gray-700">
                                Automatically run preload all cache for homepage and all posts/pages
                                after purge all cache
                            </Label>
                        </div>

                        <!-- Custom Preload Paths -->
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="preload_path_onn" v-model="settings.preload_path_onn" />
                            <Label for="preload_path_onn" class="rce-text-sm rce-text-gray-700">
                                Enable custom preload path URL
                            </Label>
                        </div>

                        <div v-show="settings.preload_path_onn" class="rce-ml-12 rce-space-y-3">
                            <Textarea
                                id="preload_path_mch"
                                v-model="settings.preload_path_mch"
                                auto-resize
                                :min-height="80"
                                :max-height="150"
                                placeholder="/login/" />
                            <p class="rce-text-sm rce-text-gray-500">
                                One URL path per line, these paths will be preloaded when running
                                preload all cache.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Scheduled Preload -->
            <Card>
                <CardHeader>
                    <CardTitle>Scheduled Preload All Cache</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="preload_schedule_onn"
                                v-model="settings.preload_schedule_onn" />
                            <Label for="preload_schedule_onn" class="rce-text-sm rce-text-gray-700">
                                Enable Scheduled Preload All Cache
                            </Label>
                        </div>

                        <div v-show="settings.preload_schedule_onn" class="rce-ml-12 rce-space-y-3">
                            <p class="rce-text-sm rce-text-gray-600">
                                Automatically run cache preload for homepage and all posts/pages at
                                the specified interval.
                            </p>
                            <div class="rce-flex rce-items-center rce-space-x-2">
                                <Input
                                    type="number"
                                    id="preload_schedule_int"
                                    v-model="settings.preload_schedule_int"
                                    min="1"
                                    class="rce-w-24" />
                                <Select v-model="settings.preload_schedule_unt">
                                    <SelectTrigger class="rce-w-32">
                                        <SelectValue placeholder="Select time unit" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="60">
                                            <SelectItemText>Minutes</SelectItemText>
                                        </SelectItem>
                                        <SelectItem value="3600">
                                            <SelectItemText>Hours</SelectItemText>
                                        </SelectItem>
                                        <SelectItem value="86400">
                                            <SelectItemText>Days</SelectItemText>
                                        </SelectItem>
                                        <SelectItem value="604800">
                                            <SelectItemText>Weeks</SelectItemText>
                                        </SelectItem>
                                        <SelectItem value="2592000">
                                            <SelectItemText>Months</SelectItemText>
                                        </SelectItem>
                                        <SelectItem value="31536000">
                                            <SelectItemText>Years</SelectItemText>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Manual Preload Tools -->
            <Card v-show="false">
                <CardHeader>
                    <CardTitle>Manual Preload Tools</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-grid rce-grid-cols-1 md:rce-grid-cols-2 rce-gap-6">
                        <!-- Manual URL Preload -->
                        <div class="rce-space-y-3">
                            <div>
                                <Label
                                    class="rce-block rce-text-sm rce-font-medium rce-text-gray-700 rce-mb-2">
                                    URL or Pattern
                                </Label>
                                <Input
                                    v-model="preloadUrl"
                                    type="text"
                                    placeholder="e.g., / or /blog/*"
                                    class="rce-w-full" />
                            </div>
                            <Button @click="startPreload">Start Preloading</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Preload Status -->
            <Card v-show="false">
                <CardHeader>
                    <CardTitle>Preload Status</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-3">
                        <div
                            v-for="job in preloadJobs"
                            :key="job.id"
                            class="rce-flex rce-items-center rce-justify-between rce-py-2 rce-border-b rce-border-gray-100 last:rce-border-b-0">
                            <div>
                                <p class="rce-text-sm rce-font-medium rce-text-gray-900">
                                    {{ job.name }}
                                </p>
                                <p class="rce-text-xs rce-text-gray-500">
                                    {{ job.progress }}% complete
                                </p>
                            </div>
                            <div class="rce-flex rce-items-center rce-space-x-2">
                                <div class="rce-w-32 rce-bg-gray-200 rce-rounded-full rce-h-2">
                                    <div
                                        class="rce-bg-indigo-600 rce-h-2 rce-rounded-full"
                                        :style="{ width: job.progress + '%' }"></div>
                                </div>
                                <span
                                    :class="getJobStatusClass(job.status)"
                                    class="rce-px-2 rce-py-1 rce-text-xs rce-rounded-full">
                                    {{ job.status }}
                                </span>
                            </div>
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
import { cacheApi } from '@/api/cache'
import { useFloatingSave } from '@/utils/useFloatingSave'
import { useMinimumLoading } from '@/utils/useMinimumLoading'
import { Button } from '@/components/ui/button'
import FloatingSaveButton from '@/components/FloatingSaveButton.vue'
import PageSkeleton from '@/components/PageSkeleton.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { TriangleAlert } from 'lucide-vue-next'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectItemText,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import { toast } from 'vue-sonner'

// Query keys for preloader settings
const preloaderQueryKeys = {
    all: ['settings', 'preloader'],
    settings: () => [...preloaderQueryKeys.all, 'settings'],
}

/**
 * Composable for preloader settings query
 */
function usePreloaderSettingsQuery() {
    return useQuery({
        queryKey: preloaderQueryKeys.settings(),
        queryFn: () => settingsApi.preloader.get(),
        staleTime: 5 * 60 * 1000, // 5 minutes
        retry: 2,
    })
}

/**
 * Composable for saving preloader settings mutation
 */
function useSavePreloaderSettingsMutation() {
    const queryClient = useQueryClient()

    return useMutation({
        mutationFn: (settings) => settingsApi.preloader.save(settings),
        onSuccess: (data) => {
            // Update the cache with the new data immediately
            queryClient.setQueryData(preloaderQueryKeys.settings(), data)
            // No invalidation - we trust the server response
        },
        onError: (_error) => {
            // Error toast is handled by API client, no need to show it here
        },
    })
}

// Default settings structure
// Keep these in sync with src/Core/Preloader.php::$default_settings.
const defaultSettings = {
    preload_post_onn: false,
    preload_speed: 12,
    preload_onn: false,
    preload_path_onn: false,
    preload_path_mch: '',
    preload_schedule_onn: false,
    preload_schedule_int: 1,
    preload_schedule_unt: '86400',
}

// Reactive settings ref
const settings = ref({ ...defaultSettings })

const preloadUrl = ref('')
const preloadJobs = ref([])

// Use TanStack Query for settings
const { data: fetchedSettings, isLoading: queryIsLoading, error } = usePreloaderSettingsQuery()
const isLoading = useMinimumLoading(queryIsLoading)

// Use TanStack Query for mutation
const saveMutation = useSavePreloaderSettingsMutation()

// Use floating save composable before using its functions
const { hasChanges, storeOriginalSettings, undoChanges, markAsSaved, isInitialized } =
    useFloatingSave(settings)

// Watch for data changes and update local settings
watch(
    fetchedSettings,
    (newData) => {
        // Extract settings from API response
        const data = newData?.settings || newData || {}

        settings.value = {
            ...defaultSettings,
            ...data,
            preload_speed: parseInt(data.preload_speed || defaultSettings.preload_speed),
            preload_schedule_int: parseInt(
                data.preload_schedule_int || defaultSettings.preload_schedule_int
            ),
            preload_schedule_unt: String(
                data.preload_schedule_unt || defaultSettings.preload_schedule_unt
            ),
        }

        // Convert string booleans to actual booleans
        Object.keys(settings.value).forEach((key) => {
            if (key.endsWith('_onn') && typeof settings.value[key] === 'string') {
                settings.value[key] = settings.value[key] === 'true'
            }
        })

        // Remove legacy keys no longer used by the backend so the floating
        // save button does not incorrectly show unsaved changes.
        delete settings.value.preload_batch_size

        // Store original settings when data is first loaded
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
        toast.success('Cache preload settings saved successfully!')
    } catch (error) {
        console.error('Save preloader settings error:', error)
    }
}

const startPreload = async () => {
    try {
        await cacheApi.preload.start(preloadUrl.value)
        preloadUrl.value = ''
    } catch (error) {
        console.error('Failed to start preload:', error)
    }
}

const getJobStatusClass = (status) => {
    const classes = {
        running: 'bg-blue-100 text-blue-800',
        completed: 'bg-green-100 text-green-800',
        failed: 'bg-red-100 text-red-800',
        pending: 'bg-gray-100 text-gray-800',
    }
    return classes[status.toLowerCase()] || classes.pending
}

// Computed properties for loading states
const isSaving = computed(() => saveMutation.isPending.value)

// Error handling
const errorMessage = computed(() => {
    if (error.value) {
        return error.value.message || 'Failed to load preloader settings'
    }
    if (saveMutation.error.value) {
        return saveMutation.error.value.message || 'Failed to save preloader settings'
    }
    return null
})
</script>
