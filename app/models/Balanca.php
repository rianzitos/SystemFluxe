<?php

/** Total de desperdício da empresa por refeição/dia; reenvios substituem, não somam. */
class Balanca
{
    public static function registrar(array $dispositivo, array $dados): array
    {
        if ($dispositivo['tipo'] !== 'balanca') {
            throw new InvalidArgumentException('Selecione uma balança.');
        }
        $valor = $dados['desperdicio_kg'] ?? null;
        $kg = is_scalar($valor) && !is_bool($valor) ? filter_var($valor, FILTER_VALIDATE_FLOAT) : false;
        if ($kg === false || !is_finite($kg) || $kg < 0 || $kg > 100000) {
            throw new InvalidArgumentException('Informe um peso entre 0 e 100.000 kg.');
        }
        $tipo = $dados['tipo'] ?? null;
        if (!is_string($tipo) || !isset(Producao::TIPOS[$tipo])) {
            throw new InvalidArgumentException('Selecione a refeição.');
        }
        $data = $dados['data'] ?? date('Y-m-d');
        $dia = is_string($data) ? DateTimeImmutable::createFromFormat('!Y-m-d', $data) : false;
        if (!$dia || $dia->format('Y-m-d') !== $data || $data > date('Y-m-d') || $data < '2000-01-01') {
            throw new InvalidArgumentException('Informe uma data válida, sem ser no futuro.');
        }
        (new Producao())->registrarDesperdicio((int) $dispositivo['empresa_id'], $tipo, (float) $kg, $data);
        return ['mensagem' => 'Total de desperdício registrado.', 'desperdicio_kg' => round($kg, 2), 'tipo' => $tipo, 'data' => $data];
    }

    public static function hoje(int $empresaId): array
    {
        $stmt = Database::connect()->prepare('SELECT tipo, quantidade_desperdicada_kg FROM producao_refeicoes WHERE empresa_id = ? AND data = ?');
        $stmt->execute([$empresaId, date('Y-m-d')]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
