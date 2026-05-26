<script setup lang="ts">
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    LogOut,
    MoreVertical,
    Paperclip,
    Search,
    Send,
    Settings,
    Smile,
} from 'lucide-vue-next';
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
import { store as storeSecretChatKey } from '@/routes/secret-chats/key';
import { store as storeSecretChatMessage } from '@/routes/secret-chats/messages';
import { destroy as leaveRoom } from '@/routes/rooms/membership';
import { edit as editRoomSettings } from '@/routes/rooms/settings';
import { show as showUserProfile } from '@/routes/users';
import type { Auth } from '@/types';

type SecretChatParticipant = {
    id: number;
    name: string;
    public_key: JsonWebKey | null;
    fingerprint: string | null;
};

type SecretChatProps = {
    participants: SecretChatParticipant[];
};

const props = defineProps<{
    currentRoom?: CurrentRoom;
    messages?: Message[];
    secretChat?: SecretChatProps;
}>();

const messageForm = useHttp({
    body: '',
});

const page = usePage();
const currentUserId = Number((page.props.auth as Auth).user.id);
const messenger = useMessengerStore(currentUserId);
const visibleMessages = messenger.messages;
const messagesScroll = ref<HTMLElement | null>(null);
const secretPrivateKey = ref<CryptoKey | null>(null);
const secretPublicKey = ref<JsonWebKey | null>(null);
const secretFingerprint = ref<string | null>(null);
const secretSharedKey = ref<CryptoKey | null>(null);
const secretSafetyNumber = ref<string | null>(null);
const secretStatus = ref('Preparing encrypted session...');
const secretHandshakePoll = ref<number | null>(null);

const isOwnMessage = (message: Message) => message.own === true;
const isSecretRoom = () => props.currentRoom?.type === 'secret';

const canonicalJson = (value: unknown): string =>
    JSON.stringify(value, Object.keys(value as object).sort());

const bytesToBase64 = (bytes: Uint8Array): string =>
    btoa(String.fromCharCode(...bytes));

const base64ToBytes = (value: string): Uint8Array =>
    Uint8Array.from(atob(value), (character) => character.charCodeAt(0));

const digestHex = async (value: string): Promise<string> => {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(value),
    );

    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
};

const formatSafetyNumber = (fingerprint: string | null): string =>
    fingerprint
        ? (fingerprint
              .slice(0, 40)
              .match(/.{1,4}/g)
              ?.join(' ') ?? '')
        : '';

const currentSecretParticipant = () =>
    props.secretChat?.participants.find(
        (participant) => Number(participant.id) === currentUserId,
    );

const peerSecretParticipant = () =>
    props.secretChat?.participants.find(
        (participant) => Number(participant.id) !== currentUserId,
    );

const ensureSecretKeyPair = async () => {
    if (
        secretPrivateKey.value &&
        secretPublicKey.value &&
        secretFingerprint.value
    ) {
        return;
    }

    const keyPair = await crypto.subtle.generateKey(
        {
            name: 'ECDH',
            namedCurve: 'P-256',
        },
        false,
        ['deriveBits'],
    );

    secretPrivateKey.value = keyPair.privateKey;
    secretPublicKey.value = await crypto.subtle.exportKey(
        'jwk',
        keyPair.publicKey,
    );
    secretFingerprint.value = await digestHex(
        canonicalJson(secretPublicKey.value),
    );
};

const publishSecretPublicKey = async () => {
    if (
        !props.currentRoom ||
        !secretPublicKey.value ||
        !secretFingerprint.value
    ) {
        return;
    }

    await useHttp({
        public_key: secretPublicKey.value,
        fingerprint: secretFingerprint.value,
    }).post(storeSecretChatKey.url(props.currentRoom.slug), {
        onSuccess: () => {
            router.reload({
                only: ['secretChat'],
            });
        },
    });
};

const stopSecretHandshakePolling = () => {
    if (secretHandshakePoll.value === null) {
        return;
    }

    window.clearInterval(secretHandshakePoll.value);
    secretHandshakePoll.value = null;
};

const startSecretHandshakePolling = () => {
    if (
        !isSecretRoom() ||
        secretSharedKey.value ||
        secretHandshakePoll.value !== null
    ) {
        return;
    }

    secretHandshakePoll.value = window.setInterval(() => {
        if (!isSecretRoom() || secretSharedKey.value) {
            stopSecretHandshakePolling();

            return;
        }

        router.reload({
            only: ['secretChat'],
        });
    }, 3000);
};

const deriveSecretSharedKey = async () => {
    const peer = peerSecretParticipant();

    if (
        !secretPrivateKey.value ||
        !secretFingerprint.value ||
        !peer?.public_key ||
        !peer.fingerprint
    ) {
        secretSharedKey.value = null;
        secretSafetyNumber.value = null;
        secretStatus.value =
            'Waiting for the other user to open this secret chat.';

        return;
    }

    const peerPublicKey = await crypto.subtle.importKey(
        'jwk',
        peer.public_key,
        {
            name: 'ECDH',
            namedCurve: 'P-256',
        },
        false,
        [],
    );
    const sharedBits = await crypto.subtle.deriveBits(
        {
            name: 'ECDH',
            public: peerPublicKey,
        },
        secretPrivateKey.value,
        256,
    );
    const keyMaterial = await crypto.subtle.importKey(
        'raw',
        sharedBits,
        'HKDF',
        false,
        ['deriveKey'],
    );
    const safetySeed = [secretFingerprint.value, peer.fingerprint]
        .sort()
        .join(':');
    const salt = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(safetySeed),
    );

    secretSharedKey.value = await crypto.subtle.deriveKey(
        {
            name: 'HKDF',
            hash: 'SHA-256',
            salt,
            info: new TextEncoder().encode('padik-secret-chat-v1'),
        },
        keyMaterial,
        {
            name: 'AES-GCM',
            length: 256,
        },
        false,
        ['encrypt', 'decrypt'],
    );
    secretSafetyNumber.value = await digestHex(
        [canonicalJson(secretPublicKey.value), canonicalJson(peer.public_key)]
            .sort()
            .join(':'),
    );
    secretStatus.value =
        'End-to-end encrypted. Compare the safety number before sharing sensitive information.';
    stopSecretHandshakePolling();
};

const setupSecretChat = async () => {
    if (!isSecretRoom()) {
        secretSharedKey.value = null;
        secretSafetyNumber.value = null;
        stopSecretHandshakePolling();

        return;
    }

    await ensureSecretKeyPair();

    const currentParticipant = currentSecretParticipant();

    if (
        currentParticipant?.fingerprint !== secretFingerprint.value ||
        !currentParticipant.public_key
    ) {
        await publishSecretPublicKey();
    }

    await deriveSecretSharedKey();
    startSecretHandshakePolling();
};

const encryptSecretMessage = async (body: string) => {
    if (!secretSharedKey.value) {
        throw new Error('Secret chat key is not ready.');
    }

    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ciphertext = await crypto.subtle.encrypt(
        {
            name: 'AES-GCM',
            iv: iv as BufferSource,
        },
        secretSharedKey.value,
        new TextEncoder().encode(body),
    );

    return {
        ciphertext: bytesToBase64(new Uint8Array(ciphertext)),
        iv: bytesToBase64(iv),
    };
};

const decryptSecretMessage = async (
    ciphertext: string,
    iv: string,
): Promise<string> => {
    if (!secretSharedKey.value) {
        return '[Encrypted message: key not ready]';
    }

    try {
        const plaintext = await crypto.subtle.decrypt(
            {
                name: 'AES-GCM',
                iv: base64ToBytes(iv) as BufferSource,
            },
            secretSharedKey.value,
            base64ToBytes(ciphertext) as BufferSource,
        );

        return new TextDecoder().decode(plaintext);
    } catch {
        return '[Encrypted message: cannot decrypt]';
    }
};

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
    () => [props.currentRoom, props.secretChat] as const,
    () => {
        void setupSecretChat();
    },
    { immediate: true },
);

watch(
    () => props.currentRoom,
    (room, previousRoom) => {
        if (
            previousRoom?.type === 'direct' ||
            previousRoom?.type === 'secret'
        ) {
            window.Echo.leave(`rooms.${previousRoom.id}`);
        }

        if (!room || (room.type !== 'direct' && room.type !== 'secret')) {
            return;
        }

        window.Echo.private(`rooms.${room.id}`).listen(
            '.RoomMessageSent',
            (event: MessagePayload) => {
                applyMessage(event);
            },
        );

        window.Echo.private(`rooms.${room.id}`).listen(
            '.SecretChatMessageSent',
            async (event: {
                message: {
                    id: string;
                    sender_id: number;
                    author: string;
                    ciphertext: string;
                    iv: string;
                    sender_fingerprint: string;
                    time: string;
                };
            }) => {
                if (event.message.sender_id === currentUserId) {
                    return;
                }

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
                        id: room.id,
                        slug: room.slug,
                        type: room.type,
                        direct_user_id: room.direct_user_id,
                    },
                });
                void scrollMessagesToBottom();
            },
        );
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopSecretHandshakePolling();

    if (
        props.currentRoom?.type === 'direct' ||
        props.currentRoom?.type === 'secret'
    ) {
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

    if (isSecretRoom()) {
        if (!secretFingerprint.value) {
            return;
        }

        const encryptedMessage = await encryptSecretMessage(body);

        await useHttp({
            ...encryptedMessage,
            sender_fingerprint: secretFingerprint.value,
        }).post(storeSecretChatMessage.url(props.currentRoom.slug), {
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
                        id: props.currentRoom!.id,
                        slug: props.currentRoom!.slug,
                        type: props.currentRoom!.type,
                        direct_user_id: props.currentRoom!.direct_user_id,
                    },
                });
                messageForm.reset();
                await scrollMessagesToBottom();
            },
        });

        return;
    }

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
                            : currentRoom?.type === 'secret'
                              ? 'Secret chat'
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
            class="chat-scroll flex-1 overflow-y-auto px-6 py-8"
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
                <article
                    v-for="message in visibleMessages"
                    :key="message.id"
                    class="flex gap-4"
                    :class="
                        isOwnMessage(message) ? 'justify-end' : 'justify-start'
                    "
                >
                    <span
                        v-if="!isOwnMessage(message)"
                        class="mt-1 grid size-10 shrink-0 place-items-center rounded-full bg-[#007681] text-sm font-bold text-white"
                    >
                        {{ message.author[0] }}
                    </span>

                    <div
                        class="flex max-w-[min(52rem,78%)] flex-col gap-1"
                        :class="
                            isOwnMessage(message) ? 'items-end' : 'items-start'
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
            class="border-t border-[#bbc9cb]/30 bg-white p-4"
        >
            <form
                class="mx-auto flex max-w-5xl items-end gap-3"
                @submit.prevent="submitMessage"
            >
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
                    :disabled="
                        messageForm.processing ||
                        !messageForm.body.trim() ||
                        (currentRoom.type === 'secret' && !secretSharedKey)
                    "
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
