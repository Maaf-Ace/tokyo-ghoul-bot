<?php

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Events;
use React\EventLoop\Loop;

include __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/bootstrap.php'; // Env, Database, Models, Repositories, MotorEventos, GerenciadorOperacoes

$discord = new Discord([
    'loop' => $loop = Loop::get(),
    'socket_options' => ['dns' => '8.8.8.8'],
    'token' => Env::getOrFail('DISCORD_TOKEN'),
    'loadAllMembers' => true, // Not recommended for bots in large guilds
    'storeMessages' => true, // <-- This is important
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT | Intents::GUILD_MEMBERS // You need at least the first two
]);

$discord->on('init', function (Discord $discord) {

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
        
        //fichas
        if (strtolower($message->content)=='!fichas' or strtolower($message->content)=='!ficha' or strtolower($message->content)=='!Fichas' or 
        strtolower($message->content)=='!Ficha') {
            $message->reply('https://drive.google.com/drive/u/1/folders/1q_io0TQbBJf1YwRArctdF-eCk7HoNWJQ');
        }

        //cardoso
        if (strtolower($message->content) == 'cardoso' or strtolower($message->content) == 'Cardoso') {
            $sexo = 'https://media.discordapp.net/attachments/748704973369638962/1038561914697035826/20221105_152654.jpg?ex=67989fe8&is=67974e68&hm=79f2e8247dbe3fdfe1b4f4f7d19f6350e0269e11c3ddd31496048907f28a6800&=&format=webp&width=503&height=671';
            $message->reply($sexo);
        }

        //bolo
        if (strtolower($message->content) == 'bolo') {
            $message->reply(':cake:');
        }

        if (strtolower($message->content) == '!loot') {
            $lootResultado = rand(1, 100) + 0.5 * rand(0, 1);

            if ($lootResultado <= 10) {
                $message->reply(':x: **[Sem Drop]** :x:');
            } else if ($lootResultado <= 50) {
                $message->reply(':white_circle: **[Loot Comum]** :white_circle:');
            } else if ($lootResultado <= 73) {
                $message->reply(':green_circle: **[Loot Incomum]** :green_circle:');
            } else if ($lootResultado <= 88) {
                $message->reply(':purple_circle: **[Loot Épico]** :purple_circle:');
            } else if ($lootResultado <= 95) {
                $message->reply(':yellow_circle: **[Loot Lendário]** :yellow_circle:');
            } else if ($lootResultado <= 99) {
                $message->reply(':blue_circle: **[Loot Mítico]** :blue_circle:');
            } else if ($lootResultado <= 100 && $lootResultado >= 99.5) {
                $message->reply(':cake: :red_circle: **[Loot Único]** :red_circle: :cake:');
            }
        }

        //Classes.

        $content = $message->content;

        /*if (strpos($content, '!') === 0 && $message->author->id == '242459562655875073') {
            if ($message->content != '!fichas' && $message->content != '!loot' && $message->content != '!contagem') {
                $classe = str_replace('!', '', $content);
        
                $query = "SELECT * FROM classe WHERE nome = '$classe';";
                $result = mysqli_query($conn, $query);
        
                if ($result) {
                    $row = mysqli_fetch_assoc($result);
                    $message->reply("Informações da classe: " . print_r($row, true));
                } else {
                    $message->reply("Erro na consulta: " . mysqli_error($conn));
                }
            }
        }     */   

        if($content == '!playerson' or $content == '!playeron') {
            $playerf = 800000;
            $playera = rand(1, 200000);
            $playerr = $playera+$playerf;

            $message->reply(<<<EOT
            **[Total de Players Online]**
            *Online:* **[$playerr]**
            EOT
            );

        }

        if (preg_match('/^(\d*)d(\d+)([\+\-](\d+))?$/', $content, $matches)) {
            $numDice = !empty($matches[1]) ? (int)$matches[1] : 1; // colocar 1 antes do D para ler
            $diceType = (int)$matches[2];
            $operator = isset($matches[3][0]) ? $matches[3][0] : '+'; // captura o operador (+ ou -)
            $extra = isset($matches[4]) ? (int)$matches[4] : 0;
        
            $rolls = [];
            for ($i = 0; $i < $numDice; $i++) {
                $rolls[] = rand(1, $diceType);
            }
        
            $format = "1d$diceType" . ($extra != 0 ? "$operator$extra" : "");
        
            $reply = "";
            foreach ($rolls as $roll) {
                if ($operator === '+') {
                    $total = $roll + $extra;
                } else {
                    $total = $roll - $extra;
                }
        
                $rollDisplay = ($roll == $diceType || $roll == 1) ? "**[$roll]**" : "[$roll]";
                $reply .= "`` $total ``  ⟵ $rollDisplay $format\n";
            }
        
            $message->reply($reply);
        }
        

        if ($content == 'miguel' or $content == 'Miguel' or $content == 'tsuki' or $content == 'Tsuki' 
        or $content == 'misay' or $content == 'Misay') 
        {
            $message->reply("https://media.discordapp.net/attachments/1214559782380372079/1341597661694005358/Imagem_do_WhatsApp_de_2025-02-17_as_16.41.30_3db34d80.jpg?ex=67b693b0&is=67b54230&hm=6b88eab721d9c832683a70cf93f12a9ab6e349d8c8493dcdbe76d5a108ffe6c7&=&format=webp");
        }

        if ($content == "att" && $message->author->id == '242459562655875073') {

            $texto = "**__Conquista Do Servidor__**\n\n> O Jogador DomQuixote conquistou a primeira classe de oficio. Parabéns pela conquista!\n\n||@everyone||";
            
            $channelId = "1341037966423756801";
            $channel = $discord->getChannel($channelId);
            $channel->sendMessage($texto);}
        
    });

});

$discord->run();