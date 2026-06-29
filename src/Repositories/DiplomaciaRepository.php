<?php

/**
 * Repositório de Diplomacia. Tabela M:N entre facções, nível de afinidade -100 a +100.
 * Convenção: para evitar duplicidade (A,B) e (B,A), sempre armazenamos com faccao_a < faccao_b
 * alfabeticamente. Os métodos abaixo cuidam disso por você.
 */
class DiplomaciaRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	private function ordenarPar(string $a, string $b): array {
		return $a <= $b ? [$a, $b] : [$b, $a];
	}

	public function getNivelAfinidade(string $faccaoA, string $faccaoB): int {
		[$a, $b] = $this->ordenarPar($faccaoA, $faccaoB);

		$stmt = $this->db->prepare(
			'SELECT nivel_afinidade FROM diplomacia WHERE faccao_a = :a AND faccao_b = :b LIMIT 1'
		);
		$stmt->execute(['a' => $a, 'b' => $b]);
		$linha = $stmt->fetch();

		return $linha ? (int) $linha['nivel_afinidade'] : 0; // default neutro se não houver registro
	}

	/** Retorna true se as facções são aliadas o suficiente pra evitar fogo amigo (limiar ajustável). */
	public function saoAliadas(string $faccaoA, string $faccaoB, int $limiarAlianca = 50): bool {
		return $this->getNivelAfinidade($faccaoA, $faccaoB) >= $limiarAlianca;
	}

	public function definirAfinidade(string $faccaoA, string $faccaoB, int $nivel): void {
		[$a, $b] = $this->ordenarPar($faccaoA, $faccaoB);
		$nivel = max(-100, min(100, $nivel));

		$sql = "INSERT INTO diplomacia (faccao_a, faccao_b, nivel_afinidade)
			VALUES (:a, :b, :nivel)
			ON DUPLICATE KEY UPDATE nivel_afinidade = :nivel2";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['a' => $a, 'b' => $b, 'nivel' => $nivel, 'nivel2' => $nivel]);
	}

	public function ajustarAfinidade(string $faccaoA, string $faccaoB, int $delta): void {
		$atual = $this->getNivelAfinidade($faccaoA, $faccaoB);
		$this->definirAfinidade($faccaoA, $faccaoB, $atual + $delta);
	}
}

/**
 * Repositório do Histórico de Combates. A "memória" do bot para fins de forense
 * (usada pela equipe de investigação dos Corvos).
 */
class HistoricoCombateRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function registrar(
		string $atacanteId,
		string $defensorId,
		string $taticaAtacante,
		string $taticaDefensor,
		string $vencedorId,
		bool   $derrotaSinistra = false,
		bool   $escolhaManual   = false
	): void {
		$sql = "INSERT INTO historico_combates
			(atacante_id, defensor_id, tatica_atacante, tatica_defensor, vencedor_id, derrota_sinistra, escolha_manual)
			VALUES (:atacante_id, :defensor_id, :tatica_atacante, :tatica_defensor, :vencedor_id, :derrota_sinistra, :escolha_manual)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'atacante_id'      => $atacanteId,
			'defensor_id'      => $defensorId,
			'tatica_atacante'  => $taticaAtacante,
			'tatica_defensor'  => $taticaDefensor,
			'vencedor_id'      => $vencedorId,
			'derrota_sinistra' => (int) $derrotaSinistra,
			'escolha_manual'   => (int) $escolhaManual,
		]);
	}

	/** @return array Últimos combates de uma facção (como atacante OU defensor), mais recentes primeiro */
	public function buscarHistoricoDaFaccao(string $faccaoId, int $limite = 10): array {
		$stmt = $this->db->prepare(
			"SELECT * FROM historico_combates
			 WHERE atacante_id = :id OR defensor_id = :id2
			 ORDER BY data_combate DESC LIMIT :limite"
		);
		$stmt->bindValue('id', $faccaoId);
		$stmt->bindValue('id2', $faccaoId);
		$stmt->bindValue('limite', $limite, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll();
	}

	/**
	 * Monta a "memória de guerra" no formato que o MotorEventos espera:
	 * contagem de quantas vezes a facção usou cada tática recentemente.
	 * Usado para alimentar o parâmetro $memoriaInimigo de escolherTatica().
	 */
	/** Retorna as últimas N táticas usadas pela facção como ATACANTE (para exibir no alerta de confronto). */
	public function getUltimasTaticasAtacante(string $faccaoId, int $limite = 5): array {
		$stmt = $this->db->prepare(
			"SELECT tatica_atacante, data_combate
			 FROM historico_combates
			 WHERE atacante_id = :id
			 ORDER BY data_combate DESC LIMIT :limite"
		);
		$stmt->bindValue('id', $faccaoId);
		$stmt->bindValue('limite', $limite, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getMemoriaTaticaDaFaccao(string $faccaoId, int $ultimosNCombates = 5): array {
		$stmt = $this->db->prepare(
			"SELECT tatica_atacante, tatica_defensor, atacante_id, defensor_id
			 FROM historico_combates
			 WHERE atacante_id = :id OR defensor_id = :id2
			 ORDER BY data_combate DESC LIMIT :limite"
		);
		$stmt->bindValue('id', $faccaoId);
		$stmt->bindValue('id2', $faccaoId);
		$stmt->bindValue('limite', $ultimosNCombates, PDO::PARAM_INT);
		$stmt->execute();
		$linhas = $stmt->fetchAll();

		$memoria = ['emboscada' => 0, 'rush' => 0, 'defesa' => 0];

		foreach ($linhas as $linha) {
			// Pega a tática que ESSA facção usou no combate (como atacante ou como defensor)
			$taticaUsada = ($linha['atacante_id'] === $faccaoId)
				? $linha['tatica_atacante']
				: $linha['tatica_defensor'];

			if (isset($memoria[$taticaUsada])) {
				$memoria[$taticaUsada]++;
			}
		}

		return $memoria;
	}
}
