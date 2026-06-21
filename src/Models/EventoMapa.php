<?php

class EventoMapa {
	public int $id;
	public string $tipo;
	public string $titulo;
	public string $descricao;
	public int $distritoId;
	public string $efeitoTipo;
	public int $efeitoValor;
	public string $dataInicio;
	public string $dataFim;
	public string $status;

	public static function fromArray(array $linha): self {
		$obj = new self();
		$obj->id          = (int) $linha['id'];
		$obj->tipo        = $linha['tipo'];
		$obj->titulo      = $linha['titulo'];
		$obj->descricao   = $linha['descricao'] ?? '';
		$obj->distritoId  = (int) $linha['distrito_id'];
		$obj->efeitoTipo  = $linha['efeito_tipo'] ?? '';
		$obj->efeitoValor = (int) ($linha['efeito_valor'] ?? 0);
		$obj->dataInicio  = $linha['data_inicio'];
		$obj->dataFim     = $linha['data_fim'];
		$obj->status      = $linha['status'];
		return $obj;
	}
}
