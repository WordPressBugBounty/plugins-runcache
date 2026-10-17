<template>
    <div class="rce-space-y-6">
        <!-- Header Skeleton -->
        <div class="rce-space-y-2">
            <div class="skeleton rce-h-8 rce-w-48"></div>
            <div class="skeleton rce-h-4 rce-w-96"></div>
        </div>

        <!-- Form Skeleton -->
        <form class="rce-space-y-7">
            <!-- Generate skeleton cards based on count -->
            <div
                v-for="i in cardCount"
                :key="i"
                class="rce-bg-white rce-border rce-border-gray-200 rce-rounded-xl">
                <div class="rce-p-6">
                    <!-- Card Title Skeleton -->
                    <div class="skeleton rce-h-6 rce-w-40 rce-mb-4"></div>

                    <!-- Card Content Skeleton -->
                    <div class="rce-space-y-4">
                        <!-- Generate skeleton items based on items per card -->
                        <div v-for="j in getItemsPerCard(i)" :key="j" class="rce-space-y-3">
                            <!-- Switch + Label Skeleton -->
                            <div class="rce-flex rce-items-center rce-space-x-3">
                                <div class="skeleton rce-h-5 rce-w-9 rce-rounded"></div>
                                <div
                                    class="skeleton rce-h-4"
                                    :style="{ width: getLabelWidth(j) }"></div>
                            </div>

                            <!-- Nested content skeleton (textarea, inputs, etc.) -->
                            <div
                                v-if="shouldShowNestedContent(i, j)"
                                class="rce-ml-12 rce-space-y-3">
                                <!-- Textarea skeleton -->
                                <div
                                    v-if="Math.random() > 0.5"
                                    class="skeleton rce-h-20 rce-w-full rce-rounded"></div>
                                <!-- Input skeleton -->
                                <div v-else class="skeleton rce-h-10 rce-w-full rce-rounded"></div>
                                <!-- Description text skeleton -->
                                <div
                                    class="skeleton rce-h-4"
                                    :style="{ width: getDescriptionWidth() }"></div>
                            </div>
                        </div>

                        <!-- Special nested items for certain cards -->
                        <div
                            v-if="i === 2 && j === getItemsPerCard(i)"
                            class="rce-ml-12 rce-space-y-4">
                            <div
                                v-for="k in 3"
                                :key="k"
                                class="rce-flex rce-items-center rce-space-x-3">
                                <div class="skeleton rce-h-5 rce-w-9 rce-rounded"></div>
                                <div
                                    class="skeleton rce-h-4"
                                    :style="{ width: getLabelWidth(k) }"></div>
                            </div>
                        </div>

                        <!-- Input + Select combo skeleton -->
                        <div v-if="shouldShowInputSelect(i)" class="rce-ml-12 rce-space-y-3">
                            <div
                                class="skeleton rce-h-4"
                                :style="{ width: getDescriptionWidth() }"></div>
                            <div class="rce-flex rce-items-center rce-space-x-2">
                                <div class="skeleton rce-h-10 rce-w-24 rce-rounded"></div>
                                <div class="skeleton rce-h-10 rce-w-32 rce-rounded"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    cardCount: {
        type: Number,
        default: 5,
    },
})

// Generate varying number of items per card for more realistic skeleton
const getItemsPerCard = (cardIndex) => {
    const itemsPerCard = [2, 9, 2, 5, 3, 2, 2] // Realistic item counts
    return itemsPerCard[cardIndex - 1] || 3
}

// Generate varying label widths for more realistic skeleton
const getLabelWidth = (itemIndex) => {
    const widths = ['w-32', 'w-40', 'w-48', 'w-56', 'w-64', 'w-72', 'w-80', 'w-88', 'w-96']
    return widths[itemIndex % widths.length]
}

// Generate varying description widths
const getDescriptionWidth = () => {
    const widths = ['w-64', 'w-72', 'w-80', 'w-88', 'w-96']
    return widths[Math.floor(Math.random() * widths.length)]
}

// Determine if nested content should be shown
const shouldShowNestedContent = (cardIndex, itemIndex) => {
    // Show nested content for certain card/item combinations
    const nestedCombinations = [
        { card: 1, items: [1] }, // URL Exclusion
        { card: 2, items: [1, 3] }, // Cookie Rules
        { card: 3, items: [1, 3] }, // Browser & IP Rules
        { card: 4, items: [1, 3, 5] }, // Query String Rules
        { card: 5, items: [1] }, // Additional Cache Key
        { card: 6, items: [1] }, // Preloader custom paths
    ]

    return nestedCombinations.some(
        (combo) => combo.card === cardIndex && combo.items.includes(itemIndex)
    )
}

// Determine if input+select combo should be shown
const shouldShowInputSelect = (cardIndex) => {
    return cardIndex === 5 || cardIndex === 6 // Scheduled purge cards
}
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
