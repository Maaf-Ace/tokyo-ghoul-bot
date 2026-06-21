<?php

/**
 * Resolve operações concluídas e gera a mensagem de resultado para o Discord.
 * Chamado pelo tick.php após GerenciadorOperacoes::processarConclusoes().
 */
class ResolvedorOperacoes {

	private DistritoRepository        $distritoRepo;
	private FaccaoRepository          $faccaoRepo;
	private EventoMapaRepository      $eventoRepo;
	private QuinqueRepository         $quinqueRepo;
	private HistoricoCombateRepository $historicoRepo;

	public function __construct(
		?DistritoRepository        $distritoRepo  = null,
		?FaccaoRepository          $faccaoRepo    = null,
		?EventoMapaRepository      $eventoRepo    = null,
		?QuinqueRepository         $quinqueRepo   = null,
		?HistoricoCombateRepository $historicoRepo = null
	) {
		$this->distritoRepo  = $distritoRepo  ?? new DistritoRepository();
		$this->faccaoRepo    = $faccaoRepo    ?? new FaccaoRepository();
		$this->eventoRepo    = $eventoRepo    ?? new EventoMapaRepository();
		$this->quinqueRepo   = $quinqueRepo   ?? new QuinqueRepository();
		$this->historicoRepo = $historicoRepo ?? new HistoricoCombateRepository();
	}

	/**
	 * Computa o resultado de uma operação.
	 * @return string|null Mensagem formatada para Discord, ou null se o tipo não tem resolver definido.
	 */
	public function resolver(Operacao $op): ?string {
		return match ($op->tipo) {
			'investigacao' => $this->resolverInvestigacao($op),
			'patrulha'     => $this->resolverPatrulha($op),
			'pesquisa'     => $this->resolverPesquisa($op),
			'campanha'     => $this->resolverCampanha($op),
			default        => null,
		};
	}

	// ── Investigação ──────────────────────────────────────────────────────────

	private function resolverInvestigacao(Operacao $op): string {
		$distrito    = $this->distritoRepo->buscarPorId($op->distritoAlvo);
		$solicitante = $op->solicitanteDiscordId ? "<@{$op->solicitanteDiscordId}>" : 'Agente';

		if (!$distrito) {
			return "📋 **[RELATÓRIO DE INTELIGÊNCIA]**\n{$solicitante} ❌ Distrito não encontrado.";
		}

		$faccao = $distrito->faccaoDominanteId
			? $this->faccaoRepo->buscarPorId($distrito->faccaoDominanteId)
			: null;

		$eventosAtivos = $this->eventoRepo->listarAtivosPorDistrito($distrito->id);

		$iconeAlerta = match (true) {
			$distrito->nivelAlerta >= 4 => '🔴',
			$distrito->nivelAlerta >= 3 => '🟠',
			$distrito->nivelAlerta >= 2 => '🟡',
			default                     => '🟢',
		};

		$controleMsg = $faccao
			? "Controlado por **{$faccao->nome}** (Dominação: {$distrito->nivelDominacao}%)"
			: '**Território Neutro**';

		$linhasEventos = '';
		if (!empty($eventosAtivos)) {
			$linhasEventos = "\n**Eventos Ativos:**\n";
			foreach ($eventosAtivos as $e) {
				$linhasEventos .= "> ⚡ {$e->titulo}\n";
			}
		}

		$dicaBonus = '';
		if ($distrito->apoioCivil >= 50) {
			$dicas = [
				"Moradores relatam movimentação suspeita na região sul. Possível esconderijo.",
				"Um informante anônimo menciona reuniões noturnas em um armazém abandonado.",
				"Relatos de entregas irregulares foram feitas à redação da delegacia local.",
				"Crianças da área descreveram 'homens assustadores' num prédio específico.",
			];
			$dicaBonus = "\n> 💡 **Dica dos moradores:** " . $dicas[array_rand($dicas)];
		}

		return "📋 **[RELATÓRIO DE INTELIGÊNCIA — CLASSIFICADO]**\n"
		     . "{$solicitante} | 📍 **{$distrito->nome}**\n"
		     . "───────────────────────────\n"
		     . "{$iconeAlerta} Nível de Alerta: **{$distrito->nivelAlerta}/5**\n"
		     . "🏙️ {$controleMsg}\n"
		     . "👥 Apoio Civil: **{$distrito->apoioCivil}%**\n"
		     . "⚔️ Status: *{$distrito->statusGuerra}*"
		     . $linhasEventos
		     . $dicaBonus;
	}

	// ── Patrulha ──────────────────────────────────────────────────────────────

	private function resolverPatrulha(Operacao $op): string {
		$distrito    = $this->distritoRepo->buscarPorId($op->distritoAlvo);
		$solicitante = $op->solicitanteDiscordId ? "<@{$op->solicitanteDiscordId}>" : 'Agente';

		if (!$distrito) {
			return "🛡️ **[RELATÓRIO DE PATRULHA]**\n{$solicitante} ❌ Distrito não encontrado.";
		}

		$apoioAntes   = $distrito->apoioCivil;
		$alertaAntes  = $distrito->nivelAlerta;
		$ganhoApoio   = rand(15, 25);
		$reducaoAlerta = $distrito->nivelAlerta >= 2 ? 1 : 0;

		$distrito->apoioCivil  = min(100, $distrito->apoioCivil + $ganhoApoio);
		$distrito->nivelAlerta = max(1, $distrito->nivelAlerta - $reducaoAlerta);
		$this->distritoRepo->atualizar($distrito);

		$relatos = [
			'"Os guardas foram gentis. Ajudaram uma idosa a atravessar a rua."',
			'"A presença deles deixou todos mais tranquilos. Faz diferença."',
			'"Finalmente! A gente estava esperando isso faz semanas."',
			'"Distribuíram panfletos sobre segurança. Isso importa pra comunidade."',
			'"Vi os agentes conversando com as crianças. Passam confiança."',
		];

		return "🛡️ **[RELATÓRIO DE PATRULHA — CONCLUÍDO]**\n"
		     . "{$solicitante} | 📍 **{$distrito->nome}**\n"
		     . "───────────────────────────\n"
		     . "✅ Patrulha pacífica concluída sem incidentes.\n"
		     . "👥 Apoio Civil: **{$apoioAntes}%** → **{$distrito->apoioCivil}%** (+{$ganhoApoio})\n"
		     . "📉 Nível de Alerta: **{$alertaAntes}/5** → **{$distrito->nivelAlerta}/5**\n"
		     . "> 🗣️ Relato de morador: _" . $relatos[array_rand($relatos)] . "_";
	}

	// ── Pesquisa (Quinque) ─────────────────────────────────────────────────────

	private function resolverPesquisa(Operacao $op): string {
		$solicitante = $op->solicitanteDiscordId ? "<@{$op->solicitanteDiscordId}>" : 'Agente';

		$ultimosCombates    = $this->historicoRepo->buscarHistoricoDaFaccao('ccg', 10);
		$combateVitorioso   = null;

		foreach ($ultimosCombates as $c) {
			if ($c['vencedor_id'] === 'ccg') {
				$combateVitorioso = $c;
				break;
			}
		}

		if (!$combateVitorioso) {
			return "🔬 **[P&D — RESULTADO]**\n{$solicitante}\n"
			     . "❌ O laboratório não possui espécimes disponíveis.\n"
			     . "> A CCG precisa vencer ao menos um confronto para coletar kagune.";
		}

		$tiposRc  = ['bikaku', 'ukaku', 'rinkaku', 'koukaku'];
		$prefixos = ['Corvo', 'Névoa', 'Ferro', 'Sangue', 'Sombra', 'Cinza', 'Ôni', 'Aço'];
		$sufixos  = ['Lâmina', 'Garra', 'Escudo', 'Golpe', 'Forma', 'Vortex', 'Marca', 'Presa'];
		$tipoRc   = $tiposRc[array_rand($tiposRc)];
		$nome     = $prefixos[array_rand($prefixos)] . ' ' . $sufixos[array_rand($sufixos)] . ' ' . ucfirst($tipoRc);
		$bonus    = rand(5, 15);

		$descricoes = [
			'bikaku'  => 'Tipo Bikaku. Cauda equilibrada entre ataque e defesa. Extremamente versátil.',
			'ukaku'   => 'Tipo Ukaku. Cristais de alta velocidade à distância. Ideal para engajamentos à longa distância.',
			'rinkaku' => 'Tipo Rinkaku. Tentáculos de alto poder regenerativo. Letal em combate corpo a corpo.',
			'koukaku' => 'Tipo Koukaku. Armadura de alta resistência. Essencial para proteção em linha de frente.',
		];

		$q              = new Quinque();
		$q->id          = uniqid('qnq_');
		$q->nome        = $nome;
		$q->tipoRc      = $tipoRc;
		$q->bonusCombate = $bonus;
		$q->descricao   = $descricoes[$tipoRc];
		$q->ghoulOrigem = $combateVitorioso['defensor_id'];
		$q->faccaoId    = 'ccg';
		$q->dataCriacao = date('Y-m-d H:i:s');
		$this->quinqueRepo->criar($q);

		return "🔬 **[P&D — QUINQUE CRIADO]**\n"
		     . "{$solicitante}\n"
		     . "───────────────────────────\n"
		     . "⚔️ Nome: **{$nome}**\n"
		     . "🧬 Tipo RC: **" . strtoupper($tipoRc) . "** | Bônus de Combate: **+{$bonus}**\n"
		     . "📖 _{$descricoes[$tipoRc]}_\n"
		     . "> Origem: material coletado de **{$combateVitorioso['defensor_id']}** após combate.";
	}

	// ── Campanha de Mídia ─────────────────────────────────────────────────────

	private function resolverCampanha(Operacao $op): string {
		$distrito    = $this->distritoRepo->buscarPorId($op->distritoAlvo);
		$solicitante = $op->solicitanteDiscordId ? "<@{$op->solicitanteDiscordId}>" : 'Agente';

		if (!$distrito) {
			return "📢 **[CAMPANHA DE MÍDIA]**\n{$solicitante} ❌ Distrito não encontrado.";
		}

		$apoioAntes = $distrito->apoioCivil;
		$ganho      = rand(20, 35);

		$distrito->apoioCivil = min(100, $distrito->apoioCivil + $ganho);
		$this->distritoRepo->atualizar($distrito);

		$bonusMsg = '';
		if ($distrito->apoioCivil >= 60) {
			$bonusMsg = "\n> 🗣️ **Bônus desbloqueado:** Moradores do {$distrito->nome} começaram a fornecer "
			          . "informações espontâneas. Investigações aqui recebem dicas extras.";
		}

		return "📢 **[CAMPANHA DE MÍDIA — CONCLUÍDA]**\n"
		     . "{$solicitante} | 📍 **{$distrito->nome}**\n"
		     . "───────────────────────────\n"
		     . "✅ Campanha de relações públicas bem-sucedida.\n"
		     . "👥 Apoio Civil: **{$apoioAntes}%** → **{$distrito->apoioCivil}%** (+{$ganho})\n"
		     . "> 📰 A imagem da CCG melhorou significativamente na região."
		     . $bonusMsg;
	}
}
