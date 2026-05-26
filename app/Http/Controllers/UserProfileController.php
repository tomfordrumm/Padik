<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    public function __invoke(User $user): Response
    {
        return Inertia::render('UserProfile', [
            'profileUser' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);
    }
}
