<?php

/**
 * Sorteia e gerencia Eventos de Mapa semanais: Blecautes, Protestos,
 * Surtos de Ghoul e outros imprevistos que alteram o cenário temporariamente.
 */
class GerenciadorEventosMapa {

	private EventoMapaRepository $eventoRepo;
	private DistritoRepository   $distritoRepo;

	private const CATALOGO = [
		[
			'tipo'           => 'blecaute',
			'titulo'         => 'Blecaute Total',
			'descricao'      => 'O fornecimento de energia foi cortado. Operações noturnas ficam dificultadas — ghouls ganham vantagem nas sombras.',
			'efeito_tipo'    => 'nivel_alerta_bonus',
			'efeito_valor'   => 2,
			'duracao_horas'  => 48,
		],
		[
			'tipo'           => 'protestos',
			'titulo'         => 'Protestos Civis',
			'descricao'      => 'A população tomou as ruas. Operações da CCG neste distrito custam o dobro de tempo.',
			'efeito_tipo'    => 'custo_operacao_multiplicador',
			'efeito_valor'   => 2,
			'duracao_horas'  => 72,
		],
		[
			'tipo'           => 'surto_ghoul',
			'titulo'         => 'Surto de Atividade Ghoul',
			'descricao'      => 'Ghouls famintos ficaram mais ousados. O nível de alerta do distrito subiu drasticamente.',
			'efeito_tipo'    => 'nivel_alerta_bonus',
			'efeito_valor'   => 3,
			'duracao_horas'  => 36,
		],
		[
			'tipo'           => 'reforcopolicial',
			'titulo'         => 'Reforço Policial',
			'descricao'      => 'Forças policiais reforçaram a presença. Facções ocultas ficam sob pressão extra.',
			'efeito_tipo'    => 'sigilo_penalidade_faccao',
			'efeito_valor'   => 10,
			'duracao_horas'  => 24,
		],
		[
			'tipo'           => 'mercado_negro',
			'titulo'         => 'Mercado Negro Ativo',
			'descricao'      => 'Contatos do submundo estão ativos. Operações de contrabando têm rendimento maior esta semana.',
			'efeito_tipo'    => 'bonus_contrabando',
			'efeito_valor'   => 50,
			'duracao_horas'  => 48,
		],
	];

	public function __construct(
		?EventoMapaRepository $eventoRepo   = null,
		?DistritoRepository   $distritoRepo = null
	) {
		$this->eventoRepo   = $eventoRepo   ?? new EventoMapaRepository();
		$this->distritoRepo = $distritoRepo ?? new DistritoRepository();
	}

	/**
	 * Sorteia entre 1 e 3 eventos aleatórios e os registra no banco.
	 * @return array[] Dados resumidos dos eventos criados (para log e Clarim)
	 */
	public function sortearEventosSemana(): array {
		$distritos = $this->distritoRepo->listarTodos();
		if (empty($distritos)) return [];

		$criados = [];
		$qtd     = rand(1, 3);

		for ($i = 0; $i < $qtd; $i++) {
			$modelo   = self::CATALOGO[array_rand(self::CATALOGO)];
			$distrito = $distritos[array_rand($distritos)];
			$dataFim  = date('Y-m-d H:i:s', strtotime("+{$modelo['duracao_horas']} hours"));

			$this->eventoRepo->criar(
				$modelo['tipo'],
				$modelo['titulo'],
				$modelo['descricao'],
				$distrito->id,
				$modelo['efeito_tipo'],
				$modelo['efeito_valor'],
				$dataFim
			);

			// Efeito imediato: blecaute reduz economia do distrito afetado
			if ($modelo['tipo'] === 'blecaute') {
				$d = $this->distritoRepo->buscarPorId($distrito->id);
				if ($d) {
					$d->economia = max(0, $d->economia - 20);
					$this->distritoRepo->atualizar($d);
				}
			}

			$criados[] = [
				'tipo'          => $modelo['tipo'],
				'titulo'        => $modelo['titulo'],
				'distrito_id'   => $distrito->id,
				'distrito_nome' => $distrito->nome,
			];
		}

		return $criados;
	}

	/** Expira eventos cuja data_fim já passou. Retorna quantos foram expirados. */
	public function expirarEventos(): int {
		return $this->eventoRepo->expirarEventosAntigos();
	}

	public function temEvento(int $distritoId, string $tipo): bool {
		foreach ($this->eventoRepo->listarAtivosPorDistrito($distritoId) as $e) {
			if ($e->tipo === $tipo) return true;
		}
		return false;
	}

	/** @return EventoMapa[] */
	public function listarAtivos(): array {
		return $this->eventoRepo->listarAtivos();
	}
}
