<?php
/**
 * @copyright Copyright (c) 2019 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Deck\Activity;

use InvalidArgumentException;
use OCA\Cospend\Service\BillService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUser;

class ActivityManager {

	private $manager;
	private $userId;
	private $billService;
	private $l10n;

	const SUBJECT_BILL_CREATE = 'bill_create';
	const SUBJECT_BILL_UPDATE = 'bill_update';
	const SUBJECT_BILL_DELETE = 'bill_delete';

	const SUBJECT_PROJECT_SHARE = 'board_share';
	const SUBJECT_PROJECT_UNSHARE = 'board_unshare';

	public function __construct(
		IManager $manager,
		PermissionService $permissionsService,
		BoardMapper $boardMapper,
		CardMapper $cardMapper,
		StackMapper $stackMapper,
		AttachmentMapper $attachmentMapper,
		AclMapper $aclMapper,
		IL10N $l10n,
		$userId
	) {
		$this->manager = $manager;
		$this->permissionService = $permissionsService;
		$this->boardMapper = $boardMapper;
		$this->cardMapper = $cardMapper;
		$this->stackMapper = $stackMapper;
		$this->attachmentMapper = $attachmentMapper;
		$this->aclMapper = $aclMapper;
		$this->l10n = $l10n;
		$this->userId = $userId;
	}

	/**
	 * @param $subjectIdentifier
	 * @param array $subjectParams
	 * @param bool $ownActivity
	 * @return string
	 */
	public function getActivityFormat($subjectIdentifier, $subjectParams = [], $ownActivity = false) {
		$subject = '';
		switch ($subjectIdentifier) {
			case self::SUBJECT_BILL_CREATE:
				$subject = $ownActivity ? $this->l10n->t('You have created a new bill {bill} in project {project}'): $this->l10n->t('{user} has created a new bill {bill} in project {project}');
				break;
			case self::SUBJECT_BILL_DELETE:
				$subject = $ownActivity ? $this->l10n->t('You have deleted the bill {bill} of project {project}') : $this->l10n->t('{user} has deleted the bill {bill} of project {project}');
				break;
			case self::SUBJECT_PROJECT_SHARE:
				$subject = $ownActivity ? $this->l10n->t('You have shared the project {project} with {who}') : $this->l10n->t('{user} has shared the project {project} with {who}');
				break;
			case self::SUBJECT_PROJECT_UNSHARE:
				$subject = $ownActivity ? $this->l10n->t('You have removed {who} from the project {project}') : $this->l10n->t('{user} has removed {who} from the project {project}');
				break;
			case self::SUBJECT_BILL_UPDATE:
				$subject = $ownActivity ? $this->l10n->t('You have updated the bill {bill} of project {project}') : $this->l10n->t('{user} has updated the bill {bill} of project {project}');
				break;
			default:
				break;
		}
		return $subject;
	}

	public function triggerEvent($objectType, $entity, $subject, $additionalParams = [], $author = null) {
		try {
			$event = $this->createEvent($objectType, $entity, $subject, $additionalParams, $author);
			if ($event !== null) {
				$this->sendToUsers($event);
			}
		} catch (\Exception $e) {
			// Ignore exception for undefined activities on update events
		}
	}

	/**
	 *
	 * @param $objectType
	 * @param ChangeSet $changeSet
	 * @param $subject
	 * @throws \Exception
	 */
	public function triggerUpdateEvents($objectType, ChangeSet $changeSet, $subject) {
		$previousEntity = $changeSet->getBefore();
		$entity = $changeSet->getAfter();
		$events = [];
		if ($previousEntity !== null) {
			foreach ($entity->getUpdatedFields() as $field => $value) {
				$getter = 'get' . ucfirst($field);
				$subjectComplete = $subject . '_' . $field;
				$changes = [
					'before' => $previousEntity->$getter(),
					'after' => $entity->$getter()
				];
				if ($changes['before'] !== $changes['after']) {
					try {
						$event = $this->createEvent($objectType, $entity, $subjectComplete, $changes);
						if ($event !== null) {
							$events[] = $event;
						}
					} catch (\Exception $e) {
						// Ignore exception for undefined activities on update events
					}
				}
			}
		} else {
			try {
				$events = [$this->createEvent($objectType, $entity, $subject)];
			} catch (\Exception $e) {
				// Ignore exception for undefined activities on update events
			}
		}
		foreach ($events as $event) {
			$this->sendToUsers($event);
		}
	}

	/**
	 * @param $objectType
	 * @param $entity
	 * @param $subject
	 * @param array $additionalParams
	 * @return IEvent|null
	 * @throws \Exception
	 */
	private function createEvent($objectType, $entity, $subject, $additionalParams = [], $author = null) {
		try {
			$object = $this->findObjectForEntity($objectType, $entity);
		} catch (DoesNotExistException $e) {
			\OC::$server->getLogger()->error('Could not create activity entry for ' . $subject . '. Entity not found.', (array)$entity);
			return null;
		} catch (MultipleObjectsReturnedException $e) {
			\OC::$server->getLogger()->error('Could not create activity entry for ' . $subject . '. Entity not found.', (array)$entity);
			return null;
		}

		/**
		 * Automatically fetch related details for subject parameters
		 * depending on the subject
		 */
		$eventType = 'deck';
		$subjectParams = [];
		$message = null;
		switch ($subject) {
			// No need to enhance parameters since entity already contains the required data
			case self::SUBJECT_BOARD_CREATE:
			case self::SUBJECT_BOARD_UPDATE_TITLE:
			case self::SUBJECT_BOARD_UPDATE_ARCHIVED:
			case self::SUBJECT_BOARD_DELETE:
			case self::SUBJECT_BOARD_RESTORE:
			// Not defined as there is no activity for
			// case self::SUBJECT_BOARD_UPDATE_COLOR
				break;
			case self::SUBJECT_CARD_COMMENT_CREATE:
				$eventType = 'deck_comment';
				$subjectParams = $this->findDetailsForCard($entity->getId());
				if (array_key_exists('comment', $additionalParams)) {
					/** @var IComment $entity */
					$comment = $additionalParams['comment'];
					$subjectParams['comment'] = $comment->getId();
					unset($additionalParams['comment']);
				}
				break;
			case self::SUBJECT_STACK_CREATE:
			case self::SUBJECT_STACK_UPDATE:
			case self::SUBJECT_STACK_UPDATE_TITLE:
			case self::SUBJECT_STACK_UPDATE_ORDER:
			case self::SUBJECT_STACK_DELETE:
				$subjectParams = $this->findDetailsForStack($entity->getId());
				break;

			case self::SUBJECT_CARD_CREATE:
			case self::SUBJECT_CARD_DELETE:
			case self::SUBJECT_CARD_UPDATE_ARCHIVE:
			case self::SUBJECT_CARD_UPDATE_UNARCHIVE:
			case self::SUBJECT_CARD_UPDATE_TITLE:
			case self::SUBJECT_CARD_UPDATE_DESCRIPTION:
			case self::SUBJECT_CARD_UPDATE_DUEDATE:
			case self::SUBJECT_CARD_UPDATE_STACKID:
			case self::SUBJECT_LABEL_ASSIGN:
			case self::SUBJECT_LABEL_UNASSING:
			case self::SUBJECT_CARD_USER_ASSIGN:
			case self::SUBJECT_CARD_USER_UNASSIGN:
				$subjectParams = $this->findDetailsForCard($entity->getId(), $subject);
				break;
			case self::SUBJECT_ATTACHMENT_CREATE:
			case self::SUBJECT_ATTACHMENT_UPDATE:
			case self::SUBJECT_ATTACHMENT_DELETE:
			case self::SUBJECT_ATTACHMENT_RESTORE:
				$subjectParams = $this->findDetailsForAttachment($entity->getId());
				break;
			case self::SUBJECT_BOARD_SHARE:
			case self::SUBJECT_BOARD_UNSHARE:
				$subjectParams = $this->findDetailsForAcl($entity->getId());
				break;
			default:
				throw new \Exception('Unknown subject for activity.');
				break;
		}

		if ($subject === self::SUBJECT_CARD_UPDATE_DESCRIPTION){
			$card = $subjectParams['card'];
			if ($card->getLastEditor() === $this->userId) {
				return null;
			}
			$subjectParams['diff'] = true;
			$eventType = 'deck_card_description';
		}
		if ($subject === self::SUBJECT_CARD_UPDATE_STACKID) {
			$subjectParams['stackBefore'] = $this->stackMapper->find($additionalParams['before']);
		}

		$subjectParams['author'] = $this->userId;


		$event = $this->manager->generateEvent();
		$event->setApp('deck')
			->setType($eventType)
			->setAuthor($author === null ? $this->userId : $author)
			->setObject($objectType, (int)$object->getId(), $object->getTitle())
			->setSubject($subject, array_merge($subjectParams, $additionalParams))
			->setTimestamp(time());

		if ($message !== null) {
			$event->setMessage($message);
		}
		return $event;
	}

	/**
	 * Publish activity to all users that are part of the board of a given object
	 *
	 * @param IEvent $event
	 */
	private function sendToUsers(IEvent $event) {
		switch ($event->getObjectType()) {
			case self::DECK_OBJECT_BOARD:
				$mapper = $this->boardMapper;
				break;
			case self::DECK_OBJECT_CARD:
				$mapper = $this->cardMapper;
				break;
		}
		$boardId = $mapper->findBoardId($event->getObjectId());
		/** @var IUser $user */
		foreach ($this->permissionService->findUsers($boardId) as $user) {
			$event->setAffectedUser($user->getUID());
			/** @noinspection DisconnectedForeachInstructionInspection */
			$this->manager->publish($event);
		}
	}

	/**
	 * @param $objectType
	 * @param $entity
	 * @return null|\OCA\Deck\Db\RelationalEntity|\OCP\AppFramework\Db\Entity
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	private function findObjectForEntity($objectType, $entity) {
		$className = \get_class($entity);
		if ($entity instanceof IComment) {
			$className = IComment::class;
		}
		$objectId = null;
		if ($objectType === self::DECK_OBJECT_CARD) {
			switch ($className) {
				case Card::class:
					$objectId = $entity->getId();
					break;
				case Attachment::class:
				case Label::class:
				case AssignedUsers::class:
					$objectId = $entity->getCardId();
					break;
				case IComment::class:
					$objectId = $entity->getObjectId();
					break;
				default:
					throw new InvalidArgumentException('No entity relation present for '. $className . ' to ' . $objectType);
			}
			return $this->cardMapper->find($objectId);
		}
		if ($objectType === self::DECK_OBJECT_BOARD) {
			switch ($className) {
				case Board::class:
					$objectId = $entity->getId();
					break;
				case Label::class:
				case Stack::class:
				case Acl::class:
					$objectId = $entity->getBoardId();
					break;
				default:
					throw new InvalidArgumentException('No entity relation present for '. $className . ' to ' . $objectType);
			}
			return $this->boardMapper->find($objectId);
		}
		throw new InvalidArgumentException('No entity relation present for '. $className . ' to ' . $objectType);
	}

	private function findDetailsForStack($stackId) {
		$stack = $this->stackMapper->find($stackId);
		$board = $this->boardMapper->find($stack->getBoardId());
		return [
			'stack' => $stack,
			'board' => $board
		];
	}

	private function findDetailsForCard($cardId, $subject = null) {
		$card = $this->cardMapper->find($cardId);
		$stack = $this->stackMapper->find($card->getStackId());
		$board = $this->boardMapper->find($stack->getBoardId());
		if ($subject !== self::SUBJECT_CARD_UPDATE_DESCRIPTION) {
			$card = [
				'id' => $card->getId(),
				'title' => $card->getTitle(),
				'archived' => $card->getArchived()
			];
		}
		return [
			'card' => $card,
			'stack' => $stack,
			'board' => $board
		];
	}

	private function findDetailsForAttachment($attachmentId) {
		$attachment = $this->attachmentMapper->find($attachmentId);
		$data = $this->findDetailsForCard($attachment->getCardId());
		return array_merge($data, ['attachment' => $attachment]);
	}

	private function findDetailsForAcl($aclId) {
		$acl = $this->aclMapper->find($aclId);
		$board = $this->boardMapper->find($acl->getBoardId());
		return [
			'acl' => $acl,
			'board' => $board
		];
	}

}
