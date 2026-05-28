import { onBeforeUnmount, watch } from 'vue';
import type { Ref } from 'vue';
import type { CurrentRoom } from '@/composables/useMessengerStore';
import type { ConversationRealtimeHandlers } from '@/types';

export function useConversationRealtime(
    currentRoom: Ref<CurrentRoom | undefined>,
    handlers: ConversationRealtimeHandlers,
): void {
    const leaveRoomChannel = (room?: CurrentRoom): void => {
        if (room?.type === 'direct' || room?.type === 'secret') {
            window.Echo.leave(`rooms.${room.id}`);
        }
    };

    watch(
        currentRoom,
        (room, previousRoom) => {
            leaveRoomChannel(previousRoom);

            if (!room || (room.type !== 'direct' && room.type !== 'secret')) {
                return;
            }

            window.Echo.private(`rooms.${room.id}`)
                .listen('.RoomMessageSent', handlers.onRoomMessage)
                .listen('.SecretChatMessageSent', handlers.onSecretChatMessage)
                .listen(
                    '.SecretChatKeyUpdated',
                    handlers.onSecretChatKeyUpdated,
                );
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        leaveRoomChannel(currentRoom.value);
    });
}
