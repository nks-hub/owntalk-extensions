<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCA\OwnTalkExtensions\AppInfo\Application;
use OCA\OwnTalkExtensions\Exception\ImageException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;

/**
 * Validates, re-encodes and stores conversation background images. Files are named by the
 * SHA-256 of the stored JPEG, so the same picture in two conversations is stored once.
 */
class BackgroundImageService {
	public const MAX_BYTES = 10 * 1024 * 1024;
	public const MAX_EDGE = 2560;
	public const SHA_PATTERN = '/^[0-9a-f]{64}$/D';
	private const MAX_SOURCE_EDGE = 8192;
	// GD keeps about 5 bytes per pixel, so 25 MP stays near 128 MB of memory.
	private const MAX_SOURCE_PIXELS = 25_000_000;
	private const DEFAULT_QUOTA = 1024 * 1024 * 1024;
	private const FOLDER = 'backgrounds';
	// An upload stores the file before the settings point at it; younger files are never collected.
	private const GRACE_SECONDS = 3600;

	public function __construct(
		private IAppData $appData,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	public static function isSupported(): bool {
		return \function_exists('imagecreatefromstring') && \function_exists('imagejpeg');
	}

	/** @return list<string> */
	public static function acceptedTypes(): array {
		$types = ['image/jpeg', 'image/png'];
		if (\function_exists('imagetypes') && (imagetypes() & IMG_WEBP)) {
			$types[] = 'image/webp';
		}
		return $types;
	}

	/**
	 * @return string SHA-256 of the stored JPEG
	 * @throws ImageException
	 */
	public function store(string $bytes, string $declaredType): string {
		if (!\in_array($declaredType, self::acceptedTypes(), true)) {
			throw new ImageException(ImageException::UNSUPPORTED);
		}
		if (\strlen($bytes) > self::MAX_BYTES) {
			throw new ImageException(ImageException::TOO_LARGE);
		}
		$sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
		$info = @getimagesizefromstring($bytes);
		if ($sniffed !== $declaredType || $info === false || ($info['mime'] ?? '') !== $declaredType) {
			throw new ImageException(ImageException::INVALID);
		}
		[$width, $height] = $info;
		if ($width < 1 || $height < 1 || $width > self::MAX_SOURCE_EDGE || $height > self::MAX_SOURCE_EDGE
			|| $width * $height > self::MAX_SOURCE_PIXELS) {
			throw new ImageException(ImageException::INVALID);
		}

		$jpeg = $this->reencode($bytes, $declaredType, $width, $height);
		$sha = hash('sha256', $jpeg);
		$folder = $this->folder(true);
		if (!$folder->fileExists($sha . '.jpg')) {
			if ($this->usedBytes($folder) + \strlen($jpeg) > $this->quota()) {
				throw new ImageException(ImageException::QUOTA);
			}
			$folder->newFile($sha . '.jpg', $jpeg);
		}
		return $sha;
	}

	public function find(string $sha): ?ISimpleFile {
		if (!preg_match(self::SHA_PATTERN, $sha)) {
			return null;
		}
		$folder = $this->folder(false);
		try {
			return $folder?->getFile($sha . '.jpg');
		} catch (NotFoundException) {
			return null;
		}
	}

	/**
	 * Deletes stored images no conversation points at any more.
	 *
	 * @param array<string, true> $referenced sha => true
	 */
	public function deleteUnreferenced(array $referenced): int {
		$folder = $this->folder(false);
		if ($folder === null) {
			return 0;
		}
		$cutoff = $this->time->getTime() - self::GRACE_SECONDS;
		$deleted = 0;
		foreach ($folder->getDirectoryListing() as $file) {
			$sha = basename($file->getName(), '.jpg');
			if (!isset($referenced[$sha]) && $file->getMTime() < $cutoff) {
				$file->delete();
				$deleted++;
			}
		}
		return $deleted;
	}

	public function deleteAll(): void {
		$this->folder(false)?->delete();
	}

	/** @throws ImageException */
	private function reencode(string $bytes, string $type, int $width, int $height): string {
		$source = @imagecreatefromstring($bytes);
		if ($source === false) {
			throw new ImageException(ImageException::INVALID);
		}
		if ($type === 'image/jpeg') {
			$source = $this->applyOrientation($source, $bytes);
			$width = imagesx($source);
			$height = imagesy($source);
		}
		$scale = min(1.0, self::MAX_EDGE / max($width, $height));
		$targetWidth = max(1, (int)round($width * $scale));
		$targetHeight = max(1, (int)round($height * $scale));

		$target = imagecreatetruecolor($targetWidth, $targetHeight);
		// Transparent PNG/WebP areas become white instead of black.
		imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
		imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
		imagedestroy($source);
		imageinterlace($target, true);

		ob_start();
		$ok = imagejpeg($target, null, 85);
		$jpeg = (string)ob_get_clean();
		imagedestroy($target);
		if (!$ok || $jpeg === '') {
			throw new ImageException(ImageException::INVALID);
		}
		return $jpeg;
	}

	/**
	 * Phones store photos sideways plus an EXIF orientation tag; the tag is dropped with the
	 * rest of the metadata, so the rotation is applied to the pixels first.
	 */
	private function applyOrientation(\GdImage $image, string $bytes): \GdImage {
		if (!\function_exists('exif_read_data')) {
			return $image;
		}
		$exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes), 'IFD0');
		$orientation = \is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
		if (\in_array($orientation, [2, 4, 5, 7], true)) {
			imageflip($image, IMG_FLIP_HORIZONTAL);
		}
		$angle = match ($orientation) {
			3, 4 => 180,
			5, 6 => 270,
			7, 8 => 90,
			default => 0,
		};
		if ($angle === 0) {
			return $image;
		}
		$rotated = imagerotate($image, $angle, 0);
		if ($rotated === false) {
			return $image;
		}
		imagedestroy($image);
		return $rotated;
	}

	private function quota(): int {
		try {
			return $this->appConfig->getValueInt(Application::APP_ID, 'image_quota_bytes', self::DEFAULT_QUOTA);
		} catch (AppConfigTypeConflictException) {
			// Set with occ without --type=integer, so stored as a string.
			return (int)$this->appConfig->getValueString(Application::APP_ID, 'image_quota_bytes', (string)self::DEFAULT_QUOTA);
		}
	}

	private function usedBytes(ISimpleFolder $folder): int {
		$total = 0;
		foreach ($folder->getDirectoryListing() as $file) {
			$total += (int)$file->getSize();
		}
		return $total;
	}

	private function folder(bool $create): ?ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $create ? $this->appData->newFolder(self::FOLDER) : null;
		}
	}
}
