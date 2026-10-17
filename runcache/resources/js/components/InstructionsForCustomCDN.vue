<template>
    <div class="rce-space-y-7">
        <Alert variant="info">
            <Info class="rce-mt-0.5 rce-size-4" :stroke-width="2" />
            <AlertTitle>Custom CDN Setup</AlertTitle>
            <AlertDescription class="rce-mt-0.5 rce-space-y-2">
                <p>
                    Provide the CDN hostname that serves your site's static assets. RunCache will
                    rewrite eligible asset URLs to use this hostname as soon as the settings are
                    saved.
                </p>
                <p class="rce-text-sm rce-text-gray-600">
                    Hostnames should not include protocol prefixes. Example:
                    <span class="rce-font-mono">cdn.example.com</span>
                </p>
            </AlertDescription>
        </Alert>

        <form @submit.prevent="saveSettings" class="rce-space-y-7">
            <div class="rce-space-y-2">
                <Label for="custom-cdn-hostname">CDN Hostname</Label>
                <Input
                    id="custom-cdn-hostname"
                    v-model="form.hostname"
                    placeholder="cdn.example.com"
                    autocomplete="off" />
                <p class="rce-text-sm rce-text-muted-foreground">
                    Enter the hostname where your CDN serves assets. Do not include
                    <span class="rce-font-mono">http://</span>
                    or
                    <span class="rce-font-mono">https://</span>
                    .
                </p>
            </div>

            <div class="rce-space-y-2">
                <Label for="custom-cdn-extensions">Allowed File Extensions</Label>
                <Textarea
                    id="custom-cdn-extensions"
                    v-model="allowedExtensionsInput"
                    rows="3"
                    class="rce-font-mono rce-text-sm"
                    placeholder="jpg, jpeg, png, gif, ico, css, js, svg, woff, woff2, ttf, eot" />
                <p class="rce-text-sm rce-text-muted-foreground">
                    Separate extensions with commas or spaces. Use
                    <span class="rce-font-mono">*</span>
                    to allow all file types. Leave empty to use the default list. RunCache
                    automatically enables the Custom CDN whenever these settings are saved.
                </p>
            </div>

            <div class="rce-pt-2">
                <Button type="submit" :disabled="isSaving || !form.hostname.trim()">
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
</template>

<script setup>
import { reactive, ref, watch, onMounted } from 'vue'
import { LoaderCircle, Info } from 'lucide-vue-next'
import { integrationsApi } from '@/api/integrations'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import { Textarea } from '@/components/ui/textarea'

const emit = defineEmits(['saved'])

const form = reactive({
    hostname: '',
})

const isSaving = ref(false)
const allowedExtensionsInput = ref('')

const DEFAULT_ALLOWED_EXTENSIONS = [
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

const toExtensionList = (source = []) => {
    if (Array.isArray(source) && source.length > 0) {
        return source
    }

    return DEFAULT_ALLOWED_EXTENSIONS
}

const parseExtensions = (value = '') => {
    if (!value) {
        return DEFAULT_ALLOWED_EXTENSIONS
    }

    const items = value
        .split(/[,\s]+/)
        .map((entry) => entry.trim().replace(/^\.+/, '').toLowerCase())
        .filter((entry) => entry !== '' && /^[a-z0-9*]+$/.test(entry))

    if (items.includes('*')) {
        return ['*']
    }

    return Array.from(new Set(items))
}

const applySettings = (source = {}) => {
    if (!source) {
        return
    }

    form.hostname = source.hostname || ''
    const extensions = toExtensionList(source.allowed_extensions)
    allowedExtensionsInput.value = extensions.join(', ')
}

const loadSettings = async () => {
    try {
        const data = await integrationsApi.customcdn.getSettings()
        applySettings(data)
    } catch (error) {
        console.error('Failed to load Custom CDN settings:', error)
    }
}

const saveSettings = async () => {
    if (isSaving.value) return

    const hostname = form.hostname.trim()
    if (!hostname) {
        return
    }

    isSaving.value = true
    try {
        const payload = {
            hostname,
            allowed_extensions: parseExtensions(allowedExtensionsInput.value),
            enable: true,
            cdn_type: 'custom',
        }

        const response = await integrationsApi.customcdn.saveSettings(payload)

        if (response?.settings?.hostname) {
            form.hostname = response.settings.hostname
            allowedExtensionsInput.value = toExtensionList(
                response.settings.allowed_extensions
            ).join(', ')
        } else {
            await loadSettings()
        }

        emit('saved')
    } catch (error) {
        console.error('Failed to save Custom CDN settings:', error)
    } finally {
        isSaving.value = false
    }
}

onMounted(() => {
    loadSettings()
})
</script>
