<template>
    <div class="rce-space-y-7">
        <h3 class="rce-text-base rce-font-semibold rce-text-gray-900">
            Server Cache Configuration
        </h3>

        <!-- Manual setup instructions -->
        <ul class="rce-list-none rce-space-y-6">
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="1" />
                    <span class="rce-font-medium rce-text-sm">
                        Add this line to your NGINX
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-1 rce-rounded-md rce-font-mono rce-text-xs">
                            server
                        </code>
                        block:
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem] rce-space-y-4">
                    <CodeSnippet>include {{ configDir }}/runcache-nginx.conf;</CodeSnippet>

                    <p class="rce-text-sm rce-text-gray-600">
                        Place it
                        <strong>before</strong>
                        the
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-1 rce-rounded-md rce-font-mono rce-text-xs">
                            location /
                        </code>
                        block. Complete server block example:
                    </p>

                    <CodeSnippet
                        :code="`server {
    # Other directives...
    include ${configDir}/runcache-nginx.conf;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }
}`" />

                    <Alert variant="info">
                        <CircleCheck class="rce-mt-0.5 rce-size-4" :stroke-width="2" />
                        <AlertTitle>Note</AlertTitle>
                        <AlertDescription class="rce-mt-0.5">
                            The configuration file has been automatically created in your uploads
                            directory.
                        </AlertDescription>
                    </Alert>
                </div>
            </li>
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="2" />
                    <span class="rce-font-medium rce-text-sm">Test your NGINX configuration:</span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet>nginx -t</CodeSnippet>
                </div>
            </li>
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="3" />
                    <span class="rce-font-medium rce-text-sm">
                        If the test passes, reload NGINX to apply changes:
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet>nginx -s reload</CodeSnippet>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { Info, CircleCheck } from 'lucide-vue-next'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import CodeSnippet from '@/components/CodeSnippet.vue'
import StepNumber from '@/components/StepNumber.vue'

defineProps({
    configDir: {
        type: String,
        required: true,
    },
})
</script>
