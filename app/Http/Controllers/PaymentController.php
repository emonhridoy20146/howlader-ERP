<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateException;
use App\Http\Requests\StoreInvoiceHistoricalPaymentRequest;
use App\Http\Requests\StoreInvoiceInboundPaymentRequest;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\SalesInvoice;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(
        StoreInvoiceInboundPaymentRequest $request,
        SalesInvoice $invoice,
        PaymentService $paymentService
    ): RedirectResponse {
        if (! $invoice->isPayable() || $invoice->amount_due <= 0) {
            return back()->with('status', 'Payment is unavailable for the current invoice state.');
        }

        try {
            $paymentService->recordAndAllocate(
                $invoice,
                $request->paymentServicePayload(),
                (int) $request->user()->id,
            );
        } catch (\Throwable $e) {
            return back()->with('status', $e->getMessage())->withInput();
        }

        if ($request->boolean('print_after')) {
            return redirect()->route('sales.invoices.print', ['invoice' => $invoice->id])
                ->with('status', 'Payment recorded.');
        }

        return back()->with('status', 'Payment recorded.');
    }

    public function storeHistorical(
        StoreInvoiceHistoricalPaymentRequest $request,
        SalesInvoice $invoice,
        PaymentService $paymentService,
    ): RedirectResponse {
        if (! $invoice->allowsAdminHistoricalPayment()) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->with('error', $invoice->historicalPaymentUnavailableReason()
                    ?? 'Historical payment is unavailable for the current invoice state.');
        }

        $skipWallet = $request->boolean('skip_wallet_gl');

        try {
            $paymentService->recordAndAllocate(
                $invoice,
                $request->paymentServicePayload(),
                (int) $request->user()->id,
            );
        } catch (\Throwable $e) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->withErrors(['historical_payment' => $e->getMessage()])
                ->withInput();
        }

        $message = $skipWallet
            ? 'Historical transaction recorded. Invoice paid totals unchanged; wallet balance not updated.'
            : 'Historical transaction recorded. Invoice paid totals unchanged; wallet balance updated.';

        return redirect()->route('sales.invoices.show', $invoice)
            ->with('status', $message);
    }

    public function destroyAllocation(
        SalesInvoice $invoice,
        PaymentAllocation $allocation,
        PaymentService $paymentService,
    ): RedirectResponse {
        Gate::authorize('delete-payment-allocation');

        try {
            $paymentService->removeAllocationFromInvoice(
                $invoice,
                $allocation,
                (int) request()->user()->id,
            );
        } catch (InvalidStateException $e) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->withErrors(['payment' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->with('status', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to remove payment.');
        }

        return redirect()->route('sales.invoices.show', $invoice)
            ->with('status', 'Payment removed from invoice.');
    }

    public function updatePaymentMethod(
        Request $request,
        SalesInvoice $invoice,
        Payment $payment,
        PaymentService $paymentService,
    ): RedirectResponse {
        Gate::authorize('update-payment-method');

        $this->authorize('view', $invoice);

        $data = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
        ]);

        $methodId = (int) $data['payment_method_id'];
        $available = PaymentMethod::availableForSalesAtLocation((int) $invoice->branch_id);
        if (! $available->pluck('id')->contains($methodId)) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'Selected payment method is not available for this invoice branch.',
            ]);
        }

        try {
            $paymentService->changePaymentMethod(
                $invoice,
                $payment,
                $methodId,
                (int) $request->user()->id,
            );
        } catch (InvalidStateException $e) {
            return back()->withErrors(['payment_method_id' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->with('status', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to change payment method.');
        }

        return back()->with('status', 'Payment method updated.');
    }
}
