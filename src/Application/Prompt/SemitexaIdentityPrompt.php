<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * A reusable identity fragment — a partial to compose into others via
 * `{{ include('core.identity') }}`. Body in resources/prompts/core.identity.twig.
 *
 * Self-binding: carries the assistant name, read by the template via
 * `{{ prompt.assistantName }}`.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'partial',
    description: "Reusable assistant-identity fragment; compose with {{ include('core.identity') }}.",
    template: 'resources/prompts/core.identity.twig',
)]
final class SemitexaIdentityPrompt implements BoundPromptInterface
{
    public const ID = 'core.identity';

    public function __construct(private readonly ?string $assistantName = null) {}

    public function withData(string $assistantName): self
    {
        return new self($assistantName);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function assistantName(): string
    {
        return (string) $this->assistantName;
    }
}
