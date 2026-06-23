<?php

class RotinasSobrevivencia {

	private DistritoRepository $distritoRepo;
	private FaccaoRepository   $faccaoRepo;

	public function __construct(
		?DistritoRepository $distritoRepo = null,
		?FaccaoRepository   $faccaoRepo   = null
	) {
		$this->distritoRepo = $distritoRepo ?? new DistritoRepository();
		$this->faccaoRepo   = $faccaoRepo   ?? new FaccaoRepository();
	}

	public function executarParaFaccao(Faccao $faccao): array {
		$acao = $this->escolherAcao($faccao);
		return match ($acao) {
			'caca'         => $this->cacar($faccao),
			'contrabando'  => $this->contrabandear($faccao),
			'recrutamento' => $this->recrutar($faccao),
			default        => [
				'tipo'        => 'sobrevivencia',
				'acao'        => 'inativa',
				'faccao'      => $faccao->nome,
				'faccao_id'   => $faccao->id,
				'distrito_id' => null,
				'descricao'   => "{$faccao->nome} permaneceu inativa neste ciclo.",
			],
		};
	}

	private function escolherAcao(Faccao $faccao): string {
		$pesos = [
			'caca'         => max(1, $faccao->fome),
			'contrabando'  => max(1, 100 - $faccao->suprimentos),
			'recrutamento' => max(1, 100 - $faccao->poderMilitar),
		];
		$total     = array_sum($pesos);
		$sorteio   = rand(1, $total);
		$acumulado = 0;
		foreach ($pesos as $acao => $peso) {
			$acumulado += $peso;
			if ($sorteio <= $acumulado) return $acao;
		}
		return 'caca';
	}

	private function cacar(Faccao $faccao): array {
		$reducaoFome   = rand(10, 25);
		$aumentoAlerta = rand(1, 3);

		$faccao->fome          = max(0, $faccao->fome - $reducaoFome);
		$faccao->agressividade = min(100, $faccao->agressividade + 2);
		$this->faccaoRepo->atualizar($faccao);

		// Caçada reduz segurança e alerta o distrito onde a facção está
		$distrito = $this->distritoRepo->buscarPorId($faccao->posicaoAtual);
		if ($distrito) {
			$distrito->seguranca   = max(0, $distrito->seguranca - rand(6, 10));
			$distrito->economia    = max(0, $distrito->economia - 5);
			$distrito->nivelAlerta = min(5, $distrito->nivelAlerta + $aumentoAlerta);
			$this->distritoRepo->atualizar($distrito);
		}

		return [
			'tipo'        => 'sobrevivencia',
			'acao'        => 'caca',
			'faccao'      => $faccao->nome,
			'faccao_id'   => $faccao->id,
			'distrito_id' => $faccao->posicaoAtual,
			'descricao'   => "{$faccao->nome} cacou no distrito #{$faccao->posicaoAtual}. Fome -{$reducaoFome}. Seguranca -8. Alerta +{$aumentoAlerta}.",
		];
	}

	private function contrabandear(Faccao $faccao): array {
		$ganhoSuprimentos = rand(15, 30);
		$perdaSigilo      = rand(5, 15);

		$faccao->suprimentos = min(100, $faccao->suprimentos + $ganhoSuprimentos);
		$faccao->sigilo      = max(0, $faccao->sigilo - $perdaSigilo);
		$this->faccaoRepo->atualizar($faccao);

		// Contrabando prejudica economia do distrito
		$distrito = $this->distritoRepo->buscarPorId($faccao->posicaoAtual);
		if ($distrito) {
			$distrito->economia = max(0, $distrito->economia - 10);
			$this->distritoRepo->atualizar($distrito);
		}

		return [
			'tipo'        => 'sobrevivencia',
			'acao'        => 'contrabando',
			'faccao'      => $faccao->nome,
			'faccao_id'   => $faccao->id,
			'distrito_id' => $faccao->posicaoAtual,
			'descricao'   => "{$faccao->nome} fez contrabando. Suprimentos +{$ganhoSuprimentos}, Sigilo -{$perdaSigilo}, Economia local -10.",
		];
	}

	private function recrutar(Faccao $faccao): array {
		$ganhoPoder  = rand(5, 15);
		$perdaSigilo = rand(8, 20);
		$aumentoFome = rand(3, 8);

		$faccao->poderMilitar = min(100, $faccao->poderMilitar + $ganhoPoder);
		$faccao->sigilo       = max(0, $faccao->sigilo - $perdaSigilo);
		$faccao->fome         = min(100, $faccao->fome + $aumentoFome);
		$this->faccaoRepo->atualizar($faccao);

		return [
			'tipo'        => 'sobrevivencia',
			'acao'        => 'recrutamento',
			'faccao'      => $faccao->nome,
			'faccao_id'   => $faccao->id,
			'distrito_id' => null,
			'descricao'   => "{$faccao->nome} recrutou membros. Poder +{$ganhoPoder}, Sigilo -{$perdaSigilo}, Fome +{$aumentoFome}.",
		];
	}
}
