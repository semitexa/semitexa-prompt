<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Model;

/**
 * The output of rendering a {@see PromptTemplate}: fully-resolved system text
 * (partials spliced, variables bound) plus the expanded few-shot messages.
 *
 * Provider-agnostic on purpose. Downstream code adapts this into whatever the
 * LLM layer needs — e.g. semitexa-llm's `LlmRequest` — without this package
 * ever depending on a provider.
 */
final readonly class RenderedPrompt
{
    /**
     * @param list<PromptMessage>   $messages  few-shot messages, variables bound
     * @param array<string, string> $variables the values that were bound
     */
    public function __construct(
        public string $promptId,
        public string $system,
        public array $messages = [],
        public array $variables = [],
    ) {}

    /**
     * @return array{prompt_id: string, system: string, messages: list<array{role: string, content: string}>, variables: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'prompt_id' => $this->promptId,
            'system' => $this->system,
            'messages' => array_map(static fn(PromptMessage $m): array => $m->toArray(), $this->messages),
            'variables' => $this->variables,
        ];
    }
}
