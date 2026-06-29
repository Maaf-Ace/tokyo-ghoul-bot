<?php

use Discord\Builders\MessageBuilder;
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
const LIMITE_INVESTIGACOES  = 3;
const LIMITE_PATRULHAS      = 2;
const LIMITE_CAMPANHAS      = 1;
const LIMITE_PESQUISAS      = 1;
const CUSTO_ABASTECER       = 800;
const LIMITE_ABASTECIMENTOS = 1;

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Resposta IMEDIATA a uma interaction (tipo 4). Use apenas para respostas rápidas
 * que não precisam de processamento pesado (ex: erro de permissão, "já resolvido").
 */
function respondToInteraction($interaction, string $content, bool $ephemeral = false): void {
    $id    = $interaction->id    ?? '';
    $token = $interaction->token ?? '';
    if (empty($id) || empty($token)) return;

    $url     = "https://discord.com/api/v10/interactions/{$id}/{$token}/callback";
    $flags   = $ephemeral ? 64 : 0;
    $payload = json_encode(
        ['type' => 4, 'data' => ['content' => $content, 'flags' => $flags]],
        JSON_UNESCAPED_UNICODE
    );
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n",
                   'content' => $payload, 'ignore_errors' => true, 'timeout' => 5],
    ]);
    @file_get_contents($url, false, $ctx);
}

/**
 * Defer imediato (tipo 5 = "Bot está pensando..."). Deve ser chamado ANTES de qualquer
 * query ao banco, para garantir resposta ao Discord em < 1 segundo.
 * Depois use followupInteraction() para enviar o resultado.
 */
function deferInteraction($interaction): void {
    $id    = $interaction->id    ?? '';
    $token = $interaction->token ?? '';
    if (empty($id) || empty($token)) return;

    $url = "https://discord.com/api/v10/interactions/{$id}/{$token}/callback";
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n",
                   'content' => json_encode(['type' => 5]), 'ignore_errors' => true, 'timeout' => 5],
    ]);
    @file_get_contents($url, false, $ctx);
}

/**
 * Envia mensagem de follow-up após um deferInteraction().
 * $appId = ID do bot (mesmo que user ID, ex: 1333121654783479890).
 */
function followupInteraction(string $appId, string $token, string $content, bool $ephemeral = false): void {
    if (empty($appId) || empty($token)) return;

    $url   = "https://discord.com/api/v10/webhooks/{$appId}/{$token}";
    $flags = $ephemeral ? 64 : 0;
    $payload = json_encode(
        ['content' => $content, 'flags' => $flags],
        JSON_UNESCAPED_UNICODE
    );
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n",
                   'content' => $payload, 'ignore_errors' => true, 'timeout' => 5],
    ]);
    @file_get_contents($url, false, $ctx);
}

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
            $distritos     = (new DistritoRepository())->listarTodos();
            $faccaoRepo    = new FaccaoRepository();
            $eventoRepo    = new EventoMapaRepository();
            $conhecimento  = (new ConhecimentoCCGRepository())->listarTodos(); // indexado por distrito_id
            $ccg           = $faccaoRepo->buscarPorId('ccg');
            $posicaoCCG    = $ccg ? $ccg->posicaoAtual : 0;

            if (empty($distritos)) {
                $message->reply('Nenhum distrito cadastrado.');
                return;
            }

            $linhas = ["**MAPA TATICO DE TOQUIO — CCG** | Posicao: #{$posicaoCCG}\n"];
            foreach ($distritos as $d) {
                $marcador = ($d->id === $posicaoCCG) ? ' **<-- CCG**' : '';
                $saber    = $conhecimento[$d->id] ?? null;
                $nivel    = $saber ? ($saber['nivel_conhecimento'] ?? 'desconhecido') : 'desconhecido';

                if ($nivel === 'desconhecido') {
                    $linhas[] = "? **#{$d->id} {$d->nome}**{$marcador} — [DESCONHECIDO]";
                    continue;
                }

                $faccaoNome = '[Neutro]';
                if ($nivel !== 'basico' && $d->faccaoDominanteId) {
                    $fac = $faccaoRepo->buscarPorId($d->faccaoDominanteId);
                    $faccaoNome = $fac ? "[{$fac->nome}]" : '[Neutro]';
                } elseif ($saber['faccao_conhecida']) {
                    $fac = $faccaoRepo->buscarPorId($saber['faccao_conhecida']);
                    $faccaoNome = $fac ? "[{$fac->nome}*]" : '[Neutro]'; // * = dado pode ser antigo
                }

                $eventos = $eventoRepo->listarAtivosPorDistrito($d->id);
                $evStr   = '';
                foreach ($eventos as $e) {
                    $evStr .= "\n   ⚠ {$e->titulo}";
                }

                $linha = nivelAlertaTexto($d->nivelAlerta)
                       . " **#{$d->id} {$d->nome}**{$marcador} {$faccaoNome}";

                if ($nivel === 'basico') {
                    $linha .= "\n   Alerta: {$d->nivelAlerta}/5 | Dom: {$d->nivelDominacao}%";
                } elseif ($nivel === 'bom') {
                    $linha .= "\n   Alerta: {$d->nivelAlerta}/5 | Dom: {$d->nivelDominacao}%"
                            . " | Seg: {$d->seguranca} | Eco: {$d->economia} | Sup: {$d->suprimentosPop}";
                } else { // critico
                    $domBar = barra($d->nivelDominacao);
                    $linha .= "\n   Dom: `{$domBar}` {$d->nivelDominacao}% | Alerta: {$d->nivelAlerta}/5"
                            . "\n   Satisfacao: {$d->satisfacaoGeral}% (Seg:{$d->seguranca}|Eco:{$d->economia}|Sup:{$d->suprimentosPop})";
                }

                $linha   .= ($d->bonusDominio ? "\n   + {$d->bonusDominio}" : '') . $evStr;
                $linhas[] = $linha;
            }

            // Coleta partes para nao ultrapassar 2000 chars
            $partes = [];
            $parte  = '';
            foreach ($linhas as $linha) {
                $candidato = $parte . ($parte ? "\n\n" : '') . $linha;
                if (strlen($candidato) > 1900) {
                    if ($parte !== '') $partes[] = $parte;
                    $parte = $linha;
                } else {
                    $parte = $candidato;
                }
            }
            if ($parte !== '') $partes[] = $parte;

            // Envia partes — a primeira leva a imagem do mapa se existir
            $imagePath = __DIR__ . '/assets/mapa_tokyo.jpg';
            foreach ($partes as $i => $p) {
                if ($i === 0) {
                    if (file_exists($imagePath)) {
                        $builder = \Discord\Builders\MessageBuilder::new()
                            ->setContent($p)
                            ->addFileFromPath('mapa_tokyo.jpg', $imagePath);
                        $message->reply($builder);
                    } else {
                        $message->reply($p);
                    }
                } else {
                    $message->channel->sendMessage($p);
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

        // ── !mover <id> — Desloca o boneco CCG (cooldown 12h) ────────────────
        if (preg_match('/^!mover\s+(\d+)$/i', $content, $m)) {
            $destino = (int) $m[1];
            $sm      = new SistemaMovimento();
            $result  = $sm->moverCCG($destino);
            $message->reply($result['mensagem']);
            return;
        }

        // ── !mover_teste <id> — Desloca sem cooldown (Admin) ─────────────────
        if (preg_match('/^!mover_teste\s+(\d+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $destino = (int) $m[1];
            $sm      = new SistemaMovimento();
            $result  = $sm->moverCCG($destino, true);
            $message->reply('[TESTE] ' . $result['mensagem']);
            return;
        }

        // ── !faccoes ───────────────────────────────────────────────────────────
        if (in_array(strtolower($content), ['!faccoes', '!frentes', '!faccções'])) {
            $faccoes = (new FaccaoRepository())->listarTodas();

            if (empty($faccoes)) {
                $message->reply('Nenhuma faccao cadastrada.');
                return;
            }

            $linhas = ["**FACCOES DE TOQUIO**\n"];
            foreach ($faccoes as $f) {
                $tag    = $f->id === 'ccg' ? '[CCG]' : '[GHOUL]';
                $linhas[] = "{$tag} **{$f->nome}** — Poder Militar: `" . barra($f->poderMilitar) . "` {$f->poderMilitar}";
            }

            $message->reply(implode("\n", $linhas));
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

        // ── !abastecer <id> ───────────────────────────────────────────────────
        if (preg_match('/^!abastecer\s+(\d+)$/i', $content, $m)) {
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

            if (!$semRepo->verificarLimite('ccg', 'abastecer', LIMITE_ABASTECIMENTOS)) {
                $message->reply("Limite semanal de abastecimentos atingido (" . LIMITE_ABASTECIMENTOS . "/semana). Reset na proxima segunda-feira.");
                return;
            }

            $slot = verificarSlotOperacao($faccaoRepo, $opRepo);
            if (!$slot['ok']) {
                $message->reply($slot['mensagem']);
                return;
            }

            if ($opRepo->temPendente('abastecer', 'ccg', $distritoId)) {
                $message->reply("Ja existe um abastecimento em andamento no **{$distrito->nome}**.");
                return;
            }

            if (!$sf->gastar(CUSTO_ABASTECER)) {
                $message->reply("Saldo insuficiente. Custo: Y" . number_format(CUSTO_ABASTECER, 0, ',', '.') . " | Saldo: Y" . number_format($sf->getOrcamento(), 0, ',', '.'));
                return;
            }

            $semRepo->incrementar('ccg', 'abastecer');
            $op = new Operacao('abastecer', 'ccg', $distritoId, 3, null, null, 'pendente', null, $authorId);
            $opRepo->criar($op);

            $usadas = $semRepo->getQuantidade('ccg', 'abastecer');
            $message->reply(
                "**[TOKYO-GO] Comboio de Abastecimento Autorizado**\n"
              . "Area: **{$distrito->nome}** (#{$distritoId})\n"
              . "Resultado em ~3h. Suprimentos populacionais +20~30.\n"
              . "Y" . number_format(CUSTO_ABASTECER, 0, ',', '.') . " debitados. ({$usadas}/" . LIMITE_ABASTECIMENTOS . " abastecimento desta semana)"
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


        // ══════════════════════════════════════════════════════════════════════
        // PAINEL DE ADMIN — comandos sem ir no banco
        // ══════════════════════════════════════════════════════════════════════

        // ── !set_faccao <id> <stat> <valor> ───────────────────────────────────
        if (preg_match('/^!set_faccao\s+(\S+)\s+(\S+)\s+(\S+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $faccaoId = strtolower($m[1]);
            $stat     = strtolower($m[2]);
            $valor    = $m[3];

            $fRepo  = new FaccaoRepository();
            $faccao = $fRepo->buscarPorId($faccaoId);
            if (!$faccao) {
                $message->reply("Faccao `{$faccaoId}` nao encontrada.");
                return;
            }

            $statsNumericos = ['fome', 'poder', 'suprimentos', 'sigilo', 'agressividade'];
            if (in_array($stat, $statsNumericos)) {
                $n = max(0, min(100, (int) $valor));
                match ($stat) {
                    'fome'         => $faccao->fome         = $n,
                    'poder'        => $faccao->poderMilitar = $n,
                    'suprimentos'  => $faccao->suprimentos  = $n,
                    'sigilo'       => $faccao->sigilo        = $n,
                    'agressividade'=> $faccao->agressividade = $n,
                    default        => null,
                };
                $fRepo->atualizar($faccao);
                $message->reply("**[GM]** `{$faccao->nome}` — {$stat} definido para **{$n}**.");
            } elseif ($stat === 'postura') {
                $posturas = ['predadora', 'indiferente', 'protetora'];
                if (!in_array($valor, $posturas)) {
                    $message->reply("Postura invalida. Use: " . implode(', ', $posturas));
                    return;
                }
                $faccao->posturaCivis = $valor;
                $fRepo->atualizar($faccao);
                $message->reply("**[GM]** `{$faccao->nome}` — postura definida para **{$valor}**.");
            } else {
                $message->reply("Stat invalido. Use: fome, poder, suprimentos, sigilo, agressividade, postura");
            }
            return;
        }

        // ── !set_distrito <id> <stat> <valor> ────────────────────────────────
        if (preg_match('/^!set_distrito\s+(\d+)\s+(\S+)\s+(\S+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $distritoId = (int) $m[1];
            $stat       = strtolower($m[2]);
            $valor      = $m[3];

            $dRepo   = new DistritoRepository();
            $distrito = $dRepo->buscarPorId($distritoId);
            if (!$distrito) {
                $message->reply("Distrito #{$distritoId} nao encontrado.");
                return;
            }

            $antigo = null;
            switch ($stat) {
                case 'seguranca':
                    $antigo = $distrito->seguranca;
                    $distrito->seguranca = max(0, min(100, (int) $valor));
                    break;
                case 'economia':
                    $antigo = $distrito->economia;
                    $distrito->economia = max(0, min(100, (int) $valor));
                    break;
                case 'suprimentos':
                    $antigo = $distrito->suprimentosPop;
                    $distrito->suprimentosPop = max(0, min(100, (int) $valor));
                    break;
                case 'alerta':
                    $antigo = $distrito->nivelAlerta;
                    $distrito->nivelAlerta = max(0, min(5, (int) $valor));
                    break;
                case 'dominacao':
                    $antigo = $distrito->nivelDominacao;
                    $distrito->nivelDominacao = max(0, min(100, (int) $valor));
                    break;
                default:
                    $message->reply("Stat invalido. Use: seguranca, economia, suprimentos, alerta, dominacao");
                    return;
            }

            $dRepo->atualizar($distrito);
            $message->reply("**[GM]** **{$distrito->nome}** (#{$distritoId}) — {$stat}: {$antigo} → **" . $m[3] . "**");
            return;
        }

        // ── !dominar <distrito_id> <faccao_id> ───────────────────────────────
        if (preg_match('/^!dominar\s+(\d+)\s+(\S+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $distritoId = (int) $m[1];
            $faccaoId   = strtolower($m[2]);

            $dRepo    = new DistritoRepository();
            $fRepo    = new FaccaoRepository();
            $distrito = $dRepo->buscarPorId($distritoId);
            $faccao   = $fRepo->buscarPorId($faccaoId);

            if (!$distrito) { $message->reply("Distrito #{$distritoId} nao encontrado."); return; }
            if (!$faccao)   { $message->reply("Faccao `{$faccaoId}` nao encontrada."); return; }

            $dRepo->conquistar($distritoId, $faccaoId);
            $message->reply("**[GM]** **{$faccao->nome}** agora domina **{$distrito->nome}** (#{$distritoId}).");
            return;
        }

        // ── !liberar <distrito_id> ────────────────────────────────────────────
        if (preg_match('/^!liberar\s+(\d+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $distritoId = (int) $m[1];

            $dRepo    = new DistritoRepository();
            $distrito = $dRepo->buscarPorId($distritoId);
            if (!$distrito) { $message->reply("Distrito #{$distritoId} nao encontrado."); return; }

            $anterior = $distrito->faccaoDominanteId ?? 'nenhum';
            $dRepo->expulsarDominador($distritoId);
            $message->reply("**[GM]** **{$distrito->nome}** (#{$distritoId}) liberado. Era dominado por: {$anterior}.");
            return;
        }

        // ── !adm — Lista todos os comandos de admin ───────────────────────────
        if (strtolower($content) === '!adm' && $authorId === '242459562655875073') {
            $message->reply(
                "**[PAINEL GM — Comandos Admin]**\n\n"
              . "**Facções**\n"
              . "`!set_faccao <id> <stat> <valor>` — altera stat de uma faccao\n"
              . "  Stats: `fome`, `poder`, `suprimentos`, `sigilo`, `agressividade`, `postura`\n"
              . "  IDs: kurogami, shinku_hana, mizu_no_rei, jishin, kiba_no_ikari, ccg\n"
              . "  Ex: `!set_faccao kurogami fome 20`\n\n"
              . "**Distritos**\n"
              . "`!set_distrito <id> <stat> <valor>` — altera pilar de um distrito (1-23)\n"
              . "  Stats: `seguranca`, `economia`, `suprimentos`, `alerta` (0-5), `dominacao`\n"
              . "  Ex: `!set_distrito 14 seguranca 75`\n\n"
              . "**Conquista / Liberação**\n"
              . "`!dominar <distrito_id> <faccao_id>` — faz faccao dominar o distrito\n"
              . "`!liberar <distrito_id>` — remove o dominador do distrito\n\n"
              . "**Combate**\n"
              . "`!combates_mesa` — lista confrontos aguardando GM\n"
              . "`!resolver_mesa <id> <faccao_id>` — define vencedor do combate em mesa\n"
              . "`!cancelar_mesa <id>` — cancela sem efeitos\n\n"
              . "**Outros**\n"
              . "`!despacho <texto>` — posta no Tokyo-GO\n"
              . "`!dano <valor>` — registra dano colateral\n"
              . "`!batalha_teste` — confronto de teste\n"
              . "`!atividades_ghoul` — lista ops ghoul em andamento\n"
              . "`!mover_teste <id>` — move CCG sem cooldown\n"
            );
            return;
        }

        // ── !despacho <texto> — Admin ──────────────────────────────────────────
        if (preg_match('/^!despacho\s+(.+)$/si', $content, $m) && $authorId === '242459562655875073') {
            $webhookNoticias = Env::get('DISCORD_WEBHOOK_NOTICIAS', '');
            if ($webhookNoticias) {
                $clarim = new ClarimToquio($webhookNoticias);
                $clarim->publicarManual("[TOKYO-GO] {$m[1]}");
                $message->reply("Despacho publicado no Tokyo-GO.");
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

        // ── !batalha_teste — Força um confronto de teste (Admin) ─────────────
        if (strtolower($content) === '!batalha_teste' && $authorId === '242459562655875073') {
            $faccaoRepo  = new FaccaoRepository();
            $faccoesGhoul = $faccaoRepo->listarFaccoesGhoul();
            $ccg         = $faccaoRepo->buscarPorId('ccg');

            if (empty($faccoesGhoul) || !$ccg) {
                $message->reply("Nenhuma faccao ghoul ou CCG nao encontrada.");
                return;
            }

            $atacante    = $faccoesGhoul[array_rand($faccoesGhoul)];
            $taticas     = ['emboscada', 'rush', 'defesa'];
            $taticaAtc   = $taticas[array_rand($taticas)];
            $confrontoId = 'tst' . bin2hex(random_bytes(6)); // sem underscore para não quebrar regex

            $botToken        = Env::get('DISCORD_TOKEN', '');
            $opsChannel      = Env::get('DISCORD_OPS_CHANNEL_ID', '');
            $clarim          = new ClarimToquio('', '', $botToken, $opsChannel);
            $historicoRepo   = new HistoricoCombateRepository();
            $historicoTaticas = $historicoRepo->getUltimasTaticasAtacante($atacante->id, 5);
            $msgId           = $clarim->publicarAlertaConfronto($confrontoId, $atacante, (int) $ccg->posicaoAtual, $historicoTaticas);

            if ($msgId) {
                $cpRepo = new ConfruntoPendenteRepository();
                $expira = date('Y-m-d H:i:s', time() + 600);
                $cpRepo->criar($confrontoId, $atacante->id, (int) $ccg->posicaoAtual, $taticaAtc, $msgId, $expira);

                $message->reply(
                    "**[TESTE DE CONFRONTO]** Criado com sucesso!\n"
                  . "Atacante: **{$atacante->nome}** | Tatica: **{$taticaAtc}**\n"
                  . "ID: `{$confrontoId}`\n"
                  . "Botoes postados no canal de ops. Voce tem 10 min para responder.\n"
                  . "_(Divisao Alfa ou admin pode clicar)_"
                );
            } else {
                $message->reply(
                    "**[ERRO]** Nao foi possivel postar os botoes no canal de ops.\n"
                  . "Verifique se `DISCORD_OPS_CHANNEL_ID` esta configurado no .env da VM.\n"
                  . "ID seria: `{$confrontoId}`"
                );
            }
            return;
        }

        // ── !atividades_ghoul — Atividades ghoul em andamento (Admin) ─────────
        if (strtolower($content) === '!atividades_ghoul' && $authorId === '242459562655875073') {
            $pendentes  = (new OperacaoGhoulRepository())->listarPendentes();
            if (empty($pendentes)) {
                $message->reply("**Atividades Ghoul**\nNenhuma atividade em andamento.");
                return;
            }

            $fRepo  = new FaccaoRepository();
            $dRepo  = new DistritoRepository();
            $linhas = ["**Atividades Ghoul em Andamento**\n"];
            foreach ($pendentes as $op) {
                $fac  = $fRepo->buscarPorId($op['faccao_id']);
                $dist = ($op['distrito_id'] > 0) ? $dRepo->buscarPorId((int) $op['distrito_id']) : null;
                $fim  = date('d/m H:i', strtotime($op['data_fim']));
                $alrt = $op['alerta_enviado'] ? ' [alerta enviado]' : '';
                $linhas[] = "**" . ($fac ? $fac->nome : $op['faccao_id']) . "** — {$op['tipo']}"
                          . ($dist ? " em {$dist->nome}" : '')
                          . " | Conclui: {$fim}{$alrt}";
            }
            $message->reply(implode("\n", $linhas));
            return;
        }

        // ── !combates_mesa — Lista confrontos em standby (Admin) ─────────────
        if (strtolower($content) === '!combates_mesa' && $authorId === '242459562655875073') {
            $mesaRepo  = new CombateMesaRepository();
            $pendentes = $mesaRepo->listarPendentes();

            if (empty($pendentes)) {
                $message->reply("**Combates em Mesa**\nNenhum confronto aguardando resolucao.");
                return;
            }

            $dRepo  = new DistritoRepository();
            $linhas = ["**Combates em Mesa — Aguardando GM**\n"];
            foreach ($pendentes as $c) {
                $dist     = $dRepo->buscarPorId((int) $c['distrito_id']);
                $nomeDist = $dist ? $dist->nome : "Distrito #{$c['distrito_id']}";
                $data     = date('d/m H:i', strtotime($c['data_criacao']));
                $linhas[] = "ID: `{$c['confronto_id']}`\n"
                          . "   Atacante: **{$c['atacante_nome']}** | Tatica: {$c['tatica_atacante']}\n"
                          . "   Local: {$nomeDist} | Criado: {$data}\n"
                          . "   Resolver: `!resolver_mesa {$c['confronto_id']} <ccg|id_faccao>`";
            }
            $message->reply(implode("\n\n", $linhas));
            return;
        }

        // ── !resolver_mesa <id> <vencedor_id> — Resolve confronto em mesa (Admin) ──
        if (preg_match('/^!resolver_mesa\s+(\S+)\s+(\S+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $confrontoId = $m[1];
            $vencedorId  = $m[2];

            $mesaRepo  = new CombateMesaRepository();
            $confronto = $mesaRepo->buscarPorId($confrontoId);

            if (!$confronto || $confronto['status'] !== 'pendente') {
                $message->reply("Confronto `{$confrontoId}` nao encontrado ou ja resolvido/cancelado.");
                return;
            }

            $faccaoRepo = new FaccaoRepository();
            $vencedor   = $faccaoRepo->buscarPorId($vencedorId);
            if (!$vencedor) {
                $message->reply("Faccao `{$vencedorId}` nao encontrada. Use o ID da faccao (ex: ccg, aogiri_tree).");
                return;
            }

            $atacante = $faccaoRepo->buscarPorId($confronto['atacante_id']);
            $ccg      = $faccaoRepo->buscarPorId('ccg');

            if ($atacante && $ccg) {
                if ($vencedorId === 'ccg') {
                    $atacante->poderMilitar = max(0, $atacante->poderMilitar - 8);
                    $ccg->poderMilitar      = max(0, $ccg->poderMilitar - 3);
                } else {
                    $ccg->poderMilitar      = max(0, $ccg->poderMilitar - 8);
                    $atacante->poderMilitar = max(0, $atacante->poderMilitar - 3);
                    $atacante->suprimentos  = min(100, $atacante->suprimentos + 10);
                }
                $faccaoRepo->atualizar($atacante);
                $faccaoRepo->atualizar($ccg);
            }

            $mesaRepo->resolver($confrontoId, $vencedorId);

            $message->reply(
                "**[GM — Combate em Mesa Resolvido]**\n"
              . "Confronto `{$confrontoId}` encerrado.\n"
              . "Vencedor: **{$vencedor->nome}**\n"
              . "Efeitos de combate aplicados automaticamente."
            );
            return;
        }

        // ── !cancelar_mesa <id> — Cancela confronto em mesa (Admin) ─────────
        if (preg_match('/^!cancelar_mesa\s+(\S+)$/i', $content, $m) && $authorId === '242459562655875073') {
            $confrontoId = $m[1];

            $mesaRepo  = new CombateMesaRepository();
            $confronto = $mesaRepo->buscarPorId($confrontoId);

            if (!$confronto || $confronto['status'] !== 'pendente') {
                $message->reply("Confronto `{$confrontoId}` nao encontrado ou ja resolvido/cancelado.");
                return;
            }

            $mesaRepo->cancelar($confrontoId);
            $message->reply("**[GM]** Confronto `{$confrontoId}` cancelado sem efeitos de combate.");
            return;
        }

        // ── !ajuda_tokyo / !comandos ───────────────────────────────────────────
        if (in_array(strtolower($content), ['!ajuda_tokyo', '!comandos', '!tokyo_ghoul'])) {
            $message->reply(
                "**[TOKYO-GO] Comandos — CCG Esquadrao Zero**\n\n"
              . "**Informacao**\n"
              . "`!mapa` — Mapa tatico (conhecimento da CCG por distrito)\n"
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
              . "`!investigar <id>` — Investiga (Y" . number_format(CUSTO_INVESTIGAR, 0, ',', '.') . ", 2h, " . LIMITE_INVESTIGACOES . "/sem) — eleva conhecimento do distrito\n"
              . "`!patrulha <id>` — Patrulha (Y" . number_format(CUSTO_PATRULHA, 0, ',', '.') . ", 4h, " . LIMITE_PATRULHAS . "/sem) — seguranca +15~25\n"
              . "`!campanha <id>` — Campanha de midia (Y" . number_format(CUSTO_CAMPANHA, 0, ',', '.') . ", 6h, " . LIMITE_CAMPANHAS . "/sem) — economia +20~35\n"
              . "`!abastecer <id>` — Comboio civil (Y" . number_format(CUSTO_ABASTECER, 0, ',', '.') . ", 3h, " . LIMITE_ABASTECIMENTOS . "/sem) — suprimentos +20~30\n"
              . "`!pesquisar` — Quinque (Y" . number_format(CUSTO_PESQUISA, 0, ',', '.') . ", 6h, " . LIMITE_PESQUISAS . "/sem)\n"
              . "`!informe` — Intel via informantes (Y" . number_format(CUSTO_INFORME, 0, ',', '.') . ", DM)\n"
              . "`!recrutar_informante <id>` — Informante fixo (Y" . number_format(CUSTO_INFORMANTE_FIXO, 0, ',', '.') . ")\n\n"
              . "**Combate**\n"
              . "Quando ghouls atacarem, botoes aparecerao no canal de ops — Divisao Alfa escolhe a tatica em 10 min.\n\n"
              . "**Utilidades**\n"
              . "`1d20`, `2d6+3`, etc. — Rolagem de dados\n"
            );
            return;
        }

    });

    // ── !batalha_teste — Força um confronto de teste (Admin) ──────────────────
    // (declarado aqui para ser acessível dentro do MESSAGE_CREATE handler abaixo)

    // ── Botões de tática de combate e interceptação ────────────────────────────
    $discord->on(Event::INTERACTION_CREATE, function ($interaction, Discord $discord) {

        $typeRaw  = $interaction->type ?? 'null';
        $customId = $interaction->data?->custom_id ?? '';
        echo "[INTERACTION] type={$typeRaw} customId={$customId}\n";

        // Somente MESSAGE_COMPONENT (cliques em botoes) = tipo 3
        if ((int) $typeRaw !== 3) return;
        if (empty($customId)) return;

        try {

        // ─── Helper: verifica cargo "Divisao Alfa" via cache do servidor ──────
        $verificarRole = function () use ($interaction, $discord): bool {
            $userId = $interaction->member?->user?->id ?? $interaction->user?->id ?? '';
            if ($userId === '242459562655875073') return true;

            $guildId = $interaction->guild_id ?? '';
            $guild   = $guildId ? $discord->guilds->get('id', $guildId) : null;
            if (!$interaction->member) return false;

            foreach ($interaction->member->roles as $role) {
                $roleId    = is_object($role) ? ($role->id ?? '') : (string) $role;
                $guildRole = $guild ? $guild->roles->get('id', $roleId) : null;
                $roleName  = $guildRole ? $guildRole->name : (is_object($role) ? ($role->name ?? '') : '');
                if ($roleName && mb_strtolower($roleName) === mb_strtolower('Divisão Alfa')) return true;
            }
            return false;
        };

        // ── Tática de combate: tatica_{confrontoId}_{emboscada|rush|defesa} ──
        if (preg_match('/^tatica_(.+)_(emboscada|rush|defesa)$/', $customId, $m)) {
            [, $confrontoId, $taticaEscolhida] = $m;

            if (!$verificarRole()) {
                respondToInteraction($interaction, 'Apenas membros da **Divisao Alfa** podem escolher taticas de combate.', true);
                return;
            }

            $cpRepo    = new ConfruntoPendenteRepository();
            $confronto = $cpRepo->buscarPorId($confrontoId);

            if (!$confronto || $confronto['resolvido']) {
                respondToInteraction($interaction, 'Este confronto ja foi resolvido ou nao existe.', true);
                return;
            }

            if (strtotime($confronto['expira_em']) < time()) {
                respondToInteraction($interaction, 'O tempo de resposta expirou. O confronto sera resolvido no proximo tick.', true);
                return;
            }

            // Defer IMEDIATO antes de qualquer query — garante resposta ao Discord em < 1s
            $appId = $discord->application->id ?? '';
            $token = $interaction->token ?? '';
            deferInteraction($interaction);

            $faccaoRepo = new FaccaoRepository();
            $atacante   = $faccaoRepo->buscarPorId($confronto['atacante_id']);
            $ccg        = $faccaoRepo->buscarPorId('ccg');

            if (!$atacante || !$ccg) {
                followupInteraction($appId, $token, 'Erro interno: faccao nao encontrada.');
                return;
            }

            $motor     = new MotorEventos();
            $resultado = $motor->resolverConfronto($atacante, $ccg, $confronto['tatica_atacante'], $taticaEscolhida);
            $cpRepo->marcarResolvido($confrontoId);

            $tAtc     = ucfirst($confronto['tatica_atacante']);
            $tDef     = ucfirst($taticaEscolhida);
            $vencedor = $resultado['vencedor'];

            $msg = "**[TOKYO-GO | CONFRONTO RESOLVIDO]**\n"
                 . "**{$atacante->nome}** ({$tAtc}) vs **CCG** ({$tDef})\n"
                 . "Forca atacante: {$resultado['forca_final_atacante']} | Forca defesa: {$resultado['forca_final_defensor']}\n"
                 . "**Vencedor: {$vencedor}** — {$resultado['resultado_texto']}";

            if (!empty($resultado['derrota_sinistra'])) {
                $msg .= "\n**[DERROTA SINISTRA]** Esmagamento total — suprimentos adicionais perdidos!";
            }

            followupInteraction($appId, $token, $msg);
            return;
        }

        // ── Combate em Mesa: mesa_{confrontoId} ───────────────────────────
        if (preg_match('/^mesa_(.+)$/', $customId, $m)) {
            $confrontoId = $m[1];

            if (!$verificarRole()) {
                respondToInteraction($interaction, 'Apenas membros da **Divisao Alfa** podem enviar confrontos para a mesa.', true);
                return;
            }

            $appId = $discord->application->id ?? '';
            $token = $interaction->token ?? '';
            deferInteraction($interaction);

            $cpRepo    = new ConfruntoPendenteRepository();
            $confronto = $cpRepo->buscarPorId($confrontoId);

            if (!$confronto || $confronto['resolvido']) {
                followupInteraction($appId, $token, 'Este confronto ja foi resolvido ou nao existe.', true);
                return;
            }

            $mesaRepo = new CombateMesaRepository();
            if ($mesaRepo->buscarPorId($confrontoId)) {
                followupInteraction($appId, $token, 'Este confronto ja esta em standby na mesa.', true);
                return;
            }

            $faccaoRepo = new FaccaoRepository();
            $atacante   = $faccaoRepo->buscarPorId($confronto['atacante_id']);
            $nomeFac    = $atacante ? $atacante->nome : $confronto['atacante_id'];

            $mesaRepo->criar($confrontoId, $confronto['atacante_id'], (int) $confronto['distrito_id'], $confronto['tatica_atacante']);
            $cpRepo->marcarResolvido($confrontoId);

            followupInteraction($appId, $token,
                "**[COMBATE EM MESA]** O confronto com **{$nomeFac}** entrou em standby.\n"
              . "O GM sera notificado para conduzir o combate manualmente.\n"
              . "Use `!combates_mesa` para ver os confrontos aguardando resolucao."
            );
            return;
        }

        // ── Interceptação ghoul: interceptar_{opId} ────────────────────────
        if (preg_match('/^interceptar_(.+)$/', $customId, $m)) {
            $opId = $m[1];

            if (!$verificarRole()) {
                respondToInteraction($interaction, 'Apenas membros da **Divisao Alfa** podem interceptar operacoes ghoul.', true);
                return;
            }

            $opGhoulRepo = new OperacaoGhoulRepository();
            $pendentes   = $opGhoulRepo->listarPendentes();
            $op          = null;
            foreach ($pendentes as $p) {
                if ($p['id'] === $opId) { $op = $p; break; }
            }

            if (!$op) {
                respondToInteraction($interaction, 'Operacao ja foi concluida ou nao existe mais.', true);
                return;
            }

            $appId = $discord->application->id ?? '';
            $token = $interaction->token ?? '';
            deferInteraction($interaction);

            $opGhoulRepo->marcarInterceptada($opId);

            $faccaoRepo = new FaccaoRepository();
            $fac        = $faccaoRepo->buscarPorId($op['faccao_id']);
            $nomeFac    = $fac ? $fac->nome : $op['faccao_id'];
            $tipoTrad   = ['alimentar' => 'alimentacao', 'cacar' => 'cacada', 'contrabandear' => 'contrabando', 'recrutar' => 'recrutamento'];
            $tipoStr    = $tipoTrad[$op['tipo']] ?? $op['tipo'];

            if ($fac) {
                $fac->sigilo = max(0, $fac->sigilo - rand(10, 20));
                $faccaoRepo->atualizar($fac);
            }

            $msg = "**[TOKYO-GO | INTERCEPTACAO BEM-SUCEDIDA]**\n"
                 . "Operacao de **{$tipoStr}** da **{$nomeFac}** foi interrompida!\n"
                 . "A faccao recua sem colher nenhum beneficio. Sigilo comprometido.";

            followupInteraction($appId, $token, $msg);
            return;
        }

        } catch (Throwable $e) {
            echo "[INTERACTION ERROR] " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine() . "\n";
            respondToInteraction($interaction, 'Erro interno. Verifique os logs do bot.', true);
        }
    });

});

$discord->run();
