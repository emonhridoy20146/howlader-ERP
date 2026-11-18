<?php

namespace App\Imports;

use App\Services\ProductBulkUpdateService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductBulkUpdateImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    /** @var array{updated: int, skipped: int, errors: list<string>} */
    public array $result = [
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    public function __construct(
        private readonly ProductBulkUpdateService $bulkUpdateService,
    ) {}

    public function collection(Collection $rows): void
    {
        $this->result = $this->bulkUpdateService->processImportRows($rows);
    }
}
