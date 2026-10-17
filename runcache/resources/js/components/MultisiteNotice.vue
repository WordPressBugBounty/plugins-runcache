<template>
    <Alert variant="info" class="rce-mb-6">
        <Info class="rce-size-4" :stroke-width="2" />
        <AlertTitle>Recommended: Multisite Setup</AlertTitle>
        <AlertDescription>
            To use server-level caching, we recommend using WordPress Multisite with subdomains.
        </AlertDescription>
    </Alert>
</template>

<script setup>
import { Info } from 'lucide-vue-next'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
</script>
