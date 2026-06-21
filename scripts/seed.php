<?php

/**
 * scripts/seed.php — Popula o banco com os dados de teste descritos no GDD.
 * Rode manualmente uma vez: php scripts/seed.php
 * É seguro rodar de novo (usa salvar() = insert ou update).
 */

require_once __DIR__ . '/../bootstrap.php';

$faccaoRepo = new FaccaoRepository();
$distritoRepo = new DistritoRepository();

echo "Inserindo facções...\n";

$faccaoRepo->salvar(new Faccao(
	id: 'aogiri',
	nome: 'Árvore Aogiri',
	distritoBase: 11,
	taticaFavorita: 'emboscada',
	poderMilitar: 60,
	suprimentos: 50,
	fome: 20,
	agressividade: 70,
	sigilo: 60,
	posturaCivis: 'predadora'
));

$faccaoRepo->salvar(new Faccao(
	id: 'ccg',
	nome: 'CCG - Esquadrão Zero',
	distritoBase: 1,
	taticaFavorita: 'defesa',
	poderMilitar: 80,
	suprimentos: 100,
	fome: 0,
	agressividade: 40,
	sigilo: 30,
	posturaCivis: 'indiferente'
));

echo "Inserindo distritos...\n";

$distritoRepo->salvar(new Distrito(
	id: 1,
	nome: 'Chiyoda',
	bonusDominio: 'Ghouls perdem sigilo instantaneamente.',
	faccaoDominanteId: 'ccg',
	statusGuerra: 'pacificado'
));

$distritoRepo->salvar(new Distrito(
	id: 4,
	nome: 'Distrito 4',
	bonusDominio: 'Libera acesso ao mercado negro.',
	faccaoDominanteId: null,
	statusGuerra: 'pacificado'
));

$distritoRepo->salvar(new Distrito(
	id: 11,
	nome: 'Distrito 11',
	bonusDominio: '+20 de Poder Militar.',
	faccaoDominanteId: 'aogiri',
	nivelDominacao: 60,
	statusGuerra: 'em_disputa'
));

$distritoRepo->salvar(new Distrito(
	id: 13,
	nome: 'Distrito 13',
	bonusDominio: 'Aumenta agressividade em 30%.',
	faccaoDominanteId: 'aogiri',
	statusGuerra: 'pacificado'
));

$distritoRepo->salvar(new Distrito(
	id: 20,
	nome: 'Distrito 20',
	bonusDominio: 'Apoio civil sobe passivamente.',
	faccaoDominanteId: null,
	statusGuerra: 'pacificado'
));

echo "Seed concluído.\n";
