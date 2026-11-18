<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssignCustomerBranchFromLastInvoiceCommand extends Command
{
    protected $signature = 'customers:assign-branch-from-last-invoice
                            {--dry-run : Show branch changes without writing}
                            {--customer= : Limit to a single customers.id}
                            {--only-unassigned : Only update customers with no branch assigned}
                            {--all-statuses : Consider all invoice statuses, not just posted/active}';

    protected $description = 'Set each customer branch_id from the branch on their most recent sales invoice';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyUnassigned = (bool) $this->option('only-unassigned');
        $allStatuses = (bool) $this->option('all-statuses');
        $customerOpt = $this->option('customer');
        $onlyCustomerId = is_numeric($customerOpt) ? (int) $customerOpt : null;

        if ($onlyCustomerId !== null && ! Customer::query()->whereKey($onlyCustomerId)->exists()) {
            $this->error('Customer not found: '.$onlyCustomerId);

            return self::FAILURE;
        }

        $lastInvoices = $this->lastInvoiceRows($onlyCustomerId, $allStatuses);

        if ($lastInvoices->isEmpty()) {
            $this->info('No qualifying invoices found.');

            return self::SUCCESS;
        }

        $customerIds = $lastInvoices->pluck('customer_id')->map(fn ($id) => (int) $id)->all();
        $customers = Customer::query()
            ->whereIn('id', $customerIds)
            ->when($onlyUnassigned, fn (Builder $query) => $query->whereNull('branch_id'))
            ->get(['id', 'customer_code', 'name', 'branch_id']);

        $branchNames = Location::query()
            ->whereIn('id', $lastInvoices->pluck('branch_id')->filter()->map(fn ($id) => (int) $id)->unique()->all())
            ->pluck('name', 'id');

        $changes = [];

        foreach ($customers as $customer) {
            $invoice = $lastInvoices->get((int) $customer->id);
            if ($invoice === null) {
                continue;
            }

            $newBranchId = (int) $invoice->branch_id;
            if ($newBranchId <= 0) {
                continue;
            }

            $currentBranchId = $customer->branch_id !== null ? (int) $customer->branch_id : null;
            if ($currentBranchId === $newBranchId) {
                continue;
            }

            $changes[] = [
                'id' => (int) $customer->id,
                'code' => (string) $customer->customer_code,
                'name' => (string) $customer->name,
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date instanceof \DateTimeInterface
                    ? $invoice->invoice_date->format('Y-m-d')
                    : (string) $invoice->invoice_date,
                'before_branch_id' => $currentBranchId,
                'before_branch_name' => $currentBranchId ? (string) ($branchNames->get($currentBranchId) ?? 'Branch #'.$currentBranchId) : '—',
                'after_branch_id' => $newBranchId,
                'after_branch_name' => (string) ($branchNames->get($newBranchId) ?? 'Branch #'.$newBranchId),
            ];
        }

        if (! $dryRun && $changes !== []) {
            DB::transaction(function () use ($changes): void {
                foreach ($changes as $row) {
                    Customer::query()->whereKey($row['id'])->update([
                        'branch_id' => $row['after_branch_id'],
                    ]);
                }
            });
        }

        $skippedAlreadyCorrect = $customers->count() - count($changes);
        $customersWithoutInvoice = $onlyCustomerId !== null && $lastInvoices->isEmpty() ? 1 : 0;

        $this->info('Customers with a last invoice: '.$lastInvoices->count());
        $this->info('Customers considered'.($onlyUnassigned ? ' (unassigned only)' : '').': '.$customers->count());
        $this->info('Customers updated: '.($dryRun ? '0 (dry run)' : count($changes)));
        $this->info('Customers already on last-invoice branch: '.max(0, $skippedAlreadyCorrect));

        if ($changes !== [] && $this->output->isVerbose()) {
            $this->table(
                ['ID', 'Code', 'Customer', 'Last invoice', 'Date', 'From branch', 'To branch'],
                collect($changes)->map(fn (array $row): array => [
                    $row['id'],
                    $row['code'],
                    $row['name'],
                    $row['invoice_number'],
                    $row['invoice_date'],
                    $row['before_branch_name'],
                    $row['after_branch_name'],
                ])->all(),
            );
        }

        if ($dryRun) {
            $this->comment($changes !== []
                ? 'Dry run — no customer rows updated. Run without --dry-run to apply.'
                : 'Dry run — no branch changes required.');
        } elseif ($changes !== []) {
            $this->info('Updated '.count($changes).' customer branch assignment(s).');
        } else {
            $this->info('No updates required.');
        }

        if ($customersWithoutInvoice > 0) {
            $this->warn('Customer '.$onlyCustomerId.' has no qualifying invoice.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object{id: int, customer_id: int, branch_id: int, invoice_number: string, invoice_date: string}>
     */
    private function lastInvoiceRows(?int $onlyCustomerId, bool $allStatuses): Collection
    {
        $query = SalesInvoice::query()
            ->select([
                'sales_invoices.id',
                'sales_invoices.customer_id',
                'sales_invoices.branch_id',
                'sales_invoices.invoice_number',
                'sales_invoices.invoice_date',
            ])
            ->when(! $allStatuses, fn (Builder $query) => $query->whereIn('sales_invoices.status', ['posted', 'active']))
            ->when($onlyCustomerId !== null, fn (Builder $query) => $query->where('sales_invoices.customer_id', $onlyCustomerId))
            ->whereNotNull('sales_invoices.customer_id')
            ->whereNotNull('sales_invoices.branch_id')
            ->whereNotExists(function ($sub) use ($allStatuses): void {
                $sub->selectRaw('1')
                    ->from('sales_invoices as newer')
                    ->whereColumn('newer.customer_id', 'sales_invoices.customer_id')
                    ->whereNotNull('newer.branch_id');

                if (! $allStatuses) {
                    $sub->whereIn('newer.status', ['posted', 'active']);
                }

                $sub->where(function ($dateQuery): void {
                    $dateQuery->whereColumn('newer.invoice_date', '>', 'sales_invoices.invoice_date')
                        ->orWhere(function ($tieQuery): void {
                            $tieQuery->whereColumn('newer.invoice_date', 'sales_invoices.invoice_date')
                                ->whereColumn('newer.id', '>', 'sales_invoices.id');
                        });
                });
            });

        return $query
            ->orderBy('sales_invoices.customer_id')
            ->get()
            ->keyBy(fn (SalesInvoice $invoice) => (int) $invoice->customer_id);
    }
}
