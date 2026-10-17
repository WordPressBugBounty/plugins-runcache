<template>
    <div class="rce-space-y-7">
        <h3 class="rce-text-base rce-font-semibold rce-text-gray-900">
            NGINX FastCGI Cache Configuration
        </h3>

        <ul class="rce-list-none rce-space-y-6">
            <!-- Create Config File in conf.d -->
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="1" />
                    <span class="rce-font-medium rce-text-sm">Create NGINX Config Include</span>
                </div>
                <div class="md:rce-ml-[4.75rem] rce-space-y-4">
                    <p class="rce-text-sm rce-text-gray-600">
                        Create this file
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono rce-text-xs">
                            /etc/nginx/conf.d/{{ configFilename }}
                        </code>
                        with content:
                    </p>
                    <CodeSnippet>include {{ configDir }}/runcache-fastcgi-config.conf;</CodeSnippet>
                </div>
            </li>

            <!-- Add Cache Directives to PHP Location -->
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="2" />
                    <span class="rce-font-medium rce-text-sm">
                        Include Cache Directives in PHP Location
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem] rce-space-y-4">
                    <p class="rce-text-sm rce-text-gray-600">
                        Add this line
                        <strong>inside</strong>
                        your existing
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono rce-text-xs">
                            location ~ \.php$
                        </code>
                        block:
                    </p>
                    <CodeSnippet>include {{ configDir }}/runcache-fastcgi-cache.conf;</CodeSnippet>

                    <p class="rce-text-sm rce-text-gray-600">
                        Complete PHP location block example:
                    </p>

                    <CodeSnippet
                        :code="`location ~ \\.php$ {
    # Your existing FastCGI configuration...
    # include snippets/fastcgi-php.conf;
    # fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;

    # Add RunCache FastCGI cache directives
    include ${configDir}/runcache-fastcgi-cache.conf;
}`" />

                    <Alert variant="warning">
                        <AlertTriangle class="rce-mt-0.5 rce-size-4" :stroke-width="2" />
                        <AlertTitle>Important</AlertTitle>
                        <AlertDescription class="rce-mt-0.5">
                            Add the include line
                            <strong>after</strong>
                            your existing FastCGI configuration (fastcgi_pass, etc.). Do not replace
                            your existing PHP configuration.
                        </AlertDescription>
                    </Alert>
                </div>
            </li>

            <!-- Deny Internal Cache Files in Server Block -->
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="3" />
                    <span class="rce-font-medium rce-text-sm">
                        Deny Internal Cache Files in Server Block
                    </span>
                </div>
                <div class="md:rce-ml-[4.75rem] rce-space-y-4">
                    <p class="rce-text-sm rce-text-gray-600">
                        RunCache stores internal state files under
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono rce-text-xs">
                            wp-content/cache/runcache/
                        </code>
                        and generated config files under
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono rce-text-xs">
                            wp-content/uploads/runcache/
                        </code>
                        . None of them are served over HTTP. Add this block to your NGINX
                        <code
                            class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono rce-text-xs">
                            server
                        </code>
                        block, next to the two includes:
                    </p>

                    <CodeSnippet
                        :code="`# RunCache: internal cache state is never URL-served.
location ~* /wp-content/cache/runcache/.*\\.(json|meta)$ {
    deny all;
    return 404;
}
location ~* /wp-content/uploads/runcache/.*\\.conf$ {
    deny all;
    return 404;
}`" />
                </div>
            </li>

            <!-- Test and Reload -->
            <li class="rce-space-y-4">
                <div class="rce-flex rce-items-center rce-space-x-3">
                    <StepNumber :number="4" />
                    <span class="rce-font-medium rce-text-sm">Test and Reload NGINX</span>
                </div>
                <div class="md:rce-ml-[4.75rem] rce-space-y-4">
                    <p class="rce-text-sm rce-text-gray-600">Test your NGINX configuration:</p>
                    <CodeSnippet>nginx -t</CodeSnippet>
                    <p class="rce-text-sm rce-text-gray-600">
                        If the test passes, reload NGINX to apply changes:
                    </p>
                    <CodeSnippet>nginx -s reload</CodeSnippet>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { AlertTriangle } from 'lucide-vue-next'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import CodeSnippet from '@/components/CodeSnippet.vue'
import StepNumber from '@/components/StepNumber.vue'

const props = defineProps({
    configDir: {
        type: String,
        required: true,
    },
    siteUrl: {
        type: String,
        required: true,
    },
    siteName: {
        type: String,
        required: true,
    },
})

const siteHostname = computed(() => {
    try {
        return new URL(props.siteUrl).hostname
    } catch {
        return props.siteUrl
    }
})

const configFilename = computed(() => {
    return siteHostname.value.replace(/\./g, '-') + '-runcache-fastcgi-config.conf'
})

const formattedSiteName = computed(() => {
    const formatted = props.siteName
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '_')
        .replace(/-+/g, '_')
        .substring(0, 30)
    return 'runcache_' + formatted + '_fastcgi'
})
</script>
