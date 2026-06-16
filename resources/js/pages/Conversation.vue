<script setup lang="ts">
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    LogOut,
    LockKeyhole,
    MoreVertical,
    Paperclip,
    Search,
    Send,
    Settings,
    Smile,
    User,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useConversationRealtime } from '@/composables/useConversationRealtime';
import { useMessengerStore } from '@/composables/useMessengerStore';
import type {
    CurrentRoom,
    Message,
    MessagePayload,
} from '@/composables/useMessengerStore';
import { formatSafetyNumber, useSecretChat } from '@/composables/useSecretChat';
import { destroy as leaveRoom } from '@/routes/rooms/membership';
import { store as storeMessage } from '@/routes/rooms/messages';
import { edit as editRoomSettings } from '@/routes/rooms/settings';
import { store as storeSecretChat } from '@/routes/secret-chats';
import { store as storeSecretChatMessage } from '@/routes/secret-chats/messages';
import { show as showUserProfile } from '@/routes/users';
import type { Auth, SecretChatMessagePayload, SecretChatProps } from '@/types';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps<{
    currentRoom?: CurrentRoom;
    messages?: Message[];
    firstUnreadMessageId?: number | string | null;
    mentionableUsers?: MentionableUser[];
    secretChat?: SecretChatProps;
}>();

type MentionableUser = {
    id: number;
    name: string;
};

const messageForm = useHttp({
    body: '',
});

const page = usePage();
const currentUserId = Number((page.props.auth as Auth).user.id);
const messenger = useMessengerStore(currentUserId);
const visibleMessages = messenger.messages;
const messagesScroll = ref<HTMLElement | null>(null);
const messageInput = ref<HTMLTextAreaElement | null>(null);
const activeMentionIndex = ref(0);
const currentRoom = computed(() => props.currentRoom);
const secretChat = computed(() => props.secretChat);
const isSecretRoom = computed(() => currentRoom.value?.type === 'secret');
const currentDirectUserId = computed(() =>
    currentRoom.value?.type === 'direct' || currentRoom.value?.type === 'secret'
        ? currentRoom.value.direct_user_id
        : null,
);
const canStartSecretChat = computed(
    () =>
        currentRoom.value?.type === 'direct' &&
        Boolean(currentDirectUserId.value),
);
const mentionableUsers = computed(() => props.mentionableUsers ?? []);

const {
    fingerprint: secretFingerprint,
    safetyNumber: secretSafetyNumber,
    sharedKey: secretSharedKey,
    status: secretStatus,
    applyParticipant: applySecretParticipant,
    decryptMessage: decryptSecretMessage,
    encryptMessage: encryptSecretMessage,
    setup: setupSecretChat,
    synchronizeSender: synchronizeSecretChatSender,
} = useSecretChat(currentRoom, secretChat, currentUserId);

const isOwnMessage = (message: Message): boolean => message.own === true;

const scrollMessagesToBottom = async (): Promise<void> => {
    await nextTick();

    if (messagesScroll.value) {
        messagesScroll.value.scrollTop = messagesScroll.value.scrollHeight;
    }
};

const scrollMessageIntoView = async (messageId: string): Promise<boolean> => {
    await nextTick();

    const messageElement = document.getElementById(`message-${messageId}`);

    if (!messageElement) {
        return false;
    }

    messageElement.scrollIntoView({ block: 'center' });
    messageElement.classList.add('message-target-highlight');

    window.setTimeout(() => {
        messageElement.classList.remove('message-target-highlight');
    }, 1800);

    return true;
};

const scrollUnreadMarkerIntoView = async (
    messageId: number | string,
): Promise<boolean> => {
    await nextTick();

    const markerElement = document.getElementById(
        `new-messages-marker-${messageId}`,
    );

    if (!markerElement) {
        return false;
    }

    markerElement.scrollIntoView({ block: 'center' });

    return true;
};

const scrollToInitialMessageOrBottom = async (): Promise<void> => {
    const messageId = window.location.hash.match(/^#message-(.+)$/)?.[1];

    if (
        messageId &&
        (await scrollMessageIntoView(decodeURIComponent(messageId)))
    ) {
        return;
    }

    if (
        props.firstUnreadMessageId &&
        (await scrollUnreadMarkerIntoView(props.firstUnreadMessageId))
    ) {
        return;
    }

    await scrollMessagesToBottom();
};

const isFirstUnreadMessage = (message: Message): boolean =>
    props.firstUnreadMessageId !== null &&
    props.firstUnreadMessageId !== undefined &&
    String(message.id) === String(props.firstUnreadMessageId);

const applyMessage = (payload: MessagePayload): void => {
    messenger.applyMessage(payload);
    void scrollMessagesToBottom();
};

const activeMention = computed(() => {
    if (!messageInput.value || isSecretRoom.value) {
        return null;
    }

    const cursorPosition = messageInput.value.selectionStart;
    const beforeCursor = messageForm.body.slice(0, cursorPosition);
    const match = beforeCursor.match(/(^|\s)@([\w.-]*)$/);

    if (!match) {
        return null;
    }

    return {
        start: cursorPosition - match[2].length - 1,
        query: match[2],
    };
});

const mentionSuggestions = computed(() => {
    const mention = activeMention.value;

    if (!mention) {
        return [];
    }

    const query = mention.query.toLocaleLowerCase();

    return mentionableUsers.value
        .filter((user) => user.name.toLocaleLowerCase().includes(query))
        .slice(0, 6);
});

const selectMention = async (user: MentionableUser): Promise<void> => {
    const mention = activeMention.value;

    if (!mention || !messageInput.value) {
        return;
    }

    const cursorPosition = messageInput.value.selectionStart;
    const beforeMention = messageForm.body.slice(0, mention.start);
    const afterMention = messageForm.body.slice(cursorPosition);
    const replacement = `@${user.name} `;

    messageForm.body = `${beforeMention}${replacement}${afterMention}`;

    await nextTick();

    const nextCursorPosition = beforeMention.length + replacement.length;
    messageInput.value.focus();
    messageInput.value.setSelectionRange(
        nextCursorPosition,
        nextCursorPosition,
    );
    activeMentionIndex.value = 0;
};

const handleMessageKeydown = (event: KeyboardEvent): void => {
    if (mentionSuggestions.value.length === 0) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeMentionIndex.value =
            (activeMentionIndex.value + 1) % mentionSuggestions.value.length;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeMentionIndex.value =
            (activeMentionIndex.value - 1 + mentionSuggestions.value.length) %
            mentionSuggestions.value.length;
    }

    if (event.key === 'Enter' || event.key === 'Tab') {
        event.preventDefault();
        void selectMention(mentionSuggestions.value[activeMentionIndex.value]);
    }

    if (event.key === 'Escape') {
        activeMentionIndex.value = 0;
    }
};

const applySecretMessage = async (
    event: SecretChatMessagePayload,
): Promise<void> => {
    if (!currentRoom.value || event.message.sender_id === currentUserId) {
        return;
    }

    await synchronizeSecretChatSender(event.message.sender_fingerprint);

    const body = await decryptSecretMessage(
        event.message.ciphertext,
        event.message.iv,
    );

    messenger.applyMessage({
        message: {
            id: event.message.id,
            sender_id: event.message.sender_id,
            author: event.message.author,
            body,
            time: event.message.time,
            own: false,
        },
        conversation: {
            id: currentRoom.value.id,
            slug: currentRoom.value.slug,
            type: currentRoom.value.type,
            direct_user_id: currentRoom.value.direct_user_id,
        },
    });
    void scrollMessagesToBottom();
};

useConversationRealtime(currentRoom, {
    onRoomMessage: applyMessage,
    onSecretChatMessage: applySecretMessage,
    onSecretChatKeyUpdated: async (event) => {
        applySecretParticipant(event.participant);
        await setupSecretChat();
    },
});

watch(
    () =>
        [
            props.currentRoom,
            props.messages,
            props.firstUnreadMessageId,
        ] as const,
    ([room, messages]) => {
        messenger.syncCurrentConversation(room, messages);
        void scrollToInitialMessageOrBottom();
    },
    { immediate: true },
);

watch(mentionSuggestions, (suggestions) => {
    if (activeMentionIndex.value >= suggestions.length) {
        activeMentionIndex.value = 0;
    }
});

const submitSecretMessage = async (body: string): Promise<void> => {
    if (!currentRoom.value || !secretFingerprint.value) {
        return;
    }

    const encryptedMessage = await encryptSecretMessage(body);

    await useHttp({
        ...encryptedMessage,
        sender_fingerprint: secretFingerprint.value,
    }).post(storeSecretChatMessage.url(currentRoom.value.slug), {
        onSuccess: async (data) => {
            const payload = data as {
                message: {
                    id: string;
                    sender_id: number;
                    author: string;
                    time: string;
                };
            };

            messenger.applyMessage({
                message: {
                    id: payload.message.id,
                    sender_id: payload.message.sender_id,
                    author: payload.message.author,
                    body,
                    time: payload.message.time,
                    own: true,
                },
                conversation: {
                    id: currentRoom.value!.id,
                    slug: currentRoom.value!.slug,
                    type: currentRoom.value!.type,
                    direct_user_id: currentRoom.value!.direct_user_id,
                },
            });
            messageForm.reset();
            await scrollMessagesToBottom();
        },
    });
};

const submitRoomMessage = async (): Promise<void> => {
    if (!currentRoom.value) {
        return;
    }

    await messageForm.post(storeMessage.url(currentRoom.value.slug), {
        onSuccess: (data) => {
            applyMessage(data as MessagePayload);
            messageForm.reset();
        },
    });
};

const submitMessage = async (): Promise<void> => {
    if (!currentRoom.value || messageForm.processing) {
        return;
    }

    const body = messageForm.body.trim();

    if (!body) {
        return;
    }

    messageForm.body = body;

    if (isSecretRoom.value) {
        await submitSecretMessage(body);

        return;
    }

    await submitRoomMessage();
};

const openRoomSettings = (): void => {
    if (!currentRoom.value) {
        return;
    }

    router.visit(editRoomSettings.url(currentRoom.value.slug));
};

const openCurrentDirectProfile = (): void => {
    if (!currentDirectUserId.value) {
        return;
    }

    router.visit(showUserProfile.url(currentDirectUserId.value));
};

const startSecretChat = (): void => {
    if (!currentDirectUserId.value) {
        return;
    }

    router.post(storeSecretChat.url(currentDirectUserId.value));
};

const leaveCurrentRoom = (): void => {
    if (!currentRoom.value) {
        return;
    }

    router.delete(leaveRoom.url(currentRoom.value.slug));
};

const openChatList = (): void => {
    window.dispatchEvent(new CustomEvent('padik:open-chat-list'));
};
</script>

<template>
    <Head title="Padik" />

    <section
        class="flex h-dvh min-h-0 min-w-0 flex-col overflow-hidden bg-white"
    >
        <header
            class="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-[#bbc9cb] bg-white px-3 sm:px-6"
        >
            <div class="flex min-w-0 items-center gap-2">
                <button
                    type="button"
                    class="grid size-10 shrink-0 place-items-center rounded-full text-[#171d1e] transition-colors hover:bg-[#e4e9ea] sm:hidden"
                    aria-label="Back to conversations"
                    @click="openChatList"
                >
                    <ArrowLeft class="size-5" />
                </button>

                <div class="min-w-0">
                    <h1
                        class="truncate text-base leading-6 font-bold text-[#171d1e] sm:text-lg"
                    >
                        {{ currentRoom?.title ?? 'Conversation' }}
                    </h1>
                    <span class="block truncate text-[11px] text-[#6c797c]">
                        {{
                            currentRoom?.type === 'direct'
                                ? 'Direct message'
                                : currentRoom?.type === 'secret'
                                  ? 'Secret chat'
                                  : currentRoom
                                    ? 'Room conversation'
                                    : 'Select a room'
                        }}
                    </span>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <button
                    class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                    aria-label="Search messages"
                >
                    <Search class="size-6" />
                </button>
                <DropdownMenu v-if="currentRoom">
                    <DropdownMenuTrigger :as-child="true">
                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                            aria-label="More options"
                        >
                            <MoreVertical class="size-6" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuItem
                            v-if="
                                currentRoom.type === 'group' &&
                                currentRoom.can_manage
                            "
                            class="cursor-pointer"
                            @select="openRoomSettings"
                        >
                            <Settings class="size-4" />
                            Room settings
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-else-if="currentRoom.type === 'group'"
                            class="cursor-pointer text-[#ba1a1a] focus:text-[#ba1a1a]"
                            @select="leaveCurrentRoom"
                        >
                            <LogOut class="size-4" />
                            Leave room
                        </DropdownMenuItem>
                        <template v-else-if="currentDirectUserId">
                            <DropdownMenuItem
                                class="cursor-pointer"
                                @select="openCurrentDirectProfile"
                            >
                                <User class="size-4" />
                                See profile
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="canStartSecretChat"
                                class="cursor-pointer"
                                @select="startSecretChat"
                            >
                                <LockKeyhole class="size-4" />
                                Start secret chat
                            </DropdownMenuItem>
                        </template>
                        <DropdownMenuItem v-else disabled>
                            No actions available
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <button
                    v-else
                    class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                    aria-label="More options"
                    disabled
                >
                    <MoreVertical class="size-6" />
                </button>
            </div>
        </header>

        <div
            ref="messagesScroll"
            class="chat-scroll min-h-0 flex-1 overflow-y-auto px-3 py-5 sm:px-6 sm:py-8"
        >
            <div v-if="currentRoom" class="mb-9 flex justify-center">
                <span
                    class="rounded-full bg-[#dee3e4] px-4 py-1.5 text-[11px] font-bold text-[#6c797c]"
                >
                    {{
                        visibleMessages.length ? 'Messages' : 'No messages yet'
                    }}
                </span>
            </div>

            <div
                v-if="currentRoom?.type === 'secret'"
                class="mx-auto mb-8 max-w-3xl rounded-lg border border-[#bbc9cb]/40 bg-[#eff5f5] p-4 text-sm text-[#171d1e]"
            >
                <p class="font-bold text-[#007681]">
                    {{ secretStatus }}
                </p>
                <p
                    v-if="secretSafetyNumber"
                    class="mt-2 text-xs text-[#6c797c]"
                >
                    Safety number:
                    <span class="font-mono text-[#171d1e]">
                        {{ formatSafetyNumber(secretSafetyNumber) }}
                    </span>
                </p>
            </div>

            <div v-if="currentRoom && visibleMessages.length" class="space-y-8">
                <template v-for="message in visibleMessages" :key="message.id">
                    <div
                        v-if="isFirstUnreadMessage(message)"
                        :id="`new-messages-marker-${message.id}`"
                        class="flex items-center gap-4"
                    >
                        <span class="h-px flex-1 bg-[#bbc9cb]/50"></span>
                        <span
                            class="rounded-full bg-[#007681] px-4 py-1.5 text-[11px] font-bold tracking-wide text-white uppercase shadow-sm"
                        >
                            New messages
                        </span>
                        <span class="h-px flex-1 bg-[#bbc9cb]/50"></span>
                    </div>

                    <article
                        :id="`message-${message.id}`"
                        class="flex gap-2 sm:gap-4"
                        :class="
                            isOwnMessage(message)
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <Link
                            v-if="!isOwnMessage(message)"
                            :href="showUserProfile(message.sender_id)"
                            class="mt-1 grid size-8 shrink-0 place-items-center rounded-full bg-[#007681] text-xs font-bold text-white transition-colors hover:bg-[#006874] focus:ring-2 focus:ring-[#006874]/25 focus:outline-none sm:size-10 sm:text-sm"
                            :aria-label="`Open ${message.author}'s profile`"
                        >
                            {{ message.author[0] }}
                        </Link>

                        <div
                            class="flex max-w-[min(52rem,88%)] flex-col gap-1 sm:max-w-[min(52rem,78%)]"
                            :class="
                                isOwnMessage(message)
                                    ? 'items-end'
                                    : 'items-start'
                            "
                        >
                            <Link
                                v-if="!isOwnMessage(message)"
                                :href="showUserProfile(message.sender_id)"
                                class="text-sm font-bold text-[#007681]"
                            >
                                {{ message.author }}
                            </Link>

                            <div
                                class="min-w-0 px-4 py-3 shadow-sm"
                                :class="
                                    isOwnMessage(message)
                                        ? 'rounded-2xl rounded-tr-none bg-[#007681] text-white'
                                        : 'rounded-2xl rounded-tl-none border border-[#bbc9cb]/30 bg-[#eff5f5] text-[#171d1e]'
                                "
                            >
                                <p class="text-sm leading-6 md:text-base">
                                    {{ message.body }}
                                </p>
                                <span
                                    class="mt-1 block text-right text-[10px]"
                                    :class="
                                        isOwnMessage(message)
                                            ? 'text-white/70'
                                            : 'text-[#6c797c]'
                                    "
                                >
                                    {{ message.time }}
                                </span>
                            </div>
                        </div>
                    </article>
                </template>
            </div>

            <div
                v-else
                class="flex h-full items-center justify-center text-sm text-[#6c797c]"
            >
                {{
                    currentRoom
                        ? 'There are no messages in this room yet.'
                        : 'Select a room to start chatting.'
                }}
            </div>
        </div>

        <footer
            v-if="currentRoom"
            class="border-t border-[#bbc9cb]/30 bg-white px-3 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:p-4"
        >
            <form
                class="mx-auto flex max-w-5xl items-end gap-2 sm:gap-3"
                @submit.prevent="submitMessage"
            >
                <button
                    type="button"
                    class="grid size-10 shrink-0 place-items-center rounded-full text-[#6c797c] transition-colors hover:text-[#007681] sm:size-12"
                    aria-label="Attach file"
                >
                    <Paperclip class="size-5 sm:size-6" />
                </button>

                <div class="relative min-w-0 flex-1">
                    <div
                        v-if="mentionSuggestions.length > 0"
                        class="absolute right-0 bottom-full left-0 z-20 mb-2 overflow-hidden rounded-lg border border-[#bbc9cb] bg-white shadow-xl"
                    >
                        <button
                            v-for="(user, index) in mentionSuggestions"
                            :key="user.id"
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors"
                            :class="
                                index === activeMentionIndex
                                    ? 'bg-[#eff5f5]'
                                    : 'bg-white hover:bg-[#eff5f5]'
                            "
                            @mousedown.prevent="selectMention(user)"
                        >
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-full bg-[#007681] text-xs font-bold text-white"
                            >
                                {{ user.name[0] }}
                            </span>
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-sm font-bold text-[#171d1e]"
                                >
                                    {{ user.name }}
                                </span>
                                <span class="block text-xs text-[#6c797c]">
                                    @{{ user.name }}
                                </span>
                            </span>
                        </button>
                    </div>
                    <textarea
                        ref="messageInput"
                        v-model="messageForm.body"
                        class="max-h-32 min-h-12 w-full resize-none rounded-2xl border-0 bg-[#eff5f5] px-5 py-3 pr-12 text-sm text-[#171d1e] placeholder:text-[#718083] focus:ring-0 focus:outline-none"
                        placeholder="Write a message..."
                        rows="1"
                        @keydown="handleMessageKeydown"
                    />
                    <p
                        v-if="messageForm.errors.body"
                        class="mt-1 px-2 text-xs text-red-600"
                    >
                        {{ messageForm.errors.body }}
                    </p>
                    <button
                        type="button"
                        class="absolute right-3 bottom-2.5 grid size-8 place-items-center rounded-full text-[#6c797c] transition-colors hover:text-[#007681]"
                        aria-label="Choose emoji"
                    >
                        <Smile class="size-6" />
                    </button>
                </div>

                <button
                    type="submit"
                    class="grid size-10 shrink-0 place-items-center rounded-full bg-[#007681] text-white shadow-md transition-all hover:bg-[#006874] active:scale-95 sm:size-12"
                    :disabled="
                        messageForm.processing ||
                        !messageForm.body.trim() ||
                        (currentRoom.type === 'secret' && !secretSharedKey)
                    "
                    aria-label="Send message"
                >
                    <Send class="size-5 sm:size-6" />
                </button>
            </form>
        </footer>
    </section>
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

:global(.message-target-highlight) {
    animation: message-target-highlight 1.8s ease-out;
}

@keyframes message-target-highlight {
    0%,
    45% {
        filter: drop-shadow(0 0 0.8rem rgb(0 118 129 / 0.45));
    }

    100% {
        filter: drop-shadow(0 0 0 rgb(0 118 129 / 0));
    }
}
</style>
