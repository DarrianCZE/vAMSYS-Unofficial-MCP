<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Reads the latest published release of the configured GitHub repository.
 * Expected assets: vamsys-mcp-<version>.zip and vamsys-mcp-<version>.zip.sig.
 */
final class GitHubReleases
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly string $repository,
    ) {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw new UpdateException("Invalid update repository '{$repository}' (expected owner/name).");
        }
    }

    /**
     * @return array{version: string, notes: string, url: string, zip_url: string, sig_url: string}
     */
    public function latest(): array
    {
        $data = json_decode($this->fetch("https://api.github.com/repos/{$this->repository}/releases/latest", 'application/vnd.github+json'), true);
        if (!is_array($data) || !is_string($data['tag_name'] ?? null)) {
            throw new UpdateException("No published release found for {$this->repository}.");
        }
        $version = ltrim($data['tag_name'], 'v');
        $assets = [];
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            $assets[(string) ($asset['name'] ?? '')] = (string) ($asset['browser_download_url'] ?? '');
        }
        $zip = "vamsys-mcp-{$version}.zip";
        if (empty($assets[$zip]) || empty($assets["{$zip}.sig"])) {
            throw new UpdateException("Release {$version} has no {$zip} / {$zip}.sig assets.");
        }

        return [
            'version' => $version,
            'notes' => mb_substr((string) ($data['body'] ?? ''), 0, 5000),
            'url' => (string) ($data['html_url'] ?? ''),
            'zip_url' => $assets[$zip],
            'sig_url' => $assets["{$zip}.sig"],
        ];
    }

    /** CHANGELOG.md as tagged for the given release. */
    public function changelog(string $version): string
    {
        return $this->fetch("https://raw.githubusercontent.com/{$this->repository}/v{$version}/CHANGELOG.md", 'text/plain');
    }

    public function download(string $url): string
    {
        if (!str_starts_with($url, 'https://github.com/')) {
            throw new UpdateException('Refusing to download an update from outside github.com.');
        }

        return $this->fetch($url, 'application/octet-stream');
    }

    private function fetch(string $url, string $accept): string
    {
        $request = $this->requests->createRequest('GET', $url)
            ->withHeader('Accept', $accept)
            ->withHeader('User-Agent', 'vamsys-unofficial-mcp-updater');
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface) {
            throw new UpdateException('Could not reach GitHub to check for updates.');
        }
        if ($response->getStatusCode() === 404 && str_starts_with($url, 'https://api.github.com/')) {
            throw new UpdateException("No published release found for {$this->repository} yet (or the repository is private).");
        }
        if ($response->getStatusCode() !== 200) {
            throw new UpdateException("GitHub answered HTTP {$response->getStatusCode()} for {$url}.");
        }
        $body = $response->getBody();
        if ($body->getSize() !== null && $body->getSize() > ReleasePackage::MAX_BYTES) {
            throw new UpdateException('The update download is unexpectedly large.');
        }

        return (string) $body;
    }
}
