<?php

declare(strict_types=1);

namespace AiSdk\GoogleAgentPlatform\Live;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\GoogleAgentPlatform\GoogleAgentPlatformOptions;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Interrupted;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\LiveEvent;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStarted;
use AiSdk\Live\SpeechStopped;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;
use AiSdk\Live\UsageEvent;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Support\Json;
use AiSdk\Tool;

/** Google Cloud Agent Platform Gemini Live WebSocket codec. */
final class GoogleAgentPlatformLiveSessionDriver implements LiveSessionDriverInterface
{
    private readonly TransportConnectionInterface $connection;

    /** @var array<string, string> */
    private array $toolNames = [];

    /** @var array<string, string> */
    private array $toolCallGroups = [];

    /** @var array<string, array{order: list<string>, remaining: array<string, true>, responses: array<string, array<string, mixed>>}> */
    private array $toolGroups = [];

    /** @var list<array<string, mixed>> */
    private array $pendingPayloads = [];

    private int $toolGroupSequence = 0;

    private bool $activityStarted = false;

    public function __construct(
        private readonly string $modelId,
        private readonly GoogleAgentPlatformOptions $options,
        private readonly LiveRequest $request,
        TransportInterface $transport,
    ) {
        if ($request->operation === LiveOperation::Transcribe) {
            throw new InvalidArgumentException(
                'Google Agent Platform Live does not provide a standalone transcription session. Use Live::voice() and consume transcript events, or use Google Cloud Speech-to-Text.',
                ['provider' => GoogleAgentPlatformOptions::PROVIDER_NAME, 'modelId' => $modelId],
            );
        }

        if ($request->operation === LiveOperation::Translate) {
            throw new InvalidArgumentException(
                'Google Agent Platform does not expose the Gemini Developer API Live Translate protocol. Use aisdk/google for dedicated Live translation.',
                ['provider' => GoogleAgentPlatformOptions::PROVIDER_NAME, 'modelId' => $modelId],
            );
        }

        $this->validateAudioFormats();

        $endpoint = new WebSocketEndpoint(
            $options->liveWebSocketUrl(),
            array_merge(['Content-Type' => 'application/json'], $options->authHeaders()),
        );

        if (! $transport->supports($endpoint)) {
            throw new InvalidArgumentException(
                'The selected transport does not support Google Agent Platform Live WebSocket endpoints.',
                ['provider' => GoogleAgentPlatformOptions::PROVIDER_NAME],
            );
        }

        $this->connection = $transport->connect($endpoint);
        $this->sendJson(['setup' => $this->setup()]);
        $this->awaitSetupComplete();
    }

    public function sendAudio(string $bytes): void
    {
        if ($this->usesManualActivityDetection() && ! $this->activityStarted) {
            $this->sendJson(['realtime_input' => ['activity_start' => new \stdClass()]]);
            $this->activityStarted = true;
        }

        $this->sendJson([
            'realtime_input' => [
                'media_chunks' => [[
                    'data' => base64_encode($bytes),
                    'mime_type' => $this->inputAudioMimeType(),
                ]],
            ],
        ]);
    }

    public function sendText(string $text): void
    {
        $this->sendJson([
            'client_content' => [
                'turns' => [[
                    'role' => 'user',
                    'parts' => [['text' => $text]],
                ]],
                'turn_complete' => true,
            ],
        ]);
    }

    public function commitAudio(): void
    {
        if (! $this->usesManualActivityDetection()) {
            throw new InvalidArgumentException(
                'Google Agent Platform Live uses server-side activity detection by default and does not document an audio-stream commit event. Disable turn detection to use explicit activity boundaries.',
            );
        }

        if (! $this->activityStarted) {
            throw new InvalidArgumentException('Google Agent Platform Live cannot end manual activity before any activity has started.');
        }

        $this->sendJson(['realtime_input' => ['activity_end' => new \stdClass()]]);
        $this->activityStarted = false;
    }

    public function clearAudio(): void
    {
        throw new InvalidArgumentException('Google Agent Platform Live streams audio directly and does not expose an input buffer to clear.');
    }

    public function requestResponse(): void
    {
        throw new InvalidArgumentException('Google Agent Platform Live starts responses from realtime input and has no response.create event.');
    }

    public function cancelResponse(): void
    {
        throw new InvalidArgumentException('Google Agent Platform Live interrupts generation when new user activity arrives; it has no standalone cancel event.');
    }

    public function sendToolResult(string $callId, mixed $result): void
    {
        $name = $this->toolNames[$callId] ?? null;
        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException(
                'Google Agent Platform requires a known function name when returning a Live tool result.',
                ['callId' => $callId],
            );
        }

        $response = [
            'id' => $callId,
            'name' => $name,
            'response' => ['result' => $result],
        ];
        $groupId = $this->toolCallGroups[$callId] ?? null;
        if ($groupId === null || ! isset($this->toolGroups[$groupId])) {
            $this->sendJson(['tool_response' => ['function_responses' => [$response]]]);

            return;
        }

        $this->toolGroups[$groupId]['responses'][$callId] = $response;
        unset($this->toolGroups[$groupId]['remaining'][$callId]);

        if ($this->toolGroups[$groupId]['remaining'] !== []) {
            return;
        }

        $responses = [];
        foreach ($this->toolGroups[$groupId]['order'] as $id) {
            if (isset($this->toolGroups[$groupId]['responses'][$id])) {
                $responses[] = $this->toolGroups[$groupId]['responses'][$id];
            }

            unset($this->toolCallGroups[$id]);
        }

        unset($this->toolGroups[$groupId]);
        $this->sendJson(['tool_response' => ['function_responses' => $responses]]);
    }

    public function events(): iterable
    {
        foreach ($this->pendingPayloads as $payload) {
            yield from $this->decode($payload);
        }
        $this->pendingPayloads = [];

        while (! $this->connection->isClosed()) {
            $frame = $this->connection->receive();
            if ($frame === null) {
                yield new LiveClosed();

                break;
            }

            if ($frame->type !== TransportFrameType::Text) {
                yield new ProviderEvent('transport.binary', ['bytes' => base64_encode($frame->payload)]);

                continue;
            }

            yield from $this->decode(Json::decode($frame->payload, 'google agent platform live event'));
        }
    }

    public function close(): void
    {
        if (! $this->connection->isClosed()) {
            $this->connection->close();
        }
    }

    /** @return array<string, mixed> */
    private function setup(): array
    {
        $setup = [
            'model' => $this->options->liveModelName($this->modelId),
            'generation_config' => [
                'response_modalities' => ['AUDIO', 'TEXT'],
            ],
            'input_audio_transcription' => new \stdClass(),
            'output_audio_transcription' => new \stdClass(),
        ];

        $instructions = $this->request->options['instructions'] ?? null;
        if (is_string($instructions) && $instructions !== '') {
            $setup['system_instruction'] = ['parts' => [['text' => $instructions]]];
        }

        $voice = $this->request->options['voice'] ?? null;
        if (is_string($voice) && $voice !== '') {
            $setup['generation_config']['speech_config']['voice_config'] = [
                'prebuilt_voice_config' => ['voice_name' => $voice],
            ];
        }

        $language = $this->request->options['language'] ?? null;
        if (is_string($language) && $language !== '') {
            $setup['generation_config']['speech_config']['language_code'] = $language;
            $setup['input_audio_transcription'] = ['language_codes' => [$language]];
            $setup['output_audio_transcription'] = ['language_codes' => [$language]];
        }

        if (array_key_exists('turn_detection', $this->request->options)) {
            $setup['realtime_input_config']['automatic_activity_detection'] = $this->turnDetection(
                $this->request->options['turn_detection'],
            );
        }

        if ($this->request->tools !== []) {
            $setup['tools'] = [[
                'function_declarations' => array_map(
                    static fn(Tool $tool): array => [
                        'name' => $tool->name(),
                        'description' => $tool->description(),
                        'parameters' => $tool->inputSchemaForProvider(),
                    ],
                    $this->request->tools,
                ),
            ]];
        }

        $providerOptions = $this->request->providerOptions[GoogleAgentPlatformOptions::PROVIDER_NAME] ?? [];
        $raw = $providerOptions['raw'] ?? null;

        return is_array($raw) ? array_replace_recursive($setup, $raw) : $setup;
    }

    /** @return array<string, mixed> */
    private function turnDetection(mixed $value): array
    {
        if ($value === null || $value === 'none' || $value === 'disabled') {
            return ['disabled' => true];
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $setting) {
            if (is_string($key) && $key !== 'type') {
                $normalized[$key] = $setting;
            }
        }

        return $normalized;
    }

    private function inputAudioMimeType(): string
    {
        $format = $this->request->options['input_audio_format'] ?? null;
        if (! is_string($format) || $format === '' || in_array(strtolower($format), ['pcm', 'pcm16', 'audio/pcm'], true)) {
            return 'audio/pcm;rate=16000';
        }

        return 'audio/pcm;rate=16000';
    }

    private function validateAudioFormats(): void
    {
        $input = $this->request->options['input_audio_format'] ?? null;
        if (is_string($input) && $input !== '' && ! in_array(strtolower($input), ['pcm', 'pcm16', 'audio/pcm', 'audio/pcm;rate=16000'], true)) {
            throw new InvalidArgumentException(
                'Google Agent Platform Live requires raw 16-bit mono PCM input at 16 kHz.',
                ['inputAudioFormat' => $input],
            );
        }

        $output = $this->request->options['output_audio_format'] ?? null;
        if (is_string($output) && $output !== '' && ! in_array(strtolower($output), ['pcm', 'pcm16', 'audio/pcm', 'audio/pcm;rate=24000'], true)) {
            throw new InvalidArgumentException(
                'Google Agent Platform Live produces raw 16-bit mono PCM output at 24 kHz.',
                ['outputAudioFormat' => $output],
            );
        }
    }

    private function awaitSetupComplete(): void
    {
        while (true) {
            $frame = $this->connection->receive();
            if ($frame === null) {
                throw InvalidResponseException::forProvider(
                    GoogleAgentPlatformOptions::PROVIDER_NAME,
                    'Google Agent Platform Live closed before acknowledging the session setup.',
                );
            }

            if ($frame->type !== TransportFrameType::Text) {
                throw InvalidResponseException::forProvider(
                    GoogleAgentPlatformOptions::PROVIDER_NAME,
                    'Google Agent Platform Live returned a binary frame before setupComplete.',
                );
            }

            $payload = Json::decode($frame->payload, 'google agent platform live setup response');
            if (array_key_exists('setup_complete', $payload) || array_key_exists('setupComplete', $payload)) {
                return;
            }

            $error = $payload['error'] ?? null;
            if (is_array($error)) {
                throw InvalidResponseException::forProvider(
                    GoogleAgentPlatformOptions::PROVIDER_NAME,
                    is_string($error['message'] ?? null) ? $error['message'] : 'Google Agent Platform Live rejected the session setup.',
                    ['error' => $error],
                );
            }

            $this->pendingPayloads[] = $payload;
        }
    }

    private function usesManualActivityDetection(): bool
    {
        if (! array_key_exists('turn_detection', $this->request->options)) {
            return false;
        }

        $turnDetection = $this->request->options['turn_detection'] ?? null;

        if ($turnDetection === null) {
            return true;
        }

        if (is_string($turnDetection)) {
            return in_array(strtolower($turnDetection), ['none', 'disabled'], true);
        }

        return is_array($turnDetection) && ($turnDetection['disabled'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<LiveEvent>
     */
    private function decode(array $payload): iterable
    {
        $serverContent = $payload['server_content'] ?? $payload['serverContent'] ?? null;
        if (is_array($serverContent)) {
            foreach ([
                ['input_transcription', TranscriptSource::Input],
                ['inputTranscription', TranscriptSource::Input],
                ['output_transcription', TranscriptSource::Output],
                ['outputTranscription', TranscriptSource::Output],
            ] as [$key, $source]) {
                yield from $this->transcriptionEvents($serverContent[$key] ?? null, $source);
            }

            $modelTurn = $serverContent['model_turn'] ?? $serverContent['modelTurn'] ?? null;
            $parts = is_array($modelTurn) && is_array($modelTurn['parts'] ?? null) ? $modelTurn['parts'] : [];
            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (is_string($part['text'] ?? null) && $part['text'] !== '') {
                    yield new TextDelta($part['text']);
                }

                $inline = $part['inline_data'] ?? $part['inlineData'] ?? null;
                $data = is_array($inline) ? ($inline['data'] ?? null) : null;
                if (is_string($data) && $data !== '') {
                    $bytes = base64_decode($data, true);
                    if ($bytes !== false) {
                        yield new AudioDelta($bytes);
                    }
                }
            }

            if (($serverContent['interrupted'] ?? false) === true) {
                yield new Interrupted();
            }

            if (($serverContent['turn_complete'] ?? $serverContent['turnComplete'] ?? false) === true) {
                yield new ResponseCompleted();
            }
        }

        foreach ([
            ['input_transcription', TranscriptSource::Input],
            ['inputTranscription', TranscriptSource::Input],
            ['output_transcription', TranscriptSource::Output],
            ['outputTranscription', TranscriptSource::Output],
        ] as [$key, $source]) {
            yield from $this->transcriptionEvents($payload[$key] ?? null, $source);
        }

        $toolCall = $payload['tool_call'] ?? $payload['toolCall'] ?? null;
        $calls = is_array($toolCall) ? ($toolCall['function_calls'] ?? $toolCall['functionCalls'] ?? null) : null;
        if (is_array($calls)) {
            $normalizedCalls = [];
            foreach ($calls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $id = is_string($call['id'] ?? null) ? $call['id'] : '';
                $name = is_string($call['name'] ?? null) ? $call['name'] : '';
                $arguments = is_array($call['args'] ?? null) ? $call['args'] : [];
                $callId = $id !== '' ? $id : $name;
                if ($callId === '') {
                    continue;
                }

                $this->toolNames[$callId] = $name;
                $normalizedCalls[] = [$callId, $name, $arguments];
            }

            if ($normalizedCalls !== []) {
                $groupId = 'tool-group-' . (++$this->toolGroupSequence);
                $order = array_map(static fn(array $call): string => $call[0], $normalizedCalls);
                $this->toolGroups[$groupId] = [
                    'order' => $order,
                    'remaining' => array_fill_keys($order, true),
                    'responses' => [],
                ];

                foreach ($normalizedCalls as [$callId, $name, $arguments]) {
                    $this->toolCallGroups[$callId] = $groupId;
                    yield new ToolCallEvent($callId, $name, $arguments);
                }
            }
        }

        $vad = $payload['voice_activity_detection_signal'] ?? $payload['voiceActivityDetectionSignal'] ?? null;
        if (is_array($vad)) {
            $signal = $vad['vad_signal_type'] ?? $vad['vadSignalType'] ?? null;
            if ($signal === 'VAD_SIGNAL_TYPE_SOS') {
                yield new SpeechStarted();
            } elseif ($signal === 'VAD_SIGNAL_TYPE_EOS') {
                yield new SpeechStopped();
            }
        }

        $usage = $payload['usage_metadata'] ?? $payload['usageMetadata'] ?? null;
        if (is_array($usage)) {
            $numeric = array_filter($usage, static fn(mixed $value): bool => is_int($value) || is_float($value));
            if ($numeric !== []) {
                yield new UsageEvent($numeric);
            }
        }

        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'Google Agent Platform Live returned an error.';
            $code = isset($error['code']) ? (string) $error['code'] : null;
            yield new LiveError($message, $code, $error);
        }

        if ($serverContent === null && $toolCall === null && $vad === null && $usage === null && $error === null && ! $this->hasTopLevelTranscription($payload)) {
            $type = (string) array_key_first($payload);
            yield new ProviderEvent($type !== '' ? $type : 'unknown', $payload);
        }
    }

    /** @return iterable<LiveEvent> */
    private function transcriptionEvents(mixed $transcription, TranscriptSource $source): iterable
    {
        if (is_array($transcription) && is_string($transcription['text'] ?? null) && $transcription['text'] !== '') {
            if (($transcription['finished'] ?? false) === true) {
                yield new TranscriptCompleted($transcription['text'], source: $source);

                return;
            }

            yield new TranscriptDelta($transcription['text'], source: $source);
        }
    }

    /** @param array<string, mixed> $payload */
    private function hasTopLevelTranscription(array $payload): bool
    {
        return isset($payload['input_transcription'])
            || isset($payload['inputTranscription'])
            || isset($payload['output_transcription'])
            || isset($payload['outputTranscription']);
    }

    /** @param array<string, mixed> $payload */
    private function sendJson(array $payload): void
    {
        $this->connection->send(TransportFrame::text(Json::encode($payload)));
    }
}
