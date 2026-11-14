-- Sinónimos de la empresa: cómo lo dice el cliente → cómo se llama en la base. Salen del uso real (rodaje).
-- La IA los usa para entender el pedido y el buscador del catálogo los usa para encontrar lo que se pide con otras palabras.
-- Se puede ejecutar varias veces (no repite un término que ya existe). Se pueden añadir más filas a mano en la tabla `sinonimos`.

INSERT INTO sinonimos (grupo, termino, concepto_canonico)
SELECT v.g, v.t, v.c FROM (
  SELECT 'Fotovoltaica' g, 'paneles solares' t, 'módulo fotovoltaico' c
  UNION ALL SELECT 'Fotovoltaica', 'placas solares', 'módulo fotovoltaico'
  UNION ALL SELECT 'Fotovoltaica', 'paneles fotovoltaicos', 'módulo fotovoltaico'
  UNION ALL SELECT 'Fotovoltaica', 'placas fotovoltaicas', 'módulo fotovoltaico'
  UNION ALL SELECT 'Fotovoltaica', 'panel solar', 'módulo fotovoltaico'
  UNION ALL SELECT 'Fotovoltaica', 'placa solar', 'módulo fotovoltaico'
  UNION ALL SELECT 'Fotovoltaica', 'panel fotovoltaico', 'módulo fotovoltaico'
  UNION ALL SELECT 'Fotovoltaica', 'placa fotovoltaica', 'módulo fotovoltaico'
  UNION ALL SELECT 'Vehículo eléctrico', 'wallbox', 'cargador de vehículo eléctrico'
  UNION ALL SELECT 'Vehículo eléctrico', 'punto de recarga', 'cargador de vehículo eléctrico'
  UNION ALL SELECT 'Vehículo eléctrico', 'cargador de coche', 'cargador de vehículo eléctrico'
  UNION ALL SELECT 'Vehículo eléctrico', 'cargador de coche eléctrico', 'cargador de vehículo eléctrico'
  UNION ALL SELECT 'Vehículo eléctrico', 'cargador ve', 'cargador de vehículo eléctrico'
  UNION ALL SELECT 'Electricidad', 'pia', 'interruptor magnetotérmico'
  UNION ALL SELECT 'Electricidad', 'magneto', 'interruptor magnetotérmico'
  UNION ALL SELECT 'Electricidad', 'magnetotermico', 'interruptor magnetotérmico'
  UNION ALL SELECT 'Electricidad', 'magnetotermicos', 'interruptor magnetotérmico'
  UNION ALL SELECT 'Electricidad', 'icp', 'interruptor de control de potencia'
  UNION ALL SELECT 'Electricidad', 'zuko', 'base de enchufe schuko'
  UNION ALL SELECT 'Electricidad', 'enchufe schuko', 'base de enchufe schuko'
  UNION ALL SELECT 'Electricidad', 'boletín eléctrico', 'certificado de instalación eléctrica'
  UNION ALL SELECT 'Electricidad', 'boletin electrico', 'certificado de instalación eléctrica'
  UNION ALL SELECT 'Electricidad', 'boletín', 'certificado de instalación eléctrica'
  UNION ALL SELECT 'Electricidad', 'rebt', 'certificado de instalación eléctrica'
  UNION ALL SELECT 'Electricidad', 'cuadro general', 'cuadro eléctrico de vivienda'
  UNION ALL SELECT 'Electricidad', 'toma de tierra', 'pica de tierra'
) v
WHERE NOT EXISTS (SELECT 1 FROM sinonimos s WHERE s.termino = v.t);
