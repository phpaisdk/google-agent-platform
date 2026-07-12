<?php

declare(strict_types=1);
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformVideoModel;
use AiSdk\GoogleAgentPlatform\Tests\Fakes\FakeHttpClient;
use AiSdk\Requests\VideoRequest;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

it('starts Veo publisher operations', function () {
    $c = new FakeHttpClient(200, json_encode(['name' => 'operations/video-1']));
    $f = new Psr17Factory();
    $o = GoogleAgentPlatformOptions::fromArray(['project' => 'project-1', 'location' => 'us-central1', 'apiKey' => 'key', 'sdk' => new Sdk($c, $f, $f)]);
    $m = new GoogleAgentPlatformVideoModel('veo-3.1-generate-001', $o);
    $j = $m->generate(new VideoRequest('Ocean'));
    expect($j->id)->toBe('operations/video-1')->and($c->lastRequest?->getUri()->getPath())->toContain('/publishers/google/models/veo-3.1-generate-001:predictLongRunning');
});
