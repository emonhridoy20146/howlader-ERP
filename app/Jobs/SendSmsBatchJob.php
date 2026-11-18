<?php

namespace App\Jobs;

use App\Models\SmsBatch;
use App\Services\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers a bulk SMS batch that an admin created on the SMS page.
 * Dispatched only by that page — never by events or the scheduler.
 */
class SendSmsBatchJob implements ShouldQueue
{
    use Queueable;

    /** Never retry automatically: a retry could resend SMS the provider accepted. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $batchId)
    {
    }

    public function handle(SmsSender $sender): void
    {
        $batch = SmsBatch::query()->find($this->batchId);
        if ($batch) {
            $sender->processBatch($batch);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $batch = SmsBatch::query()->find($this->batchId);
        if ($batch) {
            app(SmsSender::class)->refreshCounts($batch);
        }
    }
}
