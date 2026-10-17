<template>
    <div class="rce-space-y-7" style="display: none">
        <h3 class="rce-text-base rce-font-semibold rce-text-gray-900">
            RunCloud Server Cache Configuration
        </h3>

        <!-- Alert for RunCloud users -->
        <Alert variant="info" style="display: none">
            <Info class="rce-size-4" :stroke-width="2" />
            <AlertTitle>RunCloud Server Detected</AlertTitle>
            <AlertDescription
                class="rce-flex md:rce-flex-row rce-flex-col md:rce-items-center rce-gap-2">
                <div>
                    It seems you're using a RunCloud managed server. You can get better performance
                    and automatic setup by connecting your webapp to this site through RunCloud
                    Panel.
                </div>
                <a
                    href="https://manage.runcloud.io/webapplications"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rce-font-medium rce-underline-offset-4 rce-py-2 rce-shrink-0 rce-text-sky-500 hover:rce-text-sky-600 rce-underline">
                    Connect via RunCloud Panel
                </a>
            </AlertDescription>
        </Alert>

        <!-- Manual setup instructions -->
        <ul class="rce-list-none rce-space-y-6">
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="1" />
                    <span class="rce-font-medium rce-text-sm">
                        Create a new file with this name:
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet>
                        /etc/nginx-rc/extra.d/{{ webappName }}.location.main-before.runcache.conf
                    </CodeSnippet>
                </div>
            </li>
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="2" />
                    <span class="rce-font-medium rce-text-sm">
                        Add this single line to the file:
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet
                        :code="`#inside /etc/nginx-rc/extra.d/${webappName}.location.root.runcache.conf

include ${configDir}/runcache-nginx.conf;`" />
                </div>
            </li>
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="3" />
                    <span class="rce-font-medium rce-text-sm">Test the configuration:</span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet>nginx-rc -t</CodeSnippet>
                </div>
            </li>
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="4" />
                    <span class="rce-font-medium rce-text-sm">Reload NGINX if test passes:</span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet>systemctl reload nginx-rc</CodeSnippet>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { Info } from 'lucide-vue-next'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import CodeSnippet from '@/components/CodeSnippet.vue'
import StepNumber from '@/components/StepNumber.vue'

defineProps({
    webappName: {
        type: String,
        required: true,
    },
    configDir: {
        type: String,
        required: true,
    },
})
</script>
