<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Exception\ImageException;
use OCA\OwnTalkExtensions\Service\BackgroundImageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemoryFile implements ISimpleFile {
	public function __construct(
		private MemoryFolder $folder,
		private string $name,
		public string $content,
		public int $mtime,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function getSize(): int|float {
		return \strlen($this->content);
	}

	public function getETag(): string {
		return md5($this->content);
	}

	public function getMTime(): int {
		return $this->mtime;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function putContent($data): void {
		$this->content = (string)$data;
	}

	public function delete(): void {
		unset($this->folder->files[$this->name]);
	}

	public function getMimeType(): string {
		return 'image/jpeg';
	}

	public function getExtension(): string {
		return 'jpg';
	}

	public function read() {
		throw new \LogicException('not used');
	}

	public function write() {
		throw new \LogicException('not used');
	}
}

final class MemoryFolder implements ISimpleFolder {
	/** @var array<string, MemoryFile> */
	public array $files = [];
	public bool $deleted = false;

	public function __construct(
		public int $now,
	) {
	}

	public function getDirectoryListing(): array {
		return array_values($this->files);
	}

	public function fileExists(string $name): bool {
		return isset($this->files[$name]);
	}

	public function getFile(string $name): ISimpleFile {
		return $this->files[$name] ?? throw new NotFoundException($name);
	}

	public function newFile(string $name, $content = null): ISimpleFile {
		return $this->files[$name] = new MemoryFile($this, $name, (string)$content, $this->now);
	}

	public function delete(): void {
		$this->deleted = true;
	}

	public function getName(): string {
		return 'backgrounds';
	}

	public function getFolder(string $name): ISimpleFolder {
		throw new NotFoundException($name);
	}

	public function newFolder(string $path): ISimpleFolder {
		throw new \LogicException('not used');
	}
}

final class BackgroundImageServiceTest extends TestCase {
	private const NOW = 100000;

	private MemoryFolder $folder;
	private int $quota = 1024 * 1024 * 1024;

	protected function setUp(): void {
		$this->folder = new MemoryFolder(self::NOW);
	}

	private function service(): BackgroundImageService {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => $this->quota);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		return new BackgroundImageService($appData, $config, $time);
	}

	private static function png(int $width, int $height, bool $transparent = false): string {
		$image = imagecreatetruecolor($width, $height);
		if ($transparent) {
			imagealphablending($image, false);
			imagesavealpha($image, true);
			imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
		} else {
			imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
		}
		ob_start();
		imagepng($image);
		return (string)ob_get_clean();
	}

	/** Only signature and IHDR: enough for the size check, nothing to decode. */
	private static function pngHeader(int $width, int $height): string {
		$ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
		return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
	}

	/** A JPEG with an EXIF block carrying only the orientation tag. */
	private static function jpegWithOrientation(int $width, int $height, int $orientation): string {
		$image = imagecreatetruecolor($width, $height);
		ob_start();
		imagejpeg($image);
		$jpeg = (string)ob_get_clean();
		$tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01"
			. "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00"
			. "\x00\x00\x00\x00";
		$app1 = "Exif\x00\x00" . $tiff;
		return "\xFF\xD8\xFF\xE1" . pack('n', \strlen($app1) + 2) . $app1 . substr($jpeg, 2);
	}

	private function stored(string $sha): string {
		return $this->folder->files[$sha . '.jpg']->content;
	}

	public function testStoresTransparentPngAsOpaqueJpegNamedByHash(): void {
		$sha = $this->service()->store(self::png(40, 30, true), 'image/png');

		$jpeg = $this->stored($sha);
		self::assertSame(hash('sha256', $jpeg), $sha);
		self::assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
		$image = imagecreatefromstring($jpeg);
		$rgb = imagecolorsforindex($image, imagecolorat($image, 5, 5));
		self::assertGreaterThan(240, $rgb['red']);
	}

	public function testScalesLongestEdgeDown(): void {
		$sha = $this->service()->store(self::png(3200, 800), 'image/png');

		[$width, $height] = getimagesizefromstring($this->stored($sha));
		self::assertSame([2560, 640], [$width, $height]);
	}

	public function testAppliesExifRotationAndDropsMetadata(): void {
		if (!\function_exists('exif_read_data')) {
			self::markTestSkipped('exif extension missing');
		}
		$source = self::jpegWithOrientation(40, 20, 6);
		self::assertSame(6, exif_read_data('data://image/jpeg;base64,' . base64_encode($source))['Orientation']);

		$jpeg = $this->stored($this->service()->store($source, 'image/jpeg'));

		[$width, $height] = getimagesizefromstring($jpeg);
		self::assertSame([20, 40], [$width, $height]);
		self::assertStringNotContainsString('Exif', $jpeg);
	}

	public function testSamePictureIsStoredOnce(): void {
		$png = self::png(10, 10);
		$service = $this->service();

		self::assertSame($service->store($png, 'image/png'), $service->store($png, 'image/png'));
		self::assertCount(1, $this->folder->files);
	}

	/** @return array<string, array{string, string, string}> */
	public static function rejected(): array {
		return [
			'png declared as jpeg' => [self::png(4, 4), 'image/jpeg', ImageException::INVALID],
			'garbage' => [str_repeat('x', 100), 'image/png', ImageException::INVALID],
			'too wide' => [self::png(8193, 1), 'image/png', ImageException::INVALID],
			'too many pixels' => [self::pngHeader(6000, 6000), 'image/png', ImageException::INVALID],
			'gif' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'image/gif', ImageException::UNSUPPORTED],
			'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml', ImageException::UNSUPPORTED],
		];
	}

	#[DataProvider('rejected')]
	public function testRejects(string $bytes, string $type, string $reason): void {
		try {
			$this->service()->store($bytes, $type);
			self::fail('accepted');
		} catch (ImageException $e) {
			self::assertSame($reason, $e->reason);
		}
		self::assertSame([], $this->folder->files);
	}

	public function testRejectsBodyOverLimit(): void {
		$this->expectExceptionObject(new ImageException(ImageException::TOO_LARGE));
		$this->service()->store(str_repeat('x', BackgroundImageService::MAX_BYTES + 1), 'image/png');
	}

	public function testQuotaStopsNewFiles(): void {
		$this->quota = 100;
		$this->expectExceptionObject(new ImageException(ImageException::QUOTA));
		$this->service()->store(self::png(50, 50), 'image/png');
	}

	public function testFindAcceptsOnlyHashNames(): void {
		$sha = $this->service()->store(self::png(10, 10), 'image/png');

		self::assertNotNull($this->service()->find($sha));
		self::assertNull($this->service()->find('../' . $sha));
		self::assertNull($this->service()->find(strtoupper($sha)));
		self::assertNull($this->service()->find(str_repeat('0', 64)));
	}

	public function testDeletesOnlyOldUnreferencedFiles(): void {
		$old = str_repeat('a', 64);
		$kept = str_repeat('b', 64);
		$fresh = str_repeat('c', 64);
		foreach ([$old, $kept] as $sha) {
			$this->folder->newFile($sha . '.jpg', 'x')->mtime = self::NOW - 7200;
		}
		$this->folder->newFile($fresh . '.jpg', 'x');

		self::assertSame(1, $this->service()->deleteUnreferenced([$kept => true]));
		self::assertSame([$kept . '.jpg', $fresh . '.jpg'], array_keys($this->folder->files));
	}
}
