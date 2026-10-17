<template>
    <div class="rce-space-y-7">
        <h3 class="rce-text-base rce-font-semibold rce-text-gray-900">
            Server Cache Configuration
        </h3>

        <div>
            <h4 class="rce-text-base rce-font-medium rce-mb-1">Apache Configuration</h4>
            <p class="rce-text-sm rce-text-gray-500">
                Enable the required Apache modules for RunCache to function properly.
            </p>
        </div>

        <!-- Manual setup instructions -->
        <ul class="rce-list-none rce-space-y-6">
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="1" />
                    <span class="rce-font-medium rce-text-sm">
                        Ensure the
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-1 rce-rounded-md rce-font-mono rce-text-xs">
                            .htaccess
                        </code>
                        file in your WordPress root directory is writable by the web server.
                    </span>
                </div>
            </li>
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="2" />
                    <span class="rce-font-medium rce-text-sm">
                        Enable the required Apache modules:
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet>a2enmod headers rewrite</CodeSnippet>
                </div>
            </li>
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="3" />
                    <span class="rce-font-medium rce-text-sm">
                        Restart Apache to apply changes:
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem]">
                    <CodeSnippet>systemctl restart apache2</CodeSnippet>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import CodeSnippet from '@/components/CodeSnippet.vue'
import StepNumber from '@/components/StepNumber.vue'
</script>
