<?php

/**
 * Bootstrap central do projeto. Inclua este arquivo no topo de qualquer script
 * (bot.php, tick.php, tick_semanal.php, etc) e tudo fica disponível.
 */

require_once __DIR__ . '/src/Env.php';

Env::load(__DIR__ . '/.env');

require_once __DIR__ . '/src/Database.php';

// Models
require_once __DIR__ . '/src/Models/Faccao.php';
require_once __DIR__ . '/src/Models/Distrito.php';
require_once __DIR__ . '/src/Models/Operacao.php';
require_once __DIR__ . '/src/Models/EventoMapa.php';
require_once __DIR__ . '/src/Models/Quinque.php';

// Repositories
require_once __DIR__ . '/src/Repositories/FaccaoRepository.php';
require_once __DIR__ . '/src/Repositories/DistritoRepository.php';
require_once __DIR__ . '/src/Repositories/OperacaoRepository.php';
require_once __DIR__ . '/src/Repositories/DiplomaciaRepository.php';
require_once __DIR__ . '/src/Repositories/EventoMapaRepository.php';
require_once __DIR__ . '/src/Repositories/QuinqueRepository.php';
require_once __DIR__ . '/src/Repositories/FinancasCCGRepository.php';

// Game
require_once __DIR__ . '/src/Game/MotorEventos.php';
require_once __DIR__ . '/src/Game/GerenciadorOperacoes.php';
require_once __DIR__ . '/src/Game/RotinasSobrevivencia.php';
require_once __DIR__ . '/src/Game/GerenciadorEventosMapa.php';
require_once __DIR__ . '/src/Game/SistemaFinanceiro.php';
require_once __DIR__ . '/src/Game/ClarimToquio.php';
require_once __DIR__ . '/src/Game/ResolvedorOperacoes.php';
