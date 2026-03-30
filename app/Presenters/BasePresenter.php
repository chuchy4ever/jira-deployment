<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Latte\ViteExtension;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\Template;

abstract class BasePresenter extends Presenter
{
	private ViteExtension $viteExtension;

	public function injectViteExtension(ViteExtension $viteExtension): void
	{
		$this->viteExtension = $viteExtension;
	}

	public function beforeRender(): void
	{
		parent::beforeRender();
		$template = $this->getTemplate();
		if ($template instanceof Template) {
			$template->getLatte()->addExtension($this->viteExtension);
		}
	}
}
