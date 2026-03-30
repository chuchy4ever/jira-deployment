<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Model\DeployService;
use App\Model\Entity\Repository;
use App\Model\Entity\Task;
use Nette\Http\SessionSection;

final class DashboardPresenter extends BasePresenter
{
	/** @var array<Repository> */
	private array $repos = [];

	public function __construct(
		private readonly DeployService $deployService,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		try {
			$this->repos = $this->deployService->loadDashboard();
		} catch (\GuzzleHttp\Exception\ConnectException $e) {
			$this->flashMessage('Nelze se připojit k API: ' . $e->getMessage(), 'danger');
			$this->repos = [];
		} catch (\Throwable $e) {
			$this->flashMessage('Chyba při načítání: ' . $e->getMessage(), 'danger');
			$this->repos = [];
		}

		try {
			$this->deployService->enrichWithDeployStatus($this->repos);
		} catch (\Throwable) {
		}

		$this->applySessionDeployState();
		$this->checkRunningDeploys();

		$totalTasks = 0;
		/** @var Task[] $unassigned */
		$unassigned = [];
		$releaseName = null;
		$allHaveRelease = true;
		foreach ($this->repos as $key => $repo) {
			foreach ($repo->tasks as $task) {
				$totalTasks++;
				if ($key === '_unassigned') {
					$unassigned[] = $task;
				}
				if ($task->fixVersion !== null) {
					$releaseName = $task->fixVersion;
				} else {
					$allHaveRelease = false;
				}
			}
		}

		$hasRelease = $allHaveRelease && $releaseName !== null && $totalTasks > 0;

		$this->template->repos = $this->repos;
		$this->template->totalTasks = $totalTasks;
		$this->template->unassignedTasks = $unassigned;
		$this->template->hasRelease = $hasRelease;
		$this->template->releaseName = $releaseName ?? date('d.m.Y');
	}

	private function applySessionDeployState(): void
	{
		$deployState = $this->getDeployState();
		foreach ($this->repos as $repo) {
			$slug = $repo->getSlug();
			if (!isset($deployState[$slug])) {
				continue;
			}
			$state = $deployState[$slug];
			$status = $state['status'];
			$url = isset($state['url']) ? (string) $state['url'] : null;

			if ($repo->deployStatus !== 'running' && $status !== 'running') {
				$repo->deployStatus = $status;
				$repo->deployUrl = $url;
			}
			if ($repo->deployStatus === 'running' && $status === 'running') {
				$repo->deployUrl = $repo->deployUrl ?? $url;
			}
		}
	}

	public function handleCreateRelease(): void
	{
		$name = date('d.m.Y');

		try {
			$results = $this->deployService->createRelease($name);
			$projects = [];
			foreach ($results as $projectKey => $result) {
				if (isset($result['error'])) {
					$this->flashMessage("Release pro {$projectKey}: {$result['error']}", 'danger');
				} else {
					$projects[] = $projectKey;
				}
			}
			if (!empty($projects)) {
				$this->flashMessage('Release "' . $name . '" vytvořen pro: ' . implode(', ', $projects), 'success');
			}
		} catch (\Throwable $e) {
			$this->flashMessage("Chyba při vytváření release: {$e->getMessage()}", 'danger');
		}

		$this->redrawAjax();
	}

	public function handleFinalizeRelease(): void
	{
		try {
			$results = $this->deployService->finalizeCurrentRelease();

			if (!empty($results['released'])) {
				$this->flashMessage(
					'Release vydán pro: ' . implode(', ', $results['released'])
					. '. Tasků přesunuto do DONE: ' . count($results['transitioned']),
					'success',
				);
			}

			foreach ($results['errors'] as $error) {
				$this->flashMessage($error, 'warning');
			}
		} catch (\Throwable $e) {
			$this->flashMessage("Chyba: {$e->getMessage()}", 'danger');
		}

		$this->redrawAjax();
	}

	private function checkRunningDeploys(): void
	{
		$deployState = $this->getDeployState();
		$changed = false;

		foreach ($deployState as $slug => &$state) {
			$status = $state['status'];
			$runId = isset($state['runId']) ? (int) $state['runId'] : 0;

			if ($status !== 'running' || $runId === 0) {
				continue;
			}

			foreach ($this->repos as $repo) {
				if ($repo->getSlug() === $slug) {
					$result = $this->deployService->checkDeployStatus($repo->fullName, $runId);
					if ($result !== null) {
						$state['url'] = $result['url'];
						if ($result['status'] === 'completed') {
							$state['status'] = $result['conclusion'] === 'success' ? 'success' : 'failure';
							$repo->deployStatus = (string) $state['status'];
							$repo->deployUrl = (string) $state['url'];
							$changed = true;
						}
					}
					break;
				}
			}
		}
		unset($state);

		if ($changed) {
			$this->setDeployState($deployState);
		}
	}

	public function handleForceRefresh(): void
	{
		$this->deployService->forceRefresh();
		$this->flashMessage('Data znovu načtena z Jiry a GitHubu', 'success');
		$this->redrawAjax();
	}

	public function handleRefresh(): void
	{
		if ($this->isAjax()) {
			$this->redrawControl('dashboard');
		}
	}

	public function handleCreatePr(string $taskKey, string $repo, string $branch, string $summary, string $jiraUrl = ''): void
	{
		try {
			$pr = $this->deployService->createPullRequest($repo, $branch, $taskKey, $summary, $jiraUrl);
			$this->flashMessage("PR #{$pr->number} vytvořen pro {$taskKey}", 'success');
		} catch (\Throwable $e) {
			$this->flashMessage("Chyba při vytváření PR: {$e->getMessage()}", 'danger');
		}

		$this->redrawAjax();
	}

	public function handleMergePr(string $taskKey, string $repo, int $prNumber): void
	{
		try {
			$success = $this->deployService->mergePullRequest($repo, $prNumber);
			if ($success) {
				$this->flashMessage("PR #{$prNumber} mergnut pro {$taskKey}", 'success');
			} else {
				$this->flashMessage("Merge selhal pro {$taskKey}", 'danger');
			}
		} catch (\Throwable $e) {
			$this->flashMessage("Chyba při mergi: {$e->getMessage()}", 'danger');
		}

		$this->redrawAjax();
	}

	public function handleDeploy(string $repoSlug, string $repoFullName): void
	{
		try {
			$runId = $this->deployService->startDeploy($repoFullName);

			if ($runId !== null) {
				$deployState = $this->getDeployState();
				$deployState[$repoSlug] = [
					'runId' => $runId,
					'status' => 'running',
					'url' => null,
				];
				$this->setDeployState($deployState);

				$this->flashMessage("Deploy spuštěn pro {$repoFullName}", 'success');
			} else {
				$this->flashMessage("Nepodařilo se spustit deploy pro {$repoFullName}", 'danger');
			}
		} catch (\Throwable $e) {
			$this->flashMessage("Chyba při deployi: {$e->getMessage()}", 'danger');
		}

		$this->redrawAjax();
	}

	/**
	 * @return array<string, array{runId: int|null, status: string, url: string|null}>
	 */
	private function getDeployState(): array
	{
		$section = $this->getSession('deploy');
		$data = $section->get('deployState');
		/** @var array<string, array{runId: int|null, status: string, url: string|null}> */
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string, array{runId: int|null, status: string, url: string|null}> $state
	 */
	private function setDeployState(array $state): void
	{
		$section = $this->getSession('deploy');
		$section->set('deployState', $state);
	}

	private function redrawAjax(): void
	{
		if ($this->isAjax()) {
			$this->redrawControl('dashboard');
			$this->redrawControl('flashMessages');
		} else {
			$this->redirect('this');
		}
	}
}
