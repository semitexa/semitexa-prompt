<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;
use Semitexa\Prompt\Domain\Contract\PromptGuidanceProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Exception\PromptRenderException;
use Semitexa\Prompt\Domain\Model\PromptTemplate;
use Semitexa\Prompt\Domain\Model\RenderedPrompt;
use Throwable;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Sandbox\SecurityError;
use Twig\TwigFunction;

/**
 * Renders a {@see PromptTemplate} into a {@see RenderedPrompt} with Twig: the
 * system text and each few-shot message are Twig templates, so prompts get
 * variables (`{{ name }}`), conditionals/loops (`{% if %}`, `{% for %}`) and
 * composition (`{{ include('other.id') }}`) natively.
 *
 * Twig config chosen for prompts (NOT HTML): autoescape OFF (a prompt is plain
 * text — escaping would corrupt quotes/`<`/`&` in JSON examples), strict
 * variables ON (an unbound variable fails closed, as before), and a compile
 * cache so each source compiles once. Rendered output is then run through a
 * SAFE normalisation (strip trailing spaces, collapse 3+ blank lines to 2, trim
 * edges) — token-trimming that never touches meaningful structure.
 *
 * A body an operator wrote (a DB override) is not code and is not trusted like
 * code: it renders in a separate Twig environment that is sandboxed as a whole
 * (see {@see PromptSandbox}), which keeps it away from PHP callables and from
 * objects other than the bound prompt. An override the
 * sandbox refuses is NOT replaced by the shipped prompt: the render fails with
 * a {@see PromptRenderException} that names the refused construct, and the
 * override stays in the store as written, for its author to fix or revert.
 *
 * Default repository: when the container builds this service, the bound
 * {@see PromptRepositoryInterface} is injected — the {@see LayeredPromptRepository}
 * (DB override → catalog) in a full app, so `render($id)` and every
 * `{{ include('id') }}` are override-aware for the current tenant. Instantiated with
 * `new` (CLI, tests, own-template consumers) it falls back to a plain
 * {@see PromptRegistry} catalog.
 */
#[AsService]
final class PromptRenderer
{
    /**
     * The variable a prompt prints to admit operator guidance.
     *
     * Always bound — empty string when the tenant has said nothing — so a
     * template declaring `{{ guidance }}` renders unchanged under
     * `strict_variables` for everyone else. A template that does NOT print it
     * never shows guidance at all.
     *
     * Placement decides WHERE operator text lands, not what it is allowed to do:
     * the whole prompt reaches the same model, so this is a structuring
     * convention, not an enforcement boundary. See
     * {@see PromptGuidanceProviderInterface} for what that does and does not
     * buy you.
     */
    public const GUIDANCE_VARIABLE = PromptGuidanceProviderInterface::CONTEXT_VARIABLE;

    #[InjectAsReadonly]
    protected PromptRepositoryInterface $repository;

    #[InjectAsReadonly]
    protected PromptGuidanceProviderInterface $guidance;

    /**
     * Test/CLI seam — production path uses property injection.
     *
     * It exists because `prompt:render` builds its renderer with `new` and yet
     * promises "the exact text the LLM would receive". Once guidance is part of
     * that text, a previewer without a provider quietly shows a DIFFERENT prompt
     * from the one that ships — the one failure this command cannot be allowed.
     */
    public function withGuidance(PromptGuidanceProviderInterface $guidance): self
    {
        $this->guidance = $guidance;

        return $this;
    }

    /**
     * Render a catalog prompt — either by id with a variables array, or by
     * passing a self-binding {@see BoundPromptInterface}. A bound prompt is
     * exposed to its template as a single object under
     * {@see BoundPromptInterface::CONTEXT_VARIABLE} (`prompt`), so the template
     * reads its typed data through getters: `{{ prompt.assistantName }}`. Any
     * explicit $variables still wins (an explicit `prompt` key would replace the
     * object; other keys sit alongside it).
     *
     * @param array<string, mixed> $variables
     */
    public function render(string|BoundPromptInterface $prompt, array $variables = [], ?PromptRepositoryInterface $repository = null): RenderedPrompt
    {
        if ($prompt instanceof BoundPromptInterface) {
            $variables += [BoundPromptInterface::CONTEXT_VARIABLE => $prompt];

            // A bound prompt knows its own class, so its template is resolved
            // directly and deterministically (no discovery) — unless a repository
            // is given, in which case it wins (override-aware resolution).
            $template = $repository !== null
                ? $repository->get($prompt->promptId())
                : ((new PromptRegistry())->buildFromClasses([$prompt::class])[$prompt->promptId()]
                    ?? throw PromptNotFoundException::forId($prompt->promptId()));

            return $this->renderTemplate($template, $variables, $repository);
        }

        $repository ??= $this->repository();

        return $this->renderTemplate($repository->get($prompt), $variables, $repository);
    }

    /**
     * Render an explicit template. `{% include %}` tags resolve against
     * $repository (defaults to the discovered catalog).
     *
     * @param array<string, mixed> $variables
     */
    public function renderTemplate(PromptTemplate $template, array $variables = [], ?PromptRepositoryInterface $repository = null): RenderedPrompt
    {
        $repository ??= $this->repository();
        // Not `+=`: that evaluates the right-hand side first, so a caller who
        // already bound guidance — the documented scoped-guidance seam — would
        // still pay for the store round-trip whose result is then discarded.
        if (!array_key_exists(self::GUIDANCE_VARIABLE, $variables)) {
            $variables[self::GUIDANCE_VARIABLE] = $this->guidanceFor($template->id);
        }
        $twig = $this->twig($repository);

        // The body an operator wrote goes to the sandboxed environment and to
        // no other; the shipped one renders where it always did.
        $system = $template->operatorWritten
            ? $this->renderSource($twig->sandboxed, $template->system, $template->id, $variables)
            : $this->renderSource($twig->trusted, $template->system, $template->id, $variables);

        $messages = [];
        foreach ($template->fewShot as $i => $message) {
            // Few-shot messages come from the prompt's class, never from an
            // override, which carries the system text only.
            $content = $this->renderSource($twig->trusted, $message->content, $template->id . ':fewshot:' . $i, $variables);
            $messages[] = $message->withContent($content);
        }

        // The bound `prompt` object is a render-time handle, not a bound value —
        // keep it out of RenderedPrompt so toArray() stays serializable (the
        // object's typed data is reachable through its own getters, not here).
        $boundValues = $variables;
        unset($boundValues[BoundPromptInterface::CONTEXT_VARIABLE]);

        return new RenderedPrompt(
            promptId: $template->id,
            system: $system,
            messages: $messages,
            variables: $boundValues,
        );
    }

    /**
     * Render a raw source string that is not in the catalog (`{% include %}`
     * tags still resolve against $repository).
     *
     * $source is trusted like code and is NOT sandboxed (an included
     * operator-written prompt still is): never pass it text an operator or a
     * user wrote — give that a {@see PromptTemplate} marked `operatorWritten`.
     *
     * @param array<string, mixed> $variables
     */
    public function renderString(string $source, array $variables = [], ?PromptRepositoryInterface $repository = null): string
    {
        $repository ??= $this->repository();

        return $this->renderSource($this->twig($repository)->trusted, $source, '(inline)', $variables);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderSource(Environment $twig, string $source, string $promptId, array $variables): string
    {
        try {
            $loader = $twig->getLoader();
            \assert($loader instanceof PromptTwigLoader);
            $rendered = $twig->load($loader->inline($promptId, $source))->render($variables);
        } catch (LoaderError $e) {
            throw PromptRenderException::unknownPartial($promptId, $e->getMessage());
        } catch (Throwable $e) {
            if (self::isSandboxRefusal($e)) {
                throw PromptRenderException::overrideRefused($promptId, $e->getMessage(), $e);
            }

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

    /**
     * The two Twig environments of one render.
     *
     * `trusted` is the one prompts have always rendered in, with nothing added
     * but the function behind {@see PromptTwigLoader::SANDBOX_STUB}. `sandboxed`
     * has the {@see PromptSandbox} switched on for everything it runs: the
     * operator-written body it is given, and every prompt that body includes,
     * shipped ones too. They share no loader and no compiled template: the cache
     * directory and the cache keys both differ.
     *
     * A shipped prompt that includes an overridden one stays trusted itself:
     * its loader serves the stub for that id, and the stub renders the override
     * in the sandboxed environment with the variables the include passed on.
     *
     * @return object{trusted: Environment, sandboxed: Environment}
     */
    private function twig(PromptRepositoryInterface $repository): object
    {
        $sandboxed = $this->environment(new PromptTwigLoader($repository, sandboxed: true), 'sandboxed');
        $sandboxed->addExtension((new PromptSandbox())->extension());

        $trusted = $this->environment(new PromptTwigLoader($repository), '');
        $trusted->addFunction(new TwigFunction(
            PromptTwigLoader::SANDBOX_FUNCTION,
            /** @param array<string, mixed> $context */
            static fn(array $context, string $promptId): string => $sandboxed->load($promptId)->render($context),
            ['needs_context' => true],
        ));

        return new readonly class($trusted, $sandboxed) {
            public function __construct(public Environment $trusted, public Environment $sandboxed) {}
        };
    }

    private function environment(PromptTwigLoader $loader, string $cacheSubdirectory): Environment
    {
        return new Environment($loader, [
            'autoescape' => false,
            'strict_variables' => true,
            'cache' => $this->cacheDir($cacheSubdirectory),
        ]);
    }

    /**
     * Twig reports a refused construct as a SecurityError, and a callable name
     * handed to `map` / `filter` / `sort` / `reduce` as a RuntimeError naming
     * sandbox mode; a refusal inside an included prompt arrives wrapped.
     */
    private static function isSandboxRefusal(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof SecurityError) {
                return true;
            }
            if ($cause instanceof RuntimeError && str_contains($cause->getMessage(), 'in sandbox mode')) {
                return true;
            }
        }

        return false;
    }

    private function cacheDir(string $subdirectory = ''): string
    {
        // Owner-only (0o700): the compiled .php cache embeds fully-resolved prompt
        // bodies, including tenant-specific overrides. On a shared temp dir a
        // group/world-readable cache would leak that text to other local users.
        // All workers run as the same user, so owner-only keeps the shared cache.
        $dir = sys_get_temp_dir() . '/semitexa-prompt-twig' . ($subdirectory === '' ? '' : '/' . $subdirectory);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o700, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
    }

    private function repository(): PromptRepositoryInterface
    {
        // `??` yields null for the uninitialised injected property on the `new`
        // path, so this lazily falls back to the plain code catalog there.
        return $this->repository ??= new PromptRegistry();
    }

    /**
     * The guidance text for a prompt, or '' when there is none.
     *
     * Bound as a VALUE, never re-parsed: Twig substitutes a variable's contents
     * without compiling them, so `{{ }}` or `{% %}` inside guidance written by
     * an operator — or relayed from a chat room — is inert text. That is the
     * property that makes it safe to let other people's words into a prompt at
     * all, so it is pinned by a test rather than left to be rediscovered.
     *
     * Falls back to building the store, the same shape {@see repository()} uses
     * — and for a sharper reason. Every production consumer of this class builds
     * it with `new`: OsPersona, Planner, Weaver, SkillLoopRunner, SeoWriter,
     * ConversationSummarizer. None is container-managed (PersonaRegistry, for
     * one, does `new $class()`), so an injection-only lookup would have left the
     * feature working in `prompt:render` and inert in every place a prompt is
     * actually sent — an operator recording guidance, seeing it listed, seeing
     * it previewed, and watching the assistant ignore every word of it.
     *
     * The repository fallback can choose the code catalog because a code catalog
     * exists. Guidance has no code counterpart: the choice here is the store or
     * nothing. The store itself degrades to '' when there is no table to read.
     */
    private function guidanceFor(string $promptId): string
    {
        try {
            return $this->guidance()->textFor($promptId);
        } catch (Throwable) {
            // The store already degrades on a read failure; this covers a
            // provider that does not, and the build itself. An additive layer
            // must never be the reason a prompt stops rendering.
            return '';
        }
    }

    private function guidance(): PromptGuidanceProviderInterface
    {
        // `??` yields null for the uninitialised injected property on the `new`
        // path, exactly as it does for the repository above.
        return $this->guidance ??= new PromptGuidanceStore();
    }
}
