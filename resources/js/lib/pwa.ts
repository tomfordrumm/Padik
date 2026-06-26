const serviceWorkerPath = '/sw.js';

export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    if (import.meta.env.PROD) {
        window.addEventListener('load', () => {
            void navigator.serviceWorker
                .register(serviceWorkerPath)
                .catch(() => undefined);
        });

        return;
    }

    void unregisterLocalServiceWorker().catch(() => undefined);
}

async function unregisterLocalServiceWorker(): Promise<void> {
    const registration = await navigator.serviceWorker.getRegistration('/');

    if (registration?.active?.scriptURL.endsWith(serviceWorkerPath)) {
        await registration.unregister();
    }
}
