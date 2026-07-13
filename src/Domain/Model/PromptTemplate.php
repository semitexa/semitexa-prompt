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
     * Variable names referenced by this template's system text and few-shot
     * content (partial-include tokens excluded). Derived by scanning tokens, so
     * a caller can validate a values map before rendering.
     *
     * @return list<string>
     */
    public function variableNames(): array
    {
        $sources = [$this->system];
        foreach ($this->fewShot as $message) {
            $sources[] = $message->content;
        }

        $names = [];
        foreach ($sources as $text) {
            preg_match_all('/\{\{\s*(?!>)([a-zA-Z0-9_.]+)\s*\}\}/', $text, $matches);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
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
