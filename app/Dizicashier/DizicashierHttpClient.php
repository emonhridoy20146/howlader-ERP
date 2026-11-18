<?php

namespace App\Dizicashier;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DizicashierHttpClient
{
    public function __construct(
        private readonly DizicashierTokenResolver $tokenResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getDecoded(string $path, array $query = []): array
    {
        $response = $this->get($path, $query);
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Dizicashier API returned non-JSON body for '.$path);
        }
        if (($data['Success'] ?? null) !== true) {
            $msg = $data['ErrorMessage'] ?? $data['errorMessage'] ?? json_encode($data);
            throw new RuntimeException('Dizicashier API error on '.$path.': '.$msg);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        $response = $this->sendAuthorizedGet($path, $query);
        if ($response->status() === 401) {
            $this->tokenResolver->clearCachedAccessToken();
            $response = $this->sendAuthorizedGet($path, $query);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function sendAuthorizedGet(string $path, array $query): Response
    {
        $base = rtrim((string) config('dizicashier.base_url'), '/');
        $path = '/'.ltrim($path, '/');
        $token = $this->tokenResolver->resolve();

        return Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])
            ->timeout(120)
            ->get($base.$path, $query);
    }
}
