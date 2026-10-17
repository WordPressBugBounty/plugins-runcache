<template>
    <Card>
        <CardContent class="rce-pt-12 rce-pb-12">
            <div
                class="rce-flex rce-flex-col rce-items-center rce-justify-center rce-text-center rce-gap-5">
                <div
                    class="rce-flex rce-items-center rce-justify-center rce-rounded-lg rce-size-12 rce-border rce-border-primary-300 rce-ring-1 rce-ring-offset-3 rce-ring-primary-200 rce-bg-gradient-to-b rce-from-white rce-to-primary-50/50">
                    <GalleryHorizontalEnd
                        :stroke-width="1.2"
                        :size="20"
                        class="rce-text-primary-700" />
                </div>

                <div class="rce-flex rce-flex-col rce-gap-2">
                    <h3 class="rce-font-semibold">Configure Caching</h3>
                    <div
                        class="rce-text-sm rce-text-gray-500 rce-space-y-1 rce-max-w-lg rce-mx-auto">
                        Activate caching to supercharge your website speed and improve user
                        experience. Reduce server load, decrease page loading times, and boost your
                        site's performance instantly.
                    </div>
                </div>

                <Button
                    variant="primary"
                    :disabled="cacheState.isTogglingCache.value"
                    @click="cacheState.toggleCache">
                    <LoaderCircle
                        v-if="cacheState.isTogglingCache.value"
                        :size="20"
                        :stroke-width="1.5"
                        class="rce-animate-spin" />
                    {{ cacheState.isTogglingCache.value ? 'Activating...' : 'Activate Caching' }}
                </Button>
            </div>
        </CardContent>
    </Card>
</template>

<script setup>
import { inject } from 'vue'
import { GalleryHorizontalEnd, LoaderCircle } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'

const cacheState = inject('cacheState')
</script>
