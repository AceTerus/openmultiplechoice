<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SupabaseTokenVerifier;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupabaseTokenController extends Controller
{
    public function __construct(
        private readonly SupabaseTokenVerifier $verifier,
    ) {
    }

    public function exchange(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?: trim((string) $request->input('token'));

        if ($token === '') {
            throw ValidationException::withMessages([
                'token' => ['A Supabase access token is required.'],
            ]);
        }

        $claims = $this->verifier->verify($token);
        $user = $this->syncUser($claims);

        $plainToken = $user->createToken('supabase-bridge')->plainTextToken;

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'public_name' => $user->public_name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function syncUser(array $claims): User
    {
        $supabaseId = $claims['sub'] ?? null;
        if (!$supabaseId) {
            throw new AuthenticationException('Supabase token is missing a subject.');
        }

        $email = $claims['email'] ?? null;
        $metadata = Arr::get($claims, 'user_metadata', []);
        $preferredName = $this->extractPreferredName($email, is_array($metadata) ? $metadata : []);

        $user = User::where('supabase_id', $supabaseId)->first();

        if (!$user && $email) {
            $user = User::where('email', $email)->first();
        }

        if (!$user) {
            $user = new User();
            $user->supabase_id = $supabaseId;
            $user->name = $this->uniqueValue('name', $preferredName);
            $user->public_name = $this->uniqueValue('public_name', $user->name);
            $user->email = $email ?? sprintf('%s@supabase.local', $supabaseId);
        } else {
            if (empty($user->supabase_id)) {
                $user->supabase_id = $supabaseId;
            }

            if (!$user->public_name) {
                $user->public_name = $this->uniqueValue('public_name', $preferredName, $user->id);
            }

            if (!$user->name) {
                $user->name = $this->uniqueValue('name', $preferredName, $user->id);
            }
        }

        if ($email) {
            $user->email = $email;
        }

        if (($claims['email_confirmed_at'] ?? null) || Arr::get($claims, 'email_confirmed', false)) {
            $user->email_verified_at = $user->email_verified_at ?? now();
        }

        $user->last_login_at = now();
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function extractPreferredName(?string $email, array $metadata): string
    {
        $candidates = array_filter([
            Arr::get($metadata, 'full_name'),
            Arr::get($metadata, 'name'),
            Arr::get($metadata, 'user_name'),
            $email ? Str::before($email, '@') : null,
        ]);

        return $candidates ? (string) reset($candidates) : 'AceTerus Learner';
    }

    private function uniqueValue(string $column, string $base, ?int $ignoreId = null): string
    {
        $candidate = trim($base) ?: 'AceTerus Learner';
        $suffix = 1;

        while (
            User::where($column, $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $suffix += 1;
            $candidate = sprintf('%s-%d', $base, $suffix);
        }

        return $candidate;
    }
}



