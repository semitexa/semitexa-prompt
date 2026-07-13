# semitexa/prompt

A **prompt catalog** for the Semitexa Framework — an "ORM for prompts".

LLM prompt text tends to sprawl: system prompts hard-coded as heredocs at every
call site, few-shot examples buried inline, no way to see what was actually
sent. This package gives prompts the same treatment the framework gives skills
and translations: **discoverable, addressable, composable records**.

## What it gives you

- **Models** — a `PromptTemplate` is the durable, id-keyed record of a prompt.
- **A repository** — `PromptRegistry` discovers every `#[AsPrompt]` class and
  exposes the catalog behind `PromptRepositoryInterface`.
- **Relationships** — templates compose via partial includes (`{{> other.id }}`)
  and bind runtime data via variables (`{{ name }}`).
- **Observability** — `prompt:list` / `prompt:show` / `prompt:render` let you
  inspect exactly what an LLM would receive, before you send it.

The package is **provider-agnostic**: it renders text and role-tagged messages,
never a wire format. A downstream adapter (e.g. in `semitexa/llm`) maps a
`RenderedPrompt` onto whatever the provider needs.

## Defining a prompt

```php
use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

#[AsPrompt(id: 'os.planner', channel: 'os', description: 'OS planner system prompt')]
final class OsPlannerPrompt implements PromptDefinitionInterface
{
    public function system(): string
    {
        return <<<'PROMPT'
        {{> core.identity }}

        Plan the task for {{ user }}. Today is {{ date }}.
        PROMPT;
    }
}
```

Ship few-shot examples by also implementing `FewShotProviderInterface`.

## Rendering

```php
$renderer = new PromptRenderer();

$rendered = $renderer->render('os.planner', [
    'assistant_name' => 'Semi',   // consumed by the {{> core.identity }} partial
    'user' => 'Taras',
    'date' => '2026-07-13',
]);

$rendered->system;    // fully-resolved system text
$rendered->messages;  // few-shot messages, variables bound
```

Rendering is **fail-closed**: an unbound variable, an unknown partial, or a
partial cycle raises `PromptRenderException` rather than sending a
half-substituted prompt.

## CLI

```
prompt:list [--channel=os] [--json]
prompt:show --id=os.planner [--json]
prompt:render --id=os.planner --var=user=Taras --var=date=2026-07-13 [--json]
```

## Roadmap

Phase 1 (this package): catalog + composition + render/inspect tooling.
Phase 2: migrate the framework's inline system prompts onto the catalog.
Phase 3 (optional): a DB-backed, per-tenant override layer modeled on
`semitexa/locale` — live-editable prompts with catalog fallback and versioning.

## License

MIT — part of the Semitexa Ultimate distribution.
