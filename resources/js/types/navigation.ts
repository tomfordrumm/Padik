import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

export type RoomNavItem = {
    id: number;
    title: string;
    slug: string;
    type: string;
    unread_count: number;
    last_message: string | null;
};

export type DirectMessageUserNavItem = {
    id: number;
    name: string;
    unread_count: number;
    last_message: string | null;
};

export type NotificationItem = {
    id: string;
    type: string;
    title: string;
    body: string | null;
    invitation_id?: number;
    conversation_id?: number;
    room_title?: string;
    sender_id: number | null;
    sender_name: string | null;
    action_url: string | null;
    read_at: string | null;
    created_at: string;
    created_at_human: string;
};

export type NotificationNav = {
    unread_count: number;
    items: NotificationItem[];
};
