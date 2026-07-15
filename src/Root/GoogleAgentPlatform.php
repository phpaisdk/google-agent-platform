<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Contracts\Model;
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

    public static function model(string $modelId): Model
    {
        return self::default()->model($modelId);
    }
}
