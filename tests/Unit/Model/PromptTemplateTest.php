<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Domain\Model\PromptMessage;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

final class PromptTemplateTest extends TestCase
{
    public function testVariableNamesAreExtractedFromSystemAndFewShot(): void
    {
        $template = new PromptTemplate(
            id: 't',
            system: 'Hello {{ name }}, today is {{date}}.',
            fewShot: [PromptMessage::user('Ask about {{ topic }}')],
        );

        $names = $template->variableNames();
        sort($names);

        self::assertSame(['date', 'name', 'topic'], $names);
    }

    public function testPartialTokensAreNotCountedAsVariables(): void
    {
        $template = new PromptTemplate(
            id: 't',
            system: '{{> core.identity }} then {{ tail }}',
        );

        self::assertSame(['tail'], $template->variableNames());
        self::assertSame(['core.identity'], $template->partialIds());
    }

    public function testDuplicateTokensAreDeduped(): void
    {
        $template = new PromptTemplate(id: 't', system: '{{ a }} {{ a }} {{ b }}');

        $names = $template->variableNames();
        sort($names);

        self::assertSame(['a', 'b'], $names);
    }
}
