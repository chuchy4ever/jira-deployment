<?php

declare(strict_types=1);

namespace App\Model\Entity;

final class PullRequest
{
	public function __construct(
		public readonly int $number,
		public readonly string $title,
		public readonly string $state,
		public readonly string $url,
		public readonly bool $mergeable,
		public readonly ?string $mergeableState,
		public readonly bool $draft,
		public readonly ?string $ciStatus, // success, failure, pending, null
		public readonly bool $hasConflicts,
		public readonly int $reviewApprovals,
	) {
	}

	public function canMerge(): bool
	{
		return $this->mergeable
			&& !$this->hasConflicts
			&& !$this->draft
			&& $this->ciStatus !== 'failure';
	}
}
