<?php

declare(strict_types=1);

namespace Semitexa\Prompt;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'prompt.catalog',
    summary: 'Prompts as discoverable Twig templates declared with #[AsPrompt], composable and overridable per tenant.',
    useWhen: 'Prompt text has to be edited, reviewed or varied per tenant without touching PHP.',
    avoidWhen: 'One short fixed instruction used in exactly one place.',
    replaces: [
        'heredoc prompt strings inside the service that sends them',
        'a per-tenant if-branch selecting between copies of the same prompt',
    ],
    seeAlso: 'semitexa/llm',
)]
final class Capabilities
{
}
