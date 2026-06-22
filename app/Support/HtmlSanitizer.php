<?php

namespace App\Support;

/**
 * Sanitização leve do HTML do corpo das notas (editor rico TipTap). Remove o que
 * pode levar a XSS: scripts/estilos/iframes, atributos de evento (on*) e URLs
 * javascript:. É uma camada de defesa — o front nunca injeta o body cru via
 * innerHTML (cards usam stripHtml; o editor reabre via ProseMirror, que descarta
 * nós fora do schema).
 */
class HtmlSanitizer
{
    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Remove blocos perigosos por completo (conteúdo incluído).
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        // Remove tags de abertura perigosas remanescentes (sem par de fechamento).
        $html = preg_replace('#<\s*/?\s*(script|style|iframe|object|embed|form)[^>]*>#i', '', $html);
        // Remove atributos de evento inline: on*="..." / on*='...' / on*=valor.
        $html = preg_replace('#\son[a-z]+\s*=\s*"(?:[^"]*)"#i', '', $html);
        $html = preg_replace("#\son[a-z]+\s*=\s*'(?:[^']*)'#i", '', $html);
        $html = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $html);
        // Neutraliza URLs javascript:/vbscript: em href/src.
        $html = preg_replace('#(href|src)\s*=\s*("|\')\s*(javascript|vbscript):[^"\']*\2#i', '$1=$2#$2', $html);

        return trim($html);
    }
}
