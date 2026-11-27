<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Service;

use OCA\OwnTalkExtensions\Db\RoomDocuments;
use OCA\OwnTalkExtensions\Exception\RoomToolException;

final class PersonalRoomDocuments {
	public function __construct(private RoomDocuments $documents) {
	}

	public static function uuid(string $id): void {
		if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id)) {
			throw new RoomToolException(400);
		}
	}

	public static function text(string $text, int $max): void {
		if (!preg_match('/[^\s\x{feff}]/u', $text) || mb_strlen($text, 'UTF-8') > $max) {
			throw new RoomToolException(400);
		}
	}

	public function gifs(string $owner): array {
		return ['items' => array_values($this->documents->read(RoomDocuments::key('gifs', $owner)))];
	}

	public function favorite(string $owner, string $provider, string $id, string $operationId, ?array $metadata): array {
		self::uuid($operationId);
		if ($provider !== 'giphy' || !preg_match('/^[A-Za-z0-9]{1,100}$/D', $id)) {
			throw new RoomToolException(400);
		}
		foreach ($metadata ?? [] as $label) {
			if (mb_strlen($label, 'UTF-8') > 200) {
				throw new RoomToolException(400);
			}
		}
		$key = $provider . ':' . $id;
		$result = $this->documents->change(RoomDocuments::key('gifs', $owner), $owner, $operationId,
			[$key, $metadata], static function (array $items) use ($key, $id, $provider, $metadata) {
				unset($items[$key]);
				if ($metadata === null) {
					return $items;
				}
				if (count($items) >= 500) {
					throw new RoomToolException(409);
				}
				return [$key => ['provider' => $provider, 'id' => $id] + $metadata] + $items;
			});
		return ['saved' => isset($result[$key])];
	}

	public function drafts(string $owner, string $token, int $thread): array {
		return array_values($this->documents->read(RoomDocuments::key('drafts', $owner, $token, (string)$thread)));
	}

	public function writeDraft(string $owner, string $token, int $thread, string $id, ?string $baseId, string $text, ?int $replyTo): array {
		self::uuid($id);
		self::text($text, 32000);
		if ($baseId !== null) {
			self::uuid($baseId);
		}
		if ($baseId === $id || ($replyTo !== null && $replyTo < 1)) {
			throw new RoomToolException(400);
		}
		$scope = RoomDocuments::key('drafts', $owner, $token, (string)$thread);
		$draft = compact('id', 'baseId', 'text', 'replyTo');
		return array_values($this->documents->change($scope, $owner, $id, $draft, function ($variants) use ($scope, $baseId, $id, $draft) {
			if ($baseId !== null) {
				if ($this->documents->operation($scope, $baseId) === null) {
					throw new RoomToolException(409);
				}
				unset($variants[$baseId]);
			}
			if (count($variants) >= 32) {
				throw new RoomToolException(409);
			}
			return [$id => $draft] + $variants;
		}));
	}

	public function deleteDraft(string $owner, string $token, int $thread, string $id): array {
		self::uuid($id);
		$scope = RoomDocuments::key('drafts', $owner, $token, (string)$thread);
		return array_values($this->documents->change($scope,
			$owner, null, [], function ($drafts) use ($id, $scope, $owner) {
				$this->documents->retireDraft($scope, $owner, $id);
				unset($drafts[$id]);
				return $drafts;
			}));
	}
}
