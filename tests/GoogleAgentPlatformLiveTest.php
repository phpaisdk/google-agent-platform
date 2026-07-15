<?php

declare(strict_types=1);

use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\GoogleAgentPlatform;
use AiSdk\GoogleAgentPlatform\Tests\Fakes\FakeLiveTransport;
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Schema;
use AiSdk\Tool;

afterEach(function () {
    GoogleAgentPlatform::reset();
});

it('runs Agent Platform Gemini Live sessions over the core transport contract', function () {
    $provider = GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'us-central1',
        'accessToken' => 'ya29.test',
    ]);
    expect($provider)->toBeInstanceOf(LiveProviderInterface::class);
    $transport = new FakeLiveTransport([
        TransportFrame::text(json_encode([
            'server_content' => [
                'input_transcription' => ['text' => 'hello', 'finished' => true],
                'model_turn' => ['parts' => [
                    ['text' => 'Hi'],
                    ['inline_data' => ['data' => base64_encode('voice-bytes'), 'mime_type' => 'audio/pcm']],
                ]],
                'turn_complete' => true,
            ],
        ])),
        TransportFrame::text(json_encode([
            'tool_call' => ['function_calls' => [[
                'id' => 'call-1',
                'name' => 'weather',
                'args' => ['city' => 'Lahore'],
            ]]],
        ])),
        TransportFrame::text(json_encode([
            'session_resumption_update' => ['new_handle' => 'resume-1', 'resumable' => true],
        ])),
        TransportFrame::text(json_encode([
            'output_transcription' => ['text' => 'Hi there'],
        ])),
    ]);
    $weather = Tool::make('weather')->for('Get the weather');

    $session = Live::voice()
        ->model(GoogleAgentPlatform::model('gemini-live-2.5-flash-native-audio'))
        ->instructions('Be concise.')
        ->voice('Kore')
        ->language('en-US')
        ->tools([$weather])
        ->connect($transport);

    expect($transport->endpoint)->toBeInstanceOf(WebSocketEndpoint::class)
        ->and($transport->endpoint?->url)->toBe('wss://us-central1-aiplatform.googleapis.com/ws/google.cloud.aiplatform.v1.LlmBidiService/BidiGenerateContent')
        ->and($transport->endpoint?->headers['Authorization'])->toBe('Bearer ya29.test');

    $setup = $transport->connection->sentJson(0)['setup'];
    expect($setup['model'])->toBe('projects/my-project/locations/us-central1/publishers/google/models/gemini-live-2.5-flash-native-audio')
        ->and($setup['generation_config']['response_modalities'])->toBe(['AUDIO', 'TEXT'])
        ->and($setup['system_instruction']['parts'][0]['text'])->toBe('Be concise.')
        ->and($setup['tools'][0]['function_declarations'][0]['name'])->toBe('weather');

    $session->sendAudio('microphone-bytes');
    $session->sendText('Hello');
    expect($transport->connection->sentJson(1)['realtime_input']['media_chunks'][0])
        ->toBe(['data' => base64_encode('microphone-bytes'), 'mime_type' => 'audio/pcm;rate=16000'])
        ->and($transport->connection->sentJson(2)['client_content']['turns'][0]['parts'][0]['text'])->toBe('Hello')
        ->and($transport->connection->sentJson(2)['client_content']['turn_complete'])->toBeTrue();

    $events = iterator_to_array($session->events());
    $classes = array_map(static fn(object $event): string => $event::class, $events);
    expect($classes)->toContain(TranscriptCompleted::class, TextDelta::class, AudioDelta::class, ResponseCompleted::class, ToolCallEvent::class, ProviderEvent::class, LiveClosed::class);
    $transcript = array_values(array_filter($events, static fn(object $event): bool => $event instanceof TranscriptCompleted))[0];
    $outputTranscript = array_values(array_filter($events, static fn(object $event): bool => $event instanceof TranscriptDelta))[0];
    expect($transcript->source)->toBe(TranscriptSource::Input)
        ->and($outputTranscript->source)->toBe(TranscriptSource::Output);

    $session->sendToolResult('call-1', ['temperature' => 31]);
    expect($transport->connection->sentJson(3)['tool_response']['function_responses'][0])
        ->toBe(['id' => 'call-1', 'name' => 'weather', 'response' => ['result' => ['temperature' => 31]]]);
});

it('does not claim dedicated Agent Platform Live translation', function () {
    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'global',
        'apiKey' => 'vertex-key',
    ]);

    expect(fn() => Live::translate()
        ->model(GoogleAgentPlatform::model('gemini-live-2.5-flash-native-audio'))
        ->to('es-ES')
        ->connect(new FakeLiveTransport()))
        ->toThrow(InvalidArgumentException::class, 'does not expose the Gemini Developer API Live Translate protocol');
});

it('does not claim Agent Platform Live as standalone streaming transcription', function () {
    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'global',
        'accessToken' => 'ya29.test',
    ]);

    expect(fn() => Live::transcribe()
        ->model(GoogleAgentPlatform::model('gemini-live-2.5-flash-native-audio'))
        ->connect(new FakeLiveTransport()))
        ->toThrow(InvalidArgumentException::class, 'does not provide a standalone transcription session');
});

it('does not invent an Agent Platform commit event with automatic VAD', function () {
    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'global',
        'accessToken' => 'ya29.test',
    ]);
    $session = Live::voice()
        ->model(GoogleAgentPlatform::model('gemini-live-2.5-flash-native-audio'))
        ->connect($transport = new FakeLiveTransport());

    expect(fn() => $session->commitAudio())
        ->toThrow(InvalidArgumentException::class, 'does not document an audio-stream commit event');

    expect($transport->connection->sent)->toHaveCount(1);
});

it('maps commit to Agent Platform manual activity boundaries when automatic VAD is disabled', function () {
    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'global',
        'accessToken' => 'ya29.test',
    ]);
    $transport = new FakeLiveTransport();
    $session = Live::voice()
        ->model(GoogleAgentPlatform::model('gemini-live-2.5-flash-native-audio'))
        ->turnDetection('disabled')
        ->connect($transport);

    $session->sendAudio('audio');
    $session->commitAudio();

    expect($transport->connection->sentJson(0)['setup']['realtime_input_config']['automatic_activity_detection'])
        ->toBe(['disabled' => true])
        ->and($transport->connection->sentJson(1)['realtime_input']['activity_start'])->toBe([])
        ->and($transport->connection->sentJson(2)['realtime_input']['media_chunks'][0]['data'])->toBe(base64_encode('audio'))
        ->and($transport->connection->sentJson(3)['realtime_input']['activity_end'])->toBe([]);
});

it('waits for Agent Platform setup completion before returning a session', function () {
    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'global',
        'accessToken' => 'ya29.test',
    ]);

    expect(fn() => Live::voice()
        ->model(GoogleAgentPlatform::model('gemini-live-2.5-flash-native-audio'))
        ->connect(new FakeLiveTransport([], false)))
        ->toThrow(\AiSdk\Exceptions\InvalidResponseException::class, 'closed before acknowledging');
});

it('returns parallel Agent Platform tool calls in one protocol response', function () {
    GoogleAgentPlatform::create([
        'project' => 'my-project',
        'location' => 'global',
        'accessToken' => 'ya29.test',
    ]);
    $transport = new FakeLiveTransport([
        TransportFrame::text(json_encode([
            'tool_call' => ['function_calls' => [
                ['id' => 'call-weather', 'name' => 'weather', 'args' => ['city' => 'Lahore']],
                ['id' => 'call-time', 'name' => 'time', 'args' => ['city' => 'Lahore']],
            ]],
        ])),
    ]);

    $session = Live::voice()
        ->model(GoogleAgentPlatform::model('gemini-live-2.5-flash-native-audio'))
        ->tools([
            Tool::make('weather')->input(Schema::string('city')->required())->run(fn(string $city): string => "Sunny in {$city}"),
            Tool::make('time')->input(Schema::string('city')->required())->run(fn(string $city): string => "12:00 in {$city}"),
        ])
        ->connect($transport);

    iterator_to_array($session->events());

    expect($transport->connection->sent)->toHaveCount(2)
        ->and($transport->connection->sentJson(1)['tool_response']['function_responses'])->toBe([
            [
                'id' => 'call-weather',
                'name' => 'weather',
                'response' => ['result' => 'Sunny in Lahore'],
            ],
            [
                'id' => 'call-time',
                'name' => 'time',
                'response' => ['result' => '12:00 in Lahore'],
            ],
        ]);
});
