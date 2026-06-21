<?php

class QuinqueRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	/** @return Quinque[] */
	public function listarDaFaccao(string $faccaoId): array {
		$stmt = $this->db->prepare(
			"SELECT * FROM quinques WHERE faccao_id = :faccao_id ORDER BY data_criacao DESC"
		);
		$stmt->execute(['faccao_id' => $faccaoId]);
		return array_map(fn($row) => Quinque::fromArray($row), $stmt->fetchAll());
	}

	public function criar(Quinque $q): void {
		$sql = "INSERT INTO quinques
			(id, nome, tipo_rc, bonus_combate, descricao, ghoul_origem, faccao_id)
			VALUES
			(:id, :nome, :tipo_rc, :bonus_combate, :descricao, :ghoul_origem, :faccao_id)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'id'           => $q->id,
			'nome'         => $q->nome,
			'tipo_rc'      => $q->tipoRc,
			'bonus_combate'=> $q->bonusCombate,
			'descricao'    => $q->descricao,
			'ghoul_origem' => $q->ghoulOrigem,
			'faccao_id'    => $q->faccaoId,
		]);
	}
}
