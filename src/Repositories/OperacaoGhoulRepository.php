<?php

class OperacaoGhoulRepository {

    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
    }

    public function criar(string $id, string $faccaoId, string $tipo, int $distritoId, float $horas): void {
        $dataFim = date('Y-m-d H:i:s', time() + (int) ($horas * 3600));
        $this->db->prepare(
            "INSERT INTO operacoes_ghoul (id, faccao_id, tipo, distrito_id, data_fim)
             VALUES (:id, :faccao_id, :tipo, :distrito_id, :data_fim)"
        )->execute([
            'id'          => $id,
            'faccao_id'   => $faccaoId,
            'tipo'        => $tipo,
            'distrito_id' => $distritoId,
            'data_fim'    => $dataFim,
        ]);
    }

    public function temPendente(string $faccaoId): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM operacoes_ghoul WHERE faccao_id = :f AND concluida = 0 LIMIT 1"
        );
        $stmt->execute(['f' => $faccaoId]);
        return (bool) $stmt->fetch();
    }

    public function listarPendentes(): array {
        return $this->db->query(
            "SELECT * FROM operacoes_ghoul WHERE concluida = 0 ORDER BY data_inicio ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarProntasParaConcluir(): array {
        return $this->db->query(
            "SELECT * FROM operacoes_ghoul WHERE concluida = 0 AND data_fim <= NOW() ORDER BY data_fim ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marcarConcluida(string $id, string $resultado = ''): void {
        $this->db->prepare(
            "UPDATE operacoes_ghoul SET concluida = 1, resultado = :r WHERE id = :id"
        )->execute(['id' => $id, 'r' => $resultado]);
    }

    public function marcarInterceptada(string $id): void {
        $this->db->prepare(
            "UPDATE operacoes_ghoul SET concluida = 1, interceptada = 1 WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public function marcarAlertaEnviado(string $id): void {
        $this->db->prepare(
            "UPDATE operacoes_ghoul SET alerta_enviado = 1 WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public function contarSemana(string $faccaoId, string $tipo): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM operacoes_ghoul
             WHERE faccao_id = :f AND tipo = :t
               AND YEARWEEK(data_inicio, 1) = YEARWEEK(NOW(), 1)"
        );
        $stmt->execute(['f' => $faccaoId, 't' => $tipo]);
        return (int) $stmt->fetchColumn();
    }

    public function limparAntigas(int $dias = 7): void {
        $this->db->prepare(
            "DELETE FROM operacoes_ghoul WHERE concluida = 1 AND data_fim < DATE_SUB(NOW(), INTERVAL :d DAY)"
        )->execute(['d' => $dias]);
    }
}
