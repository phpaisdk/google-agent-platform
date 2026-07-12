<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Models;

use AiSdk\Capability;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\OpenAICompatible\ChatRequestBuilder;
use AiSdk\OpenAICompatible\ChatResponseParser;
use AiSdk\OpenAICompatible\ChatStreamParser;
use AiSdk\Reasoning;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Utils\Support\Url;
use Generator;

final class GoogleAgentPlatformTextModel extends BaseModel implements TextModelInterface
{
    private const array ADAPTER_CAPABILITIES = [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::ToolCalling,
        Capability::StructuredOutput,
        Capability::Reasoning,
        Capability::TextInput,
        Capability::ImageInput,
        Capability::AudioInput,
    ];

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

    public function generate(TextModelRequest $request): TextModelResponse
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES);

        $body = $this->requestBody($request, stream: false);
        $url = Url::joinPath($this->options->baseUrl, '/chat/completions');

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return ChatResponseParser::parse($payload, $this->provider());
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, streaming: true);

        $body = $this->requestBody($request, stream: true);
        $url = Url::joinPath($this->options->baseUrl, '/chat/completions');

        $events = $this->runner($this->options->sdk)
            ->postStream($url, $body, $this->options->authHeaders(), $this->provider());

        yield from ChatStreamParser::parse($events, $this->provider());
    }

    /** @return array<string, mixed> */
    private function requestBody(TextModelRequest $request, bool $stream): array
    {
        $body = ChatRequestBuilder::build(
            $this->modelId,
            $this->provider(),
            $this->withoutReasoning($request),
            $stream,
        );

        $this->normalizeAudioFormats($body);

        if ($request->reasoning !== null) {
            $google = is_array($body['extra_body']['google'] ?? null) ? $body['extra_body']['google'] : [];
            $thinking = is_array($google['thinking_config'] ?? null) ? $google['thinking_config'] : [];
            $google['thinking_config'] = $thinking + $this->thinkingConfig($request->reasoning);
            $body['extra_body']['google'] = $google;
        }

        return $body;
    }

    private function withoutReasoning(TextModelRequest $request): TextModelRequest
    {
        return new TextModelRequest(
            messages: $request->messages,
            system: $request->system,
            output: $request->output,
            tools: $request->tools,
            toolChoice: $request->toolChoice,
            maxTokens: $request->maxTokens,
            temperature: $request->temperature,
            topP: $request->topP,
            maxSteps: $request->maxSteps,
            providerOptions: $request->providerOptions,
        );
    }

    /** @return array<string, int|string> */
    private function thinkingConfig(Reasoning $reasoning): array
    {
        if ($reasoning->budgetTokens !== null) {
            return ['thinking_budget' => $reasoning->budgetTokens];
        }

        return ['thinking_level' => strtoupper((string) $reasoning->effort)];
    }

    /** @param array<string, mixed> $body */
    private function normalizeAudioFormats(array &$body): void
    {
        if (! is_array($body['messages'] ?? null)) {
            return;
        }

        foreach ($body['messages'] as $messageIndex => $message) {
            if (! is_array($message) || ! is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as $partIndex => $part) {
                if (! is_array($part) || ($part['type'] ?? null) !== 'input_audio') {
                    continue;
                }

                $format = $part['input_audio']['format'] ?? null;
                if (is_string($format) && ! str_contains($format, '/')) {
                    $body['messages'][$messageIndex]['content'][$partIndex]['input_audio']['format'] = "audio/{$format}";
                }
            }
        }
    }

}
