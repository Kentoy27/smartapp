<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64)->unique();
            $table->string('browser', 80);
            $table->string('platform', 80);
            $table->string('device_type', 20);
            $table->timestamp('last_seen_at');
            $table->timestamp('logged_out_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'logged_out_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_sessions');
    }
};
