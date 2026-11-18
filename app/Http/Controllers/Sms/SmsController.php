<?php

namespace App\Http\Controllers\Sms;

use App\Http\Controllers\Controller;
use App\Jobs\SendSmsBatchJob;
use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\SmsBatch;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use App\Services\Sms\BulkSmsBdClient;
use App\Services\Sms\SmsAudienceService;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The SMS page. Every send here is a deliberate action by an authorised
 * user; the module has no automatic triggers.
 */
class SmsController extends Controller
{
    public function __construct(
        private readonly SmsAudienceService $audiences,
        private readonly SmsSender $sender,
        private readonly BulkSmsBdClient $client,
    ) {
    }

    public function index(): View
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $sent = SmsMessage::query()->where('status', 'sent');

        $stats = [
            'today' => (clone $sent)->where('sent_at', '>=', $today)->count(),
            'today_parts' => (int) (clone $sent)->where('sent_at', '>=', $today)->sum('parts'),
            'month' => (clone $sent)->where('sent_at', '>=', $monthStart)->count(),
            'month_parts' => (int) (clone $sent)->where('sent_at', '>=', $monthStart)->sum('parts'),
            'total' => (clone $sent)->count(),
            'total_parts' => (int) (clone $sent)->sum('parts'),
            'failed' => SmsMessage::query()->where('status', 'failed')->count(),
            'pending' => SmsMessage::query()->where('status', 'pending')->count(),
        ];

        $balance = $this->client->balance();

        $estimate = [];
        foreach (['masking' => 'rate_masking', 'non_masking' => 'rate_non_masking'] as $type => $key) {
            $rate = (float) config('sms.bulksmsbd.'.$key, 0);
            $estimate[$type] = ($balance['ok'] && $rate > 0) ? (int) floor($balance['balance'] / $rate) : null;
        }

        return view('sms.index', [
            'stats' => $stats,
            'balance' => $balance,
            'estimate' => $estimate,
            'configured' => $this->client->isConfigured(),
            'enabled' => (bool) config('sms.enabled', true),
            'senderIds' => [
                'masking' => $this->client->senderIdFor('masking'),
                'non_masking' => $this->client->senderIdFor('non_masking'),
            ],
            'defaultSenderType' => (string) config('sms.default_sender_type', 'non_masking'),
            'audiences' => SmsAudienceService::definitions(),
            'placeholders' => SmsAudienceService::placeholders(),
            'templates' => SmsTemplate::query()->where('is_active', true)->orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'body']),
            'branches' => Location::query()->orderBy('name')->get(['id', 'name']),
            'company' => SmsAudienceService::companyName(),
            'recentBatches' => SmsBatch::query()->with('sender:id,name')->latest('id')->limit(8)->get(),
            'maxRecipients' => (int) config('sms.max_bulk_recipients', 5000),
        ]);
    }

    public function balance(): JsonResponse
    {
        return response()->json($this->client->balance(fresh: true));
    }

    /** Customer list for a scenario — read only, nothing is sent. */
    public function audience(Request $request): JsonResponse
    {
        $data = $this->validateAudience($request);

        $result = $this->audiences->resolve($data['audience'], $data['filters']);

        return response()->json([
            'rows' => $result['rows'],
            'skipped' => $result['skipped'],
            'count' => count($result['rows']),
        ]);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $customers = Customer::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('customer_code', 'like', $like))
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'customer_code', 'phone', 'phone_numbers']);

        $dues = SalesInvoice::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->whereIn('status', ['posted', 'active'])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(amount_due) AS due')
            ->pluck('due', 'customer_id');

        return response()->json($customers->map(function (Customer $c) use ($dues): array {
            $phones = array_values(array_filter(array_map([SmsText::class, 'normalizePhone'], $c->normalizedPhoneNumbers())));

            return [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->customer_code,
                'phones' => array_values(array_unique($phones)),
                'total_due' => SmsText::money($dues->get($c->id, 0)),
            ];
        }));
    }

    public function sendSingle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phones' => ['required', 'string', 'max:5000'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'message' => ['required', 'string', 'max:1000'],
            'sender_type' => ['required', Rule::in(['masking', 'non_masking'])],
            'bangla_digits' => ['nullable', 'boolean'],
            'request_token' => ['required', 'uuid'],
        ]);

        $parsed = SmsText::parsePhoneList($data['phones']);
        if ($parsed['valid'] === []) {
            return back()->withInput()->withErrors(['phones' => 'Enter at least one valid Bangladeshi mobile number (01XXXXXXXXX).']);
        }

        $customer = isset($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null;
        $message = $data['message'];

        if ($customer) {
            $due = (float) SalesInvoice::query()->where('customer_id', $customer->id)->whereIn('status', ['posted', 'active'])->sum('amount_due');
            $message = SmsText::render($message, [
                'name' => $customer->name,
                'customer_code' => $customer->customer_code,
                'company' => SmsAudienceService::companyName(),
                'total_due' => SmsText::money($due),
            ], (bool) ($data['bangla_digits'] ?? false));
        } else {
            $message = SmsText::render($message, ['company' => SmsAudienceService::companyName()]);
        }

        try {
            $batch = $this->sender->sendSingle(
                array_map(fn (string $p): array => ['phone' => $p, 'customer_id' => $customer?->id, 'name' => $customer?->name], $parsed['valid']),
                $message,
                $data['sender_type'],
                $request->user(),
                $data['request_token'],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['message' => $e->getMessage()]);
        }

        $note = $parsed['invalid'] !== [] ? ' Skipped invalid: '.implode(', ', array_slice($parsed['invalid'], 0, 10)) : '';

        return redirect()->route('sms.batches.show', $batch)->with(
            $batch->sent_count > 0 ? 'status' : 'error',
            "SMS: {$batch->sent_count} sent, {$batch->failed_count} failed.".$note
        );
    }

    public function sendBulk(Request $request): RedirectResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'sender_type' => ['required', Rule::in(['masking', 'non_masking'])],
            'bangla_digits' => ['nullable', 'boolean'],
            'request_token' => ['required', 'uuid'],
            'customer_ids' => ['required', 'string'],
        ]);

        $data = $this->validateAudience($request);
        $banglaDigits = $request->boolean('bangla_digits');

        $selected = array_flip(array_filter(array_map('intval', explode(',', (string) $request->input('customer_ids')))));
        if ($selected === []) {
            return back()->withErrors(['customer_ids' => 'Select at least one customer.']);
        }

        // Recompute the audience on the server so the values in each SMS are current.
        $result = $this->audiences->resolve($data['audience'], $data['filters']);

        $rows = [];
        foreach ($result['rows'] as $row) {
            if (isset($selected[$row['customer_id']])) {
                $rows[] = [
                    'customer_id' => $row['customer_id'],
                    'name' => $row['name'],
                    'phone' => $row['phone'],
                    'message' => SmsText::render((string) $request->input('message'), $row['vars'], $banglaDigits),
                ];
            }
        }

        if ($rows === []) {
            return back()->withErrors(['customer_ids' => 'None of the selected customers match the filter any more. Reload the list.']);
        }

        try {
            $batch = $this->sender->queueBulk(
                $rows,
                (string) $request->input('message'),
                $data['audience'],
                $data['filters'] + ['bangla_digits' => $banglaDigits],
                (string) $request->input('sender_type'),
                $request->user(),
                (string) $request->input('request_token'),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return redirect()->route('sms.batches.show', $batch)
            ->with('status', count($rows).' SMS queued. The page refreshes while they are being sent.');
    }

    public function history(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'sender_type' => (string) $request->input('sender_type', ''),
            'audience' => (string) $request->input('audience', ''),
            'from' => (string) $request->input('from', ''),
            'to' => (string) $request->input('to', ''),
        ];

        $messages = SmsMessage::query()
            ->with(['sender:id,name', 'customer:id,name,customer_code'])
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $like = '%'.addcslashes($filters['q'], '%_\\').'%';
                $q->where(fn ($s) => $s->where('phone', 'like', $like)->orWhere('recipient_name', 'like', $like)->orWhere('message', 'like', $like));
            })
            ->when(in_array($filters['status'], ['sent', 'failed', 'pending'], true), fn ($q) => $q->where('status', $filters['status']))
            ->when(in_array($filters['sender_type'], ['masking', 'non_masking'], true), fn ($q) => $q->where('sender_type', $filters['sender_type']))
            ->when($filters['audience'] !== '', fn ($q) => $q->where('audience', $filters['audience']))
            ->when($this->isDate($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($this->isDate($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest('id')
            ->paginate(min(200, max(10, (int) $request->input('per_page', 50))))
            ->withQueryString();

        return view('sms.history', [
            'messages' => $messages,
            'filters' => $filters,
            'audiences' => SmsAudienceService::definitions(),
        ]);
    }

    public function showBatch(SmsBatch $batch): View
    {
        $batch->load('sender:id,name');

        return view('sms.batch', [
            'batch' => $batch,
            'messages' => $batch->messages()->latest('id')->paginate(100),
            'audiences' => SmsAudienceService::definitions(),
        ]);
    }

    public function batchStatus(SmsBatch $batch): JsonResponse
    {
        $this->sender->refreshCounts($batch);

        return response()->json($batch->only(['status', 'total_recipients', 'sent_count', 'failed_count', 'total_parts']));
    }

    /** Re-dispatch messages still pending (e.g. if the queue worker was stopped). */
    public function resumeBatch(SmsBatch $batch): RedirectResponse
    {
        $pending = $batch->messages()->where('status', 'pending')->count();
        if ($pending === 0) {
            return back()->with('status', 'Nothing left to send in this batch.');
        }

        $batch->forceFill(['status' => 'queued'])->save();
        SendSmsBatchJob::dispatch($batch->id)->onQueue((string) config('sms.queue', 'default'));

        return back()->with('status', "{$pending} pending SMS re-queued.");
    }

    /** Copy failed messages into a fresh batch so they can be retried deliberately. */
    public function retryFailed(Request $request, SmsBatch $batch): RedirectResponse
    {
        $failed = $batch->messages()->where('status', 'failed')->get();
        if ($failed->isEmpty()) {
            return back()->with('status', 'No failed SMS in this batch.');
        }

        try {
            $retry = $this->sender->queueBulk(
                $failed->map(fn (SmsMessage $m): array => [
                    'customer_id' => $m->customer_id,
                    'name' => $m->recipient_name,
                    'phone' => $m->phone,
                    'message' => $m->message,
                ])->all(),
                $batch->message_template,
                (string) $batch->audience,
                ['retry_of' => $batch->id],
                $batch->sender_type,
                $request->user(),
                (string) Str::uuid(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return redirect()->route('sms.batches.show', $retry)->with('status', $failed->count().' failed SMS queued again.');
    }

    /* ---------------------------- templates ---------------------------- */

    public function templates(): View
    {
        return view('sms.templates', [
            'templates' => SmsTemplate::query()->orderBy('category')->orderBy('name')->get(),
            'audiences' => SmsAudienceService::definitions(),
            'placeholders' => SmsAudienceService::placeholders(),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        SmsTemplate::query()->create($this->validateTemplate($request) + ['created_by' => $request->user()->id]);

        return back()->with('status', 'Template saved.');
    }

    public function updateTemplate(Request $request, SmsTemplate $template): RedirectResponse
    {
        $template->update($this->validateTemplate($request));

        return back()->with('status', 'Template updated.');
    }

    public function destroyTemplate(SmsTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('status', 'Template deleted.');
    }

    /* ----------------------------- helpers ----------------------------- */

    /**
     * @return array{audience: string, filters: array<string, mixed>}
     */
    private function validateAudience(Request $request): array
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(array_keys(SmsAudienceService::definitions()))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'exists:locations,id'],
            'sale_type' => ['nullable', Rule::in(['cash', 'credit', 'installment'])],
            'min_due' => ['nullable', 'numeric', 'min:0'],
            'min_overdue_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'audience' => $data['audience'],
            'filters' => [
                'from' => $from,
                'to' => $to,
                'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
                'sale_type' => $data['sale_type'] ?? null,
                'min_due' => isset($data['min_due']) ? (float) $data['min_due'] : null,
                'min_overdue_days' => isset($data['min_overdue_days']) ? (int) $data['min_overdue_days'] : null,
                'q' => $data['q'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_merge(['general'], array_keys(SmsAudienceService::definitions())))],
            'body' => ['required', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
