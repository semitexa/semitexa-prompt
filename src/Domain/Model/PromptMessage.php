<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Model;

use Semitexa\Prompt\Domain\Enum\MessageRole;

/**
 * A single role-tagged message. Used for few-shot examples attached to a
 * {@see PromptTemplate} and for the expanded messages of a
 * {@see RenderedPrompt}.
 *
 * Immutable value object — a `with*` helper returns a copy so a renderer can
 * substitute variables into the content without mutating the source template.
 */
final readonly class PromptMessage
{
    public function __construct(
        public MessageRole $role,
        public string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self(MessageRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(MessageRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(MessageRole::Assistant, $content);
    }

    public function withContent(string $content): self
    {
        return new self($this->role, $content);
    }

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role->value, 'content' => $this->content];
    }
}
