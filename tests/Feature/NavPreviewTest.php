<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump_dashboard_html(): void
    {
        $location = Location::query()->create([
            'code' => 'LOC-ADM',
            'name' => 'Admin branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        file_put_contents(public_path('__nav-preview.html'), $response->getContent());
        $this->assertTrue(true);
    }
}
