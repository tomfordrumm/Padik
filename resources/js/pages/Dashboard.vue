<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MoreVertical, Paperclip, Search, Send, Smile } from 'lucide-vue-next';

type Message = {
    id: number;
    author: string;
    color: string;
    body: string;
    time: string;
    avatar?: string;
    own?: boolean;
};

const messages: Message[] = [
    {
        id: 1,
        author: 'Alex Chen',
        color: 'text-[#007681]',
        avatar: 'https://i.pravatar.cc/80?img=12',
        body: "Hey team, I've just uploaded the final campaign assets for the Q4 launch. Can everyone take a quick look? 🎨",
        time: '10:42 AM',
    },
    {
        id: 2,
        author: 'Sarah Jenkins',
        color: 'text-[#3f6b73]',
        avatar: 'https://i.pravatar.cc/80?img=47',
        body: 'On it, Alex! The color palette looks really sharp. Are we still going with the Amber accents for the CTA buttons?',
        time: '10:45 AM',
    },
    {
        id: 3,
        author: 'You',
        color: 'text-[#007681]',
        body: "Yes, Sarah. We're using the high-priority Amber (#dd9433) for all primary conversion points.",
        time: '10:47 AM',
        own: true,
    },
    {
        id: 4,
        author: 'Marcus Thorne',
        color: 'text-[#966000]',
        avatar: 'https://i.pravatar.cc/80?img=68',
        body: "Looks good. I'll start prepping the analytics dashboard for the launch monitoring. Should I include the secret mode metrics?",
        time: '10:50 AM',
    },
];
</script>

<template>
    <Head title="Padik" />

    <section class="flex h-dvh min-w-0 flex-col overflow-hidden bg-white">
        <header
            class="flex h-16 shrink-0 items-center justify-between border-b border-[#bbc9cb] bg-white px-6"
        >
            <div class="flex flex-col">
                <h1 class="text-lg leading-6 font-bold text-[#171d1e]">
                    Marketing-Squad
                </h1>
                <span class="text-[11px] text-[#6c797c]">
                    12 members, 4 online
                </span>
            </div>

            <div class="flex items-center gap-1">
                <button
                    class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                    aria-label="Search messages"
                >
                    <Search class="size-6" />
                </button>
                <button
                    class="grid size-10 place-items-center rounded-full text-[#6c797c] transition-colors hover:bg-[#e4e9ea]"
                    aria-label="More options"
                >
                    <MoreVertical class="size-6" />
                </button>
            </div>
        </header>

        <div class="chat-scroll flex-1 overflow-y-auto px-6 py-8">
            <div class="mb-9 flex justify-center">
                <span
                    class="rounded-full bg-[#dee3e4] px-4 py-1.5 text-[11px] font-bold text-[#6c797c]"
                >
                    October 24
                </span>
            </div>

            <div class="space-y-8">
                <article
                    v-for="message in messages"
                    :key="message.id"
                    class="flex gap-4"
                    :class="message.own ? 'justify-end' : 'justify-start'"
                >
                    <img
                        v-if="!message.own"
                        :alt="`${message.author} avatar`"
                        :src="message.avatar"
                        class="mt-1 size-10 shrink-0 rounded-full object-cover"
                    />

                    <div
                        class="flex max-w-[min(52rem,78%)] flex-col gap-1"
                        :class="message.own ? 'items-end' : 'items-start'"
                    >
                        <span
                            v-if="!message.own"
                            class="text-sm font-bold"
                            :class="message.color"
                        >
                            {{ message.author }}
                        </span>

                        <div
                            class="min-w-0 px-4 py-3 shadow-sm"
                            :class="
                                message.own
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
                                    message.own
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
        </div>

        <footer class="border-t border-[#bbc9cb]/30 bg-white p-4">
            <div class="mx-auto flex max-w-5xl items-end gap-3">
                <button
                    class="grid size-12 shrink-0 place-items-center rounded-full text-[#6c797c] transition-colors hover:text-[#007681]"
                    aria-label="Attach file"
                >
                    <Paperclip class="size-6" />
                </button>

                <div class="relative flex-1">
                    <textarea
                        class="max-h-32 min-h-12 w-full resize-none rounded-2xl border-0 bg-[#eff5f5] px-5 py-3 pr-12 text-sm text-[#171d1e] placeholder:text-[#718083] focus:ring-0 focus:outline-none"
                        placeholder="Write a message..."
                        rows="1"
                    />
                    <button
                        class="absolute right-3 bottom-2.5 grid size-8 place-items-center rounded-full text-[#6c797c] transition-colors hover:text-[#007681]"
                        aria-label="Choose emoji"
                    >
                        <Smile class="size-6" />
                    </button>
                </div>

                <button
                    class="grid size-12 shrink-0 place-items-center rounded-full bg-[#007681] text-white shadow-md transition-all hover:bg-[#006874] active:scale-95"
                    aria-label="Send message"
                >
                    <Send class="size-6" />
                </button>
            </div>
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
