<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Controller;

use OCA\OwnTalkExtensions\AppInfo\Application;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCA\OwnTalkExtensions\Exception\ConflictException;
use OCA\OwnTalkExtensions\Service\AccessPolicy;
use OCA\OwnTalkExtensions\Service\FieldRegistry;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\RoomAccessContext;
use OCA\OwnTalkExtensions\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

final class SettingsController extends OCSController {
	private const MAX_BODY_BYTES = 8192;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private RoomAccess $roomAccess,
		private AccessPolicy $policy,
		private FieldRegistry $fields,
		private SettingsService $settings,
		private IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[BruteForceProtection(action: 'owntalkRoomToken')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/room/{token}/settings')]
	public function get(string $token): DataResponse {
		try {
			$ctx = $this->context($token);
		} catch (AccessException $e) {
			return $this->accessError($e);
		}

		$document = $this->document($ctx, $this->settings->get($token));
		$etag = 'r' . $document['revision'];
		if (trim((string)$this->request->getHeader('If-None-Match')) === '"' . $etag . '"') {
			$response = new DataResponse([], Http::STATUS_NOT_MODIFIED);
		} else {
			$response = new DataResponse($document);
		}
		$response->setETag($etag);
		return $response;
	}

	#[NoAdminRequired]
	#[BruteForceProtection(action: 'owntalkRoomToken')]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/room/{token}/settings')]
	public function patch(string $token): DataResponse {
		try {
			$ctx = $this->context($token);
		} catch (AccessException $e) {
			return $this->accessError($e);
		}

		$raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
		if (!\is_string($raw) || \strlen($raw) > self::MAX_BODY_BYTES) {
			return $this->error('payload_too_large', Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
		try {
			$body = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return $this->error('invalid_json', Http::STATUS_BAD_REQUEST);
		}
		if (!\is_array($body) || array_keys($body) !== ['settings'] || !\is_array($body['settings']) || $body['settings'] === []
			|| array_is_list($body['settings'])) {
			return $this->error('invalid_json', Http::STATUS_BAD_REQUEST);
		}
		foreach (array_keys($body['settings']) as $field) {
			if (!\is_string($field) || !$this->fields->isKnown($field)) {
				return $this->error('unknown_field', Http::STATUS_BAD_REQUEST);
			}
		}

		if (!$this->policy->canEditShared($ctx, $this->editPolicy())) {
			return $this->error('field_forbidden', Http::STATUS_FORBIDDEN);
		}

		$patch = [];
		foreach ($body['settings'] as $field => $value) {
			try {
				$patch[$field] = $this->fields->normalize($field, $value);
			} catch (\InvalidArgumentException) {
				return $this->error('invalid_value', Http::STATUS_BAD_REQUEST, ['field' => $field]);
			}
		}

		if (!preg_match('/^"r(0|[1-9][0-9]{0,17})"$/', trim((string)$this->request->getHeader('If-Match')), $m)) {
			return $this->error('precondition_required', Http::STATUS_PRECONDITION_REQUIRED);
		}

		$userId = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$saved = $this->settings->patch($token, (int)$m[1], $patch, $userId);
		} catch (ConflictException $e) {
			return $this->error('revision_conflict', Http::STATUS_PRECONDITION_FAILED, [
				'current' => $this->document($ctx, $e->current),
				'fields' => $e->fields,
			]);
		}

		$response = new DataResponse($this->document($ctx, $saved));
		$response->setETag('r' . $saved['revision']);
		return $response;
	}

	/**
	 * @throws AccessException
	 */
	private function context(string $token): RoomAccessContext {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new AccessException(AccessException::NOT_FOUND);
		}
		$authorization = $this->request->getHeader('Authorization');
		return $this->roomAccess->resolve($user, $token, $authorization === '' ? null : $authorization);
	}

	/**
	 * @param array<string, mixed> $doc
	 * @return array<string, mixed>
	 */
	private function document(RoomAccessContext $ctx, array $doc): array {
		return [
			'schemaVersion' => 1,
			'roomToken' => $ctx->token,
			'revision' => $doc['revision'],
			'settings' => $doc['settings'],
			'fieldRevisions' => (object)$doc['fieldRevisions'],
			'permissions' => [
				'canEdit' => $this->policy->canEditShared($ctx, $this->editPolicy()),
				'aclSource' => $ctx->roleSource,
			],
			'updatedBy' => $doc['updatedBy'],
			'updatedAt' => $doc['updatedAt'],
		];
	}

	private function editPolicy(): string {
		$policy = $this->config->getAppValue(Application::APP_ID, 'edit_policy', AccessPolicy::POLICY_MODERATORS);
		return $policy === AccessPolicy::POLICY_CHAT ? AccessPolicy::POLICY_CHAT : AccessPolicy::POLICY_MODERATORS;
	}

	private function accessError(AccessException $e): DataResponse {
		$response = $this->error($e->reason, $e->httpStatus());
		if ($e->reason === AccessException::NOT_FOUND) {
			$response->throttle(['action' => 'owntalkRoomToken']);
		}
		return $response;
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function error(string $code, int $status, array $extra = []): DataResponse {
		return new DataResponse(['error' => $code] + $extra, $status);
	}
}
