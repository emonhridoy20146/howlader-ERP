<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CustomerRiskExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, string>  $companyPad
     */
    public function __construct(private readonly array $report, private readonly array $companyPad) {}

    public function sheets(): array
    {
        $r = $this->report;
        $c = $r['customer'];
        $s = $r['summary'];
        $fmt = fn ($d) => $d?->format('Y-m-d') ?? '';
        $subtitle = $c->name.' ('.$c->customer_code.') · As of '.$r['as_of']->format('Y-m-d');

        $summary = [
            ['Customer', $c->name],
            ['Code', $c->customer_code],
            ['Branch', $c->branch?->name ?? ''],
            ['Phones', implode(', ', $c->normalizedPhoneNumbers())],
            ['Email', (string) $c->email],
            ['Address', trim($c->address.' '.$c->detailed_address)],
            ['ID', trim($c->id_type.' '.$c->id_number)],
            ['Credit limit', (float) $c->credit_limit],
            ['Status', ucfirst($r['status'])],
            ['Risk grade', $r['grade']],
            ['Risk score', $r['score']['total'] ?? 'N/A'],
            ['Risk level', $r['risk_level']],
            ['Late pattern', ucfirst($r['trend']['classification']).($r['trend']['improving'] ? ' (improving)' : '')],
            ['Pattern reason', $r['trend']['reason']],
            ['Total purchased', $s['total_purchased']],
            ['Total paid', $s['total_paid']],
            ['Installment outstanding', $s['outstanding']],
            ['Overdue amount', $s['overdue_amount']],
            ['Overdue installments', $s['overdue_count']],
            ['Matured installments', $s['matured_count']],
            ['On-time rate %', $s['on_time_rate'] === null ? 'N/A' : round($s['on_time_rate'] * 100, 1)],
            ['Avg days late', $s['avg_days_late']],
            ['Max days late', $s['max_days_late']],
            ['Lates last 3 / 6 / 12 months', $s['late_3m'].' / '.$s['late_6m'].' / '.$s['late_12m']],
            ['Longest / current late streak', $s['longest_streak'].' / '.$s['current_streak']],
            ['Reschedules', $s['reschedule_count']],
            ['Last payment', $fmt($s['last_payment_date'])],
            ['Recommendation', $r['recommendation']['text']],
            ['Suggested max amount', $r['recommendation']['max_amount'] ?? 'N/A'],
            ['Suggested max tenure (months)', $r['recommendation']['max_tenure_months'] ?? 'N/A'],
            ['Min down payment %', $r['recommendation']['min_down_payment_pct'] ?? 'N/A'],
        ];
        foreach ($r['score']['factors'] as $f) {
            $summary[] = ['Score: '.$f['label'], $f['value'].' → '.$f['points'].' / '.$f['weight']];
        }

        $installments = array_map(fn ($i) => [
            $i['invoice_number'], $i['installment_no'], $fmt($i['due_date']), $i['due_amount'], $i['late_fee'], $i['paid_amount'],
            $i['remaining'], $fmt($i['paid_date']), $i['days_late'], $i['band'], $i['status'], $i['plan_status'],
        ], $r['installments']);

        $payments = array_map(fn ($p) => [
            $fmt($p['date']), $p['invoice_number'], $p['installment_no'], $p['payment_number'], $p['method'], $p['amount'],
        ], $r['payments']);

        $guarantors = array_map(fn ($g) => [
            $g['name'], $g['relation'], implode(', ', $g['phones']), $g['nid_number'], $g['address'], implode(', ', $g['invoices']), $g['grade'] ?? '',
        ], $r['guarantors']);

        return [
            new CustomerRiskSheet('Summary', ['Field', 'Value'], $summary, $this->companyPad, $subtitle),
            new CustomerRiskSheet('Installments', ['Invoice', '#', 'Due date', 'Due', 'Late fee', 'Paid', 'Remaining', 'Paid date', 'Days late', 'Band', 'Status', 'Plan'], $installments, $this->companyPad, $subtitle),
            new CustomerRiskSheet('Payments', ['Date', 'Invoice', 'Installment', 'Payment no', 'Method', 'Amount'], $payments, $this->companyPad, $subtitle),
            new CustomerRiskSheet('Guarantors', ['Name', 'Type', 'Phones', 'NID', 'Address', 'Invoices', 'Grade'], $guarantors, $this->companyPad, $subtitle),
        ];
    }
}
