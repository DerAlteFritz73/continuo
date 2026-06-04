<?php

namespace App\MessageHandler;

use App\Message\FetchWorkDetailsMessage;
use App\Repository\ImslpWorkRepository;
use App\Service\ImslpService;
use Doctrine\ORM\EntityManagerInterface;

final class FetchWorkDetailsHandler
{
    public function __construct(
        private readonly ImslpService $imslp,
        private readonly ImslpWorkRepository $workRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(FetchWorkDetailsMessage $message): void
    {
        $pageIds = $message->getPageIds();
        if (empty($pageIds)) return;

        $works = $this->workRepo->findByPageIds($pageIds);
        if (empty($works)) return;

        // Fetch work details in parallel batches (10 concurrent requests)
        $results = $this->imslp->fetchWorkDetailBatch($works, 10);

        // Log errors but don't throw — let queue handler retry with backoff
        foreach ($results as [$work, $exception]) {
            if ($exception !== null && !str_contains($exception->getMessage(), 'easy handle')) {
                // Log but continue for other works
                error_log(sprintf('Error fetching %s: %s', $work->getTitle(), $exception->getMessage()));
            }
        }

        // Clear ORM cache to release memory for large batches
        $this->em->clear();
    }
}
