<?php
// ERROR DE LA API — un error «esperado» con su código HTTP. El mensaje se enseña tal cual: claro y sin detalles internos.

final class ErrorApi extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $http = 400)
    {
        parent::__construct($mensaje, $http);
    }
}
