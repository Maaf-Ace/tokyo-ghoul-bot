<?php

class AcoesSemanaRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	/** Retorna a segunda-feira da semana atual como string 'Y-m-d'. */
	private function semanaAtual(): string {
		$dow = (int) date('N'); // 1=Seg ... 7=Dom
		return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
	}

	/** Quantas ações do tipo foram feitas nesta semana. */
	public function getQuantidade(string $faccaoId, string $tipo): int {
		$stmt = $this->db->prepare(
			'SELECT quantidade FROM acoes_semanais
			 WHERE faccao_id = :f AND tipo_acao = :t AND semana = :s LIMIT 1'
		);
		$stmt->execute(['f' => $faccaoId, 't' => $tipo, 's' => $this->semanaAtual()]);
		$row = $stmt->fetch();
		return $row ? (int) $row['quantidade'] : 0;
	}

	/**
	 * Verifica se ainda há espaço para mais uma ação (retorna true = pode prosseguir).
	 */
	public function verificarLimite(string $faccaoId, string $tipo, int $limite): bool {
		return $this->getQuantidade($faccaoId, $tipo) < $limite;
	}

	/** Incrementa o contador da ação nesta semana. */
	public function incrementar(string $faccaoId, string $tipo): void {
		$sql = "INSERT INTO acoes_semanais (faccao_id, tipo_acao, semana, quantidade)
				VALUES (:f, :t, :s, 1)
				ON DUPLICATE KEY UPDATE quantidade = quantidade + 1";
		$this->db->prepare($sql)->execute([
			'f' => $faccaoId,
			't' => $tipo,
			's' => $this->semanaAtual(),
		]);
	}

	/** Apaga registros de semanas passadas. Chamado no tick semanal. */
	public function resetarSemanasAntigas(): int {
		$stmt = $this->db->prepare(
			"DELETE FROM acoes_semanais WHERE semana < :s"
		);
		$stmt->execute(['s' => $this->semanaAtual()]);
		return $stmt->rowCount();
	}

	/** Resumo das ações da CCG nesta semana (para !orcamento e !operacoes). */
	public function resumoCCG(): array {
		$stmt = $this->db->prepare(
			"SELECT tipo_acao, quantidade FROM acoes_semanais
			 WHERE faccao_id = 'ccg' AND semana = :s"
		);
		$stmt->execute(['s' => $this->semanaAtual()]);
		$rows = $stmt->fetchAll();
		$res  = [];
		foreach ($rows as $r) {
			$res[$r['tipo_acao']] = (int) $r['quantidade'];
		}
		return $res;
	}
}
