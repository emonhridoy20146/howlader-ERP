<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds the customer list for an SMS scenario (read-only queries).
 *
 * Every row is one customer with one phone number and the values that the
 * message placeholders ({name}, {due_amount}, …) are filled with.
 */
final class SmsAudienceService
{
    private const LIVE_INVOICE_STATUSES = ['posted', 'active'];

    private const OPEN_SCHEDULE_STATUSES = ['pending', 'partially_paid', 'overdue'];

    /**
     * Scenario catalogue for the SMS page.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $today = Carbon::today();

        return [
            'installment_upcoming' => [
                'label' => 'Upcoming installment',
                'label_bn' => 'আসন্ন কিস্তি',
                'hint' => 'Customers whose installment falls due between the selected dates.',
                'date_label' => 'Installment due date',
                'dates' => true,
                'from' => $today->toDateString(),
                'to' => $today->copy()->addDays(7)->toDateString(),
                'extra' => [],
            ],
            'installment_due_today' => [
                'label' => 'Installment due today',
                'label_bn' => 'আজ কিস্তির তারিখ',
                'hint' => 'Customers with an unpaid installment due today.',
                'date_label' => null,
                'dates' => false,
                'from' => null,
                'to' => null,
                'extra' => [],
            ],
            'installment_overdue' => [
                'label' => 'Installment overdue',
                'label_bn' => 'বকেয়া কিস্তি',
                'hint' => 'Installments whose due date has passed and are not fully paid. Dates narrow the due-date window (optional).',
                'date_label' => 'Missed due date',
                'dates' => true,
                'from' => null,
                'to' => $today->copy()->subDay()->toDateString(),
                'extra' => ['min_overdue_days'],
            ],
            'credit_upcoming' => [
                'label' => 'Credit due soon',
                'label_bn' => 'বাকির তারিখ আসন্ন',
                'hint' => 'Credit sales with an unpaid balance whose due date is within the selected dates.',
                'date_label' => 'Credit due date',
                'dates' => true,
                'from' => $today->toDateString(),
                'to' => $today->copy()->addDays(7)->toDateString(),
                'extra' => [],
            ],
            'credit_overdue' => [
                'label' => 'Credit overdue',
                'label_bn' => 'বাকি মেয়াদোত্তীর্ণ',
                'hint' => 'Credit sales whose due date has passed with money still owed.',
                'date_label' => 'Missed due date',
                'dates' => true,
                'from' => null,
                'to' => $today->copy()->subDay()->toDateString(),
                'extra' => ['min_overdue_days'],
            ],
            'new_sales' => [
                'label' => 'New sales',
                'label_bn' => 'নতুন বিক্রয়',
                'hint' => 'Customers who bought within the selected dates (thank-you / welcome SMS).',
                'date_label' => 'Invoice date',
                'dates' => true,
                'from' => $today->toDateString(),
                'to' => $today->toDateString(),
                'extra' => ['sale_type'],
            ],
            'payment_received' => [
                'label' => 'Payment received',
                'label_bn' => 'পেমেন্ট গ্রহণ',
                'hint' => 'Customers who paid within the selected dates (receipt confirmation).',
                'date_label' => 'Payment date',
                'dates' => true,
                'from' => $today->toDateString(),
                'to' => $today->toDateString(),
                'extra' => [],
            ],
            'outstanding_dues' => [
                'label' => 'Customers with dues',
                'label_bn' => 'বকেয়া গ্রাহক',
                'hint' => 'Every customer who still owes money on any sale (cash, credit or installment).',
                'date_label' => 'Invoice date',
                'dates' => true,
                'from' => null,
                'to' => null,
                'extra' => ['min_due', 'sale_type'],
            ],
            'all_customers' => [
                'label' => 'All customers',
                'label_bn' => 'সকল গ্রাহক',
                'hint' => 'All active customers with a phone number (offers, announcements, greetings).',
                'date_label' => 'Customer since',
                'dates' => true,
                'from' => null,
                'to' => null,
                'extra' => [],
            ],
        ];
    }

    /**
     * Placeholder catalogue shown next to the message box.
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            'name' => 'Customer name',
            'customer_code' => 'Customer code',
            'phone' => 'Phone',
            'branch' => 'Branch',
            'company' => 'Company name',
            'invoice_no' => 'Invoice number(s)',
            'due_amount' => 'Amount due for this scenario',
            'due_date' => 'Due date (earliest)',
            'installment_no' => 'Installment number',
            'overdue_days' => 'Days overdue',
            'total_due' => 'Customer total outstanding',
            'sale_amount' => 'Sale amount',
            'sale_date' => 'Sale date',
            'paid_amount' => 'Amount paid',
            'payment_date' => 'Payment date',
        ];
    }

    public static function companyName(): string
    {
        $name = AppSetting::getMany(['company_pad_name'])['company_pad_name'] ?? null;

        return filled($name) ? (string) $name : (string) config('app.name');
    }

    /**
     * @param  array{from?: ?string, to?: ?string, branch_id?: ?int, sale_type?: ?string, min_due?: ?float, min_overdue_days?: ?int, q?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, skipped: int}
     */
    public function resolve(string $audience, array $filters): array
    {
        $rows = match ($audience) {
            'installment_upcoming', 'installment_due_today', 'installment_overdue' => $this->installments($audience, $filters),
            'credit_upcoming', 'credit_overdue' => $this->credits($audience, $filters),
            'new_sales' => $this->newSales($filters),
            'payment_received' => $this->payments($filters),
            'outstanding_dues' => $this->outstanding($filters),
            'all_customers' => $this->allCustomers($filters),
            default => throw new InvalidArgumentException('Unknown SMS audience.'),
        };

        return $this->finalize($rows, $filters);
    }

    /* ------------------------------------------------------------------ */

    private function installments(string $audience, array $filters): Collection
    {
        $today = Carbon::today()->toDateString();

        $query = InstallmentSchedule::query()
            ->join('installment_plans as p', 'p.id', '=', 'installment_schedules.installment_plan_id')
            ->join('sales_invoices as i', 'i.id', '=', 'p.sales_invoice_id')
            ->where('p.status', 'active')
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', self::LIVE_INVOICE_STATUSES)
            ->whereIn('installment_schedules.status', self::OPEN_SCHEDULE_STATUSES)
            ->whereRaw('(installment_schedules.due_amount + COALESCE(installment_schedules.late_fee_amount, 0) - COALESCE(installment_schedules.paid_amount, 0)) > 0.009')
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('i.branch_id', $b));

        match ($audience) {
            'installment_due_today' => $query->whereDate('installment_schedules.due_date', $today),
            'installment_upcoming' => $this->dateRange($query, 'installment_schedules.due_date', $filters, $today),
            'installment_overdue' => $this->overdueWindow($query, 'installment_schedules.due_date', $filters),
        };

        $records = $query->get([
            'p.customer_id',
            'i.invoice_number',
            'i.branch_id',
            'installment_schedules.installment_no',
            'installment_schedules.due_date',
            DB::raw('(installment_schedules.due_amount + COALESCE(installment_schedules.late_fee_amount, 0) - COALESCE(installment_schedules.paid_amount, 0)) AS remaining'),
        ]);

        return $records->groupBy('customer_id')->map(function (Collection $items, $customerId): array {
            $first = $items->sortBy(fn ($r) => Carbon::parse($r->due_date)->timestamp)->first();
            $dueDate = Carbon::parse($first->due_date);

            return [
                'customer_id' => (int) $customerId,
                'branch_id' => $first->branch_id,
                'vars' => [
                    'invoice_no' => $items->pluck('invoice_number')->filter()->unique()->implode(', '),
                    'due_amount' => SmsText::money($items->sum('remaining')),
                    'due_date' => $dueDate->format('d-m-Y'),
                    'installment_no' => (string) $first->installment_no,
                    'overdue_days' => (string) max(0, (int) $dueDate->diffInDays(Carbon::today(), false)),
                ],
                'detail' => $items->count().' installment(s) · '.SmsText::money($items->sum('remaining')).' due · earliest '.$dueDate->format('d M Y'),
                'sort' => $dueDate->timestamp,
            ];
        })->values();
    }

    private function credits(string $audience, array $filters): Collection
    {
        $today = Carbon::today()->toDateString();

        $query = SalesInvoice::query()
            ->where('sale_type', 'credit')
            ->whereIn('status', self::LIVE_INVOICE_STATUSES)
            ->where('amount_due', '>', 0.009)
            ->whereNotNull('credit_due_date')
            ->whereNotNull('customer_id')
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b));

        $audience === 'credit_upcoming'
            ? $this->dateRange($query, 'credit_due_date', $filters, $today)
            : $this->overdueWindow($query, 'credit_due_date', $filters);

        $records = $query->get(['customer_id', 'invoice_number', 'branch_id', 'credit_due_date', 'amount_due']);

        return $records->groupBy('customer_id')->map(function (Collection $items, $customerId): array {
            $first = $items->sortBy(fn ($r) => $r->credit_due_date->timestamp)->first();
            $dueDate = $first->credit_due_date;

            return [
                'customer_id' => (int) $customerId,
                'branch_id' => $first->branch_id,
                'vars' => [
                    'invoice_no' => $items->pluck('invoice_number')->filter()->unique()->implode(', '),
                    'due_amount' => SmsText::money($items->sum('amount_due')),
                    'due_date' => $dueDate->format('d-m-Y'),
                    'overdue_days' => (string) max(0, (int) $dueDate->diffInDays(Carbon::today(), false)),
                ],
                'detail' => $items->count().' credit invoice(s) · '.SmsText::money($items->sum('amount_due')).' due · '.$dueDate->format('d M Y'),
                'sort' => $dueDate->timestamp,
            ];
        })->values();
    }

    private function newSales(array $filters): Collection
    {
        $query = SalesInvoice::query()
            ->whereIn('status', self::LIVE_INVOICE_STATUSES)
            ->whereNotNull('customer_id')
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['sale_type'] ?? null, fn ($q, $t) => $q->where('sale_type', $t));

        $this->dateRange($query, 'invoice_date', $filters, Carbon::today()->toDateString());

        $records = $query->get(['customer_id', 'invoice_number', 'branch_id', 'invoice_date', 'grand_total', 'amount_due', 'sale_type']);

        return $records->groupBy('customer_id')->map(function (Collection $items, $customerId): array {
            $last = $items->sortByDesc(fn ($r) => $r->invoice_date->timestamp)->first();

            return [
                'customer_id' => (int) $customerId,
                'branch_id' => $last->branch_id,
                'vars' => [
                    'invoice_no' => $items->pluck('invoice_number')->filter()->unique()->implode(', '),
                    'sale_amount' => SmsText::money($items->sum('grand_total')),
                    'sale_date' => $last->invoice_date->format('d-m-Y'),
                    'due_amount' => SmsText::money($items->sum('amount_due')),
                ],
                'detail' => $items->count().' sale(s) · '.SmsText::money($items->sum('grand_total')).' · '.$items->pluck('sale_type')->unique()->implode('/'),
                'sort' => -$last->invoice_date->timestamp,
            ];
        })->values();
    }

    private function payments(array $filters): Collection
    {
        $query = Payment::query()
            ->where('direction', 'inbound')
            ->whereNotNull('customer_id');

        $this->dateRange($query, 'payment_date', $filters, Carbon::today()->toDateString());

        if ($filters['branch_id'] ?? null) {
            $query->whereIn('customer_id', Customer::query()->select('id')->where('branch_id', $filters['branch_id']));
        }

        $records = $query->get(['customer_id', 'payment_date', 'amount']);

        return $records->groupBy('customer_id')->map(function (Collection $items, $customerId): array {
            $last = $items->sortByDesc(fn ($r) => $r->payment_date->timestamp)->first();

            return [
                'customer_id' => (int) $customerId,
                'branch_id' => null,
                'vars' => [
                    'paid_amount' => SmsText::money($items->sum('amount')),
                    'payment_date' => $last->payment_date->format('d-m-Y'),
                ],
                'detail' => $items->count().' payment(s) · '.SmsText::money($items->sum('amount')).' paid',
                'sort' => -$last->payment_date->timestamp,
            ];
        })->values();
    }

    private function outstanding(array $filters): Collection
    {
        $query = SalesInvoice::query()
            ->whereIn('status', self::LIVE_INVOICE_STATUSES)
            ->where('amount_due', '>', 0.009)
            ->whereNotNull('customer_id')
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['sale_type'] ?? null, fn ($q, $t) => $q->where('sale_type', $t));

        $this->dateRange($query, 'invoice_date', $filters, null);

        $records = $query->get(['customer_id', 'invoice_number', 'branch_id', 'amount_due']);
        $minDue = (float) ($filters['min_due'] ?? 0);

        return $records->groupBy('customer_id')
            ->filter(fn (Collection $items) => $items->sum('amount_due') >= $minDue)
            ->map(fn (Collection $items, $customerId): array => [
                'customer_id' => (int) $customerId,
                'branch_id' => $items->first()->branch_id,
                'vars' => [
                    'invoice_no' => $items->pluck('invoice_number')->filter()->unique()->implode(', '),
                    'due_amount' => SmsText::money($items->sum('amount_due')),
                ],
                'detail' => $items->count().' open invoice(s) · '.SmsText::money($items->sum('amount_due')).' due',
                'sort' => -$items->sum('amount_due'),
            ])->values();
    }

    private function allCustomers(array $filters): Collection
    {
        $query = Customer::query()
            ->where('is_active', true)
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b));

        $this->dateRange($query, 'created_at', $filters, null);

        return $query->pluck('id')->map(fn ($id): array => [
            'customer_id' => (int) $id,
            'branch_id' => null,
            'vars' => [],
            'detail' => '',
            'sort' => 0,
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Attach customer details, a valid phone, outstanding totals; apply search.
     *
     * @return array{rows: list<array<string, mixed>>, skipped: int}
     */
    private function finalize(Collection $rows, array $filters): array
    {
        $ids = $rows->pluck('customer_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return ['rows' => [], 'skipped' => 0];
        }

        $customers = collect();
        $totals = collect();
        foreach ($ids->chunk(1000) as $chunk) {
            $customers = $customers->union(
                Customer::query()->whereKey($chunk)->get(['id', 'name', 'customer_code', 'phone', 'phone_numbers', 'branch_id'])->keyBy('id')
            );
            $totals = $totals->union(
                SalesInvoice::query()
                    ->whereIn('customer_id', $chunk)
                    ->whereIn('status', self::LIVE_INVOICE_STATUSES)
                    ->groupBy('customer_id')
                    ->selectRaw('customer_id, SUM(amount_due) AS due')
                    ->pluck('due', 'customer_id')
            );
        }

        $branches = Location::query()->pluck('name', 'id');
        $company = self::companyName();
        $term = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        $out = [];
        $skipped = 0;

        foreach ($rows->sortBy('sort') as $row) {
            $customer = $customers->get($row['customer_id']);
            if (! $customer) {
                continue;
            }

            $phone = null;
            foreach ($customer->normalizedPhoneNumbers() as $candidate) {
                if ($phone = SmsText::normalizePhone($candidate)) {
                    break;
                }
            }

            if ($term !== '') {
                $haystack = mb_strtolower($customer->name.' '.$customer->customer_code.' '.$customer->phone);
                if (! str_contains($haystack, $term)) {
                    continue;
                }
            }

            if ($phone === null) {
                $skipped++;

                continue;
            }

            $branch = $branches->get($row['branch_id'] ?? $customer->branch_id) ?? $branches->get($customer->branch_id) ?? '';

            $out[] = [
                'customer_id' => $customer->id,
                'name' => (string) $customer->name,
                'code' => (string) $customer->customer_code,
                'phone' => $phone,
                'branch' => (string) $branch,
                'detail' => $row['detail'],
                'vars' => array_merge([
                    'name' => (string) $customer->name,
                    'customer_code' => (string) $customer->customer_code,
                    'phone' => $phone,
                    'branch' => (string) $branch,
                    'company' => $company,
                    'total_due' => SmsText::money($totals->get($customer->id, 0)),
                    'invoice_no' => '',
                    'due_amount' => '',
                    'due_date' => '',
                    'installment_no' => '',
                    'overdue_days' => '',
                    'sale_amount' => '',
                    'sale_date' => '',
                    'paid_amount' => '',
                    'payment_date' => '',
                ], $row['vars']),
            ];
        }

        return ['rows' => $out, 'skipped' => $skipped];
    }

    private function dateRange(Builder $query, string $column, array $filters, ?string $defaultFrom): void
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from) {
            $query->whereDate($column, '>=', $from);
        } elseif ($defaultFrom) {
            $query->whereDate($column, '>=', $defaultFrom);
        }

        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }

    private function overdueWindow(Builder $query, string $column, array $filters): void
    {
        $today = Carbon::today();
        $minDays = max(1, (int) ($filters['min_overdue_days'] ?? 1));
        $latest = $today->copy()->subDays($minDays)->toDateString();

        $to = $filters['to'] ?? null;
        $query->whereDate($column, '<=', $to && $to < $latest ? $to : $latest);

        if ($filters['from'] ?? null) {
            $query->whereDate($column, '>=', $filters['from']);
        }
    }
}
