<?php

namespace App\Shop\Services;

use Mews\Purifier\Facades\Purifier;

/**
 * Sanea HTML rico de las secciones de la landing antes de renderizarlo.
 * La landing es pública (sin auth) → el saneo con allowlist es la barrera
 * contra XSS almacenado cuando el editor (SP2) permita pegar/editar HTML.
 */
class LandingHtmlSanitizer
{
    /** Tags/atributos permitidos (formato de texto básico, sin scripts/estilos). */
    // 'target' no se lista: HTML.TargetBlank ya fuerza target="_blank" + rel="noreferrer noopener".
    private const ALLOWED = 'p,br,strong,b,em,i,u,ul,ol,li,a[href|title],h2,h3,h4,blockquote';

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return Purifier::clean($html, [
            'HTML.Allowed' => self::ALLOWED,
            'HTML.TargetBlank' => true,
            'AutoFormat.RemoveEmpty' => true,
        ]);
    }
}
