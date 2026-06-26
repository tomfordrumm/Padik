<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Bell,
    BellOff,
    CheckCircle2,
    ExternalLink,
    LoaderCircle,
    ShieldAlert,
    Smartphone,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { usePushNotifications } from '@/composables/usePushNotifications';
import type { PushNotificationStatus } from '@/composables/usePushNotifications';
import { edit } from '@/routes/settings/notifications';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: edit(),
            },
        ],
    },
});

const push = usePushNotifications();

const stateCopy: Record<
    PushNotificationStatus,
    { title: string; description: string }
> = {
    unsupported: {
        title: 'Push is not available',
        description:
            'This browser, device, or context does not support web push notifications.',
    },
    default: {
        title: 'Permission is not set',
        description:
            'Enable notifications for this device to receive private message alerts.',
    },
    enabled: {
        title: 'Push is enabled',
        description:
            'This device is subscribed and can receive private OS notifications.',
    },
    disabled: {
        title: 'Push is disabled',
        description:
            'Browser permission is allowed, but this device is not currently subscribed.',
    },
    denied: {
        title: 'Permission is blocked',
        description:
            'Notifications are blocked in the browser or operating system settings.',
    },
    loading: {
        title: 'Updating notification status',
        description: 'Padik is checking or changing this device subscription.',
    },
    error: {
        title: 'Notification setup needs attention',
        description:
            'The last notification request could not be completed on this device.',
    },
};

const statusLabel = computed(() => {
    switch (push.status.value) {
        case 'default':
            return 'Not requested';
        case 'enabled':
            return 'Enabled';
        case 'disabled':
            return 'Disabled';
        case 'denied':
            return 'Blocked';
        case 'loading':
            return 'Loading';
        case 'error':
            return 'Error';
        default:
            return 'Unsupported';
    }
});

const statusIcon = computed(() => {
    switch (push.status.value) {
        case 'enabled':
            return CheckCircle2;
        case 'denied':
        case 'error':
            return XCircle;
        case 'loading':
            return LoaderCircle;
        case 'disabled':
            return BellOff;
        case 'default':
            return Bell;
        default:
            return ShieldAlert;
    }
});

const statusClasses = computed(() => {
    switch (push.status.value) {
        case 'enabled':
            return 'border-emerald-200 bg-emerald-50 text-emerald-800';
        case 'denied':
        case 'error':
            return 'border-red-200 bg-red-50 text-red-800';
        case 'loading':
            return 'border-sky-200 bg-sky-50 text-sky-800';
        default:
            return 'border-border bg-muted text-foreground';
    }
});

const showEnableAction = computed(() =>
    ['default', 'disabled', 'error'].includes(push.status.value),
);
const showDisableAction = computed(() =>
    ['enabled', 'error'].includes(push.status.value),
);

onMounted(() => {
    void push.refresh();
});
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Notifications"
            description="Manage push notifications for this browser and device"
        />

        <section class="space-y-4">
            <div
                class="rounded-lg border p-4"
                :class="statusClasses"
                aria-live="polite"
            >
                <div class="flex items-start gap-3">
                    <component
                        :is="statusIcon"
                        class="mt-0.5 size-5 shrink-0"
                        :class="{
                            'animate-spin': push.status.value === 'loading',
                        }"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium">
                                {{ stateCopy[push.status.value].title }}
                            </p>
                            <span
                                class="rounded-full border border-current/20 px-2 py-0.5 text-xs font-medium"
                            >
                                {{ statusLabel }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm opacity-80">
                            {{ stateCopy[push.status.value].description }}
                        </p>
                        <p
                            v-if="push.errorMessage.value"
                            class="mt-2 text-sm font-medium"
                        >
                            {{ push.errorMessage.value }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <Button
                    v-if="showEnableAction"
                    type="button"
                    :disabled="push.status.value === 'loading'"
                    @click="push.enable"
                >
                    <Bell class="size-4" />
                    Enable push notifications
                </Button>

                <Button
                    v-if="showDisableAction"
                    type="button"
                    variant="outline"
                    :disabled="push.status.value === 'loading'"
                    @click="push.disable"
                >
                    <BellOff class="size-4" />
                    Disable push notifications
                </Button>
            </div>
        </section>

        <section class="space-y-3 text-sm text-muted-foreground">
            <div class="rounded-lg border border-border p-4">
                <div class="flex gap-3">
                    <Smartphone
                        class="mt-0.5 size-5 shrink-0 text-foreground"
                    />
                    <div class="space-y-1">
                        <p class="font-medium text-foreground">
                            Current device only
                        </p>
                        <p>
                            Enabling or disabling push here affects this browser
                            subscription. Other devices remain unchanged.
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="push.isIos.value && !push.isStandalone.value"
                class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900"
            >
                <div class="flex gap-3">
                    <ExternalLink class="mt-0.5 size-5 shrink-0" />
                    <div class="space-y-1">
                        <p class="font-medium">Install Padik on iOS first</p>
                        <p>
                            In Safari, share this page and choose Add to Home
                            Screen. Open Padik from the Home Screen app before
                            enabling notifications.
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="push.status.value === 'denied'"
                class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-900"
            >
                Notifications are blocked. Re-enable them in browser or OS
                settings, then return here to subscribe this device.
            </div>
        </section>
    </div>
</template>
