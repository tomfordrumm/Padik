<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MentionReceived extends Notification
{
    use Queueable;

    public function __construct(public Message $message) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array{title: string, message_id: int, conversation_id: int, room_title: string, sender_id: int, sender_name: string, body: string|null, action_url: string, read_at: null, created_at: string, created_at_human: string}
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{title: string, message_id: int, conversation_id: int, room_title: string, sender_id: int, sender_name: string, body: string|null, action_url: string, read_at: null, created_at: string, created_at_human: string}
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array{title: string, message_id: int, conversation_id: int, room_title: string, sender_id: int, sender_name: string, body: string|null, action_url: string, read_at: null, created_at: string, created_at_human: string}
     */
    private function payload(): array
    {
        $this->message->loadMissing(['conversation', 'user']);

        $roomTitle = $this->message->conversation->title ?? 'Direct message';

        return [
            'title' => 'You were mentioned',
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'room_title' => $roomTitle,
            'sender_id' => $this->message->user_id,
            'sender_name' => $this->message->user->name,
            'body' => $this->message->body,
            'action_url' => route('rooms.show', $this->message->conversation).'#message-'.$this->message->id,
            'read_at' => null,
            'created_at' => $this->message->created_at->toIso8601String(),
            'created_at_human' => $this->message->created_at->diffForHumans(),
        ];
    }
}
