<?php

/**
 * reset.php — Reseta o banco para o estado inicial da nova campanha.
 *
 * ATENÇÃO: este script apaga histórico de combates, operações, movimentos,
 * facções ghoul e reconstrói distritos e adjacências do zero.
 * Execute apenas intencionalmente.
 *
 * Uso: php scripts/reset.php
 */

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../bootstrap.php';

$db = Database::getConnection();
$db->exec("SET FOREIGN_KEY_CHECKS = 0");

echo "[RESET] Iniciando reset do banco...\n";

// ─── 1. Limpa tabelas de histórico e estado ───────────────────────────────────
$tabelas = [
    'historico_combates',
    'operacoes_ativas',
    'diplomacia',
    'movimentos_faccoes',
    'acoes_semanais',
    'financas_ccg',
    'eventos_mapa',
    'missoes_urgentes',
    'informantes_fixos',
    'quinques',
];

foreach ($tabelas as $tabela) {
    $db->exec("DELETE FROM {$tabela}");
    echo "  [OK] {$tabela} limpa.\n";
}

// ─── 2. Remove todas as facções exceto CCG ────────────────────────────────────
$db->exec("DELETE FROM faccoes WHERE id != 'ccg'");
echo "  [OK] Faccoes ghoul removidas.\n";

// ─── 3. Atualiza CCG ──────────────────────────────────────────────────────────
$db->exec("UPDATE faccoes SET
    renda_base              = 5000,
    orcamento               = 5000,
    dano_colateral_pendente = 0,
    posicao_atual           = 15,
    distrito_base           = 15,
    agentes_feridos_ate     = NULL
WHERE id = 'ccg'");
echo "  [OK] CCG atualizada (renda 5000, orcamento 5000, base Chiyoda #15).\n";

// ─── 4. Insere as 5 novas facções ─────────────────────────────────────────────
$faccoes = [
    [
        'id'             => 'kurogami',
        'nome'           => 'Kurogami',
        'distrito_base'  => 14,
        'tatica_favorita'=> 'emboscada',
        'poder_militar'  => 75,
        'suprimentos'    => 60,
        'fome'           => 30,
        'agressividade'  => 65,
        'sigilo'         => 90,
        'postura_civis'  => 'predadora',
    ],
    [
        'id'             => 'shinku_hana',
        'nome'           => 'Shinku Hana',
        'distrito_base'  => 12,
        'tatica_favorita'=> 'defesa',
        'poder_militar'  => 55,
        'suprimentos'    => 70,
        'fome'           => 20,
        'agressividade'  => 35,
        'sigilo'         => 75,
        'postura_civis'  => 'protetora',
    ],
    [
        'id'             => 'mizu_no_rei',
        'nome'           => 'Mizu no Rei',
        'distrito_base'  => 5,
        'tatica_favorita'=> 'emboscada',
        'poder_militar'  => 45,
        'suprimentos'    => 85,
        'fome'           => 15,
        'agressividade'  => 25,
        'sigilo'         => 95,
        'postura_civis'  => 'indiferente',
    ],
    [
        'id'             => 'jishin',
        'nome'           => 'Jishin',
        'distrito_base'  => 18,
        'tatica_favorita'=> 'rush',
        'poder_militar'  => 60,
        'suprimentos'    => 40,
        'fome'           => 45,
        'agressividade'  => 85,
        'sigilo'         => 40,
        'postura_civis'  => 'predadora',
    ],
    [
        'id'             => 'kiba_no_ikari',
        'nome'           => 'Kiba no Ikari',
        'distrito_base'  => 22,
        'tatica_favorita'=> 'rush',
        'poder_militar'  => 50,
        'suprimentos'    => 35,
        'fome'           => 55,
        'agressividade'  => 90,
        'sigilo'         => 30,
        'postura_civis'  => 'predadora',
    ],
];

$sqlFaccao = "INSERT INTO faccoes
    (id, nome, distrito_base, tatica_favorita, poder_militar, suprimentos,
     fome, agressividade, sigilo, postura_civis, nivel_alerta, posicao_atual)
    VALUES
    (:id, :nome, :distrito_base, :tatica_favorita, :poder_militar, :suprimentos,
     :fome, :agressividade, :sigilo, :postura_civis, 1, :distrito_base)";

$stmt = $db->prepare($sqlFaccao);
foreach ($faccoes as $f) {
    $stmt->execute($f);
    echo "  [OK] Faccao '{$f['nome']}' inserida (base #{$f['distrito_base']}).\n";
}

// ─── 5. Atualiza nomes dos 23 distritos ──────────────────────────────────────
$nomes = [
     1 => 'Adachi',
     2 => 'Katsushika',
     3 => 'Edogawa',
     4 => 'Koto',
     5 => 'Sumida',
     6 => 'Taito',
     7 => 'Arakawa',
     8 => 'Bunkyo',
     9 => 'Kita',
    10 => 'Toshima',
    11 => 'Itabashi',
    12 => 'Nerima',
    13 => 'Nakano',
    14 => 'Shinjuku',
    15 => 'Chiyoda',
    16 => 'Chuo',
    17 => 'Minato',
    18 => 'Shibuya',
    19 => 'Suginami',
    20 => 'Setagaya',
    21 => 'Meguro',
    22 => 'Shinagawa',
    23 => 'Ota',
];

// Reseta dominação e pilares de todos os distritos (campanha nova)
$db->exec("UPDATE distritos SET faccao_dominante_id = NULL, nivel_dominacao = 0, status_guerra = 'em_disputa', nivel_alerta = 1, apoio_civil = 50, seguranca = 50, economia = 50, suprimentos_pop = 50");

$stmtNome = $db->prepare("UPDATE distritos SET nome = :nome WHERE id = :id");
foreach ($nomes as $id => $nome) {
    $stmtNome->execute(['nome' => $nome, 'id' => $id]);
}

// CCG começa dominando Chiyoda
$db->exec("UPDATE distritos SET faccao_dominante_id = 'ccg', nivel_dominacao = 100, status_guerra = 'pacificado' WHERE id = 15");

echo "  [OK] Nomes dos 23 distritos atualizados.\n";
echo "  [OK] Chiyoda (#15) definida como base dominada pela CCG.\n";

// ─── 6. Reconstrói adjacências ────────────────────────────────────────────────
$db->exec("DELETE FROM adjacencias");

// Pares bidirecionais conforme o mapa real de Tóquio
$pares = [
    [1,2],[1,7],[1,9],
    [2,3],[2,7],
    [3,4],[3,5],
    [4,5],[4,16],[4,17],
    [5,6],[5,7],[5,16],
    [6,7],[6,8],[6,15],
    [7,9],[7,10],
    [8,9],[8,10],[8,13],[8,15],
    [9,10],[9,11],
    [10,11],[10,13],[10,14],
    [11,12],[11,13],
    [12,13],[12,19],
    [13,14],[13,19],
    [14,15],[14,18],[14,21],
    [15,16],[15,17],
    [16,17],
    [17,18],[17,22],
    [18,19],[18,21],[18,22],
    [19,20],
    [20,21],[20,23],
    [21,22],[21,23],
    [22,23],
];

$stmtAdj = $db->prepare("INSERT INTO adjacencias (distrito_a, distrito_b) VALUES (:a, :b)");
foreach ($pares as [$a, $b]) {
    $stmtAdj->execute(['a' => $a, 'b' => $b]);
    $stmtAdj->execute(['a' => $b, 'b' => $a]);
}
echo "  [OK] " . count($pares) . " pares de adjacencia inseridos (" . (count($pares) * 2) . " registros bidirecionais).\n";

// ─── 7. Limpa e reseed de quests e conhecimento CCG ──────────────────────────
$db->exec("DELETE FROM quests_distrito");
$db->exec("DELETE FROM confrontos_pendentes");
$db->exec("DELETE FROM conhecimento_ccg");
echo "  [OK] quests_distrito, confrontos_pendentes e conhecimento_ccg limpos.\n";

$questRepo       = new QuestDistritoRepository();
$conhecimentoRepo = new ConhecimentoCCGRepository();

for ($id = 1; $id <= 23; $id++) {
    $questRepo->seedParaDistrito($id);
}
echo "  [OK] Quests (1 principal + 1 secundaria) geradas para os 23 distritos.\n";

$conhecimentoRepo->seedTodos();
echo "  [OK] Conhecimento CCG inicializado (desconhecido para todos; critico para Chiyoda #15).\n";

// ─── Finaliza ─────────────────────────────────────────────────────────────────
$db->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "\n[RESET] Concluido com sucesso.\n";
echo "  Faccoes: CCG + 5 ghoul\n";
echo "  Distritos: 23 (nomes atualizados, dominacao zerada exceto Chiyoda, pilares em 50)\n";
echo "  Adjacencias: " . count($pares) . " pares\n";
echo "  Quests: geradas para todos os distritos\n";
echo "  Conhecimento: seedado\n";
echo "  Historico: limpo\n";
