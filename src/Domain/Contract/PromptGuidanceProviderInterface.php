<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Contract;

use Semitexa\Prompt\Domain\Model\PromptGuidance;

/**
 * Per-tenant guidance lookup, consulted at render time.
 *
 * Read by {@see \Semitexa\Prompt\Application\Service\PromptRenderer}, which binds
 * the joined text as the `guidance` variable. A template that does not print
 * `{{ guidance }}` never shows it, and one that prints it decides where operator
 * text lands.
 *
 * Placement is NOT an enforcement boundary, and must not be sold as one. The
 * whole system prompt reaches the same model, so guidance reading "ignore the
 * rules below" is still text the model interprets; putting it above the rules
 * makes it no less persuasive. Binding as a value stops Twig EVALUATING an
 * operator's `{{ ... }}`; it does not stop a model OBEYING their sentence.
 * Guidance authors are trusted at the level of someone who could edit the
 * prompt. A rule that must hold whatever the prompt says belongs outside the
 * prompt — a guard on the model's output.
 *
 * Implementations resolve the CURRENT tenant themselves (coroutine-local), so
 * the caller passes only the prompt id and, optionally, a narrower scope.
 */
interface PromptGuidanceProviderInterface
{
    /**
     * The variable a prompt prints to admit operator guidance.
     *
     * Declared on the contract rather than on the renderer so
     * {@see \Semitexa\Prompt\Domain\Model\PromptTemplate} can name it without
     * a domain class reaching up into a service — the same arrangement
     * {@see BoundPromptInterface::CONTEXT_VARIABLE} already has.
     */
    public const CONTEXT_VARIABLE = 'guidance';

    /**
     * The enabled guidance for a prompt, oldest first — the order it was given
     * in, which is the order it reads in.
     *
     * A null $scope returns only the rows that apply everywhere. A named $scope
     * returns those PLUS the rows carrying it, so scoped guidance adds to the
     * general guidance rather than replacing it.
     *
     * @return list<PromptGuidance>
     */
    public function guidanceFor(string $promptId, ?string $scope = null): array;

    /**
     * The same rows joined into the text bound as `{{ guidance }}`, or an empty
     * string when there is none — so a template printing it renders unchanged
     * for every tenant that has said nothing.
     */
    public function textFor(string $promptId, ?string $scope = null): string;
}
