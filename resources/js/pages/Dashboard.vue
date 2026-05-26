<script setup lang="ts">
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import { LogOut, MoreVertical, Paperclip, Search, Send, Settings, Smile } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    type CurrentRoom,
    type Message,
    type MessagePayload,
    useMessengerStore,
} from '@/composables/useMessengerStore';
import { store as storeMessage } from '@/routes/rooms/messages';
import { destroy as leaveRoom } from '@/routes/rooms/membership';
import { edit as editRoomSettings } from '@/routes/rooms/settings';
import type { Auth } from '@/types';

const props = defineProps<{
    currentRoom?: CurrentRoom;
    messages?: Message[];
}>();

const messageForm = useHttp({
    body: '',
});

const page = usePage();
const currentUserId = Number((page.props.auth as Auth).user.id);
const messenger = useMessengerStore(currentUserId);
const visibleMessages = messenger.messages;
const messagesScroll = ref<HTMLElement | null>(null);

const isOwnMessage = (message: Message) => message.own === true;

const scrollMessagesToBottom = async () => {
    await nextTick();

    if (messagesScroll.value) {
        messagesScroll.value.scrollTop = messagesScroll.value.scrollHeight;
    }
};

const applyMessage = (payload: MessagePayload) => {
    messenger.applyMessage(payload);
    void scrollMessagesToBottom();
};

watch(
    () => [props.currentRoom, props.messages] as const,
    ([currentRoom, messages]) => {
        messenger.syncCurrentConversation(currentRoom, messages);
        void scrollMessagesToBottom();
    },
    { immediate: true },
);

watch(
    () => props.currentRoom,
    (room, previousRoom) => {
        if (previousRoom?.type === 'direct') {
            window.Echo.leave(`rooms.${previousRoom.id}`);
        }

        if (!room || room.type !== 'direct') {
            return;
        }

        window.Echo.private(`rooms.${room.id}`).listen(
            '.RoomMessageSent',
            (event: MessagePayload) => {
                applyMessage(event);
            },
        );
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (props.currentRoom?.type === 'direct') {
        window.Echo.leave(`rooms.${props.currentRoom.id}`);
    }
});

const submitMessage = async () => {
    if (!props.currentRoom || messageForm.processing) {
        return;
    }

    const body = messageForm.body.trim();

    if (!body) {
        return;
    }

    messageForm.body = body;

    await messageForm.post(storeMessage.url(props.currentRoom.slug), {
        onSuccess: (data) => {
            applyMessage(data as MessagePayload);
            messageForm.reset();
        },
    });
};

const openRoomSettings = () => {
    if (!props.currentRoom) {
        return;
    }

    router.visit(editRoomSettings.url(props.currentRoom.slug));
};

const leaveCurrentRoom = () => {
    if (!props.currentRoom) {
        return;
    }

    router.delete(leaveRoom.url(props.currentRoom.slug));
};
</script>

<template>
    <Head title="Padik" />

    <section class="flex h-dvh min-w-0 flex-col overflow-hidden bg-white">
        <header
            class="flex h-16 shrink-0 items-center justify-between border-b border-[#bbc9cb] bg-white px-6"
        >
            <div class="flex flex-col">
                <h1 class="text-lg leading-6 font-bold text-[#171d1e]">
                    {{ currentRoom?.title ?? 'Dashboard' }}
                </h1>
                <span class="text-[11px] text-[#6c797c]">
                    {{
                        currentRoom?.type === 'direct'
                            ? 'Direct message'
                            : currentRoom
                              ? 'Room conversation'
                              : 'Select a room'
                    }}
                </span>
            </div>

            <div class="flex items-center gap-1">
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
                            v-if="currentRoom.type === 'group' && currentRoom.can_manage"
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

        <div ref="messagesScroll" class="chat-scroll flex-1 overflow-y-auto px-6 py-8">
            <div v-if="currentRoom" class="mb-9 flex justify-center">
                <span
                    class="rounded-full bg-[#dee3e4] px-4 py-1.5 text-[11px] font-bold text-[#6c797c]"
                >
                    {{ visibleMessages.length ? 'Messages' : 'No messages yet' }}
                </span>
            </div>

            <div v-if="currentRoom && visibleMessages.length" class="space-y-8">
                <article
                    v-for="message in visibleMessages"
                    :key="message.id"
                    class="flex gap-4"
                    :class="isOwnMessage(message) ? 'justify-end' : 'justify-start'"
                >
                    <span
                        v-if="!isOwnMessage(message)"
                        class="mt-1 grid size-10 shrink-0 place-items-center rounded-full bg-[#007681] text-sm font-bold text-white"
                    >
                        {{ message.author[0] }}
                    </span>

                    <div
                        class="flex max-w-[min(52rem,78%)] flex-col gap-1"
                        :class="isOwnMessage(message) ? 'items-end' : 'items-start'"
                    >
                        <span
                            v-if="!isOwnMessage(message)"
                            class="text-sm font-bold text-[#007681]"
                        >
                            {{ message.author }}
                        </span>

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
            </div>

            <div
                v-else
                class="flex h-full items-center justify-center text-sm text-[#6c797c]"
            >
                {{ currentRoom ? 'There are no messages in this room yet.' : 'Select a room to start chatting.' }}
            </div>
        </div>

        <footer v-if="currentRoom" class="border-t border-[#bbc9cb]/30 bg-white p-4">
            <form class="mx-auto flex max-w-5xl items-end gap-3" @submit.prevent="submitMessage">
                <button
                    type="button"
                    class="grid size-12 shrink-0 place-items-center rounded-full text-[#6c797c] transition-colors hover:text-[#007681]"
                    aria-label="Attach file"
                >
                    <Paperclip class="size-6" />
                </button>

                <div class="relative flex-1">
                    <textarea
                        v-model="messageForm.body"
                        class="max-h-32 min-h-12 w-full resize-none rounded-2xl border-0 bg-[#eff5f5] px-5 py-3 pr-12 text-sm text-[#171d1e] placeholder:text-[#718083] focus:ring-0 focus:outline-none"
                        placeholder="Write a message..."
                        rows="1"
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
                    class="grid size-12 shrink-0 place-items-center rounded-full bg-[#007681] text-white shadow-md transition-all hover:bg-[#006874] active:scale-95"
                    :disabled="messageForm.processing || !messageForm.body.trim()"
                    aria-label="Send message"
                >
                    <Send class="size-6" />
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
</style>
