<template>
    <div class="rce-space-y-7">
        <h3 class="rce-text-base rce-font-semibold rce-text-gray-900">
            Server Cache Configuration
        </h3>

        <div>
            <h4 class="rce-text-base rce-font-medium rce-mb-1">LiteSpeed Configuration</h4>
            <p class="rce-text-sm rce-text-gray-500">
                Enable .htaccess compatibility for RunCache to function properly. Choose one of the
                following methods.
            </p>
        </div>

        <!-- Method options -->
        <Alert variant="warning">
            <AlertDescription class="rce-mt-0.5">
                <strong>Note:</strong>
                Choose either Method A (WebAdmin) or Method B (Configuration File). You only need to
                complete one method.
            </AlertDescription>
        </Alert>

        <!-- Method A: WebAdmin -->
        <div class="rce-mb-8">
            <h5 class="rce-text-sm rce-font-semibold rce-text-gray-900 rce-mb-3">
                Method A: Via WebAdmin (Recommended)
            </h5>
            <ul class="rce-list-none rce-space-y-4">
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-blue-500 rce-text-blue-600 rce-text-xs rce-font-medium">
                            1
                        </div>
                        <span class="rce-text-sm rce-font-medium">Access WebAdmin Console:</span>
                    </div>
                    <div class="rce-ml-9">
                        <CodeSnippet>http://your-server-ip:7080</CodeSnippet>
                    </div>
                </li>
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-blue-500 rce-text-blue-600 rce-text-xs rce-font-medium">
                            2
                        </div>
                        <span class="rce-text-sm rce-font-medium">Navigate to:</span>
                    </div>
                    <div class="rce-ml-9">
                        <p class="rce-text-xs rce-text-gray-600">
                            Configuration → Virtual Hosts → Your Virtual Host → Rewrite
                        </p>
                    </div>
                </li>
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-blue-500 rce-text-blue-600 rce-text-xs rce-font-medium">
                            3
                        </div>
                        <span class="rce-text-sm rce-font-medium">Enable Rewrite and Save:</span>
                    </div>
                    <div class="rce-ml-9">
                        <p class="rce-text-xs rce-text-gray-600">
                            Check "Enable Rewrite" and click "Save". Then click "Graceful Restart".
                        </p>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Method B: Configuration File -->
        <div class="rce-mb-6">
            <h5 class="rce-text-sm rce-font-semibold rce-text-gray-900 rce-mb-3">
                Method B: Via Configuration File
            </h5>
            <ul class="rce-list-none rce-space-y-4">
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-green-500 rce-text-green-600 rce-text-xs rce-font-medium">
                            1
                        </div>
                        <span class="rce-text-sm rce-font-medium">
                            Edit your virtual host configuration:
                        </span>
                    </div>
                    <div class="rce-ml-9 rce-space-y-2">
                        <p class="rce-text-xs rce-text-gray-600">
                            Find your virtual host config file (usually in
                            <code
                                class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono">
                                /usr/local/lsws/conf/vhosts/your-domain/vhconf.conf
                            </code>
                            )
                        </p>
                    </div>
                </li>
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-green-500 rce-text-green-600 rce-text-xs rce-font-medium">
                            2
                        </div>
                        <span class="rce-text-sm rce-font-medium">
                            Add or modify the rewrite block:
                        </span>
                    </div>
                    <div class="rce-ml-9">
                        <CodeSnippet
                            :code="`rewrite  {
  enable                  1
  autoLoadHtaccess        1
}`" />
                    </div>
                </li>
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-green-500 rce-text-green-600 rce-text-xs rce-font-medium">
                            3
                        </div>
                        <span class="rce-text-sm rce-font-medium">
                            Restart LiteSpeed to apply changes:
                        </span>
                    </div>
                    <div class="rce-ml-9">
                        <CodeSnippet>/usr/local/lsws/bin/lswsctrl restart</CodeSnippet>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Optional: Cache Headers -->
        <Alert variant="info">
            <CircleCheck class="rce-mt-0.5 rce-size-4" :stroke-width="2" />
            <AlertTitle>Optional</AlertTitle>
            <AlertDescription class="rce-mt-0.5">
                Add cache headers to monitor cache hits. RunCache works without this, but headers
                help you verify caching is functioning correctly.
            </AlertDescription>
        </Alert>

        <div class="rce-mb-6">
            <h5 class="rce-text-sm rce-font-semibold rce-text-gray-900 rce-mb-3">
                Optional: Add Cache Headers
            </h5>
            <ul class="rce-list-none rce-space-y-4">
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-purple-500 rce-text-purple-600 rce-text-xs rce-font-medium">
                            1
                        </div>
                        <span class="rce-text-sm rce-font-medium">
                            Edit your virtual host configuration:
                        </span>
                    </div>
                    <div class="rce-ml-9 rce-space-y-2">
                        <p class="rce-text-xs rce-text-gray-600">
                            Open your virtual host config file (usually in
                            <code
                                class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono">
                                /usr/local/lsws/conf/vhosts/your-domain/vhconf.conf
                            </code>
                            )
                        </p>
                    </div>
                </li>
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-purple-500 rce-text-purple-600 rce-text-xs rce-font-medium">
                            2
                        </div>
                        <span class="rce-text-sm rce-font-medium">
                            Add this context block at the end of the file:
                        </span>
                    </div>
                    <div class="rce-ml-9">
                        <CodeSnippet
                            :code="`context /wp-content/cache/runcache/ {
  location $DOC_ROOT/wp-content/cache/runcache/
  extraHeaders  <<<END_extraHeaders
  X-Cache-Type: OLS
  X-Cache-Status: HIT
  END_extraHeaders
}`" />
                    </div>
                </li>
                <li class="rce-space-y-4">
                    <div class="rce-flex rce-items-center rce-space-x-3">
                        <div
                            class="rce-size-6 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-border-purple-500 rce-text-purple-600 rce-text-xs rce-font-medium">
                            3
                        </div>
                        <span class="rce-text-sm rce-font-medium">
                            Restart LiteSpeed to apply changes:
                        </span>
                    </div>
                    <div class="rce-ml-9">
                        <CodeSnippet>/usr/local/lsws/bin/lswsctrl restart</CodeSnippet>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { CircleCheck } from 'lucide-vue-next'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import CodeSnippet from '@/components/CodeSnippet.vue'
</script>
