<?php

declare(strict_types=1);

namespace ColoManager\Support;

use ColoManager\Http\ApiException;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Reduziert Inhalte aus dem kleinen Ticketeditor auf ein bewusst enges
 * HTML-Subset. Skripte, Styles und Event-Attribute erreichen so weder andere
 * Kunden noch spätere Mitarbeiteransichten.
 */
final class TicketHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'code', 'a'];

    /** @return array{html: string, text: string} */
    public function sanitize(string $html): array
    {
        if (strlen($html) > 50_000) {
            throw new ApiException(422, 'Die Ticketnachricht ist zu lang.', 'validation_failed', [
                'field' => 'bodyHtml',
                'maximumCharacters' => 50_000,
            ]);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="ticket-message-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('ticket-message-root');
        if (!$root instanceof DOMElement) {
            return ['html' => '', 'text' => ''];
        }

        $this->cleanChildren($root);
        $safeHtml = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $safeHtml .= $document->saveHTML($child);
        }

        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($safeHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return ['html' => trim($safeHtml), 'text' => trim((string) $text)];
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                $parent->removeChild($node);
                continue;
            }

            $this->cleanChildren($node);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $originalHref = $tag === 'a' ? trim($node->getAttribute('href')) : '';
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $node->removeAttribute($attribute->name);
            }

            if ($tag === 'a') {
                if ($this->isSafeLink($originalHref)) {
                    $node->setAttribute('href', $originalHref);
                    $node->setAttribute('rel', 'noopener noreferrer');
                    $node->setAttribute('target', '_blank');
                }
            }
        }
    }

    private function isSafeLink(string $href): bool
    {
        if ($href === '') {
            return false;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
