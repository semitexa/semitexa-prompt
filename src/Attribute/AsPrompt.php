<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Attribute;

use Attribute;

/**
 * Marks a class as a discoverable prompt definition.
 *
 * The annotated class must implement
 * {@see \Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface} (it supplies
 * the system template) and may implement
 * {@see \Semitexa\Prompt\Domain\Contract\FewShotProviderInterface} (few-shot
 * examples). {@see \Semitexa\Prompt\Application\Service\PromptRegistry} reflects
 * over this attribute to build the catalog — the same discovery idiom
 * `#[AsAiSkill]` uses for skills.
 *
 * The {@see $id} is the catalog key. Prompts reference each other by id via
 * `{{> id }}` partial includes, so ids should be stable and namespaced
 * (e.g. `os.planner`, `core.identity`).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsPrompt
{
    public function __construct(
        public string $id,
        public string $channel = 'default',
        public ?string $description = null,
    ) {}
}
