<?php

namespace App\Command;

use App\Model\Chord;
use App\Service\ContinuoRealizer;
use App\Service\MusicXmlParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Throwaway analysis command: parse a MusicXML file, run our realizer, and dump
 * per-bass-note voicing as JSON to stdout (measure, offset, bass MIDI, figures,
 * our chosen upper-voice MIDIs). Used to compare against an editorial reference.
 */
#[AsCommand(name: 'app:dump-realization', description: 'Dump our realizer output as JSON')]
class DumpRealizationCommand extends Command
{
    public function __construct(
        private readonly MusicXmlParser   $parser,
        private readonly ContinuoRealizer $realizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to MusicXML file');
        $this->addOption('voices', null, InputOption::VALUE_REQUIRED, 'Voice count (3 or 4)', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $xml   = file_get_contents($input->getArgument('file'));
        $score = $this->parser->parse($xml);
        $nv    = (int) $input->getOption('voices');
        $score = $this->realizer->realize($score, $nv);

        $rows = [];
        foreach ($score->measures as $measure) {
            $mnum   = $measure->number ?? null;
            $offset = 0.0;
            foreach ($measure->bassNotes as $i => $bassNote) {
                if ($bassNote->isRest()) {
                    $offset += $bassNote->duration;
                    continue;
                }
                /** @var Chord|null $chord */
                $chord = $measure->realizedChords[$i] ?? null;
                if ($chord === null) {
                    $offset += $bassNote->duration;
                    continue;
                }
                $upper = array_map(fn($n) => $n->midiPitch(), $chord->upperVoices);
                $rows[] = [
                    'measure' => $mnum,
                    'offset'  => round($offset, 4),
                    'bass'    => $bassNote->midiPitch(),
                    'figs'    => array_map(fn($f) => ($f['number'] ?? '?') . (($f['alter'] ?? 0) ? ('@' . $f['alter']) : ''), $chord->figures),
                    'upper'   => array_values($upper),
                    'src'     => $chord->decisionTrace['figuresSource'] ?? '?',
                ];
                $offset += $bassNote->duration;
            }
        }

        $output->writeln(json_encode($rows));
        return Command::SUCCESS;
    }
}
