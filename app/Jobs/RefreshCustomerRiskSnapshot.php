<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CustomerRiskService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshCustomerRiskSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $customerId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->customerId;
    }

    public function handle(CustomerRiskService $service): void
    {
        $customer = Customer::find($this->customerId);
        if (! $customer) {
            return;
        }

        try {
            $service->refreshSnapshot($customer);
        } catch (Throwable $e) {
            // A failed risk refresh must never break the payment or installment flow that triggered it.
            report($e);
        }
    }
}
