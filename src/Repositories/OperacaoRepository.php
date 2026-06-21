<?php

class OperacaoRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function criar(Operacao $op): void {
		$sql = "INSERT INTO operacoes_ativas
			(id, tipo, faccao_id, distrito_alvo, data_fim, status, resultado, solicitante_discord_id)
			VALUES
			(:id, :tipo, :faccao_id, :distrito_alvo, :data_fim, :status, :resultado, :solicitante_discord_id)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'id'                     => $op->id,
			'tipo'                   => $op->tipo,
			'faccao_id'              => $op->faccaoId,
			'distrito_alvo'          => $op->distritoAlvo,
			'data_fim'               => $op->dataFim,
			'status'                 => $op->status,
			'resultado'              => $op->resultado,
			'solicitante_discord_id' => $op->solicitanteDiscordId,
		]);
	}

	/** @return Operacao[] Operações ainda pendentes */
	public function listarPendentes(): array {
		$stmt = $this->db->query("SELECT * FROM operacoes_ativas WHERE status = 'pendente'");
		return array_map(fn($linha) => Operacao::fromArray($linha), $stmt->fetchAll());
	}

	/** @return Operacao[] Operações pendentes cujo prazo já venceu */
	public function listarProntasParaConcluir(): array {
		$stmt = $this->db->query(
			"SELECT * FROM operacoes_ativas WHERE status = 'pendente' AND data_fim <= NOW()"
		);
		return array_map(fn($linha) => Operacao::fromArray($linha), $stmt->fetchAll());
	}

	/**
	 * Verifica se já existe uma operação do mesmo tipo+distrito pendente.
	 * Use distritoAlvo = 0 para tipos que não têm distrito (ex: pesquisa).
	 */
	public function temPendente(string $tipo, string $faccaoId, int $distritoAlvo = 0): bool {
		if ($distritoAlvo > 0) {
			$stmt = $this->db->prepare(
				"SELECT id FROM operacoes_ativas
				 WHERE tipo = :tipo AND faccao_id = :fid AND distrito_alvo = :d AND status = 'pendente'
				 LIMIT 1"
			);
			$stmt->execute(['tipo' => $tipo, 'fid' => $faccaoId, 'd' => $distritoAlvo]);
		} else {
			$stmt = $this->db->prepare(
				"SELECT id FROM operacoes_ativas
				 WHERE tipo = :tipo AND faccao_id = :fid AND status = 'pendente'
				 LIMIT 1"
			);
			$stmt->execute(['tipo' => $tipo, 'fid' => $faccaoId]);
		}
		return (bool) $stmt->fetch();
	}

	public function marcarConcluida(string $id, ?string $resultado = null): void {
		$stmt = $this->db->prepare(
			"UPDATE operacoes_ativas SET status = 'concluido', resultado = :resultado WHERE id = :id"
		);
		$stmt->execute(['id' => $id, 'resultado' => $resultado]);
	}

	public function deletar(string $id): void {
		$stmt = $this->db->prepare('DELETE FROM operacoes_ativas WHERE id = :id');
		$stmt->execute(['id' => $id]);
	}
}
