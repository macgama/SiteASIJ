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
        $html = trim(self::cfDecode($html));
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
        return self::tidy(trim($out));
    }

    /**
     * Adresses e-mail masquées par Cloudflare (pages copiées depuis un site protégé):
     * « [email protected] » et liens /cdn-cgi/l/email-protection#… redeviennent des liens mailto.
     */
    public static function cfDecode(string $html): string
    {
        if (!str_contains($html, 'cfemail') && !str_contains($html, 'email-protection')) {
            return $html;
        }
        $dec = static function (string $hex): string {
            if (strlen($hex) < 4 || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
                return '';
            }
            $k = hexdec(substr($hex, 0, 2));
            $out = '';
            for ($i = 2; $i < strlen($hex); $i += 2) {
                $out .= chr(hexdec(substr($hex, $i, 2)) ^ $k);
            }
            return filter_var($out, FILTER_VALIDATE_EMAIL) ? $out : '';
        };
        $html = preg_replace_callback('#<(span|a)\b[^>]*data-cfemail="([0-9a-fA-F]+)"[^>]*>.*?</\1>#is', function ($m) use ($dec) {
            $e = $dec($m[2]);
            return $e !== '' ? htmlspecialchars($e, ENT_QUOTES, 'UTF-8') : $m[0];
        }, $html) ?? $html;
        $html = preg_replace_callback('#href="[^"]*?email-protection\#([0-9a-fA-F]+)"#i', function ($m) use ($dec) {
            $e = $dec($m[1]);
            return $e !== '' ? 'href="mailto:' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '"' : $m[0];
        }, $html) ?? $html;
        // texte du lien encore masqué: on affiche l'adresse du lien
        return preg_replace_callback('#(<a\b[^>]*href="mailto:([^"?]+)[^"]*"[^>]*>)(.*?)(</a>)#is', function ($m) {
            $inner = preg_replace('/\[email(?:&nbsp;|&\#160;|\x{a0}|\s)protected\]/iu', $m[2], $m[3]) ?? $m[3];
            return $m[1] . $inner . $m[4];
        }, $html) ?? $html;
    }

    /**
     * Supprime les grands vides hérités des copier-coller (paragraphes vides, suites de <br>).
     * Utilisé à l'enregistrement et à l'affichage (contenus déjà importés).
     */
    public static function tidy(string $html): string
    {
        $sp = '(?:\s|&nbsp;|&\#160;|\x{a0}|<br\s*/?>|</?(?:strong|b|em|i|u|span)\b[^>]*>)';
        $prev = null;
        while ($prev !== $html) {
            $prev = $html;
            $html = preg_replace('#<(p|h2|h3|h4|li|strong|b|em|i|span)\b[^>]*>' . $sp . '*</\1>#iu', '', $html) ?? $html;
        }
        $html = preg_replace('#(<p\b[^>]*>)(?:\s|&nbsp;|&\#160;|\x{a0}|<br\s*/?>)+#iu', '$1', $html) ?? $html;
        $html = preg_replace('#(?:\s|&nbsp;|&\#160;|\x{a0}|<br\s*/?>)+(</p>)#iu', '$1', $html) ?? $html;
        $html = preg_replace('#(<br\s*/?>\s*){2,}#i', '<br>', $html) ?? $html;
        return trim($html);
    }

    /**
     * Mise en forme à l'affichage: tableaux défilants sur mobile, première ligne en en-tête
     * quand elle ne contient que du gras ou des majuscules (tableaux copiés de Jimdo).
     */
    public static function display(string $html): string
    {
        $html = self::tidy(self::cfDecode($html));
        if (!str_contains($html, '<table')) {
            return $html;
        }
        return preg_replace_callback('#<table\b[^>]*>.*?</table>#is', function ($m) {
            $t = $m[0];
            if (!str_contains($t, '<th') && preg_match('#<tr\b[^>]*>(.*?)</tr>#is', $t, $r)) {
                preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $r[1], $c);
                $head = count($c[1]) > 1;
                foreach ($c[1] as $cell) {
                    $txt = trim(str_replace("\u{a0}", ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    $bold = (bool) preg_match('#^\s*(<p[^>]*>\s*)?<(strong|b)\b#i', $cell);
                    $upper = $txt !== '' && mb_strtoupper($txt) === $txt && preg_match('/\p{L}/u', $txt);
                    if ($txt !== '' && !$bold && !$upper) {
                        $head = false;
                    }
                }
                if ($head) {
                    $row = preg_replace('#<td\b#i', '<td class="is-head"', $r[0]) ?? $r[0];
                    $t = substr_replace($t, $row, (int) strpos($t, $r[0]), strlen($r[0]));
                }
            }
            return '<div class="table-wrap">' . $t . '</div>';
        }, $html) ?? $html;
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
