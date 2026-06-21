<?php

/**
 * Model de Operação. Representa uma linha da tabela `operacoes_ativas`.
 * Sistema de tempo real: usa DATETIME (data_fim) ao invés de turnos.
 */
class Operacao {

	public ?string $id; // null até ser persistida (o repository gera o id)
	public string $tipo;
	public string $faccaoId;
	public int $distritoAlvo;
	public string $dataFim; // formato 'Y-m-d H:i:s'
	public string $status;  // 'pendente' | 'concluido'
	public ?string $resultado;            // mensagem formatada gerada ao concluir
	public ?string $solicitanteDiscordId; // ID do jogador que abriu a operação

	public function __construct(
		string $tipo,
		string $faccaoId,
		int $distritoAlvo,
		int $horasDuracao,
		?string $id = null,
		?string $dataFim = null,
		string $status = 'pendente',
		?string $resultado = null,
		?string $solicitanteDiscordId = null
	) {
		$this->id = $id ?? uniqid('op_');
		$this->tipo = $tipo;
		$this->faccaoId = $faccaoId;
		$this->distritoAlvo = $distritoAlvo;
		$this->status = $status;
		$this->resultado = $resultado;
		$this->solicitanteDiscordId = $solicitanteDiscordId;

		if ($dataFim !== null) {
			$this->dataFim = $dataFim;
		} else {
			$agora = new DateTime();
			$agora->modify("+$horasDuracao hours");
			$this->dataFim = $agora->format('Y-m-d H:i:s');
		}
	}

	public static function fromArray(array $linha): self {
		return new self(
			tipo: $linha['tipo'],
			faccaoId: $linha['faccao_id'],
			distritoAlvo: (int) $linha['distrito_alvo'],
			horasDuracao: 0,
			id: $linha['id'],
			dataFim: $linha['data_fim'],
			status: $linha['status'],
			resultado: $linha['resultado'] ?? null,
			solicitanteDiscordId: $linha['solicitante_discord_id'] ?? null
		);
	}

	/**
	 * Verifica (em memória) se a operação já deveria estar concluída.
	 * Não persiste — quem chama decide se grava via Repository.
	 */
	public function jaConcluiu(): bool {
		$agora = new DateTime();
		$fim = new DateTime($this->dataFim);
		return $agora >= $fim && $this->status === 'pendente';
	}
}
