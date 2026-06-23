<?php

class QuestDistritoRepository {

	private PDO $db;

	private const CATALOGO_PRINCIPAL = [
		['titulo' => 'Dominar a narrativa',     'descricao' => 'Realize uma campanha de midia bem-sucedida no distrito.',              'requer_satisfacao' => 60],
		['titulo' => 'Eliminar a ameaca',        'descricao' => 'Venca um combate direto com uma faccao ghoul no distrito.',            'requer_satisfacao' => 50],
		['titulo' => 'Rede de seguranca',        'descricao' => 'Complete duas patrulhas consecutivas sem incidentes no distrito.',     'requer_satisfacao' => 55],
		['titulo' => 'Lealdade conquistada',     'descricao' => 'Eleve a seguranca do distrito para 70 pontos ou mais.',               'requer_satisfacao' => 70],
		['titulo' => 'Controle de suprimentos',  'descricao' => 'Realize duas operacoes de abastecimento no distrito.',                'requer_satisfacao' => 60],
		['titulo' => 'Neutralizar base inimiga', 'descricao' => 'Detecte e destrua um esconderijo ghoul confirmado (conclusao pelo GM).','requer_satisfacao' => 50],
		['titulo' => 'Territorio pacificado',    'descricao' => 'Mantenha o alerta em nivel 1 por uma semana completa.',               'requer_satisfacao' => 65],
		['titulo' => 'Operacao de inteligencia', 'descricao' => 'Obtenha informacoes criticas via investigacao bem-sucedida.',         'requer_satisfacao' => 55],
	];

	private const CATALOGO_SECUNDARIA = [
		['titulo' => 'Recrutar aliados locais',    'descricao' => 'Recrute um informante fixo no distrito.',                               'requer_satisfacao' => 0],
		['titulo' => 'Abastecimento civil',         'descricao' => 'Realize uma operacao de abastecimento para a populacao.',              'requer_satisfacao' => 0],
		['titulo' => 'Reducao de ameacas',          'descricao' => 'Reduza o nivel de alerta em 2 pontos em uma semana.',                  'requer_satisfacao' => 0],
		['titulo' => 'Relatorio completo',          'descricao' => 'Investigue o distrito com resultado bom ou melhor.',                   'requer_satisfacao' => 0],
		['titulo' => 'Presenca publica',            'descricao' => 'Complete uma patrulha bem avaliada pela populacao.',                   'requer_satisfacao' => 0],
		['titulo' => 'Estabilidade economica',      'descricao' => 'Eleve a economia do distrito para 65 pontos ou mais.',                 'requer_satisfacao' => 0],
		['titulo' => 'Protecao da comunidade',      'descricao' => 'Eleve os suprimentos populacionais para 60 pontos.',                  'requer_satisfacao' => 0],
		['titulo' => 'Identificar infiltrados',     'descricao' => 'Detecte ao menos um movimento ghoul por investigacao no distrito.',    'requer_satisfacao' => 0],
	];

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	/** Retorna todas as quests de um distrito (todas, ou filtradas por tipo). */
	public function listarPorDistrito(int $distritoId, ?string $tipo = null): array {
		if ($tipo) {
			$stmt = $this->db->prepare('SELECT * FROM quests_distrito WHERE distrito_id = :d AND tipo = :t ORDER BY id ASC');
			$stmt->execute(['d' => $distritoId, 't' => $tipo]);
		} else {
			$stmt = $this->db->prepare('SELECT * FROM quests_distrito WHERE distrito_id = :d ORDER BY tipo DESC, id ASC');
			$stmt->execute(['d' => $distritoId]);
		}
		return $stmt->fetchAll();
	}

	/** Retorna apenas quests já reveladas (visíveis aos jogadores). */
	public function listarReveladas(int $distritoId): array {
		$stmt = $this->db->prepare(
			"SELECT * FROM quests_distrito WHERE distrito_id = :d AND status IN ('revelada','concluida') ORDER BY tipo DESC, id ASC"
		);
		$stmt->execute(['d' => $distritoId]);
		return $stmt->fetchAll();
	}

	public function revelar(int $questId): void {
		$this->db->prepare("UPDATE quests_distrito SET status = 'revelada' WHERE id = :id AND status = 'oculta'")
			->execute(['id' => $questId]);
	}

	public function revelarTodasDoDistrito(int $distritoId): void {
		$this->db->prepare("UPDATE quests_distrito SET status = 'revelada' WHERE distrito_id = :d AND status = 'oculta'")
			->execute(['d' => $distritoId]);
	}

	public function concluir(int $questId, string $faccaoId): void {
		$this->db->prepare(
			"UPDATE quests_distrito SET status = 'concluida', faccao_completou = :f, data_conclusao = NOW() WHERE id = :id"
		)->execute(['f' => $faccaoId, 'id' => $questId]);
	}

	public function temPrincipalConcluida(int $distritoId, string $faccaoId): bool {
		$stmt = $this->db->prepare(
			"SELECT 1 FROM quests_distrito WHERE distrito_id = :d AND tipo = 'principal' AND status = 'concluida' AND faccao_completou = :f LIMIT 1"
		);
		$stmt->execute(['d' => $distritoId, 'f' => $faccaoId]);
		return (bool) $stmt->fetch();
	}

	public function temSecundariaConcluida(int $distritoId, string $faccaoId): bool {
		$stmt = $this->db->prepare(
			"SELECT 1 FROM quests_distrito WHERE distrito_id = :d AND tipo = 'secundaria' AND status = 'concluida' AND faccao_completou = :f LIMIT 1"
		);
		$stmt->execute(['d' => $distritoId, 'f' => $faccaoId]);
		return (bool) $stmt->fetch();
	}

	public function contarSecundarias(int $distritoId): int {
		$stmt = $this->db->prepare("SELECT COUNT(*) FROM quests_distrito WHERE distrito_id = :d AND tipo = 'secundaria'");
		$stmt->execute(['d' => $distritoId]);
		return (int) $stmt->fetchColumn();
	}

	/** Remove todas as quests de um distrito (usado ao trocar dominador). */
	public function resetarParaDistrito(int $distritoId): void {
		$this->db->prepare('DELETE FROM quests_distrito WHERE distrito_id = :d')->execute(['d' => $distritoId]);
	}

	/** Gera 1 quest principal + 1 secundária aleatórias para um distrito. */
	public function seedParaDistrito(int $distritoId): void {
		$this->resetarParaDistrito($distritoId);

		$principal  = self::CATALOGO_PRINCIPAL[array_rand(self::CATALOGO_PRINCIPAL)];
		$secundaria = self::CATALOGO_SECUNDARIA[array_rand(self::CATALOGO_SECUNDARIA)];

		$sql = "INSERT INTO quests_distrito (distrito_id, titulo, descricao, tipo, requer_satisfacao) VALUES (:d, :t, :desc, :tipo, :req)";
		$stmt = $this->db->prepare($sql);

		$stmt->execute(['d' => $distritoId, 't' => $principal['titulo'],  'desc' => $principal['descricao'],  'tipo' => 'principal',  'req' => $principal['requer_satisfacao']]);
		$stmt->execute(['d' => $distritoId, 't' => $secundaria['titulo'], 'desc' => $secundaria['descricao'], 'tipo' => 'secundaria', 'req' => $secundaria['requer_satisfacao']]);
	}

	/** Verifica se o distrito pode ser conquistado pela facção (satisfacao >= 80 + quests concluídas). */
	public function verificarConquista(int $distritoId, string $faccaoId, int $satisfacaoGeral): bool {
		if ($satisfacaoGeral < 80) return false;
		if (!$this->temPrincipalConcluida($distritoId, $faccaoId)) return false;
		// Se existe secundária, exige pelo menos 1 concluída
		if ($this->contarSecundarias($distritoId) > 0 && !$this->temSecundariaConcluida($distritoId, $faccaoId)) return false;
		return true;
	}

	public function buscarPorId(int $questId): ?array {
		$stmt = $this->db->prepare('SELECT * FROM quests_distrito WHERE id = :id LIMIT 1');
		$stmt->execute(['id' => $questId]);
		$row = $stmt->fetch();
		return $row ?: null;
	}
}
