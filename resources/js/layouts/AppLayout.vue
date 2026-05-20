<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, LogOut, Menu, Search, User, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import { dashboard, logout } from '@/routes';
import { show as showDirectMessage } from '@/routes/direct-messages';
import { read as readNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { show as showRoom } from '@/routes/rooms';
import type {
    Auth,
    DirectMessageUserNavItem,
    NotificationItem,
    NotificationNav,
    RoomNavItem,
} from '@/types';

type ChatPreview = {
    id: number;
    slug: string;
    name: string;
    initials: string;
    color: string;
    time: string;
    preview: string;
    active?: boolean;
};

const page = usePage();
const currentUserId = Number((page.props.auth as Auth).user.id);
const activeTab = ref<'rooms' | 'dms'>(
    page.url.startsWith('/dms/') ? 'dms' : 'rooms',
);
const areNotificationsOpen = ref(false);

const roomColors = ['bg-[#007681]', 'bg-[#3f6b73]', 'bg-[#966000]'];

const initials = (title: string) =>
    title
        .split(/[\s-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');

const chats = computed<ChatPreview[]>(() =>
    (page.props.rooms as RoomNavItem[]).map((room, index) => ({
        id: room.id,
        slug: room.slug,
        name: room.title,
        initials: initials(room.title) || '#',
        color: roomColors[index % roomColors.length],
        time: room.unread_count > 0 ? `${room.unread_count} unread` : '',
        preview: room.last_message ?? 'No messages yet',
        active: page.url === showRoom.url(room.slug),
    })),
);

const directMessageUsers = computed<ChatPreview[]>(() =>
    (page.props.directMessageUsers as DirectMessageUserNavItem[]).map(
        (user, index) => ({
            id: user.id,
            slug: user.id.toString(),
            name: user.name,
            initials: initials(user.name) || user.name[0]?.toUpperCase() || '?',
            color: roomColors[index % roomColors.length],
            time: '',
            preview: user.last_message ?? 'Start a conversation',
            active: page.url === showDirectMessage.url(user.id),
        }),
    ),
);

const notifications = computed<NotificationNav>(
    () =>
        (page.props.notifications as NotificationNav) ?? {
            unread_count: 0,
            items: [],
        },
);

type BroadcastDirectMessageNotification = NotificationItem & {
    id?: string;
};

const isDrawerOpen = ref(false);

const closeDrawer = () => {
    isDrawerOpen.value = false;
};

const closeNotifications = () => {
    areNotificationsOpen.value = false;
};

const markNotificationsAsRead = () => {
    router.post(
        readNotifications.url(),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                page.props.notifications = {
                    ...notifications.value,
                    unread_count: 0,
                    items: notifications.value.items.map((notification) => ({
                        ...notification,
                        read_at: notification.read_at ?? new Date().toISOString(),
                    })),
                };
            },
        },
    );
};

const appendNotification = (notification: BroadcastDirectMessageNotification) => {
    const notificationId = notification.id ?? crypto.randomUUID();
    const currentNotifications = notifications.value;

    if (
        currentNotifications.items.some(
            (currentNotification) => currentNotification.id === notificationId,
        )
    ) {
        return;
    }

    page.props.notifications = {
        unread_count: currentNotifications.unread_count + 1,
        items: [
            {
                id: notificationId,
                type: notification.type,
                title: notification.title,
                body: notification.body,
                sender_name: notification.sender_name,
                read_at: null,
                created_at: notification.created_at,
                created_at_human: notification.created_at_human,
            },
            ...currentNotifications.items,
        ].slice(0, 20),
    };
};

const handleEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeDrawer();
        closeNotifications();
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleEscape);
    window.Echo.private(`App.Models.User.${currentUserId}`).notification(
        appendNotification,
    );
});

watch(
    () => page.url,
    (url) => {
        if (url.startsWith('/dms/')) {
            activeTab.value = 'dms';
        }
    },
);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleEscape);
    window.Echo.leave(`App.Models.User.${currentUserId}`);
});
</script>

<template>
    <div
        class="flex h-dvh w-full overflow-hidden bg-[#f5fafb] font-sans text-[#171d1e]"
    >
        <aside
            class="relative z-40 flex w-full max-w-[21rem] shrink-0 flex-col border-r border-[#bbc9cb] bg-white sm:max-w-96"
        >
            <div class="space-y-4 border-b border-[#bbc9cb] p-4">
                <div class="flex items-center gap-3">
                    <button
                        class="grid size-10 place-items-center rounded-full text-[#171d1e] transition-colors hover:bg-[#e4e9ea]"
                        aria-label="Open menu"
                        :aria-expanded="isDrawerOpen"
                        aria-controls="main-menu-drawer"
                        @click="isDrawerOpen = true"
                    >
                        <Menu class="size-6" />
                    </button>

                    <Link
                        :href="dashboard()"
                        class="text-xl font-bold tracking-tight text-[#007681]"
                    >
                        Padik
                    </Link>

                    <div class="relative ml-auto">
                        <button
                            class="relative grid size-10 place-items-center rounded-full text-[#171d1e] transition-colors hover:bg-[#e4e9ea]"
                            aria-label="Open notifications"
                            :aria-expanded="areNotificationsOpen"
                            aria-controls="notifications-menu"
                            @click="areNotificationsOpen = !areNotificationsOpen"
                        >
                            <Bell class="size-5" />
                            <span
                                v-if="notifications.unread_count > 0"
                                class="absolute top-1 right-1 grid min-w-4 place-items-center rounded-full bg-[#ba1a1a] px-1 text-[10px] leading-4 font-bold text-white"
                            >
                                {{ notifications.unread_count > 9 ? '9+' : notifications.unread_count }}
                            </span>
                        </button>

                        <div
                            v-if="areNotificationsOpen"
                            id="notifications-menu"
                            class="absolute top-12 right-0 z-50 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-[#bbc9cb] bg-white shadow-xl"
                        >
                            <div class="flex items-center justify-between gap-3 border-b border-[#bbc9cb]/60 px-4 py-3">
                                <span class="text-sm font-bold text-[#171d1e]">
                                    Notifications
                                </span>
                                <button
                                    class="text-xs font-bold text-[#007681] transition-colors hover:text-[#006874] disabled:text-[#8a989b]"
                                    :disabled="notifications.unread_count === 0"
                                    @click="markNotificationsAsRead"
                                >
                                    Mark all as read
                                </button>
                            </div>

                            <div class="chat-scroll max-h-96 overflow-y-auto py-1">
                                <div
                                    v-for="notification in notifications.items"
                                    :key="notification.id"
                                    class="border-b border-[#bbc9cb]/30 px-4 py-3 last:border-b-0"
                                    :class="notification.read_at ? 'bg-white' : 'bg-[#006874]/5'"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-bold text-[#171d1e]">
                                                {{ notification.sender_name ?? notification.title }}
                                            </span>
                                            <span class="mt-0.5 block line-clamp-2 text-xs leading-5 text-[#6c797c]">
                                                {{ notification.body ?? 'Sent you a direct message.' }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="!notification.read_at"
                                            class="mt-1 size-2 shrink-0 rounded-full bg-[#007681]"
                                            aria-label="Unread"
                                        />
                                    </div>
                                    <span class="mt-2 block text-[11px] text-[#8a989b]">
                                        {{ notification.created_at_human }}
                                    </span>
                                </div>

                                <p
                                    v-if="notifications.items.length === 0"
                                    class="px-4 py-8 text-center text-sm text-[#6c797c]"
                                >
                                    No notifications yet
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <label class="relative block">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#6c797c]"
                    />
                    <input
                        class="h-12 w-full rounded-full border-0 bg-[#eff5f5] pr-4 pl-12 text-base text-[#171d1e] placeholder:text-[#718083] focus:ring-2 focus:ring-[#006874]/20 focus:outline-none"
                        placeholder="Search"
                        type="search"
                    />
                </label>
            </div>

            <div class="flex gap-1 border-b border-[#bbc9cb] px-2 pt-2">
                <button
                    class="flex-1 border-b-2 py-3 text-sm font-bold transition-colors"
                    :class="
                        activeTab === 'rooms'
                            ? 'border-[#007681] text-[#007681]'
                            : 'border-transparent text-[#6c797c] hover:bg-[#eff5f5]'
                    "
                    @click="activeTab = 'rooms'"
                >
                    Rooms
                </button>
                <button
                    class="flex-1 border-b-2 py-3 text-sm font-bold transition-colors"
                    :class="
                        activeTab === 'dms'
                            ? 'border-[#007681] text-[#007681]'
                            : 'border-transparent text-[#6c797c] hover:bg-[#eff5f5]'
                    "
                    @click="activeTab = 'dms'"
                >
                    DMs
                </button>
            </div>

            <div v-if="activeTab === 'rooms'" class="chat-scroll flex-1 overflow-y-auto py-2">
                <Link
                    v-for="chat in chats"
                    :key="chat.id"
                    :href="showRoom(chat.slug)"
                    class="flex w-full cursor-pointer gap-3 px-3 py-3 text-left transition-colors"
                    :class="
                        chat.active
                            ? 'border-l-4 border-[#007681] bg-[#006874]/5'
                            : 'border-l-4 border-transparent hover:bg-[#eff5f5]'
                    "
                >
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-full text-base font-bold text-white"
                        :class="chat.color"
                    >
                        {{ chat.initials }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span
                            class="mb-0.5 flex items-baseline justify-between gap-3"
                        >
                            <span class="truncate text-sm font-bold">
                                {{ chat.name }}
                            </span>
                            <span
                                v-if="chat.time"
                                class="shrink-0 text-[11px] text-[#6c797c]"
                            >
                                {{ chat.time }}
                            </span>
                        </span>
                        <span class="block truncate text-xs text-[#6c797c]">
                            {{ chat.preview }}
                        </span>
                    </span>
                </Link>
            </div>

            <div v-else class="chat-scroll flex-1 overflow-y-auto py-2">
                <Link
                    v-for="user in directMessageUsers"
                    :key="user.id"
                    :href="showDirectMessage(user.id)"
                    class="flex w-full cursor-pointer gap-3 px-3 py-3 text-left transition-colors"
                    :class="
                        user.active
                            ? 'border-l-4 border-[#007681] bg-[#006874]/5'
                            : 'border-l-4 border-transparent hover:bg-[#eff5f5]'
                    "
                >
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-full text-base font-bold text-white"
                        :class="user.color"
                    >
                        {{ user.initials }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-bold">
                            {{ user.name }}
                        </span>
                        <span class="block truncate text-xs text-[#6c797c]">
                            {{ user.preview }}
                        </span>
                    </span>
                </Link>

                <p
                    v-if="directMessageUsers.length === 0"
                    class="px-4 py-6 text-sm text-[#6c797c]"
                >
                    No users yet
                </p>
            </div>
        </aside>

        <main class="min-w-0 flex-1 overflow-hidden bg-white">
            <slot />
        </main>

        <Teleport to="body">
            <Transition
                enter-active-class="transition-opacity duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition-opacity duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <button
                    v-if="isDrawerOpen"
                    class="fixed inset-0 z-50 bg-[#171d1e]/35 text-left"
                    aria-label="Close menu"
                    @click="closeDrawer"
                />
            </Transition>

            <Transition
                enter-active-class="transition-transform duration-250 ease-out"
                enter-from-class="-translate-x-full"
                enter-to-class="translate-x-0"
                leave-active-class="transition-transform duration-200 ease-in"
                leave-from-class="translate-x-0"
                leave-to-class="-translate-x-full"
            >
                <aside
                    v-if="isDrawerOpen"
                    id="main-menu-drawer"
                    class="fixed top-0 bottom-0 left-0 z-50 flex w-80 max-w-[86vw] flex-col border-r border-[#bbc9cb] bg-white shadow-2xl"
                    aria-label="Main menu"
                >
                    <div
                        class="flex h-16 items-center justify-between border-b border-[#bbc9cb] px-5"
                    >
                        <span
                            class="text-xl font-bold tracking-tight text-[#007681]"
                        >
                            Padik
                        </span>

                        <button
                            class="grid size-10 place-items-center rounded-full text-[#3c494b] transition-colors hover:bg-[#eff5f5]"
                            aria-label="Close menu"
                            @click="closeDrawer"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <nav class="flex flex-1 flex-col gap-2 p-4">
                        <Link
                            :href="editProfile()"
                            class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-[#171d1e] transition-colors hover:bg-[#eff5f5]"
                            @click="closeDrawer"
                        >
                            <User class="size-5 text-[#007681]" />
                            Profile settings
                        </Link>

                        <Link
                            :href="logout()"
                            method="post"
                            as="button"
                            class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-left text-sm font-semibold text-[#ba1a1a] transition-colors hover:bg-[#ffdad6]/60"
                            @click="closeDrawer"
                        >
                            <LogOut class="size-5" />
                            Log out
                        </Link>
                    </nav>
                </aside>
            </Transition>
        </Teleport>

        <Toaster />
    </div>
</template>

<style scoped>
.chat-scroll::-webkit-scrollbar {
    width: 6px;
}

.chat-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.chat-scroll::-webkit-scrollbar-thumb {
    background: #dee3e4;
    border-radius: 999px;
}
</style>
