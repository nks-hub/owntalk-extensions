<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Controller;

use OCA\OwnTalkExtensions\AppInfo\Application;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCA\OwnTalkExtensions\Exception\ConflictException;
use OCA\OwnTalkExtensions\Exception\ImageException;
use OCA\OwnTalkExtensions\Service\AccessPolicy;
use OCA\OwnTalkExtensions\Service\BackgroundImageService;
use OCA\OwnTalkExtensions\Service\FieldRegistry;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\RoomAccessContext;
use OCA\OwnTalkExtensions\Service\SettingsService;
use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
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
		private IAppConfig $appConfig,
		private BackgroundImageService $images,
		private TalkOcsClient $talk,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[BruteForceProtection(action: 'owntalkRoomToken')]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/room/{token}/settings')]
	public function get(string $token): DataResponse {
		try {
			$ctx = $this->context($token);
		} catch (AccessException $e) {
			return $this->accessError($e);
		}

		$document = $this->document($ctx, $this->settings->get($token));
		// Permissions are per caller and part of the answer, so they are part of the ETag.
		$etag = 'r' . $document['revision'] . '-e' . ($document['permissions']['canEdit'] ? '1' : '0');
		if ($this->stripWeak($this->request->getHeader('If-None-Match')) === '"' . $etag . '"') {
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
			$ctx = $this->context($token, true);
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

		if (!$this->policy->canEditShared($ctx, $this->editPolicy(), $this->talk->origin() !== null)) {
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

		$base = $this->baseRevision();
		if ($base === null) {
			return $this->error('precondition_required', Http::STATUS_PRECONDITION_REQUIRED);
		}
		return $this->save($ctx, $base, $patch);
	}

	/**
	 * Stores the raw request body as the conversation background image.
	 */
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'owntalkRoomToken')]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/room/{token}/settings/image')]
	public function uploadImage(string $token): DataResponse {
		try {
			$ctx = $this->context($token, true);
		} catch (AccessException $e) {
			return $this->accessError($e);
		}
		if (!$this->fields->isKnown('chat.backgroundImage')) {
			return $this->error('unknown_field', Http::STATUS_BAD_REQUEST);
		}
		if (!$this->policy->canEditShared($ctx, $this->editPolicy(), $this->talk->origin() !== null)) {
			return $this->error('field_forbidden', Http::STATUS_FORBIDDEN);
		}
		$base = $this->baseRevision();
		if ($base === null) {
			return $this->error('precondition_required', Http::STATUS_PRECONDITION_REQUIRED);
		}
		$type = strtolower(trim(explode(';', $this->request->getHeader('Content-Type'))[0]));
		if (!\in_array($type, BackgroundImageService::acceptedTypes(), true)) {
			return $this->error(ImageException::UNSUPPORTED, Http::STATUS_UNSUPPORTED_MEDIA_TYPE);
		}
		$length = $this->request->getHeader('Content-Length');
		if ($length !== '' && (int)$length > BackgroundImageService::MAX_BYTES) {
			return $this->error(ImageException::TOO_LARGE, Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
		$raw = file_get_contents('php://input', false, null, 0, BackgroundImageService::MAX_BYTES + 1);
		if (!\is_string($raw) || $raw === '') {
			return $this->error(ImageException::INVALID, Http::STATUS_BAD_REQUEST);
		}
		try {
			$sha = $this->images->store($raw, $type);
		} catch (ImageException $e) {
			return $this->error($e->reason, $e->httpStatus());
		}
		return $this->save($ctx, $base, ['chat.backgroundImage' => $sha]);
	}

	/**
	 * The conversation's current background image. Any other stored image is a 404 here,
	 * even one another conversation of the caller uses.
	 */
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'owntalkRoomToken')]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/room/{token}/settings/image/{sha}')]
	public function image(string $token, string $sha): Response {
		try {
			$this->context($token);
		} catch (AccessException $e) {
			return $this->accessError($e);
		}
		$current = $this->settings->get($token)['settings']['chat.backgroundImage'] ?? null;
		$file = $current === $sha ? $this->images->find($sha) : null;
		if ($file === null) {
			return $this->error('not_found', Http::STATUS_NOT_FOUND);
		}
		$response = new FileDisplayResponse($file, Http::STATUS_OK, [
			'Content-Type' => 'image/jpeg',
			'X-Content-Type-Options' => 'nosniff',
		]);
		$response->setETag($sha);
		$response->cacheFor(31536000, false, true);
		return $response;
	}

	private function baseRevision(): ?int {
		if (!preg_match('/^"r(0|[1-9][0-9]{0,17})"$/D', $this->stripWeak($this->request->getHeader('If-Match')), $m)) {
			return null;
		}
		return (int)$m[1];
	}

	/**
	 * @param array<string, ?string> $patch
	 */
	private function save(RoomAccessContext $ctx, int $base, array $patch): DataResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$saved = $this->settings->patch($ctx->token, $base, $patch, $userId);
		} catch (ConflictException $e) {
			return $this->error('revision_conflict', Http::STATUS_PRECONDITION_FAILED, [
				'current' => $this->document($ctx, $e->current),
				'fields' => $e->fields,
			]);
		}

		$document = $this->document($ctx, $saved);
		$response = new DataResponse($document);
		$response->setETag('r' . $saved['revision'] . '-e' . ($document['permissions']['canEdit'] ? '1' : '0'));
		return $response;
	}

	/**
	 * @throws AccessException
	 */
	private function context(string $token, bool $fresh = false): RoomAccessContext {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new AccessException(AccessException::NOT_FOUND);
		}
		return $this->roomAccess->resolve($user, $token, $this->authorization(), $fresh);
	}

	/**
	 * The caller's Authorization header. Some Apache setups expose Basic credentials only as
	 * PHP_AUTH_USER/PHP_AUTH_PW, so the header is rebuilt from those when it is missing.
	 */
	private function authorization(): ?string {
		$header = $this->request->getHeader('Authorization');
		if ($header !== '') {
			return $header;
		}
		$server = $this->request->server;
		if (isset($server['PHP_AUTH_USER'], $server['PHP_AUTH_PW']) && \is_string($server['PHP_AUTH_USER']) && \is_string($server['PHP_AUTH_PW'])) {
			return 'Basic ' . base64_encode($server['PHP_AUTH_USER'] . ':' . $server['PHP_AUTH_PW']);
		}
		return null;
	}

	/** A proxy that compresses the response turns the ETag weak; the revision inside is still exact. */
	private function stripWeak(string $value): string {
		$value = trim($value);
		return str_starts_with($value, 'W/') ? substr($value, 2) : $value;
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
				'canEdit' => $this->policy->canEditShared($ctx, $this->editPolicy(), $this->talk->origin() !== null),
				'aclSource' => $ctx->roleSource,
			],
			'updatedBy' => $doc['updatedBy'],
			'updatedAt' => $doc['updatedAt'],
		];
	}

	private function editPolicy(): string {
		$policy = $this->appConfig->getValueString(Application::APP_ID, 'edit_policy', AccessPolicy::POLICY_MODERATORS);
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
