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

            if ($ref->isAbstract()) {
                return null;
            }

            /** @var AsPrompt $attr */
            $attr = $attrs[0]->newInstance();

            $instance = $ref->newInstance();

            // Body: the Twig template file (resources/prompts/ in the class's
            // package — named by AsPrompt::$template, else the {id}.twig
            // convention) is canonical; a class implementing the legacy
            // PromptDefinitionInterface::system() is the fallback during migration.
            $system = $this->loadTemplateFile($ref, $attr->template ?? ($attr->id . '.twig'));
            if ($system === null) {
                if (!$instance instanceof PromptDefinitionInterface) {
                    $this->logger?->warning('#[AsPrompt] class has no resources/prompts template and no system()', [
                        'class' => $class,
                        'id' => $attr->id,
                    ]);
                    return null;
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
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to build prompt catalog entry', [
                'class' => $class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** @var array<string, string|null> memoized package roots by directory */
    private static array $packageRoots = [];

    /**
     * The Twig body for a prompt, from `resources/prompts/{id}.twig` in the
     * package that owns the #[AsPrompt] class. Null when there is no such file.
     */
    private function loadTemplateFile(ReflectionClass $ref, string $templateFile): ?string
    {
        $file = $ref->getFileName();
        if ($file === false) {
            return null;
        }

        $root = $this->packageRootOf(\dirname($file));
        if ($root === null) {
            return null;
        }

        $path = $root . '/resources/prompts/' . $templateFile;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** Walk up from $dir to the nearest directory containing composer.json. */
    private function packageRootOf(string $dir): ?string
    {
        if (array_key_exists($dir, self::$packageRoots)) {
            return self::$packageRoots[$dir];
        }

        $current = $dir;
        while (true) {
            if (is_file($current . '/composer.json')) {
                return self::$packageRoots[$dir] = $current;
            }
            $parent = \dirname($current);
            if ($parent === $current) {
                return self::$packageRoots[$dir] = null;
            }
            $current = $parent;
        }
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ??= new ClassDiscovery();
    }
}
