import type { MessagePayload } from '@/composables/useMessengerStore';

export type SecretChatParticipant = {
    id: number;
    name: string;
    public_key: JsonWebKey | null;
    fingerprint: string | null;
};

export type SecretChatProps = {
    participants: SecretChatParticipant[];
};

export type SecretChatKeyUpdatedPayload = {
    participant: SecretChatParticipant;
};

export type SecretChatMessagePayload = {
    message: {
        id: string;
        sender_id: number;
        author: string;
        ciphertext: string;
        iv: string;
        sender_fingerprint: string;
        time: string;
        created_at?: string | null;
    };
};

export type ConversationRealtimeHandlers = {
    onRoomMessage: (event: MessagePayload) => void;
    onSecretChatMessage: (
        event: SecretChatMessagePayload,
    ) => void | Promise<void>;
    onSecretChatKeyUpdated: (
        event: SecretChatKeyUpdatedPayload,
    ) => void | Promise<void>;
};
