<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function markFromSenderAsRead(Request $request, User $sender): RedirectResponse
    {
        $request->user()
            ->unreadNotifications()
            ->where('data->sender_id', $sender->id)
            ->update(['read_at' => now()]);

        return back();
    }
}
