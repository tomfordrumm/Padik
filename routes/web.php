<?php

use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\InvitationResponseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomInvitationController;
use App\Http\Controllers\RoomMemberController;
use App\Http\Controllers\RoomMembershipController;
use App\Http\Controllers\RoomMessageController;
use App\Http\Controllers\RoomSettingsController;
use App\Http\Controllers\SecretChatController;
use App\Http\Controllers\SecretChatKeyController;
use App\Http\Controllers\SecretChatMessageController;
use App\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/r/general')->name('dashboard');
    Route::get('users/{user}', UserProfileController::class)->name('users.show');
    Route::get('dms/{user}', DirectMessageController::class)->name('direct-messages.show');
    Route::post('secret-chats/{user}', [SecretChatController::class, 'store'])->name('secret-chats.store');
    Route::get('secret-chats/{conversation:slug}', [SecretChatController::class, 'show'])->name('secret-chats.show');
    Route::post('secret-chats/{conversation:slug}/key', [SecretChatKeyController::class, 'store'])->name('secret-chats.key.store');
    Route::post('secret-chats/{conversation:slug}/messages', [SecretChatMessageController::class, 'store'])->name('secret-chats.messages.store');
    Route::post('notifications/read', [NotificationController::class, 'markAllAsRead'])->name('notifications.read');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.item.read');
    Route::post('notifications/from/{sender}/read', [NotificationController::class, 'markFromSenderAsRead'])->name('notifications.from-sender.read');
    Route::post('notifications/{notification}/accept-invitation', [InvitationResponseController::class, 'accept'])->name('notifications.invitations.accept');
    Route::post('notifications/{notification}/decline-invitation', [InvitationResponseController::class, 'decline'])->name('notifications.invitations.decline');
    Route::post('r', [RoomController::class, 'store'])->name('rooms.store');
    Route::get('r/{conversation:slug}', RoomController::class)->name('rooms.show');
    Route::get('r/{conversation:slug}/settings', [RoomSettingsController::class, 'edit'])->name('rooms.settings.edit');
    Route::patch('r/{conversation:slug}', [RoomSettingsController::class, 'update'])->name('rooms.update');
    Route::delete('r/{conversation:slug}/membership', [RoomMembershipController::class, 'destroy'])->name('rooms.membership.destroy');
    Route::post('r/{conversation:slug}/invitations', [RoomInvitationController::class, 'store'])->name('rooms.invitations.store');
    Route::delete('r/{conversation:slug}/invitations/{invitation}', [RoomInvitationController::class, 'destroy'])->name('rooms.invitations.destroy');
    Route::delete('r/{conversation:slug}/members/{user}', [RoomMemberController::class, 'destroy'])->name('rooms.members.destroy');
    Route::post('r/{conversation:slug}/messages', [RoomMessageController::class, 'store'])->name('rooms.messages.store');
});

require __DIR__.'/settings.php';
