<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Service;

final class Checklist {
	/** @return array{revision:string,items:list<string>}|null */
	public static function parse(string $source): ?array {
		$lines = explode("\n", str_replace("\r\n", "\n", $source));
		if (count($lines) < 2 || count($lines) > 51 || !self::label(array_shift($lines), '☑ ', 120)) {
			return null;
		}
		$items = $occurrences = [];
		foreach ($lines as $line) {
			if (!self::label($line, '- [ ] ', 200)) {
				return null;
			}
			$occurrence = $occurrences[$line] ?? 0;
			$items[] = hash('sha256', $occurrence . ':' . $line);
			$occurrences[$line] = $occurrence + 1;
		}
		return ['revision' => hash('sha256', $source), 'items' => $items];
	}

	private static function label(string $line, string $prefix, int $limit): bool {
		$text = substr($line, strlen($prefix));
		return str_starts_with($line, $prefix) && mb_strlen($text, 'UTF-8') <= $limit
			&& preg_match('/[^\s\x{FEFF}]/u', $text) === 1;
	}
}
