<?php

/**
 * Gerencia as ações autônomas das facções Ghoul a cada tick:
 * Caçar (mata fome / aumenta alerta), Contrabandar (ganha suprimentos / perde sigilo)
 * ou Recrutar (ganha poder / perde sigilo e ganha fome).
 */
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

	/** Escolhe e executa uma ação de sobrevivência para a facção. Persiste as mudanças. */
	public function executarParaFaccao(Faccao $faccao): array {
		$acao = $this->escolherAcao($faccao);

		return match ($acao) {
			'caca'         => $this->cacar($faccao),
			'contrabando'  => $this->contrabandear($faccao),
			'recrutamento' => $this->recrutar($faccao),
			default        => [
				'tipo'    => 'sobrevivencia',
				'acao'    => 'inativa',
				'faccao'  => $faccao->nome,
				'faccao_id' => $faccao->id,
				'distrito_id' => null,
				'descricao' => "{$faccao->nome} permaneceu inativa neste ciclo.",
			],
		};
	}

	/** Peso de cada ação baseado nos atributos atuais da facção. */
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

		$faccao->fome         = max(0, $faccao->fome - $reducaoFome);
		$faccao->agressividade = min(100, $faccao->agressividade + 2);
		$this->faccaoRepo->atualizar($faccao);

		$distrito = $this->distritoRepo->buscarPorId($faccao->distritoBase);
		if ($distrito) {
			$distrito->apoioCivil  = max(0, $distrito->apoioCivil - rand(3, 8));
			$distrito->nivelAlerta = min(5, $distrito->nivelAlerta + $aumentoAlerta);
			$this->distritoRepo->atualizar($distrito);
		}

		return [
			'tipo'        => 'sobrevivencia',
			'acao'        => 'caca',
			'faccao'      => $faccao->nome,
			'faccao_id'   => $faccao->id,
			'distrito_id' => $faccao->distritoBase,
			'descricao'   => "{$faccao->nome} saiu para caçar. Fome -{$reducaoFome}. Alerta no distrito +{$aumentoAlerta}.",
		];
	}

	private function contrabandear(Faccao $faccao): array {
		$ganhoSuprimentos = rand(15, 30);
		$perdaSigilo      = rand(5, 15);

		$faccao->suprimentos = min(100, $faccao->suprimentos + $ganhoSuprimentos);
		$faccao->sigilo      = max(0, $faccao->sigilo - $perdaSigilo);
		$this->faccaoRepo->atualizar($faccao);

		return [
			'tipo'        => 'sobrevivencia',
			'acao'        => 'contrabando',
			'faccao'      => $faccao->nome,
			'faccao_id'   => $faccao->id,
			'distrito_id' => null,
			'descricao'   => "{$faccao->nome} fez contrabando. Suprimentos +{$ganhoSuprimentos}, Sigilo -{$perdaSigilo}.",
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
