<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\FewShotProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptBodyMissingException;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Model\PromptTemplate;
use Throwable;

/**
 * The code-first prompt catalog. Discovers every `#[AsPrompt]` class and
 * exposes their templates keyed by id — the same reflection-over-attribute
 * idiom `SkillRegistry` uses for skills.
 *
 * Plain, self-sufficient class (not an `#[AsService]`): it is `new`-able from
 * console commands and tests, and {@see PromptRenderer} builds its own instance
 * lazily. The catalog is memoised once per instance; entries are immutable, so
 * a container-singleton renderer sharing one catalog across coroutines is safe.
 */
final class PromptRegistry implements PromptRepositoryInterface
{
    /** @var array<string, PromptTemplate>|null */
    private ?array $catalog = null;

    /** @var array<string, PromptBodyMissingException> bodyless prompts, by id */
    private array $broken = [];

    private ?PromptBodyLocator $bodyLocator = null;

    public function __construct(
        private ?ClassDiscovery $classDiscovery = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function get(string $id): PromptTemplate
    {
        return $this->tryGet($id) ?? throw PromptNotFoundException::forId($id);
    }

    public function tryGet(string $id): ?PromptTemplate
    {
        $catalog = $this->catalog();
        if (isset($this->broken[$id])) {
            // Asked for by name, so the caller genuinely depends on it: this is
            // exactly the case that must not fall back to a fallback.
            throw $this->broken[$id];
        }

        return $catalog[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->catalog()[$id]);
    }

    /**
     * Prompts that declared themselves and have no body, with the failure that
     * says why — the tooling's read on {@see PromptBodyMissingException}.
     *
     * `prompt:list` reports these instead of a shorter, plausible catalog: a
     * prompt missing from a listing looks exactly like a prompt nobody wrote.
     *
     * @return array<string, PromptBodyMissingException>
     */
    public function brokenIds(): array
    {
        $this->catalog();

        return $this->broken;
    }

    /**
     * @return list<PromptTemplate>
     */
    public function all(): array
    {
        $templates = array_values($this->catalog());
        usort($templates, static fn(PromptTemplate $a, PromptTemplate $b): int => strcmp($a->id, $b->id));

        return $templates;
    }

    /**
     * Build a catalog from an explicit class list — the discovery-free seam
     * used by tests. Duplicate ids are a hard error: two classes claiming the
     * same catalog key is a real misconfiguration, not something to paper over.
     *
     * The result becomes THIS instance's catalog. It has to: the quarantine of
     * bodyless prompts is instance state, and a seam that recorded it and then
     * let the next read re-discover over the top would report the real
     * installation's verdict for a hand-built list.
     *
     * @param list<class-string> $classes
     * @return array<string, PromptTemplate>
     */
    public function buildFromClasses(array $classes): array
    {
        $catalog = [];
        $owners = [];
        $this->broken = [];
        $brokenOwners = [];
        foreach ($classes as $class) {
            try {
                $template = $this->buildTemplate($class);
            } catch (PromptBodyMissingException $e) {
                // Quarantined, not fatal. Throwing here would take the WHOLE
                // catalog down on every call — the catalog memo is only assigned
                // on success, so the throw repeats forever — and with it every
                // LLM render in the application plus `prompt:list`, the one tool
                // an operator would reach for to find out why. One misconfigured
                // class must not be able to do that. The failure stays loud where
                // it is somebody's problem: asking for THIS id by name throws,
                // and the tooling reports it (see brokenIds()).
                if (isset($owners[$e->promptId])) {
                    throw self::duplicate($e->promptId, $owners[$e->promptId], $class);
                }
                $this->broken[$e->promptId] = $e;
                $brokenOwners[$e->promptId] = $class;
                continue;
            }
            if ($template === null) {
                continue;
            }
            // Checked against BOTH maps. A quarantined id is still a CLAIMED id:
            // without this, a bodyless class poisoned the id for a healthy class
            // that also declared it, and tryGet() threw over a template sitting
            // right there in the catalog.
            if (isset($catalog[$template->id])) {
                throw self::duplicate($template->id, $owners[$template->id], $class);
            }
            if (isset($brokenOwners[$template->id])) {
                throw self::duplicate($template->id, $brokenOwners[$template->id], $class);
            }
            $catalog[$template->id] = $template;
            $owners[$template->id] = $class;
        }

        return $this->catalog = $catalog;
    }

    private static function duplicate(string $id, string $first, string $second): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'Duplicate prompt id "%s" declared by %s and %s.',
            $id,
            $first,
            $second,
        ));
    }

    /**
     * @return array<string, PromptTemplate>
     */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $classes = $this->classDiscovery()->findClassesWithAttribute(AsPrompt::class);

        return $this->buildFromClasses($classes);
    }

    private function buildTemplate(string $class): ?PromptTemplate
    {
        try {
            $ref = new ReflectionClass($class);

            $attrs = $ref->getAttributes(AsPrompt::class);
            if ($attrs === []) {
                return null;
            }

            if ($ref->isAbstract()) {
                return null;
            }

            /** @var AsPrompt $attr */
            $attr = $attrs[0]->newInstance();

            $instance = $ref->newInstance();

            // Body: the Twig template file (a package-relative path in
            // AsPrompt::$template, else the resources/prompts/{id}.twig
            // convention) is canonical; a class implementing the legacy
            // PromptDefinitionInterface::system() is the fallback during migration.
            $templateFile = $attr->template ?? ('resources/prompts/' . $attr->id . '.twig');
            $system = $this->loadTemplateFile($ref, $templateFile);
            if ($system === null) {
                if (!$instance instanceof PromptDefinitionInterface) {
                    $file = $ref->getFileName();
                    throw PromptBodyMissingException::forClass(
                        $class,
                        $attr->id,
                        $templateFile,
                        $file === false ? [] : $this->bodyLocator()->rootsFor($file),
                    );
                }
                $system = $instance->system();
            }

            $fewShot = $instance instanceof FewShotProviderInterface ? $instance->fewShot() : [];

            return new PromptTemplate(
                id: $attr->id,
                system: $system,
                channel: $attr->channel,
                description: $attr->description ?? '',
                fewShot: array_values($fewShot),
                metadata: ['class' => $class],
            );
        } catch (PromptBodyMissingException $e) {
            // Escapes the catch-all below so buildFromClasses() can quarantine it
            // by id. Swallowing it here is what made a missing body invisible.
            throw $e;
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to build prompt catalog entry', [
                'class' => $class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * The Twig body for a prompt, from an owner-relative $templateFile path
     * (e.g. `resources/prompts/core.identity.twig`) inside the package OR module
     * that owns the #[AsPrompt] class. Null when no owner root holds that file.
     */
    private function loadTemplateFile(ReflectionClass $ref, string $templateFile): ?string
    {
        $file = $ref->getFileName();
        if ($file === false) {
            return null;
        }

        return $this->bodyLocator()->load($file, $templateFile);
    }

    private function bodyLocator(): PromptBodyLocator
    {
        return $this->bodyLocator ??= new PromptBodyLocator();
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ??= new ClassDiscovery();
    }
}
