<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\Requests\ImageRequest;
use AiSdk\Responses\ImageResponse;
use AiSdk\Results\ImageData;
use AiSdk\Support\Usage;

final class GoogleAgentPlatformImageModel extends BaseModel implements ImageModelInterface
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

    public function generate(ImageRequest $request): ImageResponse
    {
        if ($request->count !== 1) {
            throw new InvalidArgumentException('Google Agent Platform image generation currently supports one image per portable request.');
        }
        if ($request->seed !== null) {
            throw new InvalidArgumentException('Google Agent Platform image generation does not support portable seed().');
        }

        $imageConfig = array_filter([
            'aspectRatio' => $request->aspectRatio,
            'imageSize' => $request->size === null ? null : $this->imageSize($request->size),
        ], static fn(mixed $value): bool => $value !== null);
        $body = [
            'contents' => [['parts' => [['text' => $request->prompt]]]],
            'generationConfig' => array_filter([
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => $imageConfig === [] ? null : $imageConfig,
            ], static fn(mixed $value): bool => $value !== null),
        ];
        $body = $this->mergeOptions($body, $request->providerOptionsFor($this->provider()));
        $payload = $this->runner($this->options->sdk)->postJson(
            $this->modelUrl(),
            $body,
            $this->options->authHeaders(),
            $this->provider(),
        );

        $images = [];
        foreach (($payload['candidates'] ?? []) as $candidate) {
            foreach ((is_array($candidate) ? ($candidate['content']['parts'] ?? []) : []) as $part) {
                $inline = is_array($part) ? ($part['inlineData'] ?? $part['inline_data'] ?? null) : null;
                if (is_array($inline) && is_string($inline['data'] ?? null)) {
                    $images[] = new ImageData(
                        base64: $inline['data'],
                        mimeType: (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png'),
                    );
                }
            }
        }
        if ($images === []) {
            throw InvalidResponseException::forProvider($this->provider(), 'Google Agent Platform returned no generated image.', ['body' => $payload]);
        }

        return new ImageResponse($images, Usage::empty(), $payload, [$this->provider() => ['model' => $this->modelId]]);
    }

    private function modelUrl(): string
    {
        if ($this->options->publisherBaseUrl === null) {
            throw new InvalidArgumentException('Native Google Agent Platform media generation requires project configuration.');
        }
        $model = str_starts_with($this->modelId, 'google/') ? substr($this->modelId, 7) : $this->modelId;

        return $this->options->publisherBaseUrl . '/' . rawurlencode($model) . ':generateContent';
    }

    private function imageSize(string $size): string
    {
        if (preg_match('/^(\d+)x(\d+)$/', $size, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid Google image size [{$size}]. Expected WIDTHxHEIGHT.");
        }

        $longestEdge = max((int) $matches[1], (int) $matches[2]);

        return match (true) {
            $longestEdge > 2048 => '4K',
            $longestEdge > 1024 => '2K',
            default => '1K',
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function mergeOptions(array $body, array $options): array
    {
        $raw = $options['raw'] ?? null;
        unset($options['raw']);
        $body = array_replace_recursive($body, $options);

        return is_array($raw) ? array_replace_recursive($body, $raw) : $body;
    }

}
