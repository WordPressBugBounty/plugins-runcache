export const PAGE_CACHE_CONFIG = {
    php: {
        value: 'php',
        label: 'PHP Cache',
        description:
            'Basic caching solution that stores cached pages in PHP files. Suitable for small to medium websites with moderate traffic.',
    },
    'runcloud-native': {
        value: 'runcloud-native',
        label: 'RunCloud NGINX FastCGI Cache',
        description:
            'Caches pages directly in NGINX FastCGI Cache for optimal performance, bypassing PHP and WordPress for cached requests. Automatically configured by RunCloud.',
    },
    'runcloud-srcache': {
        value: 'runcloud-srcache',
        label: 'RunCloud NGINX SrCache Cache',
        description:
            'Caches pages directly in NGINX SRCache using Redis for optimal performance, bypassing PHP and WordPress for cached requests. Automatically configured by RunCloud.',
    },
    nginx: {
        value: 'nginx',
        label: 'Nginx Page Cache',
        description:
            'Caches pages as static HTML and serves them directly from NGINX, bypassing PHP and WordPress for cached requests.',
    },
    fastcgi: {
        value: 'fastcgi',
        label: 'Nginx FastCGI Cache',
        description:
            'Caches pages directly in NGINX FastCGI Cache for optimal performance, bypassing PHP and WordPress for cached requests.',
    },
    apache: {
        value: 'apache',
        label: 'Apache Page Cache',
        description:
            'Caches pages and serves them directly from the .htaccess, bypassing PHP and WordPress.',
    },
    litespeed: {
        value: 'litespeed',
        label: 'LiteSpeed Page Cache',
        description:
            'Caches pages using LiteSpeed LSCache and serves them directly from the web server, bypassing PHP and WordPress.',
    },
    redis: {
        value: 'redis',
        label: 'Redis Page Cache',
        description:
            'Stores cached pages in Redis and serves them through PHP, providing fast in-memory page caching with minimal server configuration.',
    },
}

export const ASSET_CDN_CONFIG = {
    bunnycdn: {
        label: 'BunnyCDN',
        description: "Accelerate your media files delivery using BunnyCDN's global network.",
    },
    custom: {
        label: 'Custom CDN',
        description: 'Bring your own CDN hostname and let RunCache rewrite asset URLs to it.',
    },
}

export const EDGE_CACHE_CONFIG = {
    cloudflare: {
        label: 'Cloudflare Page Cache',
        description:
            "Caches and serves pages from Cloudflare's global edge network using Cache Rules. Available on all Cloudflare plans, including Free.",
    },
    'cloudflare-apo': {
        label: 'Cloudflare APO',
        description:
            "Caches and serves pages from Cloudflare's global edge network using APO. Requires an active APO subscription for the domain.",
    },
}
