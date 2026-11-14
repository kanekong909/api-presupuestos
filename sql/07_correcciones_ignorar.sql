-- Una corrección marcada como «ignorar» (p. ej. hecha probando) no cuenta para que la IA aprenda.
ALTER TABLE correcciones ADD COLUMN IF NOT EXISTS ignorar TINYINT(1) NOT NULL DEFAULT 0;
