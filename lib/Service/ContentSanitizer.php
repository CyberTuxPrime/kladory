<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

/**
 * Prüft und bereinigt den Seiteninhalt (Leinwand-JSON mit Textfeldern und Bildern).
 * Nur eine feste Liste von Tags/Attributen bleibt erhalten – alles andere
 * (Skripte, Event-Handler, fremde Styles, Links mit javascript: …) wird entfernt.
 * Die Oberfläche bereinigt zusätzlich, der Server ist aber die verbindliche Stelle.
 */
class ContentSanitizer {
	private const MAX_BLOCKS = 2000;
	private const MAX_STROKES = 10000;
	private const MAX_POINTS = 5000;
	public const IMAGE_NAME_RE = '/^[a-f0-9]{32}\.(png|jpg|gif|webp)$/';

	/** erlaubte Tags => erlaubte Attribute */
	private const TAGS = [
		'p' => [], 'div' => [], 'br' => [],
		'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [],
		'h1' => [], 'h2' => [], 'h3' => [],
		'ul' => ['class'], 'ol' => [], 'li' => ['data-checked'],
		'span' => ['style'],
		'blockquote' => [], 'code' => [], 'pre' => [], 'mark' => [], 'sub' => [], 'sup' => [],
	];

	/** werden samt Inhalt entfernt */
	private const DROP = [
		'script', 'style', 'iframe', 'frame', 'object', 'embed', 'template', 'noscript', 'svg', 'math',
		'head', 'title', 'meta', 'link', 'base', 'form', 'input', 'textarea', 'button', 'select', 'option',
		'audio', 'video', 'canvas', 'img', 'picture', 'source',
	];

	private const STYLE_PROPS = ['color', 'background-color'];
	private const COLOR_RE = '/^(#[0-9a-f]{3,8}|rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*)?\)|transparent)$/i';

	public function sanitizeContent(string $json): string {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new ValidationException('Seiteninhalt ist ungültig.');
		}
		$blocks = $data['blocks'] ?? [];
		if (!is_array($blocks) || !array_is_list($blocks)) {
			throw new ValidationException('Seiteninhalt ist ungültig.');
		}
		if (count($blocks) > self::MAX_BLOCKS) {
			throw new ValidationException('Zu viele Elemente auf einer Seite.');
		}

		$out = [];
		$seen = [];
		foreach ($blocks as $b) {
			if (!is_array($b)) {
				continue;
			}
			$type = $b['type'] ?? 'text';
			if ($type !== 'text' && $type !== 'image' && $type !== 'ink') {
				continue;
			}
			$id = is_string($b['id'] ?? null) ? $b['id'] : '';
			if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || isset($seen[$id])) {
				$id = 'b' . bin2hex(random_bytes(6));
			}
			$seen[$id] = true;
			$block = [
				'id' => $id,
				'type' => $type,
				'x' => $this->num($b['x'] ?? 0, 0, 100000),
				'y' => $this->num($b['y'] ?? 0, 0, 100000),
				'w' => $this->num($b['w'] ?? 360, 40, 4000),
			];
			if ($type === 'ink') {
				$block['strokes'] = $this->sanitizeStrokes($b['strokes'] ?? null);
				if ($block['strokes'] === []) {
					continue;
				}
			} elseif ($type === 'image') {
				$src = is_string($b['src'] ?? null) ? $b['src'] : '';
				if (!preg_match(self::IMAGE_NAME_RE, $src)) {
					continue;
				}
				$block['src'] = $src;
			} else {
				$block['html'] = $this->sanitizeHtml(is_string($b['html'] ?? null) ? $b['html'] : '');
			}
			$out[] = $block;
		}

		return json_encode(['version' => 1, 'blocks' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/**
	 * Freihand-Striche. Gespeichert wird kompakt als
	 *   {c: 'auto'|'#rrggbb', s: Stärke, t: 'pen'|'marker', d: [x0, y0, dx1, dy1, dx2, dy2, …]}
	 * (erster Punkt absolut, danach nur die Differenzen – etwa halb so groß wie absolute Punkte).
	 * Das ältere Format mit p: [[x,y], …] wird weiterhin angenommen und umgewandelt.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitizeStrokes(mixed $strokes): array {
		if (!is_array($strokes)) {
			return [];
		}
		$out = [];
		foreach ($strokes as $st) {
			if (!is_array($st)) {
				continue;
			}
			$points = self::strokePoints($st);
			if ($points === []) {
				continue;
			}
			$color = is_string($st['c'] ?? null) && preg_match('/^(auto|#[0-9a-fA-F]{6})$/', $st['c']) ? strtolower($st['c']) : 'auto';
			$out[] = [
				'c' => $color,
				's' => (int)round($this->num($st['s'] ?? 3, 1, 40)),
				't' => ($st['t'] ?? '') === 'marker' ? 'marker' : 'pen',
				'd' => self::encodeDeltas($points),
			];
			if (count($out) >= self::MAX_STROKES) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Punkte eines Strichs (absolut, ganzzahlig, auf die Leinwand begrenzt) – aus d oder p.
	 * @return array<int, array{0:int, 1:int}>
	 */
	public static function strokePoints(array $st): array {
		$clamp = static fn (float $v): int => (int)round(max(0, min(100000, $v)));
		$points = [];
		if (is_array($st['d'] ?? null) && array_is_list($st['d'])) {
			$d = $st['d'];
			$n = intdiv(min(count($d), self::MAX_POINTS * 2), 2) * 2;
			$x = 0.0;
			$y = 0.0;
			for ($i = 0; $i < $n; $i += 2) {
				if (!is_numeric($d[$i]) || !is_numeric($d[$i + 1])) {
					break;
				}
				$x += (float)$d[$i];
				$y += (float)$d[$i + 1];
				// Summe begrenzen, damit sich keine riesigen Werte aufschaukeln
				$x = max(0.0, min(100000.0, $x));
				$y = max(0.0, min(100000.0, $y));
				$points[] = [$clamp($x), $clamp($y)];
			}
			return $points;
		}
		if (is_array($st['p'] ?? null)) {
			foreach ($st['p'] as $pt) {
				if (is_array($pt) && isset($pt[0], $pt[1]) && is_numeric($pt[0]) && is_numeric($pt[1])) {
					$points[] = [$clamp((float)$pt[0]), $clamp((float)$pt[1])];
					if (count($points) >= self::MAX_POINTS) {
						break;
					}
				}
			}
		}
		return $points;
	}

	/** @param array<int, array{0:int, 1:int}> $points */
	private static function encodeDeltas(array $points): array {
		$d = [$points[0][0], $points[0][1]];
		for ($i = 1, $n = count($points); $i < $n; $i++) {
			$d[] = $points[$i][0] - $points[$i - 1][0];
			$d[] = $points[$i][1] - $points[$i - 1][1];
		}
		return $d;
	}

	/** Reiner Text für die Volltextsuche (Titel + alle Textfelder) */
	public function buildSearchText(string $title, ?string $contentJson): string {
		$parts = [trim($title)];
		$data = $contentJson ? json_decode($contentJson, true) : null;
		foreach ((is_array($data) && is_array($data['blocks'] ?? null)) ? $data['blocks'] : [] as $b) {
			if (is_array($b) && ($b['type'] ?? 'text') === 'text' && is_string($b['html'] ?? null)) {
				$text = preg_replace('/<[^>]*>/', ' ', $b['html']) ?? '';
				$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$parts[] = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
			}
		}
		// erste Zeile = Titel (auch wenn leer), danach der Inhalt
		$title = array_shift($parts);
		$body = implode("\n", array_filter($parts, static fn ($p) => $p !== ''));
		return mb_substr(str_replace("\n", ' ', (string)$title) . "\n" . $body, 0, 500000);
	}

	/** @return string[] Dateinamen aller Bilder auf der Seite */
	public function extractImageRefs(?string $contentJson): array {
		$data = $contentJson ? json_decode($contentJson, true) : null;
		$refs = [];
		foreach ((is_array($data) && is_array($data['blocks'] ?? null)) ? $data['blocks'] : [] as $b) {
			if (is_array($b) && ($b['type'] ?? '') === 'image' && is_string($b['src'] ?? null)) {
				$refs[] = $b['src'];
			}
		}
		return array_values(array_unique($refs));
	}

	public function sanitizeHtml(string $html): string {
		if (trim($html) === '') {
			return '';
		}
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML(
			'<?xml encoding="UTF-8"?><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$root = $doc->getElementsByTagName('div')->item(0);
		if ($root === null) {
			return '';
		}
		$this->clean($root);

		$result = '';
		foreach (iterator_to_array($root->childNodes) as $child) {
			$result .= $doc->saveHTML($child);
		}
		return $result;
	}

	private function clean(\DOMNode $node): void {
		foreach (iterator_to_array($node->childNodes) as $child) {
			if ($child instanceof \DOMText && !($child instanceof \DOMCdataSection)) {
				continue;
			}
			if (!($child instanceof \DOMElement)) {
				$node->removeChild($child); // Kommentare, CDATA, Processing Instructions
				continue;
			}
			$tag = strtolower($child->tagName);
			if (in_array($tag, self::DROP, true)) {
				$node->removeChild($child);
				continue;
			}
			if (!isset(self::TAGS[$tag])) {
				// unbekanntes Tag: Inhalt behalten, Hülle entfernen
				$this->clean($child);
				while ($child->firstChild !== null) {
					$node->insertBefore($child->firstChild, $child);
				}
				$node->removeChild($child);
				continue;
			}

			$names = [];
			foreach ($child->attributes as $attr) {
				$names[] = $attr->nodeName;
			}
			foreach ($names as $name) {
				if (!in_array(strtolower($name), self::TAGS[$tag], true)) {
					$child->removeAttribute($name);
				}
			}
			if ($tag === 'ul' && $child->getAttribute('class') !== 'kl-check') {
				$child->removeAttribute('class');
			}
			if ($tag === 'li' && $child->getAttribute('data-checked') !== 'true') {
				$child->removeAttribute('data-checked');
			}
			if ($tag === 'span' && $child->hasAttribute('style')) {
				$style = $this->cleanStyle($child->getAttribute('style'));
				if ($style === '') {
					$child->removeAttribute('style');
				} else {
					$child->setAttribute('style', $style);
				}
			}
			$this->clean($child);
		}
	}

	private function cleanStyle(string $style): string {
		$kept = [];
		foreach (explode(';', $style) as $decl) {
			$parts = explode(':', $decl, 2);
			if (count($parts) !== 2) {
				continue;
			}
			$prop = strtolower(trim($parts[0]));
			$value = trim($parts[1]);
			if (in_array($prop, self::STYLE_PROPS, true) && preg_match(self::COLOR_RE, $value)) {
				$kept[$prop] = $prop . ': ' . $value;
			}
		}
		return implode('; ', $kept);
	}

	private function num(mixed $v, float $min, float $max): float {
		if (!is_int($v) && !is_float($v)) {
			$v = is_numeric($v) ? (float)$v : $min;
		}
		return round(max($min, min($max, (float)$v)), 1);
	}
}
