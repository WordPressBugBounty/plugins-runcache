<template>
    <div class="rce-space-y-7">
        <h3 class="rce-text-base rce-font-semibold rce-text-gray-900">Redis Configuration</h3>

        <!-- Connection Form -->
        <div>
            <Alert variant="warning" class="rce-mb-7">
                <AlertTriangle class="rce-mt-0.5 rce-size-4" :stroke-width="2" />
                <AlertTitle>Redis Important Notes</AlertTitle>
                <AlertDescription class="rce-mt-0.5">
                    <ul class="rce-list-disc rce-list-inside">
                        <li>Make sure Redis server is installed and running</li>
                        <li>Redis extension for PHP must be installed</li>
                        <li>Redis server should be properly secured if exposed to the internet</li>
                        <li>Consider using a Redis password in production environments</li>
                        <li>
                            All RunCache entries will be stored with 'runcache:' prefix for easy
                            management
                        </li>
                        <li>Default Redis database (0) will be used for better compatibility</li>
                    </ul>
                </AlertDescription>
            </Alert>

            <div>
                <form @submit.prevent="saveConnection" class="rce-space-y-7">
                    <div class="rce-space-y-2">
                        <Label for="redis-host">Host</Label>
                        <Input
                            id="redis-host"
                            v-model="form.host"
                            placeholder="127.0.0.1"
                            type="text" />
                    </div>

                    <div class="rce-space-y-2">
                        <Label for="redis-port">Port</Label>
                        <Input id="redis-port" v-model="form.port" placeholder="6379" type="text" />
                        <p class="rce-text-sm rce-text-gray-600">
                            Set to
                            <strong>0</strong>
                            when connecting through a Unix socket.
                        </p>
                    </div>

                    <div class="rce-space-y-2">
                        <Label for="redis-username">Username (optional)</Label>
                        <Input id="redis-username" v-model="form.username" type="text" />
                    </div>

                    <div class="rce-space-y-2">
                        <Label for="redis-password">Password (optional)</Label>
                        <Input id="redis-password" v-model="form.password" type="password" />
                    </div>

                    <div class="rce-pt-2">
                        <Button type="submit" :disabled="isSaving">
                            <LoaderCircle
                                v-if="isSaving"
                                :size="20"
                                :stroke-width="1.5"
                                class="rce-animate-spin" />
                            {{ isSaving ? 'Saving...' : 'Save Connection' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { LoaderCircle, AlertTriangle } from 'lucide-vue-next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { integrationsApi } from '@/api/integrations'

// Query keys for Redis
const redisQueryKeys = {
    settings: () => ['infrastructure', 'redis', 'settings'],
    connectionTest: () => ['infrastructure', 'redis', 'connectionTest'],
}
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'

// Define emits
const emit = defineEmits(['saved'])

// Form state
const form = ref({
    host: '127.0.0.1',
    port: '6379',
    username: '',
    password: '',
})

// Query functions
function useRedisSettingsQuery() {
    return useQuery({
        queryKey: redisQueryKeys.settings(),
        queryFn: () => integrationsApi.redis.getSettings(),
        staleTime: 5 * 60 * 1000,
        retry: false,
    })
}

function useSaveRedisSettingsMutation() {
    const queryClient = useQueryClient()

    return useMutation({
        mutationFn: (settings) => integrationsApi.redis.saveSettings(settings),
        onSuccess: (data) => {
            // Invalidate related queries
            queryClient.invalidateQueries({ queryKey: redisQueryKeys.settings() })
            queryClient.invalidateQueries({ queryKey: redisQueryKeys.connectionTest() })
            // Toast is handled by API client
        },
        onError: (error) => {
            // Error toast is handled by API client
        },
    })
}

// Queries
const { data: redisSettings, isLoading: isLoadingSettings } = useRedisSettingsQuery()
// Mutation
const saveMutation = useSaveRedisSettingsMutation()

// Computed states
const isSaving = ref(false)

// Initialize form with settings when loaded
watch(
    redisSettings,
    (settings) => {
        if (settings) {
            form.value = {
                host: settings.host || form.value.host,
                port: settings.port || form.value.port,
                username: settings.username || '',
                password: settings.password || '',
            }
        }
    },
    { immediate: true }
)

// Watch save mutation state
watch(
    () => saveMutation.isPending.value,
    (isPending) => {
        isSaving.value = isPending
    }
)

const saveConnection = async () => {
    if (isSaving.value) return

    try {
        await saveMutation.mutateAsync(form.value)
        // Let the parent verify object-cache readiness after the settings request completes.
        emit('saved')
    } catch (error) {
        // The mutation handles the error toast; keep the form available for correction.
    }
}
</script>
