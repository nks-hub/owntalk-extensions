<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Service;

use OCA\OwnTalkExtensions\Db\RoomToolsRepository;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Notification\IManager;

final class FollowupService {
	public function __construct(private RoomToolsRepository $tools, private ICommentsManager $comments,
		private RoomAccess $rooms, private IUserManager $users, private IManager $notifications, private IDBConnection $db,
		private ITimeFactory $clock) {
	}

	public function hasReply(string $room, int $id, int $thread, string $owner, int $deadline): bool {
		$cursor = $id;
		do {
			$previous = $cursor;
			$batch = $this->comments->getCommentsWithVerbForObjectSinceComment('chat', $room,
				['comment', 'object_shared', 'voice-message'], $cursor, 'asc', 100, false, $thread > 0 ? (string)$thread : '');
			foreach ($batch as $reply) {
				if ($reply->getCreationDateTime()->getTimestamp() > $deadline) {
					return false;
				}
				$parent = (int)$reply->getTopmostParentId();
				$inScope = $thread > 0 || $parent === 0 || $parent === $id || (int)$reply->getParentId() === $id;
				if ($inScope && (($reply->getActorType() === 'users' && $reply->getActorId() !== $owner)
					|| in_array($reply->getActorType(), ['guests', 'federated_users'], true))) {
					return true;
				}
				$cursor = (int)$reply->getId();
			}
			if (count($batch) === 100 && $cursor === $previous) {
				throw new \UnexpectedValueException('Talk comment pagination did not advance');
			}
		} while (count($batch) === 100);
		return false;
	}

	public function process(array $row): void {
		$owner = $row['owner_id'];
		$token = $row['room_token'];
		$id = (int)$row['message_id'];
		$due = (int)$row['due_at'];
		$user = $this->users->get($owner);
		$notify = false;
		if ($user !== null) {
			try {
				$this->rooms->resolve($user, $token, null);
				$source = $this->comments->get((string)$id);
				$notify = $source->getActorType() === 'users' && $source->getActorId() === $owner
					&& $source->getObjectType() === 'chat' && (string)$source->getObjectId() === (string)$row['room_id']
					&& ($source->getExpireDate() === null || $source->getExpireDate()->getTimestamp() > $this->clock->getTime())
					&& in_array($source->getVerb(), ['comment', 'object_shared', 'voice-message'], true)
					&& !$this->hasReply((string)$row['room_id'], $id, (int)$row['thread_id'], $owner, $due);
			} catch (NotFoundException) {
				$notify = false;
			} catch (AccessException $e) {
				if ($e->reason !== AccessException::NOT_FOUND) {
					throw $e;
				}
			}
		}
		$this->db->beginTransaction();
		try {
			if ($this->tools->cancel($owner, $token, $id, $due) && $notify) {
				// Talk's notifier resolves the conversation and opens the original message.
				$notice = $this->notifications->createNotification()->setApp('spreed')->setUser($owner)
					->setObject('reminder', $token)->setDateTime(new \DateTime('@' . $due))
					->setSubject('reminder', ['token' => $token, 'message' => $id, 'userType' => 'users', 'userId' => $owner])
					->setMessage('reminder', ['commentId' => $id]);
				$this->notifications->notify($notice);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
