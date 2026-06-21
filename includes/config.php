<?php

/**
 * includes/config.php
 *
 * Ponto de conexão com o banco, mantido por compatibilidade com código
 * que já faz require_once 'includes/config.php' e espera uma variável $pdo pronta.
 *
 * A configuração real (host, user, senha) NÃO fica mais aqui — fica no .env
 * na raiz do projeto. Isso só carrega o bootstrap e expõe $pdo.
 *
 * Se o arquivo que está incluindo este aqui já carregou o bootstrap.php antes,
 * isso não duplica nada (Env::load tem proteção contra carregar 2x).
 */

require_once __DIR__ . '/../bootstrap.php';

// Disponibiliza $pdo para qualquer script legado que espera essa variável global
$pdo = Database::getConnection();
