<?php

class CombateMesaRepository {

    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
    }

    public function criar(string $confrontoId, string $atacanteId, int $distritoId, string $taticaAtacante): void {
        $this->db->prepare(
            "INSERT INTO confrontos_mesa (confronto_id, atacante_id, distrito_id, tatica_atacante)
             VALUES (:cid, :atk, :dist, :tatica)"
        )->execute([
            'cid'    => $confrontoId,
            'atk'    => $atacanteId,
            'dist'   => $distritoId,
            'tatica' => $taticaAtacante,
        ]);
    }

    public function listarPendentes(): array {
        return $this->db->query(
            "SELECT cm.*, f.nome AS atacante_nome
             FROM confrontos_mesa cm
             LEFT JOIN faccoes f ON f.id = cm.atacante_id
             WHERE cm.status = 'pendente'
             ORDER BY cm.data_criacao ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarPorId(string $confrontoId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM confrontos_mesa WHERE confronto_id = :cid LIMIT 1");
        $stmt->execute(['cid' => $confrontoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function resolver(string $confrontoId, string $vencedorId): void {
        $this->db->prepare(
            "UPDATE confrontos_mesa
             SET status = 'resolvido', vencedor_id = :v, data_resolucao = NOW()
             WHERE confronto_id = :cid"
        )->execute(['cid' => $confrontoId, 'v' => $vencedorId]);
    }

    public function cancelar(string $confrontoId): void {
        $this->db->prepare(
            "UPDATE confrontos_mesa
             SET status = 'cancelado', data_resolucao = NOW()
             WHERE confronto_id = :cid"
        )->execute(['cid' => $confrontoId]);
    }
}
