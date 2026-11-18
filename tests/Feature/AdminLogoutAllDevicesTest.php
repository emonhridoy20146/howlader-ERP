<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminLogoutAllDevicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_admin_logout_removes_sessions_on_all_devices(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true, 'remember_token' => 'old-token']);
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);

        $this->seedSession('admin-device-b', $admin->id);
        $this->seedSession('admin-device-c', $admin->id);
        $this->seedSession('sales-device', $other->id);

        $this->actingAs($admin)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertNotSame('old-token', $admin->fresh()->remember_token);
    }

    public function test_non_admin_logout_keeps_their_other_device_sessions(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);

        $this->seedSession('sales-device-b', $sales->id);

        $this->actingAs($sales)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertTrue(DB::table('sessions')->where('id', 'sales-device-b')->exists());
    }

    private function seedSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);
    }
}
