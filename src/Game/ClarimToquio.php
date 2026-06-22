<?php

/**
 * O "jornal" do bot. Formata eventos do mundo e os publica em canais Discord via webhook.
 * Usa dois webhooks: um para manchetes públicas (Clarim) e um para resultados de operações (Ops).
 */
class ClarimToquio {

	private string $webhookNoticias;
	private string $webhookOps;

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

	public function __construct(string $webhookNoticias = '', string $webhookOps = '') {
		$this->webhookNoticias = $webhookNoticias;
		$this->webhookOps      = $webhookOps;
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

		$corpo = "**[CLARIM DE TÓQUIO]**\n\n" . implode("\n\n", $manchetes);
		$this->postar($this->webhookNoticias, $corpo);
	}

	/** Publica um evento de mapa (blecaute, protestos, etc.) no canal de notícias. */
	public function publicarEventoMapa(EventoMapa $evento, Distrito $distrito): void {
		$expira = date('d/m H:i', strtotime($evento->dataFim));

		$mensagem = "**[CLARIM DE TÓQUIO]** | **{$evento->titulo}**\n"
		          . "Área afetada: **{$distrito->nome}**\n"
		          . "> _{$evento->descricao}_\n"
		          . "_Duração estimada até {$expira}._";

		$this->postar($this->webhookNoticias, $mensagem);
	}

	/** Publica o resultado de uma operação CCG (investigação, patrulha, etc.) no canal de ops. */
	public function publicarResultadoOperacao(string $mensagem): void {
		$this->postar($this->webhookOps, $mensagem);
	}

	/** Posta qualquer mensagem livre no canal de notícias (usado pelo !despacho do admin). */
	public function publicarManual(string $mensagem): void {
		$this->postar($this->webhookNoticias, $mensagem);
	}

	private function formatarCombate(array $ev): string {
		$manchete = self::MANCHETES_COMBATE[array_rand(self::MANCHETES_COMBATE)];
		return "{$manchete}\n"
		     . "As forças de **{$ev['vencedor']}** saíram vitoriosas do confronto.";
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
				'header'        => "Content-Type: application/json\r\nUser-Agent: ClarimBot/1.0\r\n",
				'content'       => $payload,
				'ignore_errors' => true,
				'timeout'       => 5,
			],
		]);
		@file_get_contents($url, false, $ctx);
	}
}
