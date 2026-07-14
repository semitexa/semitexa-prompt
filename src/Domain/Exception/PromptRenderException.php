<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Exception;

use RuntimeException;

/**
 * Raised when a template cannot be rendered: an unbound required variable, an
 * unresolvable partial include, or a partial-include cycle. Rendering is
 * fail-closed — a half-substituted prompt with a literal `{{ token }}` left in
 * it silently corrupts an LLM call, so we stop instead.
 */
final class PromptRenderException extends RuntimeException
{
    /**
     * @param list<string> $missing
     */
    public static function missingVariables(string $promptId, array $missing): self
    {
        return new self(sprintf(
            'Cannot render prompt "%s": missing value(s) for variable(s) %s.',
            $promptId,
            implode(', ', array_map(static fn(string $v): string => '{{' . $v . '}}', $missing)),
        ));
    }

    public static function unknownPartial(string $promptId, string $detail): self
    {
        return new self(sprintf(
            'Cannot render prompt "%s": an included partial could not be resolved (%s).',
            $promptId,
            $detail,
        ));
    }

    /**
     * @param list<string> $stack
     */
    public static function partialCycle(string $promptId, array $stack): self
    {
        return new self(sprintf(
            'Cannot render prompt "%s": partial include cycle detected (%s).',
            $promptId,
            implode(' -> ', $stack),
        ));
    }
}
