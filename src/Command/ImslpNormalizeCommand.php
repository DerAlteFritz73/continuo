<?php

namespace App\Command;

use App\Service\ImslpService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:imslp:normalize',
    description: 'Backfill normalised columns and tables (editions, categories, duration, firstperf, composer_id) from existing imslp_work data',
)]
class ImslpNormalizeCommand extends Command
{
    private const CHUNK = 200;

    public function __construct(
        private readonly ImslpService $imslp,
        private readonly Connection   $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'Maximum number of works to process (0 = all)', 0)
             ->addOption('delay', null, InputOption::VALUE_REQUIRED,
                'Milliseconds to sleep between iterations (0 = none)', 0)
             ->addOption('stop-file', null, InputOption::VALUE_REQUIRED,
                'Path to a stop-file; exits gracefully when the file appears', '')
             ->addOption('mode', null, InputOption::VALUE_REQUIRED,
                'What to backfill: editions|categories|duration|firstperf|composer_id|instrumentation|all', 'all')
             ->addOption('force', null, InputOption::VALUE_NONE,
                'Instrumentation mode: re-parse ALL rows with instrumentation text, overwriting existing '
                . 'part_count/voice_registers (use after changing InstrumentationParser)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit    = (int) $input->getOption('limit');
        $delay    = (int) $input->getOption('delay');
        $stopFile = (string) $input->getOption('stop-file');
        $mode     = strtolower((string) $input->getOption('mode'));
        $force    = (bool) $input->getOption('force');

        $validModes = ['editions', 'categories', 'duration', 'firstperf', 'composer_id', 'instrumentation', 'all'];
        if (!in_array($mode, $validModes, true)) {
            $output->writeln('<error>Invalid mode. Use: ' . implode('|', $validModes) . '</error>');
            return Command::FAILURE;
        }

        if ($mode === 'all' || $mode === 'composer_id') {
            $this->runComposerId($output);
            if ($mode === 'composer_id') return Command::SUCCESS;
        }

        $done = 0;

        if ($mode === 'all' || $mode === 'editions') {
            $done += $this->runEditions($output, $limit, $delay, $stopFile, $done);
        }

        if ($mode === 'all' || $mode === 'categories') {
            $done += $this->runCategories($output, $limit, $delay, $stopFile, $done);
        }

        if ($mode === 'all' || $mode === 'duration') {
            $done += $this->runDuration($output, $limit, $delay, $stopFile, $done);
        }

        if ($mode === 'all' || $mode === 'firstperf') {
            $done += $this->runFirstPerf($output, $limit, $delay, $stopFile, $done);
        }

        if ($mode === 'all' || $mode === 'instrumentation') {
            $done += $this->runInstrumentation($output, $limit, $delay, $stopFile, $done, $force);
        }

        $output->writeln(sprintf('[%s] normalize complete. Total records processed: %d', $this->ts(), $done));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function runComposerId(OutputInterface $output): void
    {
        $output->writeln(sprintf('[%s] composer_id: bulk UPDATE via JOIN ...', $this->ts()));
        $affected = $this->db->executeStatement(
            'UPDATE imslp_work w JOIN imslp_composer c ON c.name = w.composer
             SET w.composer_id = c.id
             WHERE w.composer_id IS NULL'
        );
        $output->writeln(sprintf('[%s] composer_id: %d rows updated.', $this->ts(), $affected));
    }

    private function runEditions(OutputInterface $output, int $limit, int $delay, string $stopFile, int $globalDone): int
    {
        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM imslp_work w
             WHERE w.files_json IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM imslp_edition e WHERE e.work_id = w.id)'
        );
        $output->writeln(sprintf('[%s] editions: %d works to process.', $this->ts(), $count));
        $toProcess = ($limit > 0) ? min($limit - $globalDone, $count) : $count;

        $done   = 0;
        $offset = 0;

        while ($done < $toProcess) {
            if ($stopFile && file_exists($stopFile)) {
                @unlink($stopFile);
                $output->writeln(sprintf('[%s] editions: stopped via stop-file.', $this->ts()));
                break;
            }

            $chunkSize = min(self::CHUNK, $toProcess - $done);
            $rows      = $this->db->fetchAllAssociative(
                sprintf(
                    'SELECT id, page_id, files_json FROM imslp_work w
                     WHERE w.files_json IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM imslp_edition e WHERE e.work_id = w.id)
                     ORDER BY w.id
                     LIMIT %d OFFSET %d',
                    $chunkSize, $offset
                )
            );
            if (empty($rows)) break;

            foreach ($rows as $row) {
                $editions = json_decode($row['files_json'], true) ?? [];
                if (!empty($editions)) {
                    $this->imslp->upsertEditions((int) $row['page_id'], $editions);
                }
                $done++;
                if ($done % 500 === 0) {
                    $output->writeln(sprintf('[%s] editions: %d / %d processed.', $this->ts(), $done, $toProcess));
                }
            }

            // offset not needed since we exclude already-processed rows
            if ($delay > 0) usleep($delay * 1000);
        }

        $output->writeln(sprintf('[%s] editions: done, %d works processed.', $this->ts(), $done));
        return $done;
    }

    private function runCategories(OutputInterface $output, int $limit, int $delay, string $stopFile, int $globalDone): int
    {
        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM imslp_work w
             WHERE w.genre_cats IS NOT NULL AND w.genre_cats != \'\'
               AND NOT EXISTS (SELECT 1 FROM imslp_work_category wc WHERE wc.work_id = w.id)'
        );
        $output->writeln(sprintf('[%s] categories: %d works to process.', $this->ts(), $count));
        $toProcess = ($limit > 0) ? min($limit - $globalDone, $count) : $count;

        $done = 0;

        while ($done < $toProcess) {
            if ($stopFile && file_exists($stopFile)) {
                @unlink($stopFile);
                $output->writeln(sprintf('[%s] categories: stopped via stop-file.', $this->ts()));
                break;
            }

            $chunkSize = min(self::CHUNK, $toProcess - $done);
            $rows      = $this->db->fetchAllAssociative(
                sprintf(
                    'SELECT id, page_id, genre_cats FROM imslp_work w
                     WHERE w.genre_cats IS NOT NULL AND w.genre_cats != \'\'
                       AND NOT EXISTS (SELECT 1 FROM imslp_work_category wc WHERE wc.work_id = w.id)
                     ORDER BY w.id
                     LIMIT %d',
                    $chunkSize
                )
            );
            if (empty($rows)) break;

            foreach ($rows as $row) {
                $cats = array_filter(array_map('trim', explode(' ; ', $row['genre_cats'])));
                if (!empty($cats)) {
                    $this->imslp->upsertWorkCategories((int) $row['page_id'], array_values($cats));
                }
                $done++;
                if ($done % 500 === 0) {
                    $output->writeln(sprintf('[%s] categories: %d / %d processed.', $this->ts(), $done, $toProcess));
                }
            }

            if ($delay > 0) usleep($delay * 1000);
        }

        $output->writeln(sprintf('[%s] categories: done, %d works processed.', $this->ts(), $done));
        return $done;
    }

    private function runDuration(OutputInterface $output, int $limit, int $delay, string $stopFile, int $globalDone): int
    {
        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM imslp_work WHERE average_duration IS NOT NULL AND duration_seconds IS NULL'
        );
        $output->writeln(sprintf('[%s] duration: %d works to process.', $this->ts(), $count));
        $toProcess = ($limit > 0) ? min($limit - $globalDone, $count) : $count;

        $done   = 0;
        $offset = 0;

        while ($done < $toProcess) {
            if ($stopFile && file_exists($stopFile)) {
                @unlink($stopFile);
                $output->writeln(sprintf('[%s] duration: stopped via stop-file.', $this->ts()));
                break;
            }

            $chunkSize = min(self::CHUNK, $toProcess - $done);
            $rows      = $this->db->fetchAllAssociative(
                sprintf(
                    'SELECT id, average_duration FROM imslp_work
                     WHERE average_duration IS NOT NULL AND duration_seconds IS NULL
                     ORDER BY id
                     LIMIT %d OFFSET %d',
                    $chunkSize, $offset
                )
            );
            if (empty($rows)) break;

            foreach ($rows as $row) {
                $secs = $this->imslp->parseDurationSeconds($row['average_duration']);
                $this->db->executeStatement(
                    'UPDATE imslp_work SET duration_seconds = ? WHERE id = ?',
                    [$secs, (int) $row['id']]
                );
                $done++;
            }
            $offset += count($rows);

            if ($done % 500 === 0) {
                $output->writeln(sprintf('[%s] duration: %d / %d processed.', $this->ts(), $done, $toProcess));
            }
            if ($delay > 0) usleep($delay * 1000);
        }

        $output->writeln(sprintf('[%s] duration: done, %d works processed.', $this->ts(), $done));
        return $done;
    }

    private function runFirstPerf(OutputInterface $output, int $limit, int $delay, string $stopFile, int $globalDone): int
    {
        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM imslp_work WHERE first_performance IS NOT NULL AND first_perf_date IS NULL'
        );
        $output->writeln(sprintf('[%s] firstperf: %d works to process.', $this->ts(), $count));
        $toProcess = ($limit > 0) ? min($limit - $globalDone, $count) : $count;

        $done   = 0;
        $offset = 0;

        while ($done < $toProcess) {
            if ($stopFile && file_exists($stopFile)) {
                @unlink($stopFile);
                $output->writeln(sprintf('[%s] firstperf: stopped via stop-file.', $this->ts()));
                break;
            }

            $chunkSize = min(self::CHUNK, $toProcess - $done);
            $rows      = $this->db->fetchAllAssociative(
                sprintf(
                    'SELECT id, first_performance FROM imslp_work
                     WHERE first_performance IS NOT NULL AND first_perf_date IS NULL
                     ORDER BY id
                     LIMIT %d OFFSET %d',
                    $chunkSize, $offset
                )
            );
            if (empty($rows)) break;

            foreach ($rows as $row) {
                [$date, $location] = $this->imslp->parseFirstPerformance($row['first_performance']);
                $this->db->executeStatement(
                    'UPDATE imslp_work SET first_perf_date = ?, first_perf_location = ? WHERE id = ?',
                    [
                        $date !== null ? mb_substr($date, 0, 50) : null,
                        $location !== null ? mb_substr($location, 0, 255) : null,
                        (int) $row['id'],
                    ]
                );
                $done++;
            }
            $offset += count($rows);

            if ($done % 500 === 0) {
                $output->writeln(sprintf('[%s] firstperf: %d / %d processed.', $this->ts(), $done, $toProcess));
            }
            if ($delay > 0) usleep($delay * 1000);
        }

        $output->writeln(sprintf('[%s] firstperf: done, %d works processed.', $this->ts(), $done));
        return $done;
    }

    private function runInstrumentation(OutputInterface $output, int $limit, int $delay, string $stopFile, int $globalDone, bool $force = false): int
    {
        // Rows with instrumentation text but no parsed ensemble descriptor yet.
        // Works whose text yields nothing (e.g. "lute") stay NULL and will be
        // re-scanned on a later run — the parse is cheap and network-free.
        //
        // --force re-parses EVERY row with instrumentation text, overwriting any
        // existing descriptor — needed after changing InstrumentationParser so the
        // new logic reaches rows the old parser already stamped.
        $where = $force
            ? "instrumentation IS NOT NULL AND instrumentation <> ''"
            : "instrumentation IS NOT NULL AND instrumentation <> ''
                  AND part_count_min IS NULL AND part_count_max IS NULL AND voice_registers IS NULL";

        $count = (int) $this->db->fetchOne("SELECT COUNT(*) FROM imslp_work WHERE $where");
        $output->writeln(sprintf('[%s] instrumentation: %d works to process.', $this->ts(), $count));
        $toProcess = ($limit > 0) ? min($limit - $globalDone, $count) : $count;

        $done   = 0;
        $offset = 0;

        while ($done < $toProcess) {
            if ($stopFile && file_exists($stopFile)) {
                @unlink($stopFile);
                $output->writeln(sprintf('[%s] instrumentation: stopped via stop-file.', $this->ts()));
                break;
            }

            $chunkSize = min(self::CHUNK, $toProcess - $done);
            $rows      = $this->db->fetchAllAssociative(
                sprintf(
                    "SELECT id, instrumentation FROM imslp_work
                     WHERE $where
                     ORDER BY id
                     LIMIT %d OFFSET %d",
                    $chunkSize, $offset
                )
            );
            if (empty($rows)) break;

            foreach ($rows as $row) {
                $e = $this->imslp->parseInstrumentation($row['instrumentation']);
                $this->db->executeStatement(
                    'UPDATE imslp_work SET part_count_min = ?, part_count_max = ?, voice_registers = ? WHERE id = ?',
                    [$e['part_count_min'], $e['part_count_max'], $e['voice_registers'], (int) $row['id']]
                );
                $done++;
            }
            // Rows that parse to all-NULL still match $where, so advance OFFSET
            // to walk past them rather than re-selecting the same first chunk.
            $offset += count($rows);

            if ($done % 500 === 0) {
                $output->writeln(sprintf('[%s] instrumentation: %d / %d processed.', $this->ts(), $done, $toProcess));
            }
            if ($delay > 0) usleep($delay * 1000);
        }

        $output->writeln(sprintf('[%s] instrumentation: done, %d works processed.', $this->ts(), $done));
        return $done;
    }

    // -------------------------------------------------------------------------

    private function ts(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
    }
}
