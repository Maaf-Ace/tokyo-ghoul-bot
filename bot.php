<?php

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use React\EventLoop\Loop;

include __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/bootstrap.php';

$discord = new Discord([
    'loop'           => $loop = Loop::get(),
    'socket_options' => ['dns' => '8.8.8.8'],
    'token'          => Env::getOrFail('DISCORD_TOKEN'),
    'loadAllMembers' => true,
    'storeMessages'  => true,
    'intents'        => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT | Intents::GUILD_MEMBERS,
]);

// ─── Constantes de custo (¥) ──────────────────────────────────────────────────
const CUSTO_INVESTIGAR = 500;
const CUSTO_PATRULHA   = 300;
const CUSTO_PESQUISA   = 1000;
const CUSTO_CAMPANHA   = 400;
const CUSTO_INFORME    = 750;

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Retorna uma barra de progresso ASCII de 10 chars para um valor 0-100. */
function barra(int $valor, int $max = 100): string {
    $preenchido = min(10, (int) ($valor / ($max / 10)));
    return str_repeat('█', $preenchido) . str_repeat('░', 10 - $preenchido);
}

/** Retorna o emoji de nível de alerta. */
function iconeAlerta(int $nivel): string {
    return match (true) {
        $nivel >= 4 => '🔴',
        $nivel >= 3 => '🟠',
        $nivel >= 2 => '🟡',
        default     => '🟢',
    };
}

$discord->on('init', function (Discord $discord) {

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {

        $content  = $message->content;
        $authorId = $message->author->id;

        // ── Comandos legados ───────────────────────────────────────────────────

        if (strtolower($content) === 'cardoso') {
            $message->reply('https://media.discordapp.net/attachments/1052301266929320048/1471640978833018880/ezgif.com-animated-gif-maker.gif?ex=6a3914b7&is=6a37c337&hm=ba70d103c850382b3da2ca2c943b72aa96bfe8a31a25d9c519a146cd78cb448c&='); //https://media.discordapp.net/attachments/748704973369638962/1038561914697035826/20221105_152654.jpg?ex=67989fe8&is=67974e68&hm=79f2e8247dbe3fdfe1b4f4f7d19f6350e0269e11c3ddd31496048907f28a6800&=&format=webp&width=503&height=671
        }

        if (strtolower($content) === 'bolo') {
            $message->reply(':cake:');
        }

        // Rolagem de dados: 2d6+3, 1d20, d8-1, etc.
        if (preg_match('/^(\d*)d(\d+)([\+\-](\d+))?$/i', $content, $m)) {
            $qtd      = !empty($m[1]) ? (int) $m[1] : 1;
            $faces    = (int) $m[2];
            $operador = $m[3][0] ?? '+';
            $extra    = isset($m[4]) ? (int) $m[4] : 0;
            $reply    = '';
            for ($i = 0; $i < $qtd; $i++) {
                $dado  = rand(1, $faces);
                $total = $operador === '+' ? $dado + $extra : $dado - $extra;
                $disp  = ($dado === $faces || $dado === 1) ? "**[$dado]**" : "[$dado]";
                $fmt   = "1d{$faces}" . ($extra ? "{$operador}{$extra}" : '');
                $reply .= "`` {$total} ``  ⟵ {$disp} {$fmt}\n";
            }
            $message->reply($reply);
        }

        if (in_array(strtolower($content), ['miguel', 'tsuki', 'misay'])) {
            $message->reply('https://media.discordapp.net/attachments/1214559782380372079/1341597661694005358/Imagem_do_WhatsApp_de_2025-02-17_as_16.41.30_3db34d80.jpg?ex=67b693b0&is=67b54230&hm=6b88eab721d9c832683a70cf93f12a9ab6e349d8c8493dcdbe76d5a108ffe6c7&=&format=webp');
        }

        // ══════════════════════════════════════════════════════════════════════
        // SISTEMA DE TÓQUIO — COMANDOS CCG
        // ══════════════════════════════════════════════════════════════════════

        // ── !orcamento — Exibe o saldo atual da CCG ───────────────────────────
        if (in_array(strtolower($content), ['!orcamento', '!budget', '!verba'])) {
            $sf = new SistemaFinanceiro();
            $message->reply($sf->formatarRelatorio());
            return;
        }

        // ── !mapa — Status de todos os distritos ──────────────────────────────
        if (in_array(strtolower($content), ['!mapa', '!status_tokyo', '!distritos'])) {
            $distritos   = (new DistritoRepository())->listarTodos();
            $faccaoRepo  = new FaccaoRepository();
            $eventoRepo  = new EventoMapaRepository();

            if (empty($distritos)) {
                $message->reply('❌ Nenhum distrito cadastrado.');
                return;
            }

            $linhas = ["🗺️ **MAPA TÁTICO DE TÓQUIO**\n"];
            foreach ($distritos as $d) {
                $faccao  = $d->faccaoDominanteId ? $faccaoRepo->buscarPorId($d->faccaoDominanteId) : null;
                $control = $faccao ? "[{$faccao->nome}]" : '[Neutro]';
                $eventos = $eventoRepo->listarAtivosPorDistrito($d->id);
                $evStr   = '';
                foreach ($eventos as $e) {
                    $evStr .= "\n   ⚡ *{$e->titulo}*";
                }
                $domBar = barra($d->nivelDominacao);
                $linhas[] = iconeAlerta($d->nivelAlerta) . " **{$d->nome}** {$control}\n"
                          . "   Dom: `{$domBar}` {$d->nivelDominacao}% | Alerta: {$d->nivelAlerta}/5 | Apoio Civil: {$d->apoioCivil}%"
                          . ($d->bonusDominio ? "\n   📌 _{$d->bonusDominio}_" : '')
                          . $evStr;
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !faccoes — Status de todas as facções ─────────────────────────────
        if (in_array(strtolower($content), ['!faccoes', '!frentes', '!facções'])) {
            $faccoes = (new FaccaoRepository())->listarTodas();
            $sf      = new SistemaFinanceiro();

            if (empty($faccoes)) {
                $message->reply('❌ Nenhuma facção cadastrada.');
                return;
            }

            $linhas = ["⚔️ **STATUS DAS FACÇÕES**\n"];
            foreach ($faccoes as $f) {
                $emoji = $f->id === 'ccg' ? '🛡️' : '🏴';
                $linha = "{$emoji} **{$f->nome}**\n"
                       . "   Poder Mil: `" . barra($f->poderMilitar) . "` {$f->poderMilitar}\n"
                       . "   Suprimentos: `" . barra($f->suprimentos) . "` {$f->suprimentos} | "
                       . "Sigilo: `" . barra($f->sigilo) . "` {$f->sigilo}\n"
                       . "   Agressividade: `" . barra($f->agressividade) . "` {$f->agressividade} | "
                       . "Fome: `" . barra($f->fome) . "` {$f->fome}";

                if ($f->id === 'ccg') {
                    $linha .= "\n   💰 Orçamento: **¥" . number_format($sf->getOrcamento(), 0, ',', '.') . "**";
                }
                $linhas[] = $linha;
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !quinques — Arsenal de quinques da CCG ────────────────────────────
        if (strtolower($content) === '!quinques') {
            $quinques = (new QuinqueRepository())->listarDaFaccao('ccg');

            if (empty($quinques)) {
                $message->reply("⚔️ **Arsenal de Quinques — CCG**\nNenhum quinque disponível ainda. Use `!pesquisar` para criar um.");
                return;
            }

            $linhas = ["⚔️ **Arsenal de Quinques — CCG Esquadrão Zero**\n"];
            foreach ($quinques as $i => $q) {
                $n = $i + 1;
                $linhas[] = "**{$n}. {$q->nome}** (+{$q->bonusCombate} Combate)\n"
                          . "   🧬 Tipo: **" . strtoupper($q->tipoRc) . "** | _{$q->descricao}_\n"
                          . "   Origem: {$q->ghoulOrigem} | " . date('d/m/Y', strtotime($q->dataCriacao));
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !eventos — Eventos de mapa ativos ─────────────────────────────────
        if (strtolower($content) === '!eventos') {
            $eventos      = (new EventoMapaRepository())->listarAtivos();
            $distritoRepo = new DistritoRepository();

            if (empty($eventos)) {
                $message->reply("🗓️ **Eventos de Mapa**\nNenhum evento ativo no momento. Aguarde o próximo ciclo semanal.");
                return;
            }

            $linhas = ["🗓️ **Eventos de Mapa Ativos**\n"];
            foreach ($eventos as $e) {
                $d       = $distritoRepo->buscarPorId($e->distritoId);
                $nome    = $d ? $d->nome : "Distrito #{$e->distritoId}";
                $expira  = date('d/m H:i', strtotime($e->dataFim));
                $linhas[] = "📍 **{$nome}** — **{$e->titulo}**\n"
                          . "   _{$e->descricao}_\n"
                          . "   Expira: {$expira}";
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !investigar <id_distrito> ──────────────────────────────────────────
        if (preg_match('/^!investigar\s+(\d+)$/i', $content, $m)) {
            $distritoId = (int) $m[1];
            $sf         = new SistemaFinanceiro();
            $opRepo     = new OperacaoRepository();
            $distrito   = (new DistritoRepository())->buscarPorId($distritoId);

            if (!$distrito) {
                $message->reply("❌ Distrito #{$distritoId} não encontrado. Use `!mapa` para ver os disponíveis.");
                return;
            }

            if ($opRepo->temPendente('investigacao', 'ccg', $distritoId)) {
                $message->reply("⏳ Já existe uma investigação em andamento no **{$distrito->nome}**. Aguarde o resultado.");
                return;
            }

            if (!$sf->gastar(CUSTO_INVESTIGAR)) {
                $message->reply("❌ **Saldo insuficiente.** Custo: ¥" . number_format(CUSTO_INVESTIGAR, 0, ',', '.') . " | Saldo: ¥" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $op = new Operacao('investigacao', 'ccg', $distritoId, 2, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $message->reply(
                "📂 **Operação de Inteligência Iniciada**\n"
              . "📍 Alvo: **{$distrito->nome}**\n"
              . "⏱️ Relatório esperado em ~2h.\n"
              . "💰 ¥" . number_format(CUSTO_INVESTIGAR, 0, ',', '.') . " debitados do orçamento."
            );
            return;
        }

        // ── !patrulha <id_distrito> ───────────────────────────────────────────
        if (preg_match('/^!patrulha\s+(\d+)$/i', $content, $m)) {
            $distritoId = (int) $m[1];
            $sf         = new SistemaFinanceiro();
            $opRepo     = new OperacaoRepository();
            $distrito   = (new DistritoRepository())->buscarPorId($distritoId);

            if (!$distrito) {
                $message->reply("❌ Distrito #{$distritoId} não encontrado. Use `!mapa` para ver os disponíveis.");
                return;
            }

            if ($opRepo->temPendente('patrulha', 'ccg', $distritoId)) {
                $message->reply("⏳ Já existe uma patrulha ativa no **{$distrito->nome}**. Aguarde o retorno.");
                return;
            }

            if (!$sf->gastar(CUSTO_PATRULHA)) {
                $message->reply("❌ **Saldo insuficiente.** Custo: ¥" . number_format(CUSTO_PATRULHA, 0, ',', '.') . " | Saldo: ¥" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $op = new Operacao('patrulha', 'ccg', $distritoId, 4, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $message->reply(
                "🛡️ **Patrulha Pacífica Autorizada**\n"
              . "📍 Área: **{$distrito->nome}**\n"
              . "⏱️ Resultado esperado em ~4h.\n"
              . "💰 ¥" . number_format(CUSTO_PATRULHA, 0, ',', '.') . " debitados do orçamento."
            );
            return;
        }

        // ── !campanha <id_distrito> — Campanha de mídia (Rel. Públicas) ───────
        if (preg_match('/^!campanha\s+(\d+)$/i', $content, $m)) {
            $distritoId = (int) $m[1];
            $sf         = new SistemaFinanceiro();
            $opRepo     = new OperacaoRepository();
            $distrito   = (new DistritoRepository())->buscarPorId($distritoId);

            if (!$distrito) {
                $message->reply("❌ Distrito #{$distritoId} não encontrado. Use `!mapa` para ver os disponíveis.");
                return;
            }

            if ($opRepo->temPendente('campanha', 'ccg', $distritoId)) {
                $message->reply("⏳ Já existe uma campanha em andamento no **{$distrito->nome}**.");
                return;
            }

            if (!$sf->gastar(CUSTO_CAMPANHA)) {
                $message->reply("❌ **Saldo insuficiente.** Custo: ¥" . number_format(CUSTO_CAMPANHA, 0, ',', '.') . " | Saldo: ¥" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $op = new Operacao('campanha', 'ccg', $distritoId, 6, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $message->reply(
                "📢 **Campanha de Mídia Aprovada**\n"
              . "📍 Área: **{$distrito->nome}**\n"
              . "⏱️ Agentes serão despachados. Resultado em ~6h.\n"
              . "💰 ¥" . number_format(CUSTO_CAMPANHA, 0, ',', '.') . " debitados do orçamento."
            );
            return;
        }

        // ── !pesquisar — P&D: cria um Quinque a partir de combates passados ───
        if (strtolower($content) === '!pesquisar') {
            $sf     = new SistemaFinanceiro();
            $opRepo = new OperacaoRepository();

            if ($opRepo->temPendente('pesquisa', 'ccg')) {
                $message->reply("⏳ O laboratório já está processando uma amostra. Aguarde a conclusão.");
                return;
            }

            $historico = (new HistoricoCombateRepository())->buscarHistoricoDaFaccao('ccg', 10);
            $temVitoria = false;
            foreach ($historico as $c) {
                if ($c['vencedor_id'] === 'ccg') { $temVitoria = true; break; }
            }

            if (!$temVitoria) {
                $message->reply("🔬 **P&D — Impossível Iniciar**\nO laboratório não possui espécimes. A CCG precisa vencer ao menos um confronto para coletar kagune.");
                return;
            }

            if (!$sf->gastar(CUSTO_PESQUISA)) {
                $message->reply("❌ **Saldo insuficiente.** Custo: ¥" . number_format(CUSTO_PESQUISA, 0, ',', '.') . " | Saldo: ¥" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $op = new Operacao('pesquisa', 'ccg', 0, 6, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $message->reply(
                "🔬 **Pesquisa de Quinque Iniciada**\n"
              . "O laboratório forense começou a dissecar o kagune coletado.\n"
              . "⏱️ Quinque esperado em ~6h.\n"
              . "💰 ¥" . number_format(CUSTO_PESQUISA, 0, ',', '.') . " debitados do orçamento."
            );
            return;
        }

        // ── !informe — Rede de informantes (DM ao jogador) ───────────────────
        if (strtolower($content) === '!informe') {
            $sf       = new SistemaFinanceiro();
            $faccoes  = (new FaccaoRepository())->listarFaccoesGhoul();
            $distritos = (new DistritoRepository())->listarTodos();

            if (!$sf->gastar(CUSTO_INFORME)) {
                $message->reply("❌ **Saldo insuficiente.** Custo: ¥" . number_format(CUSTO_INFORME, 0, ',', '.') . " | Saldo: ¥" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $eArmadilha = rand(1, 100) <= 30;

            if (!empty($faccoes) && !empty($distritos)) {
                $faccao  = $faccoes[array_rand($faccoes)];
                $distrito = $distritos[array_rand($distritos)];

                if ($eArmadilha) {
                    $intel = "🚨 **ATENÇÃO — INFORMAÇÃO SUSPEITA**\n\n"
                           . "Contato relata que **{$faccao->nome}** está vulnerável no **{$distrito->nome}**. "
                           . "Liderança reunida em um único ponto. _Porém... algo não bate._ "
                           . "Esta informação pode ser uma isca. Proceda com extrema cautela.";
                } else {
                    $tiposIntel = [
                        "Agentes da **{$faccao->nome}** foram vistos movendo suprimentos pelo **{$distrito->nome}** nas últimas 48h. Rota confirmada por dois informantes independentes.",
                        "Liderança da **{$faccao->nome}** realizará uma reunião no **{$distrito->nome}** esta semana. Janela de vulnerabilidade identificada.",
                        "**{$faccao->nome}** está com suprimentos críticos. Uma operação no **{$distrito->nome}** poderia cortar a logística deles agora.",
                        "Fonte interna confirma: o moral da **{$faccao->nome}** está baixo após derrotas recentes. Momento ideal para pressionar no **{$distrito->nome}**.",
                        "Recrutamento ativo da **{$faccao->nome}** detectado no **{$distrito->nome}**. Novos membros = menor sigilo. Boa janela de infiltração.",
                    ];
                    $intel = $tiposIntel[array_rand($tiposIntel)];
                }

                $msgDm = "🕵️ **[REDE DE INFORMANTES — CONFIDENCIAL]**\n\n"
                       . $intel . "\n\n"
                       . "_Esta informação expira em 24h. ¥" . number_format(CUSTO_INFORME, 0, ',', '.') . " debitados do orçamento._";

                $message->author->getPrivateChannel()->then(function ($dmChannel) use ($msgDm) {
                    $dmChannel->sendMessage($msgDm);
                });
            }

            $message->reply("📨 **Informação recebida do contato.** Verifique suas mensagens diretas.");
            return;
        }

        // ── !operacoes — Lista as operações pendentes da CCG ──────────────────
        if (strtolower($content) === '!operacoes') {
            $pendentes    = (new OperacaoRepository())->listarPendentes();
            $distritoRepo = new DistritoRepository();

            if (empty($pendentes)) {
                $message->reply("📋 **Operações Ativas**\nNenhuma operação em andamento no momento.");
                return;
            }

            $linhas = ["📋 **Operações em Andamento — CCG**\n"];
            foreach ($pendentes as $op) {
                $d      = $op->distritoAlvo > 0 ? $distritoRepo->buscarPorId($op->distritoAlvo) : null;
                $local  = $d ? $d->nome : '(sem distrito)';
                $fim    = date('d/m H:i', strtotime($op->dataFim));
                $agente = $op->solicitanteDiscordId ? "<@{$op->solicitanteDiscordId}>" : 'Sistema';
                $linhas[] = "🔹 **{$op->tipo}** em {$local}\n"
                          . "   Agente: {$agente} | Conclusão: {$fim}";
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !despacho <texto> — Admin: posta no Clarim de Tóquio ─────────────
        if (preg_match('/^!despacho\s+(.+)$/si', $content, $m) && $authorId === '242459562655875073') {
            $webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
            if ($webhookNoticias) {
                $clarim = new ClarimToquio($webhookNoticias);
                $clarim->publicarManual("📰 **[CLARIM DE TÓQUIO]** | {$m[1]}");
                $message->reply("✅ Despacho publicado no Clarim de Tóquio.");
            } else {
                $message->reply("❌ Webhook de notícias não configurado. Adicione `DISCORD_WEBHOOK_NOTICIAS` ao .env");
            }
            return;
        }

        // ── !dano <valor> — Admin: registra dano colateral ───────────────────
        if (preg_match('/^!dano\s+(\d+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $valor = (int) $m[1];
            (new SistemaFinanceiro())->registrarDanoColateral($valor);
            $message->reply("🔧 **Dano colateral registrado:** ¥" . number_format($valor, 0, ',', '.') . " serão descontados na próxima semana.");
            return;
        }

        // ── !ajuda_tokyo / !comandos ──────────────────────────────────────────
        if (in_array(strtolower($content), ['!ajuda_tokyo', '!comandos', '!help_tokyo'])) {
            $message->reply(
                "📖 **Comandos do Bot de Tóquio — CCG Esquadrão Zero**\n\n"
              . "**🗺️ Informação**\n"
              . "`!mapa` — Status de todos os distritos\n"
              . "`!faccoes` — Status de todas as facções\n"
              . "`!quinques` — Arsenal de Quinques da CCG\n"
              . "`!eventos` — Eventos de mapa ativos\n"
              . "`!operacoes` — Operações em andamento\n"
              . "`!orcamento` — Orçamento da CCG\n\n"
              . "**⚔️ Operações** _(custam ¥ do orçamento)_\n"
              . "`!investigar <id>` — Investiga um distrito (¥" . number_format(CUSTO_INVESTIGAR, 0, ',', '.') . ", 2h)\n"
              . "`!patrulha <id>` — Patrulha pacífica (¥" . number_format(CUSTO_PATRULHA, 0, ',', '.') . ", 4h)\n"
              . "`!campanha <id>` — Campanha de mídia (¥" . number_format(CUSTO_CAMPANHA, 0, ',', '.') . ", 6h)\n"
              . "`!pesquisar` — Pesquisa de Quinque no laboratório (¥" . number_format(CUSTO_PESQUISA, 0, ',', '.') . ", 6h)\n"
              . "`!informe` — Compra intel da rede de informantes (¥" . number_format(CUSTO_INFORME, 0, ',', '.') . ", via DM)\n\n"
              . "**🎲 Utilitários**\n"
              . "`1d20`, `2d6+3`, etc. — Rolagem de dados\n"
            );
            return;
        }

    });

});

$discord->run();
