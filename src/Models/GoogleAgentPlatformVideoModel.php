<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Models;

use AiSdk\ContentSource;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\Requests\VideoRequest;
use AiSdk\Responses\VideoJob;
use AiSdk\Responses\VideoJobStatus;
use AiSdk\Results\VideoData;
use AiSdk\Support\Usage;
use AiSdk\Utils\Support\Url;

final class GoogleAgentPlatformVideoModel extends BaseModel implements VideoModelInterface
{
    public function __construct(private readonly string $modelId, private readonly GoogleAgentPlatformOptions $options) {}

    public function provider(): string
    {
        return GoogleAgentPlatformOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(VideoRequest $r): VideoJob
    {
        if ($r->video !== null) {
            throw new InvalidArgumentException('Google Agent Platform Veo does not accept a source video for this generation API.');
        }
        if ($this->options->publisherBaseUrl === null) {
            throw new InvalidArgumentException('Google Agent Platform video generation requires project configuration.');
        }$o = $r->providerOptionsFor($this->provider());
        $i = ['prompt' => $r->prompt];
        if ($r->image) {
            if ($r->image->source() === ContentSource::Url) {
                throw new InvalidArgumentException('Google Agent Platform video generation requires inline image data or a GCS reference.');
            }$i['image'] = ['bytesBase64Encoded' => $r->image->base64Data(), 'mimeType' => $r->image->mimeType()];
        }if (is_array($o['referenceImages'] ?? null)) {
            $i['referenceImages'] = $o['referenceImages'];
        }$params = array_filter(['aspectRatio' => $r->output?->aspectRatio, 'resolution' => $this->resolution($r->output?->resolution), 'durationSeconds' => $r->output?->duration, 'seed' => $r->output?->seed], fn($v) => $v !== null);
        $params = array_replace($params, array_diff_key($o, array_flip(['pollIntervalMs', 'pollTimeoutMs', 'referenceImages'])));
        $url = Url::joinPath($this->options->publisherBaseUrl, '/' . rawurlencode($this->modelId) . ':predictLongRunning');
        $p = $this->runner($this->options->sdk)->postJson($url, ['instances' => [$i], 'parameters' => $params], $this->options->authHeaders(), $this->provider());
        $id = $p['name'] ?? null;
        if (! is_string($id) || $id === '') {
            throw InvalidResponseException::forProvider($this->provider(), 'Google Agent Platform returned no video operation name.', ['body' => $p]);
        }

        return new VideoJob($id, $this->provider(), $this->modelId, rawResponse: $p, providerMetadata: [$this->provider() => ['operationName' => $id, 'pollIntervalMs' => (int) ($o['pollIntervalMs'] ?? 10000), 'pollTimeoutMs' => (int) ($o['pollTimeoutMs'] ?? 600000)]]);
    }

    public function poll(VideoJob $job): VideoJob
    {
        $url = Url::joinPath((string) $this->options->publisherBaseUrl, '/' . rawurlencode($this->modelId) . ':fetchPredictOperation');
        $p = $this->runner($this->options->sdk)->postJson($url, ['operationName' => $job->id], $this->options->authHeaders(), $this->provider());
        if (! ($p['done'] ?? false)) {
            return new VideoJob($job->id, $job->provider, $job->modelId, VideoJobStatus::Running, rawResponse: $p, providerMetadata: $job->providerMetadata);
        }if (isset($p['error'])) {
            return new VideoJob($job->id, $job->provider, $job->modelId, VideoJobStatus::Failed, errorMessage: (string) ($p['error']['message'] ?? 'Google Agent Platform video generation failed.'), rawResponse: $p, providerMetadata: $job->providerMetadata);
        }$v = $p['response']['videos'][0] ?? null;
        if (! is_array($v)) {
            return new VideoJob($job->id, $job->provider, $job->modelId, VideoJobStatus::Failed, errorMessage: 'Google Agent Platform completed without video output.', rawResponse: $p, providerMetadata: $job->providerMetadata);
        }$data = isset($v['bytesBase64Encoded']) ? base64_decode((string) $v['bytesBase64Encoded'], true) : null;
        $video = new VideoData(url: is_string($v['gcsUri'] ?? null) ? $v['gcsUri'] : null, data: $data === false ? null : $data, mimeType: (string) ($v['mimeType'] ?? 'video/mp4'));

        return new VideoJob($job->id, $job->provider, $job->modelId, VideoJobStatus::Succeeded, $video, usage: Usage::empty(), rawResponse: $p, providerMetadata: $job->providerMetadata);
    }

    private function resolution(?string $r): ?string
    {
        return match ($r) {
            '1280x720' => '720p','1920x1080' => '1080p','3840x2160' => '4k',default => $r,
        };
    }
}
