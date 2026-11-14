<?php
// M1 NORMALIZADOR — deja la entrada como texto limpio y estructurado para la IA.
// Ahora entiende texto (chat, formulario, WhatsApp ya transcrito). Voz e imágenes: cuando haya transcriptor,
// su resultado entra aquí como texto en «extras».

final class Normalizador
{
    /**
     * @param array $entrada  ['texto' => string, 'cliente' =>, 'telefono' =>, 'email' =>, 'canal' =>, 'extras' => [texto…]]
     * @return array ['texto' => string limpio, 'cliente' => …, 'canal' => …]
     */
    public static function normalizar(array $entrada): array
    {
        $partes = [self::limpiar((string) ($entrada['texto'] ?? ''))];
        foreach ((array) ($entrada['extras'] ?? []) as $x) $partes[] = self::limpiar((string) $x);
        $texto = trim(implode("\n", array_filter($partes, fn($p) => $p !== '')));
        if ($texto === '') throw new ErrorApi('Falta el texto del pedido.', 400);
        if (mb_strlen($texto) > 8000) throw new ErrorApi('El pedido es demasiado largo (máximo 8000 caracteres).', 400);

        $t = fn($k) => ($v = trim((string) ($entrada[$k] ?? ''))) === '' ? null : mb_substr($v, 0, 255);
        $email = $t('email');
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ErrorApi('El email no es válido.', 400);
        return ['texto' => $texto, 'cliente' => $t('cliente'), 'telefono' => $t('telefono'), 'email' => $email, 'canal' => $t('canal') ?? 'api'] + Clientes::limpiarDatos($entrada);
    }

    /** Quita ruido: caracteres de control, espacios repetidos, saltos de línea de más, comillas «tipográficas». */
    public static function limpiar(string $s): string
    {
        $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? $s;
        $s = strtr($s, ["\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"', "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'", "\xC2\xA0" => ' ']);
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/ ?\n ?/u', "\n", $s) ?? $s;
        $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
        return trim($s);
    }
}
