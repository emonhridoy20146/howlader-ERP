<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Human-readable document numbers: {PREFIX}-{PERIOD}-{SEQUENCE}.
 * Sequence is per (code_type, period), persisted and incremented under row lock.
 */
final class DocumentNumberGenerator
{
    public const TYPE_SALES_INVOICE = 'sales_invoice';

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_PURCHASE_ORDER = 'purchase_order';

    public const TYPE_GOODS_RECEIPT = 'goods_receipt';

    public const TYPE_GOODS_RECEIPT_DIZI = 'goods_receipt_dizicashier';

    public const TYPE_STOCK_TRANSFER = 'stock_transfer';

    public const TYPE_RETURN_ORDER = 'return_order';

    public const TYPE_PAYMENT_INBOUND = 'payment_inbound';

    public const TYPE_PAYMENT_REFUND = 'payment_refund_outbound';

    public const TYPE_SERVICE_JOB = 'service_job';

    public const TYPE_SERIAL_INTERNAL_PURCHASE = 'serial_internal_purchase';

    public const TYPE_SERIAL_INTERNAL_SALE = 'serial_internal_sale';

    public const TYPE_SERIAL_BACKFILL = 'serial_backfill';

    /** Dizicashier import: monotonic codes (single counter per type, not date-based). */
    public const TYPE_DIZICASHIER_IMPORT_PRODUCT_SKU = 'dizicashier_import_product_sku';

    public const TYPE_DIZICASHIER_IMPORT_CUSTOMER_CODE = 'dizicashier_import_customer_code';

    public const TYPE_DIZICASHIER_IMPORT_PO_FALLBACK = 'dizicashier_import_po_fallback';

    public const TYPE_DIZICASHIER_IMPORT_INVOICE_FALLBACK = 'dizicashier_import_invoice_fallback';

    /**
     * Reserved period bucket for {@see nextGlobalSequence()} (not a calendar period).
     */
    private const GLOBAL_SEQUENCE_PERIOD = '0';

    /**
     * @param  non-empty-string  $codeType
     * @param  non-empty-string  $prefix
     * @param  non-empty-string  $periodFormat  PHP date() format fragment (e.g. Ymd, ym, Ym)
     */
    public function next(
        string $codeType,
        string $prefix,
        ?DateTimeInterface $at = null,
        string $periodFormat = 'Ymd',
        int $sequenceWidth = 5,
    ): string {
        if ($sequenceWidth < 1 || $sequenceWidth > 9) {
            throw new RuntimeException('sequenceWidth must be between 1 and 9.');
        }

        $moment = $at !== null ? Carbon::parse($at) : now();
        $period = $moment->format($periodFormat);

        return DB::transaction(function () use ($codeType, $prefix, $period, $sequenceWidth): string {
            $attempts = 0;
            while ($attempts < 5) {
                $attempts++;
                try {
                    $row = DocumentSequence::query()
                        ->where('code_type', $codeType)
                        ->where('period', $period)
                        ->lockForUpdate()
                        ->first();

                    if ($row === null) {
                        DocumentSequence::query()->create([
                            'code_type' => $codeType,
                            'period' => $period,
                            'last_number' => 0,
                        ]);

                        $row = DocumentSequence::query()
                            ->where('code_type', $codeType)
                            ->where('period', $period)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    $row->increment('last_number');
                    $n = (int) $row->fresh()->last_number;

                    return $this->format($prefix, $period, $n, $sequenceWidth);
                } catch (QueryException $e) {
                    if ($attempts >= 5 || ! $this->isUniqueConstraintViolation($e)) {
                        throw $e;
                    }
                }
            }

            throw new RuntimeException('Could not allocate document number after retries.');
        });
    }

    /**
     * Next number from {@see DocumentSequence} using a fixed period bucket (monotonic import / migration counters).
     *
     * @param  non-empty-string  $codeType
     * @param  non-empty-string  $prefix
     */
    public function nextGlobalSequence(string $codeType, string $prefix, int $sequenceWidth = 6): string
    {
        if ($sequenceWidth < 1 || $sequenceWidth > 9) {
            throw new RuntimeException('sequenceWidth must be between 1 and 9.');
        }

        $period = self::GLOBAL_SEQUENCE_PERIOD;

        return DB::transaction(function () use ($codeType, $prefix, $period, $sequenceWidth): string {
            $attempts = 0;
            while ($attempts < 5) {
                $attempts++;
                try {
                    $row = DocumentSequence::query()
                        ->where('code_type', $codeType)
                        ->where('period', $period)
                        ->lockForUpdate()
                        ->first();

                    if ($row === null) {
                        DocumentSequence::query()->create([
                            'code_type' => $codeType,
                            'period' => $period,
                            'last_number' => 0,
                        ]);

                        $row = DocumentSequence::query()
                            ->where('code_type', $codeType)
                            ->where('period', $period)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    $row->increment('last_number');
                    $n = (int) $row->fresh()->last_number;

                    return $this->format($prefix, $period, $n, $sequenceWidth);
                } catch (QueryException $e) {
                    if ($attempts >= 5 || ! $this->isUniqueConstraintViolation($e)) {
                        throw $e;
                    }
                }
            }

            throw new RuntimeException('Could not allocate global sequence number after retries.');
        });
    }

    /**
     * Next number from {@see DocumentSequence} using a fixed period bucket, formatted without the period string.
     * Output format: {PREFIX}-{SEQUENCE}
     *
     * @param  non-empty-string  $codeType
     * @param  non-empty-string  $prefix
     */
    public function nextSimpleSequence(string $codeType, string $prefix, int $sequenceWidth = 5): string
    {
        if ($sequenceWidth < 1 || $sequenceWidth > 9) {
            throw new RuntimeException('sequenceWidth must be between 1 and 9.');
        }

        $period = self::GLOBAL_SEQUENCE_PERIOD;

        return DB::transaction(function () use ($codeType, $prefix, $period, $sequenceWidth): string {
            $attempts = 0;
            while ($attempts < 5) {
                $attempts++;
                try {
                    $row = DocumentSequence::query()
                        ->where('code_type', $codeType)
                        ->where('period', $period)
                        ->lockForUpdate()
                        ->first();

                    if ($row === null) {
                        DocumentSequence::query()->create([
                            'code_type' => $codeType,
                            'period' => $period,
                            'last_number' => 0,
                        ]);

                        $row = DocumentSequence::query()
                            ->where('code_type', $codeType)
                            ->where('period', $period)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    $row->increment('last_number');
                    $n = (int) $row->fresh()->last_number;

                    return $prefix.'-'.str_pad((string) $n, $sequenceWidth, '0', STR_PAD_LEFT);
                } catch (QueryException $e) {
                    if ($attempts >= 5 || ! $this->isUniqueConstraintViolation($e)) {
                        throw $e;
                    }
                }
            }

            throw new RuntimeException('Could not allocate simple sequence number after retries.');
        });
    }

    /**
     * @param  non-empty-string  $prefix
     * @param  non-empty-string  $period
     */
    private function format(string $prefix, string $period, int $n, int $width): string
    {
        return $prefix.'-'.$period.'-'.str_pad((string) $n, $width, '0', STR_PAD_LEFT);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        if ($sqlState === '23000') {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'duplicate')
            || str_contains($msg, 'unique constraint failed');
    }
}
