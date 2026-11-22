<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Sandbox;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Application\Service\PromptSandbox;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Model\PromptTemplate;
use Throwable;

/**
 * The prompt editor opens on the shipped body, so an operator's first override
 * is the shipped body with a word changed. The sandbox must therefore allow
 * everything a shipped prompt does: every prompt the installed packages ship is
 * rendered here twice — as shipped, and as an operator-written copy of itself —
 * and the two must be the same text.
 *
 * The catalog is the discovered one, so the test covers whatever is installed:
 * this package's own prompt when it is installed alone, every package's in the
 * workspace.
 */
final class ShippedPromptsRenderSandboxedTest extends TestCase
{
    public function testEveryShippedPromptRendersTheSameAsAnOperatorWrittenCopy(): void
    {
        $registry = new PromptRegistry();
        $shipped = $registry->all();
        self::assertNotSame([], $shipped, 'No shipped prompt was discovered.');
        self::assertTrue($registry->has('core.identity'));

        $operatorCopy = new class($registry) implements PromptRepositoryInterface {
            public function __construct(private PromptRegistry $registry) {}

            public function get(string $id): PromptTemplate
            {
                $template = $this->registry->get($id);

                return $template->withSystem($template->system);
            }

            public function tryGet(string $id): ?PromptTemplate
            {
                $template = $this->registry->tryGet($id);

                return $template?->withSystem($template->system);
            }

            public function has(string $id): bool
            {
                return $this->registry->has($id);
            }

            public function all(): array
            {
                return array_map(static fn(PromptTemplate $t): PromptTemplate => $t->withSystem($t->system), $this->registry->all());
            }
        };

        $renderer = new PromptRenderer();
        $sandbox = new PromptSandbox();
        $rendered = 0;
        foreach ($shipped as $template) {
            self::assertFalse($template->operatorWritten, $template->id);
            self::assertTrue($operatorCopy->get($template->id)->operatorWritten, $template->id);

            $variables = $this->variablesFor($template, $sandbox);

            try {
                $expected = $renderer->render($template->id, $variables, $registry);
            } catch (Throwable $e) {
                self::fail(sprintf('Shipped prompt "%s" does not render with its defaults: %s', $template->id, $e->getMessage()));
            }
            $actual = $renderer->render($template->id, $variables, $operatorCopy);

            self::assertSame($expected->system, $actual->system, $template->id);
            self::assertEquals($expected->messages, $actual->messages, $template->id);
            self::assertNotSame('', $actual->system, $template->id);
            $rendered++;
        }

        self::assertSame(count($shipped), $rendered);
    }

    /**
     * The data a shipped prompt renders with: its own class, built the way the
     * catalog builds it (no arguments), for a self-binding prompt; a placeholder
     * for every variable otherwise.
     *
     * A getter the template reads inside a branch the defaults do not take is
     * not exercised by the render, so each one is put to the sandbox directly.
     *
     * @return array<string, mixed>
     */
    private function variablesFor(PromptTemplate $template, PromptSandbox $sandbox): array
    {
        $class = $template->metadata['class'] ?? null;
        $bound = $class !== null && is_a($class, BoundPromptInterface::class, true) ? new $class() : null;

        $variables = ['guidance' => ''];
        foreach ($template->variableNames() as $name) {
            $getter = $bound === null ? null : $this->getter($bound, $name);
            if ($bound !== null && $getter !== null) {
                $sandbox->checkMethodAllowed($bound, $getter);
                continue;
            }
            $variables[$name] = 'value-of-' . $name;
        }
        if ($bound !== null) {
            $variables[BoundPromptInterface::CONTEXT_VARIABLE] = $bound;
        }

        return $variables;
    }

    private function getter(object $bound, string $name): ?string
    {
        foreach ([$name, 'get' . $name, 'is' . $name, 'has' . $name] as $method) {
            if (method_exists($bound, $method)) {
                return $method;
            }
        }

        return null;
    }
}
