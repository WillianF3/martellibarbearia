# Martelli V49 — Conciliação segura de cadastro existente

## Diagnóstico confirmado no código

O endpoint `api/vip.php?action=request_create` procura primeiro o CPF em `mt_clients` e rejeita uma solicitação nova se o WhatsApp for diferente do cadastro encontrado. Na captura anterior, havia dois IDs distintos: um com o CPF e outro com o WhatsApp informado. Isso **não prova** que sejam a mesma pessoa: confirme a titularidade diretamente antes de unir os cadastros.

## Antes de fazer mudanças

1. No Hostinger hPanel, exporte e guarde um backup completo do MySQL.
2. Faça backup dos arquivos do site.
3. Use o phpMyAdmin no banco **Martelli**, nunca em `information_schema`.
4. Execute `sql/01_DIAGNOSTICO_V49.sql` (somente leitura) e confira IDs, nomes, CPF, WhatsApp e totais de agendamentos, assinaturas e comandas.
5. No painel administrativo principal, use `/clientes-conciliar.php` para consultar os dois cadastros e vínculos.
6. Valide por contato direto com a pessoa que ambos os registros pertencem ao mesmo titular; não use o CPF nem o e-mail informado como comprovação isolada.

## Instalar correção opcional do banco

`sql/02_CONCILIACAO_SEGURA_V49.sql` cria uma tabela de auditoria e um procedimento protegido. **Importar não apaga nem mescla ninguém**. O procedimento só executa ao fazer uma chamada explícita com os IDs, o CPF e o WhatsApp conferidos, após fornecer a frase de confirmação.

Exemplo **ILUSTRATIVO — NÃO EXECUTAR SEM CONFERIR OS IDs E OS DADOS ATUAIS**:

```sql
CALL mt_conciliar_cadastro_v49(
  ID_ATUAL_COM_CPF,
  ID_ATUAL_COM_WHATSAPP,
  'CPF_COM_11_DIGITOS',
  'DDD_E_WHATSAPP',
  'CONFERI_IDENTIDADE_E_BACKUP_V49'
);
```

O ID **com CPF** será preservado; o ID **com WhatsApp** será substituído, após transferência dos vínculos conhecidos para o primeiro. O número informado torna-se o WhatsApp principal. O procedimento não altera tabelas de planos, barbeiros, serviços ou horários. Agendamentos, assinaturas e comandas mantêm seus IDs e lançamentos. Uma tabela `mt_client_merges_v49` conserva a referência dos IDs anteriores.

Se os dois registros tiverem contas VIP, Google ou Apple próprias, ou assinaturas sobrepostas, o procedimento **bloqueia**. Ele também interrompe se encontrar alguma tabela com FK para clientes não prevista no script. Nunca desative `FOREIGN_KEY_CHECKS` para forçar a operação.

Auditorias antigas que mencionam o ID antigo continuam históricas: consulte o vínculo na tabela `mt_client_merges_v49` quando necessário.

## Depois

- Confirme a existência de apenas um cadastro canônico para o CPF e o telefone esperado, sem alterar valores financeiros.
- Teste login VIP com a credencial existente e confirme que os atendimentos anteriores aparecem.
- Caso o Google ainda não esteja vinculado, faça a vinculação pelo login da conta existente. O sistema **não** assume uma conta a partir de CPF ou igualdade de e-mail.
- O site continua recusando **nova inscrição** quando já existe assinatura VIP vinculada, como deve ser. Use login ou renovação, não um segundo cadastro.

## Segurança

Os arquivos de entrega excluem `app/*.local.php` e qualquer credencial. Verifique sua pasta `app` na Hostinger, mas não envie senhas ou tokens. Testes de sintaxe não substituem a execução controlada no banco real.
