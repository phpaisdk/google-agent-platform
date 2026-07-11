<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\Requests\SpeechRequest;
use AiSdk\Responses\SpeechResponse;
use AiSdk\Results\AudioData;
use AiSdk\Support\Usage;

final class GoogleAgentPlatformSpeechModel extends BaseModel implements SpeechModelInterface
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

    public function generate(SpeechRequest $request): SpeechResponse
    {
        if ($request->format !== null && ! in_array(strtolower($request->format), ['wav', 'pcm'], true)) {
            throw new InvalidArgumentException('Google Agent Platform TTS currently returns PCM/WAV audio; portable format() must be wav or pcm.');
        }

        $body = [
            'contents' => [['parts' => [['text' => $request->input]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $request->voice ?? 'Kore'],
                    ],
                ],
            ],
        ];
        $options = $request->providerOptionsFor($this->provider());
        $raw = $options['raw'] ?? null;
        unset($options['raw']);
        $body = array_replace_recursive($body, $options);
        if (is_array($raw)) {
            $body = array_replace_recursive($body, $raw);
        }

        $payload = $this->runner($this->options->sdk)->postJson(
            $this->modelUrl(),
            $body,
            $this->options->authHeaders(),
            $this->provider(),
        );
        foreach (($payload['candidates'] ?? []) as $candidate) {
            foreach ((is_array($candidate) ? ($candidate['content']['parts'] ?? []) : []) as $part) {
                $inline = is_array($part) ? ($part['inlineData'] ?? $part['inline_data'] ?? null) : null;
                if (! is_array($inline) || ! is_string($inline['data'] ?? null)) {
                    continue;
                }
                $bytes = base64_decode($inline['data'], true);
                if ($bytes === false || $bytes === '') {
                    continue;
                }

                return new SpeechResponse(
                    new AudioData($bytes, (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'audio/pcm')),
                    Usage::empty(),
                    $payload,
                    [$this->provider() => ['model' => $this->modelId]],
                );
            }
        }

        throw InvalidResponseException::forProvider($this->provider(), 'Google Agent Platform returned no generated audio.', ['body' => $payload]);
    }

    private function modelUrl(): string
    {
        if ($this->options->publisherBaseUrl === null) {
            throw new InvalidArgumentException('Native Google Agent Platform speech generation requires project configuration.');
        }
        $model = str_starts_with($this->modelId, 'google/') ? substr($this->modelId, 7) : $this->modelId;

        return $this->options->publisherBaseUrl . '/' . rawurlencode($model) . ':generateContent';
    }

}
