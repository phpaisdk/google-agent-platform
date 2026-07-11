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

function configureGapMediaWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory));
    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'us-central1',
        'accessToken' => 'ya29.test',
    ]);
}

it('generates images through the native publisher model endpoint', function () {
    $client = new FakeHttpClient(200, json_encode([
        'candidates' => [['content' => ['parts' => [[
            'inlineData' => ['data' => base64_encode('image-bytes'), 'mimeType' => 'image/png'],
        ]]]]],
    ]));
    configureGapMediaWith($client);

    $result = Generate::image('A small robot')
        ->model(GoogleAgentPlatform::image('google/gemini-3.1-flash-image'))
        ->aspectRatio('16:9')
        ->run();

    expect($result->output->bytes())->toBe('image-bytes')
        ->and($client->lastRequest?->getUri()->getHost())->toBe('us-central1-aiplatform.googleapis.com')
        ->and($client->lastRequest?->getUri()->getPath())->toBe('/v1/projects/my-project/locations/us-central1/publishers/google/models/gemini-3.1-flash-image:generateContent')
        ->and($client->sentBody()['generationConfig']['imageConfig']['aspectRatio'])->toBe('16:9');
});

it('generates speech through the native publisher model endpoint', function () {
    $client = new FakeHttpClient(200, json_encode([
        'candidates' => [['content' => ['parts' => [[
            'inlineData' => ['data' => base64_encode('audio-bytes'), 'mimeType' => 'audio/pcm'],
        ]]]]],
    ]));
    configureGapMediaWith($client);

    $result = Generate::speech('Welcome')
        ->model(GoogleAgentPlatform::speech('google/gemini-3.1-flash-tts-preview'))
        ->voice('Kore')
        ->run();

    expect($result->output->data)->toBe('audio-bytes')
        ->and($client->sentBody()['generationConfig']['responseModalities'])->toBe(['AUDIO'])
        ->and($client->sentBody()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'])->toBe('Kore');
});
