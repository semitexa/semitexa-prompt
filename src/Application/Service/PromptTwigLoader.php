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
 * A renderer has two of these, one per Twig environment:
 *
 *  - the TRUSTED one serves code-shipped bodies only. Asked for a prompt whose
 *    body an operator wrote, it serves {@see self::SANDBOX_STUB} in its place: a
 *    one-line body that hands the render to the sandboxed environment. The
 *    operator's text never reaches the trusted environment at all;
 *  - the SANDBOXED one serves every body as it is. Its environment is sandboxed
 *    as a whole, so an operator-written prompt and everything it includes run
 *    under the {@see PromptSandbox}.
 *
 * The cache key hashes the source, so a changed override compiles once under a
 * new key rather than serving a stale compiled template. It also names the
 * loader's side, so the two environments never share a compiled template.
 */
final class PromptTwigLoader implements LoaderInterface
{
    /** The Twig function the stub calls; {@see PromptRenderer} registers it. */
    public const SANDBOX_FUNCTION = 'semitexa_sandboxed_prompt';

    /**
     * What the trusted loader serves in place of an operator-written body.
     * `_self` is the template's own name — the prompt id — so the stub carries
     * no text of the operator's and none of the id's either.
     */
    public const SANDBOX_STUB = '{{ ' . self::SANDBOX_FUNCTION . '(_self) }}';

    /** @var array<string, string> sources rendered directly, by the name {@see inline()} gave them */
    private array $inline = [];

    public function __construct(
        private readonly PromptRepositoryInterface $repository,
        private readonly bool $sandboxed = false,
    ) {}

    /**
     * Register a source that is rendered directly rather than looked up by id
     * (the prompt being rendered, a few-shot message) and return the template
     * name to load it by. The name carries a hash of the source, so the same
     * label with another body is another template.
     *
     * An operator-written source belongs in the sandboxed loader: this method
     * does not decide trust, the loader it is called on does.
     */
    public function inline(string $label, string $source): string
    {
        $name = sprintf('%s (string template %s)', $label, hash('xxh128', $source));
        $this->inline[$name] = $source;

        return $name;
    }

    public function getSourceContext(string $name): Source
    {
        return new Source($this->body($name) ?? throw new LoaderError(sprintf('Prompt "%s" is not in the catalog.', $name)), $name);
    }

    public function getCacheKey(string $name): string
    {
        $side = $this->sandboxed ? 'sandboxed-' : '';
        if (isset($this->inline[$name])) {
            // The name already carries the hash of its source.
            return $side . 'inline:' . $name;
        }

        // Hash the source into the key so an override recompiles under a new key.
        return $side . 'prompt:' . $name . ':' . hash('xxh128', $this->body($name) ?? '');
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

    /** The body this loader serves under $name, or null when there is none. */
    private function body(string $name): ?string
    {
        if (isset($this->inline[$name])) {
            return $this->inline[$name];
        }

        $template = $this->repository->tryGet($name);
        if ($template === null) {
            return null;
        }

        return $template->operatorWritten && !$this->sandboxed ? self::SANDBOX_STUB : $template->system;
    }
}
