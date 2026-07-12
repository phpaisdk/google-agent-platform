<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\GoogleAgentPlatform\Auth\GoogleAuth;
use AiSdk\Support\Sdk;
use AiSdk\Utils\Support\Env;
use AiSdk\Utils\Support\Url;

/**
 * Configuration for Google Cloud Agent Platform (formerly Vertex AI), using the
 * OpenAI-compatible Chat Completions endpoint.
 */
final class GoogleAgentPlatformOptions
{
    public const string PROVIDER_NAME = 'google-agent-platform';

    private ?GoogleAuth $auth = null;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $serviceAccount
     */
    public function __construct(
        public readonly ?string $apiKey,
        public readonly string $baseUrl,
        public readonly ?string $publisherBaseUrl,
        public readonly array $headers,
        public readonly ?string $accessToken,
        public readonly ?array $serviceAccount,
        public readonly ?string $credentialsPath,
        public readonly bool $useApplicationDefaultCredentials,
        public readonly ?Sdk $sdk = null,
    ) {}

    public static function defaultBaseUrl(string $project, string $location): string
    {
        return self::apiHost($location) . "/v1/projects/{$project}/locations/{$location}/endpoints/openapi";
    }

    public static function defaultPublisherBaseUrl(string $project, string $location): string
    {
        return self::apiHost($location) . "/v1/projects/{$project}/locations/{$location}/publishers/google/models";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config = []): self
    {
        $apiKey = Env::loadOptionalSetting(
            isset($config['apiKey']) ? (string) $config['apiKey'] : null,
            'GOOGLE_VERTEX_API_KEY',
        );

        $project = Env::loadOptionalSetting(
            isset($config['project']) ? (string) $config['project'] : null,
            'GOOGLE_VERTEX_PROJECT',
        ) ?? Env::loadOptionalSetting(null, 'GOOGLE_CLOUD_PROJECT');

        $location = Env::loadOptionalSetting(
            isset($config['location']) ? (string) $config['location'] : null,
            'GOOGLE_VERTEX_LOCATION',
        ) ?? Env::loadOptionalSetting(null, 'GOOGLE_CLOUD_LOCATION');

        $explicitBase = Env::loadOptionalSetting(
            isset($config['baseUrl']) ? (string) $config['baseUrl'] : null,
            'GOOGLE_VERTEX_BASE_URL',
        );

        /** @var array<string, string> $headers */
        $headers = isset($config['headers']) && is_array($config['headers']) ? $config['headers'] : [];
        $sdk = $config['sdk'] ?? null;

        $accessToken = isset($config['accessToken']) ? (string) $config['accessToken'] : null;
        if ($accessToken === null || $accessToken === '') {
            $accessToken = Env::loadOptionalSetting(null, 'GOOGLE_VERTEX_ACCESS_TOKEN');
        }

        $credentialsPath = isset($config['credentialsPath']) ? (string) $config['credentialsPath'] : null;
        if ($credentialsPath === null || $credentialsPath === '') {
            $credentialsPath = Env::loadOptionalSetting(null, 'GOOGLE_VERTEX_CREDENTIALS_PATH');
        }

        /** @var array<string, mixed>|null $serviceAccount */
        $serviceAccount = isset($config['credentials']) && is_array($config['credentials']) ? $config['credentials'] : null;

        $location = ($location !== null && $location !== '') ? $location : 'global';

        if ($explicitBase !== null && $explicitBase !== '') {
            $baseUrl = Url::withoutTrailingSlash($explicitBase);
        } elseif ($project !== null && $project !== '') {
            $baseUrl = self::defaultBaseUrl($project, $location);
        } else {
            throw new InvalidArgumentException(
                'Google Agent Platform requires GOOGLE_VERTEX_PROJECT (or project option) when baseUrl is not set.',
            );
        }

        $publisherBaseUrl = $project !== null && $project !== ''
            ? self::defaultPublisherBaseUrl($project, $location)
            : null;

        $hasApiKey = $apiKey !== null && $apiKey !== '';
        $hasAccessToken = $accessToken !== null && $accessToken !== '';
        $hasPath = $credentialsPath !== null && $credentialsPath !== '';

        // When no explicit credential is supplied, fall back to Application
        // Default Credentials (resolved by google/auth at request time).
        $useAdc = ! $hasApiKey && ! $hasAccessToken && ! $hasPath && $serviceAccount === null;

        return new self(
            apiKey: $hasApiKey ? $apiKey : null,
            baseUrl: $baseUrl,
            publisherBaseUrl: $publisherBaseUrl,
            headers: $headers,
            accessToken: $hasAccessToken ? $accessToken : null,
            serviceAccount: $serviceAccount,
            credentialsPath: $hasPath ? $credentialsPath : null,
            useApplicationDefaultCredentials: $useAdc,
            sdk: $sdk instanceof Sdk ? $sdk : null,
        );
    }

    private static function apiHost(string $location): string
    {
        $host = $location === 'global' ? 'aiplatform.googleapis.com' : "{$location}-aiplatform.googleapis.com";

        return 'https://' . $host;
    }

    public function auth(): GoogleAuth
    {
        return $this->auth ??= new GoogleAuth(
            apiKey: $this->apiKey,
            accessToken: $this->accessToken,
            serviceAccount: $this->serviceAccount,
            credentialsPath: $this->credentialsPath,
        );
    }

    /**
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        return $this->auth()->authHeaders($this->headers);
    }
}
