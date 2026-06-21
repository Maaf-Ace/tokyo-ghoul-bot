<?php

/**
 * O "Cérebro do Submundo". Resolve confrontos entre facções, aplica as regras
 * de negócio do GDD e persiste tudo via Repositories.
 *
 * A lógica de IA de escolha de tática (pesos por atributo + aprendizado por memória
 * de combates passados) é a mesma ideia original, só limpa e agora alimentada
 * pelo histórico real do banco.
 */
class MotorEventos {

	private FaccaoRepository $faccaoRepo;
	private DistritoRepository $distritoRepo;
	private DiplomaciaRepository $diplomaciaRepo;
	private HistoricoCombateRepository $historicoRepo;

	private const TATICAS = ['emboscada', 'rush', 'defesa'];
	private const LIMIAR_ALIANCA = 50; // afinidade >= isso = não se atacam

	public function __construct(
		?FaccaoRepository $faccaoRepo = null,
		?DistritoRepository $distritoRepo = null,
		?DiplomaciaRepository $diplomaciaRepo = null,
		?HistoricoCombateRepository $historicoRepo = null
	) {
		$this->faccaoRepo = $faccaoRepo ?? new FaccaoRepository();
		$this->distritoRepo = $distritoRepo ?? new DistritoRepository();
		$this->diplomaciaRepo = $diplomaciaRepo ?? new DiplomaciaRepository();
		$this->historicoRepo = $historicoRepo ?? new HistoricoCombateRepository();
	}

	/**
	 * Escolhe a tática de uma facção com base nos seus atributos e na memória
	 * de como o inimigo costuma agir (tenta "counterar").
	 */
	public function escolherTatica(Faccao $faccao, array $memoriaInimigo = []): string {
		$pesos = [
			'emboscada' => $faccao->sigilo,
			'rush' => $faccao->agressividade,
			'defesa' => 100 - $faccao->agressividade,
		];

		// Piso mínimo pra nenhuma tática zerar e travar o sorteio
		foreach ($pesos as $tatica => $valor) {
			$pesos[$tatica] = max(1, $valor);
		}

		if (isset($pesos[$faccao->taticaFavorita])) {
			$pesos[$faccao->taticaFavorita] += 40;
		}

		if (!empty($memoriaInimigo)) {
			if (($memoriaInimigo['defesa'] ?? 0) > 2) $pesos['rush'] += 50;       // defende muito -> toma rush
			if (($memoriaInimigo['emboscada'] ?? 0) > 2) $pesos['defesa'] += 50;  // embosca muito -> toma defesa
			if (($memoriaInimigo['rush'] ?? 0) > 2) $pesos['emboscada'] += 50;    // rusha muito -> toma emboscada
		}

		$sorteio = rand(1, array_sum($pesos));
		$acumulado = 0;

		foreach ($pesos as $tatica => $peso) {
			$acumulado += $peso;
			if ($sorteio <= $acumulado) return $tatica;
		}

		return 'rush'; // fallback de segurança, não deveria ser alcançado
	}

	/** Vantagem circular: emboscada > rush > defesa > emboscada */
	public function calcularVantagem(string $taticaAtacante, string $taticaDefensor): int {
		if ($taticaAtacante === $taticaDefensor) return 0;

		$vantagens = [
			'emboscada' => 'rush',
			'rush' => 'defesa',
			'defesa' => 'emboscada',
		];

		return ($vantagens[$taticaAtacante] ?? null) === $taticaDefensor ? 15 : -15;
	}

	/**
	 * Resolve um confronto entre duas facções, aplica todas as regras do GDD,
	 * persiste o resultado em `faccoes` e registra em `historico_combates`.
	 *
	 * Regras aplicadas:
	 * - Atacante sempre perde sigilo, independente do resultado.
	 * - Vencedor sofre desgaste de Poder Militar e ganha Suprimentos (regra original).
	 * - Perdedor com Poder Militar baixo ganha sigilo (Instinto de Sobrevivência).
	 */
	public function resolverConfronto(Faccao $atacante, Faccao $defensor): array {
		$memoriaAtacante = $this->historicoRepo->getMemoriaTaticaDaFaccao($atacante->id);
		$memoriaDefensor = $this->historicoRepo->getMemoriaTaticaDaFaccao($defensor->id);

		$taticaAtacante = $this->escolherTatica($atacante, $memoriaDefensor);
		$taticaDefensor = $this->escolherTatica($defensor, $memoriaAtacante);

		$dadoAtacante = rand(1, 20);
		$dadoDefensor = rand(1, 20);

		$modificadorTatica = $this->calcularVantagem($taticaAtacante, $taticaDefensor);

		$maestriaAtacante = ($taticaAtacante === $atacante->taticaFavorita) ? 5 : 0;
		$maestriaDefensor = ($taticaDefensor === $defensor->taticaFavorita) ? 5 : 0;

		$forcaTotalAtacante = $atacante->poderMilitar + $dadoAtacante + $modificadorTatica + $maestriaAtacante;
		$forcaTotalDefensor = $defensor->poderMilitar + $dadoDefensor + $maestriaDefensor;

		$atacanteVenceu = $forcaTotalAtacante > $forcaTotalDefensor;

		// --- Regra: atacante sempre perde sigilo, ganhe ou perca ---
		$atacante->sigilo = max(0, $atacante->sigilo - rand(5, 15));

		if ($atacanteVenceu) {
			$resultado = 'Vitória do Atacante';
			$defensor->poderMilitar = max(0, $defensor->poderMilitar - 15);
			$atacante->poderMilitar = max(0, $atacante->poderMilitar - 5); // desgaste mesmo vencendo
			$atacante->suprimentos = min(100, $atacante->suprimentos + 10);
			$vencedor = $atacante;
			$perdedor = $defensor;
		} else {
			$resultado = 'Vitória do Defensor';
			$atacante->poderMilitar = max(0, $atacante->poderMilitar - 15);
			$vencedor = $defensor;
			$perdedor = $atacante;
		}

		// --- Regra: Instinto de Sobrevivência ---
		$perdedor->aplicarInstintoSobrevivencia();

		// --- Persistência ---
		$this->faccaoRepo->atualizar($atacante);
		$this->faccaoRepo->atualizar($defensor);

		$this->historicoRepo->registrar(
			$atacante->id,
			$defensor->id,
			$taticaAtacante,
			$taticaDefensor,
			$vencedor->id
		);

		return [
			'vencedor' => $vencedor->nome,
			'resultado_texto' => $resultado,
			'tatica_atacante' => $taticaAtacante,
			'tatica_defensor' => $taticaDefensor,
			'forca_final_atacante' => $forcaTotalAtacante,
			'forca_final_defensor' => $forcaTotalDefensor,
			'modificador_tatica' => $modificadorTatica,
			'maestria_atacante' => $maestriaAtacante,
			'maestria_defensor' => $maestriaDefensor,
			'dado_atacante' => $dadoAtacante,
			'dado_defensor' => $dadoDefensor,
		];
	}

	/**
	 * Decide aleatoriamente se uma facção vai atacar alguém neste tick,
	 * respeitando diplomacia (não ataca aliados) e checando se há alvo viável.
	 * Retorna o resultado do confronto, ou null se nada aconteceu.
	 */
	public function tentarIniciarConflito(Faccao $faccao, array $todasFaccoes, int $chancePercentual = 30): ?array {
		// CCG não inicia conflitos por conta própria nesta versão (jogadores controlam ela)
		if ($faccao->id === 'ccg') return null;

		if (rand(1, 100) > $chancePercentual) return null;

		$alvosViaveis = array_filter(
			$todasFaccoes,
			fn(Faccao $alvo) => $alvo->id !== $faccao->id
				&& !$this->diplomaciaRepo->saoAliadas($faccao->id, $alvo->id, self::LIMIAR_ALIANCA)
		);

		if (empty($alvosViaveis)) return null;

		$alvo = $alvosViaveis[array_rand($alvosViaveis)];

		$resultado = $this->resolverConfronto($faccao, $alvo);
		$resultado['atacante'] = $faccao->nome;
		$resultado['defensor'] = $alvo->nome;

		return $resultado;
	}

	/**
	 * Roda um "tick" completo do mundo: passa o tempo em todas as facções Ghoul
	 * e dá a chance de cada uma iniciar um conflito. Pensado para ser chamado
	 * pelo cronjob (scripts/tick.php).
	 *
	 * @return array Lista de eventos que aconteceram neste tick (para gerar relatório/post no Discord)
	 */
	public function rodarTick(): array {
		$eventos = [];
		$todasFaccoes = $this->faccaoRepo->listarTodas();
		$faccoesGhoul = array_filter($todasFaccoes, fn(Faccao $f) => $f->id !== 'ccg');

		foreach ($faccoesGhoul as $faccao) {
			$faccao->passarOTempo();
			$this->faccaoRepo->atualizar($faccao);

			$eventos[] = [
				'tipo' => 'passagem_tempo',
				'faccao' => $faccao->nome,
				'fome' => $faccao->fome,
				'agressividade' => $faccao->agressividade,
				'sigilo' => $faccao->sigilo,
			];
		}

		// Re-busca para pegar os valores já atualizados antes de rodar conflitos
		$todasFaccoesAtualizadas = $this->faccaoRepo->listarTodas();

		foreach ($faccoesGhoul as $faccao) {
			$faccaoAtual = $this->faccaoRepo->buscarPorId($faccao->id);
			if ($faccaoAtual === null) continue;

			$resultadoConflito = $this->tentarIniciarConflito($faccaoAtual, $todasFaccoesAtualizadas);

			if ($resultadoConflito !== null) {
				$eventos[] = array_merge(['tipo' => 'combate'], $resultadoConflito);
			}
		}

		return $eventos;
	}
}
