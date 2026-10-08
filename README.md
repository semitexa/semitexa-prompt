# semitexa/prompt

A **prompt catalog** for the Semitexa Framework — an "ORM for prompts".

LLM prompt text tends to sprawl: system prompts hard-coded as heredocs at every
call site, few-shot examples buried inline, no way to see what was actually
sent. This package gives prompts the same treatment the framework gives skills
and translations: **discoverable, addressable, composable records**.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

## What it gives you

- **Models** — a `PromptTemplate` is the durable, id-keyed record of a prompt.
- **A repository** — `PromptRegistry` discovers every `#[AsPrompt]` class and
  exposes the catalog behind `PromptRepositoryInterface`.
- **Relationships** — prompt bodies are Twig templates: they compose with
  `{{ include('other.id') }}` and bind runtime data via variables (`{{ name }}`).
- **Per-tenant overrides** — `LayeredPromptRepository` resolves a tenant's
  DB override (`prompt_override`, versioned in `prompt_override_history`) before
  the code catalog; `prompt_guidance` adds operator guidance where a template
  prints `{{ guidance }}`.
- **Observability** — `prompt:list` / `prompt:show` / `prompt:render` let you
  inspect exactly what an LLM would receive, before you send it.

The package is **provider-agnostic**: it renders text and role-tagged messages,
never a wire format. A downstream adapter (e.g. in `semitexa/llm`) maps a
`RenderedPrompt` onto whatever the provider needs.

## Defining a prompt

The class carries metadata; the body is a Twig file under the owning package's
or module's `resources/prompts/` (named by `template:`, or `{id}.twig` by default).

```php
use Semitexa\Prompt\Attribute\AsPrompt;

#[AsPrompt(
    id: 'os.planner',
    channel: 'os',
    description: 'OS planner system prompt',
    template: 'resources/prompts/os.planner.twig',
)]
final class OsPlannerPrompt
{
}
```

```twig
{# resources/prompts/os.planner.twig #}
{{ include('core.identity') }}

Plan the task for {{ user }}. Today is {{ date }}.
```

Ship few-shot examples by also implementing `FewShotProviderInterface`. A class
can instead return its body from `PromptDefinitionInterface::system()`; the
registry uses that only when no template file exists.

## Rendering

```php
use Semitexa\Prompt\Application\Prompt\SemitexaIdentityPrompt;

$rendered = $renderer->render('os.planner', [   // PromptRenderer, injected
    'prompt' => new SemitexaIdentityPrompt('Solomiia'), // read by the core.identity include
    'user' => 'Taras',
    'date' => '2026-07-13',
]);

$rendered->system;    // fully-resolved system text
$rendered->messages;  // few-shot messages, variables bound
```

A prompt class that implements `BoundPromptInterface` can be passed to
`render()` directly; its template reads it as `prompt` (for example
`{{ prompt.assistantName }}` in `core.identity`).

Rendering is **fail-closed**: an unbound variable, an unknown include, or a
broken template raises `PromptRenderException` rather than sending a
half-substituted prompt.

## CLI

```
prompt:list [--channel=os] [--json]
prompt:show --id=os.planner [--json]
prompt:render --id=os.planner --var=user=Taras --var=date=2026-07-13 [--json]
prompt:override set|list|remove|history|revert [--id=] [--system=] [--rev=]
prompt:guidance add|list|disable|enable|remove [--id=] [--text=] [--row=]
```

Docs: https://semitexa.com/docs/prompt

## License

MIT — part of the Semitexa Ultimate distribution.
