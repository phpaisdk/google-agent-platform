<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformProvider;

final class GoogleAgentPlatform
{
    private static ?GoogleAgentPlatformProvider $default = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = []): GoogleAgentPlatformProvider
    {
        return self::$default = new GoogleAgentPlatformProvider(GoogleAgentPlatformOptions::fromArray($config));
    }

    public static function default(): GoogleAgentPlatformProvider
    {
        return self::$default ??= self::create();
    }

    public static function reset(): void
    {
        self::$default = null;
    }

    public static function model(string $modelId): TextModelInterface
    {
        return self::default()->textModel($modelId);
    }

    public static function embedding(string $modelId): EmbeddingModelInterface
    {
        return self::default()->embeddingModel($modelId);
    }

    public static function image(string $modelId): ImageModelInterface
    {
        return self::default()->imageModel($modelId);
    }

    public static function speech(string $modelId): SpeechModelInterface
    {
        return self::default()->speechModel($modelId);
    }

    public static function transcription(string $modelId): TranscriptionModelInterface
    {
        return self::default()->transcriptionModel($modelId);
    }

    public static function video(string $modelId): VideoModelInterface
    {
        return self::default()->videoModel($modelId);
    }
}
