<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Sandbox\SourcePolicyInterface;
use Twig\Source;

/**
 * A Twig loader whose template names are prompt ids: it resolves each id to its
 * Twig source via a {@see PromptRepositoryInterface}. Because the repository can
 * be the override-aware {@see LayeredPromptRepository}, both `{{ include('id') }}`
 * composition AND per-tenant DB overrides flow through this one seam.
 *
 * It is also the one place that knows where each body came from, so it is what
 * tells Twig which sources run in the {@see PromptSandbox}: every body an
 * operator wrote, whether it is the prompt being rendered or one it includes.
 *
 * The cache key hashes the source, so a changed override compiles once under a
 * new key rather than serving a stale compiled template.
 */
final class PromptTwigLoader implements LoaderInterface, SourcePolicyInterface
{
    public function __construct(private readonly PromptRepositoryInterface $repository) {}

    /** @var array<string, bool> template name → an operator wrote its body */
    private array $operatorWritten = [];

    /** @var array<string, string> sources rendered directly, by the name {@see inline()} gave them */
    private array $inline = [];

    /**
     * Register a source that is rendered directly rather than looked up by id
     * (the prompt being rendered, a few-shot message) and return the template
     * name to load it by. The name carries a hash of the source, so the same
     * label with another body is another template.
     *
     * This replaces Twig's `createTemplate()` so that the name — the only thing
     * the sandbox can recognise a source by — is decided here, not by Twig.
     */
    public function inline(string $label, string $source, bool $operatorWritten): string
    {
        $name = sprintf('%s (string template %s)', $label, hash('xxh128', $source));
        $this->inline[$name] = $source;
        $this->operatorWritten[$name] = $operatorWritten;

        return $name;
    }

    /**
     * Whether Twig runs this source sandboxed: yes when an operator wrote it.
     *
     * Asked of the repository by name, and not noted while the source is being
     * loaded, because Twig does not load what it already has: a body compiled
     * by an earlier render comes from the compile cache, or is already a class
     * in this worker, and its source is never read again.
     *
     * A name nothing here can account for is sandboxed.
     */
    public function enableSandbox(Source $source): bool
    {
        $name = $source->getName();

        if (!isset($this->operatorWritten[$name])) {
            $template = $this->repository->tryGet($name);
            $this->operatorWritten[$name] = $template === null || $template->operatorWritten;
        }

        return $this->operatorWritten[$name];
    }

    public function getSourceContext(string $name): Source
    {
        if (isset($this->inline[$name])) {
            return new Source($this->inline[$name], $name);
        }

        $template = $this->repository->tryGet($name);
        if ($template === null) {
            throw new LoaderError(sprintf('Prompt "%s" is not in the catalog.', $name));
        }

        return new Source($template->system, $name);
    }

    public function getCacheKey(string $name): string
    {
        if (isset($this->inline[$name])) {
            // The name already carries the hash of its source.
            return 'inline:' . $name;
        }

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
        return isset($this->inline[$name]) || $this->repository->has($name);
    }
}
