<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\GoogleAgentPlatform;
use AiSdk\GoogleAgentPlatform\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    GoogleAgentPlatform::reset();
});

it('transcribes through the Agent Platform multimodal model path', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Agent Platform transcript.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
    ], JSON_THROW_ON_ERROR));
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    GoogleAgentPlatform::create(['apiKey' => 'google-test', 'baseUrl' => 'https://agent.example/v1beta1']);

    $result = Generate::transcription(Content::audio('wav-bytes', 'audio/wav', 'clip.wav'))
        ->model(GoogleAgentPlatform::transcription('google/gemini-2.5-flash'))
        ->run();

    $body = $client->sentBody();
    expect($result->output->text)->toBe('Agent Platform transcript.')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://agent.example/v1beta1/chat/completions')
        ->and($body['messages'][0]['content'][0]['text'])->toContain('Transcribe')
        ->and($body['messages'][0]['content'][1]['input_audio']['data'])->toBe(base64_encode('wav-bytes'))
        ->and($body['messages'][0]['content'][1]['input_audio']['format'])->toBe('audio/wav');
});
