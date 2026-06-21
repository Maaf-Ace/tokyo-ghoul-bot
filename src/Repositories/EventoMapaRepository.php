<?php

class EventoMapaRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	/** @return EventoMapa[] */
	public function listarAtivos(): array {
		$stmt = $this->db->query(
			"SELECT * FROM eventos_mapa WHERE status = 'ativo' AND data_fim > NOW() ORDER BY data_inicio DESC"
		);
		return array_map(fn($row) => EventoMapa::fromArray($row), $stmt->fetchAll());
	}

	/** @return EventoMapa[] */
	public function listarAtivosPorDistrito(int $distritoId): array {
		$stmt = $this->db->prepare(
			"SELECT * FROM eventos_mapa WHERE distrito_id = :id AND status = 'ativo' AND data_fim > NOW()"
		);
		$stmt->execute(['id' => $distritoId]);
		return array_map(fn($row) => EventoMapa::fromArray($row), $stmt->fetchAll());
	}

	public function criar(
		string $tipo,
		string $titulo,
		string $descricao,
		int $distritoId,
		string $efeitoTipo,
		int $efeitoValor,
		string $dataFim
	): void {
		$sql = "INSERT INTO eventos_mapa
			(tipo, titulo, descricao, distrito_id, efeito_tipo, efeito_valor, data_fim)
			VALUES
			(:tipo, :titulo, :descricao, :distrito_id, :efeito_tipo, :efeito_valor, :data_fim)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'tipo'        => $tipo,
			'titulo'      => $titulo,
			'descricao'   => $descricao,
			'distrito_id' => $distritoId,
			'efeito_tipo' => $efeitoTipo,
			'efeito_valor'=> $efeitoValor,
			'data_fim'    => $dataFim,
		]);
	}

	public function expirarEventosAntigos(): int {
		$stmt = $this->db->prepare(
			"UPDATE eventos_mapa SET status = 'expirado' WHERE data_fim <= NOW() AND status = 'ativo'"
		);
		$stmt->execute();
		return $stmt->rowCount();
	}
}
