<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, MailPlus, UserMinus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as showRoom, update as updateRoom } from '@/routes/rooms';
import {
    destroy as cancelInvitation,
    store as storeInvitation,
} from '@/routes/rooms/invitations';
import { destroy as removeMember } from '@/routes/rooms/members';

defineOptions({
    inheritAttrs: false,
});

type RoomSettingsUser = {
    id: number;
    name: string;
    email: string;
};

type RoomMember = RoomSettingsUser & {
    role: string;
    is_owner: boolean;
};

type PendingInvitation = RoomSettingsUser & {
    user_id: number;
    created_at_human: string;
};

const props = defineProps<{
    room: {
        id: number;
        title: string;
        slug: string;
    };
    members: RoomMember[];
    pendingInvitations: PendingInvitation[];
    availableUsers: RoomSettingsUser[];
}>();

const activeTab = ref<'general' | 'members'>('general');

const form = useForm({
    title: props.room.title,
});

const inviteForm = useForm<{ user_ids: number[] }>({
    user_ids: [],
});

const selectedAvailableUsers = computed(() =>
    props.availableUsers.filter((user) =>
        inviteForm.user_ids.includes(user.id),
    ),
);

const submit = () => {
    form.patch(updateRoom.url(props.room.slug));
};

const toggleInviteUser = (userId: number) => {
    inviteForm.user_ids = inviteForm.user_ids.includes(userId)
        ? inviteForm.user_ids.filter(
              (selectedUserId) => selectedUserId !== userId,
          )
        : [...inviteForm.user_ids, userId];
};

const sendInvitations = () => {
    inviteForm.post(storeInvitation.url(props.room.slug), {
        preserveScroll: true,
        onSuccess: () => inviteForm.reset(),
    });
};

const cancelPendingInvitation = (invitationId: number) => {
    router.delete(
        cancelInvitation.url({
            conversation: props.room.slug,
            invitation: invitationId,
        }),
        { preserveScroll: true },
    );
};

const removeRoomMember = (userId: number) => {
    router.delete(
        removeMember.url({
            conversation: props.room.slug,
            user: userId,
        }),
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="`${room.title} settings`" />

    <section class="flex h-dvh min-w-0 flex-col overflow-hidden bg-white">
        <header
            class="flex h-16 shrink-0 items-center gap-3 border-b border-[#bbc9cb] bg-white px-6"
        >
            <Link
                :href="showRoom(room.slug)"
                class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                aria-label="Back to room"
            >
                <ArrowLeft class="size-5" />
            </Link>
            <div>
                <h1 class="text-lg leading-6 font-bold text-[#171d1e]">
                    Room settings
                </h1>
                <p class="text-[11px] text-[#6c797c]">
                    {{ room.title }}
                </p>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto px-6 py-8">
            <div class="max-w-4xl">
                <div class="mb-8 flex gap-1 border-b border-[#bbc9cb]">
                    <button
                        type="button"
                        class="border-b-2 px-4 py-3 text-sm font-bold transition-colors"
                        :class="
                            activeTab === 'general'
                                ? 'border-[#007681] text-[#007681]'
                                : 'border-transparent text-[#6c797c] hover:bg-[#eff5f5]'
                        "
                        @click="activeTab = 'general'"
                    >
                        General
                    </button>
                    <button
                        type="button"
                        class="border-b-2 px-4 py-3 text-sm font-bold transition-colors"
                        :class="
                            activeTab === 'members'
                                ? 'border-[#007681] text-[#007681]'
                                : 'border-transparent text-[#6c797c] hover:bg-[#eff5f5]'
                        "
                        @click="activeTab = 'members'"
                    >
                        Members
                    </button>
                </div>

                <form
                    v-if="activeTab === 'general'"
                    class="max-w-xl space-y-5"
                    @submit.prevent="submit"
                >
                    <div class="space-y-2">
                        <Label for="room-title">Room name</Label>
                        <Input
                            id="room-title"
                            v-model="form.title"
                            name="title"
                            maxlength="80"
                            autocomplete="off"
                            :aria-invalid="Boolean(form.errors.title)"
                        />
                        <p
                            v-if="form.errors.title"
                            class="text-sm font-medium text-[#ba1a1a]"
                        >
                            {{ form.errors.title }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="form.processing">
                            Save
                        </Button>
                        <Button as-child type="button" variant="ghost">
                            <Link :href="showRoom(room.slug)">Cancel</Link>
                        </Button>
                    </div>
                </form>

                <div v-else class="space-y-8">
                    <section class="space-y-3">
                        <div>
                            <h2 class="text-base font-bold text-[#171d1e]">
                                Invite people
                            </h2>
                            <p class="text-sm text-[#6c797c]">
                                Select users who are not already members or
                                pending.
                            </p>
                        </div>

                        <div
                            v-if="availableUsers.length"
                            class="max-h-64 overflow-y-auto rounded-lg border border-[#bbc9cb]/70"
                        >
                            <button
                                v-for="user in availableUsers"
                                :key="user.id"
                                type="button"
                                class="flex w-full items-center gap-3 border-b border-[#bbc9cb]/40 px-4 py-3 text-left last:border-b-0 hover:bg-[#eff5f5] focus:bg-[#eff5f5] focus:outline-none"
                                @click="toggleInviteUser(user.id)"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-full bg-[#007681] text-sm font-bold text-white"
                                >
                                    {{ user.name[0] }}
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
                                        {{ user.email }}
                                    </span>
                                </span>
                                <span
                                    class="grid size-5 shrink-0 place-items-center rounded border transition-colors"
                                    :class="
                                        inviteForm.user_ids.includes(user.id)
                                            ? 'border-[#007681] bg-[#007681] text-white'
                                            : 'border-[#9ba9ac] bg-white'
                                    "
                                >
                                    <span
                                        v-if="
                                            inviteForm.user_ids.includes(
                                                user.id,
                                            )
                                        "
                                        class="size-2 rounded-full bg-current"
                                    />
                                </span>
                            </button>
                        </div>

                        <p
                            v-else
                            class="rounded-lg bg-[#eff5f5] px-4 py-5 text-sm text-[#6c797c]"
                        >
                            No users available to invite.
                        </p>

                        <p
                            v-if="inviteForm.errors.user_ids"
                            class="text-sm font-medium text-[#ba1a1a]"
                        >
                            {{ inviteForm.errors.user_ids }}
                        </p>

                        <Button
                            type="button"
                            :disabled="
                                inviteForm.processing ||
                                inviteForm.user_ids.length === 0
                            "
                            @click="sendInvitations"
                        >
                            <MailPlus class="size-4" />
                            Invite {{ selectedAvailableUsers.length || '' }}
                        </Button>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-base font-bold text-[#171d1e]">
                            Pending invitations
                        </h2>

                        <div
                            class="overflow-hidden rounded-lg border border-[#bbc9cb]/70"
                        >
                            <div
                                v-for="invitation in pendingInvitations"
                                :key="invitation.id"
                                class="flex items-center gap-3 border-b border-[#bbc9cb]/40 px-4 py-3 last:border-b-0"
                            >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-semibold text-[#171d1e]"
                                    >
                                        {{ invitation.name }}
                                    </span>
                                    <span
                                        class="block truncate text-xs text-[#6c797c]"
                                    >
                                        {{ invitation.email }} ·
                                        {{ invitation.created_at_human }}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    class="grid size-9 place-items-center rounded-full text-[#ba1a1a] transition-colors hover:bg-[#ffdad6]"
                                    aria-label="Cancel invitation"
                                    @click="
                                        cancelPendingInvitation(invitation.id)
                                    "
                                >
                                    <X class="size-4" />
                                </button>
                            </div>
                            <p
                                v-if="pendingInvitations.length === 0"
                                class="px-4 py-5 text-sm text-[#6c797c]"
                            >
                                No pending invitations.
                            </p>
                        </div>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-base font-bold text-[#171d1e]">
                            Members
                        </h2>

                        <div
                            class="overflow-hidden rounded-lg border border-[#bbc9cb]/70"
                        >
                            <div
                                v-for="member in members"
                                :key="member.id"
                                class="flex items-center gap-3 border-b border-[#bbc9cb]/40 px-4 py-3 last:border-b-0"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-full bg-[#3f6b73] text-sm font-bold text-white"
                                >
                                    {{ member.name[0] }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-semibold text-[#171d1e]"
                                        >
                                            {{ member.name }}
                                        </span>
                                        <span
                                            v-if="member.is_owner"
                                            class="rounded-full bg-[#d8f5dc] px-2 py-0.5 text-[11px] font-bold text-[#0f6b2f]"
                                        >
                                            Owner
                                        </span>
                                    </span>
                                    <span
                                        class="block truncate text-xs text-[#6c797c]"
                                    >
                                        {{ member.email }}
                                    </span>
                                </span>
                                <button
                                    v-if="!member.is_owner"
                                    type="button"
                                    class="grid size-9 place-items-center rounded-full text-[#ba1a1a] transition-colors hover:bg-[#ffdad6]"
                                    aria-label="Remove member"
                                    @click="removeRoomMember(member.id)"
                                >
                                    <UserMinus class="size-4" />
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </section>
</template>
