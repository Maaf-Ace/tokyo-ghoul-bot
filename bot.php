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

// ─── Custos (¥) ───────────────────────────────────────────────────────────────
const CUSTO_INVESTIGAR       = 500;
const CUSTO_PATRULHA         = 300;
const CUSTO_PESQUISA         = 1000;
const CUSTO_CAMPANHA         = 1500;
const CUSTO_INFORME          = 750;
const CUSTO_INFORMANTE_FIXO  = 2000;

// ─── Limites semanais ─────────────────────────────────────────────────────────
const LIMITE_INVESTIGACOES = 3;
const LIMITE_PATRULHAS     = 2;
const LIMITE_CAMPANHAS     = 1;
const LIMITE_PESQUISAS     = 1;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function barra(int $valor, int $max = 100): string {
    $preenchido = min(10, (int) ($valor / ($max / 10)));
    return str_repeat('█', $preenchido) . str_repeat('░', 10 - $preenchido);
}

function nivelAlertaTexto(int $nivel): string {
    return match (true) {
        $nivel >= 4 => '[CRITICO]',
        $nivel >= 3 => '[ALTO]',
        $nivel >= 2 => '[MEDIO]',
        default     => '[BAIXO]',
    };
}

/**
 * Verifica se a CCG pode operar no distrito-alvo (deve ser o atual ou adjacente).
 * Retorna ['ok'=>true] ou ['ok'=>false, 'mensagem'=>string].
 */
function verificarAlcanceCCG(int $distritoAlvo, FaccaoRepository $faccaoRepo, AdjacenciaRepository $adjRepo): array {
    $ccg = $faccaoRepo->buscarPorId('ccg');
    if (!$ccg) return ['ok' => false, 'mensagem' => 'Faccao CCG nao encontrada.'];

    if (!$adjRepo->saoAdjacentes($ccg->posicaoAtual, $distritoAlvo)) {
        $adjacentes = implode(', ', $adjRepo->getAdjacentes($ccg->posicaoAtual));
        return [
            'ok'       => false,
            'mensagem' => "A CCG esta no distrito #{$ccg->posicaoAtual} e so pode operar em distritos adjacentes: {$adjacentes}.\nUse `!mover <id>` para deslocar o boneeco antes.",
        ];
    }
    return ['ok' => true];
}

/**
 * Verifica se a CCG tem slots de operações disponíveis.
 * Com agentes feridos: max 1 operação simultânea.
 */
function verificarSlotOperacao(FaccaoRepository $faccaoRepo, OperacaoRepository $opRepo): array {
    $ccg     = $faccaoRepo->buscarPorId('ccg');
    $pendentes = count($opRepo->listarPendentes());

    if ($ccg && $ccg->agentesFeridados()) {
        if ($pendentes >= 1) {
            return ['ok' => false, 'mensagem' => 'Agentes feridos: a CCG esta limitada a 1 operacao simultanea ate a recuperacao.'];
        }
    } elseif ($pendentes >= 2) {
        return ['ok' => false, 'mensagem' => 'A CCG ja tem 2 operacoes em andamento. Aguarde a conclusao de uma delas.'];
    }
    return ['ok' => true];
}

// ─── Discord ──────────────────────────────────────────────────────────────────

$discord->on('init', function (Discord $discord) {

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {

        $content  = $message->content;
        $authorId = $message->author->id;

        // ── Comandos legados ───────────────────────────────────────────────────

        if (strtolower($content) === 'cardoso') {
            $message->reply('https://media.discordapp.net/attachments/1052301266929320048/1471640978833018880/ezgif.com-animated-gif-maker.gif?ex=6a3914b7&is=6a37c337&hm=ba70d103c850382b3da2ca2c943b72aa96bfe8a31a25d9c519a146cd78cb448c&=');
        }

        if (strtolower($content) === 'bolo') {
            $message->reply(':cake:');
        }

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
                $reply .= "`` {$total} ``  <-- {$disp} {$fmt}\n";
            }
            $message->reply($reply);
        }

        if (in_array(strtolower($content), ['miguel', 'tsuki', 'misay'])) {
            $message->reply('https://media.discordapp.net/attachments/1214559782380372079/1341597661694005358/Imagem_do_WhatsApp_de_2025-02-17_as_16.41.30_3db34d80.jpg?ex=67b693b0&is=67b54230&hm=6b88eab721d9c832683a70cf93f12a9ab6e349d8c8493dcdbe76d5a108fbe6c7&=&format=webp');
        }

        // ══════════════════════════════════════════════════════════════════════
        // SISTEMA DE TOQUIO — CCG
        // ══════════════════════════════════════════════════════════════════════

        // ── !orcamento ─────────────────────────────────────────────────────────
        if (in_array(strtolower($content), ['!orcamento', '!budget', '!verba'])) {
            $sf       = new SistemaFinanceiro();
            $semRepo  = new AcoesSemanaRepository();
            $resumo   = $semRepo->resumoCCG();

            $inv  = $resumo['investigacao'] ?? 0;
            $pat  = $resumo['patrulha']     ?? 0;
            $cam  = $resumo['campanha']     ?? 0;
            $pes  = $resumo['pesquisa']     ?? 0;

            $relatorio = $sf->formatarRelatorio();
            $relatorio .= "\n\n**Acoes desta semana:**\n"
                        . "Investigacoes: {$inv}/" . LIMITE_INVESTIGACOES . "\n"
                        . "Patrulhas:     {$pat}/" . LIMITE_PATRULHAS . "\n"
                        . "Campanhas:     {$cam}/" . LIMITE_CAMPANHAS . "\n"
                        . "Pesquisas:     {$pes}/" . LIMITE_PESQUISAS;

            $message->reply($relatorio);
            return;
        }

        // ── !mapa ──────────────────────────────────────────────────────────────
        if (in_array(strtolower($content), ['!mapa', '!status_tokyo', '!distritos'])) {
            $distritos   = (new DistritoRepository())->listarTodos();
            $faccaoRepo  = new FaccaoRepository();
            $eventoRepo  = new EventoMapaRepository();
            $ccg         = $faccaoRepo->buscarPorId('ccg');
            $posicaoCCG  = $ccg ? $ccg->posicaoAtual : 0;

            if (empty($distritos)) {
                $message->reply('Nenhum distrito cadastrado.');
                return;
            }

            $linhas = ["**MAPA TATICO DE TOQUIO** | CCG em #{$posicaoCCG}\n"];
            foreach ($distritos as $d) {
                $faccao    = $d->faccaoDominanteId ? $faccaoRepo->buscarPorId($d->faccaoDominanteId) : null;
                $control   = $faccao ? "[{$faccao->nome}]" : '[Neutro]';
                $eventos   = $eventoRepo->listarAtivosPorDistrito($d->id);
                $evStr     = '';
                foreach ($eventos as $e) {
                    $evStr .= "\n   * {$e->titulo}";
                }
                $marcador  = ($d->id === $posicaoCCG) ? ' <-- CCG' : '';
                $domBar    = barra($d->nivelDominacao);
                $linhas[]  = nivelAlertaTexto($d->nivelAlerta) . " **#{$d->id} {$d->nome}**{$marcador} {$control}\n"
                           . "   Dom: `{$domBar}` {$d->nivelDominacao}% | Alerta: {$d->nivelAlerta}/5 | Civil: {$d->apoioCivil}%"
                           . ($d->bonusDominio ? "\n   + {$d->bonusDominio}" : '')
                           . $evStr;
            }

            // Envia em partes para não ultrapassar o limite de 2000 caracteres do Discord
            $parte   = '';
            $primeiro = true;
            foreach ($linhas as $linha) {
                $candidato = $parte . ($parte ? "\n\n" : '') . $linha;
                if (strlen($candidato) > 1900) {
                    if ($primeiro) {
                        $message->reply($parte);
                        $primeiro = false;
                    } else {
                        $message->channel->sendMessage($parte);
                    }
                    $parte = $linha;
                } else {
                    $parte = $candidato;
                }
            }
            if ($parte !== '') {
                if ($primeiro) {
                    $message->reply($parte);
                } else {
                    $message->channel->sendMessage($parte);
                }
            }
            return;
        }

        // ── !posicao — Onde a CCG esta agora ──────────────────────────────────
        if (in_array(strtolower($content), ['!posicao', '!posição', '!onde'])) {
            $faccaoRepo = new FaccaoRepository();
            $adjRepo    = new AdjacenciaRepository();
            $ccg        = $faccaoRepo->buscarPorId('ccg');
            if (!$ccg) { $message->reply('CCG nao encontrada.'); return; }

            $d          = (new DistritoRepository())->buscarPorId($ccg->posicaoAtual);
            $nome       = $d ? $d->nome : "Distrito #{$ccg->posicaoAtual}";
            $adjIds     = $adjRepo->getAdjacentes($ccg->posicaoAtual);
            $adjNomes   = [];
            $dRepo      = new DistritoRepository();
            foreach ($adjIds as $aid) {
                $ad = $dRepo->buscarPorId($aid);
                $adjNomes[] = "#{$aid} " . ($ad ? $ad->nome : '?');
            }
            $ferido = $ccg->agentesFeridados() ? "\nAgentes feridos ate: {$ccg->agentesFeridosAte} (max 1 op simultanea)" : '';

            $message->reply(
                "**Posicao CCG**\n"
              . "Atual: #{$ccg->posicaoAtual} {$nome}\n"
              . "Base:  #{$ccg->distritoBase}\n"
              . "Adjacentes (alcance de operacao): " . implode(', ', $adjNomes)
              . $ferido
            );
            return;
        }

        // ── !mover <id> — Desloca o boneco CCG ────────────────────────────────
        if (preg_match('/^!mover\s+(\d+)$/i', $content, $m)) {
            $destino = (int) $m[1];
            $sm      = new SistemaMovimento();
            $result  = $sm->moverCCG($destino);
            $message->reply($result['mensagem']);
            return;
        }

        // ── !faccoes ───────────────────────────────────────────────────────────
        if (in_array(strtolower($content), ['!faccoes', '!frentes', '!faccções'])) {
            $faccoes    = (new FaccaoRepository())->listarTodas();
            $sf         = new SistemaFinanceiro();
            $dRepo      = new DistritoRepository();

            if (empty($faccoes)) {
                $message->reply('Nenhuma faccao cadastrada.');
                return;
            }

            $linhas = ["**STATUS DAS FACCOES**\n"];
            foreach ($faccoes as $f) {
                $tag  = $f->id === 'ccg' ? '[CCG]' : '[GHOUL]';
                $pos  = $dRepo->buscarPorId($f->posicaoAtual);
                $posNome = $pos ? "#{$f->posicaoAtual} {$pos->nome}" : "#{$f->posicaoAtual}";
                $linha = "{$tag} **{$f->nome}**\n"
                       . "   Poder Mil: `" . barra($f->poderMilitar) . "` {$f->poderMilitar}\n"
                       . "   Suprimentos: `" . barra($f->suprimentos) . "` {$f->suprimentos} | "
                       . "Sigilo: `" . barra($f->sigilo) . "` {$f->sigilo}\n"
                       . "   Agressividade: `" . barra($f->agressividade) . "` {$f->agressividade} | "
                       . "Fome: `" . barra($f->fome) . "` {$f->fome}\n"
                       . "   Posicao atual: {$posNome}";

                if ($f->id === 'ccg') {
                    $linha .= "\n   Orcamento: **Y" . number_format($sf->getOrcamento(), 0, ',', '.') . "**";
                    if ($f->agentesFeridados()) {
                        $linha .= "\n   [!] Agentes feridos ate {$f->agentesFeridosAte}";
                    }
                }
                $linhas[] = $linha;
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !quinques ──────────────────────────────────────────────────────────
        if (strtolower($content) === '!quinques') {
            $quinques = (new QuinqueRepository())->listarDaFaccao('ccg');

            if (empty($quinques)) {
                $message->reply("**Arsenal de Quinques — CCG**\nNenhum quinque disponivel ainda. Use `!pesquisar` para criar um.");
                return;
            }

            $linhas = ["**Arsenal de Quinques — CCG Esquadrao Zero**\n"];
            foreach ($quinques as $i => $q) {
                $n = $i + 1;
                $linhas[] = "**{$n}. {$q->nome}** (+{$q->bonusCombate} Combate)\n"
                          . "   Tipo: **" . strtoupper($q->tipoRc) . "** | {$q->descricao}\n"
                          . "   Origem: {$q->ghoulOrigem} | " . date('d/m/Y', strtotime($q->dataCriacao));
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !eventos ───────────────────────────────────────────────────────────
        if (strtolower($content) === '!eventos') {
            $eventos      = (new EventoMapaRepository())->listarAtivos();
            $distritoRepo = new DistritoRepository();

            if (empty($eventos)) {
                $message->reply("**Eventos de Mapa**\nNenhum evento ativo no momento.");
                return;
            }

            $linhas = ["**Eventos de Mapa Ativos**\n"];
            foreach ($eventos as $e) {
                $d      = $distritoRepo->buscarPorId($e->distritoId);
                $nome   = $d ? $d->nome : "Distrito #{$e->distritoId}";
                $expira = date('d/m H:i', strtotime($e->dataFim));
                $linhas[] = "#{$e->distritoId} **{$nome}** — **{$e->titulo}**\n"
                          . "   {$e->descricao}\n"
                          . "   Expira: {$expira}";
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !operacoes ─────────────────────────────────────────────────────────
        if (strtolower($content) === '!operacoes') {
            $pendentes    = (new OperacaoRepository())->listarPendentes();
            $distritoRepo = new DistritoRepository();

            if (empty($pendentes)) {
                $message->reply("**Operacoes Ativas**\nNenhuma operacao em andamento.");
                return;
            }

            $linhas = ["**Operacoes em Andamento — CCG**\n"];
            foreach ($pendentes as $op) {
                $d      = $op->distritoAlvo > 0 ? $distritoRepo->buscarPorId($op->distritoAlvo) : null;
                $local  = $d ? $d->nome : '(sem distrito)';
                $fim    = date('d/m H:i', strtotime($op->dataFim));
                $agente = $op->solicitanteDiscordId ? "<@{$op->solicitanteDiscordId}>" : 'Sistema';
                $linhas[] = "** {$op->tipo}** em {$local}\n"
                          . "   Agente: {$agente} | Conclusao: {$fim}";
            }

            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !investigar <id> ───────────────────────────────────────────────────
        if (preg_match('/^!investigar\s+(\d+)$/i', $content, $m)) {
            $distritoId = (int) $m[1];
            $faccaoRepo = new FaccaoRepository();
            $opRepo     = new OperacaoRepository();
            $adjRepo    = new AdjacenciaRepository();
            $semRepo    = new AcoesSemanaRepository();
            $sf         = new SistemaFinanceiro();
            $distrito   = (new DistritoRepository())->buscarPorId($distritoId);

            if (!$distrito) {
                $message->reply("Distrito #{$distritoId} nao encontrado. Use `!mapa` para ver os disponiveis.");
                return;
            }

            $alcance = verificarAlcanceCCG($distritoId, $faccaoRepo, $adjRepo);
            if (!$alcance['ok']) {
                $message->reply($alcance['mensagem']);
                return;
            }

            if (!$semRepo->verificarLimite('ccg', 'investigacao', LIMITE_INVESTIGACOES)) {
                $message->reply("Limite semanal de investigacoes atingido (" . LIMITE_INVESTIGACOES . "/semana). Reset na proxima segunda-feira.");
                return;
            }

            $slot = verificarSlotOperacao($faccaoRepo, $opRepo);
            if (!$slot['ok']) {
                $message->reply($slot['mensagem']);
                return;
            }

            if ($opRepo->temPendente('investigacao', 'ccg', $distritoId)) {
                $message->reply("Ja existe uma investigacao em andamento no **{$distrito->nome}**.");
                return;
            }

            if (!$sf->gastar(CUSTO_INVESTIGAR)) {
                $message->reply("Saldo insuficiente. Custo: Y" . number_format(CUSTO_INVESTIGAR, 0, ',', '.') . " | Saldo: Y" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $semRepo->incrementar('ccg', 'investigacao');
            $op = new Operacao('investigacao', 'ccg', $distritoId, 2, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $usadas = $semRepo->getQuantidade('ccg', 'investigacao');
            $message->reply(
                "**Operacao de Inteligencia Iniciada**\n"
              . "Alvo: **{$distrito->nome}** (#{$distritoId})\n"
              . "Relatorio esperado em ~2h.\n"
              . "Y" . number_format(CUSTO_INVESTIGAR, 0, ',', '.') . " debitados. ({$usadas}/" . LIMITE_INVESTIGACOES . " investigacoes desta semana)"
            );
            return;
        }

        // ── !patrulha <id> ─────────────────────────────────────────────────────
        if (preg_match('/^!patrulha\s+(\d+)$/i', $content, $m)) {
            $distritoId = (int) $m[1];
            $faccaoRepo = new FaccaoRepository();
            $opRepo     = new OperacaoRepository();
            $adjRepo    = new AdjacenciaRepository();
            $semRepo    = new AcoesSemanaRepository();
            $sf         = new SistemaFinanceiro();
            $distrito   = (new DistritoRepository())->buscarPorId($distritoId);

            if (!$distrito) {
                $message->reply("Distrito #{$distritoId} nao encontrado.");
                return;
            }

            $alcance = verificarAlcanceCCG($distritoId, $faccaoRepo, $adjRepo);
            if (!$alcance['ok']) {
                $message->reply($alcance['mensagem']);
                return;
            }

            if (!$semRepo->verificarLimite('ccg', 'patrulha', LIMITE_PATRULHAS)) {
                $message->reply("Limite semanal de patrulhas atingido (" . LIMITE_PATRULHAS . "/semana).");
                return;
            }

            $slot = verificarSlotOperacao($faccaoRepo, $opRepo);
            if (!$slot['ok']) {
                $message->reply($slot['mensagem']);
                return;
            }

            if ($opRepo->temPendente('patrulha', 'ccg', $distritoId)) {
                $message->reply("Ja existe uma patrulha ativa no **{$distrito->nome}**.");
                return;
            }

            if (!$sf->gastar(CUSTO_PATRULHA)) {
                $message->reply("Saldo insuficiente. Custo: Y" . number_format(CUSTO_PATRULHA, 0, ',', '.') . " | Saldo: Y" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $semRepo->incrementar('ccg', 'patrulha');
            $op = new Operacao('patrulha', 'ccg', $distritoId, 4, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $usadas = $semRepo->getQuantidade('ccg', 'patrulha');
            $message->reply(
                "**Patrulha Autorizada**\n"
              . "Area: **{$distrito->nome}**\n"
              . "Resultado esperado em ~4h.\n"
              . "Y" . number_format(CUSTO_PATRULHA, 0, ',', '.') . " debitados. ({$usadas}/" . LIMITE_PATRULHAS . " patrulhas desta semana)"
            );
            return;
        }

        // ── !campanha <id> ─────────────────────────────────────────────────────
        if (preg_match('/^!campanha\s+(\d+)$/i', $content, $m)) {
            $distritoId = (int) $m[1];
            $faccaoRepo = new FaccaoRepository();
            $opRepo     = new OperacaoRepository();
            $adjRepo    = new AdjacenciaRepository();
            $semRepo    = new AcoesSemanaRepository();
            $sf         = new SistemaFinanceiro();
            $distrito   = (new DistritoRepository())->buscarPorId($distritoId);

            if (!$distrito) {
                $message->reply("Distrito #{$distritoId} nao encontrado.");
                return;
            }

            $alcance = verificarAlcanceCCG($distritoId, $faccaoRepo, $adjRepo);
            if (!$alcance['ok']) {
                $message->reply($alcance['mensagem']);
                return;
            }

            if (!$semRepo->verificarLimite('ccg', 'campanha', LIMITE_CAMPANHAS)) {
                $message->reply("Limite semanal de campanhas atingido (" . LIMITE_CAMPANHAS . "/semana).");
                return;
            }

            $slot = verificarSlotOperacao($faccaoRepo, $opRepo);
            if (!$slot['ok']) {
                $message->reply($slot['mensagem']);
                return;
            }

            if ($opRepo->temPendente('campanha', 'ccg', $distritoId)) {
                $message->reply("Ja existe uma campanha em andamento no **{$distrito->nome}**.");
                return;
            }

            if (!$sf->gastar(CUSTO_CAMPANHA)) {
                $message->reply("Saldo insuficiente. Custo: Y" . number_format(CUSTO_CAMPANHA, 0, ',', '.') . " | Saldo: Y" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $semRepo->incrementar('ccg', 'campanha');
            $op = new Operacao('campanha', 'ccg', $distritoId, 6, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $usadas = $semRepo->getQuantidade('ccg', 'campanha');
            $message->reply(
                "**Campanha de Midia Aprovada**\n"
              . "Area: **{$distrito->nome}**\n"
              . "Resultado em ~6h.\n"
              . "Y" . number_format(CUSTO_CAMPANHA, 0, ',', '.') . " debitados. ({$usadas}/" . LIMITE_CAMPANHAS . " campanhas desta semana)"
            );
            return;
        }

        // ── !pesquisar ─────────────────────────────────────────────────────────
        if (strtolower($content) === '!pesquisar') {
            $faccaoRepo = new FaccaoRepository();
            $opRepo     = new OperacaoRepository();
            $semRepo    = new AcoesSemanaRepository();
            $sf         = new SistemaFinanceiro();

            if (!$semRepo->verificarLimite('ccg', 'pesquisa', LIMITE_PESQUISAS)) {
                $message->reply("Limite semanal de pesquisas atingido (" . LIMITE_PESQUISAS . "/semana).");
                return;
            }

            $slot = verificarSlotOperacao($faccaoRepo, $opRepo);
            if (!$slot['ok']) {
                $message->reply($slot['mensagem']);
                return;
            }

            if ($opRepo->temPendente('pesquisa', 'ccg')) {
                $message->reply("O laboratorio ja esta processando uma amostra. Aguarde a conclusao.");
                return;
            }

            $historico   = (new HistoricoCombateRepository())->buscarHistoricoDaFaccao('ccg', 10);
            $temVitoria  = false;
            foreach ($historico as $c) {
                if ($c['vencedor_id'] === 'ccg') { $temVitoria = true; break; }
            }

            if (!$temVitoria) {
                $message->reply("**P&D — Impossivel Iniciar**\nO laboratorio nao possui especimes. A CCG precisa vencer ao menos um confronto para coletar kagune.");
                return;
            }

            if (!$sf->gastar(CUSTO_PESQUISA)) {
                $message->reply("Saldo insuficiente. Custo: Y" . number_format(CUSTO_PESQUISA, 0, ',', '.') . " | Saldo: Y" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $semRepo->incrementar('ccg', 'pesquisa');
            $op = new Operacao('pesquisa', 'ccg', 0, 6, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $message->reply(
                "**Pesquisa de Quinque Iniciada**\n"
              . "O laboratorio forense comecou a dissecar o kagune coletado.\n"
              . "Quinque esperado em ~6h.\n"
              . "Y" . number_format(CUSTO_PESQUISA, 0, ',', '.') . " debitados."
            );
            return;
        }

        // ── !informe — Rede de informantes (DM ao jogador) ────────────────────
        if (strtolower($content) === '!informe') {
            $sf        = new SistemaFinanceiro();
            $faccoes   = (new FaccaoRepository())->listarFaccoesGhoul();
            $distritos = (new DistritoRepository())->listarTodos();

            if (!$sf->gastar(CUSTO_INFORME)) {
                $message->reply("Saldo insuficiente. Custo: Y" . number_format(CUSTO_INFORME, 0, ',', '.') . " | Saldo: Y" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $eArmadilha = rand(1, 100) <= 30;

            if (!empty($faccoes) && !empty($distritos)) {
                $faccao   = $faccoes[array_rand($faccoes)];
                $distrito = $distritos[array_rand($distritos)];

                if ($eArmadilha) {
                    $intel = "[ATENCAO — INFORMACAO SUSPEITA]\n\n"
                           . "Contato relata que **{$faccao->nome}** esta vulneravel no **{$distrito->nome}**. "
                           . "Lideranca reunida em um unico ponto. Porem... algo nao bate. "
                           . "Esta informacao pode ser uma isca. Proceda com extrema cautela.";
                } else {
                    $tiposIntel = [
                        "Agentes da **{$faccao->nome}** foram vistos movendo suprimentos pelo **{$distrito->nome}** nas ultimas 48h.",
                        "Lideranca da **{$faccao->nome}** realizara uma reuniao no **{$distrito->nome}** esta semana.",
                        "**{$faccao->nome}** esta com suprimentos criticos. Uma operacao no **{$distrito->nome}** poderia cortar a logistica deles.",
                        "Fonte interna confirma: moral da **{$faccao->nome}** esta baixo. Momento ideal para pressionar no **{$distrito->nome}**.",
                        "Recrutamento ativo da **{$faccao->nome}** detectado no **{$distrito->nome}**. Novos membros = menor sigilo.",
                    ];
                    $intel = $tiposIntel[array_rand($tiposIntel)];
                }

                $msgDm = "[REDE DE INFORMANTES — CONFIDENCIAL]\n\n"
                       . $intel . "\n\n"
                       . "Esta informacao expira em 24h. Y" . number_format(CUSTO_INFORME, 0, ',', '.') . " debitados do orcamento.";

                $message->author->getPrivateChannel()->then(function ($dmChannel) use ($msgDm) {
                    $dmChannel->sendMessage($msgDm);
                });
            }

            $message->reply("Informacao recebida do contato. Verifique suas mensagens diretas.");
            return;
        }

        // ── !recrutar_informante <id> — Informante fixo em um distrito ─────────
        if (preg_match('/^!recrutar_informante\s+(\d+)$/i', $content, $m)) {
            $distritoId = (int) $m[1];
            $sf         = new SistemaFinanceiro();
            $dRepo      = new DistritoRepository();
            $db         = Database::getConnection();
            $distrito   = $dRepo->buscarPorId($distritoId);

            if (!$distrito) {
                $message->reply("Distrito #{$distritoId} nao encontrado.");
                return;
            }

            // Verifica se ja tem informante neste distrito
            $stmt = $db->prepare("SELECT 1 FROM informantes_fixos WHERE distrito_id = :d AND faccao_id = 'ccg' AND ativo = 1 LIMIT 1");
            $stmt->execute(['d' => $distritoId]);
            if ($stmt->fetch()) {
                $message->reply("Ja existe um informante ativo no **{$distrito->nome}**.");
                return;
            }

            if (!$sf->gastar(CUSTO_INFORMANTE_FIXO)) {
                $message->reply("Saldo insuficiente. Custo: Y" . number_format(CUSTO_INFORMANTE_FIXO, 0, ',', '.') . " | Saldo: Y" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $db->prepare(
                "INSERT INTO informantes_fixos (distrito_id, faccao_id, ativo) VALUES (:d, 'ccg', 1)"
            )->execute(['d' => $distritoId]);

            $message->reply(
                "**Informante Recrutado**\n"
              . "Distrito: **{$distrito->nome}** (#{$distritoId})\n"
              . "O informante passara a enviar relatorios automaticamente a cada investigacao neste distrito.\n"
              . "Y" . number_format(CUSTO_INFORMANTE_FIXO, 0, ',', '.') . " debitados do orcamento."
            );
            return;
        }

        // ── !informantes — Lista informantes fixos ativos ─────────────────────
        if (strtolower($content) === '!informantes') {
            $db    = Database::getConnection();
            $dRepo = new DistritoRepository();
            $stmt  = $db->query("SELECT * FROM informantes_fixos WHERE faccao_id = 'ccg' AND ativo = 1 ORDER BY distrito_id ASC");
            $rows  = $stmt->fetchAll();

            if (empty($rows)) {
                $message->reply("**Rede de Informantes Fixos**\nNenhum informante recrutado ainda. Use `!recrutar_informante <id>`.");
                return;
            }

            $linhas = ["**Rede de Informantes Fixos — CCG**\n"];
            foreach ($rows as $r) {
                $d = $dRepo->buscarPorId((int) $r['distrito_id']);
                $nome = $d ? $d->nome : "Distrito #{$r['distrito_id']}";
                $desde = date('d/m/Y', strtotime($r['data_recrutamento']));
                $linhas[] = "#{$r['distrito_id']} **{$nome}** — desde {$desde}";
            }
            $message->reply(implode("\n", $linhas));
            return;
        }

        // ── !despacho <texto> — Admin ──────────────────────────────────────────
        if (preg_match('/^!despacho\s+(.+)$/si', $content, $m) && $authorId === '242459562655875073') {
            $webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
            if ($webhookNoticias) {
                $clarim = new ClarimToquio($webhookNoticias);
                $clarim->publicarManual("[CLARIM DE TOQUIO] {$m[1]}");
                $message->reply("Despacho publicado no Clarim de Toquio.");
            } else {
                $message->reply("Webhook de noticias nao configurado. Adicione DISCORD_WEBHOOK_NOTICIAS ao .env");
            }
            return;
        }

        // ── !dano <valor> — Admin ─────────────────────────────────────────────
        if (preg_match('/^!dano\s+(\d+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $valor = (int) $m[1];
            (new SistemaFinanceiro())->registrarDanoColateral($valor);
            $message->reply("Dano colateral registrado: Y" . number_format($valor, 0, ',', '.') . " serao descontados na proxima semana.");
            return;
        }

        // ── !ajuda_tokyo / !comandos ───────────────────────────────────────────
        if (in_array(strtolower($content), ['!ajuda_tokyo', '!comandos', '!help_tokyo'])) {
            $message->reply(
                "**Comandos do Bot de Toquio — CCG Esquadrao Zero**\n\n"
              . "**Informacao**\n"
              . "`!mapa` — Status de todos os distritos\n"
              . "`!posicao` — Onde a CCG esta no mapa\n"
              . "`!faccoes` — Status de todas as faccoes\n"
              . "`!quinques` — Arsenal de Quinques da CCG\n"
              . "`!eventos` — Eventos de mapa ativos\n"
              . "`!operacoes` — Operacoes em andamento\n"
              . "`!orcamento` — Orcamento e limite de acoes semanais\n"
              . "`!informantes` — Rede de informantes fixos\n\n"
              . "**Movimento**\n"
              . "`!mover <id>` — Move a CCG para um distrito adjacente\n\n"
              . "**Operacoes** _(custam Y do orcamento — so em distritos adjacentes)_\n"
              . "`!investigar <id>` — Investiga um distrito (Y" . number_format(CUSTO_INVESTIGAR, 0, ',', '.') . ", 2h, " . LIMITE_INVESTIGACOES . "/semana)\n"
              . "`!patrulha <id>` — Patrulha pacifica (Y" . number_format(CUSTO_PATRULHA, 0, ',', '.') . ", 4h, " . LIMITE_PATRULHAS . "/semana)\n"
              . "`!campanha <id>` — Campanha de midia (Y" . number_format(CUSTO_CAMPANHA, 0, ',', '.') . ", 6h, " . LIMITE_CAMPANHAS . "/semana)\n"
              . "`!pesquisar` — Pesquisa de Quinque (Y" . number_format(CUSTO_PESQUISA, 0, ',', '.') . ", 6h, " . LIMITE_PESQUISAS . "/semana)\n"
              . "`!informe` — Intel via rede de informantes (Y" . number_format(CUSTO_INFORME, 0, ',', '.') . ", DM)\n"
              . "`!recrutar_informante <id>` — Informante fixo permanente (Y" . number_format(CUSTO_INFORMANTE_FIXO, 0, ',', '.') . ")\n\n"
              . "**Utilidades**\n"
              . "`1d20`, `2d6+3`, etc. — Rolagem de dados\n"
            );
            return;
        }

    });

});

$discord->run();
