<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptRenderException;
use Semitexa\Prompt\Domain\Model\PromptMessage;
use Semitexa\Prompt\Domain\Model\PromptTemplate;
use Semitexa\Prompt\Domain\Model\RenderedPrompt;
use Throwable;
use Twig\Environment;
use Twig\Error\LoaderError;

/**
 * Renders a {@see PromptTemplate} into a {@see RenderedPrompt} with Twig: the
 * system text and each few-shot message are Twig templates, so prompts get
 * variables (`{{ name }}`), conditionals/loops (`{% if %}`, `{% for %}`) and
 * composition (`{% include 'other.id' %}`) natively.
 *
 * Twig config chosen for prompts (NOT HTML): autoescape OFF (a prompt is plain
 * text — escaping would corrupt quotes/`<`/`&` in JSON examples), strict
 * variables ON (an unbound variable fails closed, as before), and a compile
 * cache so each source compiles once. Rendered output is then run through a
 * SAFE normalisation (strip trailing spaces, collapse 3+ blank lines to 2, trim
 * edges) — token-trimming that never touches meaningful structure.
 *
 * Default repository: when the container builds this service, the bound
 * {@see PromptRepositoryInterface} is injected — the {@see LayeredPromptRepository}
 * (DB override → catalog) in a full app, so `render($id)` and every
 * `{% include %}` are override-aware for the current tenant. Instantiated with
 * `new` (CLI, tests, own-template consumers) it falls back to a plain
 * {@see PromptRegistry} catalog.
 */
#[AsService]
final class PromptRenderer
{
    #[InjectAsReadonly]
    protected PromptRepositoryInterface $repository;

    /**
     * Render a catalog prompt by id.
     *
     * @param array<string, string> $variables
     */
    public function render(string $id, array $variables = [], ?PromptRepositoryInterface $repository = null): RenderedPrompt
    {
        $repository ??= $this->repository();

        return $this->renderTemplate($repository->get($id), $variables, $repository);
    }

    /**
     * Render an explicit template. `{% include %}` tags resolve against
     * $repository (defaults to the discovered catalog).
     *
     * @param array<string, string> $variables
     */
    public function renderTemplate(PromptTemplate $template, array $variables = [], ?PromptRepositoryInterface $repository = null): RenderedPrompt
    {
        $repository ??= $this->repository();
        $twig = $this->twig($repository);

        $system = $this->renderSource($twig, $template->system, $template->id, $variables);

        $messages = [];
        foreach ($template->fewShot as $i => $message) {
            $content = $this->renderSource($twig, $message->content, $template->id . ':fewshot:' . $i, $variables);
            $messages[] = $message->withContent($content);
        }

        return new RenderedPrompt(
            promptId: $template->id,
            system: $system,
            messages: $messages,
            variables: $variables,
        );
    }

    /**
     * Render a raw source string that is not in the catalog (`{% include %}`
     * tags still resolve against $repository).
     *
     * @param array<string, string> $variables
     */
    public function renderString(string $source, array $variables = [], ?PromptRepositoryInterface $repository = null): string
    {
        $repository ??= $this->repository();

        return $this->renderSource($this->twig($repository), $source, '(inline)', $variables);
    }

    /**
     * @param array<string, string> $variables
     */
    private function renderSource(Environment $twig, string $source, string $promptId, array $variables): string
    {
        try {
            $rendered = $twig->createTemplate($source, $promptId)->render($variables);
        } catch (LoaderError $e) {
            throw PromptRenderException::unknownPartial($promptId, $e->getMessage());
        } catch (Throwable $e) {
            throw new PromptRenderException(sprintf('Cannot render prompt "%s": %s', $promptId, $e->getMessage()), 0, $e);
        }

        return $this->normalize($rendered);
    }

    /**
     * Safe, structure-preserving normalisation: strip per-line trailing
     * whitespace, collapse 3+ consecutive blank lines to at most 2, and trim the
     * edges. Deliberately conservative — an LLM keys on prompt structure, so
     * meaningful newlines and indentation are kept.
     */
    private function normalize(string $text): string
    {
        /** @var list<string> $lines */
        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_map(static fn(string $line): string => rtrim($line), $lines);
        $joined = implode("\n", $lines);
        $joined = (string) preg_replace('/\n{3,}/', "\n\n", $joined);

        return trim($joined);
    }

    private function twig(PromptRepositoryInterface $repository): Environment
    {
        return new Environment(new PromptTwigLoader($repository), [
            'autoescape' => false,
            'strict_variables' => true,
            'cache' => $this->cacheDir(),
        ]);
    }

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/semitexa-prompt-twig';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
    }

    private function repository(): PromptRepositoryInterface
    {
        // `??` yields null for the uninitialised injected property on the `new`
        // path, so this lazily falls back to the plain code catalog there.
        return $this->repository ??= new PromptRegistry();
    }
}
