<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Check,
    LogOut,
    Menu,
    Pencil,
    Search,
    User,
    X,
} from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Toaster } from '@/components/ui/sonner';
import { Spinner } from '@/components/ui/spinner';
import { useMessengerStore } from '@/composables/useMessengerStore';
import type { MessagePayload } from '@/composables/useMessengerStore';
import { logout } from '@/routes';
import { show as showDirectMessage } from '@/routes/direct-messages';
import { read as readNotifications } from '@/routes/notifications';
import { read as readNotificationsFromSender } from '@/routes/notifications/from-sender';
import {
    accept as acceptInvitation,
    decline as declineInvitation,
} from '@/routes/notifications/invitations';
import { read as readNotification } from '@/routes/notifications/item';
import { edit as editProfile } from '@/routes/profile';
import { show as showRoom, store as storeRoom } from '@/routes/rooms';
import { store as storeRoomInvitation } from '@/routes/rooms/invitations';
import type {
    Auth,
    DirectMessageUserNavItem,
    NotificationItem,
    NotificationNav,
    RoomNavItem,
} from '@/types';

defineOptions({
    inheritAttrs: false,
});

type ChatPreview = {
    id: number;
    slug: string;
    name: string;
    initials: string;
    color: string;
    time: string;
    preview: string;
    unread_count: number;
    active?: boolean;
};

const page = usePage();
const currentUserId = Number((page.props.auth as Auth).user.id);
const messenger = useMessengerStore(currentUserId);
const activeTab = ref<'rooms' | 'dms'>(
    page.url.startsWith('/dms/') ? 'dms' : 'rooms',
);
const areNotificationsOpen = ref(false);
const isCreateRoomOpen = ref(false);
const roomTitleInput = ref<{ $el: HTMLInputElement } | null>(null);
const createRoomStep = ref<'details' | 'invite'>('details');
const createdRoom = ref<{ id: number; title: string; slug: string } | null>(
    null,
);
const notificationsMenu = ref<HTMLElement | null>(null);
const isChatListOpen = ref(true);
const isConversationLoading = ref(false);
const createRoomForm = useForm({
    title: '',
});
const inviteUsersForm = useForm<{ user_ids: number[] }>({
    user_ids: [],
});

const roomColors = ['bg-[#007681]', 'bg-[#3f6b73]', 'bg-[#966000]'];

const initials = (title: string) =>
    title
        .split(/[\s-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');

const chats = computed<ChatPreview[]>(() =>
    messenger.rooms.value.map((room, index) => ({
        id: room.id,
        slug: room.slug,
        name: room.title,
        initials: initials(room.title) || '#',
        color: roomColors[index % roomColors.length],
        time: room.unread_count > 0 ? `${room.unread_count} unread` : '',
        preview: room.last_message ?? 'No messages yet',
        unread_count: room.unread_count,
        active: page.url === showRoom.url(room.slug),
    })),
);

const directMessageUsers = computed<ChatPreview[]>(() =>
    messenger.directMessageUsers.value.map((user, index) => ({
        id: user.id,
        slug: user.id.toString(),
        name: user.name,
        initials: initials(user.name) || user.name[0]?.toUpperCase() || '?',
        color: roomColors[index % roomColors.length],
        time: user.unread_count > 0 ? `${user.unread_count} unread` : '',
        preview: user.last_message ?? 'Start a conversation',
        unread_count: user.unread_count,
        active: page.url === showDirectMessage.url(user.id),
    })),
);

const invitableUsers = computed(() =>
    directMessageUsers.value.filter((user) => user.id !== currentUserId),
);

const notifications = computed<NotificationNav>(
    () => messenger.notifications.value,
);

type BroadcastNotification = NotificationItem & {
    id?: string;
};

const isDrawerOpen = ref(false);

const closeDrawer = () => {
    isDrawerOpen.value = false;
};

const closeNotifications = () => {
    areNotificationsOpen.value = false;
};

const openChatList = () => {
    isChatListOpen.value = true;
};

const closeChatList = () => {
    isChatListOpen.value = false;
};

const stopConversationLoading = () => {
    isConversationLoading.value = false;
};

const openConversation = (isActive: boolean) => {
    if (isActive) {
        stopConversationLoading();
    } else {
        isConversationLoading.value = true;
    }

    closeChatList();
};

const openCreateRoom = () => {
    activeTab.value = 'rooms';
    createRoomStep.value = 'details';
    createdRoom.value = null;
    inviteUsersForm.reset();
    inviteUsersForm.clearErrors();
    isCreateRoomOpen.value = true;

    nextTick(() => roomTitleInput.value?.$el.focus());
};

const closeCreateRoom = () => {
    isCreateRoomOpen.value = false;
    createRoomStep.value = 'details';
    createdRoom.value = null;
    createRoomForm.reset();
    createRoomForm.clearErrors();
    inviteUsersForm.reset();
    inviteUsersForm.clearErrors();
};

const submitCreateRoom = () => {
    createRoomForm.post(storeRoom.url(), {
        onSuccess: (page) => {
            const currentRoom = page.props.currentRoom as
                | { id: number; title: string; slug: string }
                | undefined;

            if (currentRoom) {
                createdRoom.value = currentRoom;
            }

            createRoomForm.reset();
            createRoomStep.value = 'invite';
            isCreateRoomOpen.value = true;
        },
    });
};

const isUserSelectedForInvite = (userId: number): boolean =>
    inviteUsersForm.user_ids.includes(userId);

const toggleInviteUser = (userId: number) => {
    inviteUsersForm.user_ids = isUserSelectedForInvite(userId)
        ? inviteUsersForm.user_ids.filter(
              (selectedUserId) => selectedUserId !== userId,
          )
        : [...inviteUsersForm.user_ids, userId];
};

const submitRoomInvitations = () => {
    if (!createdRoom.value) {
        return;
    }

    inviteUsersForm.post(storeRoomInvitation.url(createdRoom.value.slug), {
        preserveScroll: true,
        onSuccess: closeCreateRoom,
    });
};

const markNotificationsAsRead = () => {
    router.post(
        readNotifications.url(),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                messenger.markNotificationsRead();
            },
        },
    );
};

const openNotification = (notification: NotificationItem) => {
    if (isInvitationNotification(notification)) {
        return;
    }

    const actionUrl = notification.action_url;

    if (!actionUrl) {
        return;
    }

    closeNotifications();

    if (isMentionNotification(notification)) {
        router.post(
            readNotification.url(notification.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    messenger.markNotificationRead(notification.id);

                    router.visit(actionUrl);
                },
            },
        );

        return;
    }

    if (!notification.sender_id) {
        router.visit(actionUrl);

        return;
    }

    const senderId = notification.sender_id;

    router.post(
        readNotificationsFromSender.url(senderId),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                messenger.markSenderNotificationsRead(senderId);

                router.visit(actionUrl);
            },
        },
    );
};

const isInvitationNotification = (notification: NotificationItem): boolean =>
    (notification.type.endsWith('RoomInvitationReceived') ||
        notification.type.endsWith('SecretChatInvitationReceived')) &&
    Boolean(notification.invitation_id);

const isMentionNotification = (notification: NotificationItem): boolean =>
    notification.type.endsWith('MentionReceived') &&
    Boolean(notification.message_id);

const acceptInvitationNotification = (notification: NotificationItem) => {
    router.post(
        acceptInvitation.url(notification.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => closeNotifications(),
        },
    );
};

const declineInvitationNotification = (notification: NotificationItem) => {
    router.post(
        declineInvitation.url(notification.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                messenger.markNotificationsRead();
            },
        },
    );
};

const appendNotification = (notification: BroadcastNotification) => {
    const notificationId = notification.id ?? crypto.randomUUID();
    const currentNotifications = notifications.value;

    if (
        currentNotifications.items.some(
            (currentNotification) => currentNotification.id === notificationId,
        )
    ) {
        return;
    }

    messenger.appendNotification({
        id: notificationId,
        type: notification.type,
        title: notification.title,
        body: notification.body,
        sender_id: notification.sender_id,
        sender_name: notification.sender_name,
        action_url: notification.action_url,
        invitation_id: notification.invitation_id,
        message_id: notification.message_id,
        conversation_id: notification.conversation_id,
        room_title: notification.room_title,
        read_at: null,
        created_at: notification.created_at,
        created_at_human: notification.created_at_human,
    });
};

const handleEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeChatList();
        closeDrawer();
        closeNotifications();
    }
};

const handleDocumentClick = (event: MouseEvent) => {
    if (!areNotificationsOpen.value) {
        return;
    }

    const target = event.target;

    if (!(target instanceof Node)) {
        return;
    }

    if (notificationsMenu.value?.contains(target)) {
        return;
    }

    closeNotifications();
};

let removeNavigationFinishListener: (() => void) | undefined;
let viewportResizeFrame: number | undefined;
let mobileShellMediaQuery: MediaQueryList | undefined;
let scrollResetFrame: number | undefined;

const inputSelector = 'input, textarea, select, [contenteditable="true"]';

const isMobileAppShellActive = (): boolean =>
    mobileShellMediaQuery?.matches === true;

const isFocusedInput = (): boolean =>
    document.activeElement instanceof HTMLElement &&
    document.activeElement.matches(inputSelector);

const resetWindowScroll = (): void => {
    if (!isMobileAppShellActive()) {
        return;
    }

    if (window.scrollX !== 0 || window.scrollY !== 0) {
        window.scrollTo(0, 0);
    }
};

const scheduleWindowScrollReset = (): void => {
    if (scrollResetFrame) {
        window.cancelAnimationFrame(scrollResetFrame);
    }

    scrollResetFrame = window.requestAnimationFrame(() => {
        scrollResetFrame = undefined;
        resetWindowScroll();
    });
};

const updateMobileAppViewportHeight = (): void => {
    if (!isMobileAppShellActive()) {
        document.documentElement.style.removeProperty('--app-viewport-height');
        document.documentElement.style.removeProperty('--app-viewport-width');
        document.documentElement.style.removeProperty(
            '--app-viewport-offset-left',
        );
        document.documentElement.style.removeProperty(
            '--app-viewport-offset-top',
        );

        return;
    }

    const viewport = window.visualViewport;
    const viewportHeight = viewport?.height ?? window.innerHeight;
    const viewportWidth = viewport?.width ?? window.innerWidth;
    const viewportOffsetLeft = viewport?.offsetLeft ?? 0;
    const viewportOffsetTop = viewport?.offsetTop ?? 0;

    document.documentElement.style.setProperty(
        '--app-viewport-height',
        `${Math.round(viewportHeight)}px`,
    );
    document.documentElement.style.setProperty(
        '--app-viewport-width',
        `${Math.round(viewportWidth)}px`,
    );
    document.documentElement.style.setProperty(
        '--app-viewport-offset-left',
        `${Math.round(viewportOffsetLeft)}px`,
    );
    document.documentElement.style.setProperty(
        '--app-viewport-offset-top',
        `${Math.round(viewportOffsetTop)}px`,
    );
    resetWindowScroll();
};

const scheduleMobileAppViewportHeightUpdate = (): void => {
    if (viewportResizeFrame) {
        window.cancelAnimationFrame(viewportResizeFrame);
    }

    viewportResizeFrame = window.requestAnimationFrame(() => {
        viewportResizeFrame = undefined;
        updateMobileAppViewportHeight();
    });
};

const expandMobileAppViewportAfterKeyboardClose = (): void => {
    if (!isMobileAppShellActive()) {
        return;
    }

    window.setTimeout(() => {
        if (isFocusedInput()) {
            return;
        }

        document.documentElement.style.setProperty(
            '--app-viewport-height',
            `${window.innerHeight}px`,
        );
        document.documentElement.style.setProperty(
            '--app-viewport-width',
            `${window.innerWidth}px`,
        );
        document.documentElement.style.setProperty(
            '--app-viewport-offset-left',
            '0px',
        );
        document.documentElement.style.setProperty(
            '--app-viewport-offset-top',
            '0px',
        );
        resetWindowScroll();
    }, 0);
};

onMounted(() => {
    mobileShellMediaQuery = window.matchMedia('(width < 48rem)');
    updateMobileAppViewportHeight();
    window.addEventListener('resize', scheduleMobileAppViewportHeightUpdate);
    window.addEventListener(
        'orientationchange',
        scheduleMobileAppViewportHeightUpdate,
    );
    window.visualViewport?.addEventListener(
        'resize',
        scheduleMobileAppViewportHeightUpdate,
    );
    window.visualViewport?.addEventListener(
        'scroll',
        scheduleMobileAppViewportHeightUpdate,
    );
    mobileShellMediaQuery.addEventListener(
        'change',
        scheduleMobileAppViewportHeightUpdate,
    );
    window.addEventListener('scroll', scheduleWindowScrollReset, {
        passive: true,
    });
    document.addEventListener(
        'focusin',
        scheduleMobileAppViewportHeightUpdate,
        true,
    );
    document.addEventListener(
        'focusout',
        expandMobileAppViewportAfterKeyboardClose,
        true,
    );
    window.addEventListener('keydown', handleEscape);
    window.addEventListener('padik:open-chat-list', openChatList);
    document.addEventListener('click', handleDocumentClick);
    removeNavigationFinishListener = router.on(
        'finish',
        stopConversationLoading,
    );
    window.Echo.private(`App.Models.User.${currentUserId}`).notification(
        appendNotification,
    );
});

watch(
    () => [
        page.props.rooms,
        page.props.directMessageUsers,
        page.props.notifications,
    ],
    ([rooms, users, currentNotifications]) => {
        messenger.syncNavigation(
            rooms as RoomNavItem[],
            users as DirectMessageUserNavItem[],
            currentNotifications as NotificationNav,
        );
    },
    { immediate: true },
);

watch(
    () => messenger.rooms.value.map((room) => room.id),
    (roomIds, previousRoomIds = []) => {
        previousRoomIds
            .filter((roomId) => !roomIds.includes(roomId))
            .forEach((roomId) => window.Echo.leave(`rooms.${roomId}`));

        roomIds
            .filter((roomId) => !previousRoomIds.includes(roomId))
            .forEach((roomId) => {
                window.Echo.private(`rooms.${roomId}`).listen(
                    '.RoomMessageSent',
                    (event: MessagePayload) => {
                        messenger.applyMessage(event);
                    },
                );
            });
    },
    { immediate: true },
);

watch(
    () => page.url,
    (url) => {
        if (url.startsWith('/dms/')) {
            activeTab.value = 'dms';
        }

        closeChatList();
        stopConversationLoading();
    },
);

onBeforeUnmount(() => {
    if (viewportResizeFrame) {
        window.cancelAnimationFrame(viewportResizeFrame);
    }

    if (scrollResetFrame) {
        window.cancelAnimationFrame(scrollResetFrame);
    }

    window.removeEventListener('resize', scheduleMobileAppViewportHeightUpdate);
    window.removeEventListener(
        'orientationchange',
        scheduleMobileAppViewportHeightUpdate,
    );
    window.visualViewport?.removeEventListener(
        'resize',
        scheduleMobileAppViewportHeightUpdate,
    );
    window.visualViewport?.removeEventListener(
        'scroll',
        scheduleMobileAppViewportHeightUpdate,
    );
    mobileShellMediaQuery?.removeEventListener(
        'change',
        scheduleMobileAppViewportHeightUpdate,
    );
    window.removeEventListener('scroll', scheduleWindowScrollReset);
    document.removeEventListener(
        'focusin',
        scheduleMobileAppViewportHeightUpdate,
        true,
    );
    document.removeEventListener(
        'focusout',
        expandMobileAppViewportAfterKeyboardClose,
        true,
    );
    document.documentElement.style.removeProperty('--app-viewport-height');
    document.documentElement.style.removeProperty('--app-viewport-width');
    document.documentElement.style.removeProperty('--app-viewport-offset-left');
    document.documentElement.style.removeProperty('--app-viewport-offset-top');
    window.removeEventListener('keydown', handleEscape);
    window.removeEventListener('padik:open-chat-list', openChatList);
    document.removeEventListener('click', handleDocumentClick);
    removeNavigationFinishListener?.();
    window.Echo.leave(`App.Models.User.${currentUserId}`);
    messenger.rooms.value.forEach((room) =>
        window.Echo.leave(`rooms.${room.id}`),
    );
});
</script>

<template>
    <div
        class="mobile-app-shell flex h-dvh w-full overflow-hidden bg-[#f5fafb] font-sans text-[#171d1e]"
    >
        <aside
            class="h-dvh min-h-0 w-full shrink-0 flex-col border-r border-[#bbc9cb] bg-white sm:flex sm:w-96"
            :class="isChatListOpen ? 'flex' : 'hidden'"
            aria-label="Conversations"
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
                        :href="showRoom('general')"
                        class="text-xl font-bold tracking-tight text-[#007681]"
                        @click="
                            openConversation(
                                page.url === showRoom.url('general'),
                            )
                        "
                    >
                        Padik
                    </Link>

                    <div ref="notificationsMenu" class="relative ml-auto">
                        <button
                            class="relative grid size-10 place-items-center rounded-full text-[#171d1e] transition-colors hover:bg-[#e4e9ea]"
                            aria-label="Open notifications"
                            :aria-expanded="areNotificationsOpen"
                            aria-controls="notifications-menu"
                            @click="
                                areNotificationsOpen = !areNotificationsOpen
                            "
                        >
                            <Bell class="size-5" />
                            <span
                                v-if="notifications.unread_count > 0"
                                class="absolute top-1 right-1 grid min-w-4 place-items-center rounded-full bg-[#ba1a1a] px-1 text-[10px] leading-4 font-bold text-white"
                            >
                                {{
                                    notifications.unread_count > 9
                                        ? '9+'
                                        : notifications.unread_count
                                }}
                            </span>
                        </button>

                        <div
                            v-if="areNotificationsOpen"
                            id="notifications-menu"
                            class="absolute top-12 right-0 z-50 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-[#bbc9cb] bg-white shadow-xl"
                        >
                            <div
                                class="flex items-center justify-between gap-3 border-b border-[#bbc9cb]/60 px-4 py-3"
                            >
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

                            <div
                                class="chat-scroll max-h-96 overflow-y-auto py-1"
                            >
                                <button
                                    v-for="notification in notifications.items"
                                    :key="notification.id"
                                    type="button"
                                    class="block w-full border-b border-[#bbc9cb]/30 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-[#eff5f5] focus:bg-[#eff5f5] focus:outline-none"
                                    :class="
                                        notification.read_at
                                            ? 'bg-white'
                                            : 'bg-[#006874]/5'
                                    "
                                    @click="openNotification(notification)"
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate text-sm font-bold text-[#171d1e]"
                                            >
                                                {{
                                                    notification.sender_name ??
                                                    notification.title
                                                }}
                                            </span>
                                            <span
                                                class="mt-0.5 line-clamp-2 block text-xs leading-5 text-[#6c797c]"
                                            >
                                                {{
                                                    notification.body ??
                                                    'Sent you a direct message.'
                                                }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="!notification.read_at"
                                            class="mt-1 size-2 shrink-0 rounded-full bg-[#007681]"
                                            aria-label="Unread"
                                        />
                                    </div>
                                    <div
                                        v-if="
                                            isInvitationNotification(
                                                notification,
                                            ) && !notification.read_at
                                        "
                                        class="mt-3 flex items-center gap-2"
                                        @click.stop
                                    >
                                        <button
                                            type="button"
                                            class="grid size-8 place-items-center rounded-full bg-[#ffdad6] text-[#ba1a1a] transition-colors hover:bg-[#ffb4ab] focus:ring-2 focus:ring-[#ba1a1a]/20 focus:outline-none"
                                            aria-label="Decline room invitation"
                                            @click="
                                                declineInvitationNotification(
                                                    notification,
                                                )
                                            "
                                        >
                                            <X class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="grid size-8 place-items-center rounded-full bg-[#d8f5dc] text-[#0f6b2f] transition-colors hover:bg-[#b9edc4] focus:ring-2 focus:ring-[#0f6b2f]/20 focus:outline-none"
                                            aria-label="Accept room invitation"
                                            @click="
                                                acceptInvitationNotification(
                                                    notification,
                                                )
                                            "
                                        >
                                            <Check class="size-4" />
                                        </button>
                                    </div>
                                    <span
                                        class="mt-2 block text-[11px] text-[#8a989b]"
                                    >
                                        {{ notification.created_at_human }}
                                    </span>
                                </button>

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

                <div class="flex items-center gap-2">
                    <label class="relative block min-w-0 flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#6c797c]"
                        />
                        <input
                            class="h-12 w-full rounded-full border-0 bg-[#eff5f5] pr-4 pl-12 text-base text-[#171d1e] placeholder:text-[#718083] focus:ring-2 focus:ring-[#006874]/20 focus:outline-none"
                            placeholder="Search"
                            type="search"
                        />
                    </label>

                    <button
                        type="button"
                        class="grid size-12 shrink-0 place-items-center rounded-full bg-[#007681] text-white transition-colors hover:bg-[#006874] focus:ring-2 focus:ring-[#006874]/25 focus:outline-none"
                        aria-label="Create group room"
                        @click="openCreateRoom"
                    >
                        <Pencil class="size-5" />
                    </button>
                </div>
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

            <div
                v-if="activeTab === 'rooms'"
                class="chat-scroll flex-1 overflow-y-auto py-2"
            >
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
                    @click="openConversation(chat.active === true)"
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
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    v-if="chat.unread_count > 0"
                                    class="size-2.5 shrink-0 rounded-full bg-[#0b84ff] ring-2 ring-white"
                                    aria-label="Unread messages"
                                />
                                <span class="truncate text-sm font-bold">
                                    {{ chat.name }}
                                </span>
                            </span>
                            <span
                                v-if="chat.time"
                                class="shrink-0 rounded-full bg-[#d8ebff] px-2 py-0.5 text-[11px] font-bold text-[#0b5cad]"
                            >
                                {{ chat.time }}
                            </span>
                        </span>
                        <span
                            class="block truncate text-xs"
                            :class="
                                chat.unread_count > 0
                                    ? 'font-semibold text-[#344649]'
                                    : 'text-[#6c797c]'
                            "
                        >
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
                    @click="openConversation(user.active === true)"
                >
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-full text-base font-bold text-white"
                        :class="user.color"
                    >
                        {{ user.initials }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span
                            class="mb-0.5 flex items-baseline justify-between gap-3"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    v-if="user.unread_count > 0"
                                    class="size-2.5 shrink-0 rounded-full bg-[#0b84ff] ring-2 ring-white"
                                    aria-label="Unread messages"
                                />
                                <span class="truncate text-sm font-bold">
                                    {{ user.name }}
                                </span>
                            </span>
                            <span
                                v-if="user.time"
                                class="shrink-0 rounded-full bg-[#d8ebff] px-2 py-0.5 text-[11px] font-bold text-[#0b5cad]"
                            >
                                {{ user.time }}
                            </span>
                        </span>
                        <span
                            class="block truncate text-xs"
                            :class="
                                user.unread_count > 0
                                    ? 'font-semibold text-[#344649]'
                                    : 'text-[#6c797c]'
                            "
                        >
                            {{ user.preview }}
                        </span>
                    </span>
                </Link>
                <div
                    v-if="directMessageUsers.length === 0"
                    class="px-6 py-10 text-center text-sm text-[#6c797c]"
                >
                    No users yet
                </div>
            </div>
        </aside>

        <main
            class="min-h-0 min-w-0 flex-1 overflow-hidden bg-white"
            :class="isChatListOpen ? 'hidden sm:block' : 'block'"
        >
            <section
                v-if="isConversationLoading"
                class="flex h-dvh min-h-0 min-w-0 flex-col bg-white"
                aria-live="polite"
                aria-busy="true"
            >
                <header
                    class="flex h-16 shrink-0 items-center border-b border-[#bbc9cb] bg-white px-3 sm:px-6"
                >
                    <div
                        class="h-7 w-40 animate-pulse rounded-full bg-[#eff5f5]"
                    />
                </header>

                <div class="flex flex-1 items-center justify-center px-6">
                    <div
                        class="flex flex-col items-center gap-3 text-[#6c797c]"
                    >
                        <Spinner class="size-7 text-[#007681]" />
                        <p class="text-sm font-medium">Loading messages...</p>
                    </div>
                </div>
            </section>
            <slot v-else />
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

        <Dialog v-model:open="isCreateRoomOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            createRoomStep === 'details'
                                ? 'Create group room'
                                : 'Invite people'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            createRoomStep === 'details'
                                ? 'Name the room. You can invite people after it is created.'
                                : `Choose who to invite to ${createdRoom?.title ?? 'this room'}.`
                        }}
                    </DialogDescription>
                </DialogHeader>

                <form
                    v-if="createRoomStep === 'details'"
                    class="space-y-4"
                    @submit.prevent="submitCreateRoom"
                >
                    <div class="space-y-2">
                        <Label for="room-title">Room name</Label>
                        <Input
                            id="room-title"
                            ref="roomTitleInput"
                            v-model="createRoomForm.title"
                            name="title"
                            maxlength="80"
                            autocomplete="off"
                            :aria-invalid="Boolean(createRoomForm.errors.title)"
                        />
                        <p
                            v-if="createRoomForm.errors.title"
                            class="text-sm font-medium text-[#ba1a1a]"
                        >
                            {{ createRoomForm.errors.title }}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="closeCreateRoom"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="createRoomForm.processing"
                        >
                            Create
                        </Button>
                    </DialogFooter>
                </form>

                <form
                    v-else
                    class="space-y-4"
                    @submit.prevent="submitRoomInvitations"
                >
                    <div
                        class="chat-scroll max-h-72 space-y-1 overflow-y-auto pr-1"
                    >
                        <button
                            v-for="user in invitableUsers"
                            :key="user.id"
                            type="button"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-[#eff5f5] focus:bg-[#eff5f5] focus:outline-none"
                            @click="toggleInviteUser(user.id)"
                        >
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-full text-sm font-bold text-white"
                                :class="user.color"
                            >
                                {{ user.initials }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-semibold text-[#171d1e]"
                                >
                                    {{ user.name }}
                                </span>
                                <span
                                    class="block truncate text-xs text-[#6c797c]"
                                >
                                    {{ user.preview }}
                                </span>
                            </span>
                            <span
                                class="grid size-5 shrink-0 place-items-center rounded border transition-colors"
                                :class="
                                    isUserSelectedForInvite(user.id)
                                        ? 'border-[#007681] bg-[#007681] text-white'
                                        : 'border-[#9ba9ac] bg-white'
                                "
                                aria-hidden="true"
                            >
                                <span
                                    v-if="isUserSelectedForInvite(user.id)"
                                    class="size-2 rounded-full bg-current"
                                />
                            </span>
                        </button>

                        <p
                            v-if="invitableUsers.length === 0"
                            class="px-4 py-8 text-center text-sm text-[#6c797c]"
                        >
                            No users available to invite
                        </p>
                    </div>

                    <p
                        v-if="inviteUsersForm.errors.user_ids"
                        class="text-sm font-medium text-[#ba1a1a]"
                    >
                        {{ inviteUsersForm.errors.user_ids }}
                    </p>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="closeCreateRoom"
                        >
                            Skip
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                inviteUsersForm.processing ||
                                inviteUsersForm.user_ids.length === 0
                            "
                        >
                            Invite
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

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
