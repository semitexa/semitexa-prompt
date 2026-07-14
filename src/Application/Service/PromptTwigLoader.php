<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * A Twig loader whose template names are prompt ids: it resolves each id to its
 * Twig source via a {@see PromptRepositoryInterface}. Because the repository can
 * be the override-aware {@see LayeredPromptRepository}, both `{{ include('id') }}`
 * composition AND per-tenant DB overrides flow through this one seam.
 *
 * The cache key hashes the source, so a changed override compiles once under a
 * new key rather than serving a stale compiled template.
 */
final class PromptTwigLoader implements LoaderInterface
{
    public function __construct(private readonly PromptRepositoryInterface $repository) {}

    public function getSourceContext(string $name): Source
    {
        $template = $this->repository->tryGet($name);
        if ($template === null) {
            throw new LoaderError(sprintf('Prompt "%s" is not in the catalog.', $name));
        }

        return new Source($template->system, $name);
    }

    public function getCacheKey(string $name): string
    {
        $template = $this->repository->tryGet($name);
        $source = $template?->system ?? '';

        // Hash the source into the key so an override recompiles under a new key.
        return 'prompt:' . $name . ':' . hash('xxh128', $source);
    }

    public function isFresh(string $name, int $time): bool
    {
        // Freshness is carried by the source-hashed cache key, so a cached entry
        // is always valid for its key.
        return true;
    }

    public function exists(string $name): bool
    {
        return $this->repository->has($name);
    }
}
