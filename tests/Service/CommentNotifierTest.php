<?php

namespace App\Tests\Service;

use App\Entity\Comment;
use App\Service\CommentNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class CommentNotifierTest extends TestCase
{
    private const NOTIFY_TO = 'jm.kreilos@outlook.fr';
    private const MAIL_FROM = 'noreply@continuo.kreilos.fr';

    private function comment(string $email = 'visitor@example.com', string $body = 'Bravo!'): Comment
    {
        $comment = new Comment();
        $comment->setEmail($email);
        $comment->setBody($body);
        $comment->setLocale('fr');
        $comment->setIpAddress('203.0.113.7');
        $comment->setAppVersion('fe92ab1');

        return $comment;
    }

    /** Captures the message instead of sending it. */
    private function recordingMailer(?Email &$sent): MailerInterface
    {
        return new class($sent) implements MailerInterface {
            public function __construct(private ?Email &$sent) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message instanceof Email ? $message : null;
            }
        };
    }

    public function testNotificationGoesToTheOwnerAndRepliesToTheVisitor(): void
    {
        $sent = null;
        $notifier = new CommentNotifier(
            $this->recordingMailer($sent), new NullLogger(), self::NOTIFY_TO, self::MAIL_FROM
        );

        $error = $notifier->notify($this->comment());

        $this->assertNull($error);
        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame(self::NOTIFY_TO, $sent->getTo()[0]->getAddress());
        // The visitor is never the From: — most relays reject a foreign sender.
        $this->assertSame(self::MAIL_FROM, $sent->getFrom()[0]->getAddress());
        $this->assertSame('visitor@example.com', $sent->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('visitor@example.com', $sent->getSubject());
    }

    public function testBodyCarriesTheCommentAndItsMetadata(): void
    {
        $sent = null;
        $notifier = new CommentNotifier(
            $this->recordingMailer($sent), new NullLogger(), self::NOTIFY_TO, self::MAIL_FROM
        );

        $notifier->notify($this->comment(body: 'The 6/4 in bar 12 looks wrong.'));

        $this->assertStringContainsString('The 6/4 in bar 12 looks wrong.', $sent->getTextBody());
        $this->assertStringContainsString('203.0.113.7', $sent->getTextBody());
        // Which build the visitor was on — the first thing you want in a report.
        $this->assertStringContainsString('fe92ab1', $sent->getTextBody());
        $this->assertStringContainsString('fe92ab1', $sent->getHtmlBody());
        $this->assertStringContainsString('The 6/4 in bar 12 looks wrong.', $sent->getHtmlBody());
    }

    public function testHtmlBodyEscapesMarkupFromTheVisitor(): void
    {
        $sent = null;
        $notifier = new CommentNotifier(
            $this->recordingMailer($sent), new NullLogger(), self::NOTIFY_TO, self::MAIL_FROM
        );

        $notifier->notify($this->comment(body: '<script>alert(1)</script>'));

        $this->assertStringNotContainsString('<script>', $sent->getHtmlBody());
        $this->assertStringContainsString('&lt;script&gt;', $sent->getHtmlBody());
    }

    public function testTransportFailureIsReportedRatherThanThrown(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connection refused');
            }
        };

        $notifier = new CommentNotifier($mailer, new NullLogger(), self::NOTIFY_TO, self::MAIL_FROM);

        // A stored comment must not be lost because the relay is down.
        $this->assertSame('Connection refused', $notifier->notify($this->comment()));
    }
}
