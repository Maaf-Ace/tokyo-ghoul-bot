<?php

/**
 * Repositório de Facções. Toda leitura/escrita na tabela `faccoes` passa por aqui.
 * Nunca monte SQL concatenando string — sempre prepared statements.
 */
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
		return array_map(fn($linha) => Faccao::fromArray($linha), $stmt->fetchAll());
	}

	/** @return Faccao[] Todas as facções exceto a CCG (útil pro motor de eventos) */
	public function listarFaccoesGhoul(): array {
		$stmt = $this->db->query("SELECT * FROM faccoes WHERE id != 'ccg' ORDER BY nome ASC");
		return array_map(fn($linha) => Faccao::fromArray($linha), $stmt->fetchAll());
	}

	public function criar(Faccao $faccao): void {
		$sql = "INSERT INTO faccoes
			(id, nome, distrito_base, tatica_favorita, postura_civis, poder_militar, suprimentos, fome, agressividade, sigilo, nivel_alerta)
			VALUES
			(:id, :nome, :distrito_base, :tatica_favorita, :postura_civis, :poder_militar, :suprimentos, :fome, :agressividade, :sigilo, :nivel_alerta)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($this->paraParametros($faccao));
	}

	public function atualizar(Faccao $faccao): void {
		$sql = "UPDATE faccoes SET
			nome = :nome,
			distrito_base = :distrito_base,
			tatica_favorita = :tatica_favorita,
			postura_civis = :postura_civis,
			poder_militar = :poder_militar,
			suprimentos = :suprimentos,
			fome = :fome,
			agressividade = :agressividade,
			sigilo = :sigilo,
			nivel_alerta = :nivel_alerta
			WHERE id = :id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($this->paraParametros($faccao));
	}

	/** Insere se não existir, atualiza se existir. Útil pra seed de dados de teste. */
	public function salvar(Faccao $faccao): void {
		$this->buscarPorId($faccao->id) === null
			? $this->criar($faccao)
			: $this->atualizar($faccao);
	}

	public function deletar(string $id): void {
		$stmt = $this->db->prepare('DELETE FROM faccoes WHERE id = :id');
		$stmt->execute(['id' => $id]);
	}

	private function paraParametros(Faccao $faccao): array {
		return [
			'id' => $faccao->id,
			'nome' => $faccao->nome,
			'distrito_base' => $faccao->distritoBase,
			'tatica_favorita' => $faccao->taticaFavorita,
			'postura_civis' => $faccao->posturaCivis,
			'poder_militar' => $faccao->poderMilitar,
			'suprimentos' => $faccao->suprimentos,
			'fome' => $faccao->fome,
			'agressividade' => $faccao->agressividade,
			'sigilo' => $faccao->sigilo,
			'nivel_alerta' => $faccao->nivelAlerta,
		];
	}
}
