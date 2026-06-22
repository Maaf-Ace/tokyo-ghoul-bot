# Documentação do Bot de Tóquio (CCG Esquadrão Zero)

Referência das principais funções do sistema — o que cada peça faz e como tudo se conecta.

---

## 1. Visão geral do fluxo

O sistema tem **3 pontos de entrada** que rodam de forma independente:

| Arquivo | Quando roda | O que faz |
|---|---|---|
| `bot.php` | 24/7 (worker) | Escuta mensagens do Discord e responde aos comandos `!...` dos jogadores. |
| `scripts/tick.php` | A cada 10 min (cron) | "Pulso do mundo": faz as facções viverem, se moverem, lutarem e conclui operações. |
| `scripts/tick_semanal.php` | Segunda 08:00 (cron) | "Virada de semana": paga renda, reseta posições/limites, sorteia eventos. |

Os três incluem o `bootstrap.php`, que carrega todas as classes e o `.env`.

**Camadas (padrão Repository):**
- **Models** (`src/Models/`) — representam uma linha do banco + regras próprias da entidade. Não acessam o banco.
- **Repositories** (`src/Repositories/`) — única camada que lê/grava no banco (SQL).
- **Game** (`src/Game/`) — a lógica/regras do jogo. Usa os Repositories para persistir.

---

## 2. Models (`src/Models/`)

### `Faccao.php`
Representa uma facção (CCG ou ghoul). Campos: poder militar, suprimentos, fome, agressividade, sigilo, nível de alerta, **posição atual** e **agentes feridos até**.
- `fromArray($linha)` — cria a Facção a partir de uma linha do banco.
- `agentesFeridados()` — retorna `true` se os agentes da CCG ainda estão fora de ação (limita a 1 operação simultânea).
- `passarOTempo()` — aumenta a fome (e, se a fome ≥ 80, sobe agressividade e derruba sigilo). Não afeta a CCG.
- `aplicarInstintoSobrevivencia()` — facção com poder militar baixo ganha sigilo passivamente.

### `Distrito.php`
Representa um dos 23 distritos. Campos: nome, bônus de domínio, facção dominante, **apoio civil**, nível de alerta, nível de dominação, status de guerra.
- `aplicarDanoDominacao($dano)` — reduz a dominação; abaixo de 50% vira "em_disputa".
- `bonusAtivo()` — diz se o bônus do distrito está valendo (pacificado e dominação ≥ 50%).

### `Operacao.php`
Representa uma operação da CCG agendada no tempo (investigação, patrulha, etc.). Campos: tipo, distrito-alvo, `data_fim`, status, resultado, quem solicitou.
- `jaConcluiu()` — diz se o prazo (`data_fim`) já venceu.

### `EventoMapa.php` e `Quinque.php`
Models simples de dados (evento de mapa ativo e arma quinque criada no laboratório).

---

## 3. Repositories (`src/Repositories/`)

### `FaccaoRepository.php`
- `buscarPorId($id)`, `listarTodas()`, `listarFaccoesGhoul()` (todas menos a CCG).
- `criar()`, `atualizar()`, `salvar()` (decide entre criar/atualizar), `deletar()`.

### `DistritoRepository.php`
- `buscarPorId($id)`, `listarTodos()`, `listarPorFaccao($id)`.
- `criar()`, `atualizar()`, `salvar()`.

### `OperacaoRepository.php`
- `criar()`, `listarPendentes()`, `listarProntasParaConcluir()` (prazo vencido).
- `temPendente($tipo, $faccao, $distrito)` — evita duplicar a mesma operação.
- `marcarConcluida($id, $resultado)`.

### `FinancasCCGRepository.php`
Controla o dinheiro da CCG (guardado na própria tabela `faccoes`).
- `getOrcamento()`, `getRendaBase()`, `getDanoColateralPendente()`.
- `ajustarOrcamento($delta)` — soma/subtrai do saldo (nunca abaixo de 0).
- `adicionarDanoColateral()`, `zerarDanoColateral()`, `reduzirRendaBase()`.
- `registrarSemana(...)` — grava o histórico financeiro semanal.

### `AdjacenciaRepository.php`  *(novo)*
Controla quais distritos fazem fronteira.
- `saoAdjacentes($a, $b)` — `true` se dá para ir de um ao outro (ou se for o mesmo).
- `getAdjacentes($id)` — lista os IDs vizinhos de um distrito.

### `AcoesSemanaRepository.php`  *(novo)*
Controla os **limites semanais** de ações da CCG.
- `getQuantidade($faccao, $tipo)` — quantas vezes a ação foi feita esta semana.
- `verificarLimite($faccao, $tipo, $limite)` — `true` se ainda pode fazer mais uma.
- `incrementar($faccao, $tipo)` — conta +1 ação.
- `resetarSemanasAntigas()` — apaga contadores de semanas passadas (rodado no tick semanal).
- `resumoCCG()` — devolve quantas ações de cada tipo a CCG já usou (para `!orcamento`).

### `MovimentoRepository.php`  *(novo)*
Registra o **log de movimentos** das facções (a base da detecção por investigação).
- `registrar($faccao, $origem, $destino, $motivo)`.
- `listarPorDistrito($id, $horas)` — movimentos recentes num distrito.
- `listarDaFaccao($id, $horas)`, `ultimoMovimento($id)`.
- `limparAntigos($dias)` — limpeza periódica do log.

### `DiplomaciaRepository.php` (contém também `HistoricoCombateRepository`)
- Diplomacia: `saoAliadas()` (afinidade entre facções).
- Histórico de combate: `registrar()`, `buscarHistoricoDaFaccao()`, `getMemoriaTaticaDaFaccao()` (usado pela IA para "counterar" táticas).

---

## 4. Lógica do jogo (`src/Game/`)

### `MotorEventos.php` — "Cérebro do submundo"
É quem faz as facções ghoul guerrearem entre si.
- `escolherTatica($faccao, $memoriaInimigo)` — escolhe emboscada/rush/defesa por pesos (sigilo, agressividade) + tática favorita + tentativa de counterar o inimigo com base no histórico.
- `calcularVantagem($a, $b)` — pedra-papel-tesoura: emboscada > rush > defesa > emboscada (±15).
- `resolverConfronto($atacante, $defensor)` — resolve a luta (poder + d20 + tática + maestria), aplica desgaste, sigilo e persiste tudo + histórico.
- `tentarIniciarConflito($faccao, $todas, $chance)` — sorteia se a facção ataca alguém (a CCG nunca inicia; não ataca aliados).
- `rodarTick()` — roda um ciclo completo: passa o tempo em todas as ghoul e dá chance de conflito. **Chamado pelo `tick.php`.**

### `RotinasSobrevivencia.php` — rotina autônoma das ghouls
Cada tick, cada facção ghoul faz **uma** ação de sobrevivência.
- `executarParaFaccao($faccao)` — escolhe e executa a ação, salvando o resultado.
- `escolherAcao()` — peso por necessidade: fome alta → **caçar**; suprimentos baixos → **contrabando**; poder baixo → **recrutamento**.
- `cacar()` — reduz fome, mas baixa o apoio civil e sobe o alerta do distrito.
- `contrabandear()` — ganha suprimentos, perde sigilo.
- `recrutar()` — ganha poder militar, perde sigilo e ganha fome.

### `SistemaMovimento.php` — movimento e detecção  *(novo)*
- `moverCCG($destino)` — move o "boneco" da CCG. **Valida que o destino é adjacente.** Registra no log.
- `executarMovimentoIA()` — move as facções ghoul automaticamente (40% de chance por tick). **Chamado pelo `tick.php`.**
- `decidirMovimento()` / `escolherMotivoMovimento()` — IA decide o motivo: retirada (poder ≤ 30), caça (fome ≥ 70), expansão (poder ≥ 70 e suprimentos ≥ 60) ou patrulha.
- `verificarDeteccao($distrito, $faccao)` — fórmula `d20 + (apoioCivil/10)` vs sigilo. Resultado: **total** (revela facção e motivo), **parcial** (só "atividade suspeita") ou **nenhum**.
- `formatarDeteccao()` — monta o texto de rastros que aparece na investigação.
- `aplicarPressaoZona()` — distritos vizinhos de território Aogiri perdem 2 de apoio civil por tick. **Chamado pelo `tick.php`.**
- `resetarPosicoes()` — devolve todas as facções para suas bases. **Chamado pelo `tick_semanal.php`.**

### `GerenciadorOperacoes.php` — ciclo de vida das operações
- `iniciarOperacao(...)` — cria uma operação agendada.
- `processarConclusoes()` — busca as que venceram o prazo, marca como concluídas e devolve a lista. **Chamado pelo `tick.php`.**

### `ResolvedorOperacoes.php` — resultado das operações da CCG
Pega cada operação concluída e gera a mensagem de resultado.
- `resolver($op)` — direciona para o tipo certo.
- `resolverInvestigacao()` — revela dados do distrito (alerta, controle, apoio civil, eventos); apoio civil ≥ 50% dá uma dica extra dos moradores.
- `resolverPatrulha()` — sobe apoio civil (+15~25) e reduz 1 nível de alerta.
- `resolverPesquisa()` — se a CCG venceu algum combate, **cria um Quinque** aleatório (tipo RC + nome + bônus).
- `resolverCampanha()` — sobe apoio civil (+20~35); a partir de 60% desbloqueia dicas espontâneas.

### `SistemaFinanceiro.php` — dinheiro da CCG
- `gastar($valor)` — debita do orçamento; devolve `false` (sem debitar) se faltar saldo. **Toda operação no `bot.php` passa por aqui.**
- `registrarDanoColateral($valor)` — agenda desconto para a semana seguinte.
- `processarSemana()` — paga renda, desconta dano colateral, aplica corte de financiamento. **Chamado pelo `tick_semanal.php`.**
- `calcularCorteFinanciamento()` — distritos com alerta ≥ 4 cortam ¥300; ≥ 3 cortam ¥100.
- `formatarRelatorio()` — monta o texto do `!orcamento`.

### `GerenciadorEventosMapa.php` — imprevistos semanais
Catálogo de 5 eventos: blecaute, protestos, surto de ghoul, reforço policial, mercado negro.
- `sortearEventosSemana()` — cria de 1 a 3 eventos aleatórios. **Chamado pelo `tick_semanal.php`.**
- `expirarEventos()` — encerra os que venceram.
- `temEvento()`, `listarAtivos()`.

### `ClarimToquio.php` — o "jornal" (webhooks do Discord)
Publica notícias em dois canais via webhook.
- `publicarEventosTick()` — vira combates e caçadas em manchetes no canal de notícias.
- `publicarEventoMapa()` — anuncia um evento de mapa.
- `publicarResultadoOperacao()` — posta resultado/boletim no canal de operações.
- `publicarManual()` — texto livre (usado pelo `!despacho`).

---

## 5. Scripts de mundo

### `scripts/tick.php` (a cada 10 min)
Ordem de execução:
1. `MotorEventos::rodarTick()` — passagem de tempo + conflitos entre ghouls.
2. `RotinasSobrevivencia` — cada ghoul caça/contrabandeia/recruta.
3. `SistemaMovimento::executarMovimentoIA()` — ghouls se movem.
4. `SistemaMovimento::aplicarPressaoZona()` — pressão Aogiri nos vizinhos.
5. `ClarimToquio::publicarEventosTick()` — publica as manchetes.
6. `GerenciadorOperacoes::processarConclusoes()` + `ResolvedorOperacoes::resolver()` — conclui operações da CCG e publica resultados.

### `scripts/tick_semanal.php` (segunda 08:00)
1. `SistemaFinanceiro::processarSemana()` — renda, dano colateral, cortes → publica boletim.
2. `SistemaMovimento::resetarPosicoes()` — todos voltam à base.
3. `AcoesSemanaRepository::resetarSemanasAntigas()` — zera limites semanais.
4. `GerenciadorEventosMapa` — expira eventos velhos e sorteia os novos.
5. `MovimentoRepository::limparAntigos()` — limpa log de movimentos antigo.
6. Publica aviso de nova semana.

---

## 6. Comandos do `bot.php`

| Comando | O que faz | Custo / Limite |
|---|---|---|
| `!mapa` | Status dos 23 distritos + posição da CCG | — |
| `!posicao` | Onde a CCG está e para onde pode se mover | — |
| `!faccoes` | Status de todas as facções | — |
| `!quinques` | Arsenal de quinques da CCG | — |
| `!eventos` | Eventos de mapa ativos | — |
| `!operacoes` | Operações em andamento | — |
| `!orcamento` | Saldo + ações usadas na semana | — |
| `!informantes` | Lista informantes fixos | — |
| `!mover <id>` | Move a CCG (só para distrito adjacente) | — |
| `!investigar <id>` | Investiga distrito (revela dados + rastros) | ¥500, 2h, 3/semana |
| `!patrulha <id>` | Patrulha pacífica (sobe apoio civil) | ¥300, 4h, 2/semana |
| `!campanha <id>` | Campanha de mídia | ¥1.500, 6h, 1/semana |
| `!pesquisar` | Cria um quinque no laboratório | ¥1.000, 6h, 1/semana |
| `!informe` | Intel via DM (30% chance de ser armadilha) | ¥750 |
| `!recrutar_informante <id>` | Informante fixo permanente no distrito | ¥2.000 |
| `!despacho <texto>` | (Admin) publica no Clarim | — |
| `!dano <valor>` | (Admin) registra dano colateral | — |
| `!ajuda_tokyo` | Lista de comandos | — |

**Validações aplicadas em toda operação:** distrito existe → está em alcance (adjacente à posição da CCG) → não atingiu o limite semanal → tem slot livre (máx. 2 operações, ou 1 se há agentes feridos) → não há operação igual pendente → tem saldo suficiente.

---

## 7. Estilo de texto

Todo o texto enviado ao Discord está **sem emojis** e **com acentos**, mantendo as barras `█░` nos medidores. Isso vale para `bot.php`, scripts e os três geradores de mensagem (`ResolvedorOperacoes`, `SistemaFinanceiro`, `ClarimToquio`).

## 8. Deploy 24/7 (Render free)

Para rodar no plano gratuito do Render, o `bot.php` faz duas coisas extras além de escutar o Discord:

1. **Keepalive HTTP** — sobe um mini-servidor na porta `$PORT` que responde "Tokyo bot online". Serve para o Render reconhecer o processo como Web Service e para o UptimeRobot pingar e evitar a hibernação.
2. **Ticks internos** — em vez de cron separado, o próprio bot dispara `scripts/tick.php` a cada 10 min e `scripts/tick_semanal.php` na segunda ~08h, cada um como processo filho (não trava o bot). O `tick_semanal.php` tem proteção contra rodar duas vezes na mesma semana (checa `financas_ccg` da data atual).

Arquivos de deploy: `Dockerfile`, `render.yaml`, `.dockerignore`. O certificado do banco entra como **Secret File** do Render em `/etc/secrets/ca.pem` (a variável `DB_SSL_CA` já aponta para lá).
