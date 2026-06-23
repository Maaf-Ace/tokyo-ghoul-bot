<?php

class ConfruntoPendenteRepository {

	private PDO $db;

	public function __construct(?PDO $db = null) {
		$this->db = $db ?? Database::getConnection();
	}

	public function criar(
		string $id,
		string $atacanteId,
		int $distritoId,
		string $taticaAtacante,
		string $messageId,
		string $expiraEm
	): void {
		$sql = "INSERT INTO confrontos_pendentes (id, atacante_id, distrito_id, tatica_atacante, message_id, expira_em)
		        VALUES (:id, :at, :dist, :tatica, :msg, :exp)";
		$this->db->prepare($sql)->execute([
			'id'     => $id,
			'at'     => $atacanteId,
			'dist'   => $distritoId,
			'tatica' => $taticaAtacante,
			'msg'    => $messageId,
			'exp'    => $expiraEm,
		]);
	}

	public function buscarPorId(string $id): ?array {
		$stmt = $this->db->prepare('SELECT * FROM confrontos_pendentes WHERE id = :id LIMIT 1');
		$stmt->execute(['id' => $id]);
		$row = $stmt->fetch();
		return $row ?: null;
	}

	/** Retorna confrontos não resolvidos com prazo expirado. */
	public function buscarExpirados(): array {
		$stmt = $this->db->query("SELECT * FROM confrontos_pendentes WHERE resolvido = 0 AND expira_em < NOW()");
		return $stmt->fetchAll();
	}

	public function marcarResolvido(string $id): void {
		$this->db->prepare("UPDATE confrontos_pendentes SET resolvido = 1 WHERE id = :id")
			->execute(['id' => $id]);
	}

	/** Verifica se já existe um confronto pendente para um atacante. */
	public function existePendente(string $atacanteId): bool {
		$stmt = $this->db->prepare("SELECT 1 FROM confrontos_pendentes WHERE atacante_id = :id AND resolvido = 0 AND expira_em > NOW() LIMIT 1");
		$stmt->execute(['id' => $atacanteId]);
		return (bool) $stmt->fetch();
	}
}
