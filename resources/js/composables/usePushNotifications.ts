import { computed, readonly, ref } from 'vue';
import {
    destroy as destroyPushSubscription,
    key as pushSubscriptionKey,
    store as storePushSubscription,
} from '@/routes/push-subscriptions';

type PushNotificationStatus =
    | 'unsupported'
    | 'default'
    | 'enabled'
    | 'disabled'
    | 'denied'
    | 'loading'
    | 'error';

type PushSubscriptionKeyMap = {
    auth?: string;
    p256dh?: string;
};

type PushSubscriptionPayload = {
    content_encoding: 'aes128gcm';
    endpoint: string;
    expiration_time: string | null;
    keys: Required<PushSubscriptionKeyMap>;
    user_agent: string;
};

type VapidKeyResponse = {
    public_key?: string;
};

const storedEndpointKey = 'padik.push.endpoint';
const requestTimeoutMs = 15000;

const permission = ref<NotificationPermission>('default');
const hasSubscription = ref(false);
const isLoading = ref(false);
const errorMessage = ref<string | null>(null);
const initialized = ref(false);

const isBrowser = typeof window !== 'undefined';

const isStandalone = computed(() => {
    if (!isBrowser) {
        return false;
    }

    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (window.navigator as Navigator & { standalone?: boolean })
            .standalone === true
    );
});

const isIos = computed(() => {
    if (!isBrowser) {
        return false;
    }

    const navigator = window.navigator as Navigator & {
        userAgentData?: { platform?: string };
    };
    const platform =
        navigator.userAgentData?.platform ?? navigator.platform ?? '';

    return (
        /iPad|iPhone|iPod/.test(platform) ||
        (platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
});

const isSupported = computed(() => {
    if (!isBrowser) {
        return false;
    }

    const hasPushApis =
        'Notification' in window &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        window.isSecureContext;

    return hasPushApis && (!isIos.value || isStandalone.value);
});

const status = computed<PushNotificationStatus>(() => {
    if (isLoading.value) {
        return 'loading';
    }

    if (errorMessage.value) {
        return 'error';
    }

    if (!isSupported.value) {
        return 'unsupported';
    }

    if (permission.value === 'denied') {
        return 'denied';
    }

    if (permission.value === 'default') {
        return 'default';
    }

    return hasSubscription.value ? 'enabled' : 'disabled';
});

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

const requestJson = async <T>(
    url: string,
    init: RequestInit = {},
): Promise<T> => {
    const controller = new AbortController();
    const timeout = window.setTimeout(
        () => controller.abort(),
        requestTimeoutMs,
    );
    const token = csrfToken();

    try {
        const response = await fetch(url, {
            ...init,
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                ...(init.body ? { 'Content-Type': 'application/json' } : {}),
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                ...(!token && xsrfToken()
                    ? { 'X-XSRF-TOKEN': xsrfToken() as string }
                    : {}),
                ...init.headers,
            },
        });

        if (!response.ok) {
            throw new Error(`Request failed with ${response.status}.`);
        }

        if (response.status === 204) {
            return undefined as T;
        }

        return (await response.json()) as T;
    } finally {
        window.clearTimeout(timeout);
    }
};

const urlBase64ToArrayBuffer = (value: string): ArrayBuffer => {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = `${value}${padding}`.replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let index = 0; index < rawData.length; index += 1) {
        outputArray[index] = rawData.charCodeAt(index);
    }

    return outputArray.buffer as ArrayBuffer;
};

const getRegistration = async (): Promise<ServiceWorkerRegistration> => {
    const existingRegistration =
        await navigator.serviceWorker.getRegistration();

    if (existingRegistration) {
        return existingRegistration;
    }

    return Promise.race([
        navigator.serviceWorker.ready,
        new Promise<ServiceWorkerRegistration>((_, reject) => {
            window.setTimeout(
                () => reject(new Error('The service worker is not ready yet.')),
                requestTimeoutMs,
            );
        }),
    ]);
};

const rememberEndpoint = (endpoint: string | null): void => {
    if (!isBrowser) {
        return;
    }

    if (endpoint) {
        window.localStorage.setItem(storedEndpointKey, endpoint);
    } else {
        window.localStorage.removeItem(storedEndpointKey);
    }
};

const rememberedEndpoint = (): string | null => {
    if (!isBrowser) {
        return null;
    }

    return window.localStorage.getItem(storedEndpointKey);
};

const toPayload = (subscription: PushSubscription): PushSubscriptionPayload => {
    const json = subscription.toJSON() as {
        endpoint?: string;
        expirationTime?: number | null;
        keys?: PushSubscriptionKeyMap;
    };

    if (!json.keys?.p256dh || !json.keys.auth) {
        throw new Error('The browser did not return push subscription keys.');
    }

    return {
        content_encoding: 'aes128gcm',
        endpoint: subscription.endpoint,
        expiration_time: json.expirationTime
            ? new Date(json.expirationTime).toISOString()
            : null,
        keys: {
            p256dh: json.keys.p256dh,
            auth: json.keys.auth,
        },
        user_agent: window.navigator.userAgent,
    };
};

const refresh = async (): Promise<void> => {
    if (!isBrowser) {
        return;
    }

    errorMessage.value = null;
    permission.value =
        'Notification' in window ? Notification.permission : 'default';

    if (!isSupported.value || permission.value !== 'granted') {
        hasSubscription.value = false;
        initialized.value = true;

        return;
    }

    try {
        const registration = await getRegistration();
        const subscription = await registration.pushManager.getSubscription();

        hasSubscription.value = Boolean(subscription);
        rememberEndpoint(subscription?.endpoint ?? null);
    } catch {
        hasSubscription.value = false;
    } finally {
        initialized.value = true;
    }
};

const enable = async (): Promise<void> => {
    if (!isSupported.value) {
        errorMessage.value = 'Push notifications are not available here.';

        return;
    }

    isLoading.value = true;
    errorMessage.value = null;

    try {
        if (Notification.permission === 'default') {
            permission.value = await Notification.requestPermission();
        } else {
            permission.value = Notification.permission;
        }

        if (permission.value === 'denied') {
            return;
        }

        const [{ public_key: publicKey }, registration] = await Promise.all([
            requestJson<VapidKeyResponse>(pushSubscriptionKey.url()),
            getRegistration(),
        ]);

        if (!publicKey) {
            throw new Error('The push public key is not configured.');
        }

        const subscription =
            (await registration.pushManager.getSubscription()) ??
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToArrayBuffer(publicKey),
            }));

        await requestJson<{ enabled: boolean }>(storePushSubscription.url(), {
            method: 'POST',
            body: JSON.stringify(toPayload(subscription)),
        });

        hasSubscription.value = true;
        rememberEndpoint(subscription.endpoint);
    } catch (error) {
        errorMessage.value =
            error instanceof Error
                ? error.message
                : 'Unable to enable push notifications.';
    } finally {
        isLoading.value = false;
    }
};

const disable = async (): Promise<void> => {
    if (!isSupported.value && !rememberedEndpoint()) {
        return;
    }

    isLoading.value = true;
    errorMessage.value = null;

    try {
        const registration = isSupported.value
            ? await getRegistration()
            : undefined;
        const subscription = await registration?.pushManager.getSubscription();
        const endpoint = subscription?.endpoint ?? rememberedEndpoint();

        if (subscription) {
            await subscription.unsubscribe();
        }

        if (endpoint) {
            await requestJson<{ enabled: boolean }>(
                destroyPushSubscription.url(),
                {
                    method: 'DELETE',
                    body: JSON.stringify({ endpoint }),
                },
            );
        }

        hasSubscription.value = false;
        rememberEndpoint(null);
        permission.value =
            'Notification' in window ? Notification.permission : 'default';
    } catch (error) {
        errorMessage.value =
            error instanceof Error
                ? error.message
                : 'Unable to disable push notifications.';
    } finally {
        isLoading.value = false;
    }
};

export function usePushNotifications() {
    if (!initialized.value && isBrowser) {
        void refresh();
    }

    return {
        disable,
        enable,
        errorMessage: readonly(errorMessage),
        hasSubscription: readonly(hasSubscription),
        initialized: readonly(initialized),
        isIos: readonly(isIos),
        isLoading: readonly(isLoading),
        isStandalone: readonly(isStandalone),
        isSupported: readonly(isSupported),
        permission: readonly(permission),
        refresh,
        status: readonly(status),
    };
}

export type { PushNotificationStatus };
