<?php

class AdjacenciaRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function saoAdjacentes(int $a, int $b): bool {
		if ($a === $b) return true; // mesmo distrito = sempre alcançável
		$stmt = $this->db->prepare(
			'SELECT 1 FROM adjacencias WHERE distrito_a = :a AND distrito_b = :b LIMIT 1'
		);
		$stmt->execute(['a' => $a, 'b' => $b]);
		return (bool) $stmt->fetch();
	}

	/** @return int[] IDs de todos os distritos adjacentes */
	public function getAdjacentes(int $distritoId): array {
		$stmt = $this->db->prepare(
			'SELECT distrito_b FROM adjacencias WHERE distrito_a = :id ORDER BY distrito_b ASC'
		);
		$stmt->execute(['id' => $distritoId]);
		return array_column($stmt->fetchAll(), 'distrito_b');
	}
}
