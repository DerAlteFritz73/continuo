<?php

namespace App\Command;

use App\Service\ImslpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:imslp:sync-composer-dates', description: 'Fetch birth/death years for composers from IMSLP')]
class ImslpSyncComposerDatesCommand extends Command
{
    public function __construct(private readonly ImslpService $imslp)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay between requests in ms', 200)
             ->addOption('stop-file', null, InputOption::VALUE_REQUIRED,
                'Path to a stop-file; process exits gracefully when the file appears', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delay    = max(0, (int) $input->getOption('delay'));
        $stopFile = (string) $input->getOption('stop-file');
        $total    = $this->imslp->countTotalComposers();
        $missing  = $this->imslp->countComposersWithoutDates();
        $offset   = $total - $missing;

        $output->writeln(sprintf('[%s] %d/%d composers checked. %d remaining.',
            $this->ts(), $offset, $total, $missing));

        if ($missing === 0) {
            $output->writeln(sprintf('[%s] Nothing to do.', $this->ts()));
            return Command::SUCCESS;
        }

        $done = $this->imslp->syncComposerDates(
            function (int $d, int $runTotal, string $name, ?int $born, ?int $died) use ($output, $offset, $total, $stopFile) {
                $dates = ($born || $died)
                    ? sprintf(' → %s–%s', $born ?? '?', $died ?? '')
                    : ' → not found';
                $output->writeln(sprintf('[%s] [%d/%d] %s%s', $this->ts(), $offset + $d, $total, $name, $dates));

                if ($stopFile && file_exists($stopFile)) {
                    @unlink($stopFile);
                    $output->writeln(sprintf('[%s] Stopped via stop-file.', $this->ts()));
                    return false;
                }

                return true;
            },
            $delay
        );

        $output->writeln(sprintf('[%s] Done. Updated dates for %d composers.', $this->ts(), $done));

        return Command::SUCCESS;
    }

    private function ts(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
    }
}
