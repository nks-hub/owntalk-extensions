<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Exception;

final class RoomToolException extends \RuntimeException {
	public function __construct(public readonly int $status) {
		parent::__construct('Room tool request rejected', $status);
	}
}
