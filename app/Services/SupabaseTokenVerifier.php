<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use RuntimeException;

class SupabaseTokenVerifier
{
    private const CACHE_KEY = 'supabase:jwks';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * Validate the incoming Supabase JWT and return its claims.
     *
     * @throws AuthenticationException
     */
    public function verify(string $token): array
    {
        if (trim($token) === '') {
            throw new AuthenticationException('Supabase token missing.');
        }

        $config = config('services.supabase', []);
        if (empty($config)) {
            throw new RuntimeException('Supabase configuration is missing.');
        }

        $claimsObject = $this->decodeToken($token, $config);
        $claims = json_decode(json_encode($claimsObject), true);

        if (!is_array($claims)) {
            throw new AuthenticationException('Supabase token could not be decoded.');
        }

        $this->assertIssuer($claims, Arr::get($config, 'jwt_issuer'));
        $this->assertAudience($claims, Arr::get($config, 'jwt_audience'));

        return $claims;
    }

    private function decodeToken(string $token, array $config): object
    {
        $secret = Arr::get($config, 'jwt_secret');
        if (!empty($secret)) {
            return JWT::decode($token, new Key($secret, 'HS256'));
        }

        $jwksUrl = Arr::get($config, 'jwks_url');
        if (!$jwksUrl) {
            throw new RuntimeException('Supabase JWKS URL or JWT secret must be configured.');
        }

        $kid = $this->extractKid($token);
        $cacheTtl = (int) Arr::get($config, 'cache_ttl', 600);
        /** @var array<string, Key> $keys */
        $keys = $this->cache->remember(self::CACHE_KEY, $cacheTtl, function () use ($jwksUrl) {
            $response = $this->http->get($jwksUrl);

            if ($response->failed()) {
                throw new RuntimeException('Unable to fetch Supabase JWKS.');
            }

            return JWK::parseKeySet($response->json());
        });

        if (!isset($keys[$kid])) {
            $this->cache->forget(self::CACHE_KEY);
            throw new AuthenticationException('No matching Supabase signing key found.');
        }

        return JWT::decode($token, $keys[$kid]);
    }

    private function extractKid(string $token): string
    {
        $segments = explode('.', $token);
        if (count($segments) < 2) {
            throw new AuthenticationException('Supabase token is malformed.');
        }

        $header = json_decode($this->base64UrlDecode($segments[0]), true);
        if (!is_array($header) || empty($header['kid'])) {
            throw new AuthenticationException('Supabase token header is missing a key id.');
        }

        return $header['kid'];
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    private function assertIssuer(array $claims, ?string $expectedIssuer): void
    {
        if (!$expectedIssuer) {
            return;
        }

        if (($claims['iss'] ?? null) !== $expectedIssuer) {
            throw new AuthenticationException('Supabase token has an unexpected issuer.');
        }
    }

    private function assertAudience(array $claims, ?string $expectedAudience): void
    {
        if (!$expectedAudience) {
            return;
        }

        $audience = $claims['aud'] ?? null;

        if (is_array($audience) && in_array($expectedAudience, $audience, true)) {
            return;
        }

        if ($audience === $expectedAudience) {
            return;
        }

        throw new AuthenticationException('Supabase token has an unexpected audience.');
    }
}



