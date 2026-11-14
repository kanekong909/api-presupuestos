<?php
// CLAUDE — llama a la IA de Anthropic (Messages API) con cURL, sin librerías.
//   $r = Claude::preguntar('interprete', $sistema, $usuario);   // $r['texto'], $r['modelo'], $r['uso']
// Cada «uso» tiene su modelo en config › ia › usos. Reintenta 429/5xx/red; un error de la petición no se reintenta.

final class Claude
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    /** Para las pruebas: respuestas falsas. fn(string $uso, string $sistema, string $usuario): string */
    private static ?Closure $simulador = null;

    /** Lo que ha gastado cada llamada en esta ejecución (modelo y tokens de entrada y salida): sirve para comparar modelos y costes. */
    public static array $consumo = [];

    public static function simular(?callable $fn): void
    {
        self::$simulador = $fn === null ? null : Closure::fromCallable($fn);
    }

    public static function preguntar(string $uso, string $sistema, string $usuario): array
    {
        $conf = (Config::get("ia.usos.$uso", []) ?: []) + ['modelo' => 'claude-sonnet-5-5', 'max_tokens' => 8000];
        if (self::$simulador) {
            return ['texto' => (self::$simulador)($uso, $sistema, $usuario), 'modelo' => 'simulado', 'uso' => []];
        }
        $clave = (string) Config::get('ia.clave', '');
        if ($clave === '' || str_starts_with($clave, 'PON_AQUI')) throw new ErrorApi('Falta la clave de Anthropic en config/config.php (ia › clave).', 500);

        $cuerpo = json_encode([
            'model' => $conf['modelo'],
            'max_tokens' => (int) $conf['max_tokens'],
            'system' => [['type' => 'text', 'text' => $sistema, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $usuario]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $r = self::enviar($cuerpo, $clave, (int) Config::get('ia.reintentos', 2), (int) Config::get('ia.tiempo_maximo', 120));

        if (($r['stop_reason'] ?? '') === 'refusal') throw new ErrorApi('La IA no ha querido responder a este pedido. Reformúlalo.', 422);
        $texto = '';
        foreach ($r['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $texto .= $b['text'];
        self::$consumo[] = ['uso' => $uso, 'modelo' => $r['model'] ?? $conf['modelo'], 'entrada' => (int) ($r['usage']['input_tokens'] ?? 0) + (int) ($r['usage']['cache_creation_input_tokens'] ?? 0) + (int) ($r['usage']['cache_read_input_tokens'] ?? 0), 'salida' => (int) ($r['usage']['output_tokens'] ?? 0)];
        return ['texto' => $texto, 'modelo' => $r['model'] ?? $conf['modelo'], 'uso' => $r['usage'] ?? []];
    }

    private static function enviar(string $cuerpo, string $clave, int $reintentos, int $tiempo): array
    {
        for ($i = 0; ; $i++) {
            $espera = null;
            $ch = curl_init(self::URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $clave, 'anthropic-version: 2023-06-01'],
                CURLOPT_TIMEOUT => $tiempo, CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HEADERFUNCTION => function ($ch, $l) use (&$espera) {
                    if (stripos($l, 'retry-after:') === 0) $espera = (float) trim(substr($l, 12));
                    return strlen($l);
                },
            ]);
            $res = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errRed = curl_error($ch);
            curl_close($ch);
            $json = is_string($res) ? json_decode($res, true) : null;
            if ($res !== false && $http >= 200 && $http < 300 && is_array($json)) return $json;

            if (($res === false || in_array($http, [408, 409, 429], true) || $http >= 500) && $i < $reintentos) {
                usleep((int) (($espera !== null ? min(max($espera, 1), 30) : min(2 ** $i * 2, 20)) * 1_000_000));
                continue;
            }
            error_log("[presupuestos] Anthropic $http: " . substr((string) $res, 0, 300) . " $errRed");
            throw new ErrorApi(match (true) {
                $res === false => 'No se ha podido conectar con la IA de Anthropic.',
                $http === 401 => 'La clave de Anthropic no es válida (ia › clave).',
                $http === 402 => 'La cuenta de Anthropic no tiene saldo.',
                $http === 429 => 'Demasiadas peticiones a la IA: espera un minuto.',
                $http === 529 => 'La IA está saturada: inténtalo en un momento.',
                default => "La IA ha respondido con error $http.",
            }, 502);
        }
    }

    /** Saca el JSON de una respuesta de texto (de la primera «{» a la última «}»). null si no hay. */
    public static function json(string $texto): ?array
    {
        $a = strpos($texto, '{');
        $b = strrpos($texto, '}');
        if ($a === false || $b < $a) return null;
        $j = json_decode(substr($texto, $a, $b - $a + 1), true);
        return is_array($j) ? $j : null;
    }
}
