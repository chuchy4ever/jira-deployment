<?php

declare(strict_types=1);

namespace App\Latte;

use Latte\Extension;

final class ViteExtension extends Extension
{
	private string $manifestPath;
	private bool $devMode;
	private string $devServerUrl;

	public function __construct(string $wwwDir, bool $devMode = false, string $devServerUrl = 'http://localhost:5173')
	{
		$this->manifestPath = $wwwDir . '/dist/.vite/manifest.json';
		$this->devMode = $devMode;
		$this->devServerUrl = rtrim($devServerUrl, '/');
	}

	public function getFunctions(): array
	{
		return [
			'viteAsset' => [$this, 'renderAsset'],
		];
	}

	/**
	 * Returns HTML tags for a Vite entry point.
	 * In dev mode, checks if Vite dev server is running — falls back to build if not.
	 */
	public function renderAsset(string $entrypoint): string
	{
		if ($this->devMode && $this->isDevServerRunning()) {
			return '<script type="module" src="' . $this->devServerUrl . '/@vite/client"></script>'
				. "\n" . '<script type="module" src="' . $this->devServerUrl . '/' . $entrypoint . '"></script>';
		}

		if (!file_exists($this->manifestPath)) {
			return '<!-- Vite manifest not found, run: npm run build -->';
		}

		$content = file_get_contents($this->manifestPath);
		if ($content === false) {
			return '<!-- Vite manifest not readable -->';
		}

		/** @var array<string, array{file: string, css?: list<string>}> $manifest */
		$manifest = json_decode($content, true);
		$entry = $manifest[$entrypoint] ?? null;

		if ($entry === null) {
			return '<!-- Vite entry not found: ' . htmlspecialchars($entrypoint) . ' -->';
		}

		$tags = [];

		foreach ($entry['css'] ?? [] as $css) {
			$tags[] = '<link rel="stylesheet" href="/dist/' . $css . '">';
		}

		$tags[] = '<script type="module" src="/dist/' . $entry['file'] . '"></script>';

		return implode("\n", $tags);
	}

	private function isDevServerRunning(): bool
	{
		$handle = @fsockopen('127.0.0.1', 5173, $errno, $errstr, 0.3);
		if ($handle) {
			fclose($handle);
			return true;
		}
		return false;
	}
}
