<?php

class DistritoRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function buscarPorId(int $id): ?Distrito {
		$stmt = $this->db->prepare('SELECT * FROM distritos WHERE id = :id LIMIT 1');
		$stmt->execute(['id' => $id]);
		$linha = $stmt->fetch();
		return $linha ? Distrito::fromArray($linha) : null;
	}

	/** @return Distrito[] */
	public function listarTodos(): array {
		$stmt = $this->db->query('SELECT * FROM distritos ORDER BY id ASC');
		return array_map(fn($linha) => Distrito::fromArray($linha), $stmt->fetchAll());
	}

	/** @return Distrito[] Distritos controlados por uma facção */
	public function listarPorFaccao(string $faccaoId): array {
		$stmt = $this->db->prepare('SELECT * FROM distritos WHERE faccao_dominante_id = :faccao_id');
		$stmt->execute(['faccao_id' => $faccaoId]);
		return array_map(fn($linha) => Distrito::fromArray($linha), $stmt->fetchAll());
	}

	/** @return Distrito[] */
	public function listarVizinhos(int $distritoId, array $idsVizinhos): array {
		if (empty($idsVizinhos)) return [];
		$placeholders = implode(',', array_fill(0, count($idsVizinhos), '?'));
		$stmt = $this->db->prepare("SELECT * FROM distritos WHERE id IN ($placeholders)");
		$stmt->execute($idsVizinhos);
		return array_map(fn($linha) => Distrito::fromArray($linha), $stmt->fetchAll());
	}

	public function criar(Distrito $distrito): void {
		$sql = "INSERT INTO distritos
			(id, nome, bonus_dominio, faccao_dominante_id, seguranca, economia, suprimentos_pop,
			 nivel_alerta, nivel_dominacao, status_guerra)
			VALUES
			(:id, :nome, :bonus_dominio, :faccao_dominante_id, :seguranca, :economia, :suprimentos_pop,
			 :nivel_alerta, :nivel_dominacao, :status_guerra)";
		$this->db->prepare($sql)->execute($this->paraParametros($distrito));
	}

	public function atualizar(Distrito $distrito): void {
		$sql = "UPDATE distritos SET
			nome                = :nome,
			bonus_dominio       = :bonus_dominio,
			faccao_dominante_id = :faccao_dominante_id,
			seguranca           = :seguranca,
			economia            = :economia,
			suprimentos_pop     = :suprimentos_pop,
			nivel_alerta        = :nivel_alerta,
			nivel_dominacao     = :nivel_dominacao,
			status_guerra       = :status_guerra
			WHERE id = :id";
		$this->db->prepare($sql)->execute($this->paraParametros($distrito));
	}

	public function salvar(Distrito $distrito): void {
		$this->buscarPorId($distrito->id) === null
			? $this->criar($distrito)
			: $this->atualizar($distrito);
	}

	/** Remove domínio do distrito (revolta ou conquest). */
	public function expulsarDominador(int $distritoId): void {
		$this->db->prepare(
			"UPDATE distritos SET faccao_dominante_id = NULL, nivel_dominacao = 0, status_guerra = 'em_disputa' WHERE id = :id"
		)->execute(['id' => $distritoId]);
	}

	/** Aplica conquista de distrito por uma facção. */
	public function conquistar(int $distritoId, string $faccaoId): void {
		$this->db->prepare(
			"UPDATE distritos SET faccao_dominante_id = :f, nivel_dominacao = 100, status_guerra = 'pacificado' WHERE id = :id"
		)->execute(['f' => $faccaoId, 'id' => $distritoId]);
	}

	private function paraParametros(Distrito $distrito): array {
		return [
			'id'                   => $distrito->id,
			'nome'                 => $distrito->nome,
			'bonus_dominio'        => $distrito->bonusDominio,
			'faccao_dominante_id'  => $distrito->faccaoDominanteId,
			'seguranca'            => max(0, min(100, $distrito->seguranca)),
			'economia'             => max(0, min(100, $distrito->economia)),
			'suprimentos_pop'      => max(0, min(100, $distrito->suprimentosPop)),
			'nivel_alerta'         => $distrito->nivelAlerta,
			'nivel_dominacao'      => $distrito->nivelDominacao,
			'status_guerra'        => $distrito->statusGuerra,
		];
	}
}
