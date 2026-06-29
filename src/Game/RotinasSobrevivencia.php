<?php

/**
 * Gerencia as atividades agendadas das facções ghoul.
 *
 * Cada facção pode ter uma atividade pendente por vez. A atividade é agendada
 * com um timer; quando o timer expira, o próximo tick resolve os efeitos.
 *
 * Limites semanais variam por postura_civis:
 *   predadora  → caça intensa, pouca alimentação discreta
 *   indiferente→ balanceado
 *   protetora  → prefere alimentar (criminosos) em vez de caçar inocentes
 */
class RotinasSobrevivencia {

    private DistritoRepository      $distritoRepo;
    private FaccaoRepository        $faccaoRepo;
    private OperacaoGhoulRepository $opGhoulRepo;
    private AdjacenciaRepository    $adjRepo;

    // Duração em horas — maior = mais chance de interceptação pela CCG
    private const DURACOES = [
        'alimentar'     => 4.0,
        'cacar'         => 6.0,
        'contrabandear' => 6.0,
        'recrutar'      => 8.0,
    ];

    // Limites semanais por postura_civis — ajustados para durações maiores
    private const LIMITES = [
        'predadora'   => ['alimentar' =>  5, 'cacar' => 14, 'recrutar' => 7, 'contrabandear' =>  7],
        'indiferente' => ['alimentar' => 10, 'cacar' => 10, 'recrutar' => 7, 'contrabandear' => 10],
        'protetora'   => ['alimentar' => 14, 'cacar' =>  5, 'recrutar' => 7, 'contrabandear' =>  5],
    ];

    public function __construct(
        ?DistritoRepository      $distritoRepo = null,
        ?FaccaoRepository        $faccaoRepo   = null,
        ?OperacaoGhoulRepository $opGhoulRepo  = null,
        ?AdjacenciaRepository    $adjRepo      = null
    ) {
        $this->distritoRepo = $distritoRepo ?? new DistritoRepository();
        $this->faccaoRepo   = $faccaoRepo   ?? new FaccaoRepository();
        $this->opGhoulRepo  = $opGhoulRepo  ?? new OperacaoGhoulRepository();
        $this->adjRepo      = $adjRepo      ?? new AdjacenciaRepository();
    }

    /**
     * Agenda uma atividade para a facção, se ela não tiver uma pendente e
     * ainda tiver cota semanal disponível.
     * Retorna os dados do agendamento, ou null se nada foi agendado.
     */
    public function agendarParaFaccao(Faccao $faccao): ?array {
        if ($this->opGhoulRepo->temPendente($faccao->id)) return null;

        $postura = $faccao->posturaCivis ?? 'indiferente';
        $limites = self::LIMITES[$postura] ?? self::LIMITES['indiferente'];

        $acao = $this->escolherAcao($faccao, $limites);
        if ($acao === null) return null;

        $id       = bin2hex(random_bytes(8));
        $duracao  = self::DURACOES[$acao];
        $distId   = ($acao === 'recrutar') ? 0 : $this->escolherDistrito($faccao);

        $this->opGhoulRepo->criar($id, $faccao->id, $acao, $distId, $duracao);

        return [
            'faccao'      => $faccao->nome,
            'faccao_id'   => $faccao->id,
            'tipo'        => $acao,
            'distrito_id' => $distId,
        ];
    }

    /**
     * Resolve todas as operações ghoul cujo prazo já venceu.
     * Retorna array de resultados compatíveis com ClarimToquio::publicarEventosTick().
     */
    public function resolverConcluidas(): array {
        $prontas    = $this->opGhoulRepo->listarProntasParaConcluir();
        $resultados = [];

        foreach ($prontas as $op) {
            // Já interceptada: efeitos cancelados, apenas fecha
            if ($op['interceptada']) {
                $this->opGhoulRepo->marcarConcluida($op['id'], 'interceptada');
                continue;
            }

            $faccao = $this->faccaoRepo->buscarPorId($op['faccao_id']);
            if (!$faccao) {
                $this->opGhoulRepo->marcarConcluida($op['id'], 'faccao_nao_encontrada');
                continue;
            }

            $resultado = match ($op['tipo']) {
                'alimentar'     => $this->resolverAlimentar($faccao, (int) $op['distrito_id']),
                'cacar'         => $this->resolverCacar($faccao, (int) $op['distrito_id']),
                'contrabandear' => $this->resolverContrabandear($faccao, (int) $op['distrito_id']),
                'recrutar'      => $this->resolverRecruta($faccao),
                default         => null,
            };

            if ($resultado) {
                $this->opGhoulRepo->marcarConcluida($op['id'], $resultado['descricao']);
                $resultados[] = $resultado;
            }
        }

        return $resultados;
    }

    // ── Escolha de distrito (atual ou adjacente) ──────────────────────────────

    private function escolherDistrito(Faccao $faccao): int {
        $adjacentes = $this->adjRepo->getAdjacentes($faccao->posicaoAtual);
        $opcoes     = array_merge([(int) $faccao->posicaoAtual], $adjacentes);
        return (int) $opcoes[array_rand($opcoes)];
    }

    // ── Escolha de ação ───────────────────────────────────────────────────────

    private function escolherAcao(Faccao $faccao, array $limites): ?string {
        // Remove tipos esgotados na semana
        $disponiveis = array_filter(
            $limites,
            fn($limite, $tipo) => $this->opGhoulRepo->contarSemana($faccao->id, $tipo) < $limite,
            ARRAY_FILTER_USE_BOTH
        );
        if (empty($disponiveis)) return null;

        // Pesos por estado da facção
        $pesos = [];
        if (isset($disponiveis['alimentar']))
            $pesos['alimentar']     = (int) ($faccao->fome * 0.5);
        if (isset($disponiveis['cacar']))
            $pesos['cacar']         = (int) ($faccao->fome * 1.5);
        if (isset($disponiveis['contrabandear']))
            $pesos['contrabandear'] = max(1, 100 - $faccao->suprimentos);
        if (isset($disponiveis['recrutar']))
            $pesos['recrutar']      = max(1, 100 - $faccao->poderMilitar);

        if (empty($pesos)) return null;

        $total = array_sum($pesos);
        $roll  = rand(1, $total);
        $acum  = 0;
        foreach ($pesos as $acao => $peso) {
            $acum += $peso;
            if ($roll <= $acum) return $acao;
        }
        return array_key_first($pesos);
    }

    // ── Resolução por tipo ────────────────────────────────────────────────────

    private function resolverAlimentar(Faccao $faccao, int $distritoId): array {
        $reducaoFome = rand(10, 15);
        $faccao->fome = max(0, $faccao->fome - $reducaoFome);
        $this->faccaoRepo->atualizar($faccao);

        $distrito = $this->distritoRepo->buscarPorId($distritoId);
        if ($distrito) {
            $distrito->seguranca = max(0, $distrito->seguranca - rand(2, 4));
            $this->distritoRepo->atualizar($distrito);
        }

        return [
            'tipo'        => 'sobrevivencia',
            'acao'        => 'alimentar',
            'faccao'      => $faccao->nome,
            'faccao_id'   => $faccao->id,
            'distrito_id' => $distritoId,
            'descricao'   => "{$faccao->nome} se alimentou discretamente em #{$distritoId}. Fome -{$reducaoFome}.",
        ];
    }

    private function resolverCacar(Faccao $faccao, int $distritoId): array {
        $reducaoFome   = rand(20, 30);
        $aumentoAlerta = rand(1, 3);

        $faccao->fome          = max(0, $faccao->fome - $reducaoFome);
        $faccao->agressividade = min(100, $faccao->agressividade + 2);
        $this->faccaoRepo->atualizar($faccao);

        $distrito = $this->distritoRepo->buscarPorId($distritoId);
        if ($distrito) {
            $distrito->seguranca   = max(0, $distrito->seguranca - rand(6, 10));
            $distrito->economia    = max(0, $distrito->economia - 5);
            $distrito->nivelAlerta = min(5, $distrito->nivelAlerta + $aumentoAlerta);
            $this->distritoRepo->atualizar($distrito);
        }

        return [
            'tipo'        => 'sobrevivencia',
            'acao'        => 'caca',
            'faccao'      => $faccao->nome,
            'faccao_id'   => $faccao->id,
            'distrito_id' => $distritoId,
            'descricao'   => "{$faccao->nome} cacou em #{$distritoId}. Fome -{$reducaoFome}. Seguranca -8. Alerta +{$aumentoAlerta}.",
        ];
    }

    private function resolverContrabandear(Faccao $faccao, int $distritoId): array {
        $ganho       = rand(15, 30);
        $perdaSigilo = rand(5, 15);

        $faccao->suprimentos = min(100, $faccao->suprimentos + $ganho);
        $faccao->sigilo      = max(0, $faccao->sigilo - $perdaSigilo);
        $this->faccaoRepo->atualizar($faccao);

        $distrito = $this->distritoRepo->buscarPorId($distritoId);
        if ($distrito) {
            $distrito->economia = max(0, $distrito->economia - 10);
            $this->distritoRepo->atualizar($distrito);
        }

        return [
            'tipo'        => 'sobrevivencia',
            'acao'        => 'contrabando',
            'faccao'      => $faccao->nome,
            'faccao_id'   => $faccao->id,
            'distrito_id' => $distritoId,
            'descricao'   => "{$faccao->nome} contrabandeou em #{$distritoId}. Suprimentos +{$ganho}, Sigilo -{$perdaSigilo}.",
        ];
    }

    private function resolverRecruta(Faccao $faccao): array {
        $ganhoPoder  = rand(5, 15);
        $perdaSigilo = rand(8, 20);
        $aumentoFome = rand(3, 8);

        $faccao->poderMilitar = min(100, $faccao->poderMilitar + $ganhoPoder);
        $faccao->sigilo       = max(0, $faccao->sigilo - $perdaSigilo);
        $faccao->fome         = min(100, $faccao->fome + $aumentoFome);
        $this->faccaoRepo->atualizar($faccao);

        return [
            'tipo'        => 'sobrevivencia',
            'acao'        => 'recrutamento',
            'faccao'      => $faccao->nome,
            'faccao_id'   => $faccao->id,
            'distrito_id' => null,
            'descricao'   => "{$faccao->nome} recrutou membros. Poder +{$ganhoPoder}, Sigilo -{$perdaSigilo}, Fome +{$aumentoFome}.",
        ];
    }
}
