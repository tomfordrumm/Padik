<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RoomInvitationReceived extends Notification
{
    use Queueable;

    public function __construct(public Invitation $invitation) {}

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
     * @return array{title: string, invitation_id: int, conversation_id: int, room_title: string, sender_id: int, sender_name: string, body: string, action_url: string, read_at: null, created_at: string, created_at_human: string}
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{title: string, invitation_id: int, conversation_id: int, room_title: string, sender_id: int, sender_name: string, body: string, action_url: string, read_at: null, created_at: string, created_at_human: string}
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array{title: string, invitation_id: int, conversation_id: int, room_title: string, sender_id: int, sender_name: string, body: string, action_url: string, read_at: null, created_at: string, created_at_human: string}
     */
    private function payload(): array
    {
        $this->invitation->loadMissing(['conversation', 'sender']);

        $roomTitle = $this->invitation->conversation->title ?? 'Untitled room';

        return [
            'title' => 'Room invitation',
            'invitation_id' => $this->invitation->id,
            'conversation_id' => $this->invitation->conversation_id,
            'room_title' => $roomTitle,
            'sender_id' => $this->invitation->sender_id,
            'sender_name' => $this->invitation->sender->name,
            'body' => "{$this->invitation->sender->name} invited you to {$roomTitle}.",
            'action_url' => route('rooms.show', $this->invitation->conversation),
            'read_at' => null,
            'created_at' => $this->invitation->created_at->toIso8601String(),
            'created_at_human' => $this->invitation->created_at->diffForHumans(),
        ];
    }
}
