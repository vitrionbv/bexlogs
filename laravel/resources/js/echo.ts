import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

/**
 * Live-updates client. Reverb implements the Pusher protocol so we use the
 * 'reverb' broadcaster from laravel-echo. Auth happens against Laravel's
 * /broadcasting/auth endpoint via the user's existing session cookie.
 *
 * Channels we subscribe to from components:
 *   private-user.{userId}           — sidebar, Jobs, Logs index, Authenticate
 *   private-page.{pageId}           — Logs/Show (live log feed)
 *   private-job.{jobId}             — (rare) per-job dialogs
 *
 * NOTE: All `window` access lives inside this function so the module is
 * SSR-safe — Inertia's SSR pass evaluates this file in Node, where `window`
 * is undefined.
 */
export function initializeEcho(): void {
    if (typeof window === 'undefined') {
return;
}

    if (window.Echo) {
return;
}

    window.Pusher = Pusher;

    const csrfToken =
        document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY as string,
        wsHost: import.meta.env.VITE_REVERB_HOST as string,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        },
    });
}
