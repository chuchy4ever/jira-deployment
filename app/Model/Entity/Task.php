<?php

declare(strict_types=1);

namespace App\Model\Entity;

final class Task
{
	public function __construct(
		public readonly string $key,
		public readonly string $summary,
		public readonly string $status,
		public readonly string $projectKey,
		public readonly ?string $assignee,
		public readonly ?string $url,
		public ?string $repository = null,
		public ?string $branchName = null,
		public ?string $fixVersion = null,
		public ?PullRequest $pullRequest = null,
		public bool $isMerged = false,
		public bool $isDeployed = false,
		public bool $isJiraDone = false,
	) {
	}

	public function getSlug(): string
	{
		return str_replace(['/', '.'], '-', $this->key);
	}
}
