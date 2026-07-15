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

function configureGapEmbeddingsWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates embeddings through the documented native predict endpoint', function () {
    $client = new FakeHttpClient(200, json_encode([
        'predictions' => [[
            'embeddings' => [
                'values' => [0.1, 0.2],
                'statistics' => [
                    'truncated' => false,
                    'token_count' => 3,
                ],
            ],
        ]],
    ]));
    configureGapEmbeddingsWith($client);

    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'us-central1',
        'accessToken' => 'ya29.test',
    ]);

    $result = Generate::embedding(['First document', 'Second document'])
        ->model(GoogleAgentPlatform::model('google/gemini-embedding-001'))
        ->dimensions(768)
        ->providerOptions('google-agent-platform', [
            'task_type' => 'RETRIEVAL_DOCUMENT',
            'title' => 'PHP AI SDK',
            'autoTruncate' => false,
        ])
        ->run();

    expect($result->output->vector)->toBe([0.1, 0.2])
        ->and($result->embeddings[1]->vector)->toBe([0.1, 0.2])
        ->and($result->embeddings[1]->index)->toBe(1)
        ->and($result->usage->inputTokens)->toBe(6)
        ->and($result->providerMetadata['google-agent-platform'])->toMatchArray([
            'model' => 'google/gemini-embedding-001',
            'truncated' => false,
        ])
        ->and($client->requests)->toHaveCount(2);

    $firstRequest = $client->requests[0];
    $secondRequest = $client->requests[1];
    $firstBody = json_decode((string) $firstRequest->getBody(), true);
    $secondBody = json_decode((string) $secondRequest->getBody(), true);

    expect((string) $firstRequest->getUri())->toBe('https://us-central1-aiplatform.googleapis.com/v1/projects/my-project/locations/us-central1/publishers/google/models/gemini-embedding-001:predict')
        ->and($firstRequest->getHeaderLine('Authorization'))->toBe('Bearer ya29.test')
        ->and($firstBody)->toBe([
            'instances' => [[
                'content' => 'First document',
                'task_type' => 'RETRIEVAL_DOCUMENT',
                'title' => 'PHP AI SDK',
            ]],
            'parameters' => [
                'autoTruncate' => false,
                'outputDimensionality' => 768,
            ],
        ])
        ->and($secondBody['instances'][0]['content'])->toBe('Second document');
});

it('requires project configuration for native embeddings', function () {
    $client = new FakeHttpClient(200, '{}');
    configureGapEmbeddingsWith($client);
    GoogleAgentPlatform::create([
        'baseUrl' => 'https://example.test/openai',
        'accessToken' => 'ya29.test',
    ]);

    Generate::embedding('A document')
        ->model(GoogleAgentPlatform::model('gemini-embedding-001'))
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'Native Google Agent Platform embedding generation requires project configuration.');
