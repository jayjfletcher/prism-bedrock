<?php

namespace Prism\Bedrock\Schemas\Converse;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Contracts\BedrockTextStreamHandler;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Converse\ValueObjects\ConverseStreamState;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
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

class ConverseTextStreamHandler extends BedrockTextStreamHandler
{
    use CallsTools;

    protected ConverseStreamState $state;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->state = new ConverseStreamState;
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
            $event = $this->parseNextEvent($response->getBody());

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
        $eventType = array_keys($event)[0] ?? null;

        return match ($eventType) {
            'messageStart' => $this->handleMessageStart($event['messageStart']),
            'contentBlockStart' => $this->handleContentBlockStart($event['contentBlockStart']),
            'contentBlockDelta' => $this->handleContentBlockDelta($event['contentBlockDelta']),
            'contentBlockStop' => $this->handleContentBlockStop($event['contentBlockStop']),
            'messageStop' => $this->handleMessageStop($event['messageStop']),
            'metadata' => $this->handleMetadata($event['metadata']),
            'internalServerException',
            'modelStreamErrorException',
            'validationException',
            'throttlingException',
            'serviceUnavailableException' => $this->handleError($event[$eventType]),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleMessageStart(array $data): StreamStartEvent
    {
        $this->state->withMessageId(EventID::generate('msg'));

        return new StreamStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            model: 'unknown',
            provider: 'bedrock'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleContentBlockStart(array $data): ?StreamEvent
    {
        $index = $data['contentBlockIndex'] ?? 0;
        $start = $data['start'] ?? [];

        if (isset($start['toolUse'])) {
            $this->state->withBlockContext($index, 'tool_use');
            $this->state->addToolCall($index, [
                'id' => $start['toolUse']['toolUseId'] ?? EventID::generate('tool'),
                'name' => $start['toolUse']['name'] ?? 'unknown',
                'input' => '',
            ]);

            return null;
        }

        $this->state->withBlockContext($index, 'text');

        return new TextStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleContentBlockDelta(array $data): ?StreamEvent
    {
        $delta = $data['delta'] ?? [];

        if (isset($delta['text'])) {
            $text = $delta['text'];
            $this->state->appendText($text);

            return new TextDeltaEvent(
                id: EventID::generate(),
                timestamp: time(),
                delta: $text,
                messageId: $this->state->messageId()
            );
        }

        if (isset($delta['toolUse'])) {
            $partialJson = $delta['toolUse']['input'] ?? '';

            if ($this->state->currentBlockIndex() !== null) {
                $this->state->appendToolCallInput($this->state->currentBlockIndex(), $partialJson);
            }

            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleContentBlockStop(array $data): ?StreamEvent
    {
        $result = match ($this->state->currentBlockType()) {
            'text' => new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            ),
            'tool_use' => $this->handleToolUseComplete(),
            default => null,
        };

        $this->state->resetBlockContext();

        return $result;
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
     * @param  array<string, mixed>  $data
     */
    protected function handleMessageStop(array $data): null
    {
        $stopReason = $data['stopReason'] ?? null;

        if ($stopReason !== null) {
            $this->state->withFinishReason(FinishReasonMap::map($stopReason));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleMetadata(array $data): StreamEndEvent
    {
        $usage = $data['usage'] ?? [];

        $this->state->withUsage(new Usage(
            promptTokens: $usage['inputTokens'] ?? 0,
            completionTokens: $usage['outputTokens'] ?? 0
        ));

        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleError(array $data): ErrorEvent
    {
        return new ErrorEvent(
            id: EventID::generate(),
            timestamp: time(),
            errorType: $data['type'] ?? 'unknown_error',
            message: $data['message'] ?? 'Unknown error occurred',
            recoverable: false
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
     * @return array<string, mixed>|null
     */
    protected function parseNextEvent(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);
        $line = trim($line);

        if ($line === '' || $line === '0') {
            return null;
        }

        // AWS Event Stream format: events come as JSON with `:event-type` header
        // followed by JSON payload after `:message-type` header
        // For HTTP streaming, we receive SSE-like format
        if (str_starts_with($line, ':')) {
            // This is a header line, skip
            return null;
        }

        // Try to parse as JSON
        if (str_starts_with($line, '{')) {
            return $this->parseJsonData($line);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseJsonData(string $jsonData): ?array
    {
        $jsonData = trim($jsonData);

        if ($jsonData === '' || $jsonData === '0') {
            return null;
        }

        try {
            return json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Bedrock Converse', $e);
        }
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            /** @var Response $response */
            $response = $this->client
                ->withOptions(['stream' => true])
                ->post(
                    'converse-stream',
                    ConverseTextHandler::buildPayload($request, 0)
                );

            return $response;
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }
}
