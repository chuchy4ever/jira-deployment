<?php

declare(strict_types=1);

namespace App\Model;

use App\Model\Entity\PullRequest;
use App\Model\Entity\Task;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

final class GitHubService
{
	private Client $client;

	public function __construct(
		private readonly string $token,
		private readonly string $org,
	) {
		$this->client = new Client([
			'base_uri' => 'https://api.github.com/',
			'headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				'Accept' => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			],
			'connect_timeout' => 10,
			'timeout' => 15,
		]);
	}

	public function findBranch(string $repo, string $taskKey): ?string
	{
		try {
			$refs = $this->apiGet("repos/{$repo}/git/matching-refs/heads/{$taskKey}");
			foreach ($refs->asList() as $ref) {
				$branchName = str_replace('refs/heads/', '', $ref->string('ref'));
				if ($branchName === $taskKey || str_starts_with($branchName, $taskKey . '/')) {
					return $branchName;
				}
			}
		} catch (\Throwable) {
		}

		return null;
	}

	/**
	 * @param string[] $repos
	 * @return array{repo: string, branch: string}|null
	 */
	public function findBranchInRepos(string $taskKey, array $repos): ?array
	{
		foreach ($repos as $repo) {
			$branch = $this->findBranch($repo, $taskKey);
			if ($branch !== null) {
				return ['repo' => $repo, 'branch' => $branch];
			}
		}
		return null;
	}

	/**
	 * @param string[] $taskKeys
	 * @param string[] $limitToRepos
	 * @return array<string, array{repo: string, branch: string}>
	 */
	public function findAllBranches(array $taskKeys, array $limitToRepos = []): array
	{
		$cache = $this->loadBranchCache();
		/** @var array<string, array{repo: string, branch: string}> $results */
		$results = [];
		$unknown = [];

		foreach ($taskKeys as $key) {
			if (isset($cache[$key])) {
				$results[$key] = $cache[$key];
			} else {
				$unknown[] = $key;
			}
		}

		if (empty($unknown)) {
			return $results;
		}

		foreach (array_chunk($unknown, 5) as $chunk) {
			$q = implode(' OR ', $chunk) . " org:{$this->org} is:pr";
			try {
				$data = $this->apiGet('search/issues', ['q' => $q, 'per_page' => 50]);
				foreach ($data->list('items') as $item) {
					$prUrl = $item->sub('pull_request')->string('url');
					if (!preg_match('#repos/([^/]+/[^/]+)/#', $prUrl, $m)) {
						continue;
					}
					$repo = $m[1];
					try {
						$detail = $this->apiGet("repos/{$repo}/pulls/{$item->int('number')}");
						$branch = $detail->sub('head')->string('ref');
						if ($branch !== '') {
							foreach ($chunk as $taskKey) {
								if (!isset($results[$taskKey]) && ($branch === $taskKey || str_starts_with($branch, $taskKey . '/'))) {
									$results[$taskKey] = ['repo' => $repo, 'branch' => $branch];
									$cache[$taskKey] = $results[$taskKey];
									break;
								}
							}
						}
					} catch (\Throwable) {
					}
				}
			} catch (\Throwable) {
			}
		}

		$stillUnknown = array_diff($unknown, array_keys($results));
		if (!empty($stillUnknown)) {
			$repos = !empty($limitToRepos) ? $limitToRepos : $this->getOrgRepos();
			foreach ($stillUnknown as $taskKey) {
				foreach ($repos as $repo) {
					$branch = $this->findBranch($repo, $taskKey);
					if ($branch !== null) {
						$results[$taskKey] = ['repo' => $repo, 'branch' => $branch];
						$cache[$taskKey] = $results[$taskKey];
						break;
					}
				}
			}
		}

		$this->saveBranchCache($cache);
		return $results;
	}

	public function branchExists(string $repo, string $branch): bool
	{
		try {
			$this->client->get("repos/{$repo}/branches/" . urlencode($branch));
			return true;
		} catch (ClientException) {
			return false;
		} catch (\Throwable) {
			return false;
		}
	}

	public function createPullRequest(string $repo, string $branch, string $title, string $jiraUrl = ''): PullRequest
	{
		$body = $jiraUrl !== ''
			? "Jira: {$jiraUrl}\n\nAuto-created by Deploy Dashboard"
			: "Auto-created by Deploy Dashboard\n\nTask: {$title}";

		$response = $this->client->post("repos/{$repo}/pulls", [
			'json' => [
				'title' => $title,
				'head' => $branch,
				'base' => 'master',
				'body' => $body,
			],
		]);

		$pr = ApiData::from(json_decode($response->getBody()->getContents(), true));

		return new PullRequest(
			number: $pr->int('number'),
			title: $pr->string('title'),
			state: $pr->string('state'),
			url: $pr->string('html_url'),
			mergeable: $pr->bool('mergeable', true),
			mergeableState: $pr->stringOrNull('mergeable_state') ?? 'unknown',
			draft: $pr->bool('draft'),
			ciStatus: 'pending',
			hasConflicts: false,
			reviewApprovals: 0,
		);
	}

	public function mergePullRequest(string $repo, int $prNumber): bool
	{
		try {
			$this->client->put("repos/{$repo}/pulls/{$prNumber}/merge", [
				'json' => ['merge_method' => 'merge'],
			]);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public function dispatchWorkflow(string $repo, string $workflow): ?int
	{
		$beforeId = $this->getLatestWorkflowRunId($repo, $workflow);

		$this->client->post("repos/{$repo}/actions/workflows/{$workflow}/dispatches", [
			'json' => ['ref' => 'master'],
		]);

		for ($i = 0; $i < 5; $i++) {
			sleep(2);
			$afterId = $this->getLatestWorkflowRunId($repo, $workflow);
			if ($afterId !== null && $afterId !== $beforeId) {
				return $afterId;
			}
		}

		return null;
	}

	/**
	 * @return array{status: string, conclusion: string|null, url: string}|null
	 */
	public function getWorkflowRunStatus(string $repo, int $runId): ?array
	{
		try {
			$run = $this->apiGet("repos/{$repo}/actions/runs/{$runId}");
			return [
				'status' => $run->string('status'),
				'conclusion' => $run->stringOrNull('conclusion'),
				'url' => $run->string('html_url'),
			];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param Task[] $tasks
	 * @return array<string, array{repo: string, branch: string, merged: bool, pr: PullRequest|null}>
	 */
	public function getStatusForKnownBranches(array $tasks): array
	{
		/** @var array<string, array{repo: string, branch: string, merged: bool, pr: PullRequest|null}> $results */
		$results = [];

		/** @var array<string, array{repo: string, branch: string}> $taskMap */
		$taskMap = [];
		foreach ($tasks as $task) {
			if ($task->repository !== null && $task->branchName !== null) {
				$taskMap[$task->key] = ['repo' => $task->repository, 'branch' => $task->branchName];
			}
		}

		if (empty($taskMap)) {
			return $results;
		}

		$taskKeys = array_keys($taskMap);

		foreach (array_chunk($taskKeys, 5) as $chunk) {
			$q = implode(' OR ', $chunk) . " org:{$this->org} is:pr base:master";
			try {
				$data = $this->apiGet('search/issues', ['q' => $q, 'per_page' => 50, 'sort' => 'updated', 'order' => 'desc']);
				foreach ($data->list('items') as $item) {
					$prUrl = $item->sub('pull_request')->string('url');
					if (!preg_match('#repos/([^/]+/[^/]+)/#', $prUrl, $m)) {
						continue;
					}

					$repo = $m[1];
					$prNumber = $item->int('number');

					try {
						$detail = $this->apiGet("repos/{$repo}/pulls/{$prNumber}");
					} catch (\Throwable) {
						continue;
					}

					$branch = $detail->sub('head')->string('ref');
					$baseBranch = $detail->sub('base')->string('ref');
					if ($branch === '' || $baseBranch !== 'master') {
						continue;
					}

					$matchedKey = $this->matchBranchToTaskKey($branch, $chunk);
					if ($matchedKey === null || isset($results[$matchedKey])) {
						continue;
					}

					$isMerged = $detail->notNull('merged_at');
					$isClosed = $detail->string('state') === 'closed';

					if ($isMerged) {
						$results[$matchedKey] = [
							'repo' => $repo,
							'branch' => $branch,
							'merged' => true,
							'pr' => null,
						];
					} elseif ($isClosed) {
						continue;
					} else {
						$results[$matchedKey] = [
							'repo' => $repo,
							'branch' => $branch,
							'merged' => false,
							'pr' => new PullRequest(
								number: $detail->int('number'),
								title: $detail->string('title'),
								state: $detail->string('state'),
								url: $detail->string('html_url'),
								mergeable: $detail->bool('mergeable'),
								mergeableState: $detail->stringOrNull('mergeable_state'),
								draft: $detail->bool('draft'),
								ciStatus: $this->getCiStatusFromChecks($repo, $detail->sub('head')->string('sha')),
								hasConflicts: $detail->string('mergeable_state') === 'dirty',
								reviewApprovals: 0,
							),
						];
					}
				}
			} catch (\Throwable) {
			}
		}

		$remaining = array_diff($taskKeys, array_keys($results));
		foreach ($remaining as $taskKey) {
			$info = $taskMap[$taskKey];
			$results[$taskKey] = [
				'repo' => $info['repo'],
				'branch' => $info['branch'],
				'merged' => false,
				'pr' => null,
			];
		}

		return $results;
	}

	/**
	 * @return array{id: int, status: string, conclusion: string|null, url: string, created_at: string}|null
	 */
	public function getLatestWorkflowRun(string $repo, string $workflow): ?array
	{
		try {
			$data = $this->apiGet("repos/{$repo}/actions/workflows/{$workflow}/runs", [
				'per_page' => 1,
				'branch' => 'master',
			]);
			$runs = $data->list('workflow_runs');
			if (empty($runs)) {
				return null;
			}

			$run = $runs[0];
			return [
				'id' => $run->int('id'),
				'status' => $run->string('status'),
				'conclusion' => $run->stringOrNull('conclusion'),
				'url' => $run->string('html_url'),
				'created_at' => $run->string('created_at'),
			];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param string[] $taskKeys
	 */
	private function matchBranchToTaskKey(string $branch, array $taskKeys): ?string
	{
		foreach ($taskKeys as $taskKey) {
			if (str_starts_with($branch, $taskKey . '/') || $branch === $taskKey) {
				return $taskKey;
			}
		}
		return null;
	}

	private function getCiStatusFromChecks(string $repo, string $sha): ?string
	{
		try {
			$data = $this->apiGet("repos/{$repo}/commits/{$sha}/check-runs", ['per_page' => 100]);
			$runs = $data->list('check_runs');

			if (empty($runs)) {
				return null;
			}

			$allComplete = true;
			foreach ($runs as $run) {
				if ($run->string('status') !== 'completed') {
					$allComplete = false;
				}
				if ($run->string('conclusion') === 'failure') {
					return 'failure';
				}
			}

			return $allComplete ? 'success' : 'pending';
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return array<string, array{repo: string, branch: string}>
	 */
	public function loadBranchCache(): array
	{
		$file = dirname(__DIR__, 2) . '/temp/cache/branch_map.json';
		if (!file_exists($file)) {
			return [];
		}
		$content = file_get_contents($file);
		if ($content === false) {
			return [];
		}
		$data = json_decode($content, true);
		/** @var array<string, array{repo: string, branch: string}> */
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string, array{repo: string, branch: string}> $cache
	 */
	private function saveBranchCache(array $cache): void
	{
		$dir = dirname(__DIR__, 2) . '/temp/cache';
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		file_put_contents($dir . '/branch_map.json', json_encode($cache));
	}

	public function clearBranchCache(): void
	{
		@unlink(dirname(__DIR__, 2) . '/temp/cache/branch_map.json');
	}

	/** @var string[]|null */
	private ?array $orgReposCache = null;

	/**
	 * @return string[]
	 */
	public function getOrgRepos(): array
	{
		if ($this->orgReposCache !== null) {
			return $this->orgReposCache;
		}

		/** @var string[] $repos */
		$repos = [];
		$page = 1;

		try {
			do {
				$data = $this->apiGet("orgs/{$this->org}/repos", [
					'per_page' => 100,
					'page' => $page,
					'type' => 'all',
				]);
				$items = $data->asList();
				foreach ($items as $repo) {
					if (!$repo->bool('archived')) {
						$repos[] = $repo->string('full_name');
					}
				}
				$page++;
			} while (count($items) === 100);
		} catch (\Throwable) {
		}

		$this->orgReposCache = $repos;
		return $repos;
	}

	private function getLatestWorkflowRunId(string $repo, string $workflow): ?int
	{
		try {
			$data = $this->apiGet("repos/{$repo}/actions/workflows/{$workflow}/runs", [
				'per_page' => 1,
				'branch' => 'master',
			]);
			$runs = $data->list('workflow_runs');
			return !empty($runs) ? $runs[0]->intOrNull('id') : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $query
	 */
	private function apiGet(string $uri, array $query = []): ApiData
	{
		$options = !empty($query) ? ['query' => $query] : [];
		$response = $this->client->get($uri, $options);
		return ApiData::from(json_decode($response->getBody()->getContents(), true));
	}
}
