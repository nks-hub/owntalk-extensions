<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Controller;

use OCA\OwnTalkExtensions\AppInfo\Application;
use OCA\OwnTalkExtensions\Db\RoomToolsRepository;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCA\OwnTalkExtensions\Service\AccessPolicy;
use OCA\OwnTalkExtensions\Service\Checklist;
use OCA\OwnTalkExtensions\Service\FollowupService;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\RoomAccessContext;
use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

final class RoomToolsController extends OCSController {
	public function __construct(IRequest $request, private IUserSession $users, private RoomAccess $rooms,
		private TalkOcsClient $talk, private AccessPolicy $policy, private RoomToolsRepository $tools,
		private ICommentsManager $comments, private ITimeFactory $time, private FollowupService $followups) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function withMessage(string $token, int $id, callable $action): DataResponse {
		$user = $this->users->getUser();
		if ($id < 1 || $user === null) {
			return new DataResponse([], 404);
		}
		$auth = $this->request->getHeader('Authorization');
		if ($auth === '') {
			$server = $this->request->server;
			if (isset($server['PHP_AUTH_USER'], $server['PHP_AUTH_PW']) && is_string($server['PHP_AUTH_USER']) && is_string($server['PHP_AUTH_PW'])) {
				$auth = 'Basic ' . base64_encode($server['PHP_AUTH_USER'] . ':' . $server['PHP_AUTH_PW']);
			}
		}
		try {
			$ctx = $this->rooms->resolve($user, $token, $auth, true);
			if ($ctx->roleSource !== RoomAccessContext::ROLE_SOURCE_TALK_OCS || $ctx->federated) {
				return new DataResponse([], 403);
			}
			$message = $this->talk->visibleMessage($token, $id, $auth);
			return $action($ctx, $message, $user->getUID());
		} catch (AccessException $e) {
			return new DataResponse([], $e->httpStatus());
		} catch (NotFoundException) {
			return new DataResponse([], 404);
		}
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/room/{token}/checklist/{id}')]
	public function checklist(string $token, int $id): DataResponse {
		return $this->check($token, $id, null, null, null);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/room/{token}/checklist/{id}')]
	public function setItem(string $token, int $id, string $revision, int $item, bool $checked): DataResponse {
		return $this->check($token, $id, $revision, $item, $checked);
	}

	private function check(string $token, int $id, ?string $revision, ?int $item, ?bool $checked): DataResponse {
		return $this->withMessage($token, $id, function ($ctx, $message) use ($token, $id, $revision, $item, $checked) {
			$list = Checklist::parse($message['message'] ?? '');
			if ($list === null) {
				return new DataResponse([], 404);
			}
			$editable = $this->policy->canEditShared($ctx, AccessPolicy::POLICY_CHAT, true);
			if ($item !== null) {
				if (!$editable) {
					return new DataResponse([], 403);
				}
				if ($revision !== $list['revision']) {
					return new DataResponse([], 409);
				}
				if (!isset($list['items'][$item])) {
					return new DataResponse([], 400);
				}
				$this->tools->setChecked($token, $id, $list['items'][$item], $checked);
			}
			return new DataResponse(['revision' => $list['revision'], 'canEdit' => $editable,
				'checked' => $this->tools->checked($token, $id, $list['items'])]);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/room/{token}/followup/{id}')]
	public function followup(string $token, int $id): DataResponse {
		return $this->withMessage($token, $id, function ($ctx, $message, $owner) use ($token, $id) {
			$row = $this->tools->reminder($owner, $token, $id);
			if ($row === null) {
				return new DataResponse(['dueAt' => null]);
			}
			$due = (int)$row['due_at'];
			if ($this->followups->hasReply((string)$row['room_id'], $id, (int)$row['thread_id'], $owner, min($due, $this->time->getTime()))) {
				$this->tools->cancel($owner, $token, $id, $due);
				$row = $this->tools->reminder($owner, $token, $id);
			}
			return new DataResponse(['dueAt' => $row === null ? null : (int)$row['due_at']]);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/room/{token}/followup/{id}')]
	public function schedule(string $token, int $id, int $dueAt, int $threadId = 0): DataResponse {
		return $this->withMessage($token, $id, function ($ctx, $message, $owner) use ($token, $id, $dueAt, $threadId) {
			if (($message['actorType'] ?? '') !== 'users' || ($message['actorId'] ?? '') !== $owner) {
				return new DataResponse([], 403);
			}
			$comment = $this->comments->get((string)$id);
			$actualThread = ($message['isThread'] ?? false) === true ? (int)($message['threadId'] ?? 0) : 0;
			if ($comment->getObjectType() !== 'chat' || $threadId !== $actualThread || ($threadId === 0 && $ctx->roomType !== 1)) {
				return new DataResponse([], 400);
			}
			$now = $this->time->getTime();
			if ($dueAt <= $now || $dueAt > $now + 366 * 86400) {
				return new DataResponse([], 400);
			}
			if ($this->followups->hasReply((string)$comment->getObjectId(), $id, $threadId, $owner, $now)) {
				$this->tools->cancel($owner, $token, $id);
				return new DataResponse(['dueAt' => null]);
			}
			$this->tools->schedule($owner, $token, $id, (int)$comment->getObjectId(), $threadId, $dueAt);
			return new DataResponse(['dueAt' => $dueAt]);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/room/{token}/followup/{id}')]
	public function cancel(string $token, int $id): DataResponse {
		return $this->withMessage($token, $id, function ($ctx, $message, $owner) use ($token, $id) {
			$this->tools->cancel($owner, $token, $id);
			return new DataResponse(['dueAt' => null]);
		});
	}
}
