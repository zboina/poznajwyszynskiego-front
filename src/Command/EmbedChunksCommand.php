<?php

namespace App\Command;

use App\Service\Embedder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uzupełnia wektory PolDense (document_chunks.embedding_pd) we fragmentach,
 * które ich nie mają. Można przerwać i uruchomić ponownie — liczy tylko brakujące.
 * Treść fragmentów i dokumentów nie jest zmieniana.
 */
#[AsCommand(name: 'app:embed-chunks', description: 'Uzupełnij wektory PolDense we fragmentach (embedding_pd)')]
class EmbedChunksCommand extends Command
{
    public function __construct(private Embedder $embedder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('doc', null, InputOption::VALUE_REQUIRED, 'Tylko te dokumenty (id po przecinku)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $docs = $input->getOption('doc') ? array_map('intval', explode(',', $input->getOption('doc'))) : null;
        $t0 = microtime(true);
        $last = 0.0;
        $n = $this->embedder->fillPoldense($docs, function (int $done, int $total) use ($io, $t0, &$last) {
            if (microtime(true) - $last < 30 && $done < $total) {
                return;
            }
            $last = microtime(true);
            $rate = $done / max(1, $last - $t0);
            $io->writeln(sprintf('%s  %d / %d  (%.1f/s, zostało ok. %d min)',
                date('H:i:s'), $done, $total, $rate, ($total - $done) / max(0.01, $rate) / 60));
        });
        $io->success(sprintf('Uzupełniono %d fragmentów w %.1f min.', $n, (microtime(true) - $t0) / 60));
        return Command::SUCCESS;
    }
}
