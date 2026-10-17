<template>
    <div class="rce-space-y-7">
        <Alert variant="info">
            <Cloud class="rce-size-4" :stroke-width="2" />
            <AlertTitle>Cloudflare Cache Setup</AlertTitle>
            <AlertDescription class="rce-space-y-3">
                <p>
                    Configure your Cloudflare API token to enable caching through Cloudflare's
                    global CDN network. Once connected, Cloudflare will cache your entire website
                    and serve it from their edge locations for faster delivery.
                </p>
                <ol class="rce-list-decimal rce-ml-5 rce-space-y-1 rce-text-sm rce-text-gray-600">
                    <li>
                        In Cloudflare, navigate to
                        <span class="rce-font-medium">
                            My Profile → API Tokens → Create Token → Custom Token.
                        </span>
                    </li>
                    <li>
                        Add these permissions:
                        <ul class="rce-list-disc rce-ml-5 rce-space-y-1 rce-mt-1">
                            <li><span class="rce-font-medium">Zone - Zone - Read</span></li>
                            <li><span class="rce-font-medium">Zone - Cache Purge - Purge</span></li>
                            <li><span class="rce-font-medium">Zone - Cache Rules - Edit</span></li>
                        </ul>
                    </li>
                    <li>
                        Under
                        <span class="rce-font-medium">Zone Resources,</span>
                        grant access to the zone that contains this site.
                    </li>
                    <li>Generate the token, copy it immediately, and store it securely.</li>
                    <li>Make sure the site domain is proxied (orange cloud) in Cloudflare.</li>
                </ol>
            </AlertDescription>
        </Alert>

        <div>
            <form @submit.prevent="saveSettings" class="rce-space-y-7">
                <!-- Auth Method -->
                <div class="rce-space-y-2">
                    <Label for="auth_type">Authentication Method</Label>
                    <Select v-model="settings.auth_type" id="auth_type">
                        <SelectTrigger>
                            <SelectValue placeholder="Select authentication method" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="token">API Token (recommended)</SelectItem>
                            <SelectItem value="key">Global API Key</SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="rce-text-sm rce-text-muted-foreground">
                        Use a scoped API token whenever possible. Select
                        <span class="rce-font-medium">Global API Key</span>
                        only if tokens are not available for your Cloudflare plan.
                    </p>
                </div>

                <!-- API Token / Key -->
                <div class="rce-space-y-2">
                    <Label for="api_token">
                        {{ settings.auth_type === 'key' ? 'Global API Key' : 'API Token' }}
                    </Label>
                    <Input
                        id="api_token"
                        v-model="settings.api_token"
                        type="password"
                        :placeholder="
                            settings.api_token
                                ? '••••••••'
                                : settings.auth_type === 'key'
                                  ? 'Enter your Cloudflare API key'
                                  : 'Enter your Cloudflare API token'
                        "
                        autocomplete="off" />
                </div>

                <!-- Account Email (API Key mode) -->
                <div v-if="settings.auth_type === 'key'" class="rce-space-y-2">
                    <Label for="account_email">Cloudflare Account Email</Label>
                    <Input
                        id="account_email"
                        v-model="settings.account_email"
                        type="email"
                        placeholder="name@example.com"
                        autocomplete="off" />
                    <p class="rce-text-sm rce-text-muted-foreground">
                        Required by Cloudflare when using the Global API Key authentication flow.
                    </p>
                </div>

                <!-- Save Button -->
                <div class="rce-pt-2">
                    <Button type="submit" :disabled="isSaving || !isValid">
                        <LoaderCircle
                            v-if="isSaving"
                            class="rce-w-4 rce-h-4 rce-mr-2 rce-animate-spin" />
                        {{ isSaving ? 'Saving...' : 'Save Settings' }}
                    </Button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { reactive, computed, watch } from 'vue'
import { LoaderCircle, Cloud } from 'lucide-vue-next'
import { useMutation } from '@tanstack/vue-query'
import { integrationsApi } from '@/api/integrations'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import { Alert, AlertTitle, AlertDescription } from './ui/alert'

// Define props and emits
const props = defineProps({
    settings: {
        type: Object,
        default: () => ({}),
    },
})
const emit = defineEmits(['saved'])

// Form state
const settings = reactive({
    api_token: '',
    auth_type: 'token',
    account_email: '',
})

function useSaveCloudflareSettingsMutation() {
    return useMutation({
        mutationFn: (settings) =>
            integrationsApi.edgeCdn.saveProviderSettings('cloudflare', settings),
    })
}

// Mutation
const saveMutation = useSaveCloudflareSettingsMutation()

const isSaving = computed(() => saveMutation.isPending.value)

// Computed states
const isValid = computed(() => {
    const hasCredential = Boolean(settings.api_token)
    const needsEmail = settings.auth_type === 'key'
    const hasEmail = !needsEmail || Boolean(settings.account_email)
    return hasCredential && hasEmail
})

// Watch parent-provided settings and update form
watch(
    () => props.settings,
    (data) => {
        settings.api_token = data?.api_token || ''
        settings.auth_type = data?.auth_type || 'token'
        settings.account_email = data?.account_email || ''
    },
    { immediate: true, deep: true }
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

const saveSettings = () => {
    if (!isValid.value) return

    saveMutation.mutate({
        api_token: settings.api_token,
        auth_type: settings.auth_type,
        account_email: settings.account_email,
    })
}
</script>
