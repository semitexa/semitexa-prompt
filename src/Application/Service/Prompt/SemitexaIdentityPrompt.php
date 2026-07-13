<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * A reusable identity fragment. Not a standalone prompt to send — a partial
 * meant to be composed into others via `{{> core.identity }}`.
 *
 * It ships one variable, `{{ assistant_name }}`, so a caller binds the concrete
 * persona name at render time. This is the canonical demonstration of the
 * package's two primitives working together: composition + variable binding.
 */
#[AsPrompt(
    id: 'core.identity',
    channel: 'partial',
    description: 'Reusable assistant-identity fragment; compose with {{> core.identity }}.',
)]
final class SemitexaIdentityPrompt implements PromptDefinitionInterface
{
    public function system(): string
    {
        return <<<'PROMPT'
        You are {{ assistant_name }}, an assistant operating inside a Semitexa application.
        Be precise, honest about uncertainty, and never claim to be the Semitexa runtime itself.
        PROMPT;
    }
}
