<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Auth;

use Google\Auth\FetchAuthTokenInterface;

/**
 * A {@see FetchAuthTokenInterface} that returns a caller-supplied static OAuth
 * access token. Lets users bring a token minted elsewhere (e.g. `gcloud auth
 * print-access-token`) through the same google/auth code path.
 */
final class StaticAccessToken implements FetchAuthTokenInterface
{
    public function __construct(private readonly string $accessToken) {}

    /**
     * @return array{access_token: string}
     */
    public function fetchAuthToken(?callable $httpHandler = null): array
    {
        return ['access_token' => $this->accessToken];
    }

    public function getCacheKey(): string
    {
        return 'static:' . substr(hash('sha256', $this->accessToken), 0, 16);
    }

    /**
     * @return array{access_token: string}
     */
    public function getLastReceivedToken(): array
    {
        return ['access_token' => $this->accessToken];
    }
}
