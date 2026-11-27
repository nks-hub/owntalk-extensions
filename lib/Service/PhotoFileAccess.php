<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;

class PhotoFileAccess {
	public function __construct(private IRootFolder $root) {
	}

	public function forUser(string $owner, int $id): ?File {
		$node = $this->root->getUserFolder($owner)->getFirstNodeById($id);
		return $node instanceof File ? $node : null;
	}
}
