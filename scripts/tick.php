<?php

/**
 * tick.php — O "coracao batendo" do submundo.
 *
 * Cron sugerido (a cada 10 min):
 *   0,10,20,30,40,50 * * * * php /caminho/para/scripts/tick.php >> /logs/tick.log 2>&1
 *
 * A cada execucao:
 *  1. Passa o tempo em faccoes Ghoul (fome, agressividade, sigilo).
 *  2. Cada faccao executa uma Rotina de Sobrevivencia (caca, contrabando ou recrutamento).
 *  3. Movimentos de IA das faccoes Ghoul.
 *  4. Pressao de zona Aogiri (distritos adjacentes perdem apoio civil).
 *  5. Tenta iniciar conflitos entre faccoes.
 *  6. Processa operacoes CCG concluidas (resolve e posta resultado).
 *  7. Publica manchetes no Clarim de Toquio via webhook.
 */

require_once __DIR__ . '/../bootstrap.php';

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando tick...\n";

try {
	$webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
	$webhookOps      = Env::get('DISCORD_WEBHOOK_OPS', '');
	$clarim          = new ClarimToquio($webhookNoticias, $webhookOps);
	$distritoRepo    = new DistritoRepository();
	$faccaoRepo      = new FaccaoRepository();

	// 1. Motor de eventos: passagem de tempo + conflitos
	$motor   = new MotorEventos();
	$eventos = $motor->rodarTick();
	echo "  -> " . count($eventos) . " evento(s) de mundo processado(s).\n";

	// 2. Rotinas de sobrevivencia das faccoes Ghoul
	$rotinas      = new RotinasSobrevivencia();
	$faccoesGhoul = $faccaoRepo->listarFaccoesGhoul();

	foreach ($faccoesGhoul as $faccao) {
		$resultado = $rotinas->executarParaFaccao($faccao);
		$eventos[] = $resultado;
		echo "     [ROTINA/{$resultado['acao']}] {$resultado['descricao']}\n";
	}

	// 3. Movimentos de IA das faccoes Ghoul
	$sistemaMovimento  = new SistemaMovimento();
	$movimentos        = $sistemaMovimento->executarMovimentoIA();

	foreach ($movimentos as $mov) {
		$dOrigem  = $distritoRepo->buscarPorId($mov['origem']);
		$dDestino = $distritoRepo->buscarPorId($mov['destino']);
		$nOrig    = $dOrigem  ? $dOrigem->nome  : "#{$mov['origem']}";
		$nDest    = $dDestino ? $dDestino->nome : "#{$mov['destino']}";
		echo "     [MOVIMENTO] {$mov['faccao']}: {$nOrig} -> {$nDest} ({$mov['motivo']})\n";
	}

	// 4. Pressao de zona Aogiri
	$afetados = $sistemaMovimento->aplicarPressaoZona();
	if (!empty($afetados)) {
		foreach ($afetados as $a) {
			echo "     [PRESSAO] {$a['nome']} perdeu {$a['reducao']} pts de apoio civil (zona Aogiri).\n";
		}
	}

	// 5. Publica eventos (combates + cacadas) no Clarim
	$clarim->publicarEventosTick($eventos, $distritoRepo);

	// 6. Processa operacoes CCG concluidas
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

	// 7. Log de combates
	foreach ($eventos as $ev) {
		if ($ev['tipo'] === 'combate') {
			echo "     [COMBATE] {$ev['atacante']} atacou {$ev['defensor']} -> {$ev['resultado_texto']} (vencedor: {$ev['vencedor']})\n";
		}
	}

} catch (Throwable $e) {
	error_log('[tick.php] Erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
	echo "ERRO: " . $e->getMessage() . "\n";
	exit(1);
}

$duracao = round(microtime(true) - $inicio, 2);
echo "[" . date('Y-m-d H:i:s') . "] Tick finalizado em {$duracao}s.\n";
