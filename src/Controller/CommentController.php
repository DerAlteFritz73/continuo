<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Repository\CommentRepository;
use App\Service\CommentNotifier;
use App\Service\DeployInfo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CommentController extends AbstractController
{
    /** Max comments accepted from a single IP within the window below. */
    private const RATE_LIMIT_MAX = 5;
    private const RATE_LIMIT_WINDOW = '-1 hour';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommentRepository      $comments,
        private readonly ValidatorInterface     $validator,
        private readonly CommentNotifier        $notifier,
        private readonly DeployInfo             $deployInfo,
        private readonly TranslatorInterface    $translator,
    ) {}

    #[Route('/comment', name: 'app_comment_post', methods: ['POST'])]
    public function post(Request $request): JsonResponse
    {
        // Honeypot: a hidden field only a bot fills in. Pretend it worked.
        if (trim((string) $request->request->get('website', '')) !== '') {
            return $this->json(['success' => true, 'message' => $this->trans('comment.success')]);
        }

        $comment = new Comment();
        $comment->setEmail((string) $request->request->get('email', ''));
        $comment->setBody((string) $request->request->get('body', ''));
        $comment->setLocale($request->getLocale());
        $comment->setIpAddress($request->getClientIp());
        // Ties the report to the exact build the visitor was looking at.
        $comment->setAppVersion($this->deployInfo->getVersion());

        $violations = $this->validator->validate($comment);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $field = $violation->getPropertyPath() ?: '_';
                $errors[$field] ??= $this->trans((string) $violation->getMessage());
            }

            return $this->json(['success' => false, 'errors' => $errors], 422);
        }

        $since = new \DateTimeImmutable(self::RATE_LIMIT_WINDOW);
        if ($this->comments->countRecentFromIp($comment->getIpAddress(), $since) >= self::RATE_LIMIT_MAX) {
            return $this->json([
                'success' => false,
                'errors'  => ['_' => $this->trans('comment.error.rate_limited')],
            ], 429);
        }

        $this->em->persist($comment);
        $this->em->flush();

        // The comment is safely stored; a mail failure must not fail the request.
        $error = $this->notifier->notify($comment);
        $comment->setNotified($error === null);
        $comment->setNotifyError($error);
        $this->em->flush();

        return $this->json([
            'success'  => true,
            'notified' => $error === null,
            'message'  => $this->trans('comment.success'),
        ]);
    }

    /**
     * Validation messages are translation keys; anything else passes through.
     */
    private function trans(string $key): string
    {
        return $this->translator->trans($key);
    }
}
