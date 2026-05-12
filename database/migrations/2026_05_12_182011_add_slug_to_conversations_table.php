<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('title');
        });

        DB::table('conversations')
            ->orderBy('id')
            ->get(['id', 'title', 'type'])
            ->each(function (object $conversation): void {
                $baseSlug = Str::slug($conversation->title ?: $conversation->type);
                $slug = $baseSlug !== '' ? $baseSlug : "room-{$conversation->id}";
                $candidate = $slug;
                $suffix = 2;

                while (DB::table('conversations')->where('slug', $candidate)->exists()) {
                    $candidate = "{$slug}-{$suffix}";
                    $suffix++;
                }

                DB::table('conversations')
                    ->where('id', $conversation->id)
                    ->update(['slug' => $candidate]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
