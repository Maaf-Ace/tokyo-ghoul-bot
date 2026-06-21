<?php

class OperacaoRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function criar(Operacao $op): void {
		$sql = "INSERT INTO operacoes_ativas (id, tipo, faccao_id, distrito_alvo, data_fim, status)
			VALUES (:id, :tipo, :faccao_id, :distrito_alvo, :data_fim, :status)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'id' => $op->id,
			'tipo' => $op->tipo,
			'faccao_id' => $op->faccaoId,
			'distrito_alvo' => $op->distritoAlvo,
			'data_fim' => $op->dataFim,
			'status' => $op->status,
		]);
	}

	/** @return Operacao[] Operações ainda pendentes (usado pelo cronjob pra checar conclusão) */
	public function listarPendentes(): array {
		$stmt = $this->db->query("SELECT * FROM operacoes_ativas WHERE status = 'pendente'");
		return array_map(fn($linha) => Operacao::fromArray($linha), $stmt->fetchAll());
	}

	/** @return Operacao[] Operações pendentes cuja data_fim já passou (prontas pra resolver) */
	public function listarProntasParaConcluir(): array {
		$stmt = $this->db->query(
			"SELECT * FROM operacoes_ativas WHERE status = 'pendente' AND data_fim <= NOW()"
		);
		return array_map(fn($linha) => Operacao::fromArray($linha), $stmt->fetchAll());
	}

	public function marcarConcluida(string $id): void {
		$stmt = $this->db->prepare("UPDATE operacoes_ativas SET status = 'concluido' WHERE id = :id");
		$stmt->execute(['id' => $id]);
	}

	public function deletar(string $id): void {
		$stmt = $this->db->prepare('DELETE FROM operacoes_ativas WHERE id = :id');
		$stmt->execute(['id' => $id]);
	}
}
