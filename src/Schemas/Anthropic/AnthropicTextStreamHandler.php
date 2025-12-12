<?php

namespace Prism\Bedrock\Schemas\Anthropic;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Contracts\BedrockTextStreamHandler;
use Prism\Bedrock\Schemas\Anthropic\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Anthropic\ValueObjects\AnthropicStreamState;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class AnthropicTextStreamHandler extends BedrockTextStreamHandler
{
    use CallsTools;

    protected AnthropicStreamState $state;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->state = new AnthropicStreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    #[\Override]
    public function handle(Request $request): Generator
    {
        $this->state->reset();
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        while (! $response->getBody()->eof()) {
            $event = $this->parseNextBedrockEvent($response->getBody());

            if ($event === null) {
                continue;
            }

            $streamEvent = $this->processEvent($event);

            if ($streamEvent instanceof Generator) {
                yield from $streamEvent;
            } elseif ($streamEvent instanceof StreamEvent) {
                yield $streamEvent;
            }
        }

        // Handle tool calls if present
        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $depth);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return StreamEvent|Generator<StreamEvent>|null
     */
    protected function processEvent(array $event): StreamEvent|Generator|null
    {
        return match ($event['type'] ?? null) {
            'message_start' => $this->handleMessageStart($event),
            'content_block_start' => $this->handleContentBlockStart($event),
            'content_block_delta' => $this->handleContentBlockDelta($event),
            'content_block_stop' => $this->handleContentBlockStop($event),
            'message_delta' => $this->handleMessageDelta($event),
            'message_stop' => $this->handleMessageStop($event),
            'error' => $this->handleError($event),
            'ping' => null, // Ignore ping events
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageStart(array $event): StreamStartEvent
    {
        $message = $event['message'] ?? [];
        $this->state->withMessageId($message['id'] ?? EventID::generate());

        // Capture initial usage data
        $usageData = $message['usage'] ?? [];
        if (! empty($usageData)) {
            $this->state->withUsage(new Usage(
                promptTokens: $usageData['input_tokens'] ?? 0,
                completionTokens: $usageData['output_tokens'] ?? 0,
                cacheWriteInputTokens: $usageData['cache_creation_input_tokens'] ?? null,
                cacheReadInputTokens: $usageData['cache_read_input_tokens'] ?? null
            ));
        }

        return new StreamStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            model: $message['model'] ?? 'unknown',
            provider: 'bedrock'
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStart(array $event): ?StreamEvent
    {
        $contentBlock = $event['content_block'] ?? [];
        $this->state->withBlockContext(
            $event['index'] ?? 0,
            $contentBlock['type'] ?? ''
        );

        return match ($this->state->currentBlockType()) {
            'text' => new TextStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            ),
            'thinking' => $this->handleThinkingStart(),
            'tool_use' => $this->handleToolUseStart($contentBlock),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockDelta(array $event): ?StreamEvent
    {
        $delta = $event['delta'] ?? [];
        $deltaType = $delta['type'] ?? null;

        return match ([$this->state->currentBlockType(), $deltaType]) {
            ['text', 'text_delta'] => $this->handleTextDelta($delta),
            ['thinking', 'thinking_delta'] => $this->handleThinkingDelta($delta),
            ['thinking', 'signature_delta'] => $this->handleSignatureDelta($delta),
            ['tool_use', 'input_json_delta'] => $this->handleToolInputDelta($delta),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStop(array $event): ?StreamEvent
    {
        $result = match ($this->state->currentBlockType()) {
            'text' => new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            ),
            'thinking' => new ThinkingCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId()
            ),
            'tool_use' => $this->handleToolUseComplete(),
            default => null,
        };

        $this->state->resetBlockContext();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageDelta(array $event): null
    {
        // Update usage with final data from message_delta
        $usageData = $event['usage'] ?? [];
        if (! empty($usageData) && $this->state->usage() instanceof Usage && isset($usageData['output_tokens'])) {
            $currentUsage = $this->state->usage();
            $this->state->withUsage(new Usage(
                promptTokens: $currentUsage->promptTokens,
                completionTokens: $usageData['output_tokens'],
                cacheWriteInputTokens: $currentUsage->cacheWriteInputTokens,
                cacheReadInputTokens: $currentUsage->cacheReadInputTokens
            ));
        }

        // Update finish reason
        $stopReason = $event['delta']['stop_reason'] ?? null;
        if ($stopReason !== null) {
            $this->state->withFinishReason(FinishReasonMap::map($stopReason));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageStop(array $event): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage(),
            additionalContent: [
                'thinking' => $this->state->thinkingSummaries() === [] ? null : implode('', $this->state->thinkingSummaries()),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleError(array $event): ErrorEvent
    {
        return new ErrorEvent(
            id: EventID::generate(),
            timestamp: time(),
            errorType: $event['error']['type'] ?? 'unknown_error',
            message: $event['error']['message'] ?? 'Unknown error occurred',
            recoverable: true
        );
    }

    protected function handleThinkingStart(): ThinkingStartEvent
    {
        $this->state->withReasoningId(EventID::generate());

        return new ThinkingStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            reasoningId: $this->state->reasoningId()
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleTextDelta(array $delta): ?TextDeltaEvent
    {
        $text = $delta['text'] ?? '';

        if ($text === '') {
            return null;
        }

        $this->state->appendText($text);

        return new TextDeltaEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $text,
            messageId: $this->state->messageId()
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleThinkingDelta(array $delta): ?ThinkingEvent
    {
        $thinking = $delta['thinking'] ?? '';

        if ($thinking === '') {
            return null;
        }

        $this->state->appendThinking($thinking);

        return new ThinkingEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $thinking,
            reasoningId: $this->state->reasoningId()
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleSignatureDelta(array $delta): null
    {
        $this->state->appendThinkingSignature($delta['signature'] ?? '');

        return null;
    }

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    protected function handleToolUseStart(array $contentBlock): null
    {
        if ($this->state->currentBlockIndex() !== null) {
            $this->state->addToolCall($this->state->currentBlockIndex(), [
                'id' => $contentBlock['id'] ?? EventID::generate(),
                'name' => $contentBlock['name'] ?? 'unknown',
                'input' => '',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleToolInputDelta(array $delta): null
    {
        $partialJson = $delta['partial_json'] ?? '';

        if ($this->state->currentBlockIndex() !== null && isset($this->state->toolCalls()[$this->state->currentBlockIndex()])) {
            $this->state->appendToolCallInput($this->state->currentBlockIndex(), $partialJson);
        }

        return null;
    }

    protected function handleToolUseComplete(): ?ToolCallEvent
    {
        if ($this->state->currentBlockIndex() === null || ! isset($this->state->toolCalls()[$this->state->currentBlockIndex()])) {
            return null;
        }

        $toolCall = $this->state->toolCalls()[$this->state->currentBlockIndex()];
        $input = $toolCall['input'];

        // Parse the JSON input
        if (is_string($input) && json_validate($input)) {
            $input = json_decode($input, true);
        } elseif (is_string($input) && $input !== '') {
            $input = ['input' => $input];
        } else {
            $input = [];
        }

        $toolCallObj = new ToolCall(
            id: $toolCall['id'],
            name: $toolCall['name'],
            arguments: $input
        );

        return new ToolCallEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolCall: $toolCallObj,
            messageId: $this->state->messageId()
        );
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $toolCalls = [];

        // Convert tool calls to ToolCall objects
        foreach ($this->state->toolCalls() as $toolCallData) {
            $input = $toolCallData['input'];
            if (is_string($input) && json_validate($input)) {
                $input = json_decode($input, true);
            } elseif (is_string($input) && $input !== '') {
                $input = ['input' => $input];
            } else {
                $input = [];
            }

            $toolCalls[] = new ToolCall(
                id: $toolCallData['id'],
                name: $toolCallData['name'],
                arguments: $input
            );
        }

        // Execute tools and emit results
        $toolResults = [];
        foreach ($toolCalls as $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $request->tools());
                $result = call_user_func_array($tool->handle(...), $toolCall->arguments());

                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $result
                );

                $toolResults[] = $toolResult;

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $toolResult,
                    messageId: $this->state->messageId(),
                    success: true
                );
            } catch (Throwable $e) {
                $errorResultObj = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: []
                );

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $errorResultObj,
                    messageId: $this->state->messageId(),
                    success: false,
                    error: $e->getMessage()
                );
            }
        }

        // Add messages to request for next turn
        if ($toolResults !== []) {
            $request->addMessage(new AssistantMessage(
                content: $this->state->currentText(),
                toolCalls: $toolCalls
            ));

            $request->addMessage(new ToolResultMessage($toolResults));

            // Continue streaming if within step limit
            $depth++;
            if ($depth < $request->maxSteps()) {
                $this->state->reset();
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);
            }
        }
    }

    /**
     * Parse the next event from Bedrock's AWS Event Stream format.
     *
     * @return array<string, mixed>|null
     */
    protected function parseNextBedrockEvent(StreamInterface $stream): ?array
    {
        // AWS Event Stream format:
        // - Prelude: total_length (4 bytes) + headers_length (4 bytes) + prelude_crc (4 bytes)
        // - Headers: variable length
        // - Payload: variable length
        // - Message CRC: 4 bytes

        $prelude = $stream->read(12);
        if (strlen($prelude) < 12) {
            return null;
        }

        $totalLength = unpack('N', substr($prelude, 0, 4))[1];
        $headersLength = unpack('N', substr($prelude, 4, 4))[1];

        // Read remaining bytes (total - prelude already read)
        $remaining = $stream->read($totalLength - 12);
        if (strlen($remaining) < $totalLength - 12) {
            return null;
        }

        // Extract headers and payload
        $headers = substr($remaining, 0, $headersLength);
        $payload = substr($remaining, $headersLength, -4); // Exclude message CRC

        // Parse headers to find event type and content type
        $parsedHeaders = $this->parseEventStreamHeaders($headers);

        // Skip non-event messages (e.g., exceptions)
        $messageType = $parsedHeaders[':message-type'] ?? '';
        if ($messageType === 'exception') {
            return null;
        }

        // For Anthropic on Bedrock, the payload is JSON with a 'bytes' field containing base64-encoded event data
        try {
            $payloadData = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            if (isset($payloadData['bytes'])) {
                $eventJson = base64_decode((string) $payloadData['bytes']);

                return json_decode($eventJson, true, flags: JSON_THROW_ON_ERROR);
            }

            return $payloadData;
        } catch (Throwable) {
            // If we can't parse, skip this event
            return null;
        }
    }

    /**
     * Parse AWS Event Stream headers.
     *
     * @return array<string, string>
     */
    protected function parseEventStreamHeaders(string $headers): array
    {
        $parsed = [];
        $offset = 0;
        $length = strlen($headers);

        while ($offset < $length) {
            // Header name length (1 byte)
            $nameLength = ord($headers[$offset]);
            $offset++;

            // Header name
            $name = substr($headers, $offset, $nameLength);
            $offset += $nameLength;

            // Header type (1 byte) - 7 = string
            $type = ord($headers[$offset]);
            $offset++;

            if ($type === 7) {
                // String value: length (2 bytes) + value
                $valueLength = unpack('n', substr($headers, $offset, 2))[1];
                $offset += 2;
                $value = substr($headers, $offset, $valueLength);
                $offset += $valueLength;
                $parsed[$name] = $value;
            } else {
                // Skip other types for now
                break;
            }
        }

        return $parsed;
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            $payload = AnthropicTextHandler::buildPayload($request, $this->provider->apiVersion($request));

            /** @var Response $response */
            $response = $this->client
                ->withOptions(['stream' => true])
                ->post('invoke-with-response-stream', $payload);

            return $response;
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }
}
