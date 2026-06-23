<?php

class Distrito {

	public int $id;
	public string $nome;
	public ?string $bonusDominio;
	public ?string $faccaoDominanteId;
	public int $seguranca;       // pilar: segurança pública
	public int $economia;        // pilar: atividade econômica
	public int $suprimentosPop;  // pilar: suprimentos populacionais
	public int $satisfacaoGeral; // gerado: round((seg+eco+sup)/3)
	public int $nivelAlerta;
	public int $nivelDominacao;
	public string $statusGuerra; // 'pacificado' | 'em_disputa'

	public function __construct(
		int $id,
		string $nome,
		?string $bonusDominio,
		?string $faccaoDominanteId,
		int $seguranca       = 50,
		int $economia        = 50,
		int $suprimentosPop  = 50,
		int $satisfacaoGeral = 50,
		int $nivelAlerta     = 1,
		int $nivelDominacao  = 100,
		string $statusGuerra = 'pacificado'
	) {
		$this->id                = $id;
		$this->nome              = $nome;
		$this->bonusDominio      = $bonusDominio;
		$this->faccaoDominanteId = $faccaoDominanteId;
		$this->seguranca         = max(0, min(100, $seguranca));
		$this->economia          = max(0, min(100, $economia));
		$this->suprimentosPop    = max(0, min(100, $suprimentosPop));
		$this->satisfacaoGeral   = $satisfacaoGeral;
		$this->nivelAlerta       = $nivelAlerta;
		$this->nivelDominacao    = $nivelDominacao;
		$this->statusGuerra      = $statusGuerra;
	}

	public static function fromArray(array $linha): self {
		// Suporte a bancos com colunas novas ou antigas (apoio_civil como fallback)
		$fallback = (int) ($linha['apoio_civil'] ?? 50);
		$seg = (int) ($linha['seguranca']      ?? $fallback);
		$eco = (int) ($linha['economia']       ?? $fallback);
		$sup = (int) ($linha['suprimentos_pop'] ?? $fallback);
		$sat = isset($linha['satisfacao_geral'])
			? (int) $linha['satisfacao_geral']
			: (int) round(($seg + $eco + $sup) / 3);

		return new self(
			id:                (int) $linha['id'],
			nome:              $linha['nome'],
			bonusDominio:      $linha['bonus_dominio'] ?? null,
			faccaoDominanteId: $linha['faccao_dominante_id'] ?? null,
			seguranca:         $seg,
			economia:          $eco,
			suprimentosPop:    $sup,
			satisfacaoGeral:   $sat,
			nivelAlerta:       (int) ($linha['nivel_alerta']    ?? 1),
			nivelDominacao:    (int) ($linha['nivel_dominacao']  ?? 0),
			statusGuerra:      $linha['status_guerra']           ?? 'em_disputa'
		);
	}

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
