<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Models;

use AiSdk\Content;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\Message;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Requests\TranscriptionRequest;
use AiSdk\Responses\TranscriptionResponse;
use AiSdk\Results\TranscriptData;

final class GoogleAgentPlatformTranscriptionModel extends BaseModel implements TranscriptionModelInterface
{
    private const string PROMPT = 'Transcribe the supplied audio accurately. Return only the transcript text.';

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

    public function transcribe(TranscriptionRequest $request): TranscriptionResponse
    {
        $response = (new GoogleAgentPlatformTextModel($this->modelId, $this->options))->generate(new TextModelRequest(
            messages: [Message::user([
                Content::text(self::PROMPT),
                $request->audio,
            ])],
            maxTokens: 4096,
            temperature: 0.0,
            providerOptions: $request->providerOptions,
        ));

        return new TranscriptionResponse(
            transcript: new TranscriptData($response->text()),
            usage: $response->usage,
            rawResponse: $response->rawResponse,
            providerMetadata: $response->providerMetadata,
        );
    }
}
