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
    private bool $versionResolved = false;
    private ?string $version = null;

    public function __construct(
        private readonly string  $projectDir,
        private readonly ?string $deployedAtEnv = null,
        private readonly ?string $versionEnv = null,
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

    /**
     * Short commit hash of what is running — the only version number that
     * cannot drift from the code, since it *is* the code. Same two sources as
     * the date: baked in at build time, or read from the reflog in dev.
     */
    public function getVersion(): ?string
    {
        if ($this->versionResolved) {
            return $this->version;
        }
        $this->versionResolved = true;

        $env = trim((string) $this->versionEnv);
        if ($env !== '') {
            $this->version = substr($env, 0, 12);

            return $this->version;
        }

        $this->version = $this->versionFromGitReflog();

        return $this->version;
    }

    private function versionFromGitReflog(): ?string
    {
        $head = $this->lastReflogEntry();
        if ($head === null) {
            return null;
        }

        // "<old-sha> <new-sha> <name> <email> <ts> <tz>" — the second field.
        $parts = explode(' ', $head);

        return isset($parts[1]) && preg_match('/^[0-9a-f]{40}$/', $parts[1])
            ? substr($parts[1], 0, 7)
            : null;
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
    private function lastReflogEntry(): ?string
    {
        $path = $this->projectDir . '/.git/logs/HEAD';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines ? explode("\t", end($lines))[0] : null;
    }

    private function fromGitReflog(): ?\DateTimeImmutable
    {
        $head = $this->lastReflogEntry();
        if ($head === null) {
            return null;
        }

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
