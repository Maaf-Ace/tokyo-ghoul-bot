<?php

/**
 * tick_semanal.php — Ciclo semanal do mundo.
 *
 * Cron sugerido (toda segunda-feira às 08:00):
 *   0 8 * * 1 php /caminho/para/scripts/tick_semanal.php >> /logs/tick_semanal.log 2>&1
 *
 * A cada execução:
 *  1. Processa o ciclo financeiro da CCG (renda, dano colateral, corte de financiamento).
 *  2. Sorteia novos Eventos de Mapa (blecautes, protestos, etc.).
 *  3. Expira eventos antigos.
 *  4. Publica tudo no Discord via Clarim de Tóquio.
 */

require_once __DIR__ . '/../bootstrap.php';

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando tick semanal...\n";

try {
	$webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
	$webhookOps      = Env::get('DISCORD_WEBHOOK_OPS', '');
	$clarim          = new ClarimToquio($webhookNoticias, $webhookOps);

	// 1. Ciclo financeiro da CCG
	$sistemaFinanceiro = new SistemaFinanceiro();
	$res               = $sistemaFinanceiro->processarSemana();

	echo "  [FINANCEIRO] Renda base:          ¥{$res['renda_base']}\n";
	echo "  [FINANCEIRO] Dano colateral:      -¥{$res['dano_colateral']}\n";
	echo "  [FINANCEIRO] Corte financiamento: -¥{$res['corte_financiamento']}\n";
	echo "  [FINANCEIRO] Renda líquida:        ¥{$res['renda_liquida']}\n";
	echo "  [FINANCEIRO] Novo saldo CCG:       ¥{$res['novo_saldo']}\n";

	// Publica boletim financeiro no canal de ops
	$boletim = "📋 **[BOLETIM SEMANAL — CCG ESQUADRÃO ZERO]**\n"
	         . "```\n"
	         . "Renda Base:         ¥" . number_format($res['renda_base'], 0, ',', '.') . "\n"
	         . "Dano Colateral:    -¥" . number_format($res['dano_colateral'], 0, ',', '.') . "\n"
	         . "Corte Financiam.:  -¥" . number_format($res['corte_financiamento'], 0, ',', '.') . "\n"
	         . "Renda Líquida:      ¥" . number_format($res['renda_liquida'], 0, ',', '.') . "\n"
	         . "Novo Saldo:         ¥" . number_format($res['novo_saldo'], 0, ',', '.') . "\n"
	         . "```";

	if ($res['corte_financiamento'] > 0) {
		$boletim .= "\n⚠️ **Corte de financiamento aplicado:** distritos em nível crítico reduziram a renda da equipe.";
	}
	if ($res['dano_colateral'] > 0) {
		$boletim .= "\n🔧 **Indenizações pagas:** ¥" . number_format($res['dano_colateral'], 0, ',', '.') . " descontados por danos colaterais da semana anterior.";
	}

	$clarim->publicarResultadoOperacao($boletim);

	// 2. Eventos de Mapa
	$gerEventos  = new GerenciadorEventosMapa();
	$expirados   = $gerEventos->expirarEventos();
	$novosEventos = $gerEventos->sortearEventosSemana();

	echo "  [EVENTOS] {$expirados} evento(s) expirado(s).\n";
	echo "  [EVENTOS] " . count($novosEventos) . " novo(s) evento(s) sorteado(s).\n";

	// Publica cada novo evento no Clarim
	if (!empty($novosEventos)) {
		$distritoRepo = new DistritoRepository();
		$eventoRepo   = new EventoMapaRepository();
		$ativos       = $eventoRepo->listarAtivos();

		// Publica apenas os recém-criados (os últimos N)
		$recentes = array_slice($ativos, 0, count($novosEventos));
		foreach ($recentes as $evento) {
			$distrito = $distritoRepo->buscarPorId($evento->distritoId);
			if ($distrito) {
				$clarim->publicarEventoMapa($evento, $distrito);
				echo "     [{$evento->tipo}] {$evento->titulo} em {$distrito->nome}\n";
			}
		}
	}

} catch (Throwable $e) {
	error_log('[tick_semanal.php] Erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
	echo "ERRO: " . $e->getMessage() . "\n";
	exit(1);
}

$duracao = round(microtime(true) - $inicio, 2);
echo "[" . date('Y-m-d H:i:s') . "] Tick semanal finalizado em {$duracao}s.\n";
