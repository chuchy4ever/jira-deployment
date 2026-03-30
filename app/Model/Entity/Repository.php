<?php

declare(strict_types=1);

namespace App\Model\Entity;

final class Repository
{
	/** @var Task[] */
	public array $tasks = [];

	public ?string $deployStatus = null; // null, running, success, failure
	public ?string $deployUrl = null;
	public ?int $deployRunId = null;

	public function __construct(
		public readonly string $fullName,
		public readonly string $workflow,
	) {
	}

	public function getSlug(): string
	{
		return str_replace(['/', '.'], '-', $this->fullName);
	}

	public function allTasksMerged(): bool
	{
		foreach ($this->tasks as $task) {
			if (!$task->isMerged) {
				return false;
			}
		}
		return count($this->tasks) > 0;
	}

	public function allTasksDeployed(): bool
	{
		foreach ($this->tasks as $task) {
			if (!$task->isDeployed) {
				return false;
			}
		}
		return count($this->tasks) > 0;
	}
}
