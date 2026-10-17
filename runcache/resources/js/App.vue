<template>
    <div id="runcache-admin" class="runcache-app rce-min-h-screen rce-overflow-x-hidden">
        <Toaster />

        <Navbar />

        <!-- Main content -->
        <main class="rce-h-full rce-overflow-y-auto">
            <div class="md:rce-max-w-7xl rce-mx-auto rce-pt-8 rce-pb-12 rce-px-4 md:rce-px-8">
                <router-view v-slot="{ Component, route }">
                    <component :is="Component" :key="route.path" />
                </router-view>
            </div>
        </main>
    </div>
</template>

<script setup>
import { Toaster } from '@/components/ui/sonner'
import 'vue-sonner/style.css' // vue-sonner v2 requires this import
import Navbar from '@/components/Navbar.vue'
</script>
