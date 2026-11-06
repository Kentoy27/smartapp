<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false);
        });

        DB::table('users')
            ->leftJoin('schools', 'schools.id', '=', 'users.school_id')
            ->select(['users.id', 'users.username', 'users.password', 'users.role', 'schools.school_id'])
            ->orderBy('users.id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $usesPublicSchoolId = $user->role === 'user'
                        && is_string($user->school_id)
                        && $user->school_id !== ''
                        && Hash::check($user->school_id, $user->password);
                    $usesSeedPassword = in_array($user->username, ['testuser', 'psds', 'superadmin'], true)
                        && Hash::check($user->username === 'testuser' ? 'password123' : 'superadmin123', $user->password);

                    if ($usesPublicSchoolId || $usesSeedPassword) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['must_change_password' => true]);
                    }
                }
            }, 'users.id', 'id');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
