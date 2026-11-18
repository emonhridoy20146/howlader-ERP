<?php

namespace App\Dizicashier;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Obtains a Dizicashier bearer token via POST /api/account/login, caches it in a private JSON file,
 * and re-authenticates when the token is expired or rejected (401).
 */
final class DizicashierTokenResolver
{
    private const EXPIRY_BUFFER_SECONDS = 120;

    public function resolve(): string
    {
        $cached = $this->readCacheFile();
        if ($cached !== null && $this->isAccessTokenUsable($cached)) {
            return $this->normalizeBearerToken($cached['access_token']);
        }

        return $this->loginAndPersist();
    }

    /**
     * Drop cached access token so the next {@see resolve()} performs a fresh login.
     */
    public function clearCachedAccessToken(): void
    {
        $path = $this->cachePath();
        if (File::isFile($path)) {
            File::delete($path);
        }
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: string}|null
     */
    private function readCacheFile(): ?array
    {
        $path = $this->cachePath();
        if (! File::isFile($path)) {
            return null;
        }
        $raw = File::get($path);
        $decoded = json_decode($raw, true);

        return is_array($decoded) && isset($decoded['access_token']) && is_string($decoded['access_token'])
            ? $decoded
            : null;
    }

    /**
     * @param  array{access_token: string, refresh_token?: string, expires_at?: string}  $payload
     */
    private function writeCacheFile(array $payload): void
    {
        $path = $this->cachePath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    private function cachePath(): string
    {
        return (string) config('dizicashier.token_storage_path');
    }

    /**
     * @param  array{access_token: string, refresh_token?: string, expires_at?: string}  $cached
     */
    private function isAccessTokenUsable(array $cached): bool
    {
        if (! isset($cached['expires_at']) || ! is_string($cached['expires_at']) || $cached['expires_at'] === '') {
            return false;
        }
        try {
            $expires = Carbon::parse($cached['expires_at']);
        } catch (\Throwable) {
            return false;
        }

        return $expires->greaterThan(Carbon::now()->addSeconds(self::EXPIRY_BUFFER_SECONDS));
    }

    private function loginAndPersist(): string
    {
        $username = trim((string) config('dizicashier.username'));
        $password = (string) config('dizicashier.password');
        if ($username === '' || $password === '') {
            throw new RuntimeException(
                'Dizicashier login not configured: set DIZICASHIER_USERNAME and DIZICASHIER_PASSWORD in .env.'
            );
        }

        $base = rtrim((string) config('dizicashier.base_url'), '/');
        $response = Http::asJson()
            ->acceptJson()
            ->timeout(60)
            ->post($base.'/api/account/login', [
                'UserName' => $username,
                'Password' => $password,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Dizicashier login failed HTTP '.$response->status().': '.Str::limit($response->body(), 500, '…')
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Dizicashier login returned non-JSON body.');
        }

        $token = $data['access_token'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            $token = is_array($data['Data'] ?? null) ? ($data['Data']['access_token'] ?? null) : null;
        }
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Dizicashier login response has no access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 0);
        if ($expiresIn <= 0) {
            $expiresIn = 86400;
        }
        $expiresAt = Carbon::now()->addSeconds($expiresIn)->toIso8601String();

        $refresh = $data['refresh_token'] ?? null;
        $payload = [
            'access_token' => $this->normalizeBearerToken($token),
            'expires_at' => $expiresAt,
        ];
        if (is_string($refresh) && $refresh !== '') {
            $payload['refresh_token'] = $refresh;
        }

        $this->writeCacheFile($payload);

        return $payload['access_token'];
    }

    private function normalizeBearerToken(string $value): string
    {
        $value = trim($value);
        if (str_starts_with(strtolower($value), 'bearer ')) {
            $value = trim(substr($value, 7));
        }

        return $value;
    }
}
