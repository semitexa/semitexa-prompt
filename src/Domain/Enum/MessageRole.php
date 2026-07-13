<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Enum;

/**
 * Conversation role of a {@see \Semitexa\Prompt\Domain\Model\PromptMessage}.
 *
 * Deliberately provider-agnostic: this package never speaks a wire format. A
 * downstream adapter (e.g. in semitexa-llm) maps these roles onto whatever the
 * target provider expects (Gemini `role`, Ollama message roles, ...).
 */
enum MessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
