<?php

declare(strict_types=1);

use OC\DB\ConnectionAdapter;
use OC\DB\ConnectionFactory;
use OC\DB\SchemaWrapper;
use OC\Migration\NullOutput;
use OC\Comments\Manager as Comments;
use OCA\OwnTalkExtensions\Db\RoomToolsRepository;
use OCA\OwnTalkExtensions\Db\RoomDocuments;
use OCA\OwnTalkExtensions\Service\PersonalRoomDocuments;
use OCA\OwnTalkExtensions\Service\PhotoNotes;
use OCA\OwnTalkExtensions\Service\PhotoFileAccess;
use OCA\OwnTalkExtensions\Migration\Version7000Date20261127000100;
use OCA\OwnTalkExtensions\Migration\Version1000Date20261112000000;
use OCA\OwnTalkExtensions\Migration\Version6000Date20261127000000;
use OCA\OwnTalkExtensions\Service\Checklist;
use OCA\OwnTalkExtensions\Service\FollowupService;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCP\Server;

// Run with a disposable Nextcloud 34 installation or extracted server archive.
if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1] . '/lib/base.php')) {
	fwrite(STDERR, "Usage: php room_tools_sqlite.php <disposable-nextcloud-root>\n");
	exit(2);
}
define('OC_CONSOLE', 1);
$app = dirname(__DIR__, 2);
require $argv[1] . '/lib/base.php';
set_exception_handler(static function (Throwable $error): never {
	fwrite(STDERR, (string)$error . PHP_EOL);
	exit(1);
});
spl_autoload_register(static function (string $name) use ($app): void {
	$prefix = 'OCA\\OwnTalkExtensions\\';
	if (str_starts_with($name, $prefix)) {
		require $app . '/lib/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
	}
});

function expectSame(mixed $expected, mixed $actual): void {
	if ($expected !== $actual) {
		throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

$factory = Server::get(ConnectionFactory::class);
if (in_array($argv[2] ?? '', ['--check-item', '--edit-draft'], true)) {
	[$path, $item, $otherItem] = array_slice($argv, 3);
	if (dirname(realpath($path)) !== realpath(sys_get_temp_dir()) || !str_starts_with(basename($path), 'otc')) {
		throw new InvalidArgumentException('Expected a scratch room-tools database');
	}
	$db = $factory->getConnection('sqlite3', ['path' => $path]);
	$conn = new ConnectionAdapter($db);
	if ($argv[2] === '--edit-draft') {
		$personal = new PersonalRoomDocuments(new RoomDocuments($conn));
		expectSame([$item], array_column($personal->drafts('alice', 'room1', 7), 'id'));
		$write = fn () => $personal->writeDraft('alice', 'room1', 7, $otherItem, $item, 'Edit ' . $otherItem, 8);
	} else {
		$repository = new RoomToolsRepository($conn);
		expectSame([], $repository->checked('room1', 1, [$item, $otherItem]));
		$write = fn () => $repository->setChecked('room1', 1, $item, true);
	}
	echo "ready\n";
	flush();
	expectSame("go\n", fgets(STDIN));
	$write();
	exit(0);
}

$path = tempnam(sys_get_temp_dir(), 'otc');
if ($path === false) {
	throw new RuntimeException('Cannot create a scratch SQLite database');
}
try {
	$params = ['path' => $path];
	$connection = $factory->getConnection('sqlite3', $params);
	$other = $factory->getConnection('sqlite3', $params);
	$schema = new SchemaWrapper($connection);
	$previous = new Version1000Date20261112000000();
	$previous->changeSchema(new NullOutput(), fn () => $schema, []);
	$connection->migrateToSchema($schema->getWrappedSchema());
	$schema = new SchemaWrapper($connection);
	expectSame(true, $schema->hasTable('owntalk_room_settings'));
	$migration = new Version6000Date20261127000000();
	$migration->changeSchema(new NullOutput(), fn () => $schema, []);
	$connection->migrateToSchema($schema->getWrappedSchema());
	$schema = new SchemaWrapper($other);
	expectSame(true, $schema->hasTable('owntalk_room_settings'));
	expectSame(true, $schema->hasTable('owntalk_followups'));
	expectSame(true, $schema->hasTable('owntalk_checks'));

	$a = new RoomToolsRepository(new ConnectionAdapter($connection));
	$b = new RoomToolsRepository(new ConnectionAdapter($other));
	$list = Checklist::parse("☑ Lunch\n- [ ] Bread\n- [ ] Cheese");
	$parallelWrites = static function (array $jobs) use ($argv, $path) {
		$workers = [];
		try {
			foreach ($jobs as [$mode, $first, $second]) {
				$proc = proc_open([PHP_BINARY, __FILE__, $argv[1], $mode, $path, $first, $second],
					[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
				$workers[] = [$proc, $pipes[0], $pipes[1]];
			}
			foreach ($workers as [, , $output]) {
				expectSame("ready\n", fgets($output));
			}
			foreach ($workers as [, $input]) {
				fwrite($input, "go\n");
				fflush($input);
			}
		} finally {
			$codes = [];
			foreach ($workers as [$proc, $input, $output]) {
				fclose($input);
				fclose($output);
				$codes[] = proc_close($proc);
			}
		}
		expectSame([0, 0], $codes);
	};
	$parallelWrites([['--check-item', ...$list['items']], ['--check-item', ...array_reverse($list['items'])]]);
	$b->setChecked('room1', 1, $list['items'][0], true);
	expectSame([0, 1], $a->checked('room1', 1, $list['items']));
	$edited = Checklist::parse("☑ Dinner\n- [ ] Cheese\n- [ ] Fruit\n- [ ] Bread");
	expectSame([0, 2], $b->checked('room1', 1, $edited['items']));
	$a->setChecked('room1', 1, $edited['items'][0], false);
	expectSame([2], $b->checked('room1', 1, $edited['items']));
	expectSame([], $b->checked('room2', 1, $edited['items']));

	$a->schedule('alice', 'room1', 1, 42, 0, 120);
	$b->schedule('bob', 'room1', 1, 42, 0, 121);
	expectSame([], $a->due(119));
	$old = $a->due(120)[0];
	$b->schedule('alice', 'room1', 1, 42, 0, 130);
	expectSame(false, $a->cancel('alice', 'room1', 1, 120));
	$a->retryLater($old, 200);
	expectSame(['bob', 'alice'], array_column($a->due(130), 'owner_id'));
	$a->retryLater($b->due(121)[0], 130);
	expectSame(['alice'], array_column($b->due(130), 'owner_id'));
	expectSame(['alice', 'bob'], array_column($a->due(430), 'owner_id'));
	$a->purgeOwner('alice');
	expectSame(null, $b->reminder('alice', 'room1', 1));
	expectSame(121, (int)$b->reminder('bob', 'room1', 1)['due_at']);
	expectSame(true, $b->cancel('bob', 'room1', 1, 121));
	expectSame(false, $a->cancel('bob', 'room1', 1, 121));

	$adapter = new ConnectionAdapter($connection);
	$core = new SchemaWrapper($connection, new \Doctrine\DBAL\Schema\Schema());
	foreach ([new \OC\Core\Migrations\Version13000Date20170718121200($adapter),
		new \OC\Core\Migrations\Version24000Date20211222112246(),
		new \OC\Core\Migrations\Version25000Date20220602190540(),
		new \OC\Core\Migrations\Version29000Date20231213104850()] as $step) {
		$step->changeSchema(new NullOutput(), fn () => $core, []);
	}
	$connection->createSchemaManager()->createTable($core->getTable('comments'));
	$clock = Server::get(\OCP\AppFramework\Utility\ITimeFactory::class);
	$comments = new Comments($adapter, Server::get(\Psr\Log\LoggerInterface::class), Server::get(\OCP\IConfig::class),
		$clock, Server::get(\OCP\IEmojiHelper::class), Server::get(\OCP\IInitialStateService::class),
		Server::get(\OCP\Files\IRootFolder::class), Server::get(\OCP\EventDispatcher\IEventDispatcher::class));
	$service = new FollowupService($a, $comments, Server::get(RoomAccess::class), Server::get(\OCP\IUserManager::class),
		Server::get(\OCP\Notification\IManager::class), $adapter, $clock);
	$insert = static function (int $id, string $actor, string $verb, int $thread) use ($adapter): void {
		$adapter->insertIgnoreConflict('comments', ['id' => $id, 'object_type' => 'chat', 'object_id' => '42',
			'actor_type' => 'users', 'actor_id' => $actor, 'verb' => $verb, 'message' => 'Example',
			'creation_timestamp' => gmdate('Y-m-d H:i:s', 1000 + $id), 'parent_id' => $thread,
			'topmost_parent_id' => $thread, 'meta_data' => '{}']);
	};
	$insert(1, 'alice', 'comment', 0);
	$insert(2, 'bob', 'reaction', 1);
	$insert(3, 'bob', 'comment', 99);
	for ($id = 4; $id <= 105; $id++) {
		$insert($id, 'alice', 'comment', 1);
	}
	expectSame(false, $service->hasReply('42', 1, 1, 'alice', 2000));
	expectSame(false, $service->hasReply('42', 1, 0, 'alice', 2000));
	$insert(106, 'bob', 'comment', 1);
	expectSame(false, $service->hasReply('42', 1, 1, 'alice', 1105));
	expectSame(true, $service->hasReply('42', 1, 1, 'alice', 1106));
	$schema = new SchemaWrapper($connection);
	(new Version7000Date20261127000100())->changeSchema(new NullOutput(), fn () => $schema, []);
	$connection->migrateToSchema($schema->getWrappedSchema());
	$docs = new RoomDocuments($adapter);
	$first = new PersonalRoomDocuments($docs);
	$second = new PersonalRoomDocuments(new RoomDocuments(new ConnectionAdapter($other)));
	$id1 = '10000000-0000-4000-8000-000000000001';
	$id2 = '10000000-0000-4000-8000-000000000002';
	$id3 = '10000000-0000-4000-8000-000000000003';
	$meta = ['title' => 'Hello', 'subline' => ''];
	$first->favorite('alice', 'giphy', 'gif7', $id1, $meta);
	expectSame('gif7', $second->gifs('alice')['items'][0]['id']);
	$second->favorite('alice', 'giphy', 'gif7', $id2, null);
	expectSame(['saved' => false], $first->favorite('alice', 'giphy', 'gif7', $id1, $meta));
	$first->writeDraft('alice', 'room1', 7, $id1, null, 'Original', 8);
	$parallelWrites([['--edit-draft', $id1, $id2], ['--edit-draft', $id1, $id3]]);
	$ids = array_column($first->drafts('alice', 'room1', 7), 'id');
	sort($ids);
	expectSame([$id2, $id3], $ids);
	$second->deleteDraft('alice', 'room1', 7, $id2);
	expectSame([$id3], array_column($first->writeDraft('alice', 'room1', 7, $id2, $id1, 'Edit ' . $id2, 8), 'id'));
	$second->deleteDraft('alice', 'room2', 0, $id1);
	expectSame([], $first->writeDraft('alice', 'room2', 0, $id1, null, 'Delayed upload', null));
	$photos = new PhotoNotes($docs, Server::get(PhotoFileAccess::class));
	$scope = RoomDocuments::key('photo', 'room1', '8', '12', 'v1');
	$photos->write($scope, 'alice', $id1, 'Here', null, 0.25, 0.5);
	$photos->write($scope, 'bob', $id2, 'Yes', $id1, null, null);
	expectSame('Yes', $photos->notes($scope)[0]['replies'][0]['body']);
	expectSame([], $photos->notes(RoomDocuments::key('photo', 'room1', '8', '12', 'v2')));
	$second->favorite('bob', 'giphy', 'gif7', $id1, $meta);
	$docs->purgeOwner('alice');
	expectSame([], $first->drafts('alice', 'room1', 7));
	expectSame(null, $docs->operation(RoomDocuments::key('gifs', 'alice'), $id1));
	expectSame('gif7', $second->gifs('bob')['items'][0]['id']);
	expectSame(1, count($photos->notes($scope)));
	echo 'PASS: upgrades, concurrent checkbox and draft writes, reminders, GIF replay, photo discussions, account removal.', PHP_EOL;
} finally {
	if (isset($other)) {
		$other->close();
	}
	if (isset($connection)) {
		$connection->close();
	}
	foreach ([$path, $path . '-shm', $path . '-wal', $path . '-journal'] as $file) {
		if (is_file($file)) {
			unlink($file);
		}
	}
}
