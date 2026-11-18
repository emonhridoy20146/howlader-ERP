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

class StockTransferDualApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_admin_can_assign_branch_manager_to_location(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $location = Location::create([
            'code' => 'BR-MGR',
            'name' => 'Managed Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('locations.update', $location), [
                'code' => $location->code,
                'name' => $location->name,
                'type' => 'branch',
                'manager_user_id' => $manager->id,
                'is_active' => 1,
            ])
            ->assertRedirect(route('locations.index'));

        $this->assertSame($manager->id, $location->fresh()->manager_user_id);
    }

    public function test_transfer_requires_both_branch_managers_to_approve(): void
    {
        [$source, $destination, $sourceManager, $destinationManager, $transfer] = $this->dualApprovalFixture();

        $this->actingAs($sourceManager)
            ->post(route('transfers.approve-source', $transfer))
            ->assertRedirect();

        $transfer->refresh();
        $this->assertSame('draft', $transfer->status);
        $this->assertNotNull($transfer->source_approved_at);
        $this->assertNull($transfer->destination_approved_at);

        $this->actingAs($destinationManager)
            ->post(route('transfers.approve-destination', $transfer))
            ->assertRedirect();

        $transfer->refresh();
        $this->assertSame('approved', $transfer->status);
        $this->assertNotNull($transfer->source_approved_at);
        $this->assertNotNull($transfer->destination_approved_at);
        $this->assertNotNull($transfer->approved_at);
    }

    public function test_source_manager_cannot_approve_destination(): void
    {
        [, , $sourceManager, , $transfer] = $this->dualApprovalFixture();

        $this->actingAs($sourceManager)
            ->post(route('transfers.approve-destination', $transfer))
            ->assertForbidden();
    }

    public function test_destination_manager_cannot_approve_source(): void
    {
        [, , , $destinationManager, $transfer] = $this->dualApprovalFixture();

        $this->actingAs($destinationManager)
            ->post(route('transfers.approve-source', $transfer))
            ->assertForbidden();
    }

    public function test_non_manager_with_branch_access_cannot_approve_when_manager_is_set(): void
    {
        [$source, $destination, , , $transfer] = $this->dualApprovalFixture();
        $otherUser = $this->userWithTransferApprove($source);

        $this->actingAs($otherUser)
            ->post(route('transfers.approve-source', $transfer))
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->post(route('transfers.approve-destination', $transfer))
            ->assertForbidden();
    }

    public function test_approver_with_branch_access_can_approve_when_no_manager_assigned(): void
    {
        $source = Location::create([
            'code' => 'SRC-FB',
            'name' => 'Source Fallback',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $destination = Location::create([
            'code' => 'DST-FB',
            'name' => 'Destination Fallback',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $sourceApprover = $this->userWithTransferApprove($source);
        $destinationApprover = $this->userWithTransferApprove($destination);

        $transfer = StockTransfer::create([
            'transfer_number' => 'TRF-FB-001',
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'status' => 'draft',
            'requested_by' => $sourceApprover->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($sourceApprover)
            ->post(route('transfers.approve-source', $transfer))
            ->assertRedirect();

        $this->actingAs($destinationApprover)
            ->post(route('transfers.approve-destination', $transfer))
            ->assertRedirect();

        $this->assertSame('approved', $transfer->fresh()->status);
    }

    /**
     * @return array{0: Location, 1: Location, 2: User, 3: User, 4: StockTransfer}
     */
    private function dualApprovalFixture(): array
    {
        $source = Location::create([
            'code' => 'SRC-DA',
            'name' => 'Source Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $destination = Location::create([
            'code' => 'DST-DA',
            'name' => 'Destination Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $sourceManager = $this->userWithTransferApprove($source);
        $destinationManager = $this->userWithTransferApprove($destination);

        $source->update(['manager_user_id' => $sourceManager->id]);
        $destination->update(['manager_user_id' => $destinationManager->id]);

        $transfer = StockTransfer::create([
            'transfer_number' => 'TRF-DA-001',
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'status' => 'draft',
            'requested_by' => $sourceManager->id,
            'requested_at' => now(),
        ]);

        return [$source, $destination, $sourceManager, $destinationManager, $transfer];
    }

    private function userWithTransferApprove(Location $location): User
    {
        $role = Role::query()->create([
            'slug' => 'tr-'.random_int(1000, 9999),
            'name' => 'Transfer approver',
        ]);

        $permissionIds = Permission::query()
            ->whereIn('slug', [
                'stock-transfer.approve',
                'stock-transfer.view',
                'stock-transfer.view-any',
            ])
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$location->id]);

        return $user;
    }
}
