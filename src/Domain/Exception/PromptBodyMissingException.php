<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Exception;

use RuntimeException;

/**
 * Raised when an `#[AsPrompt]` class carries no body at all: no Twig template
 * under any candidate owner root, and no legacy
 * {@see \Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface::system()}.
 *
 * This is a misconfiguration, not a prompt to skip — the same judgement
 * `PromptRegistry::buildFromClasses()` already applies to a duplicate id. Skipping
 * it quietly is what made a missing body cost weeks: the class stays discoverable,
 * the catalog stays plausible, and the only symptom is a caller quietly serving
 * whatever fallback it kept. The message names every root that was searched,
 * because "where did you look" is the sole question this failure raises.
 */
final class PromptBodyMissingException extends RuntimeException
{
    private function __construct(string $message, public readonly string $promptId)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $rootsSearched
     */
    public static function forClass(string $class, string $id, string $templateFile, array $rootsSearched): self
    {
        return new self(sprintf(
            'Prompt "%s" declared by %s has no body: no "%s" under %s, and the class does not implement PromptDefinitionInterface.',
            $id,
            $class,
            $templateFile,
            $rootsSearched === []
                ? 'any resolvable owner root (the class file has no package or module root)'
                : implode(' or ', $rootsSearched),
        ), $id);
    }
}
