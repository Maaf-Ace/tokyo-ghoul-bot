<?php

/**
 * Model da Facção. Representa uma linha da tabela `faccoes`.
 * Esta classe é só estrutura de dados + regrinhas próprias da entidade (passarOTempo).
 * Quem lê/grava no banco é o FaccaoRepository.
 */
class Faccao {

	// Identidade e base
	public string $id;
	public string $nome;
	public int $distritoBase;
	public string $taticaFavorita;
	public string $posturaCivis; // 'protetora' | 'indiferente' | 'predadora'

	// Atributos principais
	public int $poderMilitar;
	public int $suprimentos;
	public int $fome;

	// Comportamento
	public int $agressividade;
	public int $sigilo;

	// Atributos dinâmicos
	public int    $nivelAlerta;
	public int    $posicaoAtual;        // distrito onde a facção está agora
	public ?string $agentesFeridosAte;  // datetime até quando agentes ficam fora (null = recuperados)

	public function __construct(
		string $id,
		string $nome,
		int $distritoBase,
		string $taticaFavorita,
		int $poderMilitar,
		int $suprimentos,
		int $fome,
		int $agressividade,
		int $sigilo,
		string $posturaCivis = 'indiferente',
		int $nivelAlerta = 1,
		int $posicaoAtual = 0,
		?string $agentesFeridosAte = null
	) {
		$this->id = $id;
		$this->nome = $nome;
		$this->distritoBase = $distritoBase;
		$this->taticaFavorita = $taticaFavorita;
		$this->poderMilitar = $poderMilitar;
		$this->suprimentos = $suprimentos;
		$this->fome = $fome;
		$this->agressividade = $agressividade;
		$this->sigilo = $sigilo;
		$this->posturaCivis = $posturaCivis;
		$this->nivelAlerta = $nivelAlerta;
		$this->posicaoAtual = $posicaoAtual ?: $distritoBase;
		$this->agentesFeridosAte = $agentesFeridosAte;
	}

	public static function fromArray(array $linha): self {
		return new self(
			id: $linha['id'],
			nome: $linha['nome'],
			distritoBase: (int) $linha['distrito_base'],
			taticaFavorita: $linha['tatica_favorita'],
			poderMilitar: (int) $linha['poder_militar'],
			suprimentos: (int) $linha['suprimentos'],
			fome: (int) $linha['fome'],
			agressividade: (int) $linha['agressividade'],
			sigilo: (int) $linha['sigilo'],
			posturaCivis: $linha['postura_civis'] ?? 'indiferente',
			nivelAlerta: (int) ($linha['nivel_alerta'] ?? 1),
			posicaoAtual: (int) ($linha['posicao_atual'] ?? $linha['distrito_base']),
			agentesFeridosAte: $linha['agentes_feridos_ate'] ?? null
		);
	}

	public function agentesFeridados(): bool {
		return $this->agentesFeridosAte !== null
			&& new DateTime() < new DateTime($this->agentesFeridosAte);
	}

	/**
	 * Aplica a passagem do tempo (fome, agressividade, sigilo).
	 * Não persiste sozinha — quem chama isso é responsável por salvar via Repository depois.
	 */
	public function passarOTempo(): void {
		if ($this->id === 'ccg') return; // CCG não sofre a degradação do submundo

		$aumentoFome = match (true) {
			$this->suprimentos >= 70 => rand(1, 8),
			$this->suprimentos >= 30 => rand(5, 15),
			default => rand(10, 20),
		};

		$this->fome = min(100, $this->fome + $aumentoFome);

		if ($this->fome >= 80) {
			$this->agressividade = min(100, $this->agressividade + 15);
			$this->sigilo = max(0, $this->sigilo - 20);
		}
	}

	/**
	 * Regra do GDD: "Instinto de Sobrevivência" — facção esmagada ganha sigilo passivamente.
	 */
	public function aplicarInstintoSobrevivencia(int $limiarPoderBaixo = 20): void {
		if ($this->poderMilitar <= $limiarPoderBaixo) {
			$this->sigilo = min(100, $this->sigilo + 10);
		}
	}
}
