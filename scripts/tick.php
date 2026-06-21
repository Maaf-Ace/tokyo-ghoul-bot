<?php

/**
 * tick.php — O "coração batendo" do submundo.
 *
 * Rode este script via crontab a cada N minutos, por exemplo a cada 10 min:
 *   0,10,20,30,40,50 * * * * php /caminho/completo/para/scripts/tick.php >> /caminho/para/logs/tick.log 2>&1
 *   (equivalente a "a cada 10 minutos" sem usar a sintaxe "barra-asterisco" dentro do bloco de comentário)
 *
 * O que ele faz a cada execução:
 *  1. Passa o tempo em todas as facções Ghoul (fome, agressividade, sigilo).
 *  2. Dá a chance de cada facção iniciar um conflito com outra (respeitando diplomacia).
 *  3. Verifica operações ativas cujo prazo já venceu e marca como concluídas.
 *  4. Imprime um resumo (útil pro log; depois plugamos isso pra postar no Discord).
 */

require_once __DIR__ . '/../bootstrap.php';

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando tick...\n";

try {
	$motor = new MotorEventos();
	$eventos = $motor->rodarTick();

	echo "  -> " . count($eventos) . " evento(s) de mundo processado(s).\n";
	foreach ($eventos as $evento) {
		if ($evento['tipo'] === 'combate') {
			echo "     [COMBATE] {$evento['atacante']} atacou {$evento['defensor']} -> {$evento['resultado_texto']} (vencedor: {$evento['vencedor']})\n";
		} else {
			echo "     [TEMPO] {$evento['faccao']}: fome={$evento['fome']} agressividade={$evento['agressividade']} sigilo={$evento['sigilo']}\n";
		}
	}

	$gerenciadorOps = new GerenciadorOperacoes();
	$concluidas = $gerenciadorOps->processarConclusoes();

	echo "  -> " . count($concluidas) . " operação(ões) concluída(s).\n";
	foreach ($concluidas as $op) {
		echo "     [OPERAÇÃO] {$op->tipo} da facção {$op->faccaoId} no distrito {$op->distritoAlvo} foi concluída.\n";
	}

	// TODO (próxima etapa): pegar $eventos e $concluidas e postar um resumo formatado
	// no canal de eventos do Discord via webhook ou via fila que o bot.php consome.

} catch (Throwable $e) {
	error_log('[tick.php] Erro: ' . $e->getMessage());
	echo "ERRO: " . $e->getMessage() . "\n";
	exit(1);
}

$duracao = round(microtime(true) - $inicio, 2);
echo "[" . date('Y-m-d H:i:s') . "] Tick finalizado em {$duracao}s.\n";
