import { onBeforeUnmount, watch } from 'vue';
import type { Ref } from 'vue';
import type { CurrentRoom } from '@/composables/useMessengerStore';
import {
    destroy as destroyPushPresence,
    store as storePushPresence,
} from '@/routes/push-presence';

const heartbeatMs = 30000;

const csrfToken = (): string | null =>
    document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? null;

const xsrfToken = (): string | null => {
    const tokenCookie = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    return tokenCookie ? decodeURIComponent(tokenCookie.split('=')[1]) : null;
};

const sendPresence = (
    method: 'DELETE' | 'POST',
    conversationId: number,
): void => {
    const token = csrfToken();
    const xsrf = xsrfToken();

    void fetch(
        method === 'POST' ? storePushPresence.url() : destroyPushPresence.url(),
        {
            method,
            credentials: 'same-origin',
            keepalive: method === 'DELETE',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                ...(!token && xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
            },
            body: JSON.stringify({ conversation_id: conversationId }),
        },
    ).catch(() => {
        // Presence is best-effort and must not interrupt chat usage.
    });
};

export function useActiveConversationPresence(
    currentRoom: Ref<CurrentRoom | undefined>,
): void {
    let interval: number | undefined;
    let activeConversationId: number | undefined;

    const stop = (): void => {
        if (interval) {
            window.clearInterval(interval);
            interval = undefined;
        }

        if (activeConversationId) {
            sendPresence('DELETE', activeConversationId);
            activeConversationId = undefined;
        }
    };

    const start = (conversationId: number): void => {
        stop();

        if (document.visibilityState !== 'visible') {
            return;
        }

        activeConversationId = conversationId;
        sendPresence('POST', conversationId);
        interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                sendPresence('POST', conversationId);
            }
        }, heartbeatMs);
    };

    const handleVisibilityChange = (): void => {
        const room = currentRoom.value;

        if (document.visibilityState === 'visible' && room) {
            start(room.id);

            return;
        }

        stop();
    };

    watch(
        currentRoom,
        (room) => {
            if (!room) {
                stop();

                return;
            }

            start(room.id);
        },
        { immediate: true },
    );

    document.addEventListener('visibilitychange', handleVisibilityChange);

    onBeforeUnmount(() => {
        document.removeEventListener(
            'visibilitychange',
            handleVisibilityChange,
        );
        stop();
    });
}
