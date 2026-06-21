<?php

/**
 * teste.php — Painel de diagnóstico isolado.
 * Roda fora do bot.php / Discord, só pra você confirmar visualmente
 * no navegador que cada peça do sistema está funcionando.
 *
 * Como usar:
 *   1. Coloque este arquivo na RAIZ do projeto (mesma pasta do bootstrap.php).
 *   2. Suba um servidor local: php -S localhost:8000
 *   3. Abra http://localhost:8000/teste.php no navegador.
 *
 * Não precisa rodar isso pelo Discord nem pelo terminal puro — é só HTML mesmo.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

function bloco(string $titulo, callable $fn): void {
	echo "<div style='margin:20px 0;padding:15px;border:1px solid #444;border-radius:8px;font-family:monospace;background:#1a1a1a;color:#eee;'>";
	echo "<h3 style='margin-top:0;color:#9cf;'>$titulo</h3>";
	try {
		$fn();
	} catch (Throwable $e) {
		echo "<p style='color:#f55;'>❌ ERRO: " . htmlspecialchars($e->getMessage()) . "</p>";
		echo "<pre style='color:#888;font-size:11px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
	}
	echo "</div>";
}

echo "<h1 style='font-family:sans-serif;'>🩺 Diagnóstico do Sistema — Tokyo Ghoul Bot</h1>";

// ----------------------------------------------------------------
bloco('1. Variáveis de ambiente (.env)', function () {
	$chaves = ['DISCORD_TOKEN', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SSL_CA'];
	echo "<table style='border-collapse:collapse;'>";
	foreach ($chaves as $chave) {
		$valor = Env::get($chave);
		$mostrar = $valor === null ? '<span style="color:#f55;">NÃO DEFINIDA</span>' : '<span style="color:#5f5;">✓ definida</span>';
		// Nunca mostramos o valor real de senha/token, só se existe
		if (in_array($chave, ['DB_PASS', 'DISCORD_TOKEN']) && $valor !== null) {
			$mostrar .= " (oculto, " . strlen($valor) . " caracteres)";
		} elseif ($valor !== null) {
			$mostrar .= " = " . htmlspecialchars($valor);
		}
		echo "<tr><td style='padding:4px 10px;'>$chave</td><td>$mostrar</td></tr>";
	}
	echo "</table>";
});

// ----------------------------------------------------------------
bloco('2. Certificado SSL (ca.pem)', function () {
	$caminho = Env::get('DB_SSL_CA');
	if ($caminho && file_exists($caminho)) {
		echo "<p style='color:#5f5;'>✓ Arquivo encontrado em: " . htmlspecialchars(realpath($caminho)) . "</p>";
		echo "<p>Tamanho: " . filesize($caminho) . " bytes</p>";
	} else {
		echo "<p style='color:#f55;'>❌ Arquivo NÃO encontrado no caminho: " . htmlspecialchars($caminho ?? '(não definido)') . "</p>";
	}
});

// ----------------------------------------------------------------
bloco('3. Conexão PDO com o banco', function () {
	$pdo = Database::getConnection();
	echo "<p style='color:#5f5;'>✓ Conectado com sucesso!</p>";
	$versao = $pdo->query('SELECT VERSION() as v')->fetch();
	echo "<p>Versão do MySQL: " . htmlspecialchars($versao['v']) . "</p>";
});

// ----------------------------------------------------------------
bloco('4. Tabelas existentes no banco', function () {
	$pdo = Database::getConnection();
	$tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
	$esperadas = ['faccoes', 'distritos', 'diplomacia', 'operacoes_ativas', 'historico_combates'];

	echo "<ul>";
	foreach ($esperadas as $tabela) {
		$existe = in_array($tabela, $tabelas);
		$cor = $existe ? '#5f5' : '#f55';
		$marca = $existe ? '✓' : '❌';
		echo "<li style='color:$cor;'>$marca $tabela</li>";
	}
	echo "</ul>";
});

// ----------------------------------------------------------------
bloco('5. FaccaoRepository — listar facções cadastradas', function () {
	$repo = new FaccaoRepository();
	$faccoes = $repo->listarTodas();

	if (empty($faccoes)) {
		echo "<p style='color:#fa5;'>⚠️ Nenhuma facção encontrada. Rode 'php scripts/seed.php' primeiro.</p>";
		return;
	}

	echo "<table style='border-collapse:collapse;width:100%;'><tr style='color:#9cf;'><th>ID</th><th>Nome</th><th>Poder</th><th>Sigilo</th><th>Tática</th></tr>";
	foreach ($faccoes as $f) {
		echo "<tr><td style='padding:4px 10px;'>{$f->id}</td><td>{$f->nome}</td><td>{$f->poderMilitar}</td><td>{$f->sigilo}</td><td>{$f->taticaFavorita}</td></tr>";
	}
	echo "</table>";
});

// ----------------------------------------------------------------
bloco('6. DistritoRepository — listar distritos cadastrados', function () {
	$repo = new DistritoRepository();
	$distritos = $repo->listarTodos();

	if (empty($distritos)) {
		echo "<p style='color:#fa5;'>⚠️ Nenhum distrito encontrado. Rode 'php scripts/seed.php' primeiro.</p>";
		return;
	}

	echo "<table style='border-collapse:collapse;width:100%;'><tr style='color:#9cf;'><th>ID</th><th>Nome</th><th>Dono</th><th>Dominação</th><th>Status</th></tr>";
	foreach ($distritos as $d) {
		echo "<tr><td style='padding:4px 10px;'>{$d->id}</td><td>{$d->nome}</td><td>" . ($d->faccaoDominanteId ?? '—') . "</td><td>{$d->nivelDominacao}%</td><td>{$d->statusGuerra}</td></tr>";
	}
	echo "</table>";
});

// ----------------------------------------------------------------
bloco('7. MotorEventos — simulação de combate (NÃO grava no banco real de forma destrutiva, usa as facções já existentes)', function () {
	$faccaoRepo = new FaccaoRepository();
	$aogiri = $faccaoRepo->buscarPorId('aogiri');
	$ccg = $faccaoRepo->buscarPorId('ccg');

	if (!$aogiri || !$ccg) {
		echo "<p style='color:#fa5;'>⚠️ Precisa ter 'aogiri' e 'ccg' cadastrados (rode o seed.php) para este teste.</p>";
		return;
	}

	echo "<p>Antes do combate: Aogiri poder={$aogiri->poderMilitar} sigilo={$aogiri->sigilo} | CCG poder={$ccg->poderMilitar}</p>";

	$motor = new MotorEventos();
	$resultado = $motor->resolverConfronto($aogiri, $ccg);

	echo "<p style='color:#5f5;'>✓ Combate resolvido e persistido no banco.</p>";
	echo "<pre style='background:#000;padding:10px;border-radius:5px;'>" . htmlspecialchars(print_r($resultado, true)) . "</pre>";

	echo "<p style='color:#fa5;'>⚠️ Atenção: isso alterou de verdade o poder/sigilo das facções 'aogiri' e 'ccg' no banco e gravou em historico_combates. Rode o seed.php de novo se quiser resetar os valores de teste.</p>";
});

// ----------------------------------------------------------------
bloco('8. GerenciadorOperacoes — criar e checar uma operação de teste', function () {
	$gerenciador = new GerenciadorOperacoes();

	// Cria uma operação de 0 horas (já nasce "vencida", pra testar a conclusão na hora)
	$op = $gerenciador->iniciarOperacao('investigacao_teste', 'aogiri', 11, 0);
	echo "<p style='color:#5f5;'>✓ Operação criada: id={$op->id}, status={$op->status}, fim={$op->dataFim}</p>";

	sleep(1); // garante que NOW() do banco já passou do data_fim

	$concluidas = $gerenciador->processarConclusoes();
	$achou = array_filter($concluidas, fn($o) => $o->id === $op->id);

	if (!empty($achou)) {
		echo "<p style='color:#5f5;'>✓ Operação de teste foi detectada como concluída corretamente.</p>";
	} else {
		echo "<p style='color:#fa5;'>⚠️ A operação de teste não apareceu como concluída — verifique fuso horário do servidor vs banco.</p>";
	}
});

echo "<p style='font-family:sans-serif;color:#888;margin-top:30px;'>Diagnóstico finalizado em " . date('Y-m-d H:i:s') . "</p>";
