<?php

declare(strict_types=1);

use AiSdk\Generate;
use AiSdk\GoogleAgentPlatform;
use AiSdk\GoogleAgentPlatform\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    GoogleAgentPlatform::reset();
});

function configureGapWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates text end to end through the Agent Platform vertical', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'chatcmpl_gap',
        'model' => 'google/gemini-2.5-flash',
        'choices' => [['index' => 0, 'message' => ['content' => 'Hello from Vertex'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 5],
    ]));
    configureGapWith($client);

    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'us-central1',
        'accessToken' => 'ya29.test',
    ]);

    $result = Generate::text('Hi')->model(GoogleAgentPlatform::model('google/gemini-2.5-flash'))->run();

    expect($result->text)->toBe('Hello from Vertex')
        ->and($result->usage->inputTokens)->toBe(7);

    $body = $client->sentBody();
    expect($body['model'])->toBe('google/gemini-2.5-flash');

    $uri = $client->lastRequest->getUri();
    expect($uri->getHost())->toBe('us-central1-aiplatform.googleapis.com')
        ->and($uri->getPath())->toBe('/v1/projects/my-project/locations/us-central1/endpoints/openapi/chat/completions')
        ->and($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer ya29.test');
});

it('defaults location to global and uses api key header', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureGapWith($client);

    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'apiKey' => 'AIza-test',
    ]);

    Generate::text('Hi')->model(GoogleAgentPlatform::model('google/gemini-2.5-flash'))->run();

    $uri = $client->lastRequest->getUri();
    expect($uri->getPath())->toBe('/v1/projects/my-project/locations/global/endpoints/openapi/chat/completions')
        ->and($client->lastRequest->getHeaderLine('x-goog-api-key'))->toBe('AIza-test');
});

it('accepts opaque model ids', function () {
    GoogleAgentPlatform::create(['project' => 'my-project', 'accessToken' => 'ya29.test']);

    expect(GoogleAgentPlatform::model('publisher/future-private-model')->modelId())->toBe('publisher/future-private-model');
});

it('maps portable reasoning onto Google thinking config', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureGapWith($client);
    GoogleAgentPlatform::create(['project' => 'my-project', 'accessToken' => 'ya29.test']);

    Generate::text('Think')
        ->model(GoogleAgentPlatform::model('google/gemini-3.1-pro-preview'))
        ->reasoning(\AiSdk\Reasoning::effort('medium'))
        ->run();

    expect($client->sentBody()['extra_body']['google']['thinking_config'])->toBe([
        'thinking_level' => 'MEDIUM',
    ])->and($client->sentBody())->not->toHaveKey('reasoning_effort');
});

it('maps portable reasoning budgets and preserves explicit Google overrides', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureGapWith($client);
    GoogleAgentPlatform::create(['project' => 'my-project', 'accessToken' => 'ya29.test']);

    Generate::text('Think')
        ->model(GoogleAgentPlatform::model('google/gemini-2.5-flash'))
        ->reasoning(\AiSdk\Reasoning::budget(2048))
        ->providerOptions('google-agent-platform', [
            'extra_body' => ['google' => ['thinking_config' => ['thinking_budget' => 4096]]],
        ])
        ->run();

    expect($client->sentBody()['extra_body']['google']['thinking_config'])->toBe([
        'thinking_budget' => 4096,
    ]);
});

it('uses Google MIME-style audio input formats', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureGapWith($client);
    GoogleAgentPlatform::create(['project' => 'my-project', 'accessToken' => 'ya29.test']);

    Generate::text()
        ->model(GoogleAgentPlatform::model('google/gemini-2.5-flash'))
        ->messages([\AiSdk\Message::user([
            \AiSdk\Content::text('Describe this audio'),
            \AiSdk\Content::audio('UklGRg==', mimeType: 'audio/wav', encoding: \AiSdk\InputEncoding::Base64),
        ])])
        ->run();

    expect($client->sentBody()['messages'][0]['content'][1]['input_audio']['format'])->toBe('audio/wav');
});
