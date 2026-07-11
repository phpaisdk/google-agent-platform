<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformImageModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformSpeechModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformTextModel;

final class GoogleAgentPlatformProvider extends BaseProvider implements ImageProviderInterface, SpeechProviderInterface, TextProviderInterface
{
    public function __construct(public readonly GoogleAgentPlatformOptions $options) {}

    public function name(): string
    {
        return GoogleAgentPlatformOptions::PROVIDER_NAME;
    }

    public function textModel(string $modelId): TextModelInterface
    {
        return new GoogleAgentPlatformTextModel($modelId, $this->options);
    }

    public function imageModel(string $modelId): ImageModelInterface
    {
        return new GoogleAgentPlatformImageModel($modelId, $this->options);
    }

    public function speechModel(string $modelId): SpeechModelInterface
    {
        return new GoogleAgentPlatformSpeechModel($modelId, $this->options);
    }
}
