<?php

class FinancasCCGRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function getOrcamento(): int {
		$stmt = $this->db->query("SELECT orcamento FROM faccoes WHERE id = 'ccg' LIMIT 1");
		$row = $stmt->fetch();
		return $row ? (int) $row['orcamento'] : 0;
	}

	public function getRendaBase(): int {
		$stmt = $this->db->query("SELECT renda_base FROM faccoes WHERE id = 'ccg' LIMIT 1");
		$row = $stmt->fetch();
		return $row ? (int) $row['renda_base'] : 5000;
	}

	/** Ajusta o orçamento em $delta (pode ser negativo). Nunca fica abaixo de 0. Retorna novo saldo. */
	public function ajustarOrcamento(int $delta): int {
		$this->db->prepare(
			"UPDATE faccoes SET orcamento = GREATEST(0, orcamento + :delta) WHERE id = 'ccg'"
		)->execute(['delta' => $delta]);
		return $this->getOrcamento();
	}

	public function getDanoColateralPendente(): int {
		$stmt = $this->db->query("SELECT dano_colateral_pendente FROM faccoes WHERE id = 'ccg' LIMIT 1");
		$row = $stmt->fetch();
		return $row ? (int) $row['dano_colateral_pendente'] : 0;
	}

	public function adicionarDanoColateral(int $valor): void {
		$this->db->prepare(
			"UPDATE faccoes SET dano_colateral_pendente = dano_colateral_pendente + :v WHERE id = 'ccg'"
		)->execute(['v' => abs($valor)]);
	}

	public function zerarDanoColateral(): void {
		$this->db->exec("UPDATE faccoes SET dano_colateral_pendente = 0 WHERE id = 'ccg'");
	}

	public function reduzirRendaBase(int $valor): void {
		$this->db->prepare(
			"UPDATE faccoes SET renda_base = GREATEST(500, renda_base - :v) WHERE id = 'ccg'"
		)->execute(['v' => abs($valor)]);
	}

	public function registrarSemana(
		int $rendaRecebida,
		int $danoColateral,
		int $gastosOperacionais,
		int $saldo,
		string $descricao = ''
	): void {
		$sql = "INSERT INTO financas_ccg
			(semana, renda_recebida, dano_colateral, gastos_operacionais, saldo, descricao_gastos)
			VALUES (CURDATE(), :renda, :dano, :gastos, :saldo, :desc)
			ON DUPLICATE KEY UPDATE
			  renda_recebida = :renda, dano_colateral = :dano,
			  gastos_operacionais = :gastos, saldo = :saldo, descricao_gastos = :desc";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'renda' => $rendaRecebida,
			'dano'  => $danoColateral,
			'gastos'=> $gastosOperacionais,
			'saldo' => $saldo,
			'desc'  => $descricao,
		]);
	}

	public function buscarHistorico(int $limite = 4): array {
		$stmt = $this->db->prepare(
			"SELECT * FROM financas_ccg ORDER BY semana DESC LIMIT :limite"
		);
		$stmt->bindValue('limite', $limite, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll();
	}
}
