<?php

declare(strict_types=1);

namespace App\Services\Products;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Stringable;

final class ProductDescriptionSanitizer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'del', 'div', 'em', 'figcaption',
        'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li',
        'mark', 'ol', 'p', 'pre', 's', 'span', 'strong', 'sub', 'sup', 'table',
        'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /**
     * Elements whose contents must not survive sanitization.
     *
     * @var list<string>
     */
    private const DROP_WITH_CONTENTS = [
        'applet', 'audio', 'base', 'button', 'canvas', 'embed', 'form', 'frame',
        'frameset', 'iframe', 'input', 'link', 'math', 'meta', 'noscript',
        'object', 'option', 'script', 'select', 'source', 'style', 'svg',
        'template', 'textarea', 'track', 'video',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'rel', 'target', 'title'],
        'img' => ['alt', 'height', 'loading', 'src', 'title', 'width'],
        'ol' => ['start', 'type'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
    ];

    /**
     * @var list<string>
     */
    private const GLOBAL_ATTRIBUTES = ['aria-label', 'class', 'dir', 'lang', 'title'];

    public function sanitize(mixed $html): ?string
    {
        if (! is_string($html) && ! $html instanceof Stringable) {
            return null;
        }

        $html = trim((string) $html);

        if ($html === '') {
            return null;
        }

        if (! class_exists(DOMDocument::class)) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div data-sempre-sanitize-root="1">'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        $xpath = new DOMXPath($document);
        $root = $xpath->query('//*[@data-sempre-sanitize-root="1"]')->item(0);

        if (! $root instanceof DOMElement) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        /** @var list<DOMElement> $elements */
        $elements = [];
        foreach ($xpath->query('.//*', $root) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        foreach ($elements as $element) {
            if ($element->parentNode === null) {
                continue;
            }

            $tag = strtolower($element->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENTS, true)) {
                $element->parentNode->removeChild($element);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrap($element);

                continue;
            }

            $allowedAttributes = array_merge(
                self::GLOBAL_ATTRIBUTES,
                self::TAG_ATTRIBUTES[$tag] ?? [],
            );

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);

                if (str_starts_with($name, 'on') || ! in_array($name, $allowedAttributes, true)) {
                    $element->removeAttributeNode($attribute);

                    continue;
                }

                if (in_array($name, ['href', 'src'], true)
                    && ! $this->isSafeUrl($attribute->value, $name === 'src')) {
                    $element->removeAttributeNode($attribute);
                }
            }

            if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $sanitized = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $sanitized .= $document->saveHTML($child) ?: '';
        }

        $sanitized = trim($sanitized);

        return $sanitized !== '' ? $sanitized : null;
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function isSafeUrl(string $url, bool $image): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/u', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowed = $image ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'];

        return in_array($scheme, $allowed, true);
    }
}
