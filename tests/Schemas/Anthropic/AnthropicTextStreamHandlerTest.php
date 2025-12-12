<?php

namespace Tests\Schemas\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Tests\Fixtures\FixtureResponse;

it('can stream text with a prompt', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-text-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    // Verify we received the expected events
    expect($events)->toHaveCount(8);

    // First event should be StreamStartEvent
    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($events[0]->provider)->toBe('bedrock');
    expect($events[0]->model)->toBe('claude-3-5-haiku-20241022');

    // Second event should be TextStartEvent
    expect($events[1])->toBeInstanceOf(TextStartEvent::class);

    // Text delta events
    expect($events[2])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[2]->delta)->toBe('Hello');

    expect($events[3])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[3]->delta)->toBe(', ');

    expect($events[4])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[4]->delta)->toBe('world');

    expect($events[5])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[5]->delta)->toBe('!');

    // TextCompleteEvent
    expect($events[6])->toBeInstanceOf(TextCompleteEvent::class);

    // Last event should be StreamEndEvent
    expect($events[7])->toBeInstanceOf(StreamEndEvent::class);
    expect($events[7]->finishReason)->toBe(FinishReason::Stop);
    expect($events[7]->usage->promptTokens)->toBe(10);
    expect($events[7]->usage->completionTokens)->toBe(4);
});

it('sends the correct request payload for streaming', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-text-1.txt');

    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->withMaxTokens(100)
        ->usingTemperature(0.7)
        ->asStream();

    // Consume the generator
    iterator_to_array($generator);

    Http::assertSent(function (Request $request): bool {
        expect($request->data())->toMatchArray([
            'anthropic_version' => 'bedrock-2023-05-31',
            'stream' => true,
            'max_tokens' => 100,
            'temperature' => 0.7,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Say hello',
                        ],
                    ],
                ],
            ],
        ]);

        return true;
    });
});

it('can stream text with thinking/reasoning content', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-with-thinking-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-sonnet-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    // Verify stream start
    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($events[0]->provider)->toBe('bedrock');

    // Verify thinking events
    expect($events[1])->toBeInstanceOf(ThinkingStartEvent::class);

    expect($events[2])->toBeInstanceOf(ThinkingEvent::class);
    expect($events[2]->delta)->toBe('Let me think about this...');

    expect($events[3])->toBeInstanceOf(ThinkingEvent::class);
    expect($events[3]->delta)->toBe(' The user wants a greeting.');

    expect($events[4])->toBeInstanceOf(ThinkingCompleteEvent::class);

    // Verify text events
    expect($events[5])->toBeInstanceOf(TextStartEvent::class);
    expect($events[6])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[6]->delta)->toBe('Hello');
    expect($events[7])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[7]->delta)->toBe('!');
    expect($events[8])->toBeInstanceOf(TextCompleteEvent::class);

    // Verify stream end includes thinking in additional content
    expect($events[9])->toBeInstanceOf(StreamEndEvent::class);
    expect($events[9]->additionalContent['thinking'])->toBe('Let me think about this... The user wants a greeting.');
});

it('can stream text with tool calls', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-with-tool-call-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Search for something')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    // Filter to relevant event types
    $streamStart = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof StreamStartEvent);
    $textDeltas = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof TextDeltaEvent);
    $toolCalls = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof ToolCallEvent);
    $streamEnd = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof StreamEndEvent);

    expect($streamStart)->toHaveCount(1);
    expect($textDeltas)->toHaveCount(1);
    expect($toolCalls)->toHaveCount(1);
    expect($streamEnd)->toHaveCount(1);

    // Verify tool call
    $toolCallEvent = array_values($toolCalls)[0];
    expect($toolCallEvent->toolCall->id)->toBe('toolu_01ABC123');
    expect($toolCallEvent->toolCall->name)->toBe('search');
    expect($toolCallEvent->toolCall->arguments())->toBe(['query' => 'test query']);
});

it('handles streaming errors', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-error-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    // Find the error event
    $errorEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof ErrorEvent);
    expect($errorEvents)->toHaveCount(1);

    $errorEvent = array_values($errorEvents)[0];
    expect($errorEvent->errorType)->toBe('overloaded_error');
    expect($errorEvent->message)->toBe('Overloaded');
    expect($errorEvent->recoverable)->toBeTrue();
});
