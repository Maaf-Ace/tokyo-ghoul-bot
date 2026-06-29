<?php

/**
 * Conexão PDO centralizada (Singleton).
 * Toda classe que precisar falar com o banco usa Database::getConnection().
 * Isso evita abrir 15 conexões soltas pelo bot inteiro.
 */
class Database {

	private static ?PDO $instancia = null;

	public static function getConnection(): PDO {
		if (self::$instancia !== null) {
			try {
				self::$instancia->query('SELECT 1');
			} catch (PDOException) {
				self::$instancia = null;
			}
		}

		if (self::$instancia !== null) {
			return self::$instancia;
		}

		$host = Env::getOrFail('DB_HOST');
		$port = Env::get('DB_PORT', '3306');
		$nome = Env::getOrFail('DB_NAME');
		$user = Env::getOrFail('DB_USER');
		$pass = Env::getOrFail('DB_PASS');
		$sslCa = Env::get('DB_SSL_CA');

		$dsn = "mysql:host=$host;port=$port;dbname=$nome;charset=utf8mb4";

		$opcoes = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false, // usa prepared statements nativos -> mais seguro
		];

		// Aiven exige SSL. Se você tiver o ca.pem, isso valida o certificado de verdade.
		if ($sslCa && file_exists($sslCa)) {
			$opcoes[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
			$opcoes[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
		}

		try {
			self::$instancia = new PDO($dsn, $user, $pass, $opcoes);
			self::$instancia->exec("SET time_zone = '-03:00'");
		} catch (PDOException $e) {
			// Não vazamos a mensagem completa (pode conter dados sensíveis de conexão) em produção
			error_log('[Database] Falha na conexão: ' . $e->getMessage());
			throw new RuntimeException('Não foi possível conectar ao banco de dados.');
		}

		return self::$instancia;
	}

	/** Útil em scripts de longa duração (bot.php) caso a conexão caia e precise reconectar. */
	public static function reset(): void {
		self::$instancia = null;
	}
}
