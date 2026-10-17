<template>
    <div>
        <!-- Dashboard Stats Skeleton (2-card layout) -->
        <div class="rce-grid rce-grids-cols-1 md:rce-grid-cols-12 rce-gap-4 rce-mb-7">
            <!-- Master Switch Card Skeleton -->
            <div
                class="rce-col-span-1 md:rce-col-span-3 rce-p-0 rce-bg-white rce-border rce-border-gray-200 rce-rounded-xl">
                <div class="rce-flex rce-items-center rce-space-x-4 rce-py-6 rce-px-7">
                    <div class="rce-flex rce-flex-col rce-gap-2 rce-w-full">
                        <div class="rce-flex rce-gap-2">
                            <div class="skeleton rce-h-3.5 rce-w-20"></div>
                            <div class="skeleton rce-h-3.5 rce-w-14"></div>
                        </div>
                        <div class="skeleton rce-h-5 rce-w-24"></div>
                    </div>
                    <div class="skeleton rce-h-5 rce-w-9 rce-rounded-full rce-shrink-0"></div>
                </div>
            </div>

            <!-- Stats Card Skeleton -->
            <div
                class="rce-col-span-1 md:rce-col-span-9 rce-bg-white rce-border rce-border-gray-200 rce-rounded-xl">
                <div class="rce-p-0 rce-pb-0 rce-px-0">
                    <div
                        class="rce-grid rce-grid-cols-1 md:rce-grid-cols-3 rce-divide-y md:rce-divide-y-0 md:rce-divide-x">
                        <!-- Pages Cached Skeleton -->
                        <div
                            class="rce-flex rce-items-center rce-justify-between rce-py-6 rce-px-7">
                            <div class="rce-flex rce-flex-col rce-gap-2">
                                <div class="skeleton rce-h-3.5 rce-w-24"></div>
                                <div class="skeleton rce-h-5 rce-w-16"></div>
                            </div>
                            <div
                                class="rce-flex rce-items-center rce-justify-center rce-rounded-lg rce-size-12 rce-border rce-border-gray-200 rce-bg-gray-100"></div>
                        </div>

                        <!-- Cache Size Skeleton -->
                        <div
                            class="rce-flex rce-items-center rce-justify-between rce-py-6 rce-px-7">
                            <div class="rce-flex rce-flex-col rce-gap-2">
                                <div class="skeleton rce-h-3.5 rce-w-20"></div>
                                <div class="skeleton rce-h-5 rce-w-12"></div>
                            </div>
                            <div
                                class="rce-flex rce-items-center rce-justify-center rce-rounded-lg rce-size-12 rce-border rce-border-gray-200 rce-bg-gray-100"></div>
                        </div>

                        <!-- Compression Ratio Skeleton -->
                        <div
                            class="rce-flex rce-items-center rce-justify-between rce-py-6 rce-px-7">
                            <div class="rce-flex rce-flex-col rce-gap-2">
                                <div class="skeleton rce-h-3.5 rce-w-28"></div>
                                <div class="skeleton rce-h-5 rce-w-16"></div>
                            </div>
                            <div
                                class="rce-flex rce-items-center rce-justify-center rce-rounded-lg rce-size-12 rce-border rce-border-gray-200 rce-bg-gray-100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Cache Configuration Skeleton (Tabbed Interface) -->
        <div class="rce-w-full">
            <!-- Tab Navigation Skeleton -->
            <div class="rce-border rce-rounded-t-xl rce-overflow-hidden">
                <nav class="rce-flex rce-w-full rce-divide-x rce-divide-gray-200" aria-label="Tabs">
                    <!-- Full Page Cache Tab -->
                    <div
                        class="rce-flex-1 rce-h-20 rce-px-4 rce-flex rce-flex-row rce-items-center rce-justify-center rce-gap-3 rce-bg-white rce-shadow-xl">
                        <div class="skeleton rce-size-6 rce-rounded"></div>
                        <div class="skeleton rce-h-3.5 rce-w-28"></div>
                    </div>

                    <!-- Assets CDN Tab -->
                    <div
                        class="rce-flex-1 rce-h-20 rce-px-4 rce-flex rce-flex-row rce-items-center rce-justify-center rce-gap-3 rce-bg-gray-50">
                        <div class="skeleton rce-size-6 rce-rounded"></div>
                        <div class="skeleton rce-h-3.5 rce-w-20"></div>
                    </div>

                    <!-- CDN Edge Cache and Object Cache Tabs -->
                    <div
                        class="rce-flex-1 rce-h-20 rce-px-4 rce-flex rce-flex-row rce-items-center rce-justify-center rce-gap-3 rce-bg-gray-50">
                        <div class="skeleton rce-size-6 rce-rounded"></div>
                        <div class="skeleton rce-h-3.5 rce-w-24"></div>
                    </div>
                </nav>
            </div>

            <!-- Tab Content Skeleton -->
            <div
                class="rce-bg-white rce-border rce-border-t-0 rce-border-gray-200 rce-rounded-b-xl rce-pt-2">
                <div>
                    <div class="rce-p-6">
                        <div class="rce-flex rce-items-center rce-justify-between rce-mb-6">
                            <div class="skeleton rce-h-6 rce-w-32"></div>
                            <div class="rce-flex rce-items-center rce-space-x-2">
                                <div class="skeleton rce-h-8 rce-w-24 rce-rounded"></div>
                                <div class="skeleton rce-h-6 rce-w-28 rce-rounded-full"></div>
                            </div>
                        </div>

                        <div class="rce-space-y-7">
                            <!-- Toggle Switch Skeleton -->
                            <div class="rce-flex rce-items-center rce-justify-between">
                                <div class="rce-flex rce-items-center rce-space-x-4">
                                    <div class="skeleton rce-h-5 rce-w-9 rce-rounded-full"></div>
                                    <div class="rce-space-y-0.5">
                                        <div class="skeleton rce-h-4 rce-w-40"></div>
                                        <div class="skeleton rce-h-4 rce-w-64"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Content Selection Skeleton -->
                            <div class="rce-space-y-6">
                                <div class="rce-space-y-0.5 rce-mb-4">
                                    <div class="skeleton rce-h-4 rce-w-24"></div>
                                    <div class="skeleton rce-h-3 rce-w-48"></div>
                                </div>
                                <div class="rce-flex rce-items-start rce-space-x-3">
                                    <div class="skeleton rce-h-10 rce-flex-1 rce-rounded"></div>
                                    <div class="skeleton rce-h-10 rce-w-20 rce-rounded"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
// Self-contained skeleton component for Dashboard loading state
</script>

<style scoped>
/* Skeleton Loading Animation */
@keyframes skeleton-loading {
    0% {
        background-position: -200px 0;
    }
    100% {
        background-position: calc(200px + 100%) 0;
    }
}

.skeleton {
    background-color: #f3f4f6;
    background-image: linear-gradient(90deg, #f3f4f6, #e5e7eb, #f3f4f6);
    background-size: 200px 100%;
    background-repeat: no-repeat;
    border-radius: 0.375rem;
    animation: skeleton-loading 1.2s ease-in-out infinite;
}

/* Respect reduced motion preference */
@media (prefers-reduced-motion: reduce) {
    .skeleton {
        animation: none;
        background-color: #f3f4f6;
    }

    @media (prefers-color-scheme: dark) {
        .skeleton {
            background-color: #374151;
        }
    }
}
</style>
