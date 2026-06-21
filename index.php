<?php
echo "<h1>Simulador de Conflitos - Tokyo Ghoul</h1>";

// 1. Importa os moldes
require_once 'faccoes.php';
require_once 'MotorEventos.php';

// 2. Inicia o Motor
$motor = new MotorEventos();

// 3. Cria as Facções para o teste
// Construtor: id, nome, distrito, taticaFavorita, poder, suprimentos, fome, agressividade, sigilo
$aogiri = new Faccao('aogiri', 'Árvore Aogiri', 11, 'emboscada', 85, 40, 70, 75, 80);
$ccg = new Faccao('ccg', 'CCG - Esquadrão Zero', 1, 'defesa', 95, 90, 0, 50, 20);

echo "<p><strong>Atacante:</strong> {$aogiri->nome} (Poder: {$aogiri->poderMilitar})</p>";
echo "<p><strong>Defensor:</strong> {$ccg->nome} (Poder: {$ccg->poderMilitar})</p>";

// 4. Cria memórias falsas simulando o histórico do banco de dados
$memoriaCCG = ['emboscada' => 0, 'rush' => 1, 'defesa' => 5]; // CCG tem defendido muito
$memoriaAogiri = ['emboscada' => 4, 'rush' => 3, 'defesa' => 0]; // Aogiri ataca bastante

// 5. Roda a pancadaria!
echo "<hr><h2>Resultado do Combate:</h2>";
$resultado = $motor->resolverConfronto($aogiri, $ccg, $memoriaCCG, $memoriaAogiri);

// Exibe o array de resultado bonitinho na tela
echo "<pre>";
print_r($resultado);
echo "</pre>";

// 6. Verifica se as consequências (dano/roubo) foram aplicadas na classe
echo "<hr><h2>Status Pós-Combate:</h2>";
echo "Poder Militar Aogiri: " . $aogiri->poderMilitar . "<br>";
echo "Poder Militar CCG: " . $ccg->poderMilitar . "<br>";
echo "Suprimentos Aogiri: " . $aogiri->suprimentos . "<br>";
?>