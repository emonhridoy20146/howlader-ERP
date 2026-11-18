<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferDeletePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_user_without_delete_permission_cannot_destroy_transfer(): void
    {
        [$user, $transfer] = $this->fixture(withDelete: false);

        $this->actingAs($user)
            ->delete(route('transfers.destroy', $transfer))
            ->assertForbidden();

        $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id]);
    }

    public function test_user_with_delete_permission_can_destroy_draft_transfer(): void
    {
        [$user, $transfer] = $this->fixture(withDelete: true);

        $this->actingAs($user)
            ->delete(route('transfers.destroy', $transfer))
            ->assertRedirect(route('transfers.index'));

        $this->assertDatabaseMissing('stock_transfers', ['id' => $transfer->id]);
    }

    /**
     * @return array{0: User, 1: StockTransfer}
     */
    private function fixture(bool $withDelete): array
    {
        $source = Location::create([
            'code' => 'TR-SRC',
            'name' => 'Transfer Source',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $destination = Location::create([
            'code' => 'TR-DST',
            'name' => 'Transfer Destination',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $role = Role::create([
            'name' => 'Transfer clerk',
            'slug' => 'transfer-clerk-'.random_int(1000, 9999),
        ]);

        $permissionSlugs = [
            'stock-transfer.view-any',
            'stock-transfer.view',
            'stock-transfer.create',
            'stock-transfer.update',
        ];

        if ($withDelete) {
            $permissionSlugs[] = 'stock-transfer.delete';
        }

        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')
        );

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $source->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$source->id, $destination->id]);

        $transfer = StockTransfer::create([
            'transfer_number' => 'TRF-DEL-001',
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'status' => 'draft',
            'requested_at' => now(),
            'requested_by' => $user->id,
        ]);

        return [$user, $transfer];
    }
}
