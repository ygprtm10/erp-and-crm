<?php

declare(strict_types=1);

namespace Modules\Platform\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the global Super Admin account idempotently for /backoffice management.
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** Resolve existing user by email or create a new record without mass-assigning is_super_admin. */
        $admin = User::query()->firstOrNew(['email' => 'admin@test.com']);
        $admin->name = 'Super Admin';
        $admin->phone = '081100000001';
        $admin->email_verified_at ??= now();
        $admin->password = Hash::make('password');
        $admin->is_super_admin = true;
        $admin->save();
    }
}
