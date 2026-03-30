<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;

class Bootstrap
{
	public static function boot(): Configurator
	{
		ini_set('max_execution_time', 120);
		// Load .env
		$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
		$dotenv->safeLoad();

		$configurator = new Configurator();
		$appDir = dirname(__DIR__);

		$configurator->setDebugMode(true);
		$configurator->enableTracy($appDir . '/log');
		$configurator->setTempDirectory($appDir . '/temp');

		$configurator->addDynamicParameters([
			'env' => [
				'JIRA_URL' => $_ENV['JIRA_URL'] ?? $_SERVER['JIRA_URL'] ?? '',
				'JIRA_EMAIL' => $_ENV['JIRA_EMAIL'] ?? $_SERVER['JIRA_EMAIL'] ?? '',
				'JIRA_API_TOKEN' => $_ENV['JIRA_API_TOKEN'] ?? $_SERVER['JIRA_API_TOKEN'] ?? '',
				'GITHUB_TOKEN' => $_ENV['GITHUB_TOKEN'] ?? $_SERVER['GITHUB_TOKEN'] ?? '',
				'GITHUB_ORG' => $_ENV['GITHUB_ORG'] ?? $_SERVER['GITHUB_ORG'] ?? '',
			],
		]);

		$configurator->createRobotLoader()
			->addDirectory(__DIR__)
			->register();

		$configurator->addConfig($appDir . '/app/config/common.neon');
		$configurator->addConfig($appDir . '/app/config/services.neon');
		$configurator->addConfig($appDir . '/app/config/local.neon');

		return $configurator;
	}
}
