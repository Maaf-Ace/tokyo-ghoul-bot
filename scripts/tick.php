<?php

date_default_timezone_set('America/Sao_Paulo');

/**
 * tick.php — O "coracao batendo" do submundo.
 *
 * Cron sugerido (a cada 10 min):
 *   0,10,20,30,40,50 * * * * php /caminho/para/scripts/tick.php >> /logs/tick.log 2>&1
 *
 * A cada execucao:
 *  1. Resolve confrontos_pendentes expirados (sem resposta do jogador).
 *  2. Passa o tempo em faccoes Ghoul (fome, agressividade, sigilo).
 *  3. Cada faccao executa uma Rotina de Sobrevivencia.
 *  4. Movimentos de IA das faccoes Ghoul.
 *  5. Pressao de zona (faccoes predadoras reduzem seguranca de vizinhos).
 *  6. Verifica revolta em distritos (satisfacaoGeral >= 100 ou < 20).
 *  7. Tenta iniciar conflitos entre faccoes.
 *  8. Processa operacoes CCG concluidas.
 *  9. Publica manchetes no Tokyo-GO via webhook.
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

	// 2. Motor de eventos: passagem de tempo + conflitos (com pendentes integrados)
	$eventos = $motor->rodarTick();
	echo "  -> " . count($eventos) . " evento(s) de mundo processado(s).\n";

	// 3. Rotinas de sobrevivencia das faccoes Ghoul
	$rotinas      = new RotinasSobrevivencia();
	$faccoesGhoul = $faccaoRepo->listarFaccoesGhoul();

	foreach ($faccoesGhoul as $faccao) {
		$resultado = $rotinas->executarParaFaccao($faccao);
		$eventos[] = $resultado;
		echo "     [ROTINA/{$resultado['acao']}] {$resultado['descricao']}\n";
	}

	// 4. Movimentos de IA das faccoes Ghoul
	$sistemaMovimento = new SistemaMovimento();
	$movimentos       = $sistemaMovimento->executarMovimentoIA();

	foreach ($movimentos as $mov) {
		$dOrigem  = $distritoRepo->buscarPorId($mov['origem']);
		$dDestino = $distritoRepo->buscarPorId($mov['destino']);
		$nOrig    = $dOrigem  ? $dOrigem->nome  : "#{$mov['origem']}";
		$nDest    = $dDestino ? $dDestino->nome : "#{$mov['destino']}";
		echo "     [MOVIMENTO] {$mov['faccao']}: {$nOrig} -> {$nDest} ({$mov['motivo']})\n";
	}

	// 5. Pressao de zona (faccoes predadoras)
	$afetados = $sistemaMovimento->aplicarPressaoZona();
	if (!empty($afetados)) {
		foreach ($afetados as $a) {
			echo "     [PRESSAO] {$a['nome']} perdeu {$a['reducao']} pts de seguranca (zona predadora).\n";
		}
	}

	// 6. Verifica revolta em distritos dominados
	$distritos = $distritoRepo->listarTodos();
	foreach ($distritos as $d) {
		if (!$d->faccaoDominanteId) continue;

		if ($d->satisfacaoGeral >= 100 || $d->satisfacaoGeral < 20) {
			$distritoRepo->expulsarDominador($d->id);
			$questRepo = new QuestDistritoRepository();
			$questRepo->resetarParaDistrito($d->id);
			$questRepo->seedParaDistrito($d->id);
			echo "     [REVOLTA] {$d->nome} (#{$d->id}) expulsou {$d->faccaoDominanteId} "
			   . "(satisfacao: {$d->satisfacaoGeral}%)\n";
		}
	}

	// 7. Publica eventos (combates + cacadas) no Tokyo-GO
	$clarim->publicarEventosTick($eventos, $distritoRepo);

	// 8. Processa operacoes CCG concluidas
	$gerenciadorOps = new GerenciadorOperacoes();
	$resolvedor     = new ResolvedorOperacoes();
	$concluidas     = $gerenciadorOps->processarConclusoes();

	echo "  -> " . count($concluidas) . " operacao(oes) concluida(s).\n";

	foreach ($concluidas as $op) {
		echo "     [OPERACAO] {$op->tipo} no distrito {$op->distritoAlvo} concluida.\n";
		$mensagemResultado = $resolvedor->resolver($op);
		if ($mensagemResultado) {
			$clarim->publicarResultadoOperacao($mensagemResultado);
		}
	}

	// 9. Log de combates
	foreach ($eventos as $ev) {
		if (($ev['tipo'] ?? '') === 'combate') {
			$sin = !empty($ev['derrota_sinistra']) ? ' [SINISTRA]' : '';
			echo "     [COMBATE{$sin}] {$ev['atacante']} vs {$ev['defensor']} -> {$ev['resultado_texto']}\n";
		}
	}

} catch (Throwable $e) {
	error_log('[tick.php] Erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
	echo "ERRO: " . $e->getMessage() . "\n";
	exit(1);
}

$duracao = round(microtime(true) - $inicio, 2);
echo "[" . date('Y-m-d H:i:s') . "] Tick finalizado em {$duracao}s.\n";
