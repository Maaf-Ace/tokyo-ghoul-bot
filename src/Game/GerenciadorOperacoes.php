<?php

/**
 * Gerencia o ciclo de vida das Operações (ataques/investigações/etc programados no tempo).
 * Usa OperacaoRepository para persistir e checar o que já venceu o prazo.
 */
class GerenciadorOperacoes {

	private OperacaoRepository $operacaoRepo;

	public function __construct(?OperacaoRepository $operacaoRepo = null) {
		$this->operacaoRepo = $operacaoRepo ?? new OperacaoRepository();
	}

	public function iniciarOperacao(string $tipo, string $faccaoId, int $distritoAlvo, int $horasDuracao): Operacao {
		$operacao = new Operacao($tipo, $faccaoId, $distritoAlvo, $horasDuracao);
		$this->operacaoRepo->criar($operacao);
		return $operacao;
	}

	/**
	 * Busca no banco todas as operações cujo prazo já venceu, marca como concluídas
	 * e retorna a lista para quem chamou decidir o que fazer (ex: gerar relatório, postar no Discord).
	 *
	 * @return Operacao[]
	 */
	public function processarConclusoes(): array {
		$prontas = $this->operacaoRepo->listarProntasParaConcluir();

		foreach ($prontas as $operacao) {
			$this->operacaoRepo->marcarConcluida($operacao->id);
			$operacao->status = 'concluido';
		}

		return $prontas;
	}
}
