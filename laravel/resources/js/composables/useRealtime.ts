import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Subscribe to a Reverb (Echo) channel for the lifetime of the component.
 *
 * Two helpers:
 *   - useUserChannel(handler)   — subscribes to private-user.{auth.user.id}
 *   - useChannel(name, events)  — subscribes to a named private channel
 *
 * Both unsubscribe on unmount; both gracefully no-op when window.Echo is
 * unavailable (e.g. SSR, or during tests where Echo isn't initialised).
 */
type EventHandler = (payload: Record<string, unknown>) => void;
type EventMap = Record<string, EventHandler>;

function getEcho(): any | null {
    if (typeof window === 'undefined') {
return null;
}

    return (window as any).Echo ?? null;
}

function getAuthUserId(): number | null {
    const auth = (usePage().props as { auth?: { user?: { id?: number } } }).auth;
    const id = auth?.user?.id;

    return typeof id === 'number' ? id : null;
}

export function useChannel(name: string, events: EventMap): void {
    let channel: any = null;

    const subscribe = () => {
        const echo = getEcho();

        if (!echo) {
return;
}

        channel = echo.private(name);

        for (const [event, handler] of Object.entries(events)) {
            channel.listen('.'+event, handler);
        }
    };

    const unsubscribe = () => {
        const echo = getEcho();

        if (!echo) {
return;
}

        try {
            echo.leave('private-'+name);
        } catch {
            // ignore — leave is best-effort
        }

        channel = null;
    };

    onMounted(subscribe);
    onBeforeUnmount(unsubscribe);
}

export function useUserChannel(events: EventMap): void {
    const userId = getAuthUserId();

    if (!userId) {
return;
}

    useChannel('user.'+userId, events);
}

export function usePageChannel(pageId: number, events: EventMap): void {
    useChannel('page.'+pageId, events);
}
