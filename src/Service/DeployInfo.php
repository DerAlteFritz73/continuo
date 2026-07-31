<?php

namespace App\Service;

/**
 * When was this copy of the app last put in place?
 *
 * There is no single answer that works everywhere, so three sources are tried
 * in order of trustworthiness:
 *
 *  1. APP_DEPLOYED_AT — baked into the Docker image at build time. This is the
 *     authoritative one in production.
 *  2. var/deploy-stamp — a file a deploy script can `touch`, for installs that
 *     are not rebuilt from the image.
 *  3. .git/logs/HEAD — the last reflog entry. Only present in development
 *     (.dockerignore excludes .git), and read as a plain file so nothing is
 *     shelled out from PHP-FPM.
 *
 * Returns null when none of them answers; callers hide the line rather than
 * printing a made-up date.
 */
class DeployInfo
{
    private bool $resolved = false;
    private ?\DateTimeImmutable $deployedAt = null;

    public function __construct(
        private readonly string  $projectDir,
        private readonly ?string $deployedAtEnv = null,
    ) {}

    public function getDeployedAt(): ?\DateTimeImmutable
    {
        if ($this->resolved) {
            return $this->deployedAt;
        }
        $this->resolved = true;

        $this->deployedAt = $this->fromEnv()
            ?? $this->fromStampFile()
            ?? $this->fromGitReflog();

        return $this->deployedAt;
    }

    private function fromEnv(): ?\DateTimeImmutable
    {
        $raw = trim((string) $this->deployedAtEnv);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function fromStampFile(): ?\DateTimeImmutable
    {
        $path = $this->projectDir . '/var/deploy-stamp';
        if (!is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);

        return $mtime ? (new \DateTimeImmutable())->setTimestamp($mtime) : null;
    }

    /**
     * Last line of .git/logs/HEAD is:
     *   <old-sha> <new-sha> <name> <email> <unix-ts> <tz>\t<message>
     * The timestamp is the sixth-from-last field before the tab.
     */
    private function fromGitReflog(): ?\DateTimeImmutable
    {
        $path = $this->projectDir . '/.git/logs/HEAD';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return null;
        }

        $head = explode("\t", end($lines))[0];
        if (!preg_match('/\s(\d{9,})\s([+-]\d{4})$/', $head, $m)) {
            return null;
        }

        try {
            return new \DateTimeImmutable('@' . $m[1]);
        } catch (\Exception) {
            return null;
        }
    }
}
