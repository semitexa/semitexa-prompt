<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Prompt\Domain\Contract\PromptOverrideProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

/**
 * The overriding prompt repository: a tenant's DB override wins, otherwise the
 * code-shipped catalog. Bound as the {@see PromptRepositoryInterface}
 * implementation, so anything resolving prompts through the container (a
 * renderer wired to this interface, tooling, future OS editors) transparently
 * sees per-tenant overrides with catalog fallback — the prompt analogue of the
 * locale TranslationService's override→catalog precedence.
 *
 * Unlike locale (where the consumer does the fallback), the fallback lives HERE
 * because callers expect a whole {@see PromptTemplate} back: an override only
 * carries the system text, so it is overlaid onto the catalog template
 * (preserving channel / few-shot / metadata) via {@see PromptTemplate::withSystem()}.
 */
#[SatisfiesRepositoryContract(of: PromptRepositoryInterface::class)]
final class LayeredPromptRepository implements PromptRepositoryInterface
{
    #[InjectAsReadonly]
    protected PromptOverrideProviderInterface $overrides;

    private ?PromptRepositoryInterface $catalog = null;

    /** Test seam — production path uses property injection. */
    public function withOverrides(PromptOverrideProviderInterface $overrides): self
    {
        $this->overrides = $overrides;

        return $this;
    }

    /** Test seam — production builds its own code catalog lazily. */
    public function withCatalog(PromptRepositoryInterface $catalog): self
    {
        $this->catalog = $catalog;

        return $this;
    }

    public function get(string $id): PromptTemplate
    {
        return $this->tryGet($id) ?? throw PromptNotFoundException::forId($id);
    }

    public function tryGet(string $id): ?PromptTemplate
    {
        $base = $this->catalog()->tryGet($id);
        $override = $this->override($id);

        if ($override !== null) {
            return $base !== null
                ? $base->withSystem($override)
                : new PromptTemplate(id: $id, system: $override);
        }

        return $base;
    }

    public function has(string $id): bool
    {
        return $this->catalog()->has($id) || $this->override($id) !== null;
    }

    /**
     * @return list<PromptTemplate>
     */
    public function all(): array
    {
        $byId = [];
        foreach ($this->catalog()->all() as $template) {
            $byId[$template->id] = $template;
        }

        foreach ($this->overrideMap() as $id => $system) {
            $byId[$id] = isset($byId[$id])
                ? $byId[$id]->withSystem($system)
                : new PromptTemplate(id: $id, system: $system);
        }

        $templates = array_values($byId);
        usort($templates, static fn(PromptTemplate $a, PromptTemplate $b): int => strcmp($a->id, $b->id));

        return $templates;
    }

    private function override(string $id): ?string
    {
        return isset($this->overrides) ? $this->overrides->override($id) : null;
    }

    /**
     * @return array<string, string>
     */
    private function overrideMap(): array
    {
        return isset($this->overrides) ? $this->overrides->all() : [];
    }

    private function catalog(): PromptRepositoryInterface
    {
        return $this->catalog ??= new PromptRegistry();
    }
}
