<?php
namespace App\Command;

use App\Service\MusicXmlParser;
use App\Service\ContinuoRealizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:debug:measure3')]
class DebugMeasure3Command extends Command
{
    public function __construct(
        private readonly MusicXmlParser $parser,
        private readonly ContinuoRealizer $realizer,
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $score = $this->parser->parse(file_get_contents('/var/www/continuo/public/sample/aria_bassline.xml'));
        $score = $this->realizer->realize($score);

        // Dump ALL raw MIDI values before display
        foreach ([$score->measures[0], $score->measures[1], $score->measures[2]] as $mi => $mx) {
            foreach ($mx->realizedChords as $bi => $c) {
                $output->writeln(sprintf('[RAW] M%d b%d  bass=%s(%d)  upper=%s',
                    $mi+1, $bi+1,
                    $c->bass->step.$c->bass->octave, $c->bass->midiPitch(),
                    implode(', ', array_map(fn($n)=>$n->step.$n->alter.$n->octave.'='.$n->midiPitch().'(pc='.($n->midiPitch()%12).')', $c->upperVoices))
                ));
            }
        }

        for ($mi = 0; $mi < 4; $mi++) {
            $m = $score->measures[$mi];
            $output->writeln('=== Measure ' . ($mi + 1) . ' ===');
            foreach ($m->realizedChords as $idx => $chord) {
                $b    = $chord->bass;
                $line = sprintf('  beat%d  bass=%-6s(m%2d)', $idx + 1, $b->step . $b->octave, $b->midiPitch());
                foreach (['T', 'A', 'S'] as $vi => $lbl) {
                    $n = $chord->upperVoices[$vi] ?? null;
                    if ($n) $line .= sprintf('  %s=%-6s(m%2d)', $lbl, $n->step . $n->octave, $n->midiPitch());
                }
                $output->writeln($line);
            }
        }

        $output->writeln('');
        $output->writeln('=== Parallel octave check measures 1–4 ===');

        $allChords = [];
        for ($mi = 0; $mi < 4; $mi++) {
            foreach ($score->measures[$mi]->realizedChords as $c) {
                $allChords[] = ['m' => $mi + 1, 'c' => $c];
            }
        }

        $labels = ['T', 'A', 'S', 'B'];
        for ($i = 1; $i < count($allChords); $i++) {
            $prev = $allChords[$i - 1]['c'];
            $curr = $allChords[$i]['c'];
            $pAll = array_merge(array_map(fn($n) => $n->midiPitch(), $prev->upperVoices), [$prev->bass->midiPitch()]);
            $cAll = array_merge(array_map(fn($n) => $n->midiPitch(), $curr->upperVoices), [$curr->bass->midiPitch()]);
            $n2   = min(count($pAll), count($cAll));
            for ($a = 0; $a < $n2; $a++) {
                for ($b = $a + 1; $b < $n2; $b++) {
                    $pi    = abs($pAll[$a] - $pAll[$b]) % 12;
                    $ci    = abs($cAll[$a] - $cAll[$b]) % 12;
                    $moved = ($pAll[$a] !== $cAll[$a]) || ($pAll[$b] !== $cAll[$b]);
                    if ($moved && $pi === 0 && $ci === 0) {
                        $output->writeln(sprintf(
                            '  M%d→M%d beat%d: || OCTAVE  %s(%d→%d)  &  %s(%d→%d)',
                            $allChords[$i - 1]['m'], $allChords[$i]['m'], $i,
                            $labels[$a], $pAll[$a], $cAll[$a],
                            $labels[$b], $pAll[$b], $cAll[$b]
                        ));
                    }
                }
            }
        }

        return Command::SUCCESS;
    }
}
