<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Contract;

/**
 * A prompt bound to its own data: a typed, self-contained renderable. Instead of
 * the caller assembling a stringly-keyed `['assistant_name' => …]` array that
 * must match the template's `{{ }}` slots, a prompt implementing this exposes
 * typed setters and converts them to the variables map itself.
 *
 * The catalog still discovers the class as a passive definition (via #[AsPrompt],
 * parameterless), so the DB-override layer and listing are unaffected; a bound
 * instance is a per-render, immutable copy produced by a `with…()` builder — safe
 * to pass straight to {@see \Semitexa\Prompt\Application\Service\PromptRenderer::render()}.
 */
interface BoundPromptInterface
{
    /** The catalog id whose template this instance renders. */
    public function promptId(): string;

    /**
     * The variables to bind, derived from this instance's typed data.
     *
     * @return array<string, string>
     */
    public function variables(): array;
}
