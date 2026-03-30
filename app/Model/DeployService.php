<?php

declare(strict_types=1);

namespace App\Model;

use App\Model\Entity\PullRequest;
use App\Model\Entity\Repository;
use App\Model\Entity\Task;

final class DeployService
{
	/** @var array<string, array{workflow?: string}> */
	private array $repositories;
	private string $cacheDir;

	/**
	 * @param array<string, array{workflow?: string}> $repositories
	 */
	public function __construct(
		private readonly JiraService $jiraService,
		private readonly GitHubService $gitHubService,
		array $repositories = [],
	) {
		$this->repositories = $repositories;
		$this->cacheDir = dirname(__DIR__, 2) . '/temp/cache/deploy';
		if (!is_dir($this->cacheDir)) {
			@mkdir($this->cacheDir, 0777, true);
		}
	}

	/**
	 * @return Repository[]
	 */
	public function loadDashboard(): array
	{
		$tasks = $this->getCachedTasks();

		if (empty($tasks)) {
			return [];
		}

		$gitInfo = $this->getCachedGitStatus($tasks);

		foreach ($tasks as $task) {
			if (isset($gitInfo[$task->key])) {
				$info = $gitInfo[$task->key];
				if ($task->repository === null) {
					$task->repository = $info['repo'];
					$task->branchName = $info['branch'];
				}
				$task->isMerged = $info['merged'];
				$task->pullRequest = $info['pr'];
			}
		}

		return $this->buildRepoMap($tasks);
	}

	/**
	 * @param Task[] $tasks
	 * @return array<string, array{repo: string, branch: string, merged: bool, pr: PullRequest|null}>
	 */
	private function getCachedGitStatus(array $tasks): array
	{
		$file = $this->cacheDir . '/gitstatus.cache';
		if (file_exists($file)) {
			$content = file_get_contents($file);
			if ($content !== false) {
				$data = @unserialize($content);
				if (is_array($data) && ($data['expires'] ?? 0) > time()) {
					/** @var array<string, array{repo: string, branch: string, merged: bool, pr: PullRequest|null}> */
					return $data['value'];
				}
			}
		}

		$gitInfo = $this->gitHubService->getStatusForKnownBranches($tasks);

		file_put_contents($file, serialize([
			'expires' => time() + 120,
			'value' => $gitInfo,
		]));

		return $gitInfo;
	}

	/**
	 * @return Task[]
	 */
	private function getCachedTasks(): array
	{
		/** @var Task[]|null $cached */
		$cached = $this->getCache('tasks');
		if ($cached !== null) {
			return $cached;
		}

		$tasks = $this->jiraService->getTasksForRelease();

		if (empty($tasks)) {
			return [];
		}

		$taskKeys = array_map(fn(Task $t) => $t->key, $tasks);
		$branchMap = $this->gitHubService->findAllBranches($taskKeys, array_keys($this->repositories));

		foreach ($tasks as $task) {
			if (isset($branchMap[$task->key])) {
				$task->repository = $branchMap[$task->key]['repo'];
				$task->branchName = $branchMap[$task->key]['branch'];
			}
		}

		$this->setCache('tasks', $tasks);

		return $tasks;
	}

	public function forceRefresh(): void
	{
		@unlink($this->cacheDir . '/tasks.cache');
		@unlink($this->cacheDir . '/gitstatus.cache');
		$this->gitHubService->clearBranchCache();
	}

	public function invalidateAfterDeploy(): void
	{
		@unlink($this->cacheDir . '/tasks.cache');
		@unlink($this->cacheDir . '/gitstatus.cache');
	}

	public function invalidateGitStatus(): void
	{
		@unlink($this->cacheDir . '/gitstatus.cache');
		@unlink($this->cacheDir . '/deploystatus.cache');
	}

	/**
	 * @return array<string, array{id: string, name: string, url: string}|array{error: string}>
	 */
	public function createRelease(string $releaseName): array
	{
		$tasks = $this->getCachedTasks();
		$results = [];

		$byProject = [];
		foreach ($tasks as $task) {
			$byProject[$task->projectKey][] = $task->key;
		}

		foreach ($byProject as $projectKey => $issueKeys) {
			try {
				$results[$projectKey] = $this->jiraService->createRelease($projectKey, $releaseName, $issueKeys);
			} catch (\Throwable $e) {
				$results[$projectKey] = ['error' => $e->getMessage()];
			}
		}

		@unlink($this->cacheDir . '/tasks.cache');

		return $results;
	}

	/**
	 * @return array{released: list<string>, transitioned: list<string>, errors: list<string>}
	 */
	public function finalizeCurrentRelease(): array
	{
		$tasks = $this->getCachedTasks();
		$results = ['released' => [], 'transitioned' => [], 'errors' => []];

		$versionIds = $this->jiraService->findVersionIds($tasks);

		foreach ($versionIds as $projectKey => $versionId) {
			if ($this->jiraService->releaseVersion($versionId)) {
				$results['released'][] = $projectKey;
			} else {
				$results['errors'][] = "Nepodařilo se vydat release pro {$projectKey}";
			}
		}

		foreach ($tasks as $task) {
			if ($this->jiraService->transitionToDone($task->key)) {
				$results['transitioned'][] = $task->key;
			} else {
				$results['errors'][] = "Nepodařilo se přesunout {$task->key} do DONE";
			}
		}

		$this->invalidateAfterDeploy();

		return $results;
	}

	/**
	 * @param Repository[] $repos
	 */
	public function enrichWithDeployStatus(array $repos): void
	{
		$cachedRuns = $this->loadDeployStatusCache();

		/** @var array<string, array{id: int, status: string, conclusion: string|null, url: string, created_at: string}|null> $statusMap */
		$statusMap = [];

		foreach ($repos as $repo) {
			if ($repo->workflow === '' || $repo->fullName === 'Nepřiřazené') {
				continue;
			}

			$slug = $repo->getSlug();

			$run = $cachedRuns[$slug]
				?? $this->gitHubService->getLatestWorkflowRun($repo->fullName, $repo->workflow);

			$statusMap[$slug] = $run;

			if ($run === null) {
				continue;
			}

			$status = $run['status'];
			if ($status === 'in_progress' || $status === 'queued') {
				$repo->deployStatus = 'running';
				$repo->deployRunId = $run['id'];
				$repo->deployUrl = $run['url'];
			} elseif ($status === 'completed') {
				$runDate = date('Y-m-d', strtotime($run['created_at']) ?: 0);
				if ($runDate === date('Y-m-d')) {
					$repo->deployStatus = $run['conclusion'] === 'success' ? 'success' : 'failure';
					$repo->deployUrl = $run['url'];
				}
			}
		}

		if (empty($cachedRuns)) {
			file_put_contents($this->cacheDir . '/deploystatus.cache', serialize([
				'expires' => time() + 15,
				'value' => $statusMap,
			]));
		}
	}

	/**
	 * @return array<string, array{id: int, status: string, conclusion: string|null, url: string, created_at: string}|null>
	 */
	private function loadDeployStatusCache(): array
	{
		$file = $this->cacheDir . '/deploystatus.cache';
		if (!file_exists($file)) {
			return [];
		}
		$content = file_get_contents($file);
		if ($content === false) {
			return [];
		}
		$data = @unserialize($content);
		if (!is_array($data) || ($data['expires'] ?? 0) <= time()) {
			return [];
		}
		/** @var array<string, array{id: int, status: string, conclusion: string|null, url: string, created_at: string}|null> */
		return is_array($data['value'] ?? null) ? $data['value'] : [];
	}

	public function createPullRequest(string $repo, string $branch, string $taskKey, string $summary, string $jiraUrl): Entity\PullRequest
	{
		$this->invalidateGitStatus();
		$title = "{$taskKey}: {$summary}";
		return $this->gitHubService->createPullRequest($repo, $branch, $title, $jiraUrl);
	}

	public function mergePullRequest(string $repo, int $prNumber): bool
	{
		$this->invalidateGitStatus();
		return $this->gitHubService->mergePullRequest($repo, $prNumber);
	}

	public function startDeploy(string $repo): ?int
	{
		$workflow = $this->repositories[$repo]['workflow'] ?? 'build-production.yml';
		return $this->gitHubService->dispatchWorkflow($repo, $workflow);
	}

	/**
	 * @return array{status: string, conclusion: string|null, url: string}|null
	 */
	public function checkDeployStatus(string $repo, int $runId): ?array
	{
		return $this->gitHubService->getWorkflowRunStatus($repo, $runId);
	}

	/**
	 * @param Task[] $tasks
	 * @return array<string, Repository>
	 */
	private function buildRepoMap(array $tasks): array
	{
		$repoMap = [];

		foreach ($tasks as $task) {
			$repoKey = $task->repository ?? '_unassigned';

			if (!isset($repoMap[$repoKey])) {
				if ($repoKey === '_unassigned') {
					$repoMap[$repoKey] = new Repository(fullName: 'Nepřiřazené', workflow: '');
				} else {
					$workflow = $this->repositories[$repoKey]['workflow'] ?? 'build-production.yml';
					$repoMap[$repoKey] = new Repository(fullName: $repoKey, workflow: $workflow);
				}
			}

			$repoMap[$repoKey]->tasks[] = $task;
		}

		uksort($repoMap, function (string $a, string $b): int {
			if ($a === '_unassigned') return 1;
			if ($b === '_unassigned') return -1;
			return $a <=> $b;
		});

		return $repoMap;
	}

	private function getCache(string $key): mixed
	{
		$file = $this->cacheDir . '/' . $key . '.cache';
		if (!file_exists($file)) {
			return null;
		}
		$content = file_get_contents($file);
		if ($content === false) {
			return null;
		}
		$data = @unserialize($content);
		if (!is_array($data)) {
			@unlink($file);
			return null;
		}
		return $data['value'] ?? null;
	}

	private function setCache(string $key, mixed $value): void
	{
		$file = $this->cacheDir . '/' . $key . '.cache';
		file_put_contents($file, serialize(['value' => $value]));
	}
}
