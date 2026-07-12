<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Auth;

use AiSdk\Exceptions\InvalidArgumentException;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\FetchAuthTokenInterface;

/**
 * Resolves Google Cloud Agent Platform (Vertex AI) authentication.
 *
 * Precedence:
 *  1. API key -> x-goog-api-key header (express mode), no token exchange.
 *  2. Static OAuth access token -> Authorization: Bearer.
 *  3. Explicit service-account credentials (array or file path) via google/auth.
 *  4. Application Default Credentials (google/auth): GOOGLE_APPLICATION_CREDENTIALS,
 *     gcloud user creds, GCE/GKE metadata server, workload identity, etc.
 *
 * Cases 3 and 4 require the google/auth package. Case 2 works standalone.
 */
final class GoogleAuth
{
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const REFRESH_BUFFER_SECONDS = 60;

    private ?FetchAuthTokenInterface $credentials = null;

    private ?string $cachedToken = null;

    private int $expiresAt = 0;

    /**
     * @param  array<string, mixed>|null  $serviceAccount
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $accessToken,
        private readonly ?array $serviceAccount,
        private readonly ?string $credentialsPath,
    ) {}

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array<string, string>
     */
    public function authHeaders(array $extraHeaders = []): array
    {
        if ($this->apiKey !== null) {
            return array_merge(['x-goog-api-key' => $this->apiKey], $extraHeaders);
        }

        return array_merge(['Authorization' => 'Bearer ' . $this->token()], $extraHeaders);
    }

    private function token(): string
    {
        if ($this->accessToken !== null && $this->serviceAccount === null && $this->credentialsPath === null) {
            return $this->accessToken;
        }

        if ($this->cachedToken !== null && time() < $this->expiresAt - self::REFRESH_BUFFER_SECONDS) {
            return $this->cachedToken;
        }

        $credentials = $this->resolveCredentials();
        $token = $credentials->fetchAuthToken();

        $accessToken = is_array($token) && isset($token['access_token']) ? (string) $token['access_token'] : '';
        if ($accessToken === '') {
            throw new InvalidArgumentException(
                'Google Agent Platform credentials did not return an access token.',
                ['provider' => 'google-agent-platform'],
            );
        }

        $expiresAt = is_array($token) && isset($token['expires_at']) ? (int) $token['expires_at'] : 0;
        if ($expiresAt === 0 && is_array($token) && isset($token['expires_in'])) {
            $expiresAt = time() + (int) $token['expires_in'];
        }
        $this->cachedToken = $accessToken;
        $this->expiresAt = $expiresAt > 0 ? $expiresAt : time() + 3600;

        return $accessToken;
    }

    private function resolveCredentials(): FetchAuthTokenInterface
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        if ($this->accessToken !== null) {
            return $this->credentials = new StaticAccessToken($this->accessToken);
        }

        if (! class_exists(ApplicationDefaultCredentials::class)) {
            throw new InvalidArgumentException(
                'Google Agent Platform service-account and Application Default Credentials require the google/auth package. '
                . 'Install it with: composer require google/auth — or provide accessToken or apiKey.',
                ['provider' => 'google-agent-platform'],
            );
        }

        if ($this->serviceAccount !== null) {
            return $this->credentials = new ServiceAccountCredentials(self::SCOPE, $this->serviceAccount);
        }

        if ($this->credentialsPath !== null) {
            return $this->credentials = new ServiceAccountCredentials(self::SCOPE, $this->credentialsPath);
        }

        /** @var FetchAuthTokenInterface $adc */
        $adc = ApplicationDefaultCredentials::getCredentials(self::SCOPE);

        return $this->credentials = $adc;
    }
}
