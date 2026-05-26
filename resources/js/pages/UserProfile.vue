<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, LockKeyhole, Mail, UserPlus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { show as showDirectMessage } from '@/routes/direct-messages';
import { store as storeSecretChat } from '@/routes/secret-chats';

const props = defineProps<{
    profileUser: {
        id: number;
        name: string;
    };
}>();
</script>

<template>
    <Head :title="profileUser.name" />

    <section class="flex h-dvh min-w-0 flex-col overflow-hidden bg-white">
        <header
            class="flex h-16 shrink-0 items-center gap-3 border-b border-[#bbc9cb] bg-white px-6"
        >
            <Link
                :href="dashboard()"
                class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                aria-label="Back to dashboard"
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
                    <Button type="button" variant="outline">
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
    </section>
</template>
