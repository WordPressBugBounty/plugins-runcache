<template>
    <div class="rce-space-y-6">
        <div>
            <h2 class="rce-text-2xl rce-font-bold rce-text-gray-900">Import & Export Settings</h2>
            <p class="rce-mt-1 rce-text-sm rce-text-gray-600">
                Move your RunCache configuration between environments by exporting a JSON snapshot
                and importing it on another site. Imports overwrite the selected sections.
            </p>
        </div>

        <div class="rce-space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Export Settings</CardTitle>
                    <p class="rce-text-sm rce-text-gray-600">
                        Download a JSON file that contains cache, purger, preloader, rules, CDN, and
                        object cache settings.
                    </p>
                </CardHeader>
                <CardContent class="rce-space-y-7">
                    <Alert variant="info">
                        <Info class="rce-size-4" :stroke-width="2" />
                        <AlertTitle>What's included</AlertTitle>
                        <AlertDescription>
                            <ul class="rce-space-y-1">
                                <li>Page cache configuration + provider integrations</li>
                                <li>Automatic purging rules + schedules</li>
                                <li>Preloader paths and schedules</li>
                                <li>Cache rules (exclusions, cookies, query params)</li>
                                <li>Object cache and CDN settings</li>
                            </ul>
                        </AlertDescription>
                    </Alert>

                    <Button
                        variant="primary"
                        :disabled="isExporting"
                        @click="exportSettings"
                        class="rce-w-full md:rce-w-auto">
                        <LoaderCircle
                            v-if="isExporting"
                            class="rce-mr-2 rce-animate-spin"
                            :size="18"
                            :stroke-width="1.75" />
                        <span>
                            {{ isExporting ? 'Preparing download…' : 'Download JSON Export' }}
                        </span>
                    </Button>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Import Settings</CardTitle>
                    <p class="rce-text-sm rce-text-gray-600">
                        Select an export file to apply its configuration. RunCache validates and
                        saves each section individually.
                    </p>
                </CardHeader>
                <CardContent class="rce-space-y-7">
                    <div class="rce-space-y-2">
                        <Label for="import-file">Select JSON File</Label>
                        <Input
                            id="import-file"
                            ref="fileInput"
                            type="file"
                            accept=".json,application/json"
                            @change="handleFileChange" />
                        <!--
                        <p class="rce-text-xs rce-text-gray-500">
                        {{ selectedFileName ? selectedFileName : 'No file selected yet' }}
                        </p>
                        -->
                    </div>

                    <div v-if="detectedSections.length" class="rce-space-y-1">
                        <p class="rce-mb-3 rce-text-sm rce-font-medium rce-text-gray-700">
                            Setting sections detected
                        </p>
                        <div class="rce-flex rce-flex-wrap rce-gap-2">
                            <span
                                v-for="section in detectedSections"
                                :key="section"
                                class="rce-inline-flex rce-items-center rce-rounded-full rce-bg-primary-50 rce-px-3 rce-py-1 rce-text-xs rce-font-medium rce-text-primary-700">
                                {{ sectionLabels[section] ?? section }}
                            </span>
                        </div>
                    </div>

                    <div v-else class="rce-text-sm rce-text-gray-500">
                        <!-- Load an export file to preview which sections will be imported. -->
                    </div>

                    <Alert variant="warning">
                        <Info class="rce-size-4" :stroke-width="2" />
                        <AlertTitle>Overwrites existing configuration</AlertTitle>
                        <AlertDescription>
                            Importing will immediately replace the selected sections. Export a
                            backup first if you need to undo the changes.
                        </AlertDescription>
                    </Alert>

                    <div class="rce-flex rce-gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!selectedFileName && !parsedBundle"
                            @click="clearSelection">
                            Clear Selection
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            :disabled="!canImport || isImporting"
                            @click="importSettings">
                            <LoaderCircle
                                v-if="isImporting"
                                class="rce-mr-2 rce-animate-spin"
                                :size="18"
                                :stroke-width="1.75" />
                            <span>
                                {{ isImporting ? 'Applying settings…' : 'Import Settings' }}
                            </span>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { LoaderCircle, Info } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import { settingsTransferApi } from '@/api/importExport'

const supportedSections = ['page_cache', 'purger', 'preloader', 'rules', 'object_cache', 'cdn']
const sectionLabels = {
    page_cache: 'Page Cache',
    purger: 'Purger',
    preloader: 'Preloader',
    rules: 'Rules',
    object_cache: 'Object Cache',
    cdn: 'CDN',
}

const fileInput = ref(null)
const selectedFileName = ref('')
const parsedBundle = ref(null)
const detectedSections = computed(() => {
    if (!parsedBundle.value || typeof parsedBundle.value !== 'object') {
        return []
    }

    const settingsBlock =
        parsedBundle.value.settings && typeof parsedBundle.value.settings === 'object'
            ? parsedBundle.value.settings
            : parsedBundle.value

    return supportedSections.filter(
        (key) =>
            Object.prototype.hasOwnProperty.call(settingsBlock, key) &&
            typeof settingsBlock[key] === 'object'
    )
})

const isExporting = ref(false)
const isImporting = ref(false)
const lastExportMeta = ref(null)

const canImport = computed(() => detectedSections.value.length > 0 && !!parsedBundle.value)

const formatTimestamp = (value) => {
    if (!value) {
        return ''
    }

    const normalized = typeof value === 'string' ? value.replace(' ', 'T') : value
    const date = new Date(normalized)

    if (Number.isNaN(date.getTime())) {
        return String(value)
    }

    return date.toLocaleString()
}

const downloadJson = (data, filename) => {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}

const exportSettings = async () => {
    if (isExporting.value) {
        return
    }

    isExporting.value = true

    try {
        const response = await settingsTransferApi.export()
        const payload = response?.settings ?? response
        const filename = response?.filename || `runcache-settings-${Date.now()}.json`

        downloadJson(payload, filename)

        lastExportMeta.value = {
            filename,
            generatedAt: payload?.generated_at ?? new Date().toISOString(),
        }

        toast.success('Settings export ready.')
    } catch (error) {
        console.error('Failed to export settings:', error)
        toast.error('Unable to generate export. Please try again.')
    } finally {
        isExporting.value = false
    }
}

const handleFileChange = async (event) => {
    const file = event.target?.files?.[0]

    if (!file) {
        clearSelection()
        return
    }

    selectedFileName.value = file.name

    try {
        const text = await file.text()
        const parsed = JSON.parse(text)
        parsedBundle.value = parsed

        if (!detectedSections.value.length) {
            toast.warning('File loaded, but no supported sections were found.')
        } else {
            toast.success('Settings file ready to import.')
        }
    } catch (error) {
        console.error('Failed to parse import file:', error)
        toast.error('Invalid JSON file. Please verify the export.')
        parsedBundle.value = null
        selectedFileName.value = ''
        if (fileInput.value) {
            fileInput.value.value = ''
        }
    }
}

const clearSelection = () => {
    parsedBundle.value = null
    selectedFileName.value = ''
    if (fileInput.value) {
        fileInput.value.value = ''
    }
}

const importSettings = async () => {
    if (!canImport.value || isImporting.value || !parsedBundle.value) {
        return
    }

    isImporting.value = true

    try {
        await settingsTransferApi.import(parsedBundle.value)
        clearSelection()
    } catch (error) {
        console.error('Failed to import settings:', error)
        // Error toast handled by API client, but keep a fallback.
        if (!error?.message) {
            toast.error('Unable to import settings. Please try again.')
        }
    } finally {
        isImporting.value = false
    }
}
</script>
