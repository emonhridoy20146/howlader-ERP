<?php

namespace App\Http\Controllers\Api\Field;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FieldPaymentRequest;
use App\Models\Payment;
use App\Services\FieldApi\FieldApiError;
use App\Services\FieldApi\FieldCustomerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/** Customers with their invoices, installment schedules and payments, for delta sync. */
class FieldCustomerController extends Controller
{
    public function __construct(private readonly FieldCustomerPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'updated_since' => ['nullable', 'date'],
            'scope' => ['nullable', 'in:receivables,all'],
            'invoices' => ['nullable', 'in:receivables,all'],
            'page' => ['nullable', 'array'],
            'page.size' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('field_api.page_size_max', 200)],
            'page.cursor' => ['nullable', 'string', 'max:200'],
        ]);
        if ($v->fails()) {
            return (new FieldApiError('VALIDATION_FAILED', 'Invalid query.', 422, $v->errors()->toArray()))->toResponse();
        }

        $size = (int) ($request->input('page.size') ?? config('field_api.page_size_default', 100));
        $afterId = self::decodeCursor($request->input('page.cursor'));
        $serverTime = now()->toIso8601String();

        $q = $this->presenter->baseQuery((string) $request->query('scope', 'receivables'));
        if ($request->filled('branch_id')) {
            $this->presenter->filterBranch($q, (int) $request->query('branch_id'));
        }
        if ($request->filled('updated_since')) {
            $this->presenter->filterChangedSince($q, Carbon::parse((string) $request->query('updated_since')));
        }
        $customers = $q->where('customers.id', '>', $afterId)->orderBy('customers.id')->limit($size + 1)->get();

        $hasMore = $customers->count() > $size;
        $page = $customers->take($size);

        return response()->json([
            'data' => $this->presenter->present($page, $request->query('invoices') === 'all'),
            'meta' => [
                'next_cursor' => $hasMore ? self::encodeCursor((int) $page->last()->id) : null,
                'server_time' => $serverTime,
                'page_size' => $size,
            ],
        ]);
    }

    public function show(Request $request, int $customerId): JsonResponse
    {
        $customer = $this->presenter->baseQuery('all')->where('customers.id', $customerId)->first();
        if (! $customer instanceof Customer) {
            return (new FieldApiError('CUSTOMER_NOT_FOUND', 'Customer not found.', 404))->toResponse();
        }

        return response()->json(['data' => $this->presenter->present(collect([$customer]), $request->query('invoices') === 'all')[0]]);
    }

    /** Full inbound payment history, newest first (cursor = last payment id seen). */
    public function payments(Request $request, int $customerId): JsonResponse
    {
        if (! Customer::query()->whereKey($customerId)->exists()) {
            return (new FieldApiError('CUSTOMER_NOT_FOUND', 'Customer not found.', 404))->toResponse();
        }

        $size = min((int) ($request->input('page.size') ?? 100), (int) config('field_api.page_size_max', 200));
        $beforeId = self::decodeCursor($request->input('page.cursor'));

        $rows = Payment::query()
            ->where('customer_id', $customerId)
            ->where('direction', 'inbound')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('payment_date', '>=', (string) $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('payment_date', '<=', (string) $request->query('to')))
            ->when($beforeId > 0, fn ($q) => $q->where('id', '<', $beforeId))
            ->with(['paymentAllocations', 'paymentMethodRef:id,name,type', 'referrerEmployee:id,first_name,last_name'])
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        $hasMore = $rows->count() > $size;
        $page = $rows->take($size);
        $keys = FieldPaymentRequest::query()->whereIn('payment_id', $page->pluck('id'))->pluck('idempotency_key', 'payment_id')
            ->mapWithKeys(fn ($k, $id) => [(int) $id => (string) $k])->all();

        return response()->json([
            'data' => $page->map(fn (Payment $p) => $this->presenter->paymentRow($p, $keys))->values(),
            'meta' => ['next_cursor' => $hasMore ? self::encodeCursor((int) $page->last()->id) : null],
        ]);
    }

    private static function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode('id:'.$id), '+/', '-_'), '=');
    }

    private static function decodeCursor(mixed $cursor): int
    {
        if (! is_string($cursor) || $cursor === '') {
            return 0;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        return is_string($raw) && str_starts_with($raw, 'id:') ? max(0, (int) substr($raw, 3)) : 0;
    }
}
