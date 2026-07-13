<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Attribute;

use Attribute;

/**
 * Marks a class as a discoverable prompt-catalog definition.
 *
 * The class is thin: metadata only. Its Twig body lives in a template file
 * under the owning package's `resources/prompts/`, named by {@see $template}
 * (or, when omitted, by the convention `{id}.twig`). A class may implement
 * {@see \Semitexa\Prompt\Domain\Contract\FewShotProviderInterface} to ship typed
 * few-shot examples. {@see \Semitexa\Prompt\Application\Service\PromptRegistry}
 * reflects over this attribute to build the catalog — the same discovery idiom
 * `#[AsAiSkill]` uses for skills.
 *
 * The {@see $id} is the catalog key. Prompts reference each other by id via
 * `{{ include('id') }}` in their Twig source, so ids should be stable and
 * namespaced (e.g. `os.planner`, `core.identity`).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsPrompt
{
    public function __construct(
        public string $id,
        public string $channel = 'default',
        public ?string $description = null,
        /**
         * The Twig template file (relative to the package's `resources/prompts/`)
         * that holds this prompt's body. Makes the class → template link explicit.
         * When null, the registry falls back to the convention `{id}.twig`.
         */
        public ?string $template = null,
    ) {}
}
