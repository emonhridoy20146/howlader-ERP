<?php

namespace App\Exports;

use App\Exports\Concerns\PrependsCompanyHeaderRows;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class BranchTargetsExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    use PrependsCompanyHeaderRows;

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $companyPad
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $companyPad,
        private readonly string $reportTitle,
        private readonly string $subtitle,
        private readonly bool $showMonthly,
        private readonly bool $showAnnual,
        private readonly string $monthLabel,
        private readonly int $year,
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(function (array $row): array {
            $loc = $row['location'];
            $out = [
                $loc->code ?? '',
                $loc->name ?? '',
                ucfirst((string) ($loc->type ?? '')),
            ];

            if ($this->showMonthly) {
                $m = $row['monthly'] ?? null;
                $has = (bool) ($m['has_target'] ?? false);
                $out = array_merge($out, [
                    $has ? round((float) $m['sales']['actual'], 2) : '',
                    $has ? round((float) $m['sales']['target'], 2) : '',
                    $has && $m['sales']['pct'] !== null ? round((float) $m['sales']['pct'], 2) : '',
                    $has ? round((float) $m['collection']['actual'], 2) : '',
                    $has ? round((float) $m['collection']['target'], 2) : '',
                    $has && $m['collection']['pct'] !== null ? round((float) $m['collection']['pct'], 2) : '',
                ]);
            }

            if ($this->showAnnual) {
                $a = $row['annual'] ?? null;
                $has = (bool) ($a['has_target'] ?? false);
                $out = array_merge($out, [
                    $has ? round((float) $a['sales']['actual'], 2) : '',
                    $has ? round((float) $a['sales']['target'], 2) : '',
                    $has && $a['sales']['pct'] !== null ? round((float) $a['sales']['pct'], 2) : '',
                    $has ? round((float) $a['collection']['actual'], 2) : '',
                    $has ? round((float) $a['collection']['target'], 2) : '',
                    $has && $a['collection']['pct'] !== null ? round((float) $a['collection']['pct'], 2) : '',
                ]);
            }

            return $out;
        });
    }

    public function headings(): array
    {
        $heads = ['Branch code', 'Branch', 'Type'];

        if ($this->showMonthly) {
            $prefix = 'Month '.$this->monthLabel.' · ';
            $heads = array_merge($heads, [
                $prefix.'Sales actual',
                $prefix.'Sales target',
                $prefix.'Sales %',
                $prefix.'Collection actual',
                $prefix.'Collection target',
                $prefix.'Collection %',
            ]);
        }

        if ($this->showAnnual) {
            $prefix = 'Annual '.$this->year.' · ';
            $heads = array_merge($heads, [
                $prefix.'Sales actual',
                $prefix.'Sales target',
                $prefix.'Sales %',
                $prefix.'Collection actual',
                $prefix.'Collection target',
                $prefix.'Collection %',
            ]);
        }

        return $heads;
    }

    public function title(): string
    {
        return 'Branch targets';
    }

    /**
     * @return array{company_pad_name: string, company_pad_tagline: string, reportTitle: string, subtitle: string}
     */
    protected function exportHeaderMeta(): array
    {
        return [
            'company_pad_name' => $this->companyPad['company_pad_name'] ?? '',
            'company_pad_tagline' => $this->companyPad['company_pad_tagline'] ?? '',
            'reportTitle' => $this->reportTitle,
            'subtitle' => $this->subtitle,
        ];
    }
}
