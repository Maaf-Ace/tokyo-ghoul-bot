<?php

class SistemaMovimento {

	private FaccaoRepository     $faccaoRepo;
	private DistritoRepository   $distritoRepo;
	private MovimentoRepository  $movimentoRepo;
	private AdjacenciaRepository $adjacenciaRepo;

	public function __construct(
		?FaccaoRepository     $faccaoRepo     = null,
		?DistritoRepository   $distritoRepo   = null,
		?MovimentoRepository  $movimentoRepo  = null,
		?AdjacenciaRepository $adjacenciaRepo = null
	) {
		$this->faccaoRepo     = $faccaoRepo     ?? new FaccaoRepository();
		$this->distritoRepo   = $distritoRepo   ?? new DistritoRepository();
		$this->movimentoRepo  = $movimentoRepo  ?? new MovimentoRepository();
		$this->adjacenciaRepo = $adjacenciaRepo ?? new AdjacenciaRepository();
	}

	// ─── Movimento manual da CCG ──────────────────────────────────────────────

	public function moverCCG(int $distritoDestino): array {
		$ccg = $this->faccaoRepo->buscarPorId('ccg');
		if (!$ccg) return ['ok' => false, 'mensagem' => 'Faccao CCG nao encontrada.'];

		$distrito = $this->distritoRepo->buscarPorId($distritoDestino);
		if (!$distrito) return ['ok' => false, 'mensagem' => "Distrito #{$distritoDestino} nao existe."];

		if (!$this->adjacenciaRepo->saoAdjacentes($ccg->posicaoAtual, $distritoDestino)) {
			$adjacentes = implode(', ', $this->adjacenciaRepo->getAdjacentes($ccg->posicaoAtual));
			return [
				'ok'       => false,
				'mensagem' => "Movimento invalido. Do distrito #{$ccg->posicaoAtual} so e possivel ir para: {$adjacentes}.",
			];
		}

		$origem = $ccg->posicaoAtual;
		$ccg->posicaoAtual = $distritoDestino;
		$this->faccaoRepo->atualizar($ccg);
		$this->movimentoRepo->registrar('ccg', $origem, $distritoDestino, 'manual');

		return [
			'ok'       => true,
			'mensagem' => "CCG deslocou-se do distrito #{$origem} ({$this->nomDistrito($origem)}) para #{$distritoDestino} ({$distrito->nome}).",
		];
	}

	// ─── IA de movimento das facções ghoul ───────────────────────────────────

	public function executarMovimentoIA(): array {
		$eventos = [];
		foreach ($this->faccaoRepo->listarFaccoesGhoul() as $faccao) {
			$resultado = $this->decidirMovimento($faccao);
			if ($resultado) $eventos[] = $resultado;
		}
		return $eventos;
	}

	private function decidirMovimento(Faccao $faccao): ?array {
		if (rand(1, 100) <= 60) return null; // 60% de chance de ficar parado

		$adjacentes = $this->adjacenciaRepo->getAdjacentes($faccao->posicaoAtual);
		if (empty($adjacentes)) return null;

		$motivo  = $this->escolherMotivoMovimento($faccao);
		$destino = $this->escolherDestino($faccao, $adjacentes, $motivo);

		if ($destino === null || $destino === $faccao->posicaoAtual) return null;

		$origem = $faccao->posicaoAtual;
		$faccao->posicaoAtual = $destino;
		$this->faccaoRepo->atualizar($faccao);
		$this->movimentoRepo->registrar($faccao->id, $origem, $destino, $motivo);

		return ['faccao' => $faccao->nome, 'origem' => $origem, 'destino' => $destino, 'motivo' => $motivo];
	}

	private function escolherMotivoMovimento(Faccao $faccao): string {
		if ($faccao->poderMilitar <= 30)                                return 'retirada';
		if ($faccao->fome >= 70)                                        return 'caca';
		if ($faccao->poderMilitar >= 70 && $faccao->suprimentos >= 60) return 'expansao';
		return 'patrulha';
	}

	private function escolherDestino(Faccao $faccao, array $adjacentes, string $motivo): ?int {
		$distritos = [];
		foreach ($adjacentes as $id) {
			$d = $this->distritoRepo->buscarPorId($id);
			if ($d) $distritos[$id] = $d;
		}
		if (empty($distritos)) return null;

		switch ($motivo) {
			case 'retirada':
				// Foge para onde há maior satisfação (menos hostil)
				return $this->maxPor($distritos, fn($d) => $d->satisfacaoGeral);
			case 'caca':
				// Vai onde há menor satisfação (presas fáceis, menos vigilância)
				return $this->minPor($distritos, fn($d) => $d->satisfacaoGeral);
			case 'expansao':
				return $this->minPor($distritos, fn($d) => ($d->faccaoDominanteId === 'ccg' ? 100 : 0) + $d->nivelAlerta * 5);
			case 'patrulha':
			default:
				$pesos = [];
				foreach ($distritos as $id => $d) {
					$pesos[$id] = ($d->faccaoDominanteId === $faccao->id) ? 1 : 3;
				}
				return $this->escolhaPonderada($pesos);
		}
	}

	// ─── Detecção de movimento (para investigação) ────────────────────────────

	/**
	 * Fórmula: d20 + floor(satisfacaoGeral / 10) vs sigilo da facção.
	 * >= sigilo       → total   (facção e motivo revelados)
	 * >= sigilo - 5   → parcial (atividade suspeita)
	 * < sigilo - 5    → nenhum
	 */
	public function verificarDeteccao(int $distritoId, Faccao $faccao): string {
		$distrito = $this->distritoRepo->buscarPorId($distritoId);
		if (!$distrito) return 'nenhum';

		$roll   = rand(1, 20) + (int) floor($distrito->satisfacaoGeral / 10);
		$sigilo = $faccao->sigilo;

		if ($roll >= $sigilo)     return 'total';
		if ($roll >= $sigilo - 5) return 'parcial';
		return 'nenhum';
	}

	public function formatarDeteccao(int $distritoId, string $nomeDistrito): string {
		$movimentos = $this->movimentoRepo->listarPorDistrito($distritoId, 168);
		if (empty($movimentos)) return "Sem rastros de atividade em {$nomeDistrito} nos ultimos 7 dias.";

		$faccoes = [];
		foreach ($this->faccaoRepo->listarTodas() as $f) {
			$faccoes[$f->id] = $f;
		}

		$linhas = [];
		foreach ($movimentos as $mov) {
			$faccao = $faccoes[$mov['faccao_id']] ?? null;
			if (!$faccao) continue;

			$nivel = $this->verificarDeteccao($distritoId, $faccao);
			if ($nivel === 'nenhum') continue;

			$data = date('d/m H:i', strtotime($mov['data_movimento']));
			if ($nivel === 'total') {
				$motivo   = $this->traduzirMotivo($mov['motivo']);
				$linhas[] = "[{$data}] {$faccao->nome} (#{$mov['distrito_origem']} -> #{$mov['distrito_destino']}) — {$motivo}";
			} else {
				$linhas[] = "[{$data}] Atividade suspeita detectada (identidade inconclusiva)";
			}
		}

		if (empty($linhas)) return "Operacoes em {$nomeDistrito} nao revelaram atividade identificavel.";
		return "Movimentos detectados em {$nomeDistrito}:\n" . implode("\n", $linhas);
	}

	// ─── Pressão de zona (facções predadoras) ────────────────────────────────

	/**
	 * Distritos adjacentes a territórios de facções predadoras perdem 2 de segurança por tick.
	 * @return array[]
	 */
	public function aplicarPressaoZona(): array {
		$afetados  = [];
		$distritos = $this->distritoRepo->listarTodos();
		$faccoes   = [];
		foreach ($this->faccaoRepo->listarTodas() as $f) {
			$faccoes[$f->id] = $f;
		}

		$predadorIds = [];
		foreach ($distritos as $d) {
			if (!$d->faccaoDominanteId || $d->faccaoDominanteId === 'ccg') continue;
			$fac = $faccoes[$d->faccaoDominanteId] ?? null;
			if ($fac && $fac->posturaCivis === 'predadora') {
				$predadorIds[] = $d->id;
			}
		}

		if (empty($predadorIds)) return [];

		$vizinhosAfetados = [];
		foreach ($predadorIds as $pid) {
			foreach ($this->adjacenciaRepo->getAdjacentes($pid) as $vid) {
				if (!in_array($vid, $predadorIds)) {
					$vizinhosAfetados[$vid] = true;
				}
			}
		}

		foreach (array_keys($vizinhosAfetados) as $vid) {
			$d = $this->distritoRepo->buscarPorId($vid);
			if (!$d) continue;

			$d->seguranca = max(0, $d->seguranca - 2);
			$this->distritoRepo->atualizar($d);

			$afetados[] = ['distrito_id' => $vid, 'nome' => $d->nome, 'reducao' => 2];
		}

		return $afetados;
	}

	// ─── Reset semanal ────────────────────────────────────────────────────────

	public function resetarPosicoes(): array {
		$resets  = [];
		foreach ($this->faccaoRepo->listarTodas() as $faccao) {
			if ($faccao->posicaoAtual !== $faccao->distritoBase) {
				$this->movimentoRepo->registrar($faccao->id, $faccao->posicaoAtual, $faccao->distritoBase, 'reset_semanal');
			}
			$faccao->posicaoAtual = $faccao->distritoBase;
			$this->faccaoRepo->atualizar($faccao);
			$resets[] = ['faccao' => $faccao->nome, 'base' => $faccao->distritoBase];
		}
		return $resets;
	}

	// ─── Utilitários privados ─────────────────────────────────────────────────

	private function maxPor(array $distritos, callable $fn): int {
		$melhorId = null; $melhorVal = PHP_INT_MIN;
		foreach ($distritos as $id => $d) {
			$val = $fn($d);
			if ($val > $melhorVal) { $melhorVal = $val; $melhorId = $id; }
		}
		return $melhorId;
	}

	private function minPor(array $distritos, callable $fn): int {
		$melhorId = null; $melhorVal = PHP_INT_MAX;
		foreach ($distritos as $id => $d) {
			$val = $fn($d);
			if ($val < $melhorVal) { $melhorVal = $val; $melhorId = $id; }
		}
		return $melhorId;
	}

	private function escolhaPonderada(array $pesos): int {
		$total = array_sum($pesos); $sorteio = rand(1, $total); $acum = 0;
		foreach ($pesos as $id => $peso) {
			$acum += $peso;
			if ($sorteio <= $acum) return $id;
		}
		return array_key_first($pesos);
	}

	private function traduzirMotivo(string $motivo): string {
		return match ($motivo) {
			'caca'          => 'Caca',
			'expansao'      => 'Expansao territorial',
			'retirada'      => 'Retirada tatica',
			'patrulha'      => 'Patrulha',
			'manual'        => 'Deslocamento CCG',
			'reset_semanal' => 'Reset de posicao',
			default         => ucfirst($motivo),
		};
	}

	private function nomDistrito(int $id): string {
		$d = $this->distritoRepo->buscarPorId($id);
		return $d ? $d->nome : "Desconhecido";
	}
}
