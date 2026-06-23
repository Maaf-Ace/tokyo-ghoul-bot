<?php

class ConhecimentoCCGRepository {

	private PDO $db;

	// Ordem dos níveis para comparação
	private const NIVEL_ORDEM = ['desconhecido' => 0, 'basico' => 1, 'bom' => 2, 'critico' => 3];

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function buscarPorDistrito(int $distritoId): ?array {
		$stmt = $this->db->prepare('SELECT * FROM conhecimento_ccg WHERE distrito_id = :id LIMIT 1');
		$stmt->execute(['id' => $distritoId]);
		$row = $stmt->fetch();
		return $row ?: null;
	}

	/** @return array Todos os registros indexados por distrito_id */
	public function listarTodos(): array {
		$stmt = $this->db->query('SELECT * FROM conhecimento_ccg');
		$mapa = [];
		foreach ($stmt->fetchAll() as $row) {
			$mapa[(int) $row['distrito_id']] = $row;
		}
		return $mapa;
	}

	/**
	 * Atualiza o conhecimento CCG de um distrito.
	 * Só eleva o nível — nunca abaixa.
	 */
	public function atualizarConhecimento(int $distritoId, string $nivel, array $dados = []): void {
		$atual = $this->buscarPorDistrito($distritoId);
		$nivelAtual = $atual ? $atual['nivel_conhecimento'] : 'desconhecido';

		// Só eleva, nunca abaixa
		if ((self::NIVEL_ORDEM[$nivel] ?? 0) <= (self::NIVEL_ORDEM[$nivelAtual] ?? 0)) {
			$nivel = $nivelAtual;
		}

		$sql = "INSERT INTO conhecimento_ccg (distrito_id, nivel_conhecimento, faccao_conhecida, dominacao_conhecida, tatica_conhecida, poder_aproximado, ultima_investigacao)
		        VALUES (:d, :n, :fac, :dom, :tat, :pod, NOW())
		        ON DUPLICATE KEY UPDATE
		          nivel_conhecimento  = :n2,
		          faccao_conhecida    = COALESCE(:fac2, faccao_conhecida),
		          dominacao_conhecida = COALESCE(:dom2, dominacao_conhecida),
		          tatica_conhecida    = COALESCE(:tat2, tatica_conhecida),
		          poder_aproximado    = COALESCE(:pod2, poder_aproximado),
		          ultima_investigacao = NOW()";

		$this->db->prepare($sql)->execute([
			'd'    => $distritoId,
			'n'    => $nivel,  'n2'   => $nivel,
			'fac'  => $dados['faccao_conhecida']    ?? null, 'fac2' => $dados['faccao_conhecida']    ?? null,
			'dom'  => $dados['dominacao_conhecida'] ?? null, 'dom2' => $dados['dominacao_conhecida'] ?? null,
			'tat'  => $dados['tatica_conhecida']    ?? null, 'tat2' => $dados['tatica_conhecida']    ?? null,
			'pod'  => $dados['poder_aproximado']    ?? null, 'pod2' => $dados['poder_aproximado']    ?? null,
		]);
	}

	/** Garante que todos os distritos têm uma entrada (cria 'desconhecido' se faltar). */
	public function seedTodos(): void {
		$this->db->exec(
			"INSERT IGNORE INTO conhecimento_ccg (distrito_id, nivel_conhecimento)
			 SELECT id, 'desconhecido' FROM distritos"
		);
		// Chiyoda (#15) é a base CCG — sempre conhecida
		$this->atualizarConhecimento(15, 'critico', [
			'faccao_conhecida'    => 'ccg',
			'dominacao_conhecida' => 100,
		]);
	}
}
