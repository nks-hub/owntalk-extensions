<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Service;

use OCA\OwnTalkExtensions\Db\RoomDocuments;
use OCA\OwnTalkExtensions\Exception\RoomToolException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

final class PhotoNotes {
	public function __construct(private RoomDocuments $documents, private PhotoFileAccess $files) {
	}

	public function version(string $owner, array $message, int $fileId, ?string $expected): string {
		$linked = false;
		foreach (($message['messageParameters'] ?? []) as $param) {
			if (is_array($param) && ($param['type'] ?? '') === 'file' && (string)($param['id'] ?? '') === (string)$fileId) {
				$linked = true;
				break;
			}
		}
		if (!$linked || $fileId < 1) {
			throw new RoomToolException(404);
		}
		try {
			$file = $this->files->forUser($owner, $fileId);
			if (!$file instanceof File || !$file->isReadable() || !str_starts_with($file->getMimetype(), 'image/')) {
				throw new RoomToolException(404);
			}
			$etag = self::unquote((string)$file->getEtag());
		} catch (NotFoundException|NotPermittedException) {
			throw new RoomToolException(404);
		}
		if ($etag === '' || ($expected !== null && self::unquote($expected) !== $etag)) {
			throw new RoomToolException(409);
		}
		return $etag;
	}

	private static function unquote(string $etag): string {
		return strlen($etag) >= 2 && $etag[0] === '"' && str_ends_with($etag, '"') ? substr($etag, 1, -1) : $etag;
	}

	public function notes(string $scope): array {
		return array_values($this->documents->read($scope));
	}

	public function write(string $scope, string $author, string $id, string $body, ?string $parent, ?float $x, ?float $y): array {
		PersonalRoomDocuments::uuid($id);
		PersonalRoomDocuments::text($body, 2000);
		if ($parent === null) {
			foreach ([$x, $y] as $p) {
				if ($p === null || !is_finite($p) || $p < 0 || $p > 1) {
					throw new RoomToolException(400);
				}
			}
		} else {
			PersonalRoomDocuments::uuid($parent);
		}
		$input = compact('author', 'id', 'body', 'parent', 'x', 'y');
		return array_values($this->documents->change($scope, null, $id, $input,
			static function ($notes) use ($author, $id, $body, $parent, $x, $y) {
				$total = count($notes);
				foreach ($notes as $note) {
					$total += count($note['replies']);
				}
				if ($total >= 256) {
					throw new RoomToolException(422);
				}
				$entry = compact('id', 'author', 'body');
				if ($parent !== null) {
					if (!isset($notes[$parent])) {
						throw new RoomToolException(404);
					}
					if (count($notes[$parent]['replies']) >= 100) {
						throw new RoomToolException(422);
					}
					$notes[$parent]['replies'][] = $entry;
				} else {
					if (count($notes) >= 100) {
						throw new RoomToolException(422);
					}
					$notes[$id] = $entry + ['x' => $x, 'y' => $y, 'replies' => []];
				}
				return $notes;
			}));
	}
}
