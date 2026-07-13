<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * A reusable identity fragment — not a standalone prompt to send, but a partial
 * to compose into others via `{{ include('core.identity') }}`.
 *
 * Thin definition: metadata only. The body lives in
 * `resources/prompts/core.identity.twig` (variable `{{ assistant_name }}`).
 */
#[AsPrompt(
    id: 'core.identity',
    channel: 'partial',
    description: "Reusable assistant-identity fragment; compose with {{ include('core.identity') }}.",
)]
final class SemitexaIdentityPrompt {}
