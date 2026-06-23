# Documentação do Bot de Tóquio (CCG Esquadrão Zero)

Referência das principais funções do sistema — o que cada peça faz e como tudo se conecta.

---

## 1. Visão geral do fluxo

O sistema tem **3 pontos de entrada** que rodam de forma independente:

| Arquivo | Quando roda | O que faz |
|---|---|---|
| `bot.php` | 24/7 (worker) | Escuta mensagens do Discord e responde aos comandos `!...` dos jogadores. |
| `scripts/tick.php` | A cada 10 min (cron) | "Pulso do mundo": faz as facções viverem, se moverem, lutarem e conclui operações. |
| `scripts/tick_semanal.php` | Segunda 08:00 Brasília (cron) | "Virada de semana": paga renda, reseta posições/limites, sorteia eventos. |

Os três incluem o `bootstrap.php`, que carrega todas as classes e o `.env`.

**Camadas (padrão Repository):**
- **Models** (`src/Models/`) — representam uma linha do banco + regras próprias da entidade. Não acessam o banco.
- **Repositories** (`src/Repositories/`) — única camada que lê/grava no banco (SQL).
- **Game** (`src/Game/`) — a lógica/regras do jogo. Usa os Repositories para persistir.

---

## 2. Models (`src/Models/`)

### `Faccao.php`
Representa uma facção (CCG ou ghoul). Campos: poder militar, suprimentos, fome, agressividade, sigilo, nível de alerta, postura_civis, tática favorita, **posição atual** e **agentes feridos até**.
- `fromArray($linha)` — cria a Facção a partir de uma linha do banco.
- `agentesFeridados()` — retorna `true` se os agentes da CCG ainda estão fora de ação (limita a 1 operação simultânea).
- `passarOTempo()` — aumenta a fome (e, se a fome ≥ 80, sobe agressividade e derruba sigilo). Não afeta a CCG.
- `aplicarInstintoSobrevivencia()` — facção com poder militar baixo ganha sigilo passivamente.

### `Distrito.php`
Representa um dos 23 distritos reais de Tóquio. Campos: nome, bônus de domínio, facção dominante, nível de alerta, nível de dominação, status de guerra e os **3 pilares de satisfação**:
- `seguranca` — presença de segurança pública (0–100)
- `economia` — atividade econômica local (0–100)
- `suprimentosPop` — suprimentos disponíveis para a população (0–100)
- `satisfacaoGeral` — coluna **GENERATED STORED** no banco: `ROUND((seguranca + economia + suprimentos_pop) / 3)`

> O campo `apoio_civil` ainda existe no banco por compatibilidade, mas a lógica usa os 3 pilares.

- `aplicarDanoDominacao($dano)` — reduz a dominação; abaixo de 50% vira "em_disputa".
- `bonusAtivo()` — diz se o bônus do distrito está valendo (pacificado e dominação ≥ 50%).

### `Operacao.php`
Representa uma operação da CCG agendada no tempo. Tipos: investigacao, patrulha, campanha, pesquisa, **abastecer**.
- `jaConcluiu()` — diz se o prazo (`data_fim`) já venceu.

### `EventoMapa.php` e `Quinque.php`
Models simples de dados (evento de mapa ativo e arma quinque criada no laboratório).

---

## 3. Repositories (`src/Repositories/`)

### `FaccaoRepository.php`
- `buscarPorId($id)`, `listarTodas()`, `listarFaccoesGhoul()` (todas menos a CCG).
- `criar()`, `atualizar()`, `salvar()`, `deletar()`.

### `DistritoRepository.php`
- `buscarPorId($id)`, `listarTodos()`, `listarPorFaccao($id)`.
- `criar()`, `atualizar()`, `salvar()`.
- `expulsarDominador($distritoId)` — remove o dominador (revolta): zera dominação, volta para "em_disputa".
- `conquistar($distritoId, $faccaoId)` — define nova facção dominante (100% dominação, "pacificado").

### `OperacaoRepository.php`
- `criar()`, `listarPendentes()`, `listarProntasParaConcluir()` (prazo vencido).
- `temPendente($tipo, $faccao, $distrito)` — evita duplicar a mesma operação.
- `marcarConcluida($id, $resultado)`.

### `QuestDistritoRepository.php`
Gerencia as missões de conquista por distrito. Cada distrito tem 1 quest principal + 1 secundária.
- `listarPorDistrito($id)`, `listarReveladas($id)` — apenas as já reveladas aos jogadores.
- `revelar($questId)`, `revelarTodasDoDistrito($id)` — revela quests ocultas (ativado por investigação bom/crítico).
- `concluir($questId, $faccaoId)` — marca a quest como concluída por uma facção.
- `verificarConquista($distritoId, $faccaoId, $satisfacaoGeral)` — retorna `true` se: satisfação ≥ 80 + quest principal concluída + (se houver secundária) ao menos 1 secundária concluída.
- `seedParaDistrito($id)` — gera 1 quest principal + 1 secundária aleatórias do catálogo.
- `resetarParaDistrito($id)` — apaga e regera quests (chamado após expulsão de dominador).

### `ConfruntoPendenteRepository.php`
Gerencia confrontos aguardando escolha de tática pelo jogador (janela de 10 min).
- `criar($id, $atacanteId, $distritoId, $taticaAtacante, $messageId, $expiraEm)`.
- `buscarPorId($id)`, `buscarExpirados()` — WHERE resolvido=0 AND expira_em < NOW().
- `marcarResolvido($id)`.
- `existePendente($atacanteId)` — impede criar dois confrontos para o mesmo atacante.

### `ConhecimentoCCGRepository.php`
Controla o que a CCG sabe sobre cada distrito. Nível só sobe, nunca desce.
Níveis: `desconhecido` → `basico` → `bom` → `critico`.
- `atualizarConhecimento($distritoId, $nivel, $dados)` — eleva o nível e salva dados (facção conhecida, dominação, tática, poder aproximado).
- `listarTodos()` — indexado por distrito_id (usado pelo `!mapa`).
- `seedTodos()` — cria entradas "desconhecido" para todos os distritos; Chiyoda (#15) já nasce "crítico".

### `FinancasCCGRepository.php`
Controla o dinheiro da CCG.
- `getOrcamento()`, `getRendaBase()`, `getDanoColateralPendente()`.
- `ajustarOrcamento($delta)` — soma/subtrai do saldo (nunca abaixo de 0).
- `adicionarDanoColateral()`, `zerarDanoColateral()`, `reduzirRendaBase()`.
- `registrarSemana(...)` — grava o histórico financeiro semanal.

### `AdjacenciaRepository.php`
Controla quais distritos fazem fronteira.
- `saoAdjacentes($a, $b)` — `true` se dá para ir de um ao outro (ou se for o mesmo).
- `getAdjacentes($id)` — lista os IDs vizinhos de um distrito.

### `AcoesSemanaRepository.php`
Controla os **limites semanais** de ações da CCG.
- `getQuantidade($faccao, $tipo)`, `verificarLimite($faccao, $tipo, $limite)`, `incrementar($faccao, $tipo)`.
- `resetarSemanasAntigas()` — apaga contadores de semanas passadas.
- `resumoCCG()` — devolve quantas ações de cada tipo a CCG já usou (para `!orcamento`).

### `MovimentoRepository.php`
Registra o log de movimentos das facções (base da detecção por investigação).
- `registrar($faccao, $origem, $destino, $motivo)`.
- `listarPorDistrito($id, $horas)`, `listarDaFaccao($id, $horas)`, `ultimoMovimento($id)`.
- `limparAntigos($dias)` — limpeza periódica.

### `DiplomaciaRepository.php` (contém também `HistoricoCombateRepository`)
- Diplomacia: `saoAliadas()` (afinidade entre facções).
- Histórico de combate: `registrar($atacante, $defensor, $taticaA, $taticaD, $vencedor, $derrotaSinistra, $escolhaManual)`, `buscarHistoricoDaFaccao()`, `getMemoriaTaticaDaFaccao()` (usado pela IA para counterar táticas).

---

## 4. Lógica do jogo (`src/Game/`)

### `MotorEventos.php` — "Cérebro do submundo"
Resolve confrontos entre facções. Recebe opcionalmente `ClarimToquio` e `ConfruntoPendenteRepository` para o sistema de botões.
- `escolherTatica($faccao, $memoriaInimigo)` — pesos por sigilo/agressividade + tática favorita + counterar o inimigo.
- `calcularVantagem($a, $b)` — pedra-papel-tesoura: emboscada > rush > defesa > emboscada (±15).
- `resolverConfronto($atacante, $defensor, $taticaAtacanteOverride, $taticaDefensorOverride)` — resolve a luta. Suporta táticas manuais (de confronto pendente ou escolha do jogador). Detecta **derrota sinistra** (diferença > 30): aplica perda extra de suprimentos e salva flag no histórico.
- `tentarIniciarConflito($faccao, $todas, $chance)` — sorteia se a facção ataca alguém. **Se o alvo for a CCG e o ClarimToquio estiver injetado**, cria um `confronto_pendente` e posta alerta com botões em vez de resolver imediatamente.
- `resolverConfrontosExpirados()` — resolve automaticamente todos os confrontos_pendentes expirados usando IA para a CCG. **Chamado pelo `tick.php`.**
- `rodarTick()` — roda um ciclo completo: passa o tempo em todas as ghoul e dá chance de conflito.

### `RotinasSobrevivencia.php` — rotina autônoma das ghouls
Cada tick, cada facção ghoul faz **uma** ação de sobrevivência.
- `cacar()` — reduz fome, mas baixa **segurança** (-6~10) e sobe o alerta do distrito onde a facção está.
- `contrabandear()` — ganha suprimentos, perde sigilo e reduz **economia** (-10) do distrito.
- `recrutar()` — ganha poder militar, perde sigilo e ganha fome. Não afeta o distrito.

### `SistemaMovimento.php` — movimento e detecção
- `moverCCG($destino)` — move o "boneco" da CCG. Valida adjacência. Registra no log.
- `executarMovimentoIA()` — move as facções ghoul automaticamente (40% de chance por tick).
- `verificarDeteccao($distritoId, $faccao)` — fórmula `d20 + floor(satisfacaoGeral/10)` vs sigilo da facção. Resultado: **total** (revela facção e motivo), **parcial** (atividade suspeita) ou **nenhum**.
- `aplicarPressaoZona()` — distritos vizinhos de territórios de facções com `postura_civis = 'predadora'` perdem 2 de **segurança** por tick. **Chamado pelo `tick.php`.**
- `resetarPosicoes()` — devolve todas as facções para suas bases. **Chamado pelo `tick_semanal.php`.**

### `GerenciadorOperacoes.php` — ciclo de vida das operações
- `iniciarOperacao(...)` — cria uma operação agendada.
- `processarConclusoes()` — busca as que venceram o prazo, marca como concluídas e devolve a lista. **Chamado pelo `tick.php`.**

### `ResolvedorOperacoes.php` — resultado das operações da CCG
- `resolver($op)` — direciona para o tipo certo.
- `resolverInvestigacao()` — revela dados do distrito (alerta, controle, 3 pilares, eventos). Determina nível de conhecimento (basico/bom/critico) com base na `satisfacaoGeral` e grava em `conhecimento_ccg`. Satisfação ≥ 50% dá dica dos moradores. Nível bom/crítico revela as quests do distrito.
- `resolverPatrulha()` — sobe **segurança** (+15~25) e reduz 1 nível de alerta.
- `resolverCampanha()` — sobe **economia** (+20~35); a partir de 60 desbloqueia dicas espontâneas.
- `resolverAbastecer()` — sobe **suprimentos_pop** (+20~30); CCG perde 15 suprimentos.
- `resolverPesquisa()` — se a CCG venceu algum combate, **cria um Quinque** aleatório.

### `SistemaFinanceiro.php` — dinheiro da CCG
- `gastar($valor)` — debita do orçamento; devolve `false` se faltar saldo.
- `registrarDanoColateral($valor)` — agenda desconto para a semana seguinte.
- `processarSemana()` — paga renda, desconta dano colateral, aplica corte de financiamento.
- `calcularCorteFinanciamento()` — distritos com alerta ≥ 4 cortam ¥300; ≥ 3 cortam ¥100.
- `formatarRelatorio()` — monta o texto do `!orcamento`.

### `GerenciadorEventosMapa.php` — imprevistos semanais
Catálogo de 5 eventos: blecaute, protestos, surto de ghoul, reforço policial, mercado negro.
- `sortearEventosSemana()` — cria de 1 a 3 eventos aleatórios. **Blecaute aplica economia -20 imediatamente.** Chamado pelo `tick_semanal.php`.
- `expirarEventos()`, `temEvento()`, `listarAtivos()`.

### `ClarimToquio.php` — o "jornal" (Tokyo-GO)
Publica notícias em dois canais via webhook, e alertas de confronto via REST API.
- `publicarEventosTick()` — vira combates e caçadas em manchetes. Confrontos com **derrota sinistra** recebem texto dramático extra.
- `publicarEventoMapa()` — anuncia um evento de mapa.
- `publicarResultadoOperacao()` — posta resultado no canal de operações.
- `publicarManual()` — texto livre (usado pelo `!despacho`).
- `publicarAlertaConfronto($confrontoId, $atacante, $distritoId, $taticaAtacante)` — posta mensagem com 3 botões (Emboscada / Rush / Defesa) no canal de ops via REST API (`POST /channels/{id}/messages`). Requer `DISCORD_OPS_CHANNEL_ID` e `DISCORD_TOKEN` no `.env`. Retorna o `message_id`.

---

## 5. Scripts de mundo

### `scripts/tick.php` (a cada 10 min)
Ordem de execução:
1. `MotorEventos::resolverConfrontosExpirados()` — confrontos sem resposta do jogador são resolvidos pela IA.
2. `MotorEventos::rodarTick()` — passagem de tempo + conflitos entre ghouls (ghoul vs CCG → cria confronto pendente com botões).
3. `RotinasSobrevivencia` — cada ghoul caça/contrabandeia/recruta.
4. `SistemaMovimento::executarMovimentoIA()` — ghouls se movem.
5. `SistemaMovimento::aplicarPressaoZona()` — facções predadoras reduzem segurança dos vizinhos.
6. **Verificação de revolta** — distritos com `satisfacaoGeral >= 100` ou `< 20` têm o dominador expulso e quests resetadas.
7. `ClarimToquio::publicarEventosTick()` — publica manchetes.
8. `GerenciadorOperacoes::processarConclusoes()` + `ResolvedorOperacoes::resolver()` — conclui operações da CCG.

### `scripts/tick_semanal.php` (segunda 08:00 Brasília)
1. `SistemaFinanceiro::processarSemana()` — renda, dano colateral, cortes → publica boletim.
2. `SistemaMovimento::resetarPosicoes()` — todos voltam à base.
3. `AcoesSemanaRepository::resetarSemanasAntigas()` — zera limites semanais.
4. `GerenciadorEventosMapa` — expira eventos velhos e sorteia os novos.
5. `MovimentoRepository::limparAntigos()` — limpa log de movimentos antigo.
6. Publica aviso de nova semana.

### `scripts/reset.php` (manual, GM)
Reset completo de campanha: limpa histórico, recria as 5 facções ghoul, atualiza nomes dos 23 distritos, reconstrói adjacências, inicializa pilares (50/50/50), gera quests para todos os distritos e semeia o conhecimento CCG.

### `scripts/migration_v4.sql` (rodado uma única vez no banco)
Adiciona ao banco: colunas `seguranca`, `economia`, `suprimentos_pop`, `satisfacao_geral` (GENERATED) em `distritos`; tabelas `quests_distrito`, `confrontos_pendentes`, `conhecimento_ccg`; colunas `derrota_sinistra` e `escolha_manual` em `historico_combates`.

---

## 6. Comandos do `bot.php`

| Comando | O que faz | Custo / Limite |
|---|---|---|
| `!mapa` | Mapa tático de Tóquio baseado no **conhecimento da CCG** por distrito | — |
| `!posicao` | Onde a CCG está e para onde pode se mover | — |
| `!faccoes` | Status de todas as facções | — |
| `!quinques` | Arsenal de quinques da CCG | — |
| `!eventos` | Eventos de mapa ativos | — |
| `!operacoes` | Operações em andamento | — |
| `!orcamento` | Saldo + ações usadas na semana | — |
| `!informantes` | Lista informantes fixos | — |
| `!mover <id>` | Move a CCG (só para distrito adjacente) | — |
| `!investigar <id>` | Investiga distrito → eleva conhecimento (basico/bom/critico), revela quests se bom+ | ¥500, 2h, 3/semana |
| `!patrulha <id>` | Patrulha pacífica → segurança +15~25 | ¥300, 4h, 2/semana |
| `!campanha <id>` | Campanha de mídia → economia +20~35 | ¥1.500, 6h, 1/semana |
| `!abastecer <id>` | Comboio civil → suprimentos_pop +20~30 (CCG perde 15 suprimentos) | ¥800, 3h, 1/semana |
| `!pesquisar` | Cria um quinque no laboratório | ¥1.000, 6h, 1/semana |
| `!informe` | Intel via DM (30% chance de ser armadilha) | ¥750 |
| `!recrutar_informante <id>` | Informante fixo permanente no distrito | ¥2.000 |
| `!concluir_quest <dist> <quest>` | (Admin) marca quest como concluída; conquista automática se elegível | — |
| `!despacho <texto>` | (Admin) publica no Tokyo-GO | — |
| `!dano <valor>` | (Admin) registra dano colateral | — |
| `!ajuda_tokyo` | Lista de comandos | — |

**Validações aplicadas em toda operação:** distrito existe → está em alcance (adjacente à posição da CCG) → não atingiu o limite semanal → tem slot livre (máx. 2 operações, ou 1 se há agentes feridos) → não há operação igual pendente → tem saldo suficiente.

### Sistema de botões de combate
Quando uma facção ghoul ataca a CCG, em vez de resolver imediatamente:
1. Cria um `confronto_pendente` no banco.
2. Posta mensagem com 3 botões (Emboscada / Rush / Defesa) no canal de ops via REST API.
3. Membros com o cargo **Divisão Alfa** têm 10 minutos para clicar.
4. Se ninguém clicar, o próximo tick resolve automaticamente com tática de IA para a CCG.

O handler `INTERACTION_CREATE` no `bot.php` processa o clique, valida o cargo, resolve o confronto e responde na interação.

---

## 7. Sistema de conhecimento e conquista

### Conhecimento da CCG (`conhecimento_ccg`)
Cada distrito tem um nível que só sobe:
- **desconhecido** — `!mapa` mostra apenas `? #ID Nome`.
- **basico** — mostra facção dominante e nível de alerta.
- **bom** — mostra também os 3 pilares; revela quests do distrito.
- **critico** — mostra tudo (satisfação geral, barra de dominação, quests).

O nível sobe quando a operação `!investigar` conclui. Chiyoda (#15) nasce como `critico`.

### Conquista de distritos
Para a CCG (ou qualquer facção) conquistar um distrito:
1. `satisfacaoGeral >= 80`
2. Quest **principal** do distrito concluída pela facção.
3. Se houver quest secundária, ao menos 1 concluída pela facção.

Cumpridos os três, `DistritoRepository::conquistar()` é chamado (automático via `!concluir_quest` ou manualmente pelo GM).

### Revolta (expulsão automática)
No final de cada tick, se `satisfacaoGeral >= 100` ou `satisfacaoGeral < 20`:
- `DistritoRepository::expulsarDominador()` remove o dominador.
- Quests são resetadas e regeradas para o distrito.

---

## 8. Estilo de texto

Todo o texto enviado ao Discord está **sem emojis** e **com acentos**, mantendo as barras `█░` nos medidores. Isso vale para `bot.php`, scripts e os geradores de mensagem (`ResolvedorOperacoes`, `SistemaFinanceiro`, `ClarimToquio`).

---

## 9. Deploy (Oracle Cloud VM)

O bot roda em uma VM Ubuntu na Oracle Cloud Free Tier, compartilhada com o servidor Foundry VTT (sem conflito — o bot não serve HTTP).

- **Serviço:** `tokyo-bot.service` (systemd, reinicia automaticamente em falha).
- **Controle:** `bash ~/bot/controle.sh ligar|desligar|status` — gerencia o serviço e os crons juntos.
- **Auto-update:** `autoupdate.sh` (cron horário) — compara hash local vs GitHub, faz pull e reinicia se houver mudança.
- **Logs:** `~/bot/bot.log` (stdout/stderr do bot).
- **Certificado SSL do banco:** `~/bot/certificate/ca.pem` (nunca commitado no git, enviado via SCP).
- **Variáveis de ambiente:** `~/bot/.env` (nunca commitado). Inclui `DISCORD_OPS_CHANNEL_ID` para os botões de combate.
