<?php

use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomMessageController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('r/{conversation:slug}', RoomController::class)->name('rooms.show');
    Route::post('r/{conversation:slug}/messages', [RoomMessageController::class, 'store'])->name('rooms.messages.store');
});

require __DIR__.'/settings.php';
