<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Jobs\SendSmsBatchJob;
use App\Models\SmsBatch;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sends SMS on explicit request only. Nothing in the application calls this
 * class automatically — only the SMS page controller does.
 */
final class SmsSender
{
    public function __construct(private readonly BulkSmsBdClient $client)
    {
    }

    /**
     * Send one message to one or more numbers right away.
     *
     * @param  list<array{phone: string, customer_id?: int|null, name?: string|null}>  $recipients
     */
    public function sendSingle(array $recipients, string $message, string $senderType, User $user, string $token): SmsBatch
    {
        $this->assertReady($senderType);

        $batch = $this->createBatch(
            kind: 'single',
            audience: 'single',
            filters: [],
            template: $message,
            senderType: $senderType,
            user: $user,
            token: $token,
            rows: array_map(fn (array $r): array => [
                'phone' => $r['phone'],
                'customer_id' => $r['customer_id'] ?? null,
                'name' => $r['name'] ?? null,
                'message' => $message,
            ], $recipients),
        );

        $this->processBatch($batch);

        return $batch->refresh();
    }

    /**
     * Store a bulk batch and hand it to the queue worker.
     *
     * @param  list<array{phone: string, customer_id: int|null, name: string|null, message: string}>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function queueBulk(array $rows, string $template, string $audience, array $filters, string $senderType, User $user, string $token): SmsBatch
    {
        $this->assertReady($senderType);

        $batch = $this->createBatch('bulk', $audience, $filters, $template, $senderType, $user, $token, $rows);

        SendSmsBatchJob::dispatch($batch->id)->onQueue((string) config('sms.queue', 'default'));

        return $batch;
    }

    /**
     * Send every pending message of a batch. Safe to call again: only rows
     * still "pending" are sent, so nothing is delivered twice.
     */
    public function processBatch(SmsBatch $batch): void
    {
        $claimed = SmsBatch::query()
            ->whereKey($batch->id)
            ->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'processing', 'started_at' => $batch->started_at ?? now()]);

        if ($claimed === 0) {
            return;
        }

        $senderId = (string) $batch->sender_id;
        $chunkSize = max(1, min(500, (int) config('sms.chunk_size', 100)));

        SmsMessage::query()
            ->where('sms_batch_id', $batch->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($messages) use ($senderId): void {
                $this->sendChunk($messages->all(), $senderId);
            });

        $this->refreshCounts($batch);
    }

    /**
     * @param  list<SmsMessage>  $messages
     */
    private function sendChunk(array $messages, string $senderId): void
    {
        if (! config('sms.enabled', true)) {
            $this->mark($messages, ['ok' => false, 'code' => 'disabled', 'message' => 'SMS sending is disabled (SMS_ENABLED=false)', 'raw' => null]);

            return;
        }

        $texts = array_unique(array_map(fn (SmsMessage $m): string => $m->message, $messages));

        $result = count($texts) === 1
            ? $this->client->sendOneToMany(array_map(fn (SmsMessage $m): string => $m->phone, $messages), reset($texts), $senderId)
            : $this->client->sendManyToMany(array_map(fn (SmsMessage $m): array => ['to' => $m->phone, 'message' => $m->message], $messages), $senderId);

        $this->mark($messages, $result);
    }

    /**
     * @param  list<SmsMessage>  $messages
     * @param  array{ok: bool, code: string|null, message: string, raw: mixed}  $result
     */
    private function mark(array $messages, array $result): void
    {
        SmsMessage::query()
            ->whereKey(array_map(fn (SmsMessage $m): int => $m->id, $messages))
            ->update([
                'status' => $result['ok'] ? 'sent' : 'failed',
                'response_code' => $result['code'],
                'response_message' => mb_substr($result['message'], 0, 250),
                'provider_response' => $result['raw'] === null ? null : json_encode($result['raw'], JSON_UNESCAPED_UNICODE),
                'sent_at' => now(),
            ]);
    }

    public function refreshCounts(SmsBatch $batch): void
    {
        $counts = SmsMessage::query()
            ->where('sms_batch_id', $batch->id)
            ->selectRaw("SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'pending') AS pending, SUM(CASE WHEN status = 'sent' THEN parts ELSE 0 END) AS parts")
            ->first();

        $pending = (int) ($counts->pending ?? 0);
        $sent = (int) ($counts->sent ?? 0);

        $batch->forceFill([
            'sent_count' => $sent,
            'failed_count' => (int) ($counts->failed ?? 0),
            'total_parts' => (int) ($counts->parts ?? 0),
            'status' => $pending > 0 ? 'processing' : ($sent > 0 ? 'completed' : 'failed'),
            'finished_at' => $pending > 0 ? null : now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array{phone: string, customer_id: int|null, name: string|null, message: string}>  $rows
     */
    private function createBatch(string $kind, string $audience, array $filters, string $template, string $senderType, User $user, string $token, array $rows): SmsBatch
    {
        if (SmsBatch::query()->where('request_token', $token)->exists()) {
            throw new RuntimeException('This SMS request was already submitted.');
        }

        // One SMS per number per batch.
        $unique = [];
        foreach ($rows as $row) {
            $phone = SmsText::normalizePhone($row['phone']);
            if ($phone !== null && trim($row['message']) !== '' && ! isset($unique[$phone])) {
                $row['phone'] = $phone;
                $unique[$phone] = $row;
            }
        }

        if ($unique === []) {
            throw new RuntimeException('No valid recipient phone numbers.');
        }

        $max = (int) config('sms.max_bulk_recipients', 5000);
        if (count($unique) > $max) {
            throw new RuntimeException("Too many recipients (".count($unique)."). The limit per send is {$max}.");
        }

        $senderId = $this->client->senderIdFor($senderType);

        return DB::transaction(function () use ($kind, $audience, $filters, $template, $senderType, $senderId, $user, $token, $unique): SmsBatch {
            $batch = SmsBatch::query()->create([
                'request_token' => $token,
                'kind' => $kind,
                'audience' => $audience,
                'filters' => $filters,
                'message_template' => $template,
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'total_recipients' => count($unique),
                'status' => 'queued',
                'sent_by' => $user->id,
            ]);

            $now = now();
            $insert = [];
            foreach ($unique as $row) {
                $info = SmsText::analyze($row['message']);
                $insert[] = [
                    'sms_batch_id' => $batch->id,
                    'customer_id' => $row['customer_id'] ?? null,
                    'recipient_name' => isset($row['name']) ? Str::limit((string) $row['name'], 250, '') : null,
                    'phone' => $row['phone'],
                    'message' => $row['message'],
                    'sender_type' => $senderType,
                    'sender_id' => $senderId,
                    'is_unicode' => $info['unicode'],
                    'characters' => min(65535, $info['characters']),
                    'parts' => max(1, $info['parts']),
                    'status' => 'pending',
                    'audience' => $audience,
                    'sent_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($insert, 500) as $chunk) {
                SmsMessage::query()->insert($chunk);
            }

            return $batch;
        });
    }

    private function assertReady(string $senderType): void
    {
        if (! in_array($senderType, ['masking', 'non_masking'], true)) {
            throw new RuntimeException('Unknown sender type.');
        }

        if (config('sms.enabled', true) && ! $this->client->isConfigured()) {
            throw new RuntimeException('BulkSMSBD API key is not configured. Set BULKSMSBD_API_KEY in .env.');
        }

        if (config('sms.enabled', true) && $this->client->senderIdFor($senderType) === null) {
            $env = $senderType === 'masking' ? 'BULKSMSBD_SENDER_ID_MASKING' : 'BULKSMSBD_SENDER_ID_NON_MASKING';
            throw new RuntimeException("No sender ID configured for {$senderType} SMS. Set {$env} in .env.");
        }
    }
}
