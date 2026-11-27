<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Controller;

use OCA\OwnTalkExtensions\AppInfo\Application;
use OCA\OwnTalkExtensions\Db\RoomDocuments;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCA\OwnTalkExtensions\Exception\RoomToolException;
use OCA\OwnTalkExtensions\Service\AccessPolicy;
use OCA\OwnTalkExtensions\Service\PersonalRoomDocuments;
use OCA\OwnTalkExtensions\Service\PhotoNotes;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\RoomAccessContext;
use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

final class RoomDocumentController extends OCSController {
	public function __construct(IRequest $request, private IUserSession $users, private RoomAccess $rooms,
		private TalkOcsClient $talk, private AccessPolicy $policy, private PersonalRoomDocuments $personal, private PhotoNotes $photos) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function request(?string $token, callable $action): DataResponse {
		$user = $this->users->getUser();
		if ($user === null) {
			return new DataResponse([], 401);
		}
		try {
			if ($token === null) {
				if (!$this->rooms->isTalkAvailable($user)) {
					throw new RoomToolException(403);
				}
				return new DataResponse($action($user->getUID()));
			}
			$auth = $this->request->getHeader('Authorization');
			if ($auth === '') {
				$server = $this->request->server;
				if (is_string($server['PHP_AUTH_USER'] ?? null) && is_string($server['PHP_AUTH_PW'] ?? null)) {
					$auth = 'Basic ' . base64_encode($server['PHP_AUTH_USER'] . ':' . $server['PHP_AUTH_PW']);
				}
			}
			$ctx = $this->rooms->resolve($user, $token, $auth, true);
			if ($ctx->roleSource !== RoomAccessContext::ROLE_SOURCE_TALK_OCS || $ctx->federated) {
				return new DataResponse([], 403);
			}
			return new DataResponse($action($user->getUID(), $auth, $ctx));
		} catch (RoomToolException $e) {
			return new DataResponse([], $e->status);
		} catch (AccessException $e) {
			return new DataResponse([], $e->httpStatus());
		}
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/personal/gifs')]
	public function gifs(): DataResponse {
		return $this->request(null, fn ($owner) => $this->personal->gifs($owner));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/personal/gifs/{provider}/{id}')]
	public function saveGif(string $provider, string $id, string $operationId, string $title = '', string $subline = ''): DataResponse {
		return $this->request(null, fn ($owner) => $this->personal->favorite($owner, $provider, $id, $operationId,
			['title' => $title, 'subline' => $subline]));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/personal/gifs/{provider}/{id}')]
	public function deleteGif(string $provider, string $id, string $operationId): DataResponse {
		return $this->request(null, fn ($owner) => $this->personal->favorite($owner, $provider, $id, $operationId, null));
	}

	private function inDraftScope(string $token, int $threadId, callable $action): DataResponse {
		return $this->request($token, function ($owner, $auth) use ($token, $threadId, $action) {
			if ($threadId < 0) {
				throw new RoomToolException(400);
			}
			if ($threadId > 0) {
				$message = $this->talk->contextMessage($token, $threadId, $auth);
				if (($message['isThread'] ?? false) !== true || ($message['threadId'] ?? 0) !== $threadId) {
					throw new RoomToolException(404);
				}
			}
			$variants = $action($owner, $auth);
			$visible = [];
			foreach ($variants as &$draft) {
				$quote = $draft['replyTo'];
				if ($quote === null) {
					continue;
				}
				if (!array_key_exists($quote, $visible)) {
					try {
						$this->quote($token, $auth, $threadId, $quote);
						$visible[$quote] = true;
					} catch (AccessException $e) {
						if ($e->httpStatus() !== 404) {
							throw $e;
						}
						$visible[$quote] = false;
					}
				}
				if (!$visible[$quote]) {
					$draft['replyTo'] = null;
				}
			}
			return ['variants' => $variants];
		});
	}

	private function quote(string $token, string $auth, int $threadId, int $id): void {
		$message = $this->talk->contextMessage($token, $id, $auth);
		if ($threadId > 0 && ($message['threadId'] ?? 0) !== $threadId) {
			throw new AccessException(AccessException::NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/room/{token}/drafts/{threadId}', requirements: ['threadId' => '[0-9]+'])]
	public function drafts(string $token, int $threadId): DataResponse {
		return $this->inDraftScope($token, $threadId, fn ($owner) => $this->personal->drafts($owner, $token, $threadId));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/room/{token}/drafts/{threadId}/{id}', requirements: ['threadId' => '[0-9]+'])]
	public function writeDraft(string $token, int $threadId, string $id, string $text, ?string $baseId = null, ?int $replyTo = null): DataResponse {
		return $this->inDraftScope($token, $threadId, function ($owner, $auth) use ($token, $threadId, $id, $baseId, $text, $replyTo) {
			if ($replyTo !== null) {
				$this->quote($token, $auth, $threadId, $replyTo);
			}
			return $this->personal->writeDraft($owner, $token, $threadId, $id, $baseId, $text, $replyTo);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/room/{token}/drafts/{threadId}/{id}', requirements: ['threadId' => '[0-9]+'])]
	public function deleteDraft(string $token, int $threadId, string $id): DataResponse {
		return $this->inDraftScope($token, $threadId, fn ($owner) => $this->personal->deleteDraft($owner, $token, $threadId, $id));
	}

	private function photo(string $token, int $messageId, int $fileId, ?string $version, ?callable $write = null): DataResponse {
		return $this->request($token, function ($owner, $auth, $ctx) use ($token, $messageId, $fileId, $version, $write) {
			$message = $this->talk->contextMessage($token, $messageId, $auth);
			$current = $this->photos->version($owner, $message, $fileId, $version);
			$scope = RoomDocuments::key('photo', $token, (string)$messageId, (string)$fileId, $current);
			$canEdit = $this->policy->canEditShared($ctx, AccessPolicy::POLICY_CHAT, true);
			if ($write !== null && !$canEdit) {
				throw new RoomToolException(403);
			}
			$notes = $write === null ? $this->photos->notes($scope) : $write($scope, $owner);
			return ['version' => $current, 'canEdit' => $canEdit, 'notes' => $notes];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/room/{token}/photo/{messageId}/{fileId}/notes')]
	public function photoNotes(string $token, int $messageId, int $fileId, ?string $version = null): DataResponse {
		return $this->photo($token, $messageId, $fileId, $version);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/room/{token}/photo/{messageId}/{fileId}/notes')]
	public function addNote(string $token, int $messageId, int $fileId, string $version, string $requestId, mixed $x, mixed $y, string $body): DataResponse {
		return $this->photo($token, $messageId, $fileId, $version,
			function ($scope, $owner) use ($requestId, $body, $x, $y) {
				if (!is_numeric($x) || !is_numeric($y)) {
					throw new RoomToolException(400);
				}
				return $this->photos->write($scope, $owner, $requestId, $body, null, (float)$x, (float)$y);
			});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/room/{token}/photo/{messageId}/{fileId}/notes/{noteId}/replies')]
	public function reply(string $token, int $messageId, int $fileId, string $noteId, string $version, string $requestId, string $body): DataResponse {
		return $this->photo($token, $messageId, $fileId, $version,
			fn ($scope, $owner) => $this->photos->write($scope, $owner, $requestId, $body, $noteId, null, null));
	}
}
