<?php

namespace Tests\Unit;

use App\Dizicashier\DizicashierTokenResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DizicashierTokenResolverTest extends TestCase
{
    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenPath = sys_get_temp_dir().'/dizicashier-token-test-'.uniqid('', true).'.json';
        if (File::isFile($this->tokenPath)) {
            File::delete($this->tokenPath);
        }
        Config::set('dizicashier.token_storage_path', $this->tokenPath);
        Config::set('dizicashier.base_url', 'https://api.dizicashier.com');
        Config::set('dizicashier.username', 'testuser');
        Config::set('dizicashier.password', 'testpass');
    }

    protected function tearDown(): void
    {
        if (File::isFile($this->tokenPath)) {
            File::delete($this->tokenPath);
        }
        parent::tearDown();
    }

    public function test_login_persists_token_and_second_resolve_skips_http(): void
    {
        Http::fake([
            'https://api.dizicashier.com/api/account/login' => Http::response([
                'access_token' => 'access-from-login',
                'expires_in' => 7200,
                'token_type' => 'bearer',
            ], 200),
        ]);

        $resolver = new DizicashierTokenResolver;
        $this->assertSame('access-from-login', $resolver->resolve());
        $this->assertSame('access-from-login', $resolver->resolve());

        Http::assertSentCount(1);
        $this->assertTrue(File::isFile($this->tokenPath));
        $stored = json_decode(File::get($this->tokenPath), true);
        $this->assertIsArray($stored);
        $this->assertSame('access-from-login', $stored['access_token']);
        $this->assertArrayHasKey('expires_at', $stored);
    }

    public function test_clear_cache_forces_new_login(): void
    {
        Http::fake([
            'https://api.dizicashier.com/api/account/login' => Http::sequence()
                ->push(['access_token' => 'first', 'expires_in' => 7200], 200)
                ->push(['access_token' => 'second', 'expires_in' => 7200], 200),
        ]);

        $resolver = new DizicashierTokenResolver;
        $this->assertSame('first', $resolver->resolve());
        $resolver->clearCachedAccessToken();
        $this->assertSame('second', $resolver->resolve());
        Http::assertSentCount(2);
    }

    public function test_missing_credentials_throws(): void
    {
        Config::set('dizicashier.username', '');
        Config::set('dizicashier.password', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DIZICASHIER_USERNAME');

        (new DizicashierTokenResolver)->resolve();
    }
}
