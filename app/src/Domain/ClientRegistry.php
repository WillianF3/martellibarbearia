<?php
declare(strict_types=1);
namespace Martelli\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Repositório de clientes para agendamentos normais e VIP.
 * A identidade do cliente é o ID existente. CPF nunca cria uma segunda conta.
 * Mudanças de WhatsApp exigem sessão VIP autenticada do próprio titular.
 */
final class ClientRegistry
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Reutiliza o CPF já cadastrado, bloqueando alterações de dados de terceiros.
     * @param int $verifiedClientId ID obtido de sessão VIP revalidada no servidor (nunca do navegador).
     * @param bool $enforceVipLogin Somente agendamento público bloqueia VIP vigente.
     */
    public function findOrCreate(
        string $name,
        string $phone,
        string $cpf,
        int $verifiedClientId = 0,
        bool $enforceVipLogin = true
    ): int {
        $byPhone = $this->findByPhoneForUpdate($phone);
        $byCpf = $this->findByCpfForUpdate($cpf);
        $client = $byCpf ?: $byPhone;

        // Nunca expor agenda ou alterar contatos só porque alguém conhece o CPF.
        if ($byPhone && $byCpf && (int)$byPhone['id'] !== (int)$byCpf['id']) {
            throw new ClientConflict('Este WhatsApp e CPF pertencem a cadastros distintos. Entre na sua conta ou solicite a atualização à barbearia.');
        }
        if ($byPhone && $byPhone['cpf'] !== null && (string)$byPhone['cpf'] !== $cpf) {
            throw new ClientConflict('Este WhatsApp está associado a outro CPF. Procure a barbearia.');
        }

        if ($client && $enforceVipLogin) {
            // Não bloquear agendamentos comuns de clientes cujo plano já venceu.
            // Verifica STATUS e ambos os limites da vigência (fuso São Paulo).
            $current = $this->currentVip((int)$client['id']);
            if ($current !== null) {
                $matchedPhone = (string)$client['phone'] === $phone;
                throw new VipBookingLoginRequired(
                    $matchedPhone && $this->hasFutureVipBookings((int)$current['id']),
                    $matchedPhone
                );
            }
        }

        if ($byCpf) {
            $clientId = (int)$byCpf['id'];
            if ((string)$byCpf['phone'] !== $phone) {
                if ($verifiedClientId !== $clientId) {
                    // Um VIP expirado pode reservar normalmente com o telefone antigo.
                    // Para trocar de telefone, é preciso entrar na conta primeiro.
                    throw new ClientConflict('Para usar um novo WhatsApp no cadastro existente, entre na sua conta e confirme seus dados.');
                }
                // Sessão do titular revalidada; o índice UNIQUE(phone) protege concorrência.
                $q = $this->pdo->prepare('UPDATE mt_clients SET name=?,phone=? WHERE id=?');
                $q->execute([$name, $phone, $clientId]);
            } else {
                $q = $this->pdo->prepare('UPDATE mt_clients SET name=? WHERE id=?');
                $q->execute([$name, $clientId]);
            }
            return $clientId;
        }
        if ($byPhone) {
            // Cadastro legado sem CPF: atualiza o CPF no mesmo registro.
            $q = $this->pdo->prepare('UPDATE mt_clients SET name=?,cpf=? WHERE id=?');
            $q->execute([$name, $cpf, (int)$byPhone['id']]);
            return (int)$byPhone['id'];
        }
        $q = $this->pdo->prepare('INSERT INTO mt_clients(name,phone,cpf) VALUES(?,?,?)');
        $q->execute([$name, $phone, $cpf]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Retorna plano vigente; cancelados, pausados, futuros e vencidos não bloqueiam. */
    private function currentVip(int $clientId): ?array
    {
        $today = (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $q = $this->pdo->prepare(
            "SELECT id FROM mt_vip_memberships WHERE client_id=? AND status='active' " .
            'AND starts_on<=? AND ends_on>=? ORDER BY id DESC LIMIT 1'
        );
        $q->execute([$clientId, $today, $today]);
        return $q->fetch() ?: null;
    }

    /** Diferencia cortes já reservados de cortes ainda disponíveis, sem divulgar datas. */
    private function hasFutureVipBookings(int $membershipId): bool
    {
        $q = $this->pdo->prepare(
            "SELECT 1 FROM mt_vip_appointments v " .
            'JOIN mt_appointments a ON a.id=v.appointment_id ' .
            "WHERE v.membership_id=? AND a.status IN ('pending','confirmed') " .
            'AND a.starts_at>=? LIMIT 1'
        );
        $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $q->execute([$membershipId,$now]);
        return (bool)$q->fetchColumn();
    }

    /** Consulta por telefone sob lock, respeitando a unicidade já existente. */
    private function findByPhoneForUpdate(string $phone): array|false
    {
        $q = $this->pdo->prepare('SELECT id,cpf,phone FROM mt_clients WHERE phone=? FOR UPDATE');
        $q->execute([$phone]);
        return $q->fetch();
    }

    /** Consulta por CPF sob lock, sem consultar dados VIP de terceiros. */
    private function findByCpfForUpdate(string $cpf): array|false
    {
        $q = $this->pdo->prepare('SELECT id,cpf,phone FROM mt_clients WHERE cpf=? FOR UPDATE');
        $q->execute([$cpf]);
        return $q->fetch();
    }
}
