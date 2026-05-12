<?php

namespace Database\Seeders;

use App\Actions\Conversations\EnsureGeneralConversation;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(EnsureGeneralConversation $generalConversation): void
    {
        // User::factory(10)->create();

        $generalConversation->conversation();
        $generalConversation->addAllUsers();
    }
}
