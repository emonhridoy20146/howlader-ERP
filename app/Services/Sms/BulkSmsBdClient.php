<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP client for https://bulksmsbd.net.
 *
 *  - smsapi          one message → one or many numbers (comma separated)
 *  - smsapimany      different message per number
 *  - getBalanceApi   account balance (BDT)
 */
final class BulkSmsBdClient
{
    public const SUCCESS_CODE = '202';

    /** Provider error codes, so the history page can say what went wrong. */
    public const CODES = [
        '202' => 'SMS submitted successfully',
        '1001' => 'Invalid number',
        '1002' => 'Sender ID not correct or disabled',
        '1003' => 'Required fields missing — contact the provider',
        '1005' => 'Provider internal error',
        '1006' => 'Balance validity not available',
        '1007' => 'Insufficient balance',
        '1011' => 'User ID not found',
        '1012' => 'Masking SMS must be sent in Bengali',
        '1013' => 'Sender ID has no gateway for this API key',
        '1014' => 'Sender type name not found for this sender',
        '1015' => 'Sender ID has no valid gateway for this API key',
        '1016' => 'Sender type active price info not found',
        '1017' => 'Sender type price info not found',
        '1018' => 'Account owner is disabled',
        '1019' => 'Sender type price for this account is disabled',
        '1020' => 'Parent account not found',
        '1021' => 'Parent active sender type price not found',
        '1031' => 'Account not verified — contact the provider',
        '1032' => 'Server IP is not whitelisted at the provider',
    ];

    public function isConfigured(): bool
    {
        return filled(config('sms.bulksmsbd.api_key'));
    }

    public function senderIdFor(string $senderType): ?string
    {
        $id = $senderType === 'masking'
            ? config('sms.bulksmsbd.sender_id_masking')
            : config('sms.bulksmsbd.sender_id_non_masking');

        return filled($id) ? (string) $id : null;
    }

    /**
     * Same message to one or more numbers.
     *
     * @param  list<string>  $numbers
     * @return array{ok: bool, code: string|null, message: string, raw: mixed}
     */
    public function sendOneToMany(array $numbers, string $message, string $senderId): array
    {
        return $this->post('smsapi', [
            'api_key' => (string) config('sms.bulksmsbd.api_key'),
            'type' => (string) config('sms.bulksmsbd.type', 'text'),
            'number' => implode(',', $numbers),
            'senderid' => $senderId,
            'message' => $message,
        ]);
    }

    /**
     * A different message per number.
     *
     * @param  list<array{to: string, message: string}>  $messages
     * @return array{ok: bool, code: string|null, message: string, raw: mixed}
     */
    public function sendManyToMany(array $messages, string $senderId): array
    {
        return $this->post('smsapimany', [
            'api_key' => (string) config('sms.bulksmsbd.api_key'),
            'senderid' => $senderId,
            'messages' => json_encode(array_values($messages), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return array{ok: bool, balance: float|null, message: string}
     */
    public function balance(bool $fresh = false): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'balance' => null, 'message' => 'API key not configured'];
        }

        $key = 'sms.bulksmsbd.balance';
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, (int) config('sms.bulksmsbd.balance_cache_seconds', 60), function (): array {
            try {
                $response = Http::timeout((int) config('sms.bulksmsbd.timeout', 30))
                    ->acceptJson()
                    ->get($this->url('getBalanceApi'), ['api_key' => (string) config('sms.bulksmsbd.api_key')]);

                $json = $response->json();
                if (is_array($json) && isset($json['balance']) && is_numeric($json['balance'])) {
                    return ['ok' => true, 'balance' => (float) $json['balance'], 'message' => 'OK'];
                }

                $code = is_array($json) ? (string) ($json['response_code'] ?? $json['error_code'] ?? '') : '';

                return [
                    'ok' => false,
                    'balance' => null,
                    'message' => self::CODES[$code] ?? ('Unexpected balance response: '.mb_substr($response->body(), 0, 120)),
                ];
            } catch (Throwable $e) {
                return ['ok' => false, 'balance' => null, 'message' => 'Could not reach BulkSMSBD: '.$e->getMessage()];
            }
        });
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{ok: bool, code: string|null, message: string, raw: mixed}
     */
    private function post(string $endpoint, array $payload): array
    {
        try {
            $response = Http::timeout((int) config('sms.bulksmsbd.timeout', 30))
                ->asForm()
                ->acceptJson()
                ->post($this->url($endpoint), $payload);

            return $this->parse($response);
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => null, 'message' => 'Could not reach BulkSMSBD: '.$e->getMessage(), 'raw' => null];
        }
    }

    /**
     * @return array{ok: bool, code: string|null, message: string, raw: mixed}
     */
    private function parse(Response $response): array
    {
        $json = $response->json();
        $raw = is_array($json) ? $json : ['body' => mb_substr($response->body(), 0, 1000), 'http_status' => $response->status()];

        $code = null;
        if (is_array($json)) {
            $code = isset($json['response_code']) ? (string) $json['response_code'] : null;
        } elseif (preg_match('/\b(202|10\d\d)\b/', $response->body(), $m) === 1) {
            $code = $m[1];
        }

        $ok = $code === self::SUCCESS_CODE;

        $message = $ok
            ? (string) (is_array($json) ? trim((string) ($json['success_message'] ?? '')) : '')
            : (string) (is_array($json) ? trim((string) ($json['error_message'] ?? '')) : '');

        if ($message === '') {
            $message = self::CODES[(string) $code] ?? ($response->successful() ? 'Unknown provider response' : 'HTTP '.$response->status());
        }

        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'raw' => $raw];
    }

    private function url(string $endpoint): string
    {
        return config('sms.bulksmsbd.base_url').'/'.$endpoint;
    }
}
