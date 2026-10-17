<template>
    <!-- Loading State -->
    <PageSkeleton v-if="isLoading" :card-count="7" />

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
            <h2 class="rce-text-2xl rce-font-bold rce-text-gray-900">Cache Purger Settings</h2>
            <p class="rce-mt-1 rce-text-sm rce-text-gray-600">
                Configure when and how RunCache should automatically purge your website's cache.
            </p>
        </div>

        <form id="purger-form" @submit.prevent="saveSettings" class="rce-space-y-7">
            <!-- Manual Purge & Preload -->
            <Card>
                <CardHeader>
                    <CardTitle>Manual Purge & Preload</CardTitle>
                    <p class="rce-text-sm rce-text-gray-600">
                        Purge cache for a specific URL and optionally warm it up immediately after.
                    </p>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div>
                            <Label
                                class="rce-block rce-text-sm rce-font-medium rce-text-gray-700 rce-mb-2">
                                URL
                            </Label>
                            <Input
                                v-model="manualUrl"
                                type="text"
                                placeholder="https://example.com/sample-page/"
                                autocomplete="off" />
                        </div>
                        <div class="rce-flex rce-gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="!canRunManualAction || isPurgingUrl"
                                @click="purgeSingleUrl">
                                {{ isPurgingUrl ? 'Purging…' : 'Purge Cache' }}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="!canRunManualAction || isPreloadingUrl"
                                @click="purgeAndPreloadUrl">
                                {{ isPreloadingUrl ? 'Purging & Preloading…' : 'Purge & Preload' }}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Purge All Cache -->
            <Card>
                <CardHeader>
                    <CardTitle>Purge All Cache</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="purge_permalink_onn"
                                v-model="settings.purge_permalink_onn" />
                            <Label for="purge_permalink_onn" class="rce-text-sm rce-text-gray-700">
                                When custom permalink structure is changed
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="purge_theme_switch_onn"
                                v-model="settings.purge_theme_switch_onn" />
                            <Label
                                for="purge_theme_switch_onn"
                                class="rce-text-sm rce-text-gray-700">
                                When switch to a different theme
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="purge_general_onn" v-model="settings.purge_general_onn" />
                            <Label for="purge_general_onn" class="rce-text-sm rce-text-gray-700">
                                When site title / tagline / WordPress address / site address is
                                changed
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="purge_customize_onn"
                                v-model="settings.purge_customize_onn" />
                            <Label for="purge_customize_onn" class="rce-text-sm rce-text-gray-700">
                                When customizer is saved/updated (Appearance-Customize)
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="purge_upgrader_onn" v-model="settings.purge_upgrader_onn" />
                            <Label for="purge_upgrader_onn" class="rce-text-sm rce-text-gray-700">
                                When a theme/plugin is updated
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="purge_plugin_onn" v-model="settings.purge_plugin_onn" />
                            <Label for="purge_plugin_onn" class="rce-text-sm rce-text-gray-700">
                                When a plugin is activated/deactivated
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="purge_navmenu_onn" v-model="settings.purge_navmenu_onn" />
                            <Label for="purge_navmenu_onn" class="rce-text-sm rce-text-gray-700">
                                When a nav menu is updated
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="purge_sidebar_onn" v-model="settings.purge_sidebar_onn" />
                            <Label for="purge_sidebar_onn" class="rce-text-sm rce-text-gray-700">
                                When a sidebar widget is updated
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="purge_widget_onn" v-model="settings.purge_widget_onn" />
                            <Label for="purge_widget_onn" class="rce-text-sm rce-text-gray-700">
                                When a widget parameter is updated
                            </Label>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Homepage Purge -->
            <Card>
                <CardHeader>
                    <CardTitle>Purge Homepage</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="homepage_post_onn" v-model="settings.homepage_post_onn" />
                            <Label for="homepage_post_onn" class="rce-text-sm rce-text-gray-700">
                                When a post/page/CPT is published/modified
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="homepage_removed_onn"
                                v-model="settings.homepage_removed_onn" />
                            <Label for="homepage_removed_onn" class="rce-text-sm rce-text-gray-700">
                                When a published post/page/CPT is trashed
                            </Label>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Content Purge -->
            <Card>
                <CardHeader>
                    <CardTitle>Purge Post/Page/CPT</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="content_publish_onn"
                                v-model="settings.content_publish_onn" />
                            <Label for="content_publish_onn" class="rce-text-sm rce-text-gray-700">
                                When a post/page/CPT is published/modified/trashed
                            </Label>
                        </div>

                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="content_comment_approved_onn"
                                v-model="settings.content_comment_approved_onn" />
                            <Label
                                for="content_comment_approved_onn"
                                class="rce-text-sm rce-text-gray-700">
                                When a comment is approved/unapproved/trashed
                            </Label>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Archives Purge -->
            <Card>
                <CardHeader>
                    <CardTitle>Purge Archives</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch
                                id="archives_content_onn"
                                v-model="settings.archives_content_onn" />
                            <Label for="archives_content_onn" class="rce-text-sm rce-text-gray-700">
                                When a post/page/CPT is published/modified/trashed
                            </Label>
                        </div>

                        <div class="rce-ml-8 rce-space-y-4" v-show="settings.archives_content_onn">
                            <div class="rce-flex rce-items-center rce-space-x-3">
                                <Switch id="archives_cpt_onn" v-model="settings.archives_cpt_onn" />
                                <Label for="archives_cpt_onn" class="rce-text-sm rce-text-gray-700">
                                    Purge post type archive page
                                </Label>
                            </div>

                            <div class="rce-flex rce-items-center rce-space-x-3">
                                <Switch
                                    id="archives_category_onn"
                                    v-model="settings.archives_category_onn" />
                                <Label
                                    for="archives_category_onn"
                                    class="rce-text-sm rce-text-gray-700">
                                    Purge category/tag/taxonomy pages
                                </Label>
                            </div>

                            <div class="rce-flex rce-items-center rce-space-x-3">
                                <Switch
                                    id="archives_author_onn"
                                    v-model="settings.archives_author_onn" />
                                <Label
                                    for="archives_author_onn"
                                    class="rce-text-sm rce-text-gray-700">
                                    Purge author page
                                </Label>
                            </div>

                            <!-- <div class="rce-flex rce-items-center rce-justify-between">
                                    <label for="archives_feed_onn" class="rce-text-sm rce-text-gray-700">
                                        Purge RSS feed page
                                    </label>
                                <Switch
                                    id="archives_feed_onn"
                                    v-model="settings.archives_feed_onn"
                                />
                            </div> -->
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Scheduled Purge -->
            <Card>
                <CardHeader>
                    <CardTitle>Scheduled Purge</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="rce-mb-4 rce-text-sm rce-text-gray-600">
                        Scheduled purge works on the WordPress-side and it could be useful if only
                        you have long "Cache Lifespan" value on the RunCache settings in your
                        server.
                    </p>
                    <div class="rce-space-y-4">
                        <div class="rce-flex rce-items-center rce-space-x-3">
                            <Switch id="schedule_purge_onn" v-model="settings.schedule_purge_onn" />
                            <Label for="schedule_purge_onn" class="rce-text-sm rce-text-gray-700">
                                Enable Scheduled Purge
                            </Label>
                        </div>

                        <div v-show="settings.schedule_purge_onn" class="rce-ml-12 rce-space-y-3">
                            <p class="rce-text-sm rce-text-gray-600">
                                Automatically clear all cache every scheduled time interval below.
                            </p>
                            <div class="rce-flex rce-items-center rce-space-x-2">
                                <Input
                                    type="number"
                                    id="schedule_purge_int"
                                    v-model="settings.schedule_purge_int"
                                    min="1"
                                    class="rce-w-20" />
                                <Select v-model="settings.schedule_purge_unt">
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
                                    </SelectContent>
                                </Select>
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
import { TriangleAlert } from 'lucide-vue-next'
import { useFloatingSave } from '@/utils/useFloatingSave'
import { useMinimumLoading } from '@/utils/useMinimumLoading'
import FloatingSaveButton from '@/components/FloatingSaveButton.vue'
import PageSkeleton from '@/components/PageSkeleton.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectItemText,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'

// Query keys for purger settings
const purgerQueryKeys = {
    all: ['settings', 'purger'],
    settings: () => [...purgerQueryKeys.all, 'settings'],
}

/**
 * Composable for purger settings query
 */
function usePurgerSettingsQuery() {
    return useQuery({
        queryKey: purgerQueryKeys.settings(),
        queryFn: () => settingsApi.purger.get(),
        staleTime: 5 * 60 * 1000, // 5 minutes
        retry: 2,
    })
}

/**
 * Composable for saving purger settings mutation
 */
function useSavePurgerSettingsMutation() {
    const queryClient = useQueryClient()

    return useMutation({
        mutationFn: (settings) => settingsApi.purger.save(settings),
        onSuccess: (data) => {
            // Update the cache with the new data immediately
            queryClient.setQueryData(purgerQueryKeys.settings(), data)
            // No invalidation - we trust the server response
        },
        onError: (_error) => {
            // Error toast is handled by API client, no need to show it here
        },
    })
}

// Default settings structure
const defaultSettings = {
    purge_permalink_onn: false,
    purge_theme_switch_onn: false,
    purge_general_onn: false,
    purge_customize_onn: false,
    purge_upgrader_onn: false,
    purge_plugin_onn: false,
    purge_navmenu_onn: false,
    purge_sidebar_onn: false,
    purge_widget_onn: false,
    homepage_post_onn: false,
    homepage_removed_onn: false,
    content_publish_onn: false,
    content_comment_approved_onn: false,
    archives_content_onn: false,
    archives_cpt_onn: false,
    archives_category_onn: false,
    archives_author_onn: false,
    archives_feed_onn: false,
    schedule_purge_onn: false,
    schedule_purge_int: 12,
    schedule_purge_unt: '3600',
    html_footprint_onn: false,
}

// Reactive settings ref
const settings = ref({ ...defaultSettings })

const manualUrl = ref('')
const isPurgingUrl = ref(false)
const isPreloadingUrl = ref(false)
const getPurgeBaseUrl = () => {
    if (typeof window === 'undefined') {
        return ''
    }

    if (window.runcacheAjax?.siteUrl) {
        return window.runcacheAjax.siteUrl
    }

    if (window.location?.origin) {
        return window.location.origin
    }

    return `${window.location?.protocol || 'https:'}//${window.location?.host || 'localhost'}`
}

const toFullyQualifiedUrl = (value) => {
    if (!value || typeof value !== 'string') {
        return ''
    }

    const trimmed = value.trim()
    if (trimmed.length === 0) {
        return ''
    }

    try {
        return new URL(trimmed, getPurgeBaseUrl()).href
    } catch (error) {
        console.warn('Invalid purge URL provided:', trimmed, error)
        return ''
    }
}

// Use TanStack Query for settings
const { data: fetchedSettings, isLoading: queryIsLoading, error } = usePurgerSettingsQuery()
const isLoading = useMinimumLoading(queryIsLoading)

// Use TanStack Query for mutation
const saveMutation = useSavePurgerSettingsMutation()

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
            schedule_purge_int: parseInt(
                data.schedule_purge_int || defaultSettings.schedule_purge_int
            ),
            schedule_purge_unt: String(
                data.schedule_purge_unt || defaultSettings.schedule_purge_unt
            ),
        }

        // Convert string booleans to actual booleans
        Object.keys(settings.value).forEach((key) => {
            if (key.endsWith('_onn') && typeof settings.value[key] === 'string') {
                settings.value[key] = settings.value[key] === 'true'
            }
        })

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
    } catch (error) {
        console.error('Save purger settings error:', error)
    }
}

const purgeSingleUrl = async () => {
    if (isPurgingUrl.value) return

    const targetUrl = toFullyQualifiedUrl(manualUrl.value)
    if (!targetUrl) return

    manualUrl.value = targetUrl

    isPurgingUrl.value = true
    try {
        await cacheApi.purgeByUrl(targetUrl)
    } catch (error) {
        console.error('Failed to purge URL cache:', error)
    } finally {
        isPurgingUrl.value = false
    }
}

const purgeAndPreloadUrl = async () => {
    if (isPreloadingUrl.value) return

    const targetUrl = toFullyQualifiedUrl(manualUrl.value)
    if (!targetUrl) return

    manualUrl.value = targetUrl

    isPreloadingUrl.value = true
    try {
        await cacheApi.purgeByUrl(targetUrl, { preload: true })
    } catch (error) {
        console.error('Failed to purge & preload URL cache:', error)
    } finally {
        isPreloadingUrl.value = false
    }
}

const canRunManualAction = computed(() => manualUrl.value.trim().length > 0)

// Computed properties for loading states
const isSaving = computed(() => saveMutation.isPending.value)

// Error handling
const errorMessage = computed(() => {
    if (error.value) {
        return error.value.message || 'Failed to load purger settings'
    }
    if (saveMutation.error.value) {
        return saveMutation.error.value.message || 'Failed to save purger settings'
    }
    return null
})
</script>
