<?php

date_default_timezone_set('America/Sao_Paulo');

/**
 * tick.php — O "coração batendo" do submundo.
 *
 * Cron sugerido (a cada 10 min):
 *   0,10,20,30,40,50 * * * * php /caminho/para/scripts/tick.php >> /logs/tick.log 2>&1
 *
 * Ordem de execução:
 *  1. Resolve confrontos_pendentes expirados (sem resposta do jogador).
 *  2. Motor de eventos: passagem de tempo + tentativas de conflito entre facções.
 *  3. Agenda atividades para facções ghoul sem operação pendente.
 *  4. Resolve atividades ghoul cujo prazo venceu (alimentar, cacar, etc.).
 *  5. Movimentos de IA das facções ghoul.
 *  6. Pressão de zona (facções predadoras reduzem segurança de vizinhos).
 *  7. Verifica revoltas em distritos dominados.
 *  8. Detecção de atividades ghoul pela CCG (gera botão de interceptar).
 *  9. Publica manchetes no Tokyo-GO via webhook.
 * 10. Processa operações CCG concluídas.
 */

require_once __DIR__ . '/../bootstrap.php';

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando tick...\n";

try {
	$webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
	$webhookOps      = Env::get('DISCORD_WEBHOOK_OPS', '');
	$botToken        = Env::get('DISCORD_TOKEN', '');
	$opsChannelId    = Env::get('DISCORD_OPS_CHANNEL_ID', '');

	$clarim       = new ClarimToquio($webhookNoticias, $webhookOps, $botToken, $opsChannelId);
	$cpRepo       = new ConfruntoPendenteRepository();
	$distritoRepo = new DistritoRepository();
	$faccaoRepo   = new FaccaoRepository();

	// 1. Resolve confrontos expirados (sem escolha do jogador)
	$motor     = new MotorEventos(null, null, null, null, $clarim, $cpRepo);
	$expirados = $motor->resolverConfrontosExpirados();
	if (!empty($expirados)) {
		foreach ($expirados as $ev) {
			echo "  [CONFRONTO EXPIRADO] {$ev['atacante']} vs {$ev['defensor']} -> {$ev['vencedor']}\n";
		}
		$clarim->publicarEventosTick($expirados, $distritoRepo);
	}

	// 2. Motor de eventos: passagem de tempo + conflitos entre facções
	$eventos = $motor->rodarTick();
	echo "  -> " . count($eventos) . " evento(s) de mundo processado(s).\n";

	// 3. Agenda atividades para facções ghoul sem operação pendente
	$rotinas      = new RotinasSobrevivencia(null, null, new OperacaoGhoulRepository());
	$faccoesGhoul = $faccaoRepo->listarFaccoesGhoul();

	foreach ($faccoesGhoul as $faccao) {
		$agendado = $rotinas->agendarParaFaccao($faccao);
		if ($agendado) {
			echo "  [AGENDADO] {$agendado['faccao']}: {$agendado['tipo']}"
			   . ($agendado['distrito_id'] ? " em #{$agendado['distrito_id']}" : '') . "\n";
		}
	}

	// 4. Resolve atividades ghoul cujo prazo venceu
	$concluidasGhoul = $rotinas->resolverConcluidas();
	foreach ($concluidasGhoul as $resultado) {
		$eventos[] = $resultado;
		echo "  [GHOUL/{$resultado['acao']}] {$resultado['descricao']}\n";
	}

	// 5. Movimentos de IA das facções ghoul
	$sistemaMovimento = new SistemaMovimento();
	$movimentos       = $sistemaMovimento->executarMovimentoIA();
	foreach ($movimentos as $mov) {
		$dOrigem  = $distritoRepo->buscarPorId($mov['origem']);
		$dDestino = $distritoRepo->buscarPorId($mov['destino']);
		$nOrig    = $dOrigem  ? $dOrigem->nome  : "#{$mov['origem']}";
		$nDest    = $dDestino ? $dDestino->nome : "#{$mov['destino']}";
		echo "  [MOVIMENTO] {$mov['faccao']}: {$nOrig} -> {$nDest} ({$mov['motivo']})\n";
	}

	// 6. Pressão de zona (facções predadoras)
	$afetados = $sistemaMovimento->aplicarPressaoZona();
	foreach ($afetados as $a) {
		echo "  [PRESSAO] {$a['nome']} perdeu {$a['reducao']} pts de seguranca (zona predadora).\n";
	}

	// 7. Verifica revolta em distritos dominados
	$distritos = $distritoRepo->listarTodos();
	foreach ($distritos as $d) {
		if (!$d->faccaoDominanteId) continue;
		if ($d->satisfacaoGeral >= 100 || $d->satisfacaoGeral < 20) {
			$distritoRepo->expulsarDominador($d->id);
			$questRepo = new QuestDistritoRepository();
			$questRepo->resetarParaDistrito($d->id);
			$questRepo->seedParaDistrito($d->id);
			echo "  [REVOLTA] {$d->nome} (#{$d->id}) expulsou {$d->faccaoDominanteId} "
			   . "(satisfacao: {$d->satisfacaoGeral}%)\n";
		}
	}

	// 8. Detecção de atividades ghoul pela CCG
	$ccg          = $faccaoRepo->buscarPorId('ccg');
	$conhecimento = (new ConhecimentoCCGRepository())->listarTodos();
	$adjRepo      = new AdjacenciaRepository();
	$opGhoulRepo  = new OperacaoGhoulRepository();
	$opsAtivas    = $opGhoulRepo->listarPendentes();

	foreach ($opsAtivas as $op) {
		if ($op['alerta_enviado'] || !$op['distrito_id'] || !$ccg) continue;
		$dist = (int) $op['distrito_id'];
		if (!$adjRepo->saoAdjacentes((int) $ccg->posicaoAtual, $dist)) continue;

		$nivel = $conhecimento[$dist]['nivel_conhecimento'] ?? 'desconhecido';
		$chanceDeteccao = match ($nivel) {
			'basico'  => 20,
			'bom'     => 35,
			'critico' => 50,
			default   => 0,
		};
		if ($chanceDeteccao === 0) continue;

		if (rand(1, 100) <= $chanceDeteccao) {
			$fac      = $faccaoRepo->buscarPorId($op['faccao_id']);
			$nomeDist = $distritoRepo->buscarPorId($dist)?->nome ?? "Distrito #{$dist}";
			$msgId    = $clarim->publicarAlertaIntercepcao($op['id'], $fac, $nomeDist, $op['tipo']);
			if ($msgId) {
				$opGhoulRepo->marcarAlertaEnviado($op['id']);
				echo "  [DETECCAO] {$op['faccao_id']} em #{$dist} detectada pela CCG.\n";
			}
		}
	}

	// 9. Publica manchetes (combates + caçadas + alimentações)
	$clarim->publicarEventosTick($eventos, $distritoRepo);

	// 10. Processa operações CCG concluídas
	$gerenciadorOps = new GerenciadorOperacoes();
	$resolvedor     = new ResolvedorOperacoes();
	$concluidas     = $gerenciadorOps->processarConclusoes();

	echo "  -> " . count($concluidas) . " operacao(oes) CCG concluida(s).\n";
	foreach ($concluidas as $op) {
		echo "  [OPERACAO CCG] {$op->tipo} no distrito {$op->distritoAlvo} concluida.\n";
		$mensagemResultado = $resolvedor->resolver($op);
		if ($mensagemResultado) {
			$clarim->publicarResultadoOperacao($mensagemResultado);
		}
	}

	foreach ($eventos as $ev) {
		if (($ev['tipo'] ?? '') === 'combate') {
			$sin = !empty($ev['derrota_sinistra']) ? ' [SINISTRA]' : '';
			echo "  [COMBATE{$sin}] {$ev['atacante']} vs {$ev['defensor']} -> {$ev['resultado_texto']}\n";
		}
	}

} catch (Throwable $e) {
	error_log('[tick.php] Erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
	echo "ERRO: " . $e->getMessage() . "\n";
	exit(1);
}

$duracao = round(microtime(true) - $inicio, 2);
echo "[" . date('Y-m-d H:i:s') . "] Tick finalizado em {$duracao}s.\n";
