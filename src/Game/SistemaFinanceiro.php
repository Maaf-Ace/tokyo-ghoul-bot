<?php

/**
 * Gerencia as finanças do Esquadrão Zero (CCG).
 * Controla orçamento semanal, dano colateral e cortes de financiamento.
 */
class SistemaFinanceiro {

	private FinancasCCGRepository $financasRepo;
	private DistritoRepository    $distritoRepo;

	public function __construct(
		?FinancasCCGRepository $financasRepo = null,
		?DistritoRepository    $distritoRepo = null
	) {
		$this->financasRepo = $financasRepo ?? new FinancasCCGRepository();
		$this->distritoRepo = $distritoRepo ?? new DistritoRepository();
	}

	public function getOrcamento(): int {
		return $this->financasRepo->getOrcamento();
	}

	public function getRendaBase(): int {
		return $this->financasRepo->getRendaBase();
	}

	public function getDanoColateral(): int {
		return $this->financasRepo->getDanoColateralPendente();
	}

	/**
	 * Tenta gastar do orçamento CCG.
	 * Retorna false (sem debitar) se o saldo for insuficiente.
	 */
	public function gastar(int $valor): bool {
		if ($this->financasRepo->getOrcamento() < $valor) return false;
		$this->financasRepo->ajustarOrcamento(-$valor);
		return true;
	}

	/** Registra dano colateral — será descontado da renda na próxima semana. */
	public function registrarDanoColateral(int $valor): void {
		$this->financasRepo->adicionarDanoColateral($valor);
	}

	/**
	 * Processa o ciclo financeiro semanal:
	 * 1. Calcula cortes por distritos em estado crítico.
	 * 2. Desconta dano colateral da renda.
	 * 3. Deposita renda líquida no orçamento.
	 *
	 * @return array Resumo com todos os valores processados.
	 */
	public function processarSemana(): array {
		$rendaBase     = $this->financasRepo->getRendaBase();
		$danoColateral = $this->financasRepo->getDanoColateralPendente();
		$corte         = $this->calcularCorteFinanciamento();

		if ($corte > 0) {
			$this->financasRepo->reduzirRendaBase($corte);
		}

		$rendaLiquida  = max(0, $rendaBase - $danoColateral);
		$saldoAnterior = $this->financasRepo->getOrcamento();
		$novoSaldo     = $this->financasRepo->ajustarOrcamento($rendaLiquida);

		$this->financasRepo->zerarDanoColateral();

		$descricao = $corte > 0
			? "Corte de financiamento: ¥{$corte} por distritos em nível crítico."
			: '';

		$this->financasRepo->registrarSemana(
			$rendaBase,
			$danoColateral,
			0,
			$novoSaldo,
			$descricao
		);

		return [
			'renda_base'          => $rendaBase,
			'dano_colateral'      => $danoColateral,
			'corte_financiamento' => $corte,
			'renda_liquida'       => $rendaLiquida,
			'saldo_anterior'      => $saldoAnterior,
			'novo_saldo'          => $novoSaldo,
		];
	}

	/** Retorna a soma de cortes baseados no nível de alerta dos distritos. */
	private function calcularCorteFinanciamento(): int {
		$corte = 0;
		foreach ($this->distritoRepo->listarTodos() as $d) {
			if ($d->nivelAlerta >= 4) $corte += 300;
			elseif ($d->nivelAlerta >= 3) $corte += 100;
		}
		return $corte;
	}

	/** Retorna uma string formatada para exibir no Discord. */
	public function formatarRelatorio(): string {
		$orcamento = $this->getOrcamento();
		$renda     = $this->getRendaBase();
		$dano      = $this->financasRepo->getDanoColateralPendente();
		$liquida   = max(0, $renda - $dano);
		$barras    = min(20, (int) ($orcamento / 500));
		$barra     = str_repeat('█', $barras) . str_repeat('░', 20 - $barras);

		return "💰 **Orçamento CCG — Esquadrão Zero**\n"
		     . "```\n"
		     . "Saldo Atual:      ¥" . number_format($orcamento, 0, ',', '.') . "\n"
		     . "Renda Semanal:    ¥" . number_format($renda, 0, ',', '.') . "\n"
		     . "Dano Pendente:   -¥" . number_format($dano, 0, ',', '.') . "\n"
		     . "Próx. Depósito:   ¥" . number_format($liquida, 0, ',', '.') . " líquido\n"
		     . "\n[$barra]\n"
		     . "```";
	}
}
