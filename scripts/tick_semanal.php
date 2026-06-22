<?php

/**
 * tick_semanal.php — Ciclo semanal do mundo.
 *
 * Cron sugerido (toda segunda-feira as 08:00):
 *   0 8 * * 1 php /caminho/para/scripts/tick_semanal.php >> /logs/tick_semanal.log 2>&1
 *
 * A cada execucao:
 *  1. Processa o ciclo financeiro da CCG.
 *  2. Reseta posicoes de todas as faccoes para as bases.
 *  3. Reseta contadores de acoes semanais.
 *  4. Sorteia novos Eventos de Mapa.
 *  5. Expira eventos antigos.
 *  6. Limpa movimentos antigos do log.
 *  7. Publica tudo no Discord via Clarim de Toquio.
 */

require_once __DIR__ . '/../bootstrap.php';

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando tick semanal...\n";

try {
	$webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
	$webhookOps      = Env::get('DISCORD_WEBHOOK_OPS', '');
	$clarim          = new ClarimToquio($webhookNoticias, $webhookOps);

	// 0. Proteção contra dupla execução na mesma semana.
	//    Se já existe registro financeiro para hoje (a segunda-feira), o ciclo já rodou.
	//    Isso evita duplicar a renda caso o bot reinicie e dispare o tick semanal de novo.
	$db   = Database::getConnection();
	$stmt = $db->query("SELECT 1 FROM financas_ccg WHERE semana = CURDATE() LIMIT 1");
	if ($stmt->fetch()) {
		echo "[" . date('Y-m-d H:i:s') . "] Ciclo semanal já processado hoje. Abortando para não duplicar.\n";
		exit(0);
	}

	// 1. Ciclo financeiro da CCG
	$sistemaFinanceiro = new SistemaFinanceiro();
	$res               = $sistemaFinanceiro->processarSemana();

	echo "  [FINANCEIRO] Renda base:          Y{$res['renda_base']}\n";
	echo "  [FINANCEIRO] Dano colateral:      -Y{$res['dano_colateral']}\n";
	echo "  [FINANCEIRO] Corte financiamento: -Y{$res['corte_financiamento']}\n";
	echo "  [FINANCEIRO] Renda liquida:        Y{$res['renda_liquida']}\n";
	echo "  [FINANCEIRO] Novo saldo CCG:       Y{$res['novo_saldo']}\n";

	$boletim = "**[BOLETIM SEMANAL — CCG ESQUADRAO ZERO]**\n"
	         . "```\n"
	         . "Renda Base:         Y" . number_format($res['renda_base'], 0, ',', '.') . "\n"
	         . "Dano Colateral:    -Y" . number_format($res['dano_colateral'], 0, ',', '.') . "\n"
	         . "Corte Financiam.:  -Y" . number_format($res['corte_financiamento'], 0, ',', '.') . "\n"
	         . "Renda Liquida:      Y" . number_format($res['renda_liquida'], 0, ',', '.') . "\n"
	         . "Novo Saldo:         Y" . number_format($res['novo_saldo'], 0, ',', '.') . "\n"
	         . "```";

	if ($res['corte_financiamento'] > 0) {
		$boletim .= "\n[!] Corte de financiamento aplicado: distritos em nivel critico reduziram a renda.";
	}
	if ($res['dano_colateral'] > 0) {
		$boletim .= "\n[!] Indenizacoes pagas: Y" . number_format($res['dano_colateral'], 0, ',', '.') . " descontados por danos colaterais da semana anterior.";
	}

	$clarim->publicarResultadoOperacao($boletim);

	// 2. Reset de posicoes de todas as faccoes
	$sistemaMovimento = new SistemaMovimento();
	$resets           = $sistemaMovimento->resetarPosicoes();
	echo "  [RESET] " . count($resets) . " faccao(oes) retornou para a base.\n";
	foreach ($resets as $r) {
		echo "     -> {$r['faccao']} -> Distrito #{$r['base']}\n";
	}

	// 3. Reset dos contadores de acoes semanais (apaga semanas anteriores)
	$semRepo    = new AcoesSemanaRepository();
	$apagados   = $semRepo->resetarSemanasAntigas();
	echo "  [RESET] {$apagados} registro(s) de acoes da semana anterior removido(s).\n";

	// 4. Eventos de Mapa
	$gerEventos   = new GerenciadorEventosMapa();
	$expirados    = $gerEventos->expirarEventos();
	$novosEventos = $gerEventos->sortearEventosSemana();

	echo "  [EVENTOS] {$expirados} evento(s) expirado(s).\n";
	echo "  [EVENTOS] " . count($novosEventos) . " novo(s) evento(s) sorteado(s).\n";

	if (!empty($novosEventos)) {
		$distritoRepo = new DistritoRepository();
		$eventoRepo   = new EventoMapaRepository();
		$ativos       = $eventoRepo->listarAtivos();
		$recentes     = array_slice($ativos, 0, count($novosEventos));

		foreach ($recentes as $evento) {
			$distrito = $distritoRepo->buscarPorId($evento->distritoId);
			if ($distrito) {
				$clarim->publicarEventoMapa($evento, $distrito);
				echo "     [{$evento->tipo}] {$evento->titulo} em {$distrito->nome}\n";
			}
		}
	}

	// 5. Limpa log de movimentos com mais de 30 dias
	$movRepo = new MovimentoRepository();
	$limpos  = $movRepo->limparAntigos(30);
	echo "  [LIMPEZA] {$limpos} movimento(s) antigo(s) removido(s) do log.\n";

	// 6. Boletim de reset para o canal de noticias
	$msgReset = "**[AVISO DO SISTEMA]**\n"
	          . "Nova semana iniciada. Posicoes e limites operacionais resetados.\n"
	          . "CCG e demais faccoes retornam as suas bases. Boa sorte, Investigadores.";
	$clarim->publicarManual($msgReset);

} catch (Throwable $e) {
	error_log('[tick_semanal.php] Erro: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
	echo "ERRO: " . $e->getMessage() . "\n";
	exit(1);
}

$duracao = round(microtime(true) - $inicio, 2);
echo "[" . date('Y-m-d H:i:s') . "] Tick semanal finalizado em {$duracao}s.\n";
