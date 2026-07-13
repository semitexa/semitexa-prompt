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

#[AsCommand(name: 'prompt:show', description: 'Show a prompt template: raw system text, variables, partials and few-shot.')]
final class PromptShowCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('prompt:show')
            ->setDescription('Show a prompt template: raw system text, variables, partials and few-shot.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Prompt id to show')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = $input->getOption('id');
        if (!is_string($id) || $id === '') {
            $io->error('Provide a prompt id with --id=<id> (see prompt:list).');

            return Command::INVALID;
        }

        $registry = new PromptRegistry();
        $template = $registry->tryGet($id);
        if ($template === null) {
            $io->error(sprintf('No prompt registered with id "%s".', $id));

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'id' => $template->id,
                'channel' => $template->channel,
                'description' => $template->description,
                'variables' => $template->variableNames(),
                'partials' => $template->partialIds(),
                'system' => $template->system,
                'few_shot' => array_map(static fn($m): array => $m->toArray(), $template->fewShot),
                'metadata' => $template->metadata,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Prompt: %s', $template->id));
        $io->definitionList(
            ['Channel' => $template->channel],
            ['Description' => $template->description !== '' ? $template->description : '—'],
            ['Variables' => $template->variableNames() === [] ? '—' : implode(', ', $template->variableNames())],
            ['Partials' => $template->partialIds() === [] ? '—' : implode(', ', $template->partialIds())],
        );

        $io->section('System template (raw)');
        $io->writeln($template->system);

        if ($template->fewShot !== []) {
            $io->section(sprintf('Few-shot (%d message(s))', count($template->fewShot)));
            foreach ($template->fewShot as $message) {
                $io->writeln(sprintf('<info>[%s]</info> %s', $message->role->value, $message->content));
            }
        }

        return Command::SUCCESS;
    }
}
