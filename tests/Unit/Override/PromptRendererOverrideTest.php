<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Override;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\LayeredPromptRepository;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptOverrideProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

/**
 * The opt-in resolution path: a consumer that renders BY ID through the layered
 * repository gets the tenant override (variables still bind), or the catalog
 * default when there is no override. This is what Weaver now does in production.
 */
final class PromptRendererOverrideTest extends TestCase
{
    /**
     * @param array<string, PromptTemplate> $templates
     * @param array<string, string>         $overrides
     */
    private function layered(array $templates, array $overrides): LayeredPromptRepository
    {
        $catalog = new class($templates) implements PromptRepositoryInterface {
            /** @param array<string, PromptTemplate> $templates */
            public function __construct(private array $templates) {}
            public function get(string $id): PromptTemplate
            {
                return $this->templates[$id] ?? throw PromptNotFoundException::forId($id);
            }
            public function tryGet(string $id): ?PromptTemplate
            {
                return $this->templates[$id] ?? null;
            }
            public function has(string $id): bool
            {
                return isset($this->templates[$id]);
            }
            public function all(): array
            {
                return array_values($this->templates);
            }
        };

        $provider = new class($overrides) implements PromptOverrideProviderInterface {
            /** @param array<string, string> $overrides */
            public function __construct(private array $overrides) {}
            public function override(string $promptId): ?string
            {
                return $this->overrides[$promptId] ?? null;
            }
            public function all(): array
            {
                return $this->overrides;
            }
        };

        return (new LayeredPromptRepository())->withCatalog($catalog)->withOverrides($provider);
    }

    public function testRenderByIdAppliesOverrideThenBindsVariables(): void
    {
        $repo = $this->layered(
            ['p' => new PromptTemplate(id: 'p', system: 'Base {{ x }}')],
            ['p' => 'Overridden {{ x }} text'],
        );

        $rendered = (new PromptRenderer())->render('p', ['x' => '1'], $repo);

        self::assertSame('Overridden 1 text', $rendered->system);
    }

    public function testRenderByIdFallsBackToCatalogWhenNoOverride(): void
    {
        $repo = $this->layered(
            ['p' => new PromptTemplate(id: 'p', system: 'Base {{ x }}')],
            [],
        );

        $rendered = (new PromptRenderer())->render('p', ['x' => '1'], $repo);

        self::assertSame('Base 1', $rendered->system);
    }
}
