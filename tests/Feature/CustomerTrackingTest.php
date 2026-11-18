<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_user_with_tracking_permission_can_save_location_and_images(): void
    {
        Storage::fake('public');

        $location = Location::create([
            'code' => 'LOC-TRK',
            'name' => 'Tracking branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $role = Role::create([
            'name' => 'Tracking staff',
            'slug' => 'tracking-staff-'.random_int(1000, 9999),
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'customer.view-any',
                'customer.view',
                'customer.tracking.view',
                'customer.tracking.update',
            ])->pluck('id')
        );

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);

        $customer = Customer::query()->create([
            'customer_code' => 'TRK-001',
            'name' => 'Tracking Customer',
            'phone' => '01700000001',
            'branch_id' => $location->id,
            'address' => 'Old general address',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put(route('customer-tracking.update', $customer), [
            'latitude' => 23.81030000,
            'longitude' => 90.41250000,
            'detailed_address' => 'House 12, Road 4, Block B',
            'location_instructions' => 'Blue gate, third floor',
            'house_image' => UploadedFile::fake()->image('house.jpg'),
            'location_image' => UploadedFile::fake()->image('landmark.jpg'),
        ]);

        $response->assertRedirect(route('customer-tracking.show', $customer));
        $customer->refresh();

        $this->assertSame(23.8103, (float) $customer->latitude);
        $this->assertSame(90.4125, (float) $customer->longitude);
        $this->assertSame('House 12, Road 4, Block B', $customer->detailed_address);
        $this->assertSame('Blue gate, third floor', $customer->location_instructions);
        $this->assertNotNull($customer->house_image_path);
        $this->assertNotNull($customer->location_image_path);
        Storage::disk('public')->assertExists($customer->house_image_path);
        Storage::disk('public')->assertExists($customer->location_image_path);

        $this->actingAs($user)->get(route('customer-tracking.index', ['q' => 'TRK-001']))
            ->assertOk()
            ->assertSee('Tracking Customer', false)
            ->assertSee('Set', false);

        $this->actingAs($user)->get(route('customer-tracking.show', $customer))
            ->assertOk()
            ->assertSee('Customer location', false)
            ->assertSee('House image', false)
            ->assertSee('Blue gate, third floor', false);

        $this->actingAs($user)->get(route('customer-tracking.edit', $customer))
            ->assertOk()
            ->assertSee('Edit tracking', false)
            ->assertSee('Save tracking', false);

        $this->actingAs($user)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Location tracking', false)
            ->assertSee('View tracking', false)
            ->assertSee('Edit tracking', false);
    }

    public function test_user_without_tracking_permission_is_forbidden(): void
    {
        $location = Location::create([
            'code' => 'LOC-NO',
            'name' => 'No access branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::query()->create([
            'customer_code' => 'TRK-002',
            'name' => 'Hidden Customer',
            'phone' => '01700000002',
            'branch_id' => $location->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('customer-tracking.index'))->assertForbidden();
        $this->actingAs($user)->get(route('customer-tracking.show', $customer))->assertForbidden();
    }
}
