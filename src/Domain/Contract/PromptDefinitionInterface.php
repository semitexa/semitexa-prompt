<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Contract;

/**
 * A code-defined prompt. Implemented by every class carrying
 * {@see \Semitexa\Prompt\Attribute\AsPrompt}.
 *
 * Implementations MUST have a parameterless constructor — the registry
 * instantiates them with `new` during discovery. Keep them free of injected
 * dependencies: a prompt definition returns text, not behaviour. Anything
 * dynamic (a runtime list, a date) is a `{{ variable }}` bound at render time,
 * not a constructor dependency.
 */
interface PromptDefinitionInterface
{
    /**
     * The raw system template. May contain `{{ variable }}` and `{{> id }}`
     * partial-include tokens; both are resolved by the renderer.
     */
    public function system(): string;
}
