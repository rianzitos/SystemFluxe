-- Executar no banco SICAPDA existente antes de publicar o código. Preserva dispositivos e dados.
ALTER TABLE dispositivos_iot MODIFY tipo ENUM('rfid', 'biometria', 'hibrido', 'balanca') NOT NULL DEFAULT 'rfid';
