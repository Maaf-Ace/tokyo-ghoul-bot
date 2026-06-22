<?php

class MovimentoRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	/** Registra um movimento de facção no log. */
	public function registrar(string $faccaoId, int $origem, int $destino, string $motivo = 'rotina'): void {
		$stmt = $this->db->prepare(
			"INSERT INTO movimentos_faccoes (faccao_id, distrito_origem, distrito_destino, motivo)
			 VALUES (:f, :o, :d, :m)"
		);
		$stmt->execute([
			'f' => $faccaoId,
			'o' => $origem,
			'd' => $destino,
			'm' => $motivo,
		]);
	}

	/**
	 * Retorna movimentos recentes de um distrito dentro de uma janela de horas.
	 * Usado na investigação: CCG detecta se alguém passou por aqui.
	 *
	 * @return array[] Rows com: faccao_id, distrito_origem, distrito_destino, motivo, data_movimento
	 */
	public function listarPorDistrito(int $distritoId, int $horasAtras = 168): array {
		$stmt = $this->db->prepare(
			"SELECT * FROM movimentos_faccoes
			 WHERE (distrito_destino = :d OR distrito_origem = :d2)
			   AND data_movimento >= DATE_SUB(NOW(), INTERVAL :h HOUR)
			 ORDER BY data_movimento DESC"
		);
		$stmt->execute(['d' => $distritoId, 'd2' => $distritoId, 'h' => $horasAtras]);
		return $stmt->fetchAll();
	}

	/**
	 * Movimentos recentes de uma facção específica.
	 *
	 * @return array[] Rows com os campos da tabela
	 */
	public function listarDaFaccao(string $faccaoId, int $horasAtras = 168): array {
		$stmt = $this->db->prepare(
			"SELECT * FROM movimentos_faccoes
			 WHERE faccao_id = :f
			   AND data_movimento >= DATE_SUB(NOW(), INTERVAL :h HOUR)
			 ORDER BY data_movimento DESC"
		);
		$stmt->execute(['f' => $faccaoId, 'h' => $horasAtras]);
		return $stmt->fetchAll();
	}

	/** Última posição conhecida de uma facção baseada no log. */
	public function ultimoMovimento(string $faccaoId): ?array {
		$stmt = $this->db->prepare(
			"SELECT * FROM movimentos_faccoes
			 WHERE faccao_id = :f
			 ORDER BY data_movimento DESC LIMIT 1"
		);
		$stmt->execute(['f' => $faccaoId]);
		$row = $stmt->fetch();
		return $row ?: null;
	}

	/** Remove movimentos com mais de N dias (limpeza periódica). */
	public function limparAntigos(int $dias = 30): int {
		$stmt = $this->db->prepare(
			"DELETE FROM movimentos_faccoes
			 WHERE data_movimento < DATE_SUB(NOW(), INTERVAL :d DAY)"
		);
		$stmt->execute(['d' => $dias]);
		return $stmt->rowCount();
	}
}
