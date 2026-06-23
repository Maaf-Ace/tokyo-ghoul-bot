<?php

/**
 * O "jornal" do bot. Formata eventos do mundo e os publica em canais Discord via webhook.
 * Usa dois webhooks: um para manchetes públicas (Tokyo-GO) e um para resultados de operações (Ops).
 * Para alertas de confronto com botões, usa a REST API do Discord diretamente.
 */
class ClarimToquio {

	private string $webhookNoticias;
	private string $webhookOps;
	private string $botToken;
	private string $opsChannelId;

	private const MANCHETES_COMBATE = [
		"Um confronto sangrento foi registrado entre facções rivais. Moradores relatam gritos e explosões na região.",
		"Forças desconhecidas se enfrentaram em combate aberto. A CCG ainda não se pronunciou.",
		"Uma guerra de gangues parece ter eclodido nas sombras. Populares pedem mais segurança.",
		"Relatos de batalha entre grupos armados chegaram à redação. Detalhes permanecem obscuros.",
		"Explosões foram ouvidas pela madrugada. Nenhum corpo foi localizado pelas equipes de resgate.",
	];

	private const MANCHETES_CACA = [
		"Moradores do **{distrito}** reportam atividade suspeita à noite. Recomenda-se cautela.",
		"Alertas de segurança no **{distrito}**: desaparecimentos nos registros policiais aumentaram.",
		"Incidentes bizarros no **{distrito}**: vestígios encontrados em becos. Autoridades investigam.",
		"Testemunhas no **{distrito}** relatam figuras sinistras em áreas ermas.",
	];

	private const DRAMATICOS_SINISTRA = [
		"**[DERROTA SINISTRA]** O perdedor foi esmagado sem clemência. Suprimentos saqueados, moral destruída.",
		"**[DERROTA SINISTRA]** Uma humilhação total. Os vencidos mal tiveram tempo de recuar antes de serem despojados.",
		"**[DERROTA SINISTRA]** Dizimados com brutalidade implacável. Nada restou além de escombros e silêncio.",
	];

	public function __construct(
		string $webhookNoticias = '',
		string $webhookOps      = '',
		string $botToken        = '',
		string $opsChannelId    = ''
	) {
		$this->webhookNoticias = $webhookNoticias;
		$this->webhookOps      = $webhookOps;
		$this->botToken        = $botToken;
		$this->opsChannelId    = $opsChannelId;
	}

	/** Publica eventos do tick (combates, caçadas) como manchetes no canal de notícias. */
	public function publicarEventosTick(array $eventos, DistritoRepository $distritoRepo): void {
		$manchetes = [];

		foreach ($eventos as $ev) {
			if ($ev['tipo'] === 'combate' && rand(1, 100) <= 70) {
				$manchetes[] = $this->formatarCombate($ev);
			} elseif ($ev['tipo'] === 'sobrevivencia' && ($ev['acao'] ?? '') === 'caca') {
				$manchetes[] = $this->formatarCacada($ev, $distritoRepo);
			}
		}

		if (empty($manchetes)) return;

		$corpo = "**[TOKYO-GO]**\n\n" . implode("\n\n", $manchetes);
		$this->postar($this->webhookNoticias, $corpo);
	}

	/** Publica um evento de mapa (blecaute, protestos, etc.) no canal de notícias. */
	public function publicarEventoMapa(EventoMapa $evento, Distrito $distrito): void {
		$expira = date('d/m H:i', strtotime($evento->dataFim));

		$mensagem = "**[TOKYO-GO]** | **{$evento->titulo}**\n"
		          . "Área afetada: **{$distrito->nome}**\n"
		          . "> _{$evento->descricao}_\n"
		          . "_Duração estimada até {$expira}._";

		$this->postar($this->webhookNoticias, $mensagem);
	}

	/** Publica o resultado de uma operação CCG no canal de ops. */
	public function publicarResultadoOperacao(string $mensagem): void {
		$this->postar($this->webhookOps, $mensagem);
	}

	/** Posta qualquer mensagem livre no canal de notícias (usado pelo !despacho do admin). */
	public function publicarManual(string $mensagem): void {
		$this->postar($this->webhookNoticias, $mensagem);
	}

	/**
	 * Posta alerta de confronto com botões de táticas no canal de ops via REST.
	 * Retorna o message_id ou null se falhar.
	 */
	public function publicarAlertaConfronto(string $confrontoId, Faccao $atacante, int $distritoId, string $taticaAtacante): ?string {
		if (empty($this->botToken) || empty($this->opsChannelId)) return null;

		$trad   = ['emboscada' => 'Emboscada', 'rush' => 'Rush', 'defesa' => 'Defesa'];
		$tAtc   = $trad[$taticaAtacante] ?? ucfirst($taticaAtacante);

		$payload = json_encode([
			'content'    => "**[ALERTA DE CONFRONTO — TOKYO-GO]**\n"
			              . "**{$atacante->nome}** avança contra a CCG no **Distrito #{$distritoId}**!\n"
			              . "Tática inimiga detectada: **{$tAtc}**\n"
			              . "**Divisão Alfa** — Escolha sua tática de resposta nos próximos 10 minutos!\n"
			              . "> Se nenhuma resposta for dada, a CCG combaterá automaticamente.",
			'components' => [[
				'type'       => 1,
				'components' => [
					['type' => 2, 'style' => 1, 'label' => 'Emboscada', 'custom_id' => "tatica_{$confrontoId}_emboscada"],
					['type' => 2, 'style' => 4, 'label' => 'Rush',      'custom_id' => "tatica_{$confrontoId}_rush"],
					['type' => 2, 'style' => 2, 'label' => 'Defesa',    'custom_id' => "tatica_{$confrontoId}_defesa"],
				],
			]],
		], JSON_UNESCAPED_UNICODE);

		$url = "https://discord.com/api/v10/channels/{$this->opsChannelId}/messages";
		$ctx = stream_context_create([
			'http' => [
				'method'        => 'POST',
				'header'        => "Content-Type: application/json\r\nAuthorization: Bot {$this->botToken}\r\n",
				'content'       => $payload,
				'ignore_errors' => true,
				'timeout'       => 5,
			],
		]);
		$resp = @file_get_contents($url, false, $ctx);
		if ($resp) {
			$data = json_decode($resp, true);
			return $data['id'] ?? null;
		}
		return null;
	}

	private function formatarCombate(array $ev): string {
		$manchete = self::MANCHETES_COMBATE[array_rand(self::MANCHETES_COMBATE)];
		$texto    = "{$manchete}\nAs forças de **{$ev['vencedor']}** saíram vitoriosas do confronto.";

		if (!empty($ev['derrota_sinistra'])) {
			$texto .= "\n" . self::DRAMATICOS_SINISTRA[array_rand(self::DRAMATICOS_SINISTRA)];
		}

		return $texto;
	}

	private function formatarCacada(array $ev, DistritoRepository $distritoRepo): string {
		$distritoId = $ev['distrito_id'] ?? 0;
		$distrito   = $distritoId ? $distritoRepo->buscarPorId($distritoId) : null;
		$nome       = $distrito ? $distrito->nome : 'área não identificada';
		$template   = self::MANCHETES_CACA[array_rand(self::MANCHETES_CACA)];
		return str_replace('{distrito}', $nome, $template);
	}

	private function postar(string $url, string $conteudo): void {
		if (empty($url)) return;

		$payload = json_encode(['content' => $conteudo], JSON_UNESCAPED_UNICODE);
		$ctx = stream_context_create([
			'http' => [
				'method'        => 'POST',
				'header'        => "Content-Type: application/json\r\nUser-Agent: TokyoGO-Bot/1.0\r\n",
				'content'       => $payload,
				'ignore_errors' => true,
				'timeout'       => 5,
			],
		]);
		@file_get_contents($url, false, $ctx);
	}
}
