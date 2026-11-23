<?php
declare(strict_types=1);

namespace App;

/**
 * Nettoyage du HTML saisi dans l'éditeur (liste blanche de balises/attributs).
 * Tout texte riche enregistré DOIT passer par Html::clean().
 */
final class Html
{
    private const TAGS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'a' => ['href', 'title', 'target'], 'sub' => [], 'sup' => [], 'hr' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'span' => ['class'], 'mark' => [],
    ];
    private const CLASSES = ['text-small', 'text-large', 'text-muted'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $root = $doc->getElementById('__root') ?? $doc->documentElement;
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'template'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                if ($tag === 'div') {
                    // div -> p pour garder la structure
                    $p = $child->ownerDocument->createElement('p');
                    while ($child->firstChild) {
                        $p->appendChild($child->firstChild);
                    }
                    $node->replaceChild($p, $child);
                    $child = $p;
                    $tag = 'p';
                }
                if ($tag === 'h1') {
                    $h = $child->ownerDocument->createElement('h2');
                    while ($child->firstChild) {
                        $h->appendChild($child->firstChild);
                    }
                    $node->replaceChild($h, $child);
                    $child = $h;
                    $tag = 'h2';
                }
                if (!array_key_exists($tag, self::TAGS)) {
                    // balise inconnue: on garde le contenu
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    if (!in_array($name, self::TAGS[$tag], true)) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'a') {
                    $href = trim($child->getAttribute('href'));
                    if (!self::safeUrl($href)) {
                        $child->removeAttribute('href');
                    }
                    if ($child->getAttribute('target') === '_blank') {
                        $child->setAttribute('rel', 'noopener noreferrer');
                    } else {
                        $child->removeAttribute('target');
                    }
                }
                if ($child->hasAttribute('class')) {
                    $cls = array_intersect(preg_split('/\s+/', $child->getAttribute('class')), self::CLASSES);
                    $cls ? $child->setAttribute('class', implode(' ', $cls)) : $child->removeAttribute('class');
                }
                self::walk($child);
            } elseif ($child instanceof \DOMComment) {
                $node->removeChild($child);
            }
        }
    }

    public static function safeUrl(string $url): bool
    {
        if ($url === '' ) {
            return false;
        }
        if ($url[0] === '/' || $url[0] === '#') {
            return !str_starts_with($url, '//');
        }
        return (bool) preg_match('#^(https?:|mailto:|tel:)#i', $url);
    }

    /** Texte brut -> HTML avec retours à la ligne. */
    public static function nl(string $text): string
    {
        return nl2br(e($text), false);
    }
}
