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
        return $this->catalog()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->catalog()[$id]);
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
     * @param list<class-string> $classes
     * @return array<string, PromptTemplate>
     */
    public function buildFromClasses(array $classes): array
    {
        $catalog = [];
        $owners = [];
        foreach ($classes as $class) {
            $template = $this->buildTemplate($class);
            if ($template === null) {
                continue;
            }
            if (isset($catalog[$template->id])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate prompt id "%s" declared by %s and %s.',
                    $template->id,
                    $owners[$template->id],
                    $class,
                ));
            }
            $catalog[$template->id] = $template;
            $owners[$template->id] = $class;
        }

        return $catalog;
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

        return $this->catalog = $this->buildFromClasses($classes);
    }

    private function buildTemplate(string $class): ?PromptTemplate
    {
        try {
            $ref = new ReflectionClass($class);

            $attrs = $ref->getAttributes(AsPrompt::class);
            if ($attrs === []) {
                return null;
            }

            if ($ref->isAbstract() || !$ref->implementsInterface(PromptDefinitionInterface::class)) {
                // A class marked #[AsPrompt] that can't be used as one is a bug,
                // not a silent no-op: warn so the misdeclaration is diagnosable.
                $this->logger?->warning('Ignoring #[AsPrompt] class that is abstract or does not implement PromptDefinitionInterface', [
                    'class' => $class,
                ]);
                return null;
            }

            /** @var AsPrompt $attr */
            $attr = $attrs[0]->newInstance();

            /** @var PromptDefinitionInterface $instance */
            $instance = $ref->newInstance();

            $fewShot = $instance instanceof FewShotProviderInterface ? $instance->fewShot() : [];

            return new PromptTemplate(
                id: $attr->id,
                system: $instance->system(),
                channel: $attr->channel,
                description: $attr->description ?? '',
                fewShot: array_values($fewShot),
                metadata: ['class' => $class],
            );
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to build prompt catalog entry', [
                'class' => $class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ??= new ClassDiscovery();
    }
}
