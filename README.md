# aisdk/google-agent-platform

Official Google Cloud Agent Platform provider for the framework-agnostic PHP AI SDK. It uses the OpenAI-compatible endpoint for text and native publisher model endpoints for embeddings, image, and speech generation.

## Installation

```bash
composer require aisdk/google-agent-platform
```

## Basic Usage

```php
use AiSdk\Generate;
use AiSdk\GoogleAgentPlatform;

$result = Generate::text()
    ->model(GoogleAgentPlatform::model('google/gemini-2.5-flash'))
    ->prompt('Explain closures in PHP.')
    ->run();

echo $result->text;
```

## Embeddings

```php
$embedding = Generate::embedding(['First document to index', 'Second document to index'])
    ->model(GoogleAgentPlatform::embedding('gemini-embedding-001'))
    ->dimensions(768)
    ->providerOptions('google-agent-platform', [
        'task_type' => 'RETRIEVAL_DOCUMENT',
        'autoTruncate' => false,
    ])
    ->run();

$vector = $embedding->output->vector;
```

The package sends one native publisher-model request per input, which supports the documented single-input limit of `gemini-embedding-001`. Provider options use Google's documented field names: `task_type`, `title`, `autoTruncate`, and `outputDimensionality`.

Publisher and routed model IDs pass through unchanged and do not need to be registered. This package does not ship a model inventory; the SDK performs internal adapter validation before Google Cloud validates support for the selected model, project, and location.

## Image and speech generation

```php
$image = Generate::image('A clean product photograph')
    ->model(GoogleAgentPlatform::image('google/gemini-3.1-flash-image'))
    ->aspectRatio('16:9')
    ->run();

$speech = Generate::speech('Welcome to the application.')
    ->model(GoogleAgentPlatform::speech('google/gemini-3.1-flash-tts-preview'))
    ->voice('Kore')
    ->run();
```

Native embedding, image, and speech generation require `project`; a custom OpenAI-compatible `baseUrl` alone is not enough to construct the publisher model endpoint.

## Streaming

```php
foreach (Generate::text('Tell me a story.')->model(GoogleAgentPlatform::model('google/gemini-2.5-flash'))->stream()->chunks() as $chunk) {
    echo $chunk;
}
```

## Configuration

| Variable | Description |
|---|---|
| `GOOGLE_VERTEX_PROJECT` | Google Cloud project id (required unless `baseUrl` set) |
| `GOOGLE_VERTEX_LOCATION` | Region (defaults to `global`) |
| `GOOGLE_VERTEX_ACCESS_TOKEN` | Static OAuth access token |
| `GOOGLE_VERTEX_CREDENTIALS_PATH` | Path to a service account JSON key |
| `GOOGLE_APPLICATION_CREDENTIALS` | Standard ADC service-account path |
| `GOOGLE_VERTEX_API_KEY` | API key (express mode) |

## Authentication

Authentication is powered by the official [`google/auth`](https://github.com/googleapis/google-auth-library-php)
library and supports the full set of Google credential sources:

- **Application Default Credentials (ADC)** — used automatically when no
  explicit credential is given: `GOOGLE_APPLICATION_CREDENTIALS`, `gcloud`
  user credentials, GCE/GKE metadata server, and workload identity federation.
- **Service account** — JSON key as an array or file path (OAuth token exchange
  handled for you).
- **Static OAuth access token** — bring your own (e.g. `gcloud auth print-access-token`).
- **API key** — express mode via the `x-goog-api-key` header.

```php
// Application Default Credentials (nothing to configure)
GoogleAgentPlatform::create(['project' => 'my-project', 'location' => 'us-central1']);

// Service account file
GoogleAgentPlatform::create([
    'project' => 'my-project',
    'location' => 'us-central1',
    'credentialsPath' => '/path/to/service-account.json',
]);

// Service account array
GoogleAgentPlatform::create([
    'project' => 'my-project',
    'credentials' => $decodedServiceAccountJson,
]);

// Static access token
GoogleAgentPlatform::create(['project' => 'my-project', 'accessToken' => 'ya29....']);
```

## Testing

```bash
composer test
```

## Links

- [Google Cloud text embeddings](https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/embeddings/get-text-embeddings)
- [Core Package](https://github.com/phpaisdk/core)
