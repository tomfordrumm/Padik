<?php

use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomMessageController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('dms/{user}', DirectMessageController::class)->name('direct-messages.show');
    Route::post('notifications/read', [NotificationController::class, 'markAllAsRead'])->name('notifications.read');
    Route::post('notifications/from/{sender}/read', [NotificationController::class, 'markFromSenderAsRead'])->name('notifications.from-sender.read');
    Route::get('r/{conversation:slug}', RoomController::class)->name('rooms.show');
    Route::post('r/{conversation:slug}/messages', [RoomMessageController::class, 'store'])->name('rooms.messages.store');
});

require __DIR__.'/settings.php';
