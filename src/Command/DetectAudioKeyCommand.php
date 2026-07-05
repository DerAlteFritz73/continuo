<?php

namespace App\Command;

use App\Service\AudioKeyDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Detect tonality/mode from an audio file using the loudness-based-chromagram
 * detector (Ni et al. 2012). Prints a global key plus a local-key timeline, or
 * a single JSON object with --json for batch tagging of an audio library.
 */
#[AsCommand(
    name: 'app:detect-audio-key',
    description: 'Detect key/mode from an audio file (loudness-based chromagram + Krumhansl-Schmuckler)',
)]
class DetectAudioKeyCommand extends Command
{
    /** Sharp-spelled pitch-class names, index = pitch class (C = 0). */
    private const PC_NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    public function __construct(private readonly AudioKeyDetector $detector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to an audio file (wav/mp3/flac/...)')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Local-key window length in seconds', '4.0')
            ->addOption('overlap', null, InputOption::VALUE_REQUIRED, 'Window overlap fraction 0..1', '0.5')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON object (for scripting/batch tagging)')
            ->addOption('no-timeline', null, InputOption::VALUE_NONE, 'Print only the global key, not the timeline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        try {
            $result = $this->detector->detect(
                $file,
                (float) $input->getOption('window'),
                (float) $input->getOption('overlap'),
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $g = $result['global'];
        $io->title(sprintf('Detected key: %s', $this->keyName($g)));
        $io->writeln(sprintf(
            ' duration %.1fs   correlation %.3f   confidence %s',
            $result['duration'], $g['correlation'], $g['confidence']
        ));

        if (!$input->getOption('no-timeline') && $result['timeline'] !== []) {
            $io->section('Local-key timeline');
            $rows = [];
            foreach ($result['timeline'] as $seg) {
                $k = $seg['key'];
                $rows[] = [
                    sprintf('%5.1f–%-5.1f', $seg['start'], $seg['end']),
                    $this->keyName($k),
                    sprintf('%.3f', $k['correlation']),
                    $k['confidence'],
                ];
            }
            $io->table(['time (s)', 'key', 'corr', 'conf'], $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * Render a "C major" / "A minor" style label from an estimator result.
     *
     * @param array{tonicPc:int, mode:string} $key
     */
    private function keyName(array $key): string
    {
        return sprintf('%s %s', self::PC_NAMES[$key['tonicPc']], $key['mode']);
    }
}
