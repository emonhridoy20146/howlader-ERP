<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Http\Requests\StoreInvoiceInboundPaymentRequest;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PaymentRegisterController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(Request $request): View
    {
        $filters = [
            'invoice_q' => trim((string) $request->input('invoice_q', '')),
            'customer_contact_q' => trim((string) $request->input('customer_contact_q', '')),
            'customer_id' => $request->input('customer_id'),
            'sale_type' => (string) $request->input('sale_type', ''),
            'created_by' => $request->input('created_by'),
        ];

        $payableScope = SalesInvoice::query()
            ->with(['customer', 'branch', 'creator', 'referrer', 'installmentPlan.schedules'])
            ->whereIn('status', ['posted', 'active'])
            ->where('payment_status', '!=', 'paid')
            ->where('amount_due', '>', 0);

        $this->scopeContextBranch($payableScope);

        $payableScope
            ->when($filters['invoice_q'] !== '', function ($query) use ($filters): void {
                $term = '%'.addcslashes($filters['invoice_q'], '%_\\').'%';
                $query->where('invoice_number', 'like', $term);
            })
            ->when($filters['customer_contact_q'] !== '', function ($query) use ($filters): void {
                $term = '%'.addcslashes($filters['customer_contact_q'], '%_\\').'%';
                $query->whereHas('customer', function ($customerQuery) use ($term): void {
                    $customerQuery->where('phone', 'like', $term)
                        ->orWhere('customer_code', 'like', $term)
                        ->orWhere('phone_numbers', 'like', $term);
                });
            })
            ->when($filters['customer_id'] !== null && $filters['customer_id'] !== '', fn ($q) => $q->where('customer_id', (int) $filters['customer_id']))
            ->when($filters['sale_type'] !== '', fn ($q) => $q->where('sale_type', $filters['sale_type']))
            ->when($filters['created_by'] !== null && $filters['created_by'] !== '', fn ($q) => $q->where('created_by', (int) $filters['created_by']));

        $invoices = (clone $payableScope)
            ->orderBy('invoice_date', 'desc')
            ->paginate($this->resolvePerPage($request, 20))
            ->withQueryString();

        $selectedInvoice = null;
        $paymentMethods = collect();
        $open = $request->query('invoice');
        if (is_string($open) && ctype_digit($open)) {
            $candidate = SalesInvoice::query()
                ->with([
                    'customer',
                    'branch',
                    'creator',
                    'referrer',
                    'installmentPlan.schedules',
                    'payments.paymentMethodRef',
                    'payments.referrerEmployee',
                    'debtWriteoffs.actor',
                ])
                ->whereKey((int) $open)
                ->first();
            if ($candidate && Gate::allows('view', $candidate)) {
                $branchOk = true;
                if ($this->contextLocationId() !== null) {
                    $branchOk = (int) $candidate->branch_id === $this->contextLocationId();
                } elseif (($ids = $this->accessibleLocationIds()) !== []) {
                    $branchOk = in_array((int) $candidate->branch_id, $ids, true);
                }
                if ($branchOk && $candidate->isPayable() && $candidate->amount_due > 0) {
                    $selectedInvoice = $candidate;
                    $paymentMethods = PaymentMethod::availableForSalesAtLocation((int) $candidate->branch_id);
                }
            }
        }

        $customers = Customer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'customer_code', 'phone', 'phone_numbers']);

        $creatorIds = SalesInvoice::query()->whereNotNull('created_by')->distinct()->pluck('created_by');
        $creators = User::query()
            ->whereIn('id', $creatorIds)
            ->orderBy('name')
            ->get(['id', 'name', 'full_name']);

        $referrerEmployees = Employee::query()
            ->where('is_active', true)
            ->orderBy('employee_code')
            ->orderBy('first_name')
            ->get(['id', 'employee_code', 'first_name', 'last_name']);

        return view('payment-register.index', compact(
            'invoices',
            'filters',
            'customers',
            'creators',
            'selectedInvoice',
            'paymentMethods',
            'referrerEmployees',
        ));
    }

    public function store(
        StoreInvoiceInboundPaymentRequest $request,
        SalesInvoice $invoice,
        PaymentService $paymentService,
    ): RedirectResponse {
        if (! $invoice->isPayable() || $invoice->amount_due <= 0) {
            return redirect()
                ->route('payment-register.index', ['invoice' => $invoice->id])
                ->with('status', 'Payment is unavailable for the current invoice state.');
        }

        try {
            $paymentService->recordAndAllocate(
                $invoice,
                $request->paymentServicePayload(),
                (int) $request->user()->id,
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('payment-register.index', ['invoice' => $invoice->id])
                ->with('swal', ['icon' => 'error', 'title' => 'Payment Failed', 'text' => $e->getMessage()])
                ->withInput();
        }

        if ($request->boolean('print_after')) {
            return redirect()
                ->route('sales.invoices.print', ['invoice' => $invoice->id])
                ->with('status', 'Payment recorded.');
        }

        $invoice->refresh();

        return redirect()
            ->route('payment-register.index')
            ->with('flash_swal_success', __('Payment recorded.'));
    }
}
