<?php

/**
 * SistemaMovimento
 *
 * Responsável por:
 *  - Mover o boneco CCG manualmente (!mover)
 *  - Executar IA de movimento das facções ghoul a cada tick
 *  - Calcular detecção de movimento (investigação CCG)
 *  - Aplicar pressão de zona Aogiri
 *  - Resetar posições semanalmente
 */
class SistemaMovimento {

	private FaccaoRepository    $faccaoRepo;
	private DistritoRepository  $distritoRepo;
	private MovimentoRepository $movimentoRepo;
	private AdjacenciaRepository $adjacenciaRepo;

	public function __construct(
		?FaccaoRepository    $faccaoRepo     = null,
		?DistritoRepository  $distritoRepo   = null,
		?MovimentoRepository $movimentoRepo  = null,
		?AdjacenciaRepository $adjacenciaRepo = null
	) {
		$this->faccaoRepo    = $faccaoRepo    ?? new FaccaoRepository();
		$this->distritoRepo  = $distritoRepo  ?? new DistritoRepository();
		$this->movimentoRepo = $movimentoRepo ?? new MovimentoRepository();
		$this->adjacenciaRepo = $adjacenciaRepo ?? new AdjacenciaRepository();
	}

	// =========================================================
	// MOVIMENTO MANUAL DA CCG
	// =========================================================

	/**
	 * Tenta mover a CCG para o distrito destino.
	 *
	 * @return array{ok:bool, mensagem:string}
	 */
	public function moverCCG(int $distritoDestino): array {
		$ccg = $this->faccaoRepo->buscarPorId('ccg');
		if (!$ccg) {
			return ['ok' => false, 'mensagem' => 'Faccao CCG nao encontrada.'];
		}

		$distrito = $this->distritoRepo->buscarPorId($distritoDestino);
		if (!$distrito) {
			return ['ok' => false, 'mensagem' => "Distrito #$distritoDestino nao existe."];
		}

		if (!$this->adjacenciaRepo->saoAdjacentes($ccg->posicaoAtual, $distritoDestino)) {
			$adjacentes = implode(', ', $this->adjacenciaRepo->getAdjacentes($ccg->posicaoAtual));
			return [
				'ok'       => false,
				'mensagem' => "Movimento invalido. Do distrito #{$ccg->posicaoAtual} voce so pode ir para: $adjacentes.",
			];
		}

		$origem = $ccg->posicaoAtual;
		$ccg->posicaoAtual = $distritoDestino;
		$this->faccaoRepo->atualizar($ccg);
		$this->movimentoRepo->registrar('ccg', $origem, $distritoDestino, 'manual');

		return [
			'ok'       => true,
			'mensagem' => "CCG deslocou-se do distrito #$origem ({$this->nomDistrito($origem)}) para #$distritoDestino ({$distrito->nome}).",
		];
	}

	// =========================================================
	// IA DE MOVIMENTO DAS FACÇÕES GHOUL
	// =========================================================

	/**
	 * Executa a IA de movimento para todas as facções não-CCG.
	 * Chamado a cada tick (tick.php).
	 *
	 * @return array[] Lista de eventos de movimento [{faccao, origem, destino, motivo}]
	 */
	public function executarMovimentoIA(): array {
		$eventos  = [];
		$faccoes  = $this->faccaoRepo->listarFaccoesGhoul();

		foreach ($faccoes as $faccao) {
			$resultado = $this->decidirMovimento($faccao);
			if ($resultado) {
				$eventos[] = $resultado;
			}
		}

		return $eventos;
	}

	/**
	 * IA de decisão de movimento de uma única facção.
	 * Só se move com ~40% de chance por tick, para não ficar telegrafando demais.
	 *
	 * @return array|null null se decidiu não se mover
	 */
	private function decidirMovimento(Faccao $faccao): ?array {
		// 60% de chance de ficar parado (preserva sigilo)
		if (rand(1, 100) <= 60) {
			return null;
		}

		$adjacentes = $this->adjacenciaRepo->getAdjacentes($faccao->posicaoAtual);
		if (empty($adjacentes)) {
			return null;
		}

		$motivo  = $this->escolherMotivoMovimento($faccao);
		$destino = $this->escolherDestino($faccao, $adjacentes, $motivo);

		if ($destino === null || $destino === $faccao->posicaoAtual) {
			return null;
		}

		$origem = $faccao->posicaoAtual;
		$faccao->posicaoAtual = $destino;
		$this->faccaoRepo->atualizar($faccao);
		$this->movimentoRepo->registrar($faccao->id, $origem, $destino, $motivo);

		return [
			'faccao'  => $faccao->nome,
			'origem'  => $origem,
			'destino' => $destino,
			'motivo'  => $motivo,
		];
	}

	/** Define o motivo estratégico do movimento baseado nos atributos. */
	private function escolherMotivoMovimento(Faccao $faccao): string {
		// Retirada: poder baixo
		if ($faccao->poderMilitar <= 30) {
			return 'retirada';
		}

		// Caça por comida: fome crítica
		if ($faccao->fome >= 70) {
			return 'caca';
		}

		// Expansão: forte e abastecido
		if ($faccao->poderMilitar >= 70 && $faccao->suprimentos >= 60) {
			return 'expansao';
		}

		return 'patrulha';
	}

	/**
	 * Escolhe o distrito destino com base no motivo.
	 * Prioriza distritos que maximizam o objetivo da IA.
	 */
	private function escolherDestino(Faccao $faccao, array $adjacentes, string $motivo): ?int {
		$distritos = [];
		foreach ($adjacentes as $id) {
			$d = $this->distritoRepo->buscarPorId($id);
			if ($d) {
				$distritos[$id] = $d;
			}
		}

		if (empty($distritos)) {
			return null;
		}

		switch ($motivo) {
			case 'retirada':
				// Foge para o distrito com maior apoio civil (menos hostil)
				return $this->maxPor($distritos, fn($d) => $d->apoioCivil);

			case 'caca':
				// Vai para onde há menos apoio civil (presas fáceis, menos vigilância)
				return $this->minPor($distritos, fn($d) => $d->apoioCivil);

			case 'expansao':
				// Prefere distritos com baixo nível de dominação da CCG
				return $this->minPor($distritos, fn($d) => ($d->faccaoDominanteId === 'ccg' ? 100 : 0) + $d->nivelAlerta * 5);

			case 'patrulha':
			default:
				// Movimento semi-aleatório com leve preferência por territórios não controlados
				$pesos = [];
				foreach ($distritos as $id => $d) {
					$pesos[$id] = ($d->faccaoDominanteId === $faccao->id) ? 1 : 3;
				}
				return $this->escolhaPonderada($pesos);
		}
	}

	// =========================================================
	// DETECÇÃO DE MOVIMENTO (para investigação CCG)
	// =========================================================

	/**
	 * Verifica se a CCG detecta rastros de uma facção num distrito.
	 * Retorna o nível de detecção: 'total', 'parcial', ou 'nenhum'.
	 *
	 * Formula: d20 + floor(apoioCivil / 10) vs sigilo da facção
	 *
	 * >= sigilo       → total (facção e motivo revelados)
	 * >= sigilo - 5   → parcial (atividade suspeita sem identidade)
	 * < sigilo - 5    → nenhum
	 */
	public function verificarDeteccao(int $distritoId, Faccao $faccao): string {
		$distrito = $this->distritoRepo->buscarPorId($distritoId);
		if (!$distrito) {
			return 'nenhum';
		}

		$roll  = rand(1, 20) + (int) floor($distrito->apoioCivil / 10);
		$sigilo = $faccao->sigilo;

		if ($roll >= $sigilo) {
			return 'total';
		}

		if ($roll >= $sigilo - 5) {
			return 'parcial';
		}

		return 'nenhum';
	}

	/**
	 * Formata o resultado de detecção para uma facção dado um log de movimentos.
	 * Retorna texto legível para a investigação.
	 */
	public function formatarDeteccao(int $distritoId, string $nomDistrito): string {
		$movimentos = $this->movimentoRepo->listarPorDistrito($distritoId, 168); // semana
		if (empty($movimentos)) {
			return "Sem rastros de atividade em $nomDistrito nos ultimos 7 dias.";
		}

		$faccoes = $this->faccaoRepo->listarTodas();
		$mapa    = [];
		foreach ($faccoes as $f) {
			$mapa[$f->id] = $f;
		}

		$linhas = [];
		foreach ($movimentos as $mov) {
			$faccao = $mapa[$mov['faccao_id']] ?? null;
			if (!$faccao) continue;

			$nivel = $this->verificarDeteccao($distritoId, $faccao);

			if ($nivel === 'nenhum') {
				continue;
			}

			$data = date('d/m H:i', strtotime($mov['data_movimento']));

			if ($nivel === 'total') {
				$motivo  = $this->traduzirMotivo($mov['motivo']);
				$linhas[] = "[$data] {$faccao->nome} (Distrito #{$mov['distrito_origem']} -> #{$mov['distrito_destino']}) - Motivo: $motivo";
			} else {
				$linhas[] = "[$data] Atividade suspeita detectada (identidade inconclusiva)";
			}
		}

		if (empty($linhas)) {
			return "Operacoes de inteligencia em $nomDistrito nao revelaram atividade identificavel.";
		}

		return "Movimentos detectados em $nomDistrito:\n" . implode("\n", $linhas);
	}

	// =========================================================
	// PRESSÃO DE ZONA AOGIRI
	// =========================================================

	/**
	 * Aplica pressão passiva de zona: distritos adjacentes a territórios Aogiri
	 * perdem 2 pontos de apoio civil por tick.
	 *
	 * @return array[] [{distrito_id, nome, reducao}]
	 */
	public function aplicarPressaoZona(): array {
		$afetados  = [];
		$distritos = $this->distritoRepo->listarTodos();

		// Distritos controlados pela Aogiri
		$aogiriIds = [];
		foreach ($distritos as $d) {
			if ($d->faccaoDominanteId === 'aogiri') {
				$aogiriIds[] = $d->id;
			}
		}

		if (empty($aogiriIds)) {
			return [];
		}

		// Vizinhos dos territórios Aogiri
		$vizinhosAfetados = [];
		foreach ($aogiriIds as $aid) {
			$adj = $this->adjacenciaRepo->getAdjacentes($aid);
			foreach ($adj as $vid) {
				if (!in_array($vid, $aogiriIds)) {
					$vizinhosAfetados[$vid] = true;
				}
			}
		}

		foreach (array_keys($vizinhosAfetados) as $vid) {
			$d = $this->distritoRepo->buscarPorId($vid);
			if (!$d) continue;

			$novoApoio = max(0, $d->apoioCivil - 2);
			$d->apoioCivil = $novoApoio;
			$this->distritoRepo->atualizar($d);

			$afetados[] = [
				'distrito_id' => $vid,
				'nome'        => $d->nome,
				'reducao'     => 2,
			];
		}

		return $afetados;
	}

	// =========================================================
	// RESET SEMANAL
	// =========================================================

	/**
	 * Reseta a posição de todas as facções para suas bases.
	 * Chamado no tick_semanal (segunda-feira).
	 *
	 * @return array[] [{faccao, base}]
	 */
	public function resetarPosicoes(): array {
		$resets  = [];
		$faccoes = $this->faccaoRepo->listarTodas();

		foreach ($faccoes as $faccao) {
			if ($faccao->posicaoAtual !== $faccao->distritoBase) {
				$this->movimentoRepo->registrar(
					$faccao->id,
					$faccao->posicaoAtual,
					$faccao->distritoBase,
					'reset_semanal'
				);
			}
			$faccao->posicaoAtual = $faccao->distritoBase;
			$this->faccaoRepo->atualizar($faccao);

			$resets[] = [
				'faccao' => $faccao->nome,
				'base'   => $faccao->distritoBase,
			];
		}

		return $resets;
	}

	// =========================================================
	// UTILITÁRIOS PRIVADOS
	// =========================================================

	private function maxPor(array $distritos, callable $fn): int {
		$melhorId  = null;
		$melhorVal = PHP_INT_MIN;
		foreach ($distritos as $id => $d) {
			$val = $fn($d);
			if ($val > $melhorVal) {
				$melhorVal = $val;
				$melhorId  = $id;
			}
		}
		return $melhorId;
	}

	private function minPor(array $distritos, callable $fn): int {
		$melhorId  = null;
		$melhorVal = PHP_INT_MAX;
		foreach ($distritos as $id => $d) {
			$val = $fn($d);
			if ($val < $melhorVal) {
				$melhorVal = $val;
				$melhorId  = $id;
			}
		}
		return $melhorId;
	}

	private function escolhaPonderada(array $pesos): int {
		$total = array_sum($pesos);
		$sorteio = rand(1, $total);
		$acum    = 0;
		foreach ($pesos as $id => $peso) {
			$acum += $peso;
			if ($sorteio <= $acum) {
				return $id;
			}
		}
		return array_key_first($pesos);
	}

	private function traduzirMotivo(string $motivo): string {
		return match ($motivo) {
			'caca'         => 'Caca',
			'expansao'     => 'Expansao territorial',
			'retirada'     => 'Retirada tattica',
			'patrulha'     => 'Patrulha',
			'manual'       => 'Deslocamento CCG',
			'reset_semanal'=> 'Reset de posicao',
			default        => ucfirst($motivo),
		};
	}

	private function nomDistrito(int $id): string {
		$d = $this->distritoRepo->buscarPorId($id);
		return $d ? $d->nome : "Desconhecido";
	}
}
