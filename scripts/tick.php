<?php

/**
 * tick.php — O "coração batendo" do submundo.
 *
 * Cron sugerido (a cada 10 min):
 *   0,10,20,30,40,50 * * * * php /caminho/para/scripts/tick.php >> /logs/tick.log 2>&1
 *
 * A cada execução:
 *  1. Passa o tempo em facções Ghoul (fome, agressividade, sigilo).
 *  2. Cada facção executa uma Rotina de Sobrevivência (caça, contrabando ou recrutamento).
 *  3. Tenta iniciar conflitos entre facções.
 *  4. Processa operações ativas cujo prazo venceu (resolve e posta resultado).
 *  5. Publica manchetes relevantes no Clarim de Tóquio via webhook.
 */

require_once __DIR__ . '/../bootstrap.php';

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando tick...\n";

try {
	$webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
	$webhookOps      = Env::get('DISCORD_WEBHOOK_OPS', '');
	$clarim          = new ClarimToquio($webhookNoticias, $webhookOps);
	$distritoRepo    = new DistritoRepository();

	// 1. Motor de eventos: passagem de tempo + conflitos
	$motor   = new MotorEventos();
	$eventos = $motor->rodarTick();
	echo "  -> " . count($eventos) . " evento(s) de mundo processado(s).\n";

	// 2. Rotinas de sobrevivência das facções Ghoul
	$rotinas       = new RotinasSobrevivencia();
	$faccoesGhoul  = (new FaccaoRepository())->listarFaccoesGhoul();

	foreach ($faccoesGhoul as $faccao) {
		$resultado = $rotinas->executarParaFaccao($faccao);
		$eventos[] = $resultado;
		echo "     [ROTINA/{$resultado['acao']}] {$resultado['descricao']}\n";
	}

	// 3. Publica eventos (combates + caçadas) no Clarim
	$clarim->publicarEventosTick($eventos, $distritoRepo);

	// 4. Processa operações CCG concluídas
	$gerenciadorOps = new GerenciadorOperacoes();
	$resolvedor     = new ResolvedorOperacoes();
	$concluidas     = $gerenciadorOps->processarConclusoes();

	echo "  -> " . count($concluidas) . " operação(ões) concluída(s).\n";

	foreach ($concluidas as $op) {
		echo "     [OPERAÇÃO] {$op->tipo} da facção {$op->faccaoId} no distrito {$op->distritoAlvo} concluída.\n";

		$mensagemResultado = $resolvedor->resolver($op);
		if ($mensagemResultado) {
			$clarim->publicarResultadoOperacao($mensagemResultado);
		}
	}

	// 5. Log de combates para referência
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
