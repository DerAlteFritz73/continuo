<?php

namespace App\Tests\Service;

use App\Entity\Comment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The e-mail address is mandatory and must be a real address — these are the
 * constraints the /comment endpoint enforces before anything is persisted.
 */
class CommentValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** @return string[] violation messages, keyed by property */
    private function violations(string $email, string $body): array
    {
        $comment = new Comment();
        $comment->setEmail($email);
        $comment->setBody($body);

        $messages = [];
        foreach ($this->validator->validate($comment) as $violation) {
            $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $messages;
    }

    public function testAValidEmailAndBodyPass(): void
    {
        $this->assertSame([], $this->violations('visitor@example.com', 'Bravo!'));
    }

    /** @dataProvider invalidEmails */
    public function testInvalidEmailIsRejected(string $email): void
    {
        $this->assertArrayHasKey('email', $this->violations($email, 'Bravo!'));
    }

    public static function invalidEmails(): array
    {
        return [
            'empty'        => [''],
            'blank'        => ['   '],
            'no at sign'   => ['visitor.example.com'],
            'no domain'    => ['visitor@'],
            'no tld'       => ['visitor@localhost'],
            'spaces'       => ['vis itor@example.com'],
            'no local part'=> ['@example.com'],
        ];
    }

    public function testEmptyBodyIsRejected(): void
    {
        $this->assertArrayHasKey('body', $this->violations('visitor@example.com', '   '));
    }

    public function testOverlongBodyIsRejected(): void
    {
        $this->assertArrayHasKey('body', $this->violations('visitor@example.com', str_repeat('a', 5001)));
    }
}
