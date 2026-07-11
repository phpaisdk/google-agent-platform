<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\Requests\EmbeddingRequest;
use AiSdk\Responses\EmbeddingResponse;
use AiSdk\Results\EmbeddingData;
use AiSdk\Support\Usage;

final class GoogleAgentPlatformEmbeddingModel extends BaseModel implements EmbeddingModelInterface
{
    public function __construct(
        private readonly string $modelId,
        private readonly GoogleAgentPlatformOptions $options,
    ) {}

    public function provider(): string
    {
        return GoogleAgentPlatformOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(EmbeddingRequest $request): EmbeddingResponse
    {
        $embeddings = [];
        $responses = [];
        $inputTokens = 0;
        $truncated = false;

        foreach ($request->inputs as $index => $input) {
            $payload = $this->runner($this->options->sdk)->postJson(
                $this->modelUrl(),
                $this->requestBody($input, $request),
                $this->options->authHeaders(),
                $this->provider(),
            );
            $responses[] = $payload;

            $embedding = $payload['predictions'][0]['embeddings'] ?? null;
            $vector = is_array($embedding) ? $this->vector($embedding['values'] ?? null) : [];
            if ($vector === []) {
                throw InvalidResponseException::forProvider(
                    $this->provider(),
                    "Google Agent Platform returned no valid embedding for input [{$index}].",
                    ['body' => $payload, 'inputIndex' => $index],
                );
            }

            $statistics = is_array($embedding['statistics'] ?? null) ? $embedding['statistics'] : [];
            $tokenCount = $statistics['token_count'] ?? $statistics['tokenCount'] ?? null;
            if (is_numeric($tokenCount)) {
                $inputTokens += (int) $tokenCount;
            }
            if (($statistics['truncated'] ?? false) === true) {
                $truncated = true;
            }

            $embeddings[] = new EmbeddingData($vector, $index);
        }

        return new EmbeddingResponse(
            embeddings: $embeddings,
            usage: new Usage(inputTokens: $inputTokens),
            rawResponse: ['responses' => $responses],
            providerMetadata: [
                $this->provider() => [
                    'model' => $this->modelId,
                    'truncated' => $truncated,
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(string $input, EmbeddingRequest $request): array
    {
        $options = $request->providerOptionsFor($this->provider());
        $raw = $options['raw'] ?? null;

        $instance = array_filter([
            'content' => $input,
            'task_type' => $options['task_type'] ?? null,
            'title' => $options['title'] ?? null,
        ], static fn(mixed $value): bool => $value !== null);
        $parameters = array_filter([
            'autoTruncate' => $options['autoTruncate'] ?? null,
            'outputDimensionality' => $request->dimensions ?? ($options['outputDimensionality'] ?? null),
        ], static fn(mixed $value): bool => $value !== null);

        $body = ['instances' => [$instance]];
        if ($parameters !== []) {
            $body['parameters'] = $parameters;
        }

        return is_array($raw) ? array_replace_recursive($body, $raw) : $body;
    }

    private function modelUrl(): string
    {
        if ($this->options->publisherBaseUrl === null) {
            throw new InvalidArgumentException('Native Google Agent Platform embedding generation requires project configuration.');
        }

        $model = str_starts_with($this->modelId, 'google/') ? substr($this->modelId, 7) : $this->modelId;

        return $this->options->publisherBaseUrl . '/' . rawurlencode($model) . ':predict';
    }

    /**
     * @return array<int, float>
     */
    private function vector(mixed $values): array
    {
        if (! is_array($values) || $values === []) {
            return [];
        }

        $vector = [];
        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return [];
            }

            $vector[] = (float) $value;
        }

        return $vector;
    }
}
