<?php

namespace App\Http\Controllers\Api\Field;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Small reference lists for the field integration: health, branches, employees, payment methods. */
class FieldDirectoryController extends Controller
{
    public function health(): JsonResponse
    {
        $db = 'ok';
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $db = 'down';
        }

        return response()->json([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'time' => now()->toIso8601String(),
            'version' => 'field-v1',
            'timezone' => (string) config('app.timezone'),
        ], $db === 'ok' ? 200 : 503);
    }

    public function branches(Request $request): JsonResponse
    {
        $rows = Location::query()
            ->when($request->query('type', 'branch') !== 'all', fn ($q) => $q->where('type', 'branch'))
            ->orderBy('id')
            ->get()
            ->map(fn (Location $l) => [
                'id' => (int) $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'type' => $l->type,
                'address' => $l->address,
                'phone' => $l->phone,
                'manager_name' => $l->manager_name,
                'is_active' => (bool) $l->is_active,
                'updated_at' => $l->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows->values()]);
    }

    public function employees(): JsonResponse
    {
        $rows = Employee::query()->with('locations:id')->orderBy('id')->get()
            ->map(fn (Employee $e) => [
                'id' => (int) $e->id,
                'employee_code' => $e->employee_code,
                'name' => $e->fullName(),
                'phone' => $e->phone,
                'user_id' => $e->user_id !== null ? (int) $e->user_id : null,
                'branch_ids' => $e->locations->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'is_active' => (bool) $e->is_active && $e->employment_status === 'active',
                'updated_at' => $e->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows->values()]);
    }

    /** Methods each branch can take (the office payment form shows the same list). */
    public function paymentMethods(Request $request): JsonResponse
    {
        $branchIds = $request->filled('branch_id')
            ? [(int) $request->query('branch_id')]
            : PaymentMethodWalletMap::query()->distinct()->pluck('location_id')->map(fn ($id) => (int) $id)->all();

        $rows = [];
        foreach ($branchIds as $branchId) {
            foreach (PaymentMethod::availableForSalesAtLocation($branchId) as $m) {
                $rows[] = [
                    'branch_id' => $branchId,
                    'id' => (int) $m->id,
                    'code' => $m->code,
                    'name' => $m->name,
                    'type' => $m->type,
                    'mobile_provider' => $m->mobile_provider,
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }
}
