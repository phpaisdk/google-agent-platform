<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\GoogleAgentPlatform\Live\GoogleAgentPlatformLiveSessionDriver;
use AiSdk\Live\Contracts\LiveModelInterface;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveRequest;

final class GoogleAgentPlatformLiveModel extends BaseModel implements LiveModelInterface
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

    public function createLiveSession(LiveRequest $request, TransportInterface $transport): LiveSessionDriverInterface
    {
        return new GoogleAgentPlatformLiveSessionDriver($this->modelId, $this->options, $request, $transport);
    }
}
