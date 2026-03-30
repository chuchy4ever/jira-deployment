<?php

declare(strict_types=1);

namespace App\Model;

use App\Model\Entity\Task;
use GuzzleHttp\Client;

final class JiraService
{
	private Client $client;

	public function __construct(
		private readonly string $url,
		private readonly string $email,
		private readonly string $token,
		private readonly string $jql,
		private readonly string $doneStatus,
	) {
		$this->client = new Client([
			'base_uri' => rtrim($this->url, '/') . '/',
			'auth' => [$this->email, $this->token],
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
			'connect_timeout' => 10,
			'timeout' => 30,
		]);
	}

	/**
	 * @return Task[]
	 */
	public function getTasksForRelease(): array
	{
		$data = $this->apiGet('rest/api/3/search/jql', [
			'jql' => $this->jql,
			'maxResults' => 100,
			'fields' => 'summary,status,project,assignee,fixVersions',
		]);

		$tasks = [];
		foreach ($data->list('issues') as $issue) {
			$fields = $issue->sub('fields');
			$fixVersions = $fields->list('fixVersions');
			$lastVersion = !empty($fixVersions) ? end($fixVersions) : null;
			$fixVersion = $lastVersion?->string('name') ?: null;

			$issueKey = $issue->string('key');
			$tasks[] = new Task(
				key: $issueKey,
				summary: $fields->string('summary'),
				status: $fields->sub('status')->string('name'),
				projectKey: $fields->sub('project')->string('key'),
				assignee: $fields->sub('assignee')->stringOrNull('displayName'),
				url: rtrim($this->url, '/') . '/browse/' . $issueKey,
				fixVersion: $fixVersion,
			);
		}

		return $tasks;
	}

	/**
	 * @param string[] $issueKeys
	 * @return array{id: string, name: string, url: string}
	 */
	public function createRelease(string $projectKey, string $name, array $issueKeys): array
	{
		$versions = $this->apiGet("rest/api/3/project/{$projectKey}/versions");

		$versionId = null;
		foreach ($versions->asList() as $v) {
			if ($v->string('name') === $name) {
				$versionId = $v->string('id');
				break;
			}
		}

		if ($versionId === null) {
			$project = $this->apiGet("rest/api/3/project/{$projectKey}");

			$response = $this->client->post('rest/api/3/version', [
				'json' => [
					'name' => $name,
					'projectId' => $project->int('id'),
					'released' => false,
					'releaseDate' => date('Y-m-d'),
				],
			]);
			$version = ApiData::from(json_decode($response->getBody()->getContents(), true));
			$versionId = $version->string('id');
		}

		foreach ($issueKeys as $issueKey) {
			try {
				$this->client->put("rest/api/3/issue/{$issueKey}", [
					'json' => [
						'update' => [
							'fixVersions' => [
								['add' => ['id' => $versionId]],
							],
						],
					],
				]);
			} catch (\Throwable) {
			}
		}

		return [
			'id' => $versionId,
			'name' => $name,
			'url' => rtrim($this->url, '/') . '/projects/' . $projectKey . '/versions/' . $versionId,
		];
	}

	/**
	 * @param Task[] $tasks
	 * @return array<string, string>
	 */
	public function findVersionIds(array $tasks): array
	{
		$versionIds = [];
		$checkedProjects = [];

		foreach ($tasks as $task) {
			if ($task->fixVersion === null || isset($checkedProjects[$task->projectKey])) {
				continue;
			}
			$checkedProjects[$task->projectKey] = true;

			try {
				$versions = $this->apiGet("rest/api/3/project/{$task->projectKey}/versions");
				foreach ($versions->asList() as $v) {
					if ($v->string('name') === $task->fixVersion && !$v->bool('released')) {
						$versionIds[$task->projectKey] = $v->string('id');
						break;
					}
				}
			} catch (\Throwable) {
			}
		}

		return $versionIds;
	}

	public function releaseVersion(string $versionId): bool
	{
		try {
			$this->client->put("rest/api/3/version/{$versionId}", [
				'json' => [
					'released' => true,
					'releaseDate' => date('Y-m-d'),
				],
			]);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public function transitionToDone(string $issueKey): bool
	{
		try {
			$data = $this->apiGet("rest/api/3/issue/{$issueKey}/transitions");

			$transitionId = null;
			foreach ($data->list('transitions') as $transition) {
				if (strcasecmp($transition->sub('to')->string('name'), $this->doneStatus) === 0) {
					$transitionId = $transition->string('id');
					break;
				}
			}

			if ($transitionId === null) {
				return false;
			}

			$this->client->post("rest/api/3/issue/{$issueKey}/transitions", [
				'json' => [
					'transition' => ['id' => $transitionId],
				],
			]);

			return true;
		} catch (\Throwable) {
			return false;
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
