<?php

namespace Tests\Feature;

use App\Models\BranchAnnualTarget;
use App\Models\BranchMonthlyTarget;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchTargetsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_targets_report_page_loads_for_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));

        $branch = Location::create([
            'code' => 'BR-TGT-R',
            'name' => 'Report Target Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        BranchAnnualTarget::create([
            'location_id' => $branch->id,
            'year' => 2026,
            'sales_target' => 100000,
            'collection_target' => 80000,
        ]);

        BranchMonthlyTarget::create([
            'location_id' => $branch->id,
            'year' => 2026,
            'month' => 8,
            'sales_target' => 10000,
            'collection_target' => 8000,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('reports.branch-targets', ['year' => 2026, 'month' => 8]))
            ->assertOk()
            ->assertSee('Branch targets', false)
            ->assertSee('Report Target Branch', false)
            ->assertSee('Collection actual / target', false);
    }

    public function test_branch_targets_report_is_listed_on_reports_hub(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Branch targets', false);
    }
}
