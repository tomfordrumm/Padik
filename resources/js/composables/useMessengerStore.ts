import { computed, reactive } from 'vue';
import type {
    DirectMessageUserNavItem,
    NotificationItem,
    NotificationNav,
    RoomNavItem,
} from '@/types';

export type CurrentRoom = {
    id: number;
    title: string;
    slug: string;
    type: string;
    created_by_id?: number | null;
    can_manage?: boolean;
    direct_user_id?: number | null;
};

export type Message = {
    id: number;
    sender_id: number;
    author: string;
    body: string | null;
    time: string;
    own?: boolean;
};

export type MessageConversation = {
    id: number;
    slug: string;
    type: string;
    direct_user_id?: number | null;
};

export type MessagePayload = {
    message: Message;
    conversation: MessageConversation;
};

type State = {
    rooms: RoomNavItem[];
    directMessageUsers: DirectMessageUserNavItem[];
    notifications: NotificationNav;
    currentRoom: CurrentRoom | null;
    messages: Message[];
};

const state = reactive<State>({
    rooms: [],
    directMessageUsers: [],
    notifications: {
        unread_count: 0,
        items: [],
    },
    currentRoom: null,
    messages: [],
});

const normalizeMessage = (message: Message, currentUserId: number): Message => ({
    ...message,
    own: Number(message.sender_id) === currentUserId,
});

const replaceById = <T extends { id: number }>(
    items: T[],
    id: number,
    updater: (item: T) => T,
): T[] => items.map((item) => (item.id === id ? updater(item) : item));

const addMessage = (message: Message): void => {
    if (state.messages.some((currentMessage) => currentMessage.id === message.id)) {
        return;
    }

    state.messages = [...state.messages, message];
};

const clearUnreadForConversation = (currentRoom: CurrentRoom): void => {
    if (currentRoom.type === 'direct' && currentRoom.direct_user_id) {
        state.directMessageUsers = replaceById(
            state.directMessageUsers,
            currentRoom.direct_user_id,
            (user) => ({
                ...user,
                unread_count: 0,
            }),
        );

        return;
    }

    state.rooms = replaceById(state.rooms, currentRoom.id, (room) => ({
        ...room,
        unread_count: 0,
    }));
};

export function useMessengerStore(currentUserId: number) {
    const syncNavigation = (
        rooms: RoomNavItem[],
        directMessageUsers: DirectMessageUserNavItem[],
        notifications: NotificationNav,
    ): void => {
        state.rooms = [...rooms];
        state.directMessageUsers = [...directMessageUsers];
        state.notifications = {
            unread_count: notifications.unread_count,
            items: [...notifications.items],
        };
    };

    const syncCurrentConversation = (
        currentRoom: CurrentRoom | null | undefined,
        messages: Message[] | null | undefined,
    ): void => {
        state.currentRoom = currentRoom ?? null;
        state.messages = (messages ?? []).map((message) =>
            normalizeMessage(message, currentUserId),
        );

        if (state.currentRoom) {
            clearUnreadForConversation(state.currentRoom);
        }
    };

    const applyMessage = (payload: MessagePayload): void => {
        const message = normalizeMessage(payload.message, currentUserId);

        if (state.currentRoom?.id === payload.conversation.id) {
            addMessage(message);
        }

        if (payload.conversation.type === 'direct') {
            const directUserId =
                payload.conversation.direct_user_id ?? message.sender_id;
            const shouldIncrementUnread =
                !message.own && state.currentRoom?.id !== payload.conversation.id;

            state.directMessageUsers = replaceById(
                state.directMessageUsers,
                Number(directUserId),
                (user) => ({
                    ...user,
                    last_message: message.body,
                    unread_count: shouldIncrementUnread
                        ? user.unread_count + 1
                        : user.unread_count,
                }),
            );

            return;
        }

        const shouldIncrementUnread =
            !message.own && state.currentRoom?.id !== payload.conversation.id;

        state.rooms = replaceById(state.rooms, payload.conversation.id, (room) => ({
            ...room,
            last_message: message.body,
            unread_count: shouldIncrementUnread
                ? room.unread_count + 1
                : room.unread_count,
        }));
    };

    const appendNotification = (notification: NotificationItem): void => {
        if (
            state.notifications.items.some(
                (currentNotification) => currentNotification.id === notification.id,
            )
        ) {
            return;
        }

        state.notifications = {
            unread_count: state.notifications.unread_count + 1,
            items: [notification, ...state.notifications.items].slice(0, 20),
        };

        if (notification.sender_id && notification.body) {
            state.directMessageUsers = replaceById(
                state.directMessageUsers,
                notification.sender_id,
                (user) => ({
                    ...user,
                    last_message: notification.body,
                    unread_count:
                        state.currentRoom?.direct_user_id === notification.sender_id
                            ? user.unread_count
                            : user.unread_count + 1,
                }),
            );
        }
    };

    const markNotificationsRead = (): void => {
        state.notifications = {
            ...state.notifications,
            unread_count: 0,
            items: state.notifications.items.map((notification) => ({
                ...notification,
                read_at: notification.read_at ?? new Date().toISOString(),
            })),
        };
    };

    const markSenderNotificationsRead = (senderId: number): void => {
        const readAt = new Date().toISOString();

        state.notifications = {
            ...state.notifications,
            unread_count: state.notifications.items.filter(
                (notification) =>
                    !notification.read_at && notification.sender_id !== senderId,
            ).length,
            items: state.notifications.items.map((notification) =>
                notification.sender_id === senderId
                    ? {
                          ...notification,
                          read_at: notification.read_at ?? readAt,
                      }
                    : notification,
            ),
        };
    };

    return {
        rooms: computed(() => state.rooms),
        directMessageUsers: computed(() => state.directMessageUsers),
        notifications: computed(() => state.notifications),
        currentRoom: computed(() => state.currentRoom),
        messages: computed(() => state.messages),
        syncNavigation,
        syncCurrentConversation,
        applyMessage,
        appendNotification,
        markNotificationsRead,
        markSenderNotificationsRead,
    };
}
