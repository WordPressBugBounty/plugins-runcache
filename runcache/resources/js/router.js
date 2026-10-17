import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
    {
        path: '/',
        redirect: '/dashboard',
    },
    {
        path: '/dashboard',
        name: 'Dashboard',
        component: () => import('@/pages/Dashboard.vue'),
        meta: {
            icon: 'dashboard',
        },
    },
    {
        path: '/purger',
        name: 'Purger',
        component: () => import('@/pages/Purger.vue'),
        meta: {
            icon: 'purger',
        },
    },
    {
        path: '/preloader',
        name: 'Preloader',
        component: () => import('@/pages/Preloader.vue'),
        meta: {
            icon: 'preloader',
        },
    },
    {
        path: '/rules',
        name: 'Rules',
        component: () => import('@/pages/Rules.vue'),
        meta: {
            icon: 'rules',
        },
    },
    {
        path: '/import-export',
        name: 'Import/Export',
        component: () => import('@/pages/ImportExport.vue'),
        meta: {
            icon: 'importExport',
        },
    },
]

const router = createRouter({
    history: createWebHashHistory(),
    routes,
})

export default router
