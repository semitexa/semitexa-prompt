<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Model;

/**
 * A catalog entry: the *unrendered* definition of a prompt.
 *
 * This is the "model" in the prompt-ORM analogy — the durable, addressable
 * record of a prompt, keyed by {@see $id}. It holds a raw system template that
 * may contain two kinds of tokens:
 *
 *   - variables:  `{{ name }}`        — bound at render time from a values map
 *   - partials:   `{{> other.id }}`   — the system text of another catalog
 *                                        entry, spliced in (composition)
 *
 * Few-shot examples travel with the template and are variable-substituted the
 * same way at render time.
 *
 * Immutable. Rendering never mutates a template — it produces a
 * {@see RenderedPrompt}.
 */
final readonly class PromptTemplate
{
    /**
     * @param list<PromptMessage>   $fewShot  few-shot examples folded into the rendered message list
     * @param array<string, string> $metadata free-form annotations (owner, model hint, ...)
     */
    public function __construct(
        public string $id,
        public string $system,
        public string $channel = 'default',
        public string $description = '',
        public array $fewShot = [],
        public array $metadata = [],
    ) {}

    /**
     * A copy with the system text replaced, keeping id, channel, description,
     * few-shot and metadata. Used by the DB override layer to overlay a
     * tenant-edited body onto the catalog default without losing its other
     * attributes.
     */
    public function withSystem(string $system): self
    {
        return new self(
            id: $this->id,
            system: $system,
            channel: $this->channel,
            description: $this->description,
            fewShot: $this->fewShot,
            metadata: $this->metadata,
        );
    }

    /**
     * The top-level context variables this template (system + few-shot) reads —
     * the values a caller must bind before rendering.
     *
     * Computed from the Twig AST, not a regex: it correctly handles filters
     * (`{{ x|upper }}`), conditionals (`{% if x %}`) and loops (`{% for f in
     * items %}` yields `items`, not the loop-local `f`). Falls back to an empty
     * list if a source is unparseable.
     *
     * @return list<string>
     */
    public function variableNames(): array
    {
        $names = [];
        foreach ([$this->system, ...array_map(static fn(PromptMessage $m): string => $m->content, $this->fewShot)] as $source) {
            foreach (self::analyzeVariables($source) as $name) {
                $names[$name] = true;
            }
        }

        $out = array_keys($names);
        sort($out);

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function analyzeVariables(string $source): array
    {
        static $env = null;
        static $collector = null;

        if ($env === null) {
            // A NodeVisitor is invoked on EVERY node during parse, so it reliably
            // separates read references (ContextVariable) from loop/set targets
            // (AssignContextVariable) — which manual traversal misses.
            $collector = new class implements \Twig\NodeVisitor\NodeVisitorInterface {
                /** @var array<string, true> */
                public array $refs = [];
                /** @var array<string, true> */
                public array $locals = ['loop' => true];

                public function reset(): void
                {
                    $this->refs = [];
                    $this->locals = ['loop' => true];
                }

                public function enterNode(\Twig\Node\Node $node, \Twig\Environment $env): \Twig\Node\Node
                {
                    if ($node instanceof \Twig\Node\Expression\Variable\AssignContextVariable) {
                        $this->locals[(string) $node->getAttribute('name')] = true;
                    } elseif ($node instanceof \Twig\Node\Expression\Variable\ContextVariable) {
                        $this->refs[(string) $node->getAttribute('name')] = true;
                    }

                    return $node;
                }

                public function leaveNode(\Twig\Node\Node $node, \Twig\Environment $env): ?\Twig\Node\Node
                {
                    return $node;
                }

                public function getPriority(): int
                {
                    return 0;
                }
            };
            $env = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
            $env->addNodeVisitor($collector);
        }

        $collector->reset();
        try {
            $env->parse($env->tokenize(new \Twig\Source($source, 'prompt')));
        } catch (\Throwable) {
            return [];
        }

        // Referenced minus locals; drop Twig-internal `_`-prefixed loop variables.
        $vars = array_diff(array_keys($collector->refs), array_keys($collector->locals));

        return array_values(array_filter($vars, static fn(string $v): bool => $v === '' || $v[0] !== '_'));
    }

    /**
     * Composed prompt ids referenced by this template's Twig source via the
     * `{{ include('id') }}` function form (the print form is used rather than
     * the `{% include %}` tag because block tags trim the trailing newline).
     *
     * @return list<string>
     */
    public function partialIds(): array
    {
        preg_match_all('/include\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/', $this->system, $matches);

        return array_values(array_unique($matches[1]));
    }
}
