<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, LockKeyhole, Mail, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { show as showDirectMessage } from '@/routes/direct-messages';
import { show as showRoom } from '@/routes/rooms';
import { store as storeRoomInvitation } from '@/routes/rooms/invitations';
import { store as storeSecretChat } from '@/routes/secret-chats';

type InvitableRoom = {
    id: number;
    title: string;
    slug: string;
};

const props = defineProps<{
    profileUser: {
        id: number;
        name: string;
    };
    invitableRooms: InvitableRoom[];
}>();

const isInviteDialogOpen = ref(false);
const selectedRoomSlug = ref<string | null>(null);
const selectedRoom = computed(
    () =>
        props.invitableRooms.find((room) => room.slug === selectedRoomSlug.value) ??
        null,
);
const inviteForm = useForm<{ user_ids: number[] }>({
    user_ids: [props.profileUser.id],
});

const openInviteDialog = (): void => {
    selectedRoomSlug.value = props.invitableRooms[0]?.slug ?? null;
    inviteForm.clearErrors();
    isInviteDialogOpen.value = true;
};

const sendInvitation = (): void => {
    if (!selectedRoom.value) {
        return;
    }

    inviteForm.user_ids = [props.profileUser.id];
    inviteForm.post(storeRoomInvitation.url(selectedRoom.value.slug), {
        preserveScroll: true,
        onSuccess: () => {
            isInviteDialogOpen.value = false;
            selectedRoomSlug.value = null;
            inviteForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="profileUser.name" />

    <section class="flex h-dvh min-w-0 flex-col overflow-hidden bg-white">
        <header
            class="flex h-16 shrink-0 items-center gap-3 border-b border-[#bbc9cb] bg-white px-6"
        >
            <Link
                :href="showRoom('general')"
                class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                aria-label="Back to General"
            >
                <ArrowLeft class="size-5" />
            </Link>
            <div>
                <h1 class="text-lg leading-6 font-bold text-[#171d1e]">
                    User profile
                </h1>
                <p class="text-[11px] text-[#6c797c]">
                    {{ profileUser.name }}
                </p>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto px-6 py-8">
            <div class="max-w-xl space-y-8">
                <div class="flex items-center gap-4">
                    <div
                        class="grid size-16 shrink-0 place-items-center rounded-full bg-[#007681] text-2xl font-bold text-white"
                    >
                        {{ profileUser.name[0] }}
                    </div>
                    <div class="min-w-0">
                        <h2 class="truncate text-2xl font-bold text-[#171d1e]">
                            {{ profileUser.name }}
                        </h2>
                    </div>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <Button as-child class="bg-[#007681] hover:bg-[#006874]">
                        <Link :href="showDirectMessage(profileUser.id)">
                            <Mail class="size-4" />
                            Отправить сообщение
                        </Link>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="openInviteDialog"
                    >
                        <UserPlus class="size-4" />
                        Пригласить в группу
                    </Button>
                    <Button as-child variant="outline">
                        <Link
                            :href="storeSecretChat(profileUser.id)"
                            method="post"
                            as="button"
                        >
                            <LockKeyhole class="size-4" />
                            Создать секретный чат
                        </Link>
                    </Button>
                </div>
            </div>
        </main>

        <Dialog v-model:open="isInviteDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Пригласить в группу</DialogTitle>
                    <DialogDescription>
                        Выберите группу, куда отправить приглашение для
                        {{ profileUser.name }}.
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="invitableRooms.length"
                    class="max-h-72 overflow-y-auto rounded-lg border border-[#bbc9cb]/70"
                >
                    <button
                        v-for="room in invitableRooms"
                        :key="room.id"
                        type="button"
                        class="flex w-full items-center gap-3 border-b border-[#bbc9cb]/40 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-[#eff5f5] focus:bg-[#eff5f5] focus:outline-none"
                        :class="
                            selectedRoomSlug === room.slug ? 'bg-[#eff5f5]' : ''
                        "
                        @click="selectedRoomSlug = room.slug"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-full bg-[#007681] text-sm font-bold text-white"
                        >
                            {{ room.title[0] }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-sm font-semibold text-[#171d1e]"
                            >
                                {{ room.title }}
                            </span>
                        </span>
                        <span
                            class="grid size-6 shrink-0 place-items-center rounded-full border"
                            :class="
                                selectedRoomSlug === room.slug
                                    ? 'border-[#007681] bg-[#007681] text-white'
                                    : 'border-[#9ba9ac] bg-white text-transparent'
                            "
                        >
                            <Check class="size-4" />
                        </span>
                    </button>
                </div>

                <p
                    v-else
                    class="rounded-lg bg-[#eff5f5] px-4 py-5 text-sm text-[#6c797c]"
                >
                    Нет групп, куда можно пригласить этого пользователя.
                </p>

                <p
                    v-if="inviteForm.errors.user_ids"
                    class="text-sm font-medium text-[#ba1a1a]"
                >
                    {{ inviteForm.errors.user_ids }}
                </p>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="isInviteDialogOpen = false"
                    >
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        class="bg-[#007681] hover:bg-[#006874]"
                        :disabled="
                            inviteForm.processing || selectedRoomSlug === null
                        "
                        @click="sendInvitation"
                    >
                        <UserPlus class="size-4" />
                        Пригласить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
