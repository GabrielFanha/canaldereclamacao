<?php
declare(strict_types=1);

require __DIR__ . '/bd.php';
require __DIR__ . '/mail.php';
preparar_aplicacao();

try {
    despachar();
} catch (ValidacaoException $e) {
    json_sair(false, $e->getMessage(), null, 422);
} catch (Throwable $e) {
    registrar_log('erros.log', $e::class . ' ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    json_sair(false, 'Não foi possível concluir a operação.', null, 500);
}

function despachar(): void
{
    if (($_GET['acao'] ?? '') === 'anexo') {
        baixar_anexo();
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_sair(false, 'Método não permitido.', null, 405);
    }
    if (corpo_excedeu_limite()) {
        json_sair(false, 'O envio excede o limite permitido. Reduza os anexos.', null, 413);
    }
    if (!origem_confiavel()) {
        json_sair(false, 'Origem da requisição não permitida.', null, 403);
    }
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_confere(is_string($token) ? $token : null)) {
        json_sair(false, 'Sessão de segurança expirada. Atualize a página.', null, 403);
    }

    $acao = (string) ($_POST['acao'] ?? '');
    $publicas = ['login', 'reclamacao_publica'];
    $logadas = ['sair', 'catalogo', 'reclamacao_criar'];
    $gestao = [
        'dashboard_dados', 'reclamacoes_listar', 'acoes_listar', 'acao_detalhe', 'acao_status', 'reclamacao_detalhe', 'reclamacao_setor', 'reclamacao_status', 'reclamacao_reabrir',         'tratativa_iniciar', 'tratativa_salvar',
        'comentario_criar', 'comentario_editar', 'comentario_excluir', 'acao_comentario_criar', 'acao_comentario_editar', 'acao_comentario_excluir', 'usuarios_listar', 'usuarios_sincronizar', 'usuarios_sso_listar', 'usuarios_adicionar', 'usuario_perfil', 'usuario_bloqueio', 'usuario_excluir', 'cadastros_listar',
        'cadastro_salvar', 'cadastro_excluir', 'status_salvar', 'status_excluir',
        'status_mover', 'status_ordenar', 'status_uso', 'status_inativar', 'relatorio_csv',
    ];

    $somenteAdmin = [
        'usuarios_listar', 'usuarios_sincronizar', 'usuarios_sso_listar', 'usuarios_adicionar',
        'usuario_perfil', 'usuario_bloqueio', 'usuario_excluir', 'reclamacao_setor',
        'cadastros_listar', 'cadastro_salvar', 'cadastro_excluir',
        'status_salvar', 'status_excluir', 'status_mover', 'status_ordenar', 'status_uso', 'status_inativar',
    ];
    if (in_array($acao, $gestao, true)) {
        $usuario = exigir_gestao();
    } elseif (in_array($acao, $logadas, true)) {
        $usuario = exigir_login();
    } elseif (!in_array($acao, $publicas, true)) {
        json_sair(false, 'Ação inválida.', null, 404);
    } else {
        $usuario = null;
    }

    if (in_array($acao, $somenteAdmin, true) && ($usuario['perfil'] ?? '') !== 'administrador') {
        json_sair(false, 'Você não tem permissão para esta ação.', null, 403);
    }

    switch ($acao) {
        case 'login':
            fazer_login();
            break;
        case 'sair':
            encerrar_sessao();
            json_sair(true, 'Sessão encerrada.');
            break;
        case 'catalogo':
            json_sair(true, '', catalogo_interno());
            break;
        case 'dashboard_dados':
            json_sair(true, '', dados_dashboard());
            break;
        case 'reclamacao_publica':
            validar_envio_publico();
            $criada = criar_reclamacao(
                $_POST,
                null,
                'publica',
                validar_lista_anexos(arquivos_enviados('anexos'))
            );
            unset($_SESSION['form_publico_em']);
            json_sair(true, 'Reclamação registrada.', ['protocolo' => $criada['protocolo'], 'id' => $criada['id']]);
            break;
        case 'reclamacao_criar':
            $criada = criar_reclamacao($_POST, (int) $usuario['id'], 'interna', validar_lista_anexos(arquivos_enviados('anexos')));
            json_sair(true, 'Reclamação registrada.', $criada);
            break;
        case 'reclamacoes_listar':
            json_sair(true, '', consultar_reclamacoes(filtros_da_requisicao()));
            break;
        case 'acoes_listar':
            json_sair(true, '', listar_acoes());
            break;
        case 'acao_detalhe':
            json_sair(true, '', detalhar_acao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'acao_status':
    $idAcao = inteiro($_POST['id'] ?? 0);
    $concluiu = (string) ($_POST['status'] ?? '') === 'concluido';
    alterar_status_acao();
    json_sair(true, $concluiu ? 'Ação concluída.' : 'Status da ação atualizado.', detalhar_acao($idAcao));
            break;
        case 'reclamacao_detalhe':
            json_sair(true, '', detalhar_reclamacao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'reclamacao_setor':
            alterar_setor_reclamacao((int) $usuario['id']);
            json_sair(true, 'Setor da reclamação atualizado.', detalhar_reclamacao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'reclamacao_status':
        case 'reclamacao_reabrir':
            json_sair(false, 'O status acompanha as tratativas e não pode ser alterado manualmente.', null, 422);
            break;
        case 'tratativa_iniciar':
            iniciar_tratativa((int) $usuario['id']);
            json_sair(true, 'Tratativa iniciada.', detalhar_reclamacao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'tratativa_salvar':
            salvar_tratativa((int) $usuario['id']);
            json_sair(true, 'Tratativa registrada.', detalhar_reclamacao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'comentario_criar':
            adicionar_comentario(
                (int) $usuario['id'],
                inteiro($_POST['id'] ?? 0),
                (string) ($_POST['texto'] ?? ''),
                validar_lista_anexos(arquivos_enviados('anexos'))
            );
            json_sair(true, 'Comentário registrado.', detalhar_reclamacao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'comentario_editar':
            editar_comentario((int) $usuario['id'], inteiro($_POST['comentario_id'] ?? 0), inteiro($_POST['id'] ?? 0), (string) ($_POST['texto'] ?? ''));
            json_sair(true, 'Mensagem atualizada.', detalhar_reclamacao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'comentario_excluir':
            excluir_comentario((int) $usuario['id'], inteiro($_POST['comentario_id'] ?? 0), inteiro($_POST['id'] ?? 0));
            json_sair(true, 'Mensagem excluída.', detalhar_reclamacao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'acao_comentario_criar':
            adicionar_comentario_acao(
                (int) $usuario['id'],
                inteiro($_POST['id'] ?? 0),
                (string) ($_POST['texto'] ?? ''),
                validar_lista_anexos(arquivos_enviados('anexos'))
            );
            json_sair(true, 'Comentário registrado.', detalhar_acao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'acao_comentario_editar':
            editar_comentario_acao((int) $usuario['id'], inteiro($_POST['comentario_id'] ?? 0), inteiro($_POST['id'] ?? 0), (string) ($_POST['texto'] ?? ''));
            json_sair(true, 'Mensagem atualizada.', detalhar_acao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'acao_comentario_excluir':
            excluir_comentario_acao((int) $usuario['id'], inteiro($_POST['comentario_id'] ?? 0), inteiro($_POST['id'] ?? 0));
            json_sair(true, 'Mensagem excluída.', detalhar_acao(inteiro($_POST['id'] ?? 0)));
            break;
        case 'usuarios_listar':
            if ((int) ($_POST['sincronizar'] ?? 0) === 1) {
                $resultado = sincronizar_usuarios_sso((int) $usuario['id']);
                if ($resultado['eu_inativo']) {
                    encerrar_sessao('inativo');
                    json_sair(false, 'Seu usuário foi inativado. O acesso foi encerrado.', ['codigo' => 'inativo'], 401);
                }
            }
            json_sair(true, '', ['itens' => listar_usuarios(trim((string) ($_POST['q'] ?? '')))]);
            break;
        case 'usuarios_sso_listar':
            json_sair(true, '', ['itens' => listar_usuarios_sso_ativos()]);
            break;
        case 'usuarios_adicionar':
            $bruto = json_decode((string) ($_POST['sso_ids'] ?? ''), true);
            $ids = is_array($bruto) ? $bruto : [];
            $setores = json_decode((string) ($_POST['setor_ids'] ?? '[]'), true);
            $quantidade = adicionar_usuarios_sso(
                $ids,
                $usuario,
                (string) ($_POST['perfil'] ?? ''),
                inteiro($_POST['empresa_id'] ?? 0),
                is_array($setores) ? $setores : []
            );
            json_sair(true, $quantidade === 1 ? '1 usuário incluído.' : $quantidade . ' usuários incluídos.', ['quantidade' => $quantidade]);
            break;
        case 'usuarios_sincronizar':
            $resultado = sincronizar_usuarios_sso((int) $usuario['id']);
            if ($resultado['eu_inativo']) {
                encerrar_sessao('inativo');
            }
            if ($resultado['confirmados'] === 0) {
                json_sair(false, $resultado['mensagem'], null, 422);
            }
            json_sair(true, $resultado['mensagem'], ['eu_inativo' => $resultado['eu_inativo']]);
            break;
        case 'usuario_perfil':
            $setores = json_decode((string) ($_POST['setor_ids'] ?? '[]'), true);
            atualizar_perfil(
                inteiro($_POST['id'] ?? 0),
                (string) ($_POST['perfil'] ?? ''),
                $usuario,
                inteiro($_POST['empresa_id'] ?? 0),
                is_array($setores) ? $setores : []
            );
            json_sair(true, 'Perfil atualizado.');
            break;
        case 'usuario_bloqueio':
            definir_bloqueio_usuario(
                inteiro($_POST['id'] ?? 0),
                (int) ($_POST['bloqueado'] ?? 0) === 1 ? 1 : 0,
                (int) $usuario['id']
            );
            json_sair(true, 'Acesso atualizado.');
            break;
        case 'usuario_excluir':
            excluir_usuario(inteiro($_POST['id'] ?? 0), (int) $usuario['id']);
            json_sair(true, 'Usuário excluído.');
            break;
        case 'cadastros_listar':
            json_sair(true, '', ['itens' => listar_entidade((string) ($_POST['entidade'] ?? ''))]);
            break;
        case 'cadastro_salvar':
            salvar_entidade(
                (string) ($_POST['entidade'] ?? ''),
                inteiro($_POST['id'] ?? 0),
                (string) ($_POST['nome'] ?? ''),
                (int) ($_POST['ativo'] ?? 1) === 1 ? 1 : 0,
                (string) ($_POST['cor'] ?? ''),
                inteiro($_POST['empresa_id'] ?? 0)
            );
            json_sair(true, 'Registro salvo.');
            break;
        case 'cadastro_excluir':
            excluir_entidade((string) ($_POST['entidade'] ?? ''), inteiro($_POST['id'] ?? 0));
            json_sair(true, 'Registro excluído.');
            break;
        case 'status_salvar':
        case 'status_excluir':
        case 'status_mover':
        case 'status_ordenar':
        case 'status_inativar':
            json_sair(false, 'Os status são automáticos e não podem ser alterados.', null, 422);
            break;
        case 'status_uso':
            json_sair(true, '', uso_status(inteiro($_POST['id'] ?? 0)));
            break;
        case 'relatorio_csv':
            exportar_csv(filtros_da_requisicao());
            break;
        default:
            json_sair(false, 'Ação inválida.', null, 404);
    }
}

function fazer_login(): void
{
    $login = trim((string) ($_POST['usuario'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    $resultado = sso_autenticar($login, $senha);
    $mensagens = [
        'token' => 'O token SSO não está configurado. Preencha SSO_TOKEN no arquivo .env.',
        'conexao' => 'Não foi possível conectar ao serviço de login. Tente novamente.',
        'inativo' => 'Usuário inativo. O acesso foi bloqueado.',
        'credencial' => 'Usuário ou senha inválidos.',
    ];
    if ($resultado['codigo'] === 'inativo' && is_array($resultado['usuario']) && usuario_local_sso($resultado['usuario']) !== null) {
        salvar_usuario_sso($resultado['usuario'], false);
    }
    if (!$resultado['ok'] || !is_array($resultado['usuario'])) {
        json_sair(false, $mensagens[$resultado['codigo']] ?? 'Usuário ou senha inválidos.', null, 401);
    }
    if (usuario_local_sso($resultado['usuario']) === null && (int) valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'administrador'") > 0) {
        json_sair(false, 'Seu usuário não está liberado. Peça ao administrador para incluir você.', null, 401);
    }
    $usuario = salvar_usuario_sso($resultado['usuario'], true);
    if ((int) valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'administrador'") === 0) {
        executar('UPDATE usuarios SET perfil = ?, atualizado_em = ? WHERE id = ?', ['administrador', agora(), (int) $usuario['id']]);
        $usuario['perfil'] = 'administrador';
    }
    if ((int) $usuario['ativo'] !== 1 || (int) ($usuario['bloqueado'] ?? 0) === 1) {
        json_sair(false, $mensagens['inativo'], null, 401);
    }
    abrir_sessao_usuario($usuario);
    json_sair(true, 'Login realizado.', ['redirecionar' => 'dashboard.php']);
}

function catalogo_interno(): array
{
    $setores = linhas_cadastro('setores');
    $usuario = usuario_da_sessao();
    if ($usuario !== null && !perfil_amplo($usuario)) {
        $permitidos = setores_do_gestor((int) $usuario['id']);
        $setores = array_values(array_filter(
            $setores,
            static fn (array $item): bool => in_array($item['id'], $permitidos, true)
        ));
    }
    return [
        'setores' => $setores,
        'empresas' => linhas_cadastro('empresas'),
        'tipos' => linhas_cadastro('tipos_reclamacao'),
        'situacoes' => linhas_cadastro('situacoes'),
        'status' => listar_status(),
    ];
}

function contagem_uso(string $coluna): array
{
    if (!in_array($coluna, ['setor_id', 'tipo_id', 'situacao_id', 'status_id'], true)) {
        return [];
    }
    $mapa = [];
    foreach (consultar("SELECT {$coluna} AS ref, COUNT(*) AS total FROM reclamacoes GROUP BY {$coluna}") as $linha) {
        if ($linha['ref'] === null || $linha['ref'] === '') {
            continue;
        }
        $mapa[(int) $linha['ref']] = (int) $linha['total'];
    }
    return $mapa;
}

function linhas_cadastro(string $tabela): array
{
    if ($tabela === 'setores') {
        return linhas_setores();
    }
    if ($tabela === 'empresas') {
        return linhas_empresas();
    }
    $comCor = $tabela === 'situacoes';
    $colunas = [
        'tipos_reclamacao' => 'tipo_id',
        'situacoes' => 'situacao_id',
    ];
    $uso = contagem_uso($colunas[$tabela] ?? '');
    $linhas = consultar('SELECT id, nome, ativo' . ($comCor ? ', cor' : '') . " FROM {$tabela} ORDER BY nome");
    return array_map(static function (array $linha) use ($comCor, $uso): array {
        $id = (int) $linha['id'];
        $item = [
            'id' => $id,
            'nome' => $linha['nome'],
            'ativo' => (int) $linha['ativo'],
            'em_uso' => ($uso[$id] ?? 0) > 0,
        ];
        if ($comCor) {
            $item['cor'] = cor_status(is_string($linha['cor'] ?? null) ? $linha['cor'] : '', null);
        }
        return $item;
    }, $linhas);
}

function linhas_setores(): array
{
    $uso = contagem_uso('setor_id');
    $linhas = consultar(
        'SELECT s.id, s.nome, s.ativo, s.empresa_id, e.nome AS empresa_nome
         FROM setores s
         LEFT JOIN empresas e ON e.id = s.empresa_id
         ORDER BY s.ordem, s.nome'
    );
    return array_map(static function (array $linha) use ($uso): array {
        $id = (int) $linha['id'];
        return [
            'id' => $id,
            'nome' => $linha['nome'],
            'ativo' => (int) $linha['ativo'],
            'empresa_id' => $linha['empresa_id'] !== null && $linha['empresa_id'] !== '' ? (int) $linha['empresa_id'] : null,
            'empresa_nome' => is_string($linha['empresa_nome'] ?? null) && $linha['empresa_nome'] !== '' ? $linha['empresa_nome'] : '',
            'em_uso' => ($uso[$id] ?? 0) > 0,
        ];
    }, $linhas);
}

function linhas_empresas(): array
{
    $uso = [];
    foreach (consultar('SELECT empresa_id AS ref, COUNT(*) AS total FROM setores WHERE empresa_id IS NOT NULL GROUP BY empresa_id') as $linha) {
        $uso[(int) $linha['ref']] = (int) $linha['total'];
    }
    $linhas = consultar('SELECT id, nome, ativo FROM empresas ORDER BY nome');
    return array_map(static function (array $linha) use ($uso): array {
        $id = (int) $linha['id'];
        return [
            'id' => $id,
            'nome' => $linha['nome'],
            'ativo' => (int) $linha['ativo'],
            'em_uso' => ($uso[$id] ?? 0) > 0,
        ];
    }, $linhas);
}

function listar_status(): array
{
    $uso = contagem_uso('status_id');
    $linhas = consultar("SELECT id, nome, ordem, fixo, ativo, cor, criado_em FROM status_reclamacao ORDER BY CASE WHEN fixo = 'inicial' THEN 0 WHEN fixo = 'final' THEN 2 ELSE 1 END, ordem, id");
    return array_map(static function (array $linha) use ($uso): array {
        $fixo = $linha['fixo'] !== null && $linha['fixo'] !== '' ? $linha['fixo'] : null;
        $id = (int) $linha['id'];
        return [
            'id' => $id,
            'nome' => $linha['nome'],
            'ordem' => (int) $linha['ordem'],
            'fixo' => $fixo,
            'ativo' => (int) $linha['ativo'],
            'cor' => cor_status(is_string($linha['cor'] ?? null) ? $linha['cor'] : '', $fixo),
            'em_uso' => ($uso[$id] ?? 0) > 0,
        ];
    }, $linhas);
}

function filtros_da_requisicao(): array
{
    $busca = trim((string) ($_POST['busca'] ?? ''));
    if (mb_strlen($busca) > 100) {
        $busca = mb_substr($busca, 0, 100);
    }
    return [
        'busca' => $busca,
        'status_id' => inteiro($_POST['status_id'] ?? 0),
        'setor_id' => inteiro($_POST['setor_id'] ?? 0),
        'tipo_id' => inteiro($_POST['tipo_id'] ?? 0),
        'situacao_id' => inteiro($_POST['situacao_id'] ?? 0),
        'origem' => (string) ($_POST['origem'] ?? ''),
        'data_ini' => trim((string) ($_POST['data_ini'] ?? '')),
        'data_fim' => trim((string) ($_POST['data_fim'] ?? '')),
        'status_grupo' => (string) ($_POST['status_grupo'] ?? ''),
        'periodo' => (string) ($_POST['periodo'] ?? ''),
        'pagina' => max(1, inteiro($_POST['pagina'] ?? 1)),
        'empresa_id' => inteiro($_POST['empresa_id'] ?? 0),
        'mes' => trim((string) ($_POST['mes'] ?? '')),
    ];
}

function clausulas_reclamacao(array $filtros): array
{
    $where = ['1 = 1'];
    $params = [];
    if ($filtros['busca'] !== '') {
        $like = texto_busca($filtros['busca']);
        $where[] = condicao_busca([
            'r.protocolo', 'r.nome_cliente', 'r.unidade', 'r.responsavel_denuncia', 'r.responsavel_atendimento',
            'r.descricao', 'st.nome', 't.nome',
        ]);
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($filtros['status_id'] > 0) {
        $where[] = 'r.status_id = ?';
        $params[] = $filtros['status_id'];
    } elseif ($filtros['status_grupo'] === 'inicial') {
        $where[] = "s.fixo = 'inicial'";
    } elseif ($filtros['status_grupo'] === 'meio') {
        $where[] = "(s.fixo IS NULL OR s.fixo = '')";
    } elseif ($filtros['status_grupo'] === 'final') {
        $where[] = "s.fixo = 'final'";
    }
    foreach (['setor_id' => 'r.setor_id', 'tipo_id' => 'r.tipo_id', 'situacao_id' => 'r.situacao_id'] as $campo => $coluna) {
        if ($filtros[$campo] > 0) {
            $where[] = $coluna . ' = ?';
            $params[] = $filtros[$campo];
        }
    }
    if (in_array($filtros['origem'], ['publica', 'interna'], true)) {
        $where[] = 'r.origem = ?';
        $params[] = $filtros['origem'];
    }
    if ($filtros['data_ini'] !== '') {
        if (!data_valida($filtros['data_ini'])) {
            throw new ValidacaoException('Data inicial inválida.');
        }
        $where[] = 'substr(r.criado_em, 1, 10) >= ?';
        $params[] = $filtros['data_ini'];
    }
    if ($filtros['data_fim'] !== '') {
        if (!data_valida($filtros['data_fim'])) {
            throw new ValidacaoException('Data final inválida.');
        }
        $where[] = 'substr(r.criado_em, 1, 10) <= ?';
        $params[] = $filtros['data_fim'];
    }
    if ($filtros['data_ini'] !== '' && $filtros['data_fim'] !== '' && $filtros['data_ini'] > $filtros['data_fim']) {
        throw new ValidacaoException('A data inicial não pode ser maior que a data final.');
    }
    if ($filtros['periodo'] === 'mes') {
        $where[] = 'substr(r.criado_em, 1, 7) = ?';
        $params[] = date('Y-m');
    }
    if (($filtros['empresa_id'] ?? 0) > 0) {
        $where[] = 'st.empresa_id = ?';
        $params[] = (int) $filtros['empresa_id'];
    }
    $mesPainel = (string) ($filtros['mes'] ?? '');
    if ($mesPainel !== '') {
        $where[] = expr_mes_reclamacao() . ' = ?';
        $params[] = $mesPainel;
    }
    $setoresGestor = escopo_setores_gestor();
    if (is_array($setoresGestor)) {
        if ($setoresGestor === []) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'r.setor_id IN (' . implode(',', array_fill(0, count($setoresGestor), '?')) . ')';
            array_push($params, ...$setoresGestor);
        }
    }
    return [$where, $params];
}

function from_reclamacoes(): string
{
    return 'FROM reclamacoes r
        JOIN status_reclamacao s ON s.id = r.status_id
        JOIN setores st ON st.id = r.setor_id
        JOIN tipos_reclamacao t ON t.id = r.tipo_id
        LEFT JOIN situacoes si ON si.id = r.situacao_id';
}

function consultar_reclamacoes(array $filtros, int $porPagina = 20): array
{
    [$where, $params] = clausulas_reclamacao($filtros);
    $sqlWhere = implode(' AND ', $where);
    $total = (int) valor('SELECT COUNT(*) ' . from_reclamacoes() . ' WHERE ' . $sqlWhere, $params);
    $porPagina = max(1, min(100, $porPagina));
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($filtros['pagina'], $paginas);
    $offset = ($pagina - 1) * $porPagina;
    $linhas = consultar(
        'SELECT r.id, r.protocolo, r.origem, r.nome_cliente, r.criado_em, r.concluida_em, r.atualizado_em, r.status_id, r.situacao_id,
            s.nome AS status_nome, s.fixo AS status_fixo, s.cor AS status_cor, st.nome AS setor_nome, emp.nome AS empresa_nome, t.nome AS tipo_nome,
            si.nome AS situacao_nome, si.cor AS situacao_cor '
        . from_reclamacoes()
        . ' LEFT JOIN empresas emp ON emp.id = st.empresa_id WHERE ' . $sqlWhere
        . ' ORDER BY r.criado_em DESC, r.id DESC LIMIT ' . $porPagina . ' OFFSET ' . $offset,
        $params
    );
    return [
        'total' => $total,
        'pagina' => $pagina,
        'paginas' => $paginas,
        'por_pagina' => $porPagina,
        'itens' => array_map('resumo_reclamacao', $linhas),
    ];
}

function expr_prazo_valido(): string
{
    if (driver_banco() === 'mysql') {
        return "p.tempo_aplicacao REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'";
    }
    return "p.tempo_aplicacao GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'";
}

function status_acao_valido(string $status): string
{
    return in_array($status, ['aguardando', 'andamento', 'concluido', 'cancelado'], true) ? $status : 'aguardando';
}

function rotulo_status_acao(string $status): string
{
    return [
        'aguardando' => 'Aguardando',
        'andamento' => 'Em Andamento',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ][$status] ?? 'Aguardando';
}

function alterar_status_acao(): void
{
    $id = inteiro($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if ($id <= 0 || !in_array($status, ['aguardando', 'andamento', 'concluido'], true)) {
        throw new ValidacaoException('Informe o status da ação.');
    }
    $plano = consultar_um('SELECT id, reclamacao_id, status FROM planos_acao WHERE id = ?', [$id]);
    if ($plano === null) {
        throw new ValidacaoException('Ação não encontrada.');
    }
    exigir_reclamacao_gestor((int) $plano['reclamacao_id']);
    if ((string) $plano['status'] === 'concluido') {
        throw new ValidacaoException('Esta ação já foi concluída e não pode ser reaberta.');
    }
    if ((string) $plano['status'] === $status) {
        return;
    }
    $usuario = usuario_da_sessao();
    $usuarioId = $usuario === null ? null : (int) $usuario['id'];
    $htmlConclusao = '';
    if ($status === 'concluido') {
        if ($usuarioId === null) {
            throw new ValidacaoException('Não foi possível identificar quem concluiu a ação.');
        }
        $bruto = str_replace(["\r\n", "\r", "\n"], '<br>', trim((string) ($_POST['conclusao'] ?? '')));
        [$htmlConclusao, $puro] = html_formatado($bruto, 2000, 'A conclusão');
        if (mb_strlen($puro) < 80) {
            throw new ValidacaoException('Descreva a conclusão com pelo menos 80 caracteres.');
        }
    }
    $momento = agora();
    $descricao = 'Status alterado de ' . rotulo_status_acao((string) $plano['status']) . ' para ' . rotulo_status_acao($status) . '.';
    transacao(static function (PDO $pdo) use ($id, $status, $usuarioId, $descricao, $momento, $htmlConclusao): void {
        $pdo->prepare('UPDATE planos_acao SET status = ? WHERE id = ?')->execute([$status, $id]);
        if ($status === 'concluido') {
            $pdo->prepare('INSERT INTO acoes_comentarios (plano_id, usuario_id, texto, criado_em) VALUES (?, ?, ?, ?)')
                ->execute([$id, $usuarioId, '[[conclusao]]]]' . $htmlConclusao, $momento]);
        }
        $pdo->prepare('INSERT INTO acoes_movimentacoes (plano_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)')
            ->execute([$id, $usuarioId, $descricao, $momento]);
    });
}

function situacao_acao(string $prazo, string $hoje): array
{
    if (!data_valida($prazo)) {
        return ['status' => 'sem_prazo', 'dias' => null];
    }
    $inicio = new DateTimeImmutable($hoje);
    $fim = new DateTimeImmutable($prazo);
    $dias = (int) $inicio->diff($fim)->format('%r%a');
    if ($dias < 0) {
        $status = 'atrasada';
    } elseif ($dias === 0) {
        $status = 'hoje';
    } else {
        $status = 'prazo';
    }
    return ['status' => $status, 'dias' => $dias];
}

function listar_acoes(): array
{
    $busca = trim((string) ($_POST['busca'] ?? ''));
    if (mb_strlen($busca) > 100) {
        $busca = mb_substr($busca, 0, 100);
    }
    $empresaId = inteiro($_POST['empresa_id'] ?? 0);
    $setorId = inteiro($_POST['setor_id'] ?? 0);
    $dataIni = trim((string) ($_POST['data_ini'] ?? ''));
    $dataFim = trim((string) ($_POST['data_fim'] ?? ''));
    $situacao = (string) ($_POST['situacao'] ?? '');
    if (!in_array($situacao, ['atrasada', 'hoje', 'prazo'], true)) {
        $situacao = '';
    }
    $pagina = max(1, inteiro($_POST['pagina'] ?? 1));
    $hoje = date('Y-m-d');
    $where = ['1 = 1'];
    $params = [];
    if ($busca !== '') {
        $like = texto_busca($busca);
        $where[] = condicao_busca(['p.acao', 'p.responsavel', 'r.protocolo', 'r.nome_cliente']);
        array_push($params, $like, $like, $like, $like);
    }
    if ($empresaId > 0) {
        $where[] = 'st.empresa_id = ?';
        $params[] = $empresaId;
    }
    if ($setorId > 0) {
        $where[] = 'r.setor_id = ?';
        $params[] = $setorId;
    }
    if ($dataIni !== '') {
        if (!data_valida($dataIni)) {
            throw new ValidacaoException('Data inicial inválida.');
        }
        $where[] = 'p.tempo_aplicacao >= ?';
        $params[] = $dataIni;
    }
    if ($dataFim !== '') {
        if (!data_valida($dataFim)) {
            throw new ValidacaoException('Data final inválida.');
        }
        $where[] = 'p.tempo_aplicacao <= ?';
        $params[] = $dataFim;
    }
    if ($dataIni !== '' && $dataFim !== '' && $dataIni > $dataFim) {
        throw new ValidacaoException('A data inicial não pode ser maior que a data final.');
    }
    $setoresGestor = escopo_setores_gestor();
    if (is_array($setoresGestor)) {
        if ($setoresGestor === []) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'r.setor_id IN (' . implode(',', array_fill(0, count($setoresGestor), '?')) . ')';
            array_push($params, ...$setoresGestor);
        }
    }
    $from = 'FROM planos_acao p
        JOIN reclamacoes r ON r.id = p.reclamacao_id
        JOIN setores st ON st.id = r.setor_id
        LEFT JOIN empresas emp ON emp.id = st.empresa_id';
    $sqlWhere = implode(' AND ', $where);
    $valida = expr_prazo_valido();
    $contagem = consultar_um(
        "SELECT COUNT(*) AS todas,
            COALESCE(SUM(CASE WHEN {$valida} AND p.tempo_aplicacao < ? THEN 1 ELSE 0 END), 0) AS atrasada,
            COALESCE(SUM(CASE WHEN {$valida} AND p.tempo_aplicacao = ? THEN 1 ELSE 0 END), 0) AS hoje,
            COALESCE(SUM(CASE WHEN {$valida} AND p.tempo_aplicacao > ? THEN 1 ELSE 0 END), 0) AS prazo
         {$from} WHERE {$sqlWhere}",
        array_merge([$hoje, $hoje, $hoje], $params)
    ) ?? ['todas' => 0, 'atrasada' => 0, 'hoje' => 0, 'prazo' => 0];
    $whereLista = $where;
    $paramsLista = $params;
    if ($situacao === 'atrasada') {
        $whereLista[] = "{$valida} AND p.tempo_aplicacao < ?";
        $paramsLista[] = $hoje;
    } elseif ($situacao === 'hoje') {
        $whereLista[] = "{$valida} AND p.tempo_aplicacao = ?";
        $paramsLista[] = $hoje;
    } elseif ($situacao === 'prazo') {
        $whereLista[] = "{$valida} AND p.tempo_aplicacao > ?";
        $paramsLista[] = $hoje;
    }
    $sqlLista = implode(' AND ', $whereLista);
    $total = (int) valor("SELECT COUNT(*) {$from} WHERE {$sqlLista}", $paramsLista);
    $porPagina = 20;
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $paginas);
    $offset = ($pagina - 1) * $porPagina;
    $linhas = consultar(
        "SELECT p.id, p.acao, p.responsavel, p.tempo_aplicacao, p.status,
            r.id AS reclamacao_id, r.protocolo, r.nome_cliente,
            st.nome AS setor_nome, emp.nome AS empresa_nome
         {$from} WHERE {$sqlLista}
         ORDER BY CASE
            WHEN {$valida} AND p.tempo_aplicacao < ? THEN 0
            WHEN {$valida} AND p.tempo_aplicacao = ? THEN 1
            WHEN {$valida} AND p.tempo_aplicacao > ? THEN 2
            ELSE 3 END, p.tempo_aplicacao ASC, p.id ASC
         LIMIT {$porPagina} OFFSET {$offset}",
        array_merge($paramsLista, [$hoje, $hoje, $hoje])
    );
    return [
        'total' => $total,
        'pagina' => $pagina,
        'paginas' => $paginas,
        'por_pagina' => $porPagina,
        'contagens' => [
            'todas' => (int) ($contagem['todas'] ?? 0),
            'atrasada' => (int) ($contagem['atrasada'] ?? 0),
            'hoje' => (int) ($contagem['hoje'] ?? 0),
            'prazo' => (int) ($contagem['prazo'] ?? 0),
        ],
        'itens' => array_map(static function (array $linha) use ($hoje): array {
            $situacaoPrazo = situacao_acao((string) $linha['tempo_aplicacao'], $hoje);
            return [
                'id' => (int) $linha['id'],
                'reclamacao_id' => (int) $linha['reclamacao_id'],
                'protocolo' => $linha['protocolo'],
                'nome_cliente' => $linha['nome_cliente'],
                'acao' => $linha['acao'],
                'responsavel' => $linha['responsavel'],
                'prazo' => $linha['tempo_aplicacao'],
                'situacao' => $situacaoPrazo['status'],
                'dias' => $situacaoPrazo['dias'],
                'status' => status_acao_valido((string) ($linha['status'] ?? '')),
                'setor_nome' => $linha['setor_nome'],
                'empresa_nome' => is_string($linha['empresa_nome'] ?? null) ? $linha['empresa_nome'] : '',
            ];
        }, $linhas),
    ];
}

function resumo_reclamacao(array $linha): array
{
    return [
        'id' => (int) $linha['id'],
        'protocolo' => $linha['protocolo'],
        'origem' => $linha['origem'],
        'nome_cliente' => $linha['nome_cliente'],
        'criado_em' => $linha['criado_em'],
        'status_id' => (int) $linha['status_id'],
        'status_nome' => $linha['status_nome'],
        'status_fixo' => $linha['status_fixo'] !== null && $linha['status_fixo'] !== '' ? $linha['status_fixo'] : null,
        'status_cor' => cor_status(is_string($linha['status_cor'] ?? null) ? $linha['status_cor'] : '', $linha['status_fixo'] !== null && $linha['status_fixo'] !== '' ? (string) $linha['status_fixo'] : null),
        'situacao_id' => $linha['situacao_id'] !== null ? (int) $linha['situacao_id'] : null,
        'situacao_nome' => $linha['situacao_nome'],
        'situacao_cor' => !empty($linha['situacao_nome']) ? cor_status(is_string($linha['situacao_cor'] ?? null) ? $linha['situacao_cor'] : '', null) : null,
        'setor_nome' => $linha['setor_nome'],
        'empresa_nome' => is_string($linha['empresa_nome'] ?? null) ? $linha['empresa_nome'] : '',
        'tipo_nome' => $linha['tipo_nome'],
    ] + tempo_reclamacao($linha);
}

function segundos_entre(string $inicio, ?string $fim): int
{
    $ini = DateTime::createFromFormat('Y-m-d H:i:s', $inicio) ?: date_create($inicio);
    if (!$ini instanceof DateTimeInterface) {
        return 0;
    }
    $ate = $fim !== null && $fim !== ''
        ? (DateTime::createFromFormat('Y-m-d H:i:s', $fim) ?: date_create($fim))
        : new DateTimeImmutable('now');
    if (!$ate instanceof DateTimeInterface) {
        return 0;
    }
    return max(0, $ate->getTimestamp() - $ini->getTimestamp());
}

function tempo_reclamacao(array $linha): array
{
    $final = ($linha['status_fixo'] ?? '') === 'final';
    $fim = null;
    if ($final) {
        $fim = (string) ($linha['concluida_em'] ?? '');
        if ($fim === '') {
            $fim = (string) ($linha['atualizado_em'] ?? '');
        }
    }
    return [
        'tempo_segundos' => segundos_entre((string) ($linha['criado_em'] ?? ''), $fim !== '' ? $fim : null),
        'tempo_aberto' => !$final,
    ];
}

function expr_mes_reclamacao(): string
{
    return "substr(COALESCE(NULLIF(r.data_reclamacao, ''), r.criado_em), 1, 7)";
}

function expr_segundos(string $inicio, string $fim): string
{
    if (driver_banco() === 'mysql') {
        return 'TIMESTAMPDIFF(SECOND, ' . $inicio . ', ' . $fim . ')';
    }
    return 'CAST((julianday(' . $fim . ') - julianday(' . $inicio . ')) * 86400 AS INTEGER)';
}

function resumo_tempo(array $linha): array
{
    return [
        'id' => (int) $linha['id'],
        'protocolo' => $linha['protocolo'],
        'nome_cliente' => $linha['nome_cliente'],
        'setor_nome' => $linha['setor_nome'],
    ] + tempo_reclamacao($linha);
}

function detalhar_reclamacao(int $id): array
{
    if ($id <= 0) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
    exigir_reclamacao_gestor($id);
    $linha = consultar_um(
        'SELECT r.*, s.nome AS status_nome, s.fixo AS status_fixo, st.nome AS setor_nome, emp.nome AS empresa_nome,
            t.nome AS tipo_nome, si.nome AS situacao_nome, si.cor AS situacao_cor, u.nome AS autor_nome,
            ua.nome AS acao_autor, uc.nome AS causa_autor, up.nome AS plano_autor '
        . from_reclamacoes()
        . ' LEFT JOIN empresas emp ON emp.id = st.empresa_id
            LEFT JOIN usuarios u ON u.id = r.usuario_id
            LEFT JOIN usuarios ua ON ua.id = r.acao_usuario_id
            LEFT JOIN usuarios uc ON uc.id = r.causa_usuario_id
            LEFT JOIN usuarios up ON up.id = r.plano_usuario_id
            WHERE r.id = ?',
        [$id]
    );
    if ($linha === null) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
    $resumo = resumo_reclamacao($linha);
    $resumo['setor_id'] = (int) $linha['setor_id'];
    $resumo['unidade'] = $linha['unidade'] ?? null;
    $resumo['responsavel_denuncia'] = $linha['responsavel_denuncia'] ?? null;
    $resumo['email_cliente'] = $linha['email_cliente'];
    $resumo['telefone_cliente'] = $linha['telefone_cliente'];
    $resumo['data_reclamacao'] = $linha['data_reclamacao'] ?? null;
    $resumo['responsavel_atendimento'] = $linha['responsavel_atendimento'] ?? null;
    $resumo['descricao'] = $linha['descricao'];
    $resumo['acao_imediata'] = trim((string) ($linha['acao_imediata'] ?? ''));
    $resumo['causa'] = trim((string) ($linha['causa'] ?? ''));
    $resumo['acao_confirmacao'] = confirmacao_etapa($linha['acao_autor'] ?? null, $linha['acao_confirmada_em'] ?? null);
    $resumo['causa_confirmacao'] = confirmacao_etapa($linha['causa_autor'] ?? null, $linha['causa_confirmada_em'] ?? null);
    $resumo['plano_confirmacao'] = confirmacao_etapa($linha['plano_autor'] ?? null, $linha['plano_confirmada_em'] ?? null);
    $resumo['plano'] = array_map(static fn (array $item): array => [
        'id' => (int) $item['id'],
        'acao' => $item['acao'],
        'responsavel' => $item['responsavel'],
        'tempo_aplicacao' => $item['tempo_aplicacao'],
        'status' => status_acao_valido((string) ($item['status'] ?? '')),
    ], consultar(
        'SELECT id, acao, responsavel, tempo_aplicacao, status FROM planos_acao WHERE reclamacao_id = ? ORDER BY ordem, id',
        [$id]
    ));
    $resumo['conclusao'] = $linha['conclusao'];
    $resumo['concluida_em'] = $linha['concluida_em'];
    $resumo['atualizado_em'] = $linha['atualizado_em'];
    $resumo['autor_nome'] = $linha['autor_nome'];
    $comentarios = consultar(
        'SELECT c.id, c.usuario_id, c.texto, c.criado_em, c.editado_em, c.excluido_em, u.nome AS autor, u.foto AS autor_foto
         FROM comentarios c JOIN usuarios u ON u.id = c.usuario_id
         WHERE c.reclamacao_id = ? ORDER BY c.id ASC',
        [$id]
    );
    $anexosComentario = consultar(
        'SELECT a.id, a.comentario_id, a.nome_original, a.mime, a.tamanho FROM comentarios_anexos a JOIN comentarios c ON c.id = a.comentario_id WHERE c.reclamacao_id = ? ORDER BY a.id',
        [$id]
    );
    $resumo['comentarios'] = montar_comentarios($comentarios, $anexosComentario);
    $resumo['movimentacoes'] = array_map(static fn (array $item): array => [
        'id' => (int) $item['id'],
        'descricao' => $item['descricao'],
        'criado_em' => $item['criado_em'],
        'autor' => $item['autor'],
    ], consultar(
        'SELECT m.id, m.descricao, m.criado_em, u.nome AS autor FROM movimentacoes m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.reclamacao_id = ? ORDER BY m.id DESC',
        [$id]
    ));
    $resumo['anexos'] = array_map(static fn (array $item): array => [
        'id' => (int) $item['id'],
        'nome_original' => $item['nome_original'],
        'tamanho' => (int) $item['tamanho'],
        'contexto' => $item['contexto'],
        'criado_em' => $item['criado_em'],
    ], consultar(
        'SELECT id, nome_original, tamanho, contexto, criado_em FROM anexos WHERE reclamacao_id = ? ORDER BY id',
        [$id]
    ));
    return $resumo;
}

function alterar_setor_reclamacao(int $usuarioId): void
{
    $id = inteiro($_POST['id'] ?? 0);
    $setorId = inteiro($_POST['setor_id'] ?? 0);
    if ($id <= 0 || $setorId <= 0) {
        throw new ValidacaoException('Selecione o setor da reclamação.');
    }
    exigir_reclamacao_gestor($id);
    $atual = consultar_um(
        'SELECT r.id, r.setor_id, s.fixo AS status_fixo, st.nome AS setor_nome, st.empresa_id, emp.nome AS empresa_nome
         FROM reclamacoes r
         JOIN setores st ON st.id = r.setor_id
         JOIN status_reclamacao s ON s.id = r.status_id
         LEFT JOIN empresas emp ON emp.id = st.empresa_id
         WHERE r.id = ?',
        [$id]
    );
    if ($atual === null) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
    if (($atual['status_fixo'] ?? '') === 'final') {
        throw new ValidacaoException('Não é possível alterar o setor de uma reclamação concluída.');
    }
    if ((int) $atual['setor_id'] === $setorId) {
        return;
    }
    $novo = consultar_um(
        'SELECT s.id, s.nome, s.ativo, s.empresa_id, e.nome AS empresa_nome
         FROM setores s
         LEFT JOIN empresas e ON e.id = s.empresa_id
         WHERE s.id = ?',
        [$setorId]
    );
    if ($novo === null || (int) $novo['ativo'] !== 1) {
        throw new ValidacaoException('Selecione um setor ativo.');
    }
    if ((int) ($novo['empresa_id'] ?? 0) <= 0 || (int) $novo['empresa_id'] !== (int) ($atual['empresa_id'] ?? 0)) {
        throw new ValidacaoException('O setor precisa ser da mesma empresa da reclamação.');
    }
    $momento = agora();
    $descricao = 'Setor alterado de ' . rotulo_setor_movimento($atual) . ' para ' . rotulo_setor_movimento($novo) . '.';
    transacao(static function (PDO $pdo) use ($id, $setorId, $usuarioId, $descricao, $momento): void {
        $pdo->prepare('UPDATE reclamacoes SET setor_id = ?, atualizado_em = ? WHERE id = ?')->execute([$setorId, $momento, $id]);
        $pdo->prepare('INSERT INTO movimentacoes (reclamacao_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)')
            ->execute([$id, $usuarioId, $descricao, $momento]);
    });
}

function rotulo_setor_movimento(array $linha): string
{
    $nome = trim((string) ($linha['setor_nome'] ?? $linha['nome'] ?? ''));
    $empresa = trim((string) ($linha['empresa_nome'] ?? ''));
    return $empresa !== '' ? $nome . ' (' . $empresa . ')' : $nome;
}

function validar_entrada_reclamacao(array $entrada): array
{
    $nome = limpar_texto(
        (string) ($entrada['nome_cliente'] ?? ''),
        2,
        150,
        'Informe o nome da empresa cliente.'
    );
    $email = trim((string) ($entrada['email_cliente'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ValidacaoException('Informe um e-mail válido.');
    }
    if (mb_strlen($email) > 150) {
        throw new ValidacaoException('O e-mail é muito longo.');
    }
    $telefone = trim((string) ($entrada['telefone_cliente'] ?? ''));
    if ($telefone !== '' && preg_match('/^[0-9+()\-\s]{8,30}$/', $telefone) !== 1) {
        throw new ValidacaoException('Informe um telefone válido.');
    }
    $descricao = trim((string) ($entrada['descricao'] ?? ''));
    $descricao = preg_replace("/\r\n?/", "\n", $descricao) ?? $descricao;
    if (mb_strlen($descricao) < 100) {
        throw new ValidacaoException('Descreva a reclamação com pelo menos 100 caracteres.');
    }
    if (mb_strlen($descricao) > 5000) {
        throw new ValidacaoException('A descrição passa de 5.000 caracteres.');
    }
    $unidade = texto_opcional((string) ($entrada['unidade'] ?? ''), 120);
    $denuncia = limpar_texto((string) ($entrada['responsavel_denuncia'] ?? ''), 2, 150, 'Informe quem fez a reclamação.');
    $atendimento = limpar_texto((string) ($entrada['responsavel_atendimento'] ?? ''), 2, 150, 'Informe quem está abrindo a reclamação.');
    $dataReclamacao = trim((string) ($entrada['data_reclamacao'] ?? ''));
    if (!data_valida($dataReclamacao)) {
        throw new ValidacaoException('Informe a data da reclamação.');
    }
    return [
        'nome_cliente' => $nome,
        'unidade' => $unidade,
        'responsavel_denuncia' => $denuncia,
        'email_cliente' => $email !== '' ? $email : null,
        'telefone_cliente' => $telefone !== '' ? $telefone : null,
        'data_reclamacao' => $dataReclamacao,
        'descricao' => $descricao,
        'responsavel_atendimento' => $atendimento,
        'setor_id' => exigir_id($entrada['setor_id'] ?? 0, 'Selecione o setor da reclamação.'),
        'empresa_id' => exigir_id($entrada['empresa_id'] ?? 0, 'Selecione a empresa.'),
        'tipo_id' => exigir_id($entrada['tipo_id'] ?? 0, 'Selecione o tipo de reclamação.'),
    ];
}

function texto_opcional(string $valor, int $max): ?string
{
    $valor = preg_replace('/\s+/u', ' ', trim($valor)) ?? '';
    if ($valor === '') {
        return null;
    }
    if (mb_strlen($valor) > $max) {
        throw new ValidacaoException('O texto informado é muito longo.');
    }
    return $valor;
}

function exigir_id(mixed $valor, string $erro): int
{
    $id = inteiro($valor);
    if ($id <= 0) {
        throw new ValidacaoException($erro);
    }
    return $id;
}

function exigir_registro_ativo(PDO $pdo, string $tabela, int $id, string $rotulo): void
{
    $permitidas = ['setores', 'tipos_reclamacao', 'situacoes', 'status_reclamacao', 'empresas'];
    if (!in_array($tabela, $permitidas, true)) {
        throw new RuntimeException('Tabela inválida.');
    }
    $stmt = $pdo->prepare("SELECT * FROM {$tabela} WHERE id = ?");
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    if ($linha === false) {
        throw new ValidacaoException($rotulo . ' não encontrado.');
    }
    if (array_key_exists('ativo', $linha) && (int) $linha['ativo'] !== 1) {
        throw new ValidacaoException($rotulo . ' está inativo.');
    }
}

function proximo_protocolo(PDO $pdo): string
{
    $usados = [];
    foreach ($pdo->query('SELECT protocolo FROM reclamacoes')->fetchAll(PDO::FETCH_COLUMN) as $protocolo) {
        $usados[(string) $protocolo] = true;
    }
    return protocolo_quatro_digitos($usados);
}

function protocolo_por_id(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT protocolo FROM reclamacoes WHERE id = ?');
    $stmt->execute([$id]);
    $protocolo = $stmt->fetchColumn();
    if (!is_string($protocolo)) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
    return protocolo_anexo($protocolo);
}

function criar_reclamacao(array $entrada, ?int $usuarioId, string $origem, array $anexos): array
{
    $dados = validar_entrada_reclamacao($entrada);
    $gravados = [];
    try {
        $criada = transacao(function (PDO $pdo) use ($dados, $usuarioId, $origem, $anexos, &$gravados): array {
            exigir_registro_ativo($pdo, 'empresas', $dados['empresa_id'], 'Empresa');
            exigir_registro_ativo($pdo, 'setores', $dados['setor_id'], 'Setor');
            $stmtSetor = $pdo->prepare('SELECT empresa_id FROM setores WHERE id = ?');
            $stmtSetor->execute([$dados['setor_id']]);
            $empresaDoSetor = $stmtSetor->fetchColumn();
            if ($empresaDoSetor === false || (int) $empresaDoSetor !== $dados['empresa_id']) {
                throw new ValidacaoException('O setor não pertence à empresa selecionada.');
            }
            exigir_registro_ativo($pdo, 'tipos_reclamacao', $dados['tipo_id'], 'Tipo');
            $status = $pdo->query("SELECT id FROM status_reclamacao WHERE fixo = 'inicial' LIMIT 1")->fetch();
            if ($status === false) {
                throw new RuntimeException('Status inicial ausente.');
            }
            $protocolo = '';
            $id = 0;
            $inserir = $pdo->prepare('INSERT INTO reclamacoes (
                protocolo, origem, nome_cliente, unidade, responsavel_denuncia, email_cliente, telefone_cliente,
                data_reclamacao, setor_id, tipo_id, descricao, responsavel_atendimento,
                status_id, usuario_id, criado_em, atualizado_em
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            for ($tentativa = 0; $tentativa < 5; $tentativa++) {
                $protocolo = proximo_protocolo($pdo);
                try {
                    $momento = agora();
                    $inserir->execute([
                        $protocolo,
                        $origem,
                        $dados['nome_cliente'],
                        $dados['unidade'],
                        $dados['responsavel_denuncia'],
                        $dados['email_cliente'],
                        $dados['telefone_cliente'],
                        $dados['data_reclamacao'],
                        $dados['setor_id'],
                        $dados['tipo_id'],
                        $dados['descricao'],
                        $dados['responsavel_atendimento'],
                        (int) $status['id'],
                        $usuarioId,
                        $momento,
                        $momento,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                    break;
                } catch (PDOException $e) {
                    if ($tentativa === 4 || !str_contains(mb_strtolower($e->getMessage()), 'protocolo')) {
                        throw $e;
                    }
                }
            }
            gravar_anexos($pdo, $id, $usuarioId, 'abertura', $anexos, $gravados);
            $texto = $origem === 'publica'
                ? 'Reclamação registrada pelo formulário público.'
                : 'Reclamação registrada pela equipe.';
            $mov = $pdo->prepare('INSERT INTO movimentacoes (reclamacao_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)');
            $mov->execute([$id, $usuarioId, $texto, agora()]);
            return ['id' => $id, 'protocolo' => $protocolo];
        });
    } catch (Throwable $e) {
        apagar_arquivos($gravados);
        throw $e;
    }
    alertar_nova_reclamacao((int) $criada['id']);
    return $criada;
}

function gravar_anexos(PDO $pdo, int $reclamacaoId, ?int $usuarioId, string $contexto, array $anexos, array &$gravados): void
{
    if (!in_array($contexto, ['abertura', 'conclusao'], true)) {
        throw new RuntimeException('Contexto de anexo inválido.');
    }
    $protocolo = protocolo_por_id($pdo, $reclamacaoId);
    $inserir = $pdo->prepare('INSERT INTO anexos (reclamacao_id, usuario_id, contexto, nome_original, arquivo, mime, tamanho, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($anexos as $anexo) {
        $movido = mover_anexo($anexo, $protocolo);
        $gravados[] = $movido['caminho'];
        $inserir->execute([
            $reclamacaoId,
            $usuarioId,
            $contexto,
            $anexo['original'],
            $movido['arquivo'],
            $anexo['mime'],
            $anexo['tamanho'],
            agora(),
        ]);
    }
}

function confirmacao_etapa(mixed $nome, mixed $quando): ?array
{
    $quando = trim((string) $quando);
    if ($quando === '') {
        return null;
    }
    $nome = trim((string) $nome);
    return [
        'nome' => $nome !== '' ? $nome : 'Usuário',
        'em' => $quando,
    ];
}

function texto_tratativa(string $valor, string $erro): string
{
    $valor = preg_replace("/\r\n?/", "\n", trim($valor)) ?? '';
    if (mb_strlen($valor) < 100) {
        throw new ValidacaoException($erro);
    }
    if (mb_strlen($valor) > 5000) {
        throw new ValidacaoException('O texto passa de 5.000 caracteres.');
    }
    return $valor;
}

function data_prazo(string $valor): string
{
    $valor = trim($valor);
    if (!data_valida($valor)) {
        throw new ValidacaoException('Informe até quando a ação deve ser realizada.');
    }
    if ($valor < date('Y-m-d')) {
        throw new ValidacaoException('A data da ação não pode ser anterior a hoje.');
    }
    return $valor;
}

function ler_plano_acao(): array
{
    $bruto = json_decode((string) ($_POST['acoes'] ?? ''), true);
    if (!is_array($bruto)) {
        throw new ValidacaoException('Adicione ao menos uma ação.');
    }
    $acoes = [];
    foreach ($bruto as $item) {
        if (!is_array($item)) {
            continue;
        }
        $acao = trim((string) ($item['acao'] ?? ''));
        $responsavel = trim((string) ($item['responsavel'] ?? ''));
        $tempo = trim((string) ($item['tempo'] ?? ''));
        if ($acao === '' && $responsavel === '' && $tempo === '') {
            continue;
        }
        $acoes[] = [
            'id' => inteiro($item['id'] ?? 0),
            'acao' => limpar_texto($acao, 2, 500, 'Informe a ação.'),
            'responsavel' => limpar_texto($responsavel, 2, 150, 'Informe o responsável.'),
            'tempo' => data_prazo($tempo),
        ];
    }
    if ($acoes === []) {
        throw new ValidacaoException('Adicione ao menos uma ação.');
    }
    if (count($acoes) > 30) {
        throw new ValidacaoException('O plano pode ter no máximo 30 ações.');
    }
    return $acoes;
}

function papel_status(?string $fixo): string
{
    if ($fixo === 'inicial') {
        return 'inicial';
    }
    if ($fixo === 'final') {
        return 'final';
    }
    return 'meio';
}

function status_por_papel(PDO $pdo, string $papel): array
{
    if ($papel === 'inicial') {
        $sql = "SELECT id, nome FROM status_reclamacao WHERE fixo = 'inicial' ORDER BY id LIMIT 1";
    } elseif ($papel === 'final') {
        $sql = "SELECT id, nome FROM status_reclamacao WHERE fixo = 'final' ORDER BY id LIMIT 1";
    } else {
        $sql = "SELECT id, nome FROM status_reclamacao WHERE fixo IS NULL OR fixo = '' ORDER BY ordem, id LIMIT 1";
    }
    $linha = $pdo->query($sql)->fetch();
    if ($linha === false) {
        throw new RuntimeException('Status automático ausente.');
    }
    return ['id' => (int) $linha['id'], 'nome' => (string) $linha['nome']];
}

function registrar_avanco(PDO $pdo, array $atual, ?string $papelDestino, int $usuarioId, string $texto, string $momento): void
{
    $descricao = $texto;
    $rank = ['inicial' => 0, 'meio' => 1, 'final' => 2];
    $papelAtual = papel_status(is_string($atual['status_fixo'] ?? null) && $atual['status_fixo'] !== '' ? (string) $atual['status_fixo'] : null);
    if ($papelDestino !== null && ($rank[$papelDestino] ?? 0) > ($rank[$papelAtual] ?? 0)) {
        $destino = status_por_papel($pdo, $papelDestino);
        if ($papelDestino === 'final') {
            $pdo->prepare('UPDATE reclamacoes SET status_id = ?, concluida_em = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$destino['id'], $momento, $momento, (int) $atual['id']]);
        } else {
            $pdo->prepare('UPDATE reclamacoes SET status_id = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$destino['id'], $momento, (int) $atual['id']]);
        }
        $descricao .= ' Status alterado de ' . $atual['status_nome'] . ' para ' . $destino['nome'] . '.';
    } else {
        $pdo->prepare('UPDATE reclamacoes SET atualizado_em = ? WHERE id = ?')->execute([$momento, (int) $atual['id']]);
    }
    $pdo->prepare('INSERT INTO movimentacoes (reclamacao_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([(int) $atual['id'], $usuarioId, mb_substr($descricao, 0, 8000), $momento]);
}

function iniciar_tratativa(int $usuarioId): void
{
    $id = inteiro($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new ValidacaoException('Informe a reclamação.');
    }
    exigir_reclamacao_gestor($id);
    transacao(function (PDO $pdo) use ($usuarioId, $id): void {
        $stmt = $pdo->prepare('SELECT r.*, s.nome AS status_nome, s.fixo AS status_fixo FROM reclamacoes r JOIN status_reclamacao s ON s.id = r.status_id WHERE r.id = ?');
        $stmt->execute([$id]);
        $atual = $stmt->fetch();
        if ($atual === false) {
            throw new ValidacaoException('Reclamação não encontrada.');
        }
        $fixo = is_string($atual['status_fixo'] ?? null) && $atual['status_fixo'] !== '' ? (string) $atual['status_fixo'] : null;
        if (papel_status($fixo) !== 'inicial') {
            throw new ValidacaoException('A tratativa desta reclamação já foi iniciada.');
        }
        registrar_avanco($pdo, $atual, 'meio', $usuarioId, 'Tratativa iniciada.', agora());
    });
}

function salvar_tratativa(int $usuarioId): void
{
    $id = inteiro($_POST['id'] ?? 0);
    $etapa = (string) ($_POST['etapa'] ?? '');
    if ($id <= 0 || !in_array($etapa, ['acao', 'causa', 'plano'], true)) {
        throw new ValidacaoException('Informe a tratativa.');
    }
    exigir_reclamacao_gestor($id);
    $texto = '';
    $acoes = [];
    if ($etapa === 'plano') {
        $acoes = ler_plano_acao();
    } else {
        $texto = texto_tratativa(
            (string) ($_POST['texto'] ?? ''),
            $etapa === 'acao'
                ? 'Descreva a ação imediata com pelo menos 100 caracteres.'
                : 'Descreva a causa com pelo menos 100 caracteres.'
        );
    }
    transacao(function (PDO $pdo) use ($usuarioId, $id, $etapa, $texto, $acoes): void {
        $stmt = $pdo->prepare('SELECT r.*, s.nome AS status_nome, s.fixo AS status_fixo FROM reclamacoes r JOIN status_reclamacao s ON s.id = r.status_id WHERE r.id = ?');
        $stmt->execute([$id]);
        $atual = $stmt->fetch();
        if ($atual === false) {
            throw new ValidacaoException('Reclamação não encontrada.');
        }
        $momento = agora();
        $fixo = is_string($atual['status_fixo'] ?? null) && $atual['status_fixo'] !== '' ? (string) $atual['status_fixo'] : null;
        if (papel_status($fixo) === 'inicial') {
            throw new ValidacaoException('Inicie a tratativa antes de registrar.');
        }
        if ($etapa === 'acao') {
            if (trim((string) ($atual['acao_imediata'] ?? '')) !== '') {
                throw new ValidacaoException('A ação imediata já foi concluída.');
            }
            $pdo->prepare('UPDATE reclamacoes SET acao_imediata = ?, acao_usuario_id = ?, acao_confirmada_em = ? WHERE id = ?')
                ->execute([$texto, $usuarioId, $momento, $id]);
            registrar_avanco($pdo, $atual, null, $usuarioId, 'Ação imediata registrada.', $momento);
            return;
        }
        if (trim((string) ($atual['acao_imediata'] ?? '')) === '') {
            throw new ValidacaoException('Registre a ação imediata antes de continuar.');
        }
        if ($etapa === 'causa') {
            if (trim((string) ($atual['causa'] ?? '')) !== '') {
                throw new ValidacaoException('A identificação da causa já foi concluída.');
            }
            $pdo->prepare('UPDATE reclamacoes SET causa = ?, causa_usuario_id = ?, causa_confirmada_em = ? WHERE id = ?')
                ->execute([$texto, $usuarioId, $momento, $id]);
            registrar_avanco($pdo, $atual, null, $usuarioId, 'Causa identificada.', $momento);
            return;
        }
        if (trim((string) ($atual['causa'] ?? '')) === '') {
            throw new ValidacaoException('Identifique a causa antes de montar o plano de ação.');
        }
        $existentes = (int) valor('SELECT COUNT(*) FROM planos_acao WHERE reclamacao_id = ?', [$id]);
        if ($existentes > 0) {
            throw new ValidacaoException('O plano de ação já foi concluído.');
        }
        $inserir = $pdo->prepare('INSERT INTO planos_acao (reclamacao_id, ordem, acao, responsavel, tempo_aplicacao, status, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($acoes as $indice => $acao) {
            $inserir->execute([$id, $indice + 1, $acao['acao'], $acao['responsavel'], $acao['tempo'], 'aguardando', $momento]);
        }
        $pdo->prepare('UPDATE reclamacoes SET plano_usuario_id = ?, plano_confirmada_em = ? WHERE id = ?')
            ->execute([$usuarioId, $momento, $id]);
        registrar_avanco($pdo, $atual, 'final', $usuarioId, 'Plano de ação registrado.', $momento);
    });
}

function alterar_status(int $usuarioId, int $id, int $statusId, int $situacaoId, string $conclusao, array $anexos): void
{
    if ($id <= 0 || $statusId <= 0) {
        throw new ValidacaoException('Informe a reclamação e o status.');
    }
    exigir_reclamacao_gestor($id);
    $gravados = [];
    try {
        transacao(function (PDO $pdo) use ($usuarioId, $id, $statusId, $situacaoId, $conclusao, $anexos, &$gravados): void {
            $stmt = $pdo->prepare('SELECT r.*, s.nome AS status_nome, s.fixo AS status_fixo FROM reclamacoes r JOIN status_reclamacao s ON s.id = r.status_id WHERE r.id = ?');
            $stmt->execute([$id]);
            $atual = $stmt->fetch();
            if ($atual === false) {
                throw new ValidacaoException('Reclamação não encontrada.');
            }
            $stmt = $pdo->prepare('SELECT * FROM status_reclamacao WHERE id = ?');
            $stmt->execute([$statusId]);
            $novo = $stmt->fetch();
            if ($novo === false) {
                throw new ValidacaoException('Status não encontrado.');
            }
            if ((int) ($novo['ativo'] ?? 1) !== 1 && (int) $atual['status_id'] !== $statusId) {
                throw new ValidacaoException('Este status está inativo.');
            }
            $momento = agora();
            if ($novo['fixo'] === 'final') {
                if ($situacaoId <= 0) {
                    throw new ValidacaoException('Selecione a situação da conclusão.');
                }
                exigir_registro_ativo($pdo, 'situacoes', $situacaoId, 'Situação');
                [$texto, $puro] = html_formatado($conclusao, 5000, 'A conclusão');
                if (mb_strlen($puro) < 200) {
                    throw new ValidacaoException('Descreva a conclusão com pelo menos 200 caracteres.');
                }
                $stmt = $pdo->prepare('SELECT nome FROM situacoes WHERE id = ?');
                $stmt->execute([$situacaoId]);
                $situacaoNome = (string) $stmt->fetchColumn();
                $descricao = 'Conclusão registrada. Situação: ' . $situacaoNome . '.';
                $upd = $pdo->prepare('UPDATE reclamacoes SET status_id = ?, situacao_id = ?, conclusao = ?, concluida_em = ?, atualizado_em = ? WHERE id = ?');
                $upd->execute([$statusId, $situacaoId, $texto, $momento, $momento, $id]);
                $comentario = $pdo->prepare('INSERT INTO comentarios (reclamacao_id, usuario_id, texto, criado_em) VALUES (?, ?, ?, ?)');
                $comentario->execute([$id, $usuarioId, '[[conclusao]]' . rawurlencode($situacaoNome) . ']]' . $texto, $momento]);
                $comentarioId = (int) $pdo->lastInsertId();
                $inserirAnexo = $pdo->prepare('INSERT INTO comentarios_anexos (comentario_id, nome_original, arquivo, mime, tamanho, criado_em) VALUES (?, ?, ?, ?, ?, ?)');
                $protocolo = protocolo_por_id($pdo, $id);
                foreach ($anexos as $anexo) {
                    $movido = mover_anexo($anexo, $protocolo);
                    $gravados[] = $movido['caminho'];
                    $inserirAnexo->execute([$comentarioId, $anexo['original'], $movido['arquivo'], $anexo['mime'], $anexo['tamanho'], $momento]);
                }
            } else {
                if ((int) $atual['status_id'] === $statusId) {
                    throw new ValidacaoException('A reclamação já está com este status.');
                }
                $descricao = 'Status alterado de ' . $atual['status_nome'] . ' para ' . $novo['nome'] . '.';
                if ($atual['status_fixo'] === 'final') {
                    $descricao = 'Reclamação reaberta. ' . $descricao;
                }
                $upd = $pdo->prepare('UPDATE reclamacoes SET status_id = ?, atualizado_em = ? WHERE id = ?');
                $upd->execute([$statusId, $momento, $id]);
            }
            $mov = $pdo->prepare('INSERT INTO movimentacoes (reclamacao_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)');
            $mov->execute([$id, $usuarioId, mb_substr($descricao, 0, 8000), $momento]);
        });
    } catch (Throwable $e) {
        apagar_arquivos($gravados);
        throw $e;
    }
}

function reabrir_reclamacao(int $usuarioId, int $id): void
{
    if ($id <= 0) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
    exigir_reclamacao_gestor($id);
    transacao(function (PDO $pdo) use ($usuarioId, $id): void {
        $stmt = $pdo->prepare('SELECT r.id, s.nome AS status_nome, s.fixo AS status_fixo FROM reclamacoes r JOIN status_reclamacao s ON s.id = r.status_id WHERE r.id = ?');
        $stmt->execute([$id]);
        $atual = $stmt->fetch();
        if ($atual === false) {
            throw new ValidacaoException('Reclamação não encontrada.');
        }
        if ($atual['status_fixo'] !== 'final') {
            throw new ValidacaoException('Só é possível reabrir uma tratativa encerrada.');
        }
        $inicial = $pdo->query("SELECT id, nome FROM status_reclamacao WHERE fixo = 'inicial' LIMIT 1")->fetch();
        if ($inicial === false) {
            throw new ValidacaoException('Não há status inicial configurado.');
        }
        $momento = agora();
        $pdo->prepare('UPDATE reclamacoes SET status_id = ?, situacao_id = NULL, conclusao = NULL, concluida_em = NULL, atualizado_em = ? WHERE id = ?')
            ->execute([(int) $inicial['id'], $momento, $id]);
        $texto = 'Reclamação reaberta.';
        $pdo->prepare('INSERT INTO comentarios (reclamacao_id, usuario_id, texto, criado_em) VALUES (?, ?, ?, ?)')
            ->execute([$id, $usuarioId, $texto, $momento]);
        $descricao = 'Reclamação reaberta. Status alterado de ' . $atual['status_nome'] . ' para ' . $inicial['nome'] . '.';
        $pdo->prepare('INSERT INTO movimentacoes (reclamacao_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)')
            ->execute([$id, $usuarioId, $descricao, $momento]);
    });
}

function texto_plano(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', ' ', $html) ?? $html;
    $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $html) ?? '');
}

function html_formatado(string $html, int $maximo, string $rotulo): array
{
    $html = str_replace(['<strong>', '</strong>', '<em>', '</em>'], ['<b>', '</b>', '<i>', '</i>'], $html);
    $html = preg_replace('/<(div|p)\b[^>]*>/i', '', $html) ?? $html;
    $html = preg_replace('/<\/(div|p)>/i', '<br>', $html) ?? $html;
    $html = strip_tags($html, '<b><i><u><br>');
    $html = preg_replace('/<(b|i|u)\b[^>]*>/i', '<$1>', $html) ?? $html;
    $html = preg_replace('/<br\s*\/?>/i', '<br>', $html) ?? $html;
    $html = preg_replace('/^(?:<br>)+|(?:<br>)+$/i', '', $html) ?? $html;
    $puro = texto_plano($html);
    if (mb_strlen($puro) > $maximo) {
        throw new ValidacaoException($rotulo . ' passa de ' . number_format($maximo, 0, ',', '.') . ' caracteres.');
    }
    return [$html, $puro];
}

function texto_comentario(string $html): array
{
    return html_formatado($html, 2000, 'O comentário');
}

function adicionar_comentario(int $usuarioId, int $id, string $texto, array $anexos = []): void
{
    if ($id <= 0) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
    $linha = consultar_um(
        'SELECT r.id, s.fixo AS status_fixo FROM reclamacoes r JOIN status_reclamacao s ON s.id = r.status_id WHERE r.id = ?',
        [$id]
    );
    if ($linha === null) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
    if (($linha['status_fixo'] ?? '') === 'inicial') {
        throw new ValidacaoException('Os comentários ficam disponíveis depois que a tratativa for iniciada.');
    }
    if (($linha['status_fixo'] ?? '') === 'final') {
        throw new ValidacaoException('Esta reclamação foi concluída. Não é possível adicionar comentários.');
    }
    exigir_reclamacao_gestor($id);
    [$html, $puro] = texto_comentario($texto);
    if (mb_strlen($puro) < 2 && $anexos === []) {
        throw new ValidacaoException('Escreva um comentário.');
    }
    $momento = agora();
    $gravados = [];
    try {
        transacao(function (PDO $pdo) use ($usuarioId, $id, $html, $anexos, $momento, &$gravados): void {
            $pdo->prepare('INSERT INTO comentarios (reclamacao_id, usuario_id, texto, criado_em) VALUES (?, ?, ?, ?)')
                ->execute([$id, $usuarioId, $html, $momento]);
            $comentarioId = (int) $pdo->lastInsertId();
            $protocolo = protocolo_por_id($pdo, $id);
            $inserir = $pdo->prepare('INSERT INTO comentarios_anexos (comentario_id, nome_original, arquivo, mime, tamanho, criado_em) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($anexos as $anexo) {
                $movido = mover_anexo($anexo, $protocolo);
                $gravados[] = $movido['caminho'];
                $inserir->execute([$comentarioId, $anexo['original'], $movido['arquivo'], $anexo['mime'], $anexo['tamanho'], $momento]);
            }
            $pdo->prepare('INSERT INTO movimentacoes (reclamacao_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)')
                ->execute([$id, $usuarioId, 'Comentário adicionado.', $momento]);
            $pdo->prepare('UPDATE reclamacoes SET atualizado_em = ? WHERE id = ?')->execute([$momento, $id]);
        });
    } catch (Throwable $e) {
        apagar_arquivos($gravados);
        throw $e;
    }
}

function comentario_editavel(int $usuarioId, int $comentarioId, int $reclamacaoId, bool $somenteUltima): array
{
    if ($comentarioId <= 0 || $reclamacaoId <= 0) {
        throw new ValidacaoException('Mensagem não encontrada.');
    }
    exigir_reclamacao_gestor($reclamacaoId);
    $linha = consultar_um(
        'SELECT c.id, c.reclamacao_id, c.usuario_id, c.texto, c.excluido_em, s.fixo AS status_fixo
         FROM comentarios c
         JOIN reclamacoes r ON r.id = c.reclamacao_id
         JOIN status_reclamacao s ON s.id = r.status_id
         WHERE c.id = ?',
        [$comentarioId]
    );
    if ($linha === null || (int) $linha['reclamacao_id'] !== $reclamacaoId) {
        throw new ValidacaoException('Mensagem não encontrada.');
    }
    if (($linha['status_fixo'] ?? '') === 'inicial') {
        throw new ValidacaoException('Os comentários ficam disponíveis depois que a tratativa for iniciada.');
    }
    if (($linha['status_fixo'] ?? '') === 'final') {
        throw new ValidacaoException('Esta reclamação foi concluída. Não é possível alterar os comentários.');
    }
    if ((int) $linha['usuario_id'] !== $usuarioId) {
        throw new ValidacaoException('Você só pode alterar as próprias mensagens.');
    }
    if (str_starts_with((string) $linha['texto'], '[[conclusao]]')) {
        throw new ValidacaoException('Esta mensagem não pode ser alterada.');
    }
    if (trim((string) ($linha['excluido_em'] ?? '')) !== '') {
        throw new ValidacaoException('Esta mensagem já foi excluída.');
    }
    if ($somenteUltima) {
        $ultima = (int) valor(
            "SELECT id FROM comentarios WHERE reclamacao_id = ? AND texto NOT LIKE '[[conclusao]]%' ORDER BY id DESC LIMIT 1",
            [$reclamacaoId]
        );
        if ($ultima !== $comentarioId) {
            throw new ValidacaoException('Só é possível editar a última mensagem.');
        }
    }
    return $linha;
}

function editar_comentario(int $usuarioId, int $comentarioId, int $reclamacaoId, string $texto): void
{
    comentario_editavel($usuarioId, $comentarioId, $reclamacaoId, true);
    [$html, $puro] = texto_comentario($texto);
    if (mb_strlen($puro) < 2) {
        throw new ValidacaoException('Escreva a mensagem.');
    }
    $momento = agora();
    executar('UPDATE comentarios SET texto = ?, editado_em = ? WHERE id = ?', [$html, $momento, $comentarioId]);
}

function excluir_comentario(int $usuarioId, int $comentarioId, int $reclamacaoId): void
{
    comentario_editavel($usuarioId, $comentarioId, $reclamacaoId, false);
    executar('UPDATE comentarios SET texto = ?, excluido_em = ? WHERE id = ?', ['', agora(), $comentarioId]);
}

function montar_comentarios(array $comentarios, array $anexos): array
{
    return array_map(static function (array $item) use ($anexos): array {
        $idComentario = (int) $item['id'];
        $excluido = trim((string) ($item['excluido_em'] ?? '')) !== '';
        return [
            'id' => $idComentario,
            'usuario_id' => (int) $item['usuario_id'],
            'texto' => $excluido ? '' : $item['texto'],
            'criado_em' => $item['criado_em'],
            'editado_em' => $excluido ? null : ($item['editado_em'] ?? null),
            'excluido' => $excluido,
            'autor' => $item['autor'],
            'foto' => foto_segura(is_string($item['autor_foto'] ?? null) ? $item['autor_foto'] : null),
            'anexos' => $excluido ? [] : array_values(array_map(static fn (array $anexo): array => [
                'id' => (int) $anexo['id'],
                'nome_original' => $anexo['nome_original'],
                'mime' => $anexo['mime'],
                'tamanho' => (int) $anexo['tamanho'],
            ], array_filter($anexos, static fn (array $anexo): bool => (int) $anexo['comentario_id'] === $idComentario))),
        ];
    }, $comentarios);
}

function buscar_plano(int $id): array
{
    if ($id <= 0) {
        throw new ValidacaoException('Ação não encontrada.');
    }
    $linha = consultar_um(
        'SELECT p.id, p.acao, p.responsavel, p.tempo_aplicacao, p.status, p.reclamacao_id,
            r.protocolo, r.nome_cliente, st.nome AS setor_nome, emp.nome AS empresa_nome
         FROM planos_acao p
         JOIN reclamacoes r ON r.id = p.reclamacao_id
         JOIN setores st ON st.id = r.setor_id
         LEFT JOIN empresas emp ON emp.id = st.empresa_id
         WHERE p.id = ?',
        [$id]
    );
    if ($linha === null) {
        throw new ValidacaoException('Ação não encontrada.');
    }
    exigir_reclamacao_gestor((int) $linha['reclamacao_id']);
    return $linha;
}

function detalhar_acao(int $id): array
{
    $linha = buscar_plano($id);
    $hoje = (new DateTimeImmutable('today'))->format('Y-m-d');
    $situacaoPrazo = situacao_acao((string) $linha['tempo_aplicacao'], $hoje);
    $comentarios = consultar(
        'SELECT c.id, c.usuario_id, c.texto, c.criado_em, c.editado_em, c.excluido_em, u.nome AS autor, u.foto AS autor_foto
         FROM acoes_comentarios c JOIN usuarios u ON u.id = c.usuario_id
         WHERE c.plano_id = ? ORDER BY c.id ASC',
        [$id]
    );
    $anexos = consultar(
        'SELECT a.id, a.comentario_id, a.nome_original, a.mime, a.tamanho
         FROM acoes_comentarios_anexos a JOIN acoes_comentarios c ON c.id = a.comentario_id
         WHERE c.plano_id = ? ORDER BY a.id',
        [$id]
    );
    return [
        'id' => (int) $linha['id'],
        'reclamacao_id' => (int) $linha['reclamacao_id'],
        'protocolo' => $linha['protocolo'],
        'nome_cliente' => $linha['nome_cliente'],
        'acao' => $linha['acao'],
        'responsavel' => $linha['responsavel'],
        'prazo' => $linha['tempo_aplicacao'],
        'situacao' => $situacaoPrazo['status'],
        'dias' => $situacaoPrazo['dias'],
        'status' => status_acao_valido((string) ($linha['status'] ?? '')),
        'setor_nome' => $linha['setor_nome'],
        'empresa_nome' => is_string($linha['empresa_nome'] ?? null) ? $linha['empresa_nome'] : '',
        'comentarios' => montar_comentarios($comentarios, $anexos),
        'movimentacoes' => array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'descricao' => $item['descricao'],
            'criado_em' => $item['criado_em'],
            'autor' => $item['autor'],
        ], consultar(
            'SELECT m.id, m.descricao, m.criado_em, u.nome AS autor
             FROM acoes_movimentacoes m LEFT JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.plano_id = ? ORDER BY m.id DESC',
            [$id]
        )),
    ];
}

function comentario_acao_editavel(int $usuarioId, int $comentarioId, int $planoId, bool $somenteUltima): array
{
    if ($comentarioId <= 0 || $planoId <= 0) {
        throw new ValidacaoException('Mensagem não encontrada.');
    }
    $plano = buscar_plano($planoId);
    $linha = consultar_um(
        'SELECT id, plano_id, usuario_id, texto, excluido_em FROM acoes_comentarios WHERE id = ?',
        [$comentarioId]
    );
    if ($linha === null || (int) $linha['plano_id'] !== $planoId) {
        throw new ValidacaoException('Mensagem não encontrada.');
    }
    if ((string) ($plano['status'] ?? '') === 'concluido') {
        throw new ValidacaoException('Esta ação foi concluída. Os comentários estão bloqueados.');
    }
    if (str_starts_with((string) $linha['texto'], '[[conclusao]]')) {
        throw new ValidacaoException('Esta mensagem não pode ser alterada.');
    }
    if ((int) $linha['usuario_id'] !== $usuarioId) {
        throw new ValidacaoException('Você só pode alterar as próprias mensagens.');
    }
    if (trim((string) ($linha['excluido_em'] ?? '')) !== '') {
        throw new ValidacaoException('Esta mensagem já foi excluída.');
    }
    if ($somenteUltima) {
        $ultima = (int) valor(
            "SELECT id FROM acoes_comentarios WHERE plano_id = ? AND texto NOT LIKE '[[conclusao]]%' ORDER BY id DESC LIMIT 1",
            [$planoId]
        );
        if ($ultima !== $comentarioId) {
            throw new ValidacaoException('Só é possível editar a última mensagem.');
        }
    }
    return $linha;
}

function adicionar_comentario_acao(int $usuarioId, int $id, string $texto, array $anexos = []): void
{
    $plano = buscar_plano($id);
    if ((string) ($plano['status'] ?? '') === 'concluido') {
        throw new ValidacaoException('Esta ação foi concluída. Não é possível adicionar comentários.');
    }
    [$html, $puro] = texto_comentario($texto);
    if (mb_strlen($puro) < 2 && $anexos === []) {
        throw new ValidacaoException('Escreva um comentário.');
    }
    $momento = agora();
    $gravados = [];
    try {
        transacao(function (PDO $pdo) use ($usuarioId, $id, $plano, $html, $anexos, $momento, &$gravados): void {
            $pdo->prepare('INSERT INTO acoes_comentarios (plano_id, usuario_id, texto, criado_em) VALUES (?, ?, ?, ?)')
                ->execute([$id, $usuarioId, $html, $momento]);
            $comentarioId = (int) $pdo->lastInsertId();
            $protocolo = protocolo_por_id($pdo, (int) $plano['reclamacao_id']);
            $inserir = $pdo->prepare('INSERT INTO acoes_comentarios_anexos (comentario_id, nome_original, arquivo, mime, tamanho, criado_em) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($anexos as $anexo) {
                $movido = mover_anexo($anexo, $protocolo);
                $gravados[] = $movido['caminho'];
                $inserir->execute([$comentarioId, $anexo['original'], $movido['arquivo'], $anexo['mime'], $anexo['tamanho'], $momento]);
            }
            $pdo->prepare('INSERT INTO acoes_movimentacoes (plano_id, usuario_id, descricao, criado_em) VALUES (?, ?, ?, ?)')
                ->execute([$id, $usuarioId, 'Comentário adicionado.', $momento]);
        });
    } catch (Throwable $e) {
        apagar_arquivos($gravados);
        throw $e;
    }
}

function editar_comentario_acao(int $usuarioId, int $comentarioId, int $planoId, string $texto): void
{
    comentario_acao_editavel($usuarioId, $comentarioId, $planoId, true);
    [$html, $puro] = texto_comentario($texto);
    if (mb_strlen($puro) < 2) {
        throw new ValidacaoException('Escreva a mensagem.');
    }
    executar('UPDATE acoes_comentarios SET texto = ?, editado_em = ? WHERE id = ?', [$html, agora(), $comentarioId]);
}

function excluir_comentario_acao(int $usuarioId, int $comentarioId, int $planoId): void
{
    comentario_acao_editavel($usuarioId, $comentarioId, $planoId, false);
    executar('UPDATE acoes_comentarios SET texto = ?, excluido_em = ? WHERE id = ?', ['', agora(), $comentarioId]);
}

function dados_dashboard(): array
{
    $usuario = usuario_da_sessao();
    if ($usuario === null || !pode_gerir($usuario)) {
        json_sair(false, 'Você não tem permissão para esta ação.', null, 403);
    }
    $setoresGestor = perfil_amplo($usuario) ? null : setores_do_gestor((int) $usuario['id']);
    $empresas = consultar('SELECT id, nome FROM empresas ORDER BY id');
    if (is_array($setoresGestor)) {
        $empresaVinculo = (int) ($usuario['empresa_id'] ?? 0);
        if ($empresaVinculo > 0) {
            $empresas = array_values(array_filter(
                $empresas,
                static fn (array $linha): bool => (int) $linha['id'] === $empresaVinculo
            ));
        } elseif ($setoresGestor !== []) {
            $marcadores = implode(',', array_fill(0, count($setoresGestor), '?'));
            $empresas = consultar(
                "SELECT DISTINCT e.id, e.nome FROM empresas e JOIN setores s ON s.empresa_id = e.id WHERE s.id IN ({$marcadores}) ORDER BY e.id",
                $setoresGestor
            );
        } else {
            $empresas = [];
        }
    }
    $empresaId = inteiro($_POST['empresa_id'] ?? 0);
    $ids = array_map(static fn (array $linha): int => (int) $linha['id'], $empresas);
    if (!in_array($empresaId, $ids, true)) {
        $empresaId = $ids[0] ?? 0;
    }
    $mes = trim((string) ($_POST['mes'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = '';
    }
    $exprMes = expr_mes_reclamacao();
    $diffAberto = expr_segundos('r.criado_em', 'r.concluida_em');
    $filtroEmpresa = 'st.empresa_id = ?';
    $paramsBase = [$empresaId];
    $filtroMes = '';
    $paramsMes = [];
    if ($mes !== '') {
        $filtroMes = ' AND ' . $exprMes . ' = ?';
        $paramsMes = [$mes];
    }
    $filtroSetor = '';
    $paramsSetor = [];
    if (is_array($setoresGestor)) {
        if ($setoresGestor === []) {
            $filtroSetor = ' AND 1 = 0';
        } else {
            $filtroSetor = ' AND r.setor_id IN (' . implode(',', array_fill(0, count($setoresGestor), '?')) . ')';
            $paramsSetor = $setoresGestor;
        }
    }
    $filtroSetorCadastro = '';
    $paramsSetorCadastro = [];
    if (is_array($setoresGestor)) {
        if ($setoresGestor === []) {
            $filtroSetorCadastro = ' AND 1 = 0';
        } else {
            $filtroSetorCadastro = ' AND st.id IN (' . implode(',', array_fill(0, count($setoresGestor), '?')) . ')';
            $paramsSetorCadastro = $setoresGestor;
        }
    }
    $totais = $empresaId > 0 ? consultar_um(
        "SELECT COUNT(*) AS total,
            SUM(CASE WHEN s.fixo = 'inicial' THEN 1 ELSE 0 END) AS abertas,
            SUM(CASE WHEN s.fixo IS NULL OR s.fixo = '' THEN 1 ELSE 0 END) AS andamento,
            SUM(CASE WHEN s.fixo = 'final' THEN 1 ELSE 0 END) AS concluidas
         FROM reclamacoes r
         JOIN status_reclamacao s ON s.id = r.status_id
         JOIN setores st ON st.id = r.setor_id
         WHERE {$filtroEmpresa}{$filtroMes}{$filtroSetor}",
        array_merge($paramsBase, $paramsMes, $paramsSetor)
    ) ?? [] : [];
    $porSetor = $empresaId > 0 ? consultar(
        "SELECT st.nome, COUNT(r.id) AS quantidade,
            AVG(CASE WHEN s.fixo = 'final' AND r.concluida_em IS NOT NULL THEN {$diffAberto} END) AS media
         FROM setores st
         LEFT JOIN reclamacoes r ON r.setor_id = st.id{$filtroMes}
         LEFT JOIN status_reclamacao s ON s.id = r.status_id
         WHERE {$filtroEmpresa}{$filtroSetorCadastro}
         GROUP BY st.id, st.nome, st.ordem
         ORDER BY st.ordem, st.nome",
        array_merge($paramsMes, $paramsBase, $paramsSetorCadastro)
    ) : [];
    $porStatus = consultar(
        "SELECT s.nome, s.fixo, COUNT(r.id) AS quantidade
         FROM status_reclamacao s
         LEFT JOIN reclamacoes r ON r.status_id = s.id
            AND r.setor_id IN (SELECT id FROM setores WHERE empresa_id = ?){$filtroMes}{$filtroSetor}
         GROUP BY s.id, s.nome, s.fixo
         ORDER BY CASE WHEN s.fixo = 'inicial' THEN 0 WHEN s.fixo = 'final' THEN 2 ELSE 1 END, s.ordem",
        array_merge($paramsBase, $paramsMes, $paramsSetor)
    );
    $recentes = consultar_reclamacoes([
        'busca' => '', 'status_id' => 0, 'setor_id' => 0, 'tipo_id' => 0,
        'situacao_id' => 0, 'origem' => '', 'data_ini' => '', 'data_fim' => '',
        'status_grupo' => '', 'periodo' => '', 'pagina' => 1,
        'empresa_id' => $empresaId, 'mes' => $mes,
    ], 8);
    $diff = expr_segundos('r.criado_em', 'r.concluida_em');
    $colunasTempo = 'SELECT r.id, r.protocolo, r.nome_cliente, r.criado_em, r.concluida_em, r.atualizado_em, st.nome AS setor_nome, s.fixo AS status_fixo ';
    $paramsTempo = array_merge($paramsBase, $paramsMes, $paramsSetor);
    $maisAndamento = $empresaId > 0 ? consultar(
        $colunasTempo . from_reclamacoes() . " WHERE (s.fixo IS NULL OR s.fixo <> 'final') AND {$filtroEmpresa}{$filtroMes}{$filtroSetor} ORDER BY r.criado_em ASC LIMIT 5",
        $paramsTempo
    ) : [];
    $maisDemoradas = $empresaId > 0 ? consultar(
        $colunasTempo . from_reclamacoes() . " WHERE s.fixo = 'final' AND r.concluida_em IS NOT NULL AND {$filtroEmpresa}{$filtroMes}{$filtroSetor} ORDER BY {$diff} DESC LIMIT 5",
        $paramsTempo
    ) : [];
    $mediaConclusao = $empresaId > 0 ? consultar_um(
        "SELECT AVG({$diffAberto}) AS media
         FROM reclamacoes r
         JOIN status_reclamacao s ON s.id = r.status_id
         JOIN setores st ON st.id = r.setor_id
         WHERE s.fixo = 'final' AND r.concluida_em IS NOT NULL AND {$filtroEmpresa}{$filtroMes}{$filtroSetor}",
        $paramsTempo
    ) : [];
    $meses = $empresaId > 0 ? consultar(
        "SELECT DISTINCT {$exprMes} AS mes
         FROM reclamacoes r
         JOIN setores st ON st.id = r.setor_id
         WHERE st.empresa_id = ?{$filtroSetor}
         ORDER BY mes DESC",
        array_merge([$empresaId], $paramsSetor)
    ) : [];
    return [
        'empresa_id' => $empresaId,
        'mes' => $mes,
        'empresas' => array_map(static fn (array $linha): array => [
            'id' => (int) $linha['id'],
            'nome' => $linha['nome'],
        ], $empresas),
        'meses' => array_values(array_filter(array_map(static fn (array $linha): string => (string) $linha['mes'], $meses))),
        'total' => (int) ($totais['total'] ?? 0),
        'abertas' => (int) ($totais['abertas'] ?? 0),
        'andamento' => (int) ($totais['andamento'] ?? 0),
        'concluidas' => (int) ($totais['concluidas'] ?? 0),
        'por_setor' => array_map(static fn (array $linha): array => [
            'nome' => $linha['nome'],
            'quantidade' => (int) $linha['quantidade'],
            'media' => $linha['media'] !== null ? (int) round((float) $linha['media']) : null,
        ], $porSetor),
        'por_status' => array_map(static fn (array $linha): array => [
            'nome' => $linha['nome'],
            'fixo' => $linha['fixo'] !== null && $linha['fixo'] !== '' ? $linha['fixo'] : null,
            'quantidade' => (int) $linha['quantidade'],
        ], $porStatus),
        'recentes' => $recentes['itens'],
        'media_conclusao' => isset($mediaConclusao['media']) && $mediaConclusao['media'] !== null ? (int) round((float) $mediaConclusao['media']) : null,
        'mais_andamento' => array_map('resumo_tempo', $maisAndamento),
        'mais_demoradas' => array_map('resumo_tempo', $maisDemoradas),
    ];
}

function escopo_setores_gestor(): ?array
{
    $usuario = usuario_da_sessao();
    if ($usuario === null || perfil_amplo($usuario)) {
        return null;
    }
    return setores_do_gestor((int) $usuario['id']);
}

function setores_do_gestor(int $usuarioId): array
{
    $linhas = consultar('SELECT setor_id FROM usuario_setores WHERE usuario_id = ?', [$usuarioId]);
    return array_map(static fn (array $linha): int => (int) $linha['setor_id'], $linhas);
}

function exigir_reclamacao_gestor(int $id): void
{
    $permitidos = escopo_setores_gestor();
    if ($permitidos === null) {
        return;
    }
    $setor = (int) valor('SELECT setor_id FROM reclamacoes WHERE id = ?', [$id]);
    if ($setor <= 0 || !in_array($setor, $permitidos, true)) {
        throw new ValidacaoException('Reclamação não encontrada.');
    }
}

function mapa_setores_usuarios(): array
{
    $mapa = [];
    foreach (consultar(
        'SELECT us.usuario_id, s.id, s.nome
         FROM usuario_setores us
         JOIN setores s ON s.id = us.setor_id
         ORDER BY s.ordem, s.nome'
    ) as $linha) {
        $mapa[(int) $linha['usuario_id']][] = [
            'id' => (int) $linha['id'],
            'nome' => $linha['nome'],
        ];
    }
    return $mapa;
}

function validar_setores_empresa(int $empresaId, array $setores): array
{
    $empresa = consultar_um('SELECT id, ativo FROM empresas WHERE id = ?', [$empresaId]);
    if ($empresa === null || (int) $empresa['ativo'] !== 1) {
        throw new ValidacaoException('Selecione a empresa.');
    }
    $ids = [];
    foreach ($setores as $setor) {
        $numero = (int) $setor;
        if ($numero > 0) {
            $ids[$numero] = $numero;
        }
    }
    if ($ids === []) {
        throw new ValidacaoException('Selecione ao menos um setor.');
    }
    $lista = array_values($ids);
    $marcas = implode(',', array_fill(0, count($lista), '?'));
    $validos = consultar(
        'SELECT id FROM setores WHERE empresa_id = ? AND id IN (' . $marcas . ')',
        array_merge([$empresaId], $lista)
    );
    $encontrados = array_map(static fn (array $linha): int => (int) $linha['id'], $validos);
    if (count($encontrados) !== count($lista)) {
        throw new ValidacaoException('Selecione setores da empresa escolhida.');
    }
    return $lista;
}

function definir_acesso_usuario(int $id, string $perfil, int $empresaId, array $setores): void
{
    $semSetor = array_filter($setores, static fn ($setor): bool => (int) $setor > 0) === [];
    if ($perfil === 'diretoria' || ($perfil === 'administrador' && $semSetor)) {
        executar('UPDATE usuarios SET perfil = ?, empresa_id = NULL, atualizado_em = ? WHERE id = ?', [$perfil, agora(), $id]);
        executar('DELETE FROM usuario_setores WHERE usuario_id = ?', [$id]);
        return;
    }
    $setoresOk = validar_setores_empresa($empresaId, $setores);
    executar('UPDATE usuarios SET perfil = ?, empresa_id = ?, atualizado_em = ? WHERE id = ?', [$perfil, $empresaId, agora(), $id]);
    executar('DELETE FROM usuario_setores WHERE usuario_id = ?', [$id]);
    foreach ($setoresOk as $setorId) {
        executar('INSERT INTO usuario_setores (usuario_id, setor_id) VALUES (?, ?)', [$id, $setorId]);
    }
}

function listar_usuarios(string $busca): array
{
    $params = [];
    $sql = 'SELECT u.id, u.nome, u.email, u.celular, u.foto, u.ativo, u.bloqueado, u.perfil, u.login, u.ultimo_acesso, u.criado_em, u.empresa_id, e.nome AS empresa_nome
        FROM usuarios u
        LEFT JOIN empresas e ON e.id = u.empresa_id';
    if ($busca !== '') {
        $like = texto_busca(mb_substr($busca, 0, 80));
        $sql .= ' WHERE ' . condicao_busca(['u.nome', 'u.email', 'u.login']);
        $params = [$like, $like, $like];
    }
    $sql .= ' ORDER BY u.nome';
    $setores = mapa_setores_usuarios();
    return array_map(static function (array $linha) use ($setores): array {
        $id = (int) $linha['id'];
        return [
            'id' => $id,
            'nome' => $linha['nome'],
            'email' => $linha['email'],
            'celular' => $linha['celular'],
            'foto' => foto_segura($linha['foto']),
            'ativo' => (int) $linha['ativo'],
            'bloqueado' => (int) ($linha['bloqueado'] ?? 0),
            'perfil' => $linha['perfil'],
            'login' => $linha['login'],
            'ultimo_acesso' => $linha['ultimo_acesso'],
            'iniciais' => iniciais((string) $linha['nome']),
            'empresa_id' => $linha['empresa_id'] !== null && $linha['empresa_id'] !== '' ? (int) $linha['empresa_id'] : null,
            'empresa_nome' => is_string($linha['empresa_nome'] ?? null) ? $linha['empresa_nome'] : '',
            'setores' => $setores[$id] ?? [],
        ];
    }, consultar($sql, $params));
}

function exigir_administrador(array $ator): void
{
    if (($ator['perfil'] ?? '') !== 'administrador') {
        throw new ValidacaoException('Somente o administrador pode incluir usuários.');
    }
}

function listar_usuarios_sso_ativos(): array
{
    $lista = sso_listar_usuarios();
    if ($lista === null) {
        throw new ValidacaoException('Não foi possível consultar os usuários da Provita.');
    }
    $locais = consultar('SELECT sso_id, login FROM usuarios');
    $ids = [];
    $logins = [];
    foreach ($locais as $local) {
        $ids[(string) $local['sso_id']] = true;
        $logins[mb_strtolower((string) $local['login'])] = true;
    }
    $saida = [];
    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }
        $item = chaves_minusculas($item);
        if (ler_ativo($item, false) === false) {
            continue;
        }
        $mapeado = mapear_usuario_sso($item, '');
        if ($mapeado['sso_id'] === '' || $mapeado['login'] === '') {
            continue;
        }
        $saida[] = [
            'sso_id' => $mapeado['sso_id'],
            'login' => $mapeado['login'],
            'nome' => $mapeado['nome'],
            'email' => $mapeado['email'],
            'celular' => $mapeado['celular'],
            'ja_cadastrado' => isset($ids[$mapeado['sso_id']]) || isset($logins[mb_strtolower($mapeado['login'])]),
        ];
    }
    usort($saida, static fn (array $a, array $b): int => strcasecmp((string) $a['nome'], (string) $b['nome']));
    return $saida;
}

function adicionar_usuarios_sso(array $ids, array $ator, string $perfil, int $empresaId, array $setores): int
{
    exigir_administrador($ator);
    if (!in_array($perfil, ['gestao', 'diretoria', 'administrador'], true)) {
        throw new ValidacaoException('Informe se o usuário é gestor, diretoria ou administrador.');
    }
    if ($perfil === 'gestao' || ($perfil === 'administrador' && $setores !== [])) {
        validar_setores_empresa($empresaId, $setores);
    }
    $desejados = [];
    foreach ($ids as $id) {
        $texto = trim((string) $id);
        if ($texto !== '') {
            $desejados[$texto] = true;
        }
    }
    if ($desejados === []) {
        throw new ValidacaoException('Selecione ao menos um usuário.');
    }
    $lista = sso_listar_usuarios();
    if ($lista === null) {
        throw new ValidacaoException('Não foi possível consultar os usuários da Provita.');
    }
    $adicionados = 0;
    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }
        $item = chaves_minusculas($item);
        if (ler_ativo($item, false) === false) {
            continue;
        }
        $mapeado = mapear_usuario_sso($item, '');
        if ($mapeado['sso_id'] === '' || !isset($desejados[$mapeado['sso_id']])) {
            continue;
        }
        if (usuario_local_sso($mapeado) !== null) {
            continue;
        }
        $criado = salvar_usuario_sso($mapeado, true);
        definir_acesso_usuario((int) $criado['id'], $perfil, $empresaId, $setores);
        $adicionados++;
    }
    if ($adicionados === 0) {
        throw new ValidacaoException('Nenhum usuário novo foi incluído.');
    }
    return $adicionados;
}

function sincronizar_usuarios_sso(int $atorId): array
{
    $locais = consultar('SELECT id, sso_id, login, nome, email, celular, ativo FROM usuarios');
    $lista = sso_listar_usuarios();
    $remotos = [];
    if (is_array($lista)) {
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item = chaves_minusculas($item);
            $mapeado = mapear_usuario_sso($item, '');
            if ($mapeado['sso_id'] === '' && $mapeado['login'] === '') {
                continue;
            }
            $ativo = ler_ativo($item, false);
            $remotos[] = [
                'sso_id' => $mapeado['sso_id'],
                'login' => $mapeado['login'],
                'nome' => $mapeado['nome'],
                'email' => $mapeado['email'],
                'celular' => $mapeado['celular'],
                'ativo' => $ativo ?? true,
            ];
        }
    }
    if ($remotos === []) {
        return [
            'mensagem' => 'A Provita não informou os usuários. Nenhum dado foi alterado.',
            'confirmados' => 0,
            'eu_inativo' => false,
        ];
    }
    $inativados = 0;
    $reativados = 0;
    $atualizados = 0;
    $confirmados = 0;
    $euInativo = false;
    foreach ($locais as $local) {
        $login = mb_strtolower((string) $local['login']);
        $ssoId = (string) $local['sso_id'];
        $remoto = null;
        foreach ($remotos as $item) {
            $loginRemoto = mb_strtolower($item['login']);
            if ($item['sso_id'] === $ssoId || ($loginRemoto !== '' && $loginRemoto === $login)) {
                $remoto = $item;
                break;
            }
        }
        $confirmados++;
        $id = (int) $local['id'];
        if ($remoto === null) {
            if ((int) $local['ativo'] === 1) {
                executar('UPDATE usuarios SET ativo = 0, atualizado_em = ? WHERE id = ?', [agora(), $id]);
                $inativados++;
            }
            if ($id === $atorId) {
                $euInativo = true;
            }
            continue;
        }
        $ativo = $remoto['ativo'] === true;
        $estavaAtivo = (int) $local['ativo'] === 1;
        if ($ativo && !$estavaAtivo) {
            $reativados++;
        } elseif (!$ativo && $estavaAtivo) {
            $inativados++;
            if ($id === $atorId) {
                $euInativo = true;
            }
        } elseif (!$ativo && $id === $atorId) {
            $euInativo = true;
        }
        $loginNovo = $remoto['login'] !== '' ? $remoto['login'] : (string) $local['login'];
        $nomeNovo = $remoto['nome'] !== '' ? $remoto['nome'] : (string) $local['nome'];
        $emailNovo = $remoto['email'];
        $celularNovo = $remoto['celular'];
        $mudou = $loginNovo !== (string) $local['login']
            || $nomeNovo !== (string) $local['nome']
            || (string) ($emailNovo ?? '') !== (string) ($local['email'] ?? '')
            || (string) ($celularNovo ?? '') !== (string) ($local['celular'] ?? '')
            || $ativo !== $estavaAtivo;
        if ($mudou) {
            executar(
                'UPDATE usuarios SET login = ?, nome = ?, email = ?, celular = ?, ativo = ?, atualizado_em = ? WHERE id = ?',
                [$loginNovo, $nomeNovo, $emailNovo, $celularNovo, $ativo ? 1 : 0, agora(), $id]
            );
            if ($loginNovo !== (string) $local['login'] || $nomeNovo !== (string) $local['nome'] || (string) ($emailNovo ?? '') !== (string) ($local['email'] ?? '') || (string) ($celularNovo ?? '') !== (string) ($local['celular'] ?? '')) {
                $atualizados++;
            }
        }
    }
    $partes = [];
    if ($atualizados === 1) {
        $partes[] = '1 usuário atualizado';
    } elseif ($atualizados > 1) {
        $partes[] = $atualizados . ' usuários atualizados';
    }
    if ($inativados === 1) {
        $partes[] = '1 usuário inativado';
    } elseif ($inativados > 1) {
        $partes[] = $inativados . ' usuários inativados';
    }
    if ($reativados === 1) {
        $partes[] = '1 usuário reativado';
    } elseif ($reativados > 1) {
        $partes[] = $reativados . ' usuários reativados';
    }
    $mensagem = $partes === []
        ? 'Sincronização concluída. Nenhuma informação mudou.'
        : 'Sincronização concluída. ' . implode(' e ', $partes) . '.';
    return ['mensagem' => $mensagem, 'confirmados' => $confirmados, 'eu_inativo' => $euInativo];
}

function atualizar_perfil(int $id, string $perfil, array $ator, int $empresaId = 0, array $setores = []): void
{
    if (($ator['perfil'] ?? '') !== 'administrador') {
        throw new ValidacaoException('Somente o administrador pode alterar o perfil.');
    }
    if (!in_array($perfil, ['gestao', 'diretoria', 'administrador'], true)) {
        throw new ValidacaoException('Perfil inválido.');
    }
    transacao(function (PDO $pdo) use ($id, $perfil, $empresaId, $setores): void {
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $usuario = $stmt->fetch();
        if ($usuario === false) {
            throw new ValidacaoException('Usuário não encontrado.');
        }
        if ($usuario['perfil'] === 'administrador' && $perfil !== 'administrador' && administradores_livres($id) === 0) {
            throw new ValidacaoException('Deve permanecer ao menos um administrador ativo.');
        }
        definir_acesso_usuario($id, $perfil, $empresaId, $setores);
    });
}

function listar_entidade(string $entidade): array
{
    return linhas_cadastro(tabela_cadastro($entidade));
}

function nome_duplicado(string $tabela, string $nome, int $ignorar, int $empresaId = 0): bool
{
    $permitidas = ['empresas', 'setores', 'tipos_reclamacao', 'situacoes', 'status_reclamacao'];
    if (!in_array($tabela, $permitidas, true)) {
        throw new RuntimeException('Tabela inválida.');
    }
    $sql = "SELECT id, nome FROM {$tabela}";
    $params = [];
    if ($tabela === 'setores' && $empresaId > 0) {
        $sql .= ' WHERE empresa_id = ?';
        $params[] = $empresaId;
    }
    $linhas = consultar($sql, $params);
    $comparar = mb_strtolower($nome);
    foreach ($linhas as $linha) {
        if ((int) $linha['id'] === $ignorar) {
            continue;
        }
        if (mb_strtolower((string) $linha['nome']) === $comparar) {
            return true;
        }
    }
    return false;
}

function definir_bloqueio_usuario(int $id, int $bloqueado, int $atorId): void
{
    if ($id === $atorId) {
        throw new ValidacaoException('Você não pode inativar o próprio usuário.');
    }
    $usuario = consultar_um('SELECT * FROM usuarios WHERE id = ?', [$id]);
    if ($usuario === null) {
        throw new ValidacaoException('Usuário não encontrado.');
    }
    if ($bloqueado === 1 && $usuario['perfil'] === 'administrador' && administradores_livres($id) === 0) {
        throw new ValidacaoException('Deve permanecer ao menos um administrador ativo.');
    }
    executar('UPDATE usuarios SET bloqueado = ?, atualizado_em = ? WHERE id = ?', [$bloqueado === 1 ? 1 : 0, agora(), $id]);
}

function excluir_usuario(int $id, int $atorId): void
{
    if ($id === $atorId) {
        throw new ValidacaoException('Você não pode excluir o próprio usuário.');
    }
    $usuario = consultar_um('SELECT * FROM usuarios WHERE id = ?', [$id]);
    if ($usuario === null) {
        throw new ValidacaoException('Usuário não encontrado.');
    }
    if ($usuario['perfil'] === 'administrador' && (int) valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'administrador' AND id <> ?", [$id]) === 0) {
        throw new ValidacaoException('Deve permanecer ao menos um administrador.');
    }
    if (uso_usuario($id) > 0) {
        throw new ValidacaoException('Este usuário já registrou ou tratou reclamações. Você pode inativá-lo.');
    }
    executar('DELETE FROM usuario_setores WHERE usuario_id = ?', [$id]);
    executar('DELETE FROM usuarios WHERE id = ?', [$id]);
}

function uso_usuario(int $id): int
{
    return (int) valor(
        'SELECT
            (SELECT COUNT(*) FROM reclamacoes WHERE usuario_id = ?) +
            (SELECT COUNT(*) FROM comentarios WHERE usuario_id = ?) +
            (SELECT COUNT(*) FROM movimentacoes WHERE usuario_id = ?) +
            (SELECT COUNT(*) FROM anexos WHERE usuario_id = ?) +
            (SELECT COUNT(*) FROM acoes_comentarios WHERE usuario_id = ?) +
            (SELECT COUNT(*) FROM acoes_movimentacoes WHERE usuario_id = ?)',
        [$id, $id, $id, $id, $id, $id]
    );
}

function administradores_livres(int $ignorar): int
{
    return (int) valor(
        "SELECT COUNT(*) FROM usuarios WHERE perfil = 'administrador' AND ativo = 1 AND bloqueado = 0 AND id <> ?",
        [$ignorar]
    );
}

function salvar_entidade(string $entidade, int $id, string $nome, int $ativo, string $cor = '', int $empresaId = 0): void
{
    $tabela = tabela_cadastro($entidade);
    $nome = limpar_texto($nome, 2, 80, 'Informe um nome com pelo menos 2 caracteres.');
    if ($entidade === 'setores') {
        if ($empresaId <= 0 || consultar_um('SELECT id FROM empresas WHERE id = ?', [$empresaId]) === null) {
            throw new ValidacaoException('Selecione a empresa.');
        }
    }
    if (nome_duplicado($tabela, $nome, $id, $entidade === 'setores' ? $empresaId : 0)) {
        throw new ValidacaoException($entidade === 'setores'
            ? 'Esta empresa já possui um setor com este nome.'
            : 'Já existe um registro com este nome.');
    }
    if ($entidade === 'situacoes' && $ativo === 0) {
        $ativas = (int) valor("SELECT COUNT(*) FROM situacoes WHERE ativo = 1 AND id <> ?", [$id]);
        if ($ativas === 0) {
            throw new ValidacaoException('Mantenha ao menos uma situação ativa.');
        }
    }
    if ($id > 0) {
        if (consultar_um("SELECT id FROM {$tabela} WHERE id = ?", [$id]) === null) {
            throw new ValidacaoException('Registro não encontrado.');
        }
        if ($entidade === 'situacoes') {
            executar("UPDATE {$tabela} SET nome = ?, ativo = ?, cor = ? WHERE id = ?", [$nome, $ativo, cor_status($cor, null), $id]);
            return;
        }
        if ($entidade === 'setores') {
            executar('UPDATE setores SET nome = ?, ativo = ?, empresa_id = ? WHERE id = ?', [$nome, $ativo, $empresaId, $id]);
            return;
        }
        executar("UPDATE {$tabela} SET nome = ?, ativo = ? WHERE id = ?", [$nome, $ativo, $id]);
        return;
    }
    if ($entidade === 'situacoes') {
        executar("INSERT INTO {$tabela} (nome, ativo, cor, criado_em) VALUES (?, ?, ?, ?)", [$nome, $ativo === 0 ? 0 : 1, cor_status($cor, null), agora()]);
        return;
    }
    if ($entidade === 'setores') {
        $ordem = (int) valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM setores');
        executar('INSERT INTO setores (nome, ativo, ordem, empresa_id, criado_em) VALUES (?, ?, ?, ?, ?)', [$nome, $ativo === 0 ? 0 : 1, $ordem, $empresaId, agora()]);
        return;
    }
    executar("INSERT INTO {$tabela} (nome, ativo, criado_em) VALUES (?, ?, ?)", [$nome, $ativo === 0 ? 0 : 1, agora()]);
}

function excluir_entidade(string $entidade, int $id): void
{
    $tabela = tabela_cadastro($entidade);
    if ($id <= 0 || consultar_um("SELECT id FROM {$tabela} WHERE id = ?", [$id]) === null) {
        throw new ValidacaoException('Registro não encontrado.');
    }
    if ($tabela === 'empresas') {
        if ((int) valor('SELECT COUNT(*) FROM setores WHERE empresa_id = ?', [$id]) > 0) {
            throw new ValidacaoException('Esta empresa já possui setores. Você pode inativá-la.');
        }
        executar('DELETE FROM empresas WHERE id = ?', [$id]);
        return;
    }
    $colunas = [
        'setores' => 'setor_id',
        'tipos_reclamacao' => 'tipo_id',
        'situacoes' => 'situacao_id',
    ];
    $coluna = $colunas[$tabela];
    if ((int) valor("SELECT COUNT(*) FROM reclamacoes WHERE {$coluna} = ?", [$id]) > 0) {
        throw new ValidacaoException('Este registro já está em uso. Você pode inativá-lo.');
    }
    if ($tabela === 'situacoes' && (int) valor('SELECT COUNT(*) FROM situacoes') <= 1) {
        throw new ValidacaoException('Mantenha ao menos uma situação.');
    }
    executar("DELETE FROM {$tabela} WHERE id = ?", [$id]);
}

function cores_status(): array
{
    return [
        '#fde2e4', '#f8c4c4', '#f6d0c4', '#fce1c8', '#f6d6a8', '#fff3bf',
        '#e7f5c8', '#d8f3dc', '#b7e4c7', '#c8f0e4', '#d0f4f7', '#c5e8f7',
        '#dbe7ff', '#d5d9f6', '#e5dff6', '#e7d5f5', '#f8d7e8', '#f3d1dc',
        '#efe6d8', '#e2e8e4',
    ];
}

function cor_status(string $cor, ?string $fixo = null): string
{
    $cor = strtolower(trim($cor));
    if (in_array($cor, cores_status(), true)) {
        return $cor;
    }
    if ($fixo === 'inicial') {
        return '#fff3bf';
    }
    if ($fixo === 'final') {
        return '#d8f3dc';
    }
    return '#dbe7ff';
}

function salvar_status(int $id, string $nome, int $ativo = 1, string $cor = ''): void
{
    $nome = limpar_texto($nome, 2, 60, 'Informe o nome do status.');
    if (nome_duplicado('status_reclamacao', $nome, $id)) {
        throw new ValidacaoException('Já existe um status com este nome.');
    }
    $ativo = $ativo === 1 ? 1 : 0;
    if ($id > 0) {
        $atual = consultar_um('SELECT id, fixo FROM status_reclamacao WHERE id = ?', [$id]);
        if ($atual === null) {
            throw new ValidacaoException('Status não encontrado.');
        }
        $fixo = $atual['fixo'] !== null && $atual['fixo'] !== '' ? (string) $atual['fixo'] : null;
        if ($fixo !== null) {
            $ativo = 1;
        }
        executar('UPDATE status_reclamacao SET nome = ?, ativo = ?, cor = ? WHERE id = ?', [$nome, $ativo, cor_status($cor, $fixo), $id]);
        return;
    }
    $cor = cor_status($cor, null);
    transacao(function (PDO $pdo) use ($nome, $ativo, $cor): void {
        $pdo->prepare('INSERT INTO status_reclamacao (nome, ordem, fixo, ativo, cor, criado_em) VALUES (?, 99999, NULL, ?, ?, ?)')
            ->execute([$nome, $ativo, $cor, agora()]);
        renumerar_meios($pdo);
    });
}

function uso_status(int $id): array
{
    $status = consultar_um('SELECT id, fixo FROM status_reclamacao WHERE id = ?', [$id]);
    if ($status === null) {
        throw new ValidacaoException('Status não encontrado.');
    }
    $destinos = consultar(
        "SELECT id, nome FROM status_reclamacao WHERE ativo = 1 AND id <> ? AND (fixo IS NULL OR fixo = 'inicial') ORDER BY CASE WHEN fixo = 'inicial' THEN 0 ELSE 1 END, ordem, nome",
        [$id]
    );
    return [
        'quantidade' => (int) valor('SELECT COUNT(*) FROM reclamacoes WHERE status_id = ?', [$id]),
        'destinos' => array_map(static fn (array $linha): array => [
            'id' => (int) $linha['id'],
            'nome' => $linha['nome'],
        ], $destinos),
    ];
}

function inativar_status(int $id, int $ativo, int $destino): void
{
    $status = consultar_um('SELECT id, fixo FROM status_reclamacao WHERE id = ?', [$id]);
    if ($status === null) {
        throw new ValidacaoException('Status não encontrado.');
    }
    if (!empty($status['fixo'])) {
        throw new ValidacaoException('O status inicial e o final não podem ser inativados.');
    }
    if ($ativo === 1) {
        executar('UPDATE status_reclamacao SET ativo = 1 WHERE id = ?', [$id]);
        return;
    }
    $quantidade = (int) valor('SELECT COUNT(*) FROM reclamacoes WHERE status_id = ?', [$id]);
    if ($quantidade > 0) {
        if ($destino <= 0 || $destino === $id) {
            throw new ValidacaoException('Escolha o status que receberá as reclamações.');
        }
        $alvo = consultar_um("SELECT id FROM status_reclamacao WHERE id = ? AND ativo = 1 AND (fixo IS NULL OR fixo = 'inicial')", [$destino]);
        if ($alvo === null) {
            throw new ValidacaoException('O status de destino não está disponível.');
        }
    }
    transacao(function (PDO $pdo) use ($id, $destino, $quantidade): void {
        if ($quantidade > 0) {
            $pdo->prepare('UPDATE reclamacoes SET status_id = ?, atualizado_em = ? WHERE status_id = ?')
                ->execute([$destino, agora(), $id]);
        }
        $pdo->prepare('UPDATE status_reclamacao SET ativo = 0 WHERE id = ?')->execute([$id]);
    });
}

function excluir_status(int $id): void
{
    $status = consultar_um('SELECT * FROM status_reclamacao WHERE id = ?', [$id]);
    if ($status === null) {
        throw new ValidacaoException('Status não encontrado.');
    }
    if (!empty($status['fixo'])) {
        throw new ValidacaoException('O status inicial e o final não podem ser removidos.');
    }
    if ((int) valor('SELECT COUNT(*) FROM reclamacoes WHERE status_id = ?', [$id]) > 0) {
        throw new ValidacaoException('Este status já está em uso e não pode ser removido.');
    }
    executar('DELETE FROM status_reclamacao WHERE id = ?', [$id]);
}

function mover_status(int $id, string $direcao): void
{
    if (!in_array($direcao, ['cima', 'baixo'], true)) {
        throw new ValidacaoException('Direção inválida.');
    }
    $meios = array_values(array_filter(listar_status(), static fn (array $status): bool => $status['fixo'] === null));
    $indice = null;
    foreach ($meios as $i => $status) {
        if ($status['id'] === $id) {
            $indice = $i;
        }
    }
    if ($indice === null) {
        throw new ValidacaoException('Só é possível reordenar os status do meio.');
    }
    $vizinho = $direcao === 'cima' ? $indice - 1 : $indice + 1;
    if (!isset($meios[$vizinho])) {
        return;
    }
    $ids = array_map(static fn (array $status): int => $status['id'], $meios);
    $tmp = $ids[$indice];
    $ids[$indice] = $ids[$vizinho];
    $ids[$vizinho] = $tmp;
    transacao(function (PDO $pdo) use ($ids): void {
        $upd = $pdo->prepare('UPDATE status_reclamacao SET ordem = ? WHERE id = ?');
        $ordem = 10;
        foreach ($ids as $itemId) {
            $upd->execute([$ordem, $itemId]);
            $ordem += 10;
        }
    });
}

function ordenar_status(array $ids): void
{
    $ids = array_values(array_map(static fn ($id): int => (int) $id, $ids));
    if ($ids === [] || count($ids) !== count(array_unique($ids))) {
        throw new ValidacaoException('A ordem informada é inválida.');
    }
    $meios = array_values(array_filter(listar_status(), static fn (array $status): bool => $status['fixo'] === null));
    $porId = [];
    foreach ($meios as $status) {
        $porId[$status['id']] = true;
    }
    foreach ($ids as $id) {
        if (!isset($porId[$id])) {
            throw new ValidacaoException('Só é possível reordenar os status do meio.');
        }
    }
    $escolhidos = array_fill_keys($ids, true);
    $fila = $ids;
    $novo = [];
    foreach ($meios as $status) {
        $novo[] = isset($escolhidos[$status['id']]) ? (int) array_shift($fila) : $status['id'];
    }
    transacao(function (PDO $pdo) use ($novo): void {
        $upd = $pdo->prepare('UPDATE status_reclamacao SET ordem = ? WHERE id = ? AND fixo IS NULL');
        $ordem = 10;
        foreach ($novo as $itemId) {
            $upd->execute([$ordem, $itemId]);
            $ordem += 10;
        }
    });
}

function renumerar_meios(PDO $pdo): void
{
    $ids = $pdo->query('SELECT id FROM status_reclamacao WHERE fixo IS NULL ORDER BY ordem, id')->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare('UPDATE status_reclamacao SET ordem = ? WHERE id = ?');
    $ordem = 10;
    foreach ($ids as $id) {
        $upd->execute([$ordem, (int) $id]);
        $ordem += 10;
    }
}

function csv_seguro(?string $valor): string
{
    $valor = preg_replace('/\s+/u', ' ', trim($valor ?? '')) ?? '';
    if (preg_match('/^[=+\-@\t]/', $valor) === 1) {
        $valor = "'" . $valor;
    }
    return $valor;
}

function exportar_csv(array $filtros): never
{
    [$where, $params] = clausulas_reclamacao($filtros);
    $sqlWhere = implode(' AND ', $where);
    $total = (int) valor('SELECT COUNT(*) ' . from_reclamacoes() . ' WHERE ' . $sqlWhere, $params);
    if ($total > 10000) {
        throw new ValidacaoException('Há mais de 10 mil reclamações neste filtro. Reduza o período.');
    }
    $linhas = consultar(
        'SELECT r.protocolo, r.criado_em, r.data_reclamacao, r.origem, r.nome_cliente, r.unidade,
            r.responsavel_denuncia, r.email_cliente, r.telefone_cliente, r.responsavel_atendimento,
            st.nome AS setor_nome, t.nome AS tipo_nome, s.nome AS status_nome,
            si.nome AS situacao_nome, r.descricao, r.conclusao, u.nome AS autor_nome '
        . from_reclamacoes()
        . ' LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE ' . $sqlWhere
        . ' ORDER BY r.criado_em DESC, r.id DESC LIMIT 10000',
        $params
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reclamacoes-' . date('Ymd') . '.csv"');
    $saida = fopen('php://output', 'w');
    if ($saida === false) {
        throw new RuntimeException('Não foi possível gerar o arquivo.');
    }
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['Data da reclamação', 'Origem', 'Empresa cliente', 'Unidade', 'Quem fez a reclamação', 'E-mail', 'Telefone', 'Quem está abrindo a reclamação', 'Setor', 'Tipo', 'Status', 'Situação', 'Descrição', 'Conclusão', 'Registrado por'], ';');
    foreach ($linhas as $linha) {
        fputcsv($saida, [
            csv_seguro($linha['data_reclamacao'] ?: $linha['criado_em']),
            csv_seguro($linha['origem'] === 'publica' ? 'Pública' : 'Interna'),
            csv_seguro($linha['nome_cliente']),
            csv_seguro($linha['unidade'] ?? ''),
            csv_seguro($linha['responsavel_denuncia'] ?? ''),
            csv_seguro($linha['email_cliente']),
            csv_seguro($linha['telefone_cliente']),
            csv_seguro($linha['responsavel_atendimento'] ?? ''),
            csv_seguro($linha['setor_nome']),
            csv_seguro($linha['tipo_nome']),
            csv_seguro($linha['status_nome']),
            csv_seguro($linha['situacao_nome']),
            csv_seguro($linha['descricao']),
            csv_seguro(texto_plano((string) $linha['conclusao'])),
            csv_seguro($linha['autor_nome'] ?: 'Formulário público'),
        ], ';');
    }
    fclose($saida);
    exit;
}

function baixar_anexo(): never
{
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site === 'cross-site') {
        http_response_code(403);
        exit;
    }
    $usuario = usuario_da_sessao();
    if ($usuario === null || !pode_gerir($usuario)) {
        redirecionar('index.php');
    }
    $id = inteiro($_GET['id'] ?? 0);
    $origem = (string) ($_GET['origem'] ?? '');
    $tabela = match ($origem) {
        'comentario' => 'comentarios_anexos',
        'acao' => 'acoes_comentarios_anexos',
        default => 'anexos',
    };
    $anexo = consultar_um("SELECT nome_original, arquivo, mime FROM {$tabela} WHERE id = ?", [$id]);
    if ($anexo === null) {
        http_response_code(404);
        echo 'Arquivo não encontrado.';
        exit;
    }
    $reclamacaoId = 0;
    if ($tabela === 'comentarios_anexos') {
        $pai = consultar_um('SELECT c.reclamacao_id, c.excluido_em FROM comentarios_anexos a JOIN comentarios c ON c.id = a.comentario_id WHERE a.id = ?', [$id]);
        if ($pai === null || trim((string) ($pai['excluido_em'] ?? '')) !== '') {
            http_response_code(404);
            echo 'Arquivo não encontrado.';
            exit;
        }
        $reclamacaoId = (int) $pai['reclamacao_id'];
    } elseif ($tabela === 'acoes_comentarios_anexos') {
        $pai = consultar_um(
            'SELECT p.reclamacao_id, c.excluido_em
             FROM acoes_comentarios_anexos a
             JOIN acoes_comentarios c ON c.id = a.comentario_id
             JOIN planos_acao p ON p.id = c.plano_id
             WHERE a.id = ?',
            [$id]
        );
        if ($pai === null || trim((string) ($pai['excluido_em'] ?? '')) !== '') {
            http_response_code(404);
            echo 'Arquivo não encontrado.';
            exit;
        }
        $reclamacaoId = (int) $pai['reclamacao_id'];
    } else {
        $reclamacaoId = (int) valor('SELECT reclamacao_id FROM anexos WHERE id = ?', [$id]);
    }
    $permitidos = escopo_setores_gestor();
    if (is_array($permitidos)) {
        $setor = (int) valor('SELECT setor_id FROM reclamacoes WHERE id = ?', [$reclamacaoId]);
        if ($setor <= 0 || !in_array($setor, $permitidos, true)) {
            http_response_code(404);
            echo 'Arquivo não encontrado.';
            exit;
        }
    }
    $caminho = caminho_upload((string) $anexo['arquivo']);
    if ($caminho === null) {
        http_response_code(404);
        echo 'Arquivo não encontrado.';
        exit;
    }
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $anexo['nome_original']) ?: 'anexo';
    $mime = (string) $anexo['mime'];
    $ver = ($_GET['ver'] ?? '') === '1';
    $podeVer = $ver && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($caminho));
    header('Content-Disposition: ' . ($podeVer ? 'inline' : 'attachment') . '; filename="' . $ascii . '"');
    readfile($caminho);
    exit;
}
