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

/**
 * Resolves a {@see PromptTemplate} into a {@see RenderedPrompt}: splices partial
 * includes (`{{> id }}`), binds variables (`{{ name }}`), and expands few-shot
 * messages the same way.
 *
 * `#[AsService]` so downstream code (e.g. the semitexa-llm adapter) can inject
 * it. Stateless: partials/by-id lookups resolve against a repository passed per
 * call, or the default one.
 *
 * Default repository: when the container builds this service, the bound
 * {@see PromptRepositoryInterface} is injected — in a full app that is the
 * {@see LayeredPromptRepository} (DB override → catalog), so `render($id)` is
 * override-aware for the current tenant. When instantiated with `new` (CLI,
 * tests, or a consumer that resolves its own template), the property is left
 * uninitialised and falls back to a plain {@see PromptRegistry} catalog — no ORM
 * dependency is touched on that path.
 */
#[AsService]
final class PromptRenderer
{
    private const MAX_PARTIAL_DEPTH = 16;

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
     * Render an explicit template. Partial includes are resolved against
     * $repository (defaults to the discovered catalog).
     *
     * @param array<string, string> $variables
     */
    public function renderTemplate(PromptTemplate $template, array $variables = [], ?PromptRepositoryInterface $repository = null): RenderedPrompt
    {
        $repository ??= $this->repository();

        $used = [];

        $expanded = $this->expandPartials($template->system, $repository, [$template->id]);
        $system = $this->bindVariables($expanded, $variables, $template->id, $used);

        $messages = [];
        foreach ($template->fewShot as $message) {
            $content = $this->bindVariables($message->content, $variables, $template->id, $used);
            $messages[] = $message->withContent($content);
        }

        $boundVariables = [];
        foreach ($used as $name => $_) {
            $boundVariables[$name] = $variables[$name];
        }

        return new RenderedPrompt(
            promptId: $template->id,
            system: $system,
            messages: $messages,
            variables: $boundVariables,
        );
    }

    /**
     * Render a raw template string that is not in the catalog (partials still
     * resolve against $repository).
     *
     * @param array<string, string> $variables
     */
    public function renderString(string $template, array $variables = [], ?PromptRepositoryInterface $repository = null): string
    {
        $repository ??= $this->repository();
        $used = [];

        $expanded = $this->expandPartials($template, $repository, ['(inline)']);

        return $this->bindVariables($expanded, $variables, '(inline)', $used);
    }

    /**
     * @param list<string> $stack ids on the current expansion path, for cycle detection
     */
    private function expandPartials(string $text, PromptRepositoryInterface $repository, array $stack): string
    {
        if (count($stack) > self::MAX_PARTIAL_DEPTH) {
            throw PromptRenderException::partialCycle($stack[0], $stack);
        }

        return (string) preg_replace_callback(
            '/\{\{>\s*([a-zA-Z0-9_.\-]+)\s*\}\}/',
            function (array $match) use ($repository, $stack): string {
                $partialId = $match[1];

                if (in_array($partialId, $stack, true)) {
                    throw PromptRenderException::partialCycle($stack[0], [...$stack, $partialId]);
                }

                $partial = $repository->tryGet($partialId);
                if ($partial === null) {
                    throw PromptRenderException::unknownPartial($stack[0], $partialId);
                }

                return $this->expandPartials($partial->system, $repository, [...$stack, $partialId]);
            },
            $text,
        );
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, true>   $used receives the names actually substituted
     */
    private function bindVariables(string $text, array $variables, string $promptId, array &$used): string
    {
        $missing = [];

        $result = (string) preg_replace_callback(
            '/\{\{\s*(?!>)([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $match) use ($variables, &$missing, &$used): string {
                $name = $match[1];
                if (!array_key_exists($name, $variables)) {
                    $missing[$name] = true;
                    return $match[0];
                }
                $used[$name] = true;

                return $variables[$name];
            },
            $text,
        );

        if ($missing !== []) {
            throw PromptRenderException::missingVariables($promptId, array_keys($missing));
        }

        return $result;
    }

    private function repository(): PromptRepositoryInterface
    {
        // `??` yields null for the uninitialised injected property on the `new`
        // path, so this lazily falls back to the plain code catalog there.
        return $this->repository ??= new PromptRegistry();
    }
}
