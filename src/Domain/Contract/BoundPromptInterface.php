<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Contract;

/**
 * A prompt bound to its own data: a typed, self-contained renderable. Instead of
 * the caller assembling a stringly-keyed `['assistant_name' => …]` array that
 * must match the template's `{{ }}` slots, the prompt object IS the render
 * context — the template reads its typed data through Twig's dot access on the
 * {@see self::CONTEXT_VARIABLE} handle: `{{ prompt.assistantName }}` resolves to
 * the class's `assistantName()` getter. There is no variables() map to keep in
 * sync: add a getter, reference `prompt.thatGetter`, done.
 *
 * The catalog still discovers the class as a passive definition (via #[AsPrompt],
 * parameterless), so the DB-override layer and listing are unaffected; a bound
 * instance is a per-render, immutable copy produced by a `with…()` builder — safe
 * to pass straight to {@see \Semitexa\Prompt\Application\Service\PromptRenderer::render()}.
 */
interface BoundPromptInterface
{
    /**
     * The Twig context key the bound prompt object is exposed under. Templates
     * (and DB overrides) read its data via `{{ prompt.<getter> }}`.
     */
    public const CONTEXT_VARIABLE = 'prompt';

    /** The catalog id whose template this instance renders. */
    public function promptId(): string;
}
