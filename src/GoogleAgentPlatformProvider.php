<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\Contracts\TranscriptionProviderInterface;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Contracts\VideoProviderInterface;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformEmbeddingModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformImageModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformLiveModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformSpeechModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformTextModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformTranscriptionModel;
use AiSdk\GoogleAgentPlatform\Models\GoogleAgentPlatformVideoModel;
use AiSdk\Live\Contracts\LiveModelInterface;

final class GoogleAgentPlatformProvider extends BaseProvider implements EmbeddingProviderInterface, ImageProviderInterface, LiveProviderInterface, SpeechProviderInterface, TextProviderInterface, TranscriptionProviderInterface, VideoProviderInterface
{
    public function __construct(public readonly GoogleAgentPlatformOptions $options) {}

    public function name(): string
    {
        return GoogleAgentPlatformOptions::PROVIDER_NAME;
    }

    protected function textModel(string $modelId): TextModelInterface
    {
        return new GoogleAgentPlatformTextModel($modelId, $this->options);
    }

    protected function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new GoogleAgentPlatformEmbeddingModel($modelId, $this->options);
    }

    protected function imageModel(string $modelId): ImageModelInterface
    {
        return new GoogleAgentPlatformImageModel($modelId, $this->options);
    }

    protected function speechModel(string $modelId): SpeechModelInterface
    {
        return new GoogleAgentPlatformSpeechModel($modelId, $this->options);
    }

    protected function transcriptionModel(string $modelId): TranscriptionModelInterface
    {
        return new GoogleAgentPlatformTranscriptionModel($modelId, $this->options);
    }

    protected function videoModel(string $modelId): VideoModelInterface
    {
        return new GoogleAgentPlatformVideoModel($modelId, $this->options);
    }

    protected function liveModel(string $modelId): LiveModelInterface
    {
        return new GoogleAgentPlatformLiveModel($modelId, $this->options);
    }
}
