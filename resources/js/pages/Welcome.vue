<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { LockKeyhole, MessageCircle, ShieldCheck } from 'lucide-vue-next';
import { dashboard, login, register } from '@/routes';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Padik" />

    <main
        class="flex min-h-dvh items-center justify-center overflow-hidden bg-[#f5fafb] px-6 py-10 text-[#171d1e]"
    >
        <section
            class="mx-auto flex w-full max-w-3xl flex-col items-center text-center"
        >
            <div
                class="mb-8 grid size-20 place-items-center rounded-3xl border border-[#bbc9cb]/70 bg-white shadow-sm"
                aria-hidden="true"
            >
                <MessageCircle class="size-10 text-[#007681]" />
            </div>

            <p
                class="mb-3 text-sm font-semibold tracking-[0.24em] text-[#007681] uppercase"
            >
                Self-hosted secure messaging
            </p>

            <h1
                class="text-5xl leading-tight font-bold tracking-normal text-[#171d1e] sm:text-6xl"
            >
                Padik
            </h1>

            <p class="mt-5 max-w-2xl text-lg leading-8 text-[#3c494b]">
                Защищенный мессенджер для команд: общие комнаты, личные диалоги,
                приглашения и секретные чаты без хранения сообщений на сервере.
            </p>

            <div
                class="mt-8 flex flex-wrap justify-center gap-3 text-sm text-[#3c494b]"
            >
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-[#bbc9cb]/70 bg-white px-4 py-2"
                >
                    <ShieldCheck class="size-4 text-[#007681]" />
                    Private rooms
                </span>
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-[#bbc9cb]/70 bg-white px-4 py-2"
                >
                    <LockKeyhole class="size-4 text-[#007681]" />
                    End-to-end secret chats
                </span>
            </div>

            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                prefetch
                class="mt-10 inline-flex min-h-14 items-center justify-center rounded-full bg-[#007681] px-8 text-base font-bold text-white shadow-lg shadow-[#007681]/20 transition hover:bg-[#006874] focus:ring-2 focus:ring-[#006874]/30 focus:outline-none active:scale-95"
            >
                Start messaging
            </Link>

            <Link
                v-else-if="canRegister"
                :href="register()"
                prefetch
                class="mt-10 inline-flex min-h-14 items-center justify-center rounded-full bg-[#007681] px-8 text-base font-bold text-white shadow-lg shadow-[#007681]/20 transition hover:bg-[#006874] focus:ring-2 focus:ring-[#006874]/30 focus:outline-none active:scale-95"
            >
                Join Padik
            </Link>

            <Link
                v-else
                :href="login()"
                prefetch
                class="mt-10 inline-flex min-h-14 items-center justify-center rounded-full bg-[#007681] px-8 text-base font-bold text-white shadow-lg shadow-[#007681]/20 transition hover:bg-[#006874] focus:ring-2 focus:ring-[#006874]/30 focus:outline-none active:scale-95"
            >
                Start messaging
            </Link>
        </section>
    </main>
</template>
