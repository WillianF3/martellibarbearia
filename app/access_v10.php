<?php
/** Autorização Martelli V10. Fonte de verdade: ID persistente, nunca nome ou e-mail de formulário. */
declare(strict_types=1);
const MT_ACCESS_KEYS = ['minha_agenda','agendar_meu_dia','agenda','cadastros','horarios','lista_espera','financeiro','estoque','relatorios','configuracoes'];
/** Módulos da administração que o proprietário pode ligar/desligar.
 * Não afeta agendamentos públicos ou dados armazenados. */
const MT_PANEL_FEATURES = [
    'agenda' => ['Agenda e agendamentos','Consultas e ações sobre agendamentos no painel. Não interrompe reservas públicas.'],
    'cadastros' => ['Cadastros e equipe','Clientes, serviços e profissionais na administração.'],
    'horarios' => ['Horários da equipe','Configuração dos horários dos profissionais.'],
    'lista_espera' => ['Lista de espera e bloqueios','Área administrativa de organização de filas e períodos indisponíveis.'],
    'financeiro' => ['Financeiro e comandas','Comandas, caixa, recebimentos e movimentações.'],
    'estoque' => ['Produtos e estoque','Consulta e controle de produtos e movimentações do estoque.'],
    'comissoes' => ['Comissões','Consulta e pagamento das comissões; requer Financeiro ativo.'],
    'relatorios' => ['Dashboard e relatórios','Indicadores, gráficos e relatórios gerenciais.'],
];
function mt_feature_states(): array {
    static $states = null;
    if ($states !== null) return $states;
    $states=array_fill_keys(array_keys(MT_PANEL_FEATURES), true);
    try {
        $q=db()->prepare("SELECT `key`, `value` FROM mt_settings WHERE `key` LIKE 'admin_feature_%'");
        $q->execute();
        while ($r=$q->fetch(PDO::FETCH_ASSOC)) {
            $key=substr((string)$r['key'], strlen('admin_feature_'));
            if (array_key_exists($key, $states)) $states[$key] = (string)$r['value'] !== '0';
        }
    } catch (Throwable $e) {
        // Não manter acessos liberados se a configuração não puder ser consultada.
        error_log('Martelli feature flags unavailable: '.$e->getMessage());
        $states=array_fill_keys(array_keys(MT_PANEL_FEATURES), false);
    }
    return $states;
}
function mt_feature_enabled(string $key): bool {
    if (!array_key_exists($key, MT_PANEL_FEATURES)) return true;
    return mt_feature_states()[$key];
}

function mt_access_state(): array {
    static $cachedId = null, $cached = null;
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($cachedId === $id && $cached !== null) return $cached;
    $cachedId = $id;
    $cached = ['owner'=>false, 'enabled'=>false, 'permissions'=>[], 'barber_id'=>0];
    if (!$id) return $cached;
    try {
        // Se a migração não existir ou a conta for removida, falhar fechado.
        $q = db()->prepare('SELECT a.id, o.admin_id owner_id, p.enabled, p.permissions, p.barber_id FROM mt_admins a LEFT JOIN mt_access_owner o ON o.id=1 LEFT JOIN mt_admin_access p ON p.admin_id=a.id WHERE a.id=? LIMIT 1');
        $q->execute([$id]); $row=$q->fetch();
        if (!$row) return $cached;
        $cached['owner'] = (int)($row['owner_id'] ?? 0) === $id;
        if ($cached['owner']) { $cached['enabled']=true; $cached['permissions']=MT_ACCESS_KEYS; return $cached; }
        $keys=json_decode((string)($row['permissions'] ?? '[]'), true);
        $cached['enabled'] = (int)($row['enabled'] ?? 0) === 1;
        $cached['permissions'] = is_array($keys) ? array_values(array_intersect(MT_ACCESS_KEYS,$keys)) : [];
        $cached['barber_id'] = max(0,(int)($row['barber_id'] ?? 0));
    } catch (Throwable $e) {
        error_log('Martelli access schema missing or invalid: '.$e->getMessage());
    }
    return $cached;
}
function mt_is_owner(): bool {return mt_access_state()['owner'];}
function mt_can(string $permission): bool {
    if (!in_array($permission, MT_ACCESS_KEYS, true)) return false;
    if (!mt_feature_enabled($permission)) return false;
    $a=mt_access_state();
    return $a['enabled'] && ($a['owner'] || in_array($permission,$a['permissions'],true));
}
function mt_can_any(array $permissions): bool {
    foreach ($permissions as $permission) if (mt_can($permission)) return true;
    return false;
}
function mt_active_admin(): bool { $a=mt_access_state(); return $a['enabled'] && ($a['owner'] || count($a['permissions'])>0); }
function mt_require(string $permission): void { if (!mt_can($permission)) fail('Sem permissão para esta função.',403); }
function mt_require_any(array $permissions): void { if (!mt_can_any($permissions)) fail('Sem permissão para esta função.',403); }
function mt_require_owner(): void { if (!mt_is_owner()) fail('Somente o responsável principal pode gerenciar acessos.',403); }
function mt_start_page(): string {
    if(mt_can_any(['agenda','cadastros','horarios','configuracoes'])) return '/admin.php';
    if(mt_can_any(['financeiro','estoque','relatorios'])) return '/painel.php';
    if(mt_can('lista_espera')) return '/gestao.php';
    if(mt_can('minha_agenda')) return '/barbeiro.php';
    return '/login.php';
}
