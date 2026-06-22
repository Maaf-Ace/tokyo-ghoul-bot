<?php

class FaccaoRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function buscarPorId(string $id): ?Faccao {
		$stmt = $this->db->prepare('SELECT * FROM faccoes WHERE id = :id LIMIT 1');
		$stmt->execute(['id' => $id]);
		$linha = $stmt->fetch();
		return $linha ? Faccao::fromArray($linha) : null;
	}

	/** @return Faccao[] */
	public function listarTodas(): array {
		$stmt = $this->db->query('SELECT * FROM faccoes ORDER BY nome ASC');
		return array_map(fn($l) => Faccao::fromArray($l), $stmt->fetchAll());
	}

	/** @return Faccao[] Facções não-CCG */
	public function listarFaccoesGhoul(): array {
		$stmt = $this->db->query("SELECT * FROM faccoes WHERE id != 'ccg' ORDER BY nome ASC");
		return array_map(fn($l) => Faccao::fromArray($l), $stmt->fetchAll());
	}

	public function criar(Faccao $faccao): void {
		$sql = "INSERT INTO faccoes
			(id, nome, distrito_base, tatica_favorita, postura_civis, poder_militar,
			 suprimentos, fome, agressividade, sigilo, nivel_alerta, posicao_atual, agentes_feridos_ate)
			VALUES
			(:id, :nome, :distrito_base, :tatica_favorita, :postura_civis, :poder_militar,
			 :suprimentos, :fome, :agressividade, :sigilo, :nivel_alerta, :posicao_atual, :agentes_feridos_ate)";
		$this->db->prepare($sql)->execute($this->paraParametros($faccao));
	}

	public function atualizar(Faccao $faccao): void {
		$sql = "UPDATE faccoes SET
			nome                 = :nome,
			distrito_base        = :distrito_base,
			tatica_favorita      = :tatica_favorita,
			postura_civis        = :postura_civis,
			poder_militar        = :poder_militar,
			suprimentos          = :suprimentos,
			fome                 = :fome,
			agressividade        = :agressividade,
			sigilo               = :sigilo,
			nivel_alerta         = :nivel_alerta,
			posicao_atual        = :posicao_atual,
			agentes_feridos_ate  = :agentes_feridos_ate
			WHERE id = :id";
		$this->db->prepare($sql)->execute($this->paraParametros($faccao));
	}

	public function salvar(Faccao $faccao): void {
		$this->buscarPorId($faccao->id) === null ? $this->criar($faccao) : $this->atualizar($faccao);
	}

	public function deletar(string $id): void {
		$this->db->prepare('DELETE FROM faccoes WHERE id = :id')->execute(['id' => $id]);
	}

	private function paraParametros(Faccao $faccao): array {
		return [
			'id'                   => $faccao->id,
			'nome'                 => $faccao->nome,
			'distrito_base'        => $faccao->distritoBase,
			'tatica_favorita'      => $faccao->taticaFavorita,
			'postura_civis'        => $faccao->posturaCivis,
			'poder_militar'        => $faccao->poderMilitar,
			'suprimentos'          => $faccao->suprimentos,
			'fome'                 => $faccao->fome,
			'agressividade'        => $faccao->agressividade,
			'sigilo'               => $faccao->sigilo,
			'nivel_alerta'         => $faccao->nivelAlerta,
			'posicao_atual'        => $faccao->posicaoAtual,
			'agentes_feridos_ate'  => $faccao->agentesFeridosAte,
		];
	}
}
