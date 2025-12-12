<?php

namespace Tests\Schemas;

use Illuminate\Broadcasting\Channel;
use Prism\Prism\Events\Broadcasting\ErrorBroadcast;
use Prism\Prism\Events\Broadcasting\StreamEndBroadcast;
use Prism\Prism\Events\Broadcasting\StreamStartBroadcast;
use Prism\Prism\Events\Broadcasting\TextCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\TextDeltaBroadcast;
use Prism\Prism\Events\Broadcasting\TextStartBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingCompleteBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingStartBroadcast;
use Prism\Prism\Events\Broadcasting\ToolCallBroadcast;
use Prism\Prism\Events\Broadcasting\ToolResultBroadcast;
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
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Tests\Fixtures\FixtureResponse;

it('stream events are compatible with Prism broadcasting classes', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-text-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    $channel = new Channel('test-channel');

    // Test that each event type can be wrapped in its corresponding broadcast class
    foreach ($events as $event) {
        $broadcast = match (true) {
            $event instanceof StreamStartEvent => new StreamStartBroadcast($event, $channel),
            $event instanceof TextStartEvent => new TextStartBroadcast($event, $channel),
            $event instanceof TextDeltaEvent => new TextDeltaBroadcast($event, $channel),
            $event instanceof TextCompleteEvent => new TextCompleteBroadcast($event, $channel),
            $event instanceof StreamEndEvent => new StreamEndBroadcast($event, $channel),
            $event instanceof ThinkingStartEvent => new ThinkingStartBroadcast($event, $channel),
            $event instanceof ThinkingEvent => new ThinkingBroadcast($event, $channel),
            $event instanceof ThinkingCompleteEvent => new ThinkingCompleteBroadcast($event, $channel),
            $event instanceof ToolCallEvent => new ToolCallBroadcast($event, $channel),
            $event instanceof ToolResultEvent => new ToolResultBroadcast($event, $channel),
            $event instanceof ErrorEvent => new ErrorBroadcast($event, $channel),
            default => null,
        };

        expect($broadcast)->not->toBeNull();

        // Verify broadcast methods work
        expect($broadcast->broadcastOn())->toBe([$channel]);
        expect($broadcast->broadcastAs())->toBeString();
        expect($broadcast->broadcastWith())->toBeArray();
        expect($broadcast->broadcastWith())->toHaveKey('id');
        expect($broadcast->broadcastWith())->toHaveKey('timestamp');
    }
});

it('bedrock stream events can be broadcast to Laravel channels', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-with-thinking-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-sonnet-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    $channel = new Channel('ai-stream-123');
    $broadcasts = [];

    foreach ($events as $event) {
        $broadcast = match (true) {
            $event instanceof StreamStartEvent => new StreamStartBroadcast($event, $channel),
            $event instanceof TextStartEvent => new TextStartBroadcast($event, $channel),
            $event instanceof TextDeltaEvent => new TextDeltaBroadcast($event, $channel),
            $event instanceof TextCompleteEvent => new TextCompleteBroadcast($event, $channel),
            $event instanceof StreamEndEvent => new StreamEndBroadcast($event, $channel),
            $event instanceof ThinkingStartEvent => new ThinkingStartBroadcast($event, $channel),
            $event instanceof ThinkingEvent => new ThinkingBroadcast($event, $channel),
            $event instanceof ThinkingCompleteEvent => new ThinkingCompleteBroadcast($event, $channel),
            default => null,
        };

        if ($broadcast !== null) {
            $broadcasts[] = $broadcast;
        }
    }

    // Verify we got broadcasts for all event types including thinking
    expect($broadcasts)->toHaveCount(10);

    // Verify thinking events are broadcastable
    $thinkingBroadcasts = array_filter($broadcasts, fn (\Prism\Prism\Events\Broadcasting\StreamEndBroadcast|\Prism\Prism\Events\Broadcasting\StreamStartBroadcast|\Prism\Prism\Events\Broadcasting\TextCompleteBroadcast|\Prism\Prism\Events\Broadcasting\TextDeltaBroadcast|\Prism\Prism\Events\Broadcasting\TextStartBroadcast|\Prism\Prism\Events\Broadcasting\ThinkingBroadcast|\Prism\Prism\Events\Broadcasting\ThinkingCompleteBroadcast|\Prism\Prism\Events\Broadcasting\ThinkingStartBroadcast $b): bool => $b instanceof ThinkingBroadcast);
    expect($thinkingBroadcasts)->toHaveCount(2);

    // Verify text events are broadcastable
    $textDeltaBroadcasts = array_filter($broadcasts, fn (\Prism\Prism\Events\Broadcasting\StreamEndBroadcast|\Prism\Prism\Events\Broadcasting\StreamStartBroadcast|\Prism\Prism\Events\Broadcasting\TextCompleteBroadcast|\Prism\Prism\Events\Broadcasting\TextDeltaBroadcast|\Prism\Prism\Events\Broadcasting\TextStartBroadcast|\Prism\Prism\Events\Broadcasting\ThinkingBroadcast|\Prism\Prism\Events\Broadcasting\ThinkingCompleteBroadcast|\Prism\Prism\Events\Broadcasting\ThinkingStartBroadcast $b): bool => $b instanceof TextDeltaBroadcast);
    expect($textDeltaBroadcasts)->toHaveCount(2);
});

it('can broadcast to multiple channels simultaneously', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-text-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    // Create multiple channels
    $channels = [
        new Channel('user-123'),
        new Channel('admin-notifications'),
        new Channel('analytics'),
    ];

    // Test that broadcasts can target multiple channels
    foreach ($events as $event) {
        if ($event instanceof TextDeltaEvent) {
            $broadcast = new TextDeltaBroadcast($event, $channels);

            expect($broadcast->broadcastOn())->toBe($channels);
            expect($broadcast->broadcastOn())->toHaveCount(3);
        }
    }
});

it('broadcasts tool call and result events correctly', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-with-tool-call-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Search for something')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    $channel = new Channel('tool-stream');

    // Find and verify tool call events
    $toolCallEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof ToolCallEvent);
    expect($toolCallEvents)->toHaveCount(1);

    $toolCallEvent = array_values($toolCallEvents)[0];
    $broadcast = new ToolCallBroadcast($toolCallEvent, $channel);

    // Verify broadcast structure
    expect($broadcast->broadcastAs())->toBe('tool_call');
    expect($broadcast->broadcastWith())->toHaveKey('tool_id');
    expect($broadcast->broadcastWith())->toHaveKey('tool_name');
    expect($broadcast->broadcastWith())->toHaveKey('arguments');
    expect($broadcast->broadcastWith()['tool_name'])->toBe('search');
});

it('broadcasts error events with correct structure', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-error-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    $channel = new Channel('error-channel');

    // Find error events
    $errorEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof ErrorEvent);
    expect($errorEvents)->toHaveCount(1);

    $errorEvent = array_values($errorEvents)[0];
    $broadcast = new ErrorBroadcast($errorEvent, $channel);

    // Verify error broadcast structure
    expect($broadcast->broadcastAs())->toBe('error');
    expect($broadcast->broadcastWith())->toHaveKey('error_type');
    expect($broadcast->broadcastWith())->toHaveKey('message');
    expect($broadcast->broadcastWith())->toHaveKey('recoverable');
    expect($broadcast->broadcastWith()['error_type'])->toBe('overloaded_error');
    expect($broadcast->broadcastWith()['message'])->toBe('Overloaded');
});

it('serializes events correctly for WebSocket transmission', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-text-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    $channel = new Channel('websocket-test');

    foreach ($events as $event) {
        $broadcast = match (true) {
            $event instanceof StreamStartEvent => new StreamStartBroadcast($event, $channel),
            $event instanceof TextStartEvent => new TextStartBroadcast($event, $channel),
            $event instanceof TextDeltaEvent => new TextDeltaBroadcast($event, $channel),
            $event instanceof TextCompleteEvent => new TextCompleteBroadcast($event, $channel),
            $event instanceof StreamEndEvent => new StreamEndBroadcast($event, $channel),
            default => null,
        };

        if ($broadcast === null) {
            continue;
        }

        // Verify the broadcast data can be JSON encoded (required for WebSocket transmission)
        $json = json_encode($broadcast->broadcastWith());
        expect($json)->toBeString();
        expect($json)->not->toBeFalse();

        // Verify it can be decoded back
        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded)->toHaveKey('id');
        expect($decoded)->toHaveKey('timestamp');
    }
});

it('broadcasts stream end event with usage and finish reason', function (): void {
    FixtureResponse::fakeStreamResponse('invoke-with-response-stream', 'anthropic/stream-text-1.txt');

    $events = [];
    $generator = (new Prism)->text()
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withPrompt('Say hello')
        ->asStream();

    foreach ($generator as $event) {
        $events[] = $event;
    }

    $channel = new Channel('completion-channel');

    // Find stream end event
    $endEvents = array_filter($events, fn (\Prism\Prism\Streaming\Events\StreamEvent $e): bool => $e instanceof StreamEndEvent);
    expect($endEvents)->toHaveCount(1);

    $endEvent = array_values($endEvents)[0];
    $broadcast = new StreamEndBroadcast($endEvent, $channel);

    // Verify stream end broadcast has required fields
    expect($broadcast->broadcastAs())->toBe('stream_end');
    expect($broadcast->broadcastWith())->toHaveKey('finish_reason');
    expect($broadcast->broadcastWith())->toHaveKey('usage');
    expect($broadcast->broadcastWith()['usage'])->toHaveKey('prompt_tokens');
    expect($broadcast->broadcastWith()['usage'])->toHaveKey('completion_tokens');
});
