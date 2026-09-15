<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Oidc;

use CoreX\Tenancy\Exceptions\OidcAuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Builds the authorize-URL (`state` + `nonce` + PKCE S256 `code_challenge`,
 * all written to the session exactly once — D129/D123) and exchanges an
 * authorization `code` for tokens on `token_endpoint`. Endpoints always come
 * from {@see OidcDiscovery}, never hardcoded (D123). `client_secret`/
 * `code_verifier` are marked {@see SensitiveParameter} so neither reaches a
 * stack trace.
 */
final class OidcClient
{
    public const string SESSION_STATE_KEY = 'corex_tenancy_oidc.state';

    public const string SESSION_NONCE_KEY = 'corex_tenancy_oidc.nonce';

    public const string SESSION_CODE_VERIFIER_KEY = 'corex_tenancy_oidc.code_verifier';

    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly string $clientId,
        #[SensitiveParameter]
        private readonly ?string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    public function authorizationUrl(Request $request): string
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(64);

        $request->session()->put(self::SESSION_STATE_KEY, $state);
        $request->session()->put(self::SESSION_NONCE_KEY, $nonce);
        $request->session()->put(self::SESSION_CODE_VERIFIER_KEY, $codeVerifier);

        $query = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->codeChallengeS256($codeVerifier),
            'code_challenge_method' => 'S256',
        ];

        return $this->discovery->authorizationEndpoint().'?'.http_build_query($query);
    }

    /**
     * @return array{id_token: string, ...<string, mixed>}
     */
    public function exchangeCodeForTokens(
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $codeVerifier,
    ): array {
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $codeVerifier,
        ];

        if ($this->clientSecret !== null) {
            $payload['client_secret'] = $this->clientSecret;
        }

        $response = Http::asForm()->post($this->discovery->tokenEndpoint(), $payload);

        if ($response->failed()) {
            throw OidcAuthenticationException::tokenExchangeFailed();
        }

        /** @var array<string, mixed>|null $tokens */
        $tokens = $response->json();

        if (! is_array($tokens) || ! isset($tokens['id_token']) || ! is_string($tokens['id_token'])) {
            throw OidcAuthenticationException::tokenExchangeFailed();
        }

        return $tokens;
    }

    private function codeChallengeS256(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
