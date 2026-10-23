import { configureEcho } from '@laravel/echo-vue';

// The Reverb WebSocket endpoint defaults to the host that served the page: the
// app's reverse proxy exposes Reverb under /app on that same host, so this is
// correct for any deployment (localhost in dev, the public domain in prod)
// without an environment-specific build. Set VITE_REVERB_* at build time only
// to pin a fixed/dedicated Reverb host.
const loc = typeof window !== 'undefined' ? window.location : undefined;
const envHost = import.meta.env.VITE_REVERB_HOST || undefined;
const envPort = import.meta.env.VITE_REVERB_PORT || undefined;
const envScheme = import.meta.env.VITE_REVERB_SCHEME || undefined;

const scheme = envScheme ?? (loc?.protocol === 'https:' ? 'https' : 'http');
const host = envHost ?? loc?.hostname ?? 'localhost';
const port = Number(envPort ?? (scheme === 'https' ? 443 : 80));

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
