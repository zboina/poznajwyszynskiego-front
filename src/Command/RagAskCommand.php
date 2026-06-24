<?php

namespace App\Command;

use App\Service\RagAnswerer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:rag-ask', description: 'Zadaj pytanie do korpusu (RAG, test CLI)')]
class RagAskCommand extends Command
{
    public function __construct(private RagAnswerer $rag)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'Pytanie')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Ogranicz do tomu (id)')
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Liczba fragmentów', '8');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $q = $input->getArgument('question');
        $vol = $input->getOption('volume');

        $io->section('Pytanie');
        $io->writeln($q);

        $t0 = microtime(true);
        $r = $this->rag->answer($q, (int) $input->getOption('k'), $vol ? (int) $vol : null);
        $dt = microtime(true) - $t0;

        $io->section('Odpowiedź');
        $io->writeln($r['answer']);

        $io->section('Źródła');
        foreach ($r['citations'] as $c) {
            $io->writeln(sprintf('[%d] %s — %s (doc %d)', $c['n'], $c['label'], $c['title'], $c['document_id']));
        }
        $io->newLine();
        $io->comment(sprintf('Czas: %.1fs', $dt));

        return Command::SUCCESS;
    }
}
