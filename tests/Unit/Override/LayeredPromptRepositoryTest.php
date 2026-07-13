<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Override;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\LayeredPromptRepository;
use Semitexa\Prompt\Domain\Contract\PromptOverrideProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

final class LayeredPromptRepositoryTest extends TestCase
{
    /**
     * @param array<string, PromptTemplate> $templates
     */
    private function catalog(array $templates): PromptRepositoryInterface
    {
        return new class($templates) implements PromptRepositoryInterface {
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
    }

    /**
     * @param array<string, string> $map
     */
    private function overrides(array $map): PromptOverrideProviderInterface
    {
        return new class($map) implements PromptOverrideProviderInterface {
            /** @param array<string, string> $map */
            public function __construct(private array $map) {}

            public function override(string $promptId): ?string
            {
                return $this->map[$promptId] ?? null;
            }

            public function all(): array
            {
                return $this->map;
            }
        };
    }

    private function layered(array $templates, array $overrides): LayeredPromptRepository
    {
        return (new LayeredPromptRepository())
            ->withCatalog($this->catalog($templates))
            ->withOverrides($this->overrides($overrides));
    }

    public function testOverrideWinsAndPreservesCatalogChannel(): void
    {
        $repo = $this->layered(
            ['a' => new PromptTemplate(id: 'a', system: 'CATALOG A', channel: 'os', description: 'd')],
            ['a' => 'OVERRIDE A'],
        );

        $t = $repo->get('a');
        self::assertSame('OVERRIDE A', $t->system);
        self::assertSame('os', $t->channel);
        self::assertSame('d', $t->description);
    }

    public function testCatalogUsedWhenNoOverride(): void
    {
        $repo = $this->layered(['a' => new PromptTemplate(id: 'a', system: 'CATALOG A')], []);

        self::assertSame('CATALOG A', $repo->get('a')->system);
    }

    public function testOverrideOnlyPromptWithNoCatalogEntry(): void
    {
        $repo = $this->layered([], ['c' => 'OVERRIDE C']);

        $t = $repo->get('c');
        self::assertSame('OVERRIDE C', $t->system);
        self::assertSame('c', $t->id);
    }

    public function testUnknownIdThrows(): void
    {
        $repo = $this->layered(['a' => new PromptTemplate(id: 'a', system: 'A')], []);

        $this->expectException(PromptNotFoundException::class);
        $repo->get('missing');
    }

    public function testHasReflectsBothLayers(): void
    {
        $repo = $this->layered(['a' => new PromptTemplate(id: 'a', system: 'A')], ['c' => 'C']);

        self::assertTrue($repo->has('a'));   // catalog only
        self::assertTrue($repo->has('c'));   // override only
        self::assertFalse($repo->has('z'));
    }

    public function testAllMergesCatalogAndOverrides(): void
    {
        $repo = $this->layered(
            [
                'a' => new PromptTemplate(id: 'a', system: 'CATALOG A', channel: 'os'),
                'b' => new PromptTemplate(id: 'b', system: 'CATALOG B'),
            ],
            ['a' => 'OVERRIDE A', 'c' => 'OVERRIDE C'],
        );

        $all = $repo->all();
        $byId = [];
        foreach ($all as $t) {
            $byId[$t->id] = $t;
        }

        self::assertSame(['a', 'b', 'c'], array_keys($byId));
        self::assertSame('OVERRIDE A', $byId['a']->system);
        self::assertSame('os', $byId['a']->channel);       // channel preserved through override
        self::assertSame('CATALOG B', $byId['b']->system); // untouched
        self::assertSame('OVERRIDE C', $byId['c']->system); // override-only
    }

    public function testWithoutOverridesBehavesLikePlainCatalog(): void
    {
        $repo = (new LayeredPromptRepository())
            ->withCatalog($this->catalog(['a' => new PromptTemplate(id: 'a', system: 'A')]));

        self::assertSame('A', $repo->get('a')->system);
        self::assertFalse($repo->has('c'));
    }
}
