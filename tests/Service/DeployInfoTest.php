<?php

namespace App\Tests\Service;

use App\Service\DeployInfo;
use PHPUnit\Framework\TestCase;

class DeployInfoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/deployinfo-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/var', 0777, true);
        mkdir($this->dir . '/.git/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.git/logs/HEAD', '/var/deploy-stamp'] as $f) {
            @unlink($this->dir . $f);
        }
        foreach (['/.git/logs', '/.git', '/var', ''] as $d) {
            @rmdir($this->dir . $d);
        }
    }

    private function writeReflog(int $timestamp): void
    {
        file_put_contents(
            $this->dir . '/.git/logs/HEAD',
            "0000000000000000000000000000000000000000 abc123 Chuck <c@example.com> 1700000000 +0200\tcommit: first\n"
            . "abc123 def456 Chuck <c@example.com> $timestamp +0200\tcommit: second\n"
        );
    }

    public function testEnvironmentVariableWins(): void
    {
        $this->writeReflog(1700000000);
        touch($this->dir . '/var/deploy-stamp', 1600000000);

        $info = new DeployInfo($this->dir, '2026-07-31T12:00:00Z');

        $this->assertSame('2026-07-31 12:00', $info->getDeployedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function testStampFileUsedWhenNoEnvironmentVariable(): void
    {
        $this->writeReflog(1700000000);
        $stamp = 1750000000;
        touch($this->dir . '/var/deploy-stamp', $stamp);

        $info = new DeployInfo($this->dir, null);

        $this->assertSame($stamp, $info->getDeployedAt()->getTimestamp());
    }

    public function testFallsBackToTheLastGitReflogEntry(): void
    {
        $this->writeReflog(1785526351);

        $info = new DeployInfo($this->dir, '');

        $this->assertSame(1785526351, $info->getDeployedAt()->getTimestamp());
    }

    public function testNullWhenNothingCanAnswer(): void
    {
        $this->assertNull((new DeployInfo($this->dir, null))->getDeployedAt());
    }

    public function testMalformedEnvironmentValueDoesNotThrow(): void
    {
        // Falls through to the next source rather than blowing up the page.
        $this->writeReflog(1785526351);

        $info = new DeployInfo($this->dir, 'not-a-date');

        $this->assertSame(1785526351, $info->getDeployedAt()->getTimestamp());
    }

    public function testMalformedReflogIsIgnored(): void
    {
        file_put_contents($this->dir . '/.git/logs/HEAD', "garbage without a timestamp\n");

        $this->assertNull((new DeployInfo($this->dir, null))->getDeployedAt());
    }
}
