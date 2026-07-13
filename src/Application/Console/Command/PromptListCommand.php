<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'prompt:list', description: 'List every registered prompt template in the catalog.')]
final class PromptListCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('prompt:list')
            ->setDescription('List every registered prompt template in the catalog.')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Only show prompts on this channel')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = new PromptRegistry();
        $channel = $input->getOption('channel');

        $templates = $registry->all();
        if (is_string($channel) && $channel !== '') {
            $templates = array_values(array_filter(
                $templates,
                static fn($t): bool => $t->channel === $channel,
            ));
        }

        if ((bool) $input->getOption('json')) {
            $rows = array_map(static fn($t): array => [
                'id' => $t->id,
                'channel' => $t->channel,
                'description' => $t->description,
                'variables' => $t->variableNames(),
                'partials' => $t->partialIds(),
            ], $templates);
            $output->writeln((string) json_encode(['prompts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Prompt Catalog');

        if ($templates === []) {
            $io->warning('No prompts registered. Add #[AsPrompt] to a PromptDefinitionInterface class.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($templates as $t) {
            $vars = $t->variableNames();
            $rows[] = [
                $t->id,
                $t->channel,
                $vars === [] ? '—' : implode(', ', $vars),
                $t->description,
            ];
        }

        $io->table(['Id', 'Channel', 'Variables', 'Description'], $rows);
        $io->text(sprintf('Total: %d prompt(s).', count($templates)));

        return Command::SUCCESS;
    }
}
