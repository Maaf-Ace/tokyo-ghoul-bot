<?php

/**
 * Model do Distrito. Representa uma linha da tabela `distritos`.
 * Implementa a mecânica de "Cabo de Guerra" descrita no GDD.
 */
class Distrito {

	public int $id;
	public string $nome;
	public ?string $bonusDominio;
	public ?string $faccaoDominanteId;
	public int $apoioCivil;
	public int $nivelAlerta;
	public int $nivelDominacao;
	public string $statusGuerra; // 'pacificado' | 'em_disputa'

	public function __construct(
		int $id,
		string $nome,
		?string $bonusDominio,
		?string $faccaoDominanteId,
		int $apoioCivil = 0,
		int $nivelAlerta = 1,
		int $nivelDominacao = 100,
		string $statusGuerra = 'pacificado'
	) {
		$this->id = $id;
		$this->nome = $nome;
		$this->bonusDominio = $bonusDominio;
		$this->faccaoDominanteId = $faccaoDominanteId;
		$this->apoioCivil = $apoioCivil;
		$this->nivelAlerta = $nivelAlerta;
		$this->nivelDominacao = $nivelDominacao;
		$this->statusGuerra = $statusGuerra;
	}

	public static function fromArray(array $linha): self {
		return new self(
			id: (int) $linha['id'],
			nome: $linha['nome'],
			bonusDominio: $linha['bonus_dominio'],
			faccaoDominanteId: $linha['faccao_dominante_id'],
			apoioCivil: (int) $linha['apoio_civil'],
			nivelAlerta: (int) $linha['nivel_alerta'],
			nivelDominacao: (int) $linha['nivel_dominacao'],
			statusGuerra: $linha['status_guerra']
		);
	}

	/**
	 * Aplica dano de dominação (ex: após combate em cima do distrito).
	 * Regra do GDD: abaixo de 50% vira "em_disputa" e perde o bônus de domínio.
	 */
	public function aplicarDanoDominacao(int $dano): void {
		$this->nivelDominacao = max(0, $this->nivelDominacao - $dano);

		if ($this->nivelDominacao < 50) {
			$this->statusGuerra = 'em_disputa';
		}
	}

	public function bonusAtivo(): bool {
		return $this->statusGuerra === 'pacificado' && $this->nivelDominacao >= 50;
	}
}
