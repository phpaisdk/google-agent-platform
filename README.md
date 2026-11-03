# PHP AI SDK — Google Cloud Agent Platform

Google Cloud Agent Platform (formerly Vertex AI) provider for the framework-agnostic [PHP AI SDK](https://phpaisdk.com). Supports text, images, speech, embeddings, video, and live sessions.

## Installation

```bash
composer require aisdk/google-agent-platform
```

Set `GOOGLE_VERTEX_PROJECT`, `GOOGLE_VERTEX_LOCATION`, and `GOOGLE_VERTEX_ACCESS_TOKEN` in your environment. Service account credentials are also supported.

## Usage

```php
use AiSdk\Generate;
use AiSdk\GoogleAgentPlatform;

$result = Generate::text('Explain closures in PHP in one sentence.')
    ->model(GoogleAgentPlatform::model('google/gemini-3.8-flash'))
    ->run();

echo $result->text;
```

The existing `GOOGLE_VERTEX_*` environment names remain supported.

## Documentation

[Read the documentation](https://phpaisdk.com/docs/0.x/providers/google-agent-platform) for configuration, supported features, and more examples.

[Contributing](https://github.com/phpaisdk/.github/blob/main/CONTRIBUTING.md) · [Support](https://github.com/phpaisdk/.github/blob/main/SUPPORT.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
