<?php

/**
 * O "Cérebro do Submundo". Resolve confrontos entre facções, aplica as regras
 * de negócio do GDD e persiste tudo via Repositories.
 */
class MotorEventos {

	private FaccaoRepository            $faccaoRepo;
	private DistritoRepository          $distritoRepo;
	private DiplomaciaRepository        $diplomaciaRepo;
	private HistoricoCombateRepository  $historicoRepo;
	private ?ClarimToquio               $clarim;
	private ?ConfruntoPendenteRepository $cpRepo;

	private const TATICAS        = ['emboscada', 'rush', 'defesa'];
	private const LIMIAR_ALIANCA = 50;

	public function __construct(
		?FaccaoRepository             $faccaoRepo    = null,
		?DistritoRepository           $distritoRepo  = null,
		?DiplomaciaRepository         $diplomaciaRepo = null,
		?HistoricoCombateRepository   $historicoRepo  = null,
		?ClarimToquio                 $clarim         = null,
		?ConfruntoPendenteRepository  $cpRepo         = null
	) {
		$this->faccaoRepo    = $faccaoRepo    ?? new FaccaoRepository();
		$this->distritoRepo  = $distritoRepo  ?? new DistritoRepository();
		$this->diplomaciaRepo = $diplomaciaRepo ?? new DiplomaciaRepository();
		$this->historicoRepo  = $historicoRepo  ?? new HistoricoCombateRepository();
		$this->clarim         = $clarim;
		$this->cpRepo         = $cpRepo;
	}

	/**
	 * Escolhe a tática de uma facção com base nos seus atributos e na memória
	 * de como o inimigo costuma agir (tenta "counterar").
	 */
	public function escolherTatica(Faccao $faccao, array $memoriaInimigo = []): string {
		$pesos = [
			'emboscada' => $faccao->sigilo,
			'rush'      => $faccao->agressividade,
			'defesa'    => 100 - $faccao->agressividade,
		];

		foreach ($pesos as $tatica => $valor) {
			$pesos[$tatica] = max(1, $valor);
		}

		if (isset($pesos[$faccao->taticaFavorita])) {
			$pesos[$faccao->taticaFavorita] += 40;
		}

		if (!empty($memoriaInimigo)) {
			if (($memoriaInimigo['defesa']    ?? 0) > 2) $pesos['rush']      += 50;
			if (($memoriaInimigo['emboscada'] ?? 0) > 2) $pesos['defesa']    += 50;
			if (($memoriaInimigo['rush']      ?? 0) > 2) $pesos['emboscada'] += 50;
		}

		$sorteio   = rand(1, array_sum($pesos));
		$acumulado = 0;
		foreach ($pesos as $tatica => $peso) {
			$acumulado += $peso;
			if ($sorteio <= $acumulado) return $tatica;
		}
		return 'rush';
	}

	/** Vantagem circular: emboscada > rush > defesa > emboscada */
	public function calcularVantagem(string $taticaAtacante, string $taticaDefensor): int {
		if ($taticaAtacante === $taticaDefensor) return 0;
		$vantagens = ['emboscada' => 'rush', 'rush' => 'defesa', 'defesa' => 'emboscada'];
		return ($vantagens[$taticaAtacante] ?? null) === $taticaDefensor ? 15 : -15;
	}

	/**
	 * Resolve um confronto. Aceita overrides de tática para confrontos pendentes
	 * onde a tática do atacante é recuperada do banco e/ou a do defensor é escolhida pelo jogador.
	 *
	 * @param string|null $taticaAtacanteOverride  Tática já definida (de confronto pendente)
	 * @param string|null $taticaDefensorOverride  Tática escolhida manualmente pelo jogador
	 */
	public function resolverConfronto(
		Faccao  $atacante,
		Faccao  $defensor,
		?string $taticaAtacanteOverride = null,
		?string $taticaDefensorOverride = null
	): array {
		$memoriaAtacante = $this->historicoRepo->getMemoriaTaticaDaFaccao($atacante->id);
		$memoriaDefensor = $this->historicoRepo->getMemoriaTaticaDaFaccao($defensor->id);

		$escolhaManual  = $taticaDefensorOverride !== null;
		$taticaAtacante = $taticaAtacanteOverride ?? $this->escolherTatica($atacante, $memoriaDefensor);
		$taticaDefensor = $taticaDefensorOverride ?? $this->escolherTatica($defensor, $memoriaAtacante);

		$dadoAtacante    = rand(1, 20);
		$dadoDefensor    = rand(1, 20);
		$modTatica       = $this->calcularVantagem($taticaAtacante, $taticaDefensor);
		$maestriaAtacante = ($taticaAtacante === $atacante->taticaFavorita) ? 5 : 0;
		$maestriaDefensor = ($taticaDefensor === $defensor->taticaFavorita) ? 5 : 0;

		$forcaAtacante = $atacante->poderMilitar + $dadoAtacante + $modTatica + $maestriaAtacante;
		$forcaDefensor = $defensor->poderMilitar + $dadoDefensor + $maestriaDefensor;

		$atacanteVenceu = $forcaAtacante > $forcaDefensor;
		$diff           = abs($forcaAtacante - $forcaDefensor);
		$derrotaSinistra = $atacanteVenceu && $diff > 30;

		// Atacante sempre perde sigilo
		$atacante->sigilo = max(0, $atacante->sigilo - rand(5, 15));

		if ($atacanteVenceu) {
			$resultado = 'Vitória do Atacante';
			$defensor->poderMilitar = max(0, $defensor->poderMilitar - 15);
			$atacante->poderMilitar = max(0, $atacante->poderMilitar - 5);
			$atacante->suprimentos  = min(100, $atacante->suprimentos + 10);
			if ($derrotaSinistra) {
				// Perda extra de suprimentos do perdedor
				$defensor->suprimentos = max(0, $defensor->suprimentos - rand(10, 20));
			}
			$vencedor = $atacante;
			$perdedor = $defensor;
		} else {
			$resultado = 'Vitória do Defensor';
			$atacante->poderMilitar = max(0, $atacante->poderMilitar - 15);
			$vencedor = $defensor;
			$perdedor = $atacante;
		}

		$perdedor->aplicarInstintoSobrevivencia();

		$this->faccaoRepo->atualizar($atacante);
		$this->faccaoRepo->atualizar($defensor);

		$this->historicoRepo->registrar(
			$atacante->id,
			$defensor->id,
			$taticaAtacante,
			$taticaDefensor,
			$vencedor->id,
			$derrotaSinistra,
			$escolhaManual
		);

		return [
			'vencedor'             => $vencedor->nome,
			'vencedor_id'          => $vencedor->id,
			'resultado_texto'      => $resultado,
			'tatica_atacante'      => $taticaAtacante,
			'tatica_defensor'      => $taticaDefensor,
			'forca_final_atacante' => $forcaAtacante,
			'forca_final_defensor' => $forcaDefensor,
			'modificador_tatica'   => $modTatica,
			'maestria_atacante'    => $maestriaAtacante,
			'maestria_defensor'    => $maestriaDefensor,
			'dado_atacante'        => $dadoAtacante,
			'dado_defensor'        => $dadoDefensor,
			'derrota_sinistra'     => $derrotaSinistra,
			'escolha_manual'       => $escolhaManual,
		];
	}

	/**
	 * Decide se uma facção vai atacar alguém neste tick.
	 * Se o alvo for a CCG e houver ClarimToquio + cpRepo injetados,
	 * cria um confronto_pendente em vez de resolver imediatamente.
	 */
	public function tentarIniciarConflito(Faccao $faccao, array $todasFaccoes, int $chancePercentual = 30): ?array {
		if ($faccao->id === 'ccg')             return null;
		if (rand(1, 100) > $chancePercentual)  return null;

		$alvosViaveis = array_filter(
			$todasFaccoes,
			fn(Faccao $alvo) => $alvo->id !== $faccao->id
				&& !$this->diplomaciaRepo->saoAliadas($faccao->id, $alvo->id, self::LIMIAR_ALIANCA)
		);

		if (empty($alvosViaveis)) return null;

		$alvo = $alvosViaveis[array_rand($alvosViaveis)];

		// Ataque à CCG: gera confronto pendente com botões em vez de resolver agora
		if ($alvo->id === 'ccg' && $this->clarim !== null && $this->cpRepo !== null) {
			return $this->criarConfruntoPendente($faccao, $alvo);
		}

		$resultado             = $this->resolverConfronto($faccao, $alvo);
		$resultado['tipo']     = 'combate';
		$resultado['atacante'] = $faccao->nome;
		$resultado['defensor'] = $alvo->nome;
		return $resultado;
	}

	/** Cria um confronto_pendente e posta alerta com botões via ClarimToquio. */
	private function criarConfruntoPendente(Faccao $atacante, Faccao $ccg): array {
		if ($this->cpRepo->existePendente($atacante->id)) {
			return [];
		}

		$taticaAtacante = $this->escolherTatica($atacante);
		$confrontoId    = uniqid('cnf_');
		$expiraEm       = date('Y-m-d H:i:s', strtotime('+10 minutes'));
		$distritoId     = $ccg->posicaoAtual;

		$messageId = $this->clarim->publicarAlertaConfronto($confrontoId, $atacante, $distritoId, $taticaAtacante);
		$this->cpRepo->criar($confrontoId, $atacante->id, $distritoId, $taticaAtacante, $messageId ?? '', $expiraEm);

		return [
			'tipo'         => 'confronto_pendente',
			'atacante'     => $atacante->nome,
			'defensor'     => 'CCG',
			'confronto_id' => $confrontoId,
		];
	}

	/**
	 * Resolve todos os confrontos_pendentes expirados usando IA para a CCG.
	 * Chamado pelo tick.php a cada ciclo.
	 */
	public function resolverConfrontosExpirados(): array {
		if ($this->cpRepo === null) return [];

		$expirados  = $this->cpRepo->buscarExpirados();
		$resultados = [];

		foreach ($expirados as $confronto) {
			$atacante = $this->faccaoRepo->buscarPorId($confronto['atacante_id']);
			$ccg      = $this->faccaoRepo->buscarPorId('ccg');

			if (!$atacante || !$ccg) {
				$this->cpRepo->marcarResolvido($confronto['id']);
				continue;
			}

			// Usa a tática do atacante já definida; CCG escolhe por IA
			$resultado = $this->resolverConfronto($atacante, $ccg, $confronto['tatica_atacante'], null);
			$this->cpRepo->marcarResolvido($confronto['id']);

			$resultado['tipo']         = 'combate';
			$resultado['atacante']     = $atacante->nome;
			$resultado['defensor']     = $ccg->nome;
			$resultado['confronto_id'] = $confronto['id'];
			$resultado['expirado']     = true;
			$resultados[]              = $resultado;
		}

		return $resultados;
	}

	/**
	 * Roda um "tick" completo: passa o tempo em todas as facções Ghoul
	 * e dá a chance de cada uma iniciar um conflito.
	 */
	public function rodarTick(): array {
		$eventos      = [];
		$todasFaccoes = $this->faccaoRepo->listarTodas();
		$faccoesGhoul = array_filter($todasFaccoes, fn(Faccao $f) => $f->id !== 'ccg');

		foreach ($faccoesGhoul as $faccao) {
			$faccao->passarOTempo();
			$this->faccaoRepo->atualizar($faccao);
			$eventos[] = [
				'tipo'          => 'passagem_tempo',
				'faccao'        => $faccao->nome,
				'fome'          => $faccao->fome,
				'agressividade' => $faccao->agressividade,
				'sigilo'        => $faccao->sigilo,
			];
		}

		$todasFaccoesAtualizadas = $this->faccaoRepo->listarTodas();

		foreach ($faccoesGhoul as $faccao) {
			$faccaoAtual = $this->faccaoRepo->buscarPorId($faccao->id);
			if ($faccaoAtual === null) continue;

			$resultadoConflito = $this->tentarIniciarConflito($faccaoAtual, $todasFaccoesAtualizadas);
			if (!empty($resultadoConflito)) {
				$eventos[] = $resultadoConflito;
			}
		}

		return $eventos;
	}
}
