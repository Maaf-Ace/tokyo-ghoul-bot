<?php

/**
 * Carregador simples de variáveis de ambiente a partir de um arquivo .env.
 * Evita dependência externa (vlucas/phpdotenv) caso você queira manter o projeto enxuto.
 * Se preferir a lib oficial, é só trocar a chamada Env::load() pelo Dotenv::createImmutable()->load().
 */
class Env {

	private static bool $carregado = false;

	public static function load(string $caminhoArquivo): void {
		if (self::$carregado) return;

		if (!file_exists($caminhoArquivo)) {
			throw new RuntimeException("Arquivo .env não encontrado em: $caminhoArquivo. Copie o .env.example e preencha os valores.");
		}

		$linhas = file($caminhoArquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		foreach ($linhas as $linha) {
			$linha = trim($linha);

			if ($linha === '' || str_starts_with($linha, '#')) continue;
			if (!str_contains($linha, '=')) continue;

			[$chave, $valor] = explode('=', $linha, 2);
			$chave = trim($chave);
			$valor = trim($valor);

			// Remove aspas envolventes, se houver
			$valor = trim($valor, "\"'");

			putenv("$chave=$valor");
			$_ENV[$chave] = $valor;
		}

		self::$carregado = true;
	}

	public static function get(string $chave, $padrao = null) {
		$valor = $_ENV[$chave] ?? getenv($chave);
		return ($valor === false || $valor === null) ? $padrao : $valor;
	}

	/** Versão que lança erro se a variável obrigatória não existir. Use para coisas como token/senha. */
	public static function getOrFail(string $chave): string {
		$valor = self::get($chave);
		if ($valor === null || $valor === '') {
			throw new RuntimeException("Variável de ambiente obrigatória '$chave' não definida no .env");
		}
		return $valor;
	}
}
