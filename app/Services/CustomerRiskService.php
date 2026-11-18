<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerRiskSnapshot;
use App\Models\InstallmentSchedule;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the customer profile & risk array used by the page, print, PDF and Excel outputs.
 * The pure math (days late, slope, classification, score, grade) lives in small public
 * static methods so it can be unit-tested without a database.
 */
class CustomerRiskService
{
    private const GRADE_ORDER = ['A', 'B', 'C', 'D', 'E'];

    /**
     * @param  list<int>|null  $branchIds  null = no branch restriction
     */
    public function build(Customer $customer, ?Carbon $asOf = null, ?array $branchIds = null, bool $withGuarantorGrades = true): array
    {
        $cfg = config('customer_risk');
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $customer->loadMissing(['branch', 'defaultGuarantor']);

        $invoices = SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->with([
                'branch',
                'guarantor',
                'debtWriteoffs',
                'installmentPlan.schedules' => fn ($q) => $q->orderBy('installment_no'),
                'installmentPlan.schedules.paymentAllocations.payment',
            ])
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        $countedInvoices = $invoices->reject(fn (SalesInvoice $i) => in_array($i->status, ['draft', 'cancelled'], true));

        $rows = [];
        $plans = [];
        $payments = [];
        $writtenOff = false;

        foreach ($invoices as $invoice) {
            $plan = $invoice->installmentPlan;
            if (! $plan) {
                continue;
            }
            $planWrittenOff = $invoice->debtWriteoffs->isNotEmpty();
            $writtenOff = $writtenOff || ($planWrittenOff && $plan->status !== 'cancelled');
            $planRows = [];

            foreach ($plan->schedules as $schedule) {
                $allocs = $schedule->paymentAllocations
                    ->filter(fn ($a) => $a->payment !== null)
                    ->sortBy(fn ($a) => [$a->payment->payment_date?->timestamp ?? 0, $a->id])
                    ->values();
                $paidDate = $this->settlementDate($schedule, $allocs);
                $due = $schedule->due_date?->copy()->startOfDay();
                $daysLate = self::daysLate($due, $paidDate, $asOf);
                $remaining = $schedule->remainingDue();
                $scheduleRow = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date,
                    'branch' => $invoice->branch?->name,
                    'plan_id' => $plan->id,
                    'plan_status' => $plan->status,
                    'installment_no' => $schedule->installment_no,
                    'due_date' => $due,
                    'due_amount' => (float) $schedule->due_amount,
                    'late_fee' => (float) $schedule->late_fee_amount,
                    'paid_amount' => (float) $schedule->paid_amount,
                    'remaining' => $remaining,
                    'paid_date' => $paidDate,
                    'days_late' => $daysLate,
                    'is_late' => $daysLate > $cfg['grace_days'],
                    'band' => self::bandFor($daysLate, $cfg['grace_days'], $cfg['late_bands']),
                    'status' => $schedule->computeStatus(),
                    'matured' => $due !== null && $due->lte($asOf),
                    'scored' => $plan->status !== 'cancelled',
                    'payments' => $allocs->map(fn ($a) => [
                        'date' => $a->payment->payment_date,
                        'amount' => (float) $a->allocated_amount,
                        'method' => $a->payment->payment_method,
                        'payment_number' => $a->payment->payment_number,
                    ])->all(),
                ];
                $rows[] = $scheduleRow;
                $planRows[] = $scheduleRow;
                foreach ($scheduleRow['payments'] as $p) {
                    $payments[] = $p + ['invoice_number' => $invoice->invoice_number, 'installment_no' => $schedule->installment_no];
                }
            }

            $plans[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'plan_id' => $plan->id,
                'status' => $plan->status,
                'financed_amount' => (float) $plan->financed_amount,
                'total_amount' => (float) $plan->total_amount,
                'down_payment' => (float) $plan->down_payment,
                'reschedule_count' => (int) $plan->reschedule_count,
                'written_off' => $planWrittenOff,
                'installments' => $planRows,
            ];
        }

        $scored = collect($rows)->filter(fn ($r) => $r['scored'] && $r['matured'])
            ->sortBy(fn ($r) => [$r['due_date']->timestamp, $r['invoice_id'], $r['installment_no']])
            ->values();
        $scoredAll = collect($rows)->filter(fn ($r) => $r['scored']);

        $series = $scored->map(fn ($r) => ['due' => $r['due_date'], 'days_late' => $r['days_late'], 'is_late' => $r['is_late']])->all();
        $lates = $scored->where('is_late', true);
        $maturedCount = $scored->count();
        $lateCount = $lates->count();
        $onTimeRate = $maturedCount > 0 ? ($maturedCount - $lateCount) / $maturedCount : null;

        $overdueRows = $scored->filter(fn ($r) => $r['remaining'] > 0.009 && $r['due_date']->lt($asOf));
        $overdueAmount = round((float) $overdueRows->sum('remaining'), 2);
        $installmentOutstanding = round((float) $scoredAll->sum('remaining'), 2);

        $hasDefault = $writtenOff || collect($plans)->contains(fn ($p) => $p['status'] === 'defaulted');
        $hasActivePlan = collect($plans)->contains(fn ($p) => $p['status'] === 'active');

        $status = match (true) {
            $hasDefault => 'defaulted',
            $overdueRows->isNotEmpty() => 'overdue',
            $hasActivePlan => 'active',
            default => 'closed',
        };

        $countLatesSince = fn (int $months) => $lates->filter(fn ($r) => $r['due_date']->gte($asOf->copy()->subMonths($months)))->count();

        $streaks = self::streaks(array_column($series, 'is_late'));
        $trend = self::classify($series, $asOf, $cfg['trend'], $cfg['grace_days']);

        $metrics = [
            'on_time_rate' => $onTimeRate,
            'avg_days_late' => $lateCount > 0 ? round((float) $lates->avg('days_late'), 1) : 0.0,
            'max_days_late' => (int) ($lates->max('days_late') ?? 0),
            'late_6m' => $countLatesSince(6),
            'overdue_amount' => $overdueAmount,
            'outstanding' => $installmentOutstanding,
            'has_default' => $hasDefault,
            'matured' => $maturedCount,
        ];
        $score = self::score($metrics, $cfg['weights']);
        $grade = self::grade($score['total'], $trend['classification'] === 'trend', $hasDefault, $cfg);
        $riskLevel = match ($grade) {
            'A', 'B' => 'Low',
            'C' => 'Medium',
            'D', 'E' => 'High',
            default => 'Unknown',
        };

        $lastPayment = collect($payments)->max(fn ($p) => $p['date']?->timestamp);

        $summary = [
            'total_purchased' => round((float) $countedInvoices->sum('grand_total'), 2),
            'total_paid' => round((float) $countedInvoices->sum('amount_paid'), 2),
            'outstanding' => $installmentOutstanding,
            'invoice_outstanding' => round((float) $countedInvoices->sum('amount_due'), 2),
            'customer_outstanding_balance' => (float) $customer->outstanding_balance,
            'overdue_amount' => $overdueAmount,
            'overdue_count' => $overdueRows->count(),
            'matured_count' => $maturedCount,
            'late_count' => $lateCount,
            'on_time_rate' => $onTimeRate,
            'avg_days_late' => $metrics['avg_days_late'],
            'max_days_late' => $metrics['max_days_late'],
            'late_3m' => $countLatesSince(3),
            'late_6m' => $countLatesSince(6),
            'late_12m' => $countLatesSince(12),
            'longest_streak' => $streaks['longest'],
            'current_streak' => $streaks['current'],
            'reschedule_count' => (int) collect($plans)->sum('reschedule_count'),
            'defaulted_plans' => collect($plans)->filter(fn ($p) => $p['status'] === 'defaulted' || $p['written_off'])->count(),
            'last_payment_date' => $lastPayment ? Carbon::createFromTimestamp($lastPayment) : null,
        ];

        return [
            'customer' => $customer,
            'as_of' => $asOf,
            'status' => $status,
            'summary' => $summary,
            'trend' => $trend,
            'score' => $score,
            'grade' => $grade,
            'risk_level' => $riskLevel,
            'recommendation' => $this->recommend($grade, $overdueAmount, $plans, $cfg),
            'invoices' => $invoices,
            'plans' => $plans,
            'installments' => $rows,
            'payments' => $payments,
            'guarantors' => $this->guarantors($customer, $invoices, $branchIds, $asOf, $withGuarantorGrades),
            'charts' => $this->charts($rows, $scored, $asOf, $cfg),
            'config' => $cfg,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $report  an unrestricted (all-branch) build() result to reuse
     */
    public function refreshSnapshot(Customer $customer, ?array $report = null): CustomerRiskSnapshot
    {
        $r = $report ?? $this->build($customer, Carbon::today(), null, false);
        $s = $r['summary'];

        return CustomerRiskSnapshot::updateOrCreate(['customer_id' => $customer->id], [
            'branch_id' => $customer->branch_id,
            'score' => $r['score']['total'],
            'grade' => $r['grade'],
            'risk_level' => $r['risk_level'],
            'classification' => $r['trend']['classification'],
            'improving' => $r['trend']['improving'],
            'status' => $r['status'],
            'outstanding_amount' => $s['outstanding'],
            'overdue_amount' => $s['overdue_amount'],
            'overdue_count' => $s['overdue_count'],
            'avg_days_late' => $s['avg_days_late'],
            'max_days_late' => $s['max_days_late'],
            'late_6m' => $s['late_6m'],
            'current_streak' => $s['current_streak'],
            'has_guarantor' => $r['guarantors'] !== [],
            'computed_at' => now(),
        ]);
    }

    public static function daysLate(?Carbon $due, ?Carbon $paidDate, Carbon $asOf): int
    {
        if ($due === null) {
            return 0;
        }
        $due = $due->copy()->startOfDay();
        if ($paidDate !== null) {
            return max(0, (int) $due->diffInDays($paidDate->copy()->startOfDay(), false));
        }
        if ($due->lt($asOf)) {
            return (int) $due->diffInDays($asOf);
        }

        return 0;
    }

    /**
     * @param  list<int>  $bands
     */
    public static function bandFor(int $daysLate, int $grace, array $bands): string
    {
        if ($daysLate <= $grace) {
            return 'On time';
        }
        $prev = 0;
        foreach ($bands as $max) {
            if ($daysLate <= $max) {
                return ($prev + 1).'-'.$max.' days';
            }
            $prev = $max;
        }

        return $prev.'+ days';
    }

    /** Least-squares slope of values against their index. */
    public static function slope(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $values = array_values($values);
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $num = $den = 0.0;
        foreach ($values as $x => $y) {
            $num += ($x - $meanX) * ($y - $meanY);
            $den += ($x - $meanX) ** 2;
        }

        return $den > 0 ? $num / $den : 0.0;
    }

    /**
     * @param  list<bool>  $lateFlags  ordered by due date
     * @return array{longest: int, current: int}
     */
    public static function streaks(array $lateFlags): array
    {
        $longest = $current = 0;
        foreach ($lateFlags as $late) {
            $current = $late ? $current + 1 : 0;
            $longest = max($longest, $current);
        }

        return ['longest' => $longest, 'current' => $current];
    }

    /**
     * @param  list<array{due: Carbon, days_late: int, is_late: bool}>  $series  matured installments ordered by due date
     * @param  array<string, mixed>  $cfg  the `trend` config block
     * @return array{classification: string, improving: bool, slope: float, lates_in_window: int, current_streak: int, reason: string}
     */
    public static function classify(array $series, Carbon $asOf, array $cfg, int $grace = 3): array
    {
        $lateFlags = array_column($series, 'is_late');
        $lateTotal = count(array_filter($lateFlags));
        $streak = self::streaks($lateFlags)['current'];
        $windowStart = $asOf->copy()->subMonths($cfg['recent_window_months']);
        $inWindow = count(array_filter($series, fn ($r) => $r['is_late'] && $r['due']->gte($windowStart)));
        $sample = array_slice(array_column($series, 'days_late'), -$cfg['slope_sample']);
        $slope = round(self::slope($sample), 2);
        $improving = $lateTotal > 0 && count($sample) >= 2 && $slope <= -$cfg['slope_threshold'];

        $base = ['improving' => $improving, 'slope' => $slope, 'lates_in_window' => $inWindow, 'current_streak' => $streak];

        if ($lateTotal === 0) {
            return $base + ['classification' => 'none', 'reason' => 'No late payments on record.'];
        }

        $reasons = [];
        if ($inWindow >= $cfg['min_lates_in_window']) {
            $reasons[] = "{$inWindow} late payments in the last {$cfg['recent_window_months']} months (limit {$cfg['min_lates_in_window']})";
        }
        if ($lateTotal >= 2 && $slope >= $cfg['slope_threshold']) {
            $reasons[] = "days-late is rising by {$slope} per installment (limit {$cfg['slope_threshold']})";
        }
        if ($streak >= $cfg['streak_threshold']) {
            $reasons[] = "{$streak} consecutive late payments right now (limit {$cfg['streak_threshold']})";
        }

        if ($reasons !== []) {
            return $base + ['classification' => 'trend', 'reason' => 'Trend: '.implode('; ', $reasons).'.'];
        }

        $reason = "Isolated: {$lateTotal} late payment(s) in total, {$inWindow} in the last {$cfg['recent_window_months']} months, slope {$slope}, no current late streak.";

        return $base + ['classification' => 'isolated', 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, int|float>  $weights
     * @return array{total: float|null, factors: list<array{key: string, label: string, value: string, weight: float, points: float}>, formula: string}
     */
    public static function score(array $m, array $weights): array
    {
        $formula = 'Score = on_time×rate + avg_late×max(0,1−avg_days/60) + max_late×max(0,1−max_days/120) '
            .'+ recent×max(0,1−lates_6m/6) + overdue×(no overdue ? 1 : max(0,1−overdue/outstanding)) + defaults×(no default/write-off ? 1 : 0)';

        if (($m['matured'] ?? 0) === 0 || $m['on_time_rate'] === null) {
            return ['total' => null, 'factors' => [], 'formula' => $formula];
        }

        $overdueRatio = $m['overdue_amount'] <= 0
            ? 1.0
            : ($m['outstanding'] > 0 ? max(0.0, 1 - $m['overdue_amount'] / $m['outstanding']) : 0.0);

        $parts = [
            ['on_time', 'On-time rate', number_format($m['on_time_rate'] * 100, 1).'%', $m['on_time_rate']],
            ['avg_late', 'Average days late', $m['avg_days_late'].' d', max(0, 1 - $m['avg_days_late'] / 60)],
            ['max_late', 'Worst delay', $m['max_days_late'].' d', max(0, 1 - $m['max_days_late'] / 120)],
            ['recent', 'Lates in last 6 months', (string) $m['late_6m'], max(0, 1 - $m['late_6m'] / 6)],
            ['overdue', 'Overdue vs outstanding', number_format($m['overdue_amount'], 2).' / '.number_format($m['outstanding'], 2), $overdueRatio],
            ['defaults', 'Default / write-off', $m['has_default'] ? 'Yes' : 'No', $m['has_default'] ? 0 : 1],
        ];

        $factors = [];
        $total = 0.0;
        foreach ($parts as [$key, $label, $value, $ratio]) {
            $points = round($weights[$key] * $ratio, 2);
            $total += $points;
            $factors[] = ['key' => $key, 'label' => $label, 'value' => $value, 'weight' => (float) $weights[$key], 'points' => $points];
        }

        return ['total' => round($total, 1), 'factors' => $factors, 'formula' => $formula];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    public static function grade(?float $score, bool $isTrend, bool $hasDefault, array $cfg): string
    {
        if ($hasDefault) {
            return 'E';
        }
        if ($score === null) {
            return 'N/A';
        }

        $grade = 'E';
        foreach ($cfg['grades'] as $letter => $min) {
            if ($score >= $min) {
                $grade = $letter;
                break;
            }
        }

        if ($isTrend) {
            $cap = $cfg['trend_max_grade'] ?? 'C';
            if (array_search($grade, self::GRADE_ORDER, true) < array_search($cap, self::GRADE_ORDER, true)) {
                $grade = $cap;
            }
        }

        return $grade;
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @param  array<string, mixed>  $cfg
     */
    private function recommend(string $grade, float $overdueAmount, array $plans, array $cfg): array
    {
        if ($grade === 'N/A') {
            return ['eligible' => false, 'text' => 'New customer — no installment history. Apply the standard new-customer policy.', 'max_amount' => null, 'max_tenure_months' => null, 'min_down_payment_pct' => null, 'base_amount' => 0.0];
        }

        $rules = $cfg['eligibility'][$grade];
        $completed = collect($plans)->where('status', 'completed')->pluck('financed_amount');
        $base = $completed->isNotEmpty()
            ? (float) $completed->avg()
            : (float) (collect($plans)->max('financed_amount') ?? 0);
        $max = round($base * $rules['max_amount_multiplier'] / 100) * 100;
        $eligible = $rules['max_amount_multiplier'] > 0 && $overdueAmount <= 0;

        $text = match (true) {
            $overdueAmount > 0 => 'Not eligible until overdue is cleared.',
            $rules['max_amount_multiplier'] <= 0 => 'Not eligible — risk grade '.$grade.'.',
            $rules['min_down_payment_pct'] > 10 => 'Eligible with higher down payment.',
            default => 'Eligible.',
        };

        return [
            'eligible' => $eligible,
            'text' => $text,
            'max_amount' => $eligible ? $max : 0.0,
            'max_tenure_months' => $eligible ? $rules['max_tenure_months'] : 0,
            'min_down_payment_pct' => $rules['min_down_payment_pct'],
            'base_amount' => round($base, 2),
        ];
    }

    private function settlementDate(InstallmentSchedule $schedule, Collection $allocations): ?Carbon
    {
        if (! $schedule->isFullyPaid()) {
            return null;
        }
        $target = max(0, round((float) $schedule->due_amount + (float) $schedule->late_fee_amount, 2)) - 0.009;
        $cum = 0.0;
        foreach ($allocations as $a) {
            $cum += (float) $a->allocated_amount;
            if ($cum >= $target) {
                return $a->payment->payment_date?->copy()->startOfDay();
            }
        }

        return $allocations->last()?->payment->payment_date?->copy()->startOfDay();
    }

    private function guarantors(Customer $customer, Collection $invoices, ?array $branchIds, Carbon $asOf, bool $withGrades): array
    {
        $out = [];
        $add = function (string $key, array $data) use (&$out): void {
            $out[$key] ??= $data + ['invoices' => []];
            if (isset($data['invoice_number'])) {
                $out[$key]['invoices'][] = $data['invoice_number'];
            }
        };

        $shape = fn (Customer $g, array $extra = []) => [
            'customer_id' => $g->id,
            'name' => $g->name,
            'phones' => $g->normalizedPhoneNumbers(),
            'nid_number' => $g->id_number,
            'nid_url' => $g->nidUrl(),
            'address' => $g->address,
            'relation' => 'Guarantor',
        ] + $extra;

        if ($customer->defaultGuarantor) {
            $add('c'.$customer->defaultGuarantor->id, $shape($customer->defaultGuarantor, ['relation' => 'Default guarantor']));
        }

        foreach ($invoices as $invoice) {
            if ($invoice->guarantor) {
                $add('c'.$invoice->guarantor->id, $shape($invoice->guarantor, [
                    'invoice_number' => $invoice->invoice_number,
                    'nid_number' => $invoice->guarantor_nid_number ?: $invoice->guarantor->id_number,
                    'nid_url' => $invoice->guarantorNidUrl() ?: $invoice->guarantor->nidUrl(),
                ]));
            } elseif (filled($invoice->guarantor_name)) {
                $add('n'.mb_strtolower(trim($invoice->guarantor_name)), [
                    'customer_id' => null,
                    'name' => $invoice->guarantor_name,
                    'phones' => is_array($invoice->guarantor_phone_numbers) ? $invoice->guarantor_phone_numbers : [],
                    'nid_number' => $invoice->guarantor_nid_number,
                    'nid_url' => $invoice->guarantorNidUrl(),
                    'address' => $invoice->guarantor_address,
                    'relation' => 'Guarantor',
                    'invoice_number' => $invoice->invoice_number,
                ]);
            }
        }

        $result = [];
        foreach ($out as $g) {
            $g['grade'] = null;
            if ($withGrades && $g['customer_id']) {
                $guarantorCustomer = Customer::find($g['customer_id']);
                if ($guarantorCustomer) {
                    $g['grade'] = $this->build($guarantorCustomer, $asOf, $branchIds, false)['grade'];
                }
            }
            $result[] = $g;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function charts(array $rows, Collection $scored, Carbon $asOf, array $cfg): array
    {
        $months = [];
        for ($i = $cfg['chart_months'] - 1; $i >= 0; $i--) {
            $m = $asOf->copy()->startOfMonth()->subMonths($i);
            $months[$m->format('Y-m')] = ['label' => $m->format('M Y'), 'on_time' => 0, 'late' => 0, 'due' => 0.0, 'paid' => 0.0];
        }

        foreach ($rows as $r) {
            if (! $r['scored'] || ! $r['due_date']) {
                continue;
            }
            $key = $r['due_date']->format('Y-m');
            if (! isset($months[$key])) {
                continue;
            }
            $months[$key]['due'] += $r['due_amount'] + $r['late_fee'];
            $months[$key]['paid'] += $r['paid_amount'];
            if ($r['matured']) {
                $months[$key][$r['is_late'] ? 'late' : 'on_time']++;
            }
        }

        $days = $scored->pluck('days_late')->all();
        $sample = $cfg['trend']['slope_sample'];
        $slope = self::slope(array_slice($days, -$sample));
        $window = array_slice($days, -$sample);
        $mean = $window ? array_sum($window) / count($window) : 0;
        $offset = count($days) - count($window);
        $line = [];
        foreach ($days as $i => $_) {
            $line[] = $i < $offset ? null : round($mean + $slope * (($i - $offset) - (count($window) - 1) / 2), 2);
        }

        return [
            'months' => array_values($months),
            'installment_series' => [
                'labels' => $scored->map(fn ($r) => $r['invoice_number'].' #'.$r['installment_no'].' ('.$r['due_date']->format('d M y').')')->all(),
                'days_late' => $days,
                'trend_line' => $line,
            ],
        ];
    }
}
