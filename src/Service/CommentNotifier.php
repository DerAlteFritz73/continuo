<?php

namespace App\Service;

use App\Entity\Comment;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the "new comment" notification to the site owner.
 *
 * The commenter's address is never used as the From: header (most SMTP
 * relays reject a sender they do not own); it goes into Reply-To instead,
 * so hitting "Reply" answers the visitor directly.
 */
class CommentNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string          $notifyTo,
        private readonly string          $mailFrom,
    ) {}

    /**
     * Attempts to send the notification. Never throws: a comment that is
     * already persisted must not be lost because the SMTP relay is down.
     *
     * @return string|null null on success, the transport error otherwise
     */
    public function notify(Comment $comment): ?string
    {
        $email = (new Email())
            ->from(new Address($this->mailFrom, 'Basso Continuo Realizer'))
            ->to($this->notifyTo)
            ->replyTo(new Address($comment->getEmail()))
            ->subject(sprintf('[Continuo] New comment from %s', $comment->getEmail()))
            ->text($this->textBody($comment))
            ->html($this->htmlBody($comment));

        try {
            $this->mailer->send($email);

            return null;
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->logger->error('Comment notification could not be sent', [
                'comment_id' => $comment->getId(),
                'exception'  => $e,
            ]);

            return $e->getMessage();
        }
    }

    private function textBody(Comment $comment): string
    {
        return implode("\n", [
            'New comment on the Basso Continuo Realizer.',
            '',
            'From:    ' . $comment->getEmail(),
            'Date:    ' . $comment->getCreatedAt()->format('Y-m-d H:i:s'),
            'Locale:  ' . ($comment->getLocale() ?? '—'),
            'IP:      ' . ($comment->getIpAddress() ?? '—'),
            'Comment #' . ($comment->getId() ?? '?'),
            '',
            str_repeat('-', 60),
            $comment->getBody(),
            str_repeat('-', 60),
        ]);
    }

    private function htmlBody(Comment $comment): string
    {
        $e = static fn (?string $v): string => htmlspecialchars($v ?? '—', ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<div style="font-family:system-ui,sans-serif;font-size:14px;color:#1c2333">'
            . '<h2 style="font-size:16px;margin:0 0 .8em">New comment on the Basso Continuo Realizer</h2>'
            . '<table cellpadding="4" style="border-collapse:collapse;font-size:13px;color:#4a5568">'
            . '<tr><td><strong>From</strong></td><td><a href="mailto:%1$s">%1$s</a></td></tr>'
            . '<tr><td><strong>Date</strong></td><td>%2$s</td></tr>'
            . '<tr><td><strong>Locale</strong></td><td>%3$s</td></tr>'
            . '<tr><td><strong>IP</strong></td><td>%4$s</td></tr>'
            . '<tr><td><strong>ID</strong></td><td>#%5$s</td></tr>'
            . '</table>'
            . '<blockquote style="margin:1.2em 0;padding:.8em 1.2em;border-left:3px solid #c8a96e;'
            . 'background:#faf7f1;white-space:pre-wrap">%6$s</blockquote>'
            . '</div>',
            $e($comment->getEmail()),
            $e($comment->getCreatedAt()->format('Y-m-d H:i:s')),
            $e($comment->getLocale()),
            $e($comment->getIpAddress()),
            $e((string) ($comment->getId() ?? '?')),
            nl2br($e($comment->getBody())),
        );
    }
}
