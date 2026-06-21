<?php

class Quinque {
	public string $id;
	public string $nome;
	public string $tipoRc;       // bikaku | ukaku | rinkaku | koukaku
	public int    $bonusCombate;
	public string $descricao;
	public string $ghoulOrigem;
	public string $faccaoId;
	public string $dataCriacao;

	public static function fromArray(array $linha): self {
		$obj = new self();
		$obj->id           = $linha['id'];
		$obj->nome         = $linha['nome'];
		$obj->tipoRc       = $linha['tipo_rc'];
		$obj->bonusCombate = (int) $linha['bonus_combate'];
		$obj->descricao    = $linha['descricao'] ?? '';
		$obj->ghoulOrigem  = $linha['ghoul_origem'] ?? 'Desconhecido';
		$obj->faccaoId     = $linha['faccao_id'];
		$obj->dataCriacao  = $linha['data_criacao'];
		return $obj;
	}
}
