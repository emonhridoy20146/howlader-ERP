<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Customer;
use App\Models\Location;
use App\Models\SerialItem;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceJobController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {}

    /** @var list<string> */
    private const SERIAL_STATUSES_SERVICEABLE = [
        'in_stock', 'reserved', 'sold', 'returned', 'damaged', 'under_service',
    ];

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'type' => (string) $request->input('type', ''),
        ];

        $jobs = ServiceJob::query()
            ->with(['serialItem.product', 'branch', 'customer', 'assignee'])
            ->when($this->contextLocationId() !== null, fn ($query) => $query->where('branch_id', $this->contextLocationId()))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $like = '%'.addcslashes($keyword, '%_\\').'%';
                $query->where(function ($subQuery) use ($like, $keyword): void {
                    $subQuery->where('job_number', 'like', $like)
                        ->orWhere('complaint', 'like', $like)
                        ->orWhereHas('serialItem', function ($q) use ($like, $keyword): void {
                            $q->where('serial_number', 'like', $like)
                                ->orWhere('manufacturer_serial', 'like', $like);
                            if (ctype_digit($keyword)) {
                                $q->orWhere('id', (int) $keyword);
                            }
                        })
                        ->orWhereHas('serialItem.product', fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like));
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->latest()
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpiScope = ServiceJob::query()
            ->when($this->contextLocationId() !== null, fn ($query) => $query->where('branch_id', $this->contextLocationId()));

        $kpis = [
            'total' => (clone $kpiScope)->count(),
            'in_progress' => (clone $kpiScope)->whereIn('status', ['intake', 'diagnosing', 'awaiting_parts', 'repairing'])->count(),
            'done' => (clone $kpiScope)->where('status', 'done')->count(),
            'returned' => (clone $kpiScope)->where('status', 'returned_to_customer')->count(),
            'filtered' => $jobs->total(),
        ];

        return view('service-jobs.index', compact('jobs', 'filters', 'kpis'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ServiceJob::class);

        $locations = $this->locationsForForms();
        $customers = Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_code']);
        $assignees = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'full_name']);

        $defaultBranchId = $request->old('branch_id', $this->contextLocationId());

        return view('service-jobs.create', compact('locations', 'customers', 'assignees', 'defaultBranchId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ServiceJob::class);

        $accessible = $request->user()->accessibleLocationIds();

        $data = $request->validate([
            'serial_item_id' => ['required', 'integer', 'exists:serial_items,id'],
            'branch_id' => ['required', 'integer', Rule::in($accessible)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'type' => ['required', 'in:warranty_repair,paid_repair,inspection'],
            'status' => ['required', 'in:intake,diagnosing,awaiting_parts,repairing,done,returned_to_customer,unrepairable'],
            'complaint' => ['required', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'work_done' => ['nullable', 'string'],
            'repair_cost' => ['nullable', 'numeric', 'min:0'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'intake_date' => ['required', 'date'],
            'promised_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date'],
        ]);

        $this->assertSerialEligibleForBranch((int) $data['serial_item_id'], (int) $data['branch_id']);

        $job = DB::transaction(function () use ($data): ServiceJob {
            return ServiceJob::create([
                ...$data,
                'repair_cost' => $data['repair_cost'] ?? 0,
                'job_number' => $this->generateJobNumber(Carbon::parse((string) $data['intake_date'])),
            ]);
        });

        return redirect()->route('service-jobs.show', $job)->with('status', 'Service job created.');
    }

    public function show(ServiceJob $service_job): View
    {
        $this->authorize('view', $service_job);
        $this->abortIfJobOutsideContext($service_job);

        $service_job->load(['serialItem.product', 'branch', 'customer', 'assignee']);

        return view('service-jobs.show', ['job' => $service_job]);
    }

    public function edit(ServiceJob $service_job): View
    {
        $this->authorize('update', $service_job);
        $this->abortIfJobOutsideContext($service_job);

        $service_job->load(['serialItem.product', 'branch', 'customer', 'assignee']);
        $locations = $this->locationsForForms();
        $customers = Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_code']);
        $assignees = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'full_name']);

        return view('service-jobs.edit', compact('service_job', 'locations', 'customers', 'assignees'));
    }

    public function update(Request $request, ServiceJob $service_job): RedirectResponse
    {
        $this->authorize('update', $service_job);
        $this->abortIfJobOutsideContext($service_job);

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'type' => ['required', 'in:warranty_repair,paid_repair,inspection'],
            'status' => ['required', 'in:intake,diagnosing,awaiting_parts,repairing,done,returned_to_customer,unrepairable'],
            'complaint' => ['required', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'work_done' => ['nullable', 'string'],
            'repair_cost' => ['nullable', 'numeric', 'min:0'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'intake_date' => ['required', 'date'],
            'promised_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date'],
        ]);

        $service_job->update([
            ...$data,
            'repair_cost' => $data['repair_cost'] ?? $service_job->repair_cost,
        ]);

        return redirect()->route('service-jobs.show', $service_job)->with('status', 'Service job updated.');
    }

    public function serialSearch(Request $request): JsonResponse
    {
        $accessible = $request->user()->accessibleLocationIds();

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($accessible)],
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $keyword = trim($data['q']);
        $like = '%'.addcslashes($keyword, '%_\\').'%';

        $rows = SerialItem::query()
            ->with(['product:id,name,sku'])
            ->where('current_location_id', $data['branch_id'])
            ->whereIn('status', self::SERIAL_STATUSES_SERVICEABLE)
            ->where(function ($q) use ($like, $keyword): void {
                $q->where('serial_number', 'like', $like)
                    ->orWhere('manufacturer_serial', 'like', $like);
                if (ctype_digit($keyword)) {
                    $q->orWhere('id', (int) $keyword);
                }
            })
            ->orderBy('serial_number')
            ->limit(40)
            ->get(['id', 'serial_number', 'manufacturer_serial', 'status', 'product_id']);

        return response()->json($rows->map(function (SerialItem $row): array {
            $product = $row->product;
            $label = $row->serial_number.($product ? ' · '.$product->name : '');

            return [
                'id' => $row->id,
                'label' => $label,
                'serial_number' => $row->serial_number,
                'manufacturer_serial' => $row->manufacturer_serial,
                'status' => $row->status,
                'product_name' => $product?->name,
                'product_sku' => $product?->sku,
            ];
        }));
    }

    /**
     * @return Collection<int, Location>
     */
    private function locationsForForms()
    {
        $ids = $this->accessibleLocationIds();

        return Location::query()
            ->where('is_active', true)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function generateJobNumber(Carbon $intakeDate): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = $this->documentNumbers->next(
                DocumentNumberGenerator::TYPE_SERVICE_JOB,
                'SVC',
                $intakeDate,
                'Ymd',
                5,
            );
            if (! ServiceJob::query()->where('job_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $this->documentNumbers->next(
            DocumentNumberGenerator::TYPE_SERVICE_JOB,
            'SVC',
            now(),
            'YmdHis',
            4,
        );
    }

    private function assertSerialEligibleForBranch(int $serialItemId, int $branchId): void
    {
        $serial = SerialItem::query()->find($serialItemId);
        if (! $serial) {
            throw ValidationException::withMessages(['serial_item_id' => 'Invalid serial.']);
        }
        if ((int) $serial->current_location_id !== $branchId) {
            throw ValidationException::withMessages(['serial_item_id' => 'That serial is not recorded at the selected branch.']);
        }
        if (! in_array($serial->status, self::SERIAL_STATUSES_SERVICEABLE, true)) {
            throw ValidationException::withMessages(['serial_item_id' => 'That serial cannot be used for a service job in its current state.']);
        }
    }

    private function abortIfJobOutsideContext(ServiceJob $job): void
    {
        if ($this->contextLocationId() !== null && (int) $job->branch_id !== (int) $this->contextLocationId()) {
            abort(404);
        }
    }
}
