<?php

/**
 * reset_ghouls.php — Reseta somente os status das 5 facções ghoul.
 *
 * Não apaga histórico, movimentos ou distritos.
 * Reseta: fome, poder_militar, suprimentos, sigilo, agressividade, posicao_atual,
 *         agentes_feridos_ate e limpa operacoes_ghoul pendentes.
 *
 * Uso: php scripts/reset_ghouls.php
 */

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../bootstrap.php';

$db = Database::getConnection();

echo "[RESET GHOULS] Restaurando status das faccoes ghoul...\n";

$defaults = [
    'kurogami' => [
        'poder_militar' => 75, 'suprimentos' => 60, 'fome' => 30,
        'agressividade' => 65, 'sigilo' => 90, 'distrito_base' => 14,
    ],
    'shinku_hana' => [
        'poder_militar' => 55, 'suprimentos' => 70, 'fome' => 20,
        'agressividade' => 35, 'sigilo' => 75, 'distrito_base' => 12,
    ],
    'mizu_no_rei' => [
        'poder_militar' => 45, 'suprimentos' => 85, 'fome' => 15,
        'agressividade' => 25, 'sigilo' => 95, 'distrito_base' => 5,
    ],
    'jishin' => [
        'poder_militar' => 60, 'suprimentos' => 40, 'fome' => 45,
        'agressividade' => 85, 'sigilo' => 40, 'distrito_base' => 18,
    ],
    'kiba_no_ikari' => [
        'poder_militar' => 50, 'suprimentos' => 35, 'fome' => 55,
        'agressividade' => 90, 'sigilo' => 30, 'distrito_base' => 22,
    ],
];

$stmt = $db->prepare("
    UPDATE faccoes SET
        poder_militar       = :poder_militar,
        suprimentos         = :suprimentos,
        fome                = :fome,
        agressividade       = :agressividade,
        sigilo              = :sigilo,
        posicao_atual       = :distrito_base,
        agentes_feridos_ate = NULL
    WHERE id = :id
");

foreach ($defaults as $id => $v) {
    $stmt->execute(array_merge($v, ['id' => $id]));
    echo "  [OK] {$id}: fome={$v['fome']}, poder={$v['poder_militar']}, sig={$v['sigilo']}\n";
}

// Limpa operacoes_ghoul pendentes e weekly counters
$db->exec("DELETE FROM operacoes_ghoul WHERE concluida = 0");
$db->exec("DELETE FROM acoes_semanais WHERE faccao_id != 'ccg'");

echo "  [OK] operacoes_ghoul pendentes removidas.\n";
echo "  [OK] contadores semanais ghoul zerados.\n";
echo "\n[RESET GHOULS] Concluido. Posicoes resetadas para as bases.\n";
