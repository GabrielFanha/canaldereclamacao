<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    $scriptAtual = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $esteArquivo = realpath(__FILE__);
    if ($scriptAtual !== false && $esteArquivo !== false && $scriptAtual === $esteArquivo) {
        http_response_code(404);
        exit;
    }
}

class ValidacaoException extends RuntimeException
{
}

function preparar_aplicacao(): void
{
    carregar_env(__DIR__ . '/.env');
    date_default_timezone_set('America/Sao_Paulo');
    mb_internal_encoding('UTF-8');
    ini_set('default_charset', 'UTF-8');
    if (PHP_SAPI !== 'cli') {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        iniciar_sessao();
        headers_seguranca();
    }
    db();
}

function carregar_env(string $arquivo): void
{
    static $carregado = false;
    if ($carregado) {
        return;
    }
    $carregado = true;
    if (!is_file($arquivo)) {
        return;
    }
    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES);
    if ($linhas === false) {
        return;
    }
    foreach ($linhas as $indice => $linha) {
        if ($indice === 0) {
            $linha = preg_replace('/^\xEF\xBB\xBF/', '', $linha) ?? $linha;
        }
        $linha = trim($linha);
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }
        $posicao = strpos($linha, '=');
        if ($posicao === false) {
            continue;
        }
        $chave = trim(substr($linha, 0, $posicao));
        $valor = trim(substr($linha, $posicao + 1));
        if (!preg_match('/^[A-Z0-9_]+$/', $chave)) {
            continue;
        }
        if (strlen($valor) >= 2) {
            $aspas = $valor[0];
            if (($aspas === '"' || $aspas === "'") && str_ends_with($valor, $aspas)) {
                $valor = substr($valor, 1, -1);
            }
        }
        $_ENV[$chave] = $valor;
        putenv($chave . '=' . $valor);
    }
}

function env(string $chave, ?string $padrao = null): string
{
    $valor = $_ENV[$chave] ?? getenv($chave);
    if ($valor === false || $valor === null || $valor === '') {
        return $padrao ?? '';
    }
    return (string) $valor;
}

function preparar_diretorios(): void
{
    foreach (['storage', 'uploads'] as $diretorio) {
        $caminho = __DIR__ . '/' . $diretorio;
        if (!is_dir($caminho)) {
            mkdir($caminho, 0755, true);
        }
    }
}

function driver_banco(): string
{
    $driver = strtolower(env('DB_DRIVER', 'sqlite'));
    return $driver === 'mysql' ? 'mysql' : 'sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    carregar_env(__DIR__ . '/.env');
    preparar_diretorios();
    if (driver_banco() === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_NAME', 'canal_reclamacoes')
        );
        $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } else {
        $relativo = env('DB_PATH', 'storage/banco.sqlite');
        $absoluto = preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\\/)/', $relativo) === 1;
        $caminho = $absoluto ? $relativo : __DIR__ . '/' . ltrim($relativo, '/\\');
        $pasta = dirname($caminho);
        if (!is_dir($pasta)) {
            mkdir($pasta, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $caminho, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->sqliteCreateFunction('sem_acento', 'sem_acento', 1);
    }
    migrar($pdo);
    return $pdo;
}

function sql_pk(): string
{
    return driver_banco() === 'mysql'
        ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
        : 'INTEGER PRIMARY KEY AUTOINCREMENT';
}

function sql_fk(): string
{
    return driver_banco() === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
}

function migrar(PDO $pdo): void
{
    $pk = sql_pk();
    $fk = sql_fk();
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id {$pk},
        sso_id TEXT NOT NULL UNIQUE,
        login TEXT NOT NULL,
        nome TEXT NOT NULL,
        email TEXT,
        celular TEXT,
        foto TEXT,
        ativo INTEGER NOT NULL DEFAULT 1,
        perfil TEXT NOT NULL DEFAULT 'gestao' CHECK (perfil IN ('comum', 'gestao', 'diretoria', 'administrador')),
        ultimo_acesso TEXT,
        criado_em TEXT NOT NULL,
        atualizado_em TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS empresas (
        id {$pk},
        nome TEXT NOT NULL,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS setores (
        id {$pk},
        nome TEXT NOT NULL,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tipos_reclamacao (
        id {$pk},
        nome TEXT NOT NULL,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS status_reclamacao (
        id {$pk},
        nome TEXT NOT NULL,
        ordem INTEGER NOT NULL,
        fixo TEXT CHECK (fixo IS NULL OR fixo IN ('inicial', 'final')),
        criado_em TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS situacoes (
        id {$pk},
        nome TEXT NOT NULL,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reclamacoes (
        id {$pk},
        protocolo TEXT NOT NULL UNIQUE,
        origem TEXT NOT NULL CHECK (origem IN ('publica', 'interna')),
        nome_cliente TEXT NOT NULL,
        email_cliente TEXT,
        telefone_cliente TEXT,
        setor_id {$fk} NOT NULL,
        tipo_id {$fk} NOT NULL,
        descricao TEXT NOT NULL,
        status_id {$fk} NOT NULL,
        situacao_id {$fk},
        conclusao TEXT,
        concluida_em TEXT,
        usuario_id {$fk},
        criado_em TEXT NOT NULL,
        atualizado_em TEXT NOT NULL,
        FOREIGN KEY (setor_id) REFERENCES setores(id),
        FOREIGN KEY (tipo_id) REFERENCES tipos_reclamacao(id),
        FOREIGN KEY (status_id) REFERENCES status_reclamacao(id),
        FOREIGN KEY (situacao_id) REFERENCES situacoes(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comentarios (
        id {$pk},
        reclamacao_id {$fk} NOT NULL,
        usuario_id {$fk} NOT NULL,
        texto TEXT NOT NULL,
        criado_em TEXT NOT NULL,
        editado_em TEXT,
        excluido_em TEXT,
        FOREIGN KEY (reclamacao_id) REFERENCES reclamacoes(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS movimentacoes (
        id {$pk},
        reclamacao_id {$fk} NOT NULL,
        usuario_id {$fk},
        descricao TEXT NOT NULL,
        criado_em TEXT NOT NULL,
        FOREIGN KEY (reclamacao_id) REFERENCES reclamacoes(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comentarios_anexos (
        id {$pk},
        comentario_id {$fk} NOT NULL,
        nome_original TEXT NOT NULL,
        arquivo TEXT NOT NULL,
        mime TEXT NOT NULL,
        tamanho INTEGER NOT NULL,
        criado_em TEXT NOT NULL,
        FOREIGN KEY (comentario_id) REFERENCES comentarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS planos_acao (
        id {$pk},
        reclamacao_id {$fk} NOT NULL,
        ordem INTEGER NOT NULL,
        acao TEXT NOT NULL,
        responsavel TEXT NOT NULL,
        tempo_aplicacao TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'aguardando',
        criado_em TEXT NOT NULL,
        FOREIGN KEY (reclamacao_id) REFERENCES reclamacoes(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acoes_comentarios (
        id {$pk},
        plano_id {$fk} NOT NULL,
        usuario_id {$fk} NOT NULL,
        texto TEXT NOT NULL,
        criado_em TEXT NOT NULL,
        editado_em TEXT,
        excluido_em TEXT,
        FOREIGN KEY (plano_id) REFERENCES planos_acao(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acoes_comentarios_anexos (
        id {$pk},
        comentario_id {$fk} NOT NULL,
        nome_original TEXT NOT NULL,
        arquivo TEXT NOT NULL,
        mime TEXT NOT NULL,
        tamanho INTEGER NOT NULL,
        criado_em TEXT NOT NULL,
        FOREIGN KEY (comentario_id) REFERENCES acoes_comentarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acoes_movimentacoes (
        id {$pk},
        plano_id {$fk} NOT NULL,
        usuario_id {$fk},
        descricao TEXT NOT NULL,
        criado_em TEXT NOT NULL,
        FOREIGN KEY (plano_id) REFERENCES planos_acao(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS anexos (
        id {$pk},
        reclamacao_id {$fk} NOT NULL,
        usuario_id {$fk},
        contexto TEXT NOT NULL CHECK (contexto IN ('abertura', 'conclusao')),
        nome_original TEXT NOT NULL,
        arquivo TEXT NOT NULL,
        mime TEXT NOT NULL,
        tamanho INTEGER NOT NULL,
        criado_em TEXT NOT NULL,
        FOREIGN KEY (reclamacao_id) REFERENCES reclamacoes(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_setores (
        usuario_id {$fk} NOT NULL,
        setor_id {$fk} NOT NULL,
        PRIMARY KEY (usuario_id, setor_id)
    )");
    $pdo->exec('CREATE TABLE IF NOT EXISTS esquema_versao (versao INTEGER NOT NULL)');
    if (driver_banco() === 'sqlite') {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reclamacoes_status ON reclamacoes (status_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reclamacoes_setor ON reclamacoes (setor_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reclamacoes_criado ON reclamacoes (criado_em)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comentarios_rec ON comentarios (reclamacao_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mov_rec ON movimentacoes (reclamacao_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_anexos_rec ON anexos (reclamacao_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_planos_rec ON planos_acao (reclamacao_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_acoes_comentarios_plano ON acoes_comentarios (plano_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_acoes_mov_plano ON acoes_movimentacoes (plano_id)');
    }
    garantir_colunas($pdo);
    $versao = $pdo->query('SELECT versao FROM esquema_versao LIMIT 1')->fetchColumn();
    if ($versao === false) {
        $pdo->exec('INSERT INTO esquema_versao (versao) VALUES (1)');
    }
    $agora = date('Y-m-d H:i:s');
    $status = (int) $pdo->query('SELECT COUNT(*) FROM status_reclamacao')->fetchColumn();
    if ($status === 0) {
        $inserir = $pdo->prepare('INSERT INTO status_reclamacao (nome, ordem, fixo, ativo, cor, criado_em) VALUES (?, ?, ?, 1, ?, ?)');
        $inserir->execute(['Em fila para tratativa', 1, 'inicial', '#fff3bf', $agora]);
        $inserir->execute(['Em tratativa', 2, null, '#dbe7ff', $agora]);
        $inserir->execute(['Concluída', 3, 'final', '#d8f3dc', $agora]);
    }
    $situacoes = (int) $pdo->query('SELECT COUNT(*) FROM situacoes')->fetchColumn();
    if ($situacoes === 0) {
        $inserir = $pdo->prepare('INSERT INTO situacoes (nome, ativo, criado_em) VALUES (?, 1, ?)');
        foreach (['Procedente', 'Improcedente'] as $nome) {
            $inserir->execute([$nome, $agora]);
        }
    }
    $pdo->exec("UPDATE usuarios SET perfil = 'gestao' WHERE perfil = 'comum'");
    garantir_perfil_diretoria($pdo);
    garantir_protocolos_aleatorios($pdo);
    pintar_situacoes_sem_cor($pdo);
    garantir_status_automaticos($pdo);
    organizar_uploads($pdo);
    adequar_catalogo($pdo);
}

function garantir_status_automaticos(PDO $pdo): void
{
    $momento = date('Y-m-d H:i:s');
    $gravar = $pdo->prepare('UPDATE status_reclamacao SET nome = ?, ordem = ?, fixo = ?, ativo = 1, cor = ? WHERE id = ?');
    $buscar = static function (PDO $pdo, string $sql): ?int {
        $id = $pdo->query($sql)->fetchColumn();
        return $id === false ? null : (int) $id;
    };
    $inicialId = $buscar($pdo, "SELECT id FROM status_reclamacao WHERE fixo = 'inicial' ORDER BY id LIMIT 1");
    if ($inicialId === null) {
        $pdo->prepare('INSERT INTO status_reclamacao (nome, ordem, fixo, ativo, cor, criado_em) VALUES (?, 1, ?, 1, ?, ?)')
            ->execute(['Em fila para tratativa', 'inicial', '#fff3bf', $momento]);
        $inicialId = (int) $pdo->lastInsertId();
    }
    $meioId = $buscar($pdo, "SELECT id FROM status_reclamacao WHERE fixo IS NULL OR fixo = '' ORDER BY ordem, id LIMIT 1");
    if ($meioId === null) {
        $pdo->prepare('INSERT INTO status_reclamacao (nome, ordem, fixo, ativo, cor, criado_em) VALUES (?, 2, NULL, 1, ?, ?)')
            ->execute(['Em tratativa', '#dbe7ff', $momento]);
        $meioId = (int) $pdo->lastInsertId();
    }
    $finalId = $buscar($pdo, "SELECT id FROM status_reclamacao WHERE fixo = 'final' ORDER BY id LIMIT 1");
    if ($finalId === null) {
        $pdo->prepare('INSERT INTO status_reclamacao (nome, ordem, fixo, ativo, cor, criado_em) VALUES (?, 3, ?, 1, ?, ?)')
            ->execute(['Concluída', 'final', '#d8f3dc', $momento]);
        $finalId = (int) $pdo->lastInsertId();
    }
    $gravar->execute(['Em fila para tratativa', 1, 'inicial', '#fff3bf', $inicialId]);
    $gravar->execute(['Em tratativa', 2, null, '#dbe7ff', $meioId]);
    $gravar->execute(['Concluída', 3, 'final', '#d8f3dc', $finalId]);
    $sobras = $pdo->prepare('SELECT id, fixo FROM status_reclamacao WHERE id NOT IN (?, ?, ?)');
    $sobras->execute([$inicialId, $meioId, $finalId]);
    $mover = $pdo->prepare('UPDATE reclamacoes SET status_id = ? WHERE status_id = ?');
    $apagar = $pdo->prepare('DELETE FROM status_reclamacao WHERE id = ?');
    foreach ($sobras->fetchAll(PDO::FETCH_ASSOC) as $sobra) {
        $destino = ($sobra['fixo'] ?? '') === 'final' ? $finalId : $inicialId;
        $mover->execute([$destino, (int) $sobra['id']]);
        $apagar->execute([(int) $sobra['id']]);
    }
}

function pintar_situacoes_sem_cor(PDO $pdo): void
{
    if (!coluna_existe($pdo, 'situacoes', 'cor')) {
        return;
    }
    $linhas = $pdo->query('SELECT id, nome, cor FROM situacoes')->fetchAll(PDO::FETCH_ASSOC);
    $atualizar = $pdo->prepare('UPDATE situacoes SET cor = ? WHERE id = ?');
    foreach ($linhas as $linha) {
        if (trim((string) ($linha['cor'] ?? '')) !== '') {
            continue;
        }
        $nome = mb_strtolower((string) $linha['nome']);
        $cor = '#dbe7ff';
        if (str_contains($nome, 'improced')) {
            $cor = '#fde2e4';
        } elseif (str_contains($nome, 'proced')) {
            $cor = '#d8f3dc';
        } elseif (str_contains($nome, 'autoriz')) {
            $cor = '#d0f4f7';
        }
        $atualizar->execute([$cor, (int) $linha['id']]);
    }
}

function protocolo_quatro_digitos(array $usados): string
{
    for ($tentativa = 0; $tentativa < 50; $tentativa++) {
        $numero = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (!isset($usados[$numero])) {
            return $numero;
        }
    }
    throw new RuntimeException('Não foi possível gerar um protocolo.');
}

function garantir_protocolos_aleatorios(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS esquema_marca (nome TEXT PRIMARY KEY)');
    $marca = $pdo->query("SELECT 1 FROM esquema_marca WHERE nome = 'protocolo_aleatorio'")->fetchColumn();
    $linhas = $pdo->query('SELECT id, protocolo FROM reclamacoes')->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    $usados = [];
    foreach ($linhas as $linha) {
        $protocolo = (string) $linha['protocolo'];
        $quatro = preg_match('/^\d{4}$/', $protocolo) === 1;
        if ($marca === false || !$quatro) {
            $ids[] = (int) $linha['id'];
            continue;
        }
        $usados[$protocolo] = true;
    }
    if ($ids === []) {
        if ($marca === false) {
            $pdo->prepare('INSERT INTO esquema_marca (nome) VALUES (?)')->execute(['protocolo_aleatorio']);
        }
        return;
    }
    $atualizar = $pdo->prepare('UPDATE reclamacoes SET protocolo = ? WHERE id = ?');
    foreach ($ids as $id) {
        $atualizar->execute(['tmp-' . $id, $id]);
    }
    foreach ($ids as $id) {
        $numero = protocolo_quatro_digitos($usados);
        $usados[$numero] = true;
        $atualizar->execute([$numero, $id]);
    }
    if ($marca === false) {
        $pdo->prepare('INSERT INTO esquema_marca (nome) VALUES (?)')->execute(['protocolo_aleatorio']);
    }
}

function garantir_colunas(PDO $pdo): void
{
    $ajustes = [
        'status_reclamacao' => [
            'ativo' => 'INTEGER NOT NULL DEFAULT 1',
            'cor' => 'TEXT',
        ],
        'situacoes' => [
            'cor' => 'TEXT',
        ],
        'usuarios' => [
            'bloqueado' => 'INTEGER NOT NULL DEFAULT 0',
            'empresa_id' => 'INTEGER',
        ],
        'setores' => [
            'ordem' => 'INTEGER NOT NULL DEFAULT 0',
            'empresa_id' => 'INTEGER',
        ],
        'reclamacoes' => [
            'unidade' => 'TEXT',
            'responsavel_denuncia' => 'TEXT',
            'data_reclamacao' => 'TEXT',
            'responsavel_atendimento' => 'TEXT',
            'acao_imediata' => 'TEXT',
            'causa' => 'TEXT',
            'acao_usuario_id' => 'INTEGER',
            'acao_confirmada_em' => 'TEXT',
            'causa_usuario_id' => 'INTEGER',
            'causa_confirmada_em' => 'TEXT',
            'plano_usuario_id' => 'INTEGER',
            'plano_confirmada_em' => 'TEXT',
        ],
        'planos_acao' => [
            'status' => "TEXT NOT NULL DEFAULT 'aguardando'",
        ],
        'comentarios' => [
            'editado_em' => 'TEXT',
            'excluido_em' => 'TEXT',
        ],
    ];
    foreach ($ajustes as $tabela => $colunas) {
        foreach ($colunas as $coluna => $definicao) {
            if (!coluna_existe($pdo, $tabela, $coluna)) {
                $pdo->exec('ALTER TABLE ' . $tabela . ' ADD COLUMN ' . $coluna . ' ' . $definicao);
            }
        }
    }
}

function coluna_existe(PDO $pdo, string $tabela, string $coluna): bool
{
    $permitidas = [
        'status_reclamacao' => ['ativo', 'cor'],
        'situacoes' => ['cor'],
        'usuarios' => ['bloqueado', 'empresa_id'],
        'setores' => ['ordem', 'empresa_id'],
        'reclamacoes' => ['unidade', 'responsavel_denuncia', 'data_reclamacao', 'responsavel_atendimento', 'acao_imediata', 'causa', 'acao_usuario_id', 'acao_confirmada_em', 'causa_usuario_id', 'causa_confirmada_em', 'plano_usuario_id', 'plano_confirmada_em'],
        'planos_acao' => ['status'],
        'comentarios' => ['editado_em', 'excluido_em'],
    ];
    if (!isset($permitidas[$tabela]) || !in_array($coluna, $permitidas[$tabela], true)) {
        return true;
    }
    if (driver_banco() === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$tabela, $coluna]);
        return (int) $stmt->fetchColumn() > 0;
    }
    $consulta = $pdo->query('PRAGMA table_info(' . $tabela . ')');
    foreach ($consulta as $info) {
        if (($info['name'] ?? '') === $coluna) {
            return true;
        }
    }
    return false;
}

function consultar(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function consultar_um(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $linha = $stmt->fetch();
    return $linha === false ? null : $linha;
}

function executar(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function valor(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function transacao(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $pdo->beginTransaction();
    try {
        $resultado = $fn($pdo);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function requisicao_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https' && !empty($_SERVER['HTTP_CF_RAY']);
}

function uri_app(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (str_ends_with($dir, '/novareclamacao')) {
        $dir = substr($dir, 0, -strlen('/novareclamacao'));
    }
    if ($dir === '' || $dir === '.' || $dir === '/') {
        return '/';
    }
    return $dir . '/';
}

function base_uri(): string
{
    return uri_app();
}

function url_formulario_publico(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $prefixo = $host !== '' ? ((requisicao_https() ? 'https' : 'http') . '://' . $host) : '';
    return $prefixo . uri_app() . 'novareclamacao/';
}

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('CANALRECLAMACAO');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_uri(),
        'secure' => requisicao_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function headers_seguranca(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; frame-src 'self' blob:; style-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_confere(?string $token): bool
{
    $sessao = $_SESSION['csrf'] ?? '';
    return is_string($token) && $token !== '' && is_string($sessao) && $sessao !== '' && hash_equals($sessao, $token);
}

function origem_confiavel(): bool
{
    $origem = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origem === '') {
        return true;
    }
    $esperada = (requisicao_https() ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? '');
    return hash_equals($esperada, $origem);
}

function json_sair(bool $ok, string $mensagem = '', mixed $dados = null, int $codigo = 200): never
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
    }
    $json = json_encode(
        ['ok' => $ok, 'mensagem' => $mensagem, 'dados' => $dados],
        JSON_UNESCAPED_UNICODE
    );
    echo $json === false
        ? '{"ok":false,"mensagem":"Não foi possível concluir a operação.","dados":null}'
        : $json;
    exit;
}

function redirecionar(string $caminho): never
{
    header('Location: ' . $caminho);
    exit;
}

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function agora(): string
{
    return date('Y-m-d H:i:s');
}

function inteiro(mixed $valor): int
{
    if (is_int($valor)) {
        return $valor;
    }
    if (is_string($valor) && preg_match('/^\d+$/', $valor)) {
        return (int) $valor;
    }
    return 0;
}

function data_valida(string $data): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $data);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $data;
}

function limpar_texto(string $valor, int $min, int $max, string $erroMin): string
{
    $valor = preg_replace('/\s+/u', ' ', trim($valor)) ?? '';
    $tamanho = mb_strlen($valor);
    if ($tamanho < $min) {
        throw new ValidacaoException($erroMin);
    }
    if ($tamanho > $max) {
        throw new ValidacaoException('O texto informado é muito longo.');
    }
    return $valor;
}

function texto_like(string $valor): string
{
    $valor = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $valor);
    return '%' . $valor . '%';
}

function sem_acento(?string $texto): string
{
    if ($texto === null || $texto === '') {
        return '';
    }
    $texto = mb_strtolower($texto, 'UTF-8');
    if (class_exists('Normalizer')) {
        $decomposto = Normalizer::normalize($texto, Normalizer::FORM_D);
        if (is_string($decomposto)) {
            $limpo = preg_replace('/\p{Mn}/u', '', $decomposto);
            if (is_string($limpo)) {
                $texto = $limpo;
            }
        }
    }
    return strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ]);
}

function texto_busca(string $valor): string
{
    return texto_like(sem_acento($valor));
}

function expr_sem_acento(string $expressao): string
{
    if (driver_banco() === 'sqlite') {
        return 'sem_acento(' . $expressao . ')';
    }
    $mapa = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n', 'ý' => 'y', 'Ý' => 'y',
    ];
    $sql = $expressao;
    foreach ($mapa as $de => $para) {
        $sql = "REPLACE({$sql}, '{$de}', '{$para}')";
    }
    return 'LOWER(' . $sql . ')';
}

function condicao_busca(array $colunas): string
{
    $partes = array_map(static fn (string $coluna): string => expr_sem_acento($coluna) . " LIKE ? ESCAPE '!'", $colunas);
    return '(' . implode(' OR ', $partes) . ')';
}

function iniciais(string $nome): string
{
    $partes = preg_split('/\s+/u', trim($nome)) ?: [];
    $primeira = mb_substr((string) ($partes[0] ?? '?'), 0, 1);
    $segunda = isset($partes[1]) ? mb_substr((string) $partes[1], 0, 1) : '';
    return mb_strtoupper($primeira . $segunda);
}

function rotulo_perfil(string $perfil): string
{
    return match ($perfil) {
        'administrador' => 'Administrador',
        'diretoria' => 'Diretoria',
        'gestao' => 'Gestor',
        default => 'Gestor',
    };
}

function perfil_amplo(array $usuario): bool
{
    return in_array($usuario['perfil'] ?? '', ['administrador', 'diretoria'], true);
}

function pode_gerir(array $usuario): bool
{
    return in_array($usuario['perfil'] ?? '', ['gestao', 'diretoria', 'administrador'], true);
}

function garantir_perfil_diretoria(PDO $pdo): void
{
    if (driver_banco() === 'mysql') {
        $checks = $pdo->query(
            "SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
             FROM information_schema.TABLE_CONSTRAINTS tc
             JOIN information_schema.CHECK_CONSTRAINTS cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'usuarios' AND tc.CONSTRAINT_TYPE = 'CHECK'"
        )->fetchAll();
        $jaTem = false;
        foreach ($checks as $check) {
            if (str_contains(mb_strtolower((string) ($check['CHECK_CLAUSE'] ?? '')), 'diretoria')) {
                $jaTem = true;
                continue;
            }
            if (!str_contains(mb_strtolower((string) ($check['CHECK_CLAUSE'] ?? '')), 'perfil')) {
                continue;
            }
            $nome = str_replace('`', '', (string) $check['CONSTRAINT_NAME']);
            $pdo->exec('ALTER TABLE usuarios DROP CHECK `' . $nome . '`');
        }
        if (!$jaTem) {
            $pdo->exec("ALTER TABLE usuarios ADD CONSTRAINT usuarios_perfil_chk CHECK (perfil IN ('comum', 'gestao', 'diretoria', 'administrador'))");
        }
        return;
    }
    $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'usuarios'")->fetchColumn();
    if ($sql === '' || str_contains($sql, 'diretoria')) {
        return;
    }
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec("CREATE TABLE usuarios_nova (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sso_id TEXT NOT NULL UNIQUE,
            login TEXT NOT NULL,
            nome TEXT NOT NULL,
            email TEXT,
            celular TEXT,
            foto TEXT,
            ativo INTEGER NOT NULL DEFAULT 1,
            bloqueado INTEGER NOT NULL DEFAULT 0,
            perfil TEXT NOT NULL DEFAULT 'gestao' CHECK (perfil IN ('comum', 'gestao', 'diretoria', 'administrador')),
            empresa_id INTEGER,
            ultimo_acesso TEXT,
            criado_em TEXT NOT NULL,
            atualizado_em TEXT NOT NULL
        )");
        $pdo->exec('INSERT INTO usuarios_nova (
            id, sso_id, login, nome, email, celular, foto, ativo, bloqueado, perfil, empresa_id, ultimo_acesso, criado_em, atualizado_em
        ) SELECT
            id, sso_id, login, nome, email, celular, foto, ativo, COALESCE(bloqueado, 0), perfil, empresa_id, ultimo_acesso, criado_em, atualizado_em
        FROM usuarios');
        $pdo->exec('DROP TABLE usuarios');
        $pdo->exec('ALTER TABLE usuarios_nova RENAME TO usuarios');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function registrar_log(string $arquivo, string $mensagem): void
{
    if (preg_match('/^[a-z0-9_-]+\.log$/', $arquivo) !== 1) {
        return;
    }
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $mensagem = str_replace(["\r", "\n"], ' ', $mensagem);
    $mensagem = mb_substr($mensagem, 0, 2000);
    file_put_contents($dir . '/' . $arquivo, agora() . ' ' . $mensagem . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function versao_asset(string $arquivo): string
{
    if (!in_array($arquivo, ['styles.css', 'scripts.js'], true)) {
        return '1';
    }
    $caminho = __DIR__ . '/' . $arquivo;
    return is_file($caminho) ? (string) filemtime($caminho) : '0';
}

function layout_inicio(string $titulo, string $corpoClasse = ''): void
{
    if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        iniciar_sessao();
    }
    $css = e(versao_asset('styles.css'));
    $raiz = e(uri_app());
    $classe = $corpoClasse !== '' ? ' class="' . e($corpoClasse) . '"' : '';
    echo '<!DOCTYPE html><html lang="pt-BR"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<meta name="csrf" content="' . e(csrf_token()) . '">';
    echo '<meta name="raiz" content="' . $raiz . '">';
    echo '<meta name="color-scheme" content="light">';
    echo '<title>' . e($titulo) . '</title>';
    echo '<link rel="icon" href="' . $raiz . 'assets/wf-logo-branco.png">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400;1,700&display=swap">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">';
    echo '<link rel="stylesheet" href="' . $raiz . 'styles.css?v=' . $css . '">';
    echo '</head><body' . $classe . '>';
    echo '<noscript><div class="aviso aviso-erro aviso-fixo">Ative o JavaScript para usar o Canal de Reclamações.</div></noscript>';
}

function layout_fim(): void
{
    echo '<script src="' . e(uri_app()) . 'scripts.js?v=' . e(versao_asset('scripts.js')) . '"></script>';
    echo '</body></html>';
}

function mensagem_aviso(): string
{
    $codigo = '';
    if (isset($_GET['aviso']) && is_string($_GET['aviso'])) {
        $codigo = $_GET['aviso'];
    } elseif (isset($_COOKIE['aviso']) && is_string($_COOKIE['aviso'])) {
        $codigo = $_COOKIE['aviso'];
    }
    if (isset($_COOKIE['aviso'])) {
        setcookie('aviso', '', [
            'expires' => time() - 3600,
            'path' => base_uri(),
            'secure' => requisicao_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $mapa = [
        'inativo' => 'Seu usuário foi inativado. O acesso foi encerrado.',
        'expirada' => 'Sua sessão expirou. Entre novamente.',
    ];
    return $mapa[$codigo] ?? '';
}

function encerrar_sessao(string $motivo = ''): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => ($params['path'] ?? '') !== '' ? $params['path'] : '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
    if ($motivo !== '') {
        setcookie('aviso', $motivo, [
            'expires' => time() + 120,
            'path' => base_uri(),
            'secure' => requisicao_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function foto_segura(?string $foto): ?string
{
    if ($foto === null) {
        return null;
    }
    $foto = trim($foto);
    if ($foto === '' || str_contains($foto, '..') || preg_match('/[\s\x00-\x1F]/', $foto) === 1 || strlen($foto) > 500) {
        return null;
    }
    if (preg_match('#^https?://#i', $foto) === 1) {
        return $foto;
    }
    if (str_starts_with($foto, '/')) {
        $partes = parse_url(env('SSO_URL', 'https://www.provitasst.com.br/sso.php'));
        $host = (string) ($partes['host'] ?? '');
        if ($host === '') {
            return null;
        }
        return ($partes['scheme'] ?? 'https') . '://' . $host . $foto;
    }
    return null;
}

function usuario_da_sessao(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $uaSessao = $_SESSION['ua'] ?? '';
    if (!is_string($uaSessao) || $uaSessao === '' || !hash_equals($uaSessao, $ua)) {
        $GLOBALS['motivo_sessao'] = 'expirada';
        encerrar_sessao('expirada');
        return null;
    }
    if (time() - (int) ($_SESSION['visto'] ?? 0) > 28800) {
        $GLOBALS['motivo_sessao'] = 'expirada';
        encerrar_sessao('expirada');
        return null;
    }
    $_SESSION['visto'] = time();
    $usuario = consultar_um('SELECT * FROM usuarios WHERE id = ?', [(int) $_SESSION['uid']]);
    if ($usuario === null || (int) $usuario['ativo'] !== 1 || (int) ($usuario['bloqueado'] ?? 0) === 1) {
        $GLOBALS['motivo_sessao'] = $usuario !== null ? 'inativo' : 'expirada';
        encerrar_sessao($usuario !== null ? 'inativo' : 'expirada');
        return null;
    }
    if (time() - (int) ($_SESSION['sso_check'] ?? 0) > 120) {
        $ativo = sso_esta_ativo((string) $usuario['sso_id'], (string) $usuario['login']);
        if ($ativo === false) {
            executar('UPDATE usuarios SET ativo = 0, atualizado_em = ? WHERE id = ?', [agora(), (int) $usuario['id']]);
            $GLOBALS['motivo_sessao'] = 'inativo';
            encerrar_sessao('inativo');
            return null;
        }
        $_SESSION['sso_check'] = time();
    }
    return $usuario;
}

function exigir_login(): array
{
    $usuario = usuario_da_sessao();
    if ($usuario === null) {
        $motivo = ($GLOBALS['motivo_sessao'] ?? '') === 'inativo' ? 'inativo' : 'expirada';
        $mensagem = $motivo === 'inativo'
            ? 'Seu usuário foi inativado. O acesso foi encerrado.'
            : 'Sessão expirada.';
        json_sair(false, $mensagem, ['codigo' => $motivo], 401);
    }
    return $usuario;
}

function exigir_gestao(): array
{
    $usuario = exigir_login();
    if (!pode_gerir($usuario)) {
        json_sair(false, 'Você não tem permissão para esta ação.', null, 403);
    }
    return $usuario;
}

function chaves_minusculas(array $dados): array
{
    $saida = [];
    foreach ($dados as $chave => $valor) {
        $nova = is_string($chave) ? mb_strtolower($chave) : $chave;
        $saida[$nova] = is_array($valor) ? chaves_minusculas($valor) : $valor;
    }
    return $saida;
}

function sso_requisicao(array $campos): array
{
    if (!function_exists('curl_init')) {
        return ['http' => 0, 'json' => null, 'erro' => 'curl'];
    }
    $url = env('SSO_URL', 'https://www.provitasst.com.br/sso.php');
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http' => 0, 'json' => null, 'erro' => 'curl'];
    }
    unset($campos['token']);
    $corpoJson = json_encode($campos, JSON_UNESCAPED_UNICODE);
    if ($corpoJson === false) {
        return ['http' => 0, 'json' => null, 'erro' => 'json'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpoJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . env('SSO_TOKEN'),
            'User-Agent: CanalReclamacoes/1.0',
        ],
    ]);
    $corpo = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || !is_string($corpo)) {
        return ['http' => 0, 'json' => null, 'erro' => 'conexao'];
    }
    $json = json_decode($corpo, true);
    if (!is_array($json)) {
        return ['http' => $http, 'json' => null, 'erro' => 'json'];
    }
    return ['http' => $http, 'json' => chaves_minusculas($json), 'erro' => null];
}

function para_bool(mixed $valor): ?bool
{
    if (is_bool($valor)) {
        return $valor;
    }
    if (is_int($valor) || (is_string($valor) && preg_match('/^-?\d+$/', $valor) === 1)) {
        $numero = (int) $valor;
        if ($numero === 1) {
            return true;
        }
        if ($numero === 0) {
            return false;
        }
        return null;
    }
    if (is_string($valor)) {
        $texto = mb_strtolower(trim($valor));
        if (in_array($texto, ['1', 'true', 'sim', 's', 'yes', 'y', 'ativo', 'active'], true)) {
            return true;
        }
        if (in_array($texto, ['0', 'false', 'nao', 'não', 'n', 'no', 'inativo', 'inactive', 'bloqueado', 'desligado'], true)) {
            return false;
        }
    }
    return null;
}

function ler_ativo(array $fonte, bool $raiz): ?bool
{
    if (array_key_exists('situacao', $fonte)) {
        $valor = para_bool($fonte['situacao']);
        if ($valor !== null) {
            return $valor;
        }
    }
    if (array_key_exists('inativo', $fonte)) {
        $valor = para_bool($fonte['inativo']);
        if ($valor !== null) {
            return !$valor;
        }
    }
    foreach (['ativo', 'active', 'usuario_ativo'] as $chave) {
        if (!array_key_exists($chave, $fonte)) {
            continue;
        }
        $valor = para_bool($fonte[$chave]);
        if ($valor !== null) {
            return $valor;
        }
    }
    if (!$raiz && array_key_exists('status', $fonte)) {
        return para_bool($fonte['status']);
    }
    return null;
}

function extrair_usuario_sso(array $json): ?array
{
    foreach (['usuario', 'user', 'dados', 'data', 'funcionario', 'colaborador'] as $chave) {
        if (!isset($json[$chave]) || !is_array($json[$chave])) {
            continue;
        }
        $candidato = $json[$chave];
        if (isset($candidato['nome']) || isset($candidato['nome_completo']) || isset($candidato['id']) || isset($candidato['usuario']) || isset($candidato['email'])) {
            return $candidato;
        }
        foreach (['usuario', 'user', 'dados', 'data'] as $interno) {
            if (isset($candidato[$interno]) && is_array($candidato[$interno])) {
                return $candidato[$interno];
            }
        }
    }
    if (isset($json['nome']) || isset($json['nome_completo'])) {
        return $json;
    }
    return null;
}

function sso_mensagem(array $json): string
{
    foreach (['message', 'mensagem', 'erro', 'error', 'msg'] as $chave) {
        if (isset($json[$chave]) && is_string($json[$chave])) {
            return trim($json[$chave]);
        }
    }
    return '';
}

function sso_sucesso(array $json, int $http): bool
{
    if ($http === 401 || $http === 403) {
        return false;
    }
    foreach (['success', 'ok', 'autenticado', 'authenticated'] as $chave) {
        if (!array_key_exists($chave, $json)) {
            continue;
        }
        $valor = para_bool($json[$chave]);
        if ($valor !== null) {
            return $valor;
        }
    }
    return $http >= 200 && $http < 300 && extrair_usuario_sso($json) !== null;
}

function primeiro_texto(array $fonte, array $chaves): ?string
{
    foreach ($chaves as $chave) {
        if (!array_key_exists($chave, $fonte) || is_array($fonte[$chave])) {
            continue;
        }
        $valor = trim((string) $fonte[$chave]);
        if ($valor !== '') {
            return $valor;
        }
    }
    return null;
}

function mapear_usuario_sso(array $usuario, string $loginDigitado): array
{
    $nome = primeiro_texto($usuario, ['nome_completo', 'nome', 'name']);
    $email = primeiro_texto($usuario, ['email', 'e_mail', 'mail']);
    $celular = primeiro_texto($usuario, ['telefone', 'celular', 'whatsapp', 'phone', 'mobile', 'fone']);
    $login = primeiro_texto($usuario, ['usuario', 'login', 'username', 'user']) ?? $loginDigitado;
    $id = primeiro_texto($usuario, ['id', 'usuario_id', 'sso_id', 'codigo', 'matricula']);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = null;
    }
    return [
        'sso_id' => mb_substr($id !== null && $id !== '' ? $id : $login, 0, 120),
        'login' => mb_substr($login, 0, 80),
        'nome' => mb_substr($nome !== null && $nome !== '' ? $nome : $login, 0, 200),
        'email' => $email !== null ? mb_substr($email, 0, 150) : null,
        'celular' => $celular !== null ? mb_substr($celular, 0, 30) : null,
        'foto' => foto_segura(primeiro_texto($usuario, ['foto_perfil', 'foto', 'avatar', 'imagem', 'photo', 'caminho_foto'])),
    ];
}

function sso_autenticar(string $login, string $senha): array
{
    $login = trim($login);
    if ($login === '' || $senha === '') {
        return ['ok' => false, 'codigo' => 'credencial', 'mensagem' => 'Informe usuário e senha.', 'usuario' => null];
    }
    if (trim(env('SSO_TOKEN')) === '') {
        return ['ok' => false, 'codigo' => 'token', 'mensagem' => 'Token SSO não configurado.', 'usuario' => null];
    }
    $resposta = sso_requisicao([
        'action' => 'login',
        'usuario' => $login,
        'senha' => $senha,
    ]);
    if ($resposta['erro'] !== null || !is_array($resposta['json'])) {
        return ['ok' => false, 'codigo' => 'conexao', 'mensagem' => 'Falha de conexão.', 'usuario' => null];
    }
    $json = $resposta['json'];
    $mensagem = sso_mensagem($json);
    if (!sso_sucesso($json, (int) $resposta['http'])) {
        if (preg_match('/inativ/iu', $mensagem) === 1) {
            $codigo = 'inativo';
        } elseif (preg_match('/n[aã]o autorizado/iu', $mensagem) === 1) {
            $codigo = 'token';
        } else {
            $codigo = 'credencial';
        }
        return ['ok' => false, 'codigo' => $codigo, 'mensagem' => $mensagem, 'usuario' => null];
    }
    $bruto = extrair_usuario_sso($json);
    if ($bruto === null) {
        return ['ok' => false, 'codigo' => 'credencial', 'mensagem' => $mensagem, 'usuario' => null];
    }
    $ativo = ler_ativo($bruto, false);
    if ($ativo === null) {
        $ativo = ler_ativo($json, true);
    }
    if ($ativo === null) {
        $ativo = true;
    }
    if (preg_match('/inativ/iu', $mensagem) === 1) {
        $ativo = false;
    }
    $mapeado = mapear_usuario_sso($bruto, $login);
    if ($mapeado['sso_id'] === '') {
        return ['ok' => false, 'codigo' => 'credencial', 'mensagem' => $mensagem, 'usuario' => null];
    }
    return ['ok' => $ativo, 'codigo' => $ativo ? 'ok' : 'inativo', 'mensagem' => $mensagem, 'usuario' => $mapeado];
}

function sso_listar_usuarios(): ?array
{
    static $carregou = false;
    static $cache = null;
    if ($carregou) {
        return $cache;
    }
    $carregou = true;
    if (trim(env('SSO_TOKEN')) === '' || trim(env('SSO_URL', 'https://www.provitasst.com.br/sso.php')) === '') {
        return null;
    }
    $resposta = sso_requisicao(['action' => 'listar_usuarios']);
    if ($resposta['erro'] !== null || !is_array($resposta['json'])) {
        return null;
    }
    $json = $resposta['json'];
    if (para_bool($json['success'] ?? null) !== true || !isset($json['data']) || !is_array($json['data']) || !array_is_list($json['data'])) {
        return null;
    }
    $cache = $json['data'];
    return $cache;
}

function sso_esta_ativo(string $ssoId, string $login): ?bool
{
    $lista = sso_listar_usuarios();
    if ($lista === null || $lista === []) {
        return null;
    }
    $login = mb_strtolower(trim($login));
    $ssoId = trim($ssoId);
    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        $usuario = mb_strtolower(trim((string) ($item['usuario'] ?? $item['login'] ?? '')));
        if (($ssoId !== '' && $id === $ssoId) || ($login !== '' && $usuario === $login)) {
            return ler_ativo($item, false) ?? true;
        }
    }
    return false;
}

function usuario_local_sso(array $dados): ?array
{
    $porId = consultar_um('SELECT * FROM usuarios WHERE sso_id = ?', [$dados['sso_id']]);
    if ($porId !== null) {
        return $porId;
    }
    if ($dados['login'] === '') {
        return null;
    }
    return consultar_um('SELECT * FROM usuarios WHERE login = ?', [$dados['login']]);
}

function salvar_usuario_sso(array $dados, bool $ativo): array
{
    $agora = agora();
    $existe = consultar_um('SELECT * FROM usuarios WHERE sso_id = ?', [$dados['sso_id']]);
    if ($existe !== null) {
        $email = $dados['email'] !== null && $dados['email'] !== '' ? $dados['email'] : null;
        $celular = $dados['celular'] !== null && $dados['celular'] !== '' ? $dados['celular'] : null;
        executar(
            'UPDATE usuarios SET login = ?, nome = ?, email = ?, celular = ?, foto = ?, ativo = ?, atualizado_em = ?, ultimo_acesso = ? WHERE id = ?',
            [
                $dados['login'],
                $dados['nome'],
                $email,
                $celular,
                $dados['foto'],
                $ativo ? 1 : 0,
                $agora,
                $ativo ? $agora : $existe['ultimo_acesso'],
                (int) $existe['id'],
            ]
        );
        $atual = consultar_um('SELECT * FROM usuarios WHERE id = ?', [(int) $existe['id']]);
        if ($atual === null) {
            throw new RuntimeException('Usuário não encontrado após atualização.');
        }
        return $atual;
    }
    $perfil = 'gestao';
    if ($ativo && (int) valor("SELECT COUNT(*) FROM usuarios WHERE perfil = 'administrador'") === 0) {
        $perfil = 'administrador';
    }
    executar(
        'INSERT INTO usuarios (sso_id, login, nome, email, celular, foto, ativo, perfil, ultimo_acesso, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $dados['sso_id'],
            $dados['login'],
            $dados['nome'],
            $dados['email'],
            $dados['celular'],
            $dados['foto'],
            $ativo ? 1 : 0,
            $perfil,
            $ativo ? $agora : null,
            $agora,
            $agora,
        ]
    );
    $id = (int) db()->lastInsertId();
    $criado = consultar_um('SELECT * FROM usuarios WHERE id = ?', [$id]);
    if ($criado === null) {
        throw new RuntimeException('Usuário não encontrado após cadastro.');
    }
    return $criado;
}

function abrir_sessao_usuario(array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $usuario['id'];
    $_SESSION['ua'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['visto'] = time();
    $_SESSION['sso_check'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function opcoes_publicas(): array
{
    return [
        'empresas' => consultar('SELECT id, nome FROM empresas WHERE ativo = 1 ORDER BY nome'),
        'setores' => consultar(
            'SELECT s.id, s.nome, s.empresa_id FROM setores s
             INNER JOIN empresas e ON e.id = s.empresa_id
             WHERE s.ativo = 1 AND e.ativo = 1
             ORDER BY s.ordem, s.nome'
        ),
        'tipos' => consultar('SELECT id, nome FROM tipos_reclamacao WHERE ativo = 1 ORDER BY nome'),
    ];
}

function garantir_empresas(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS esquema_marca (nome TEXT PRIMARY KEY)');
    $marca = $pdo->query("SELECT 1 FROM esquema_marca WHERE nome = 'empresas_wfaccioli'")->fetchColumn();
    if ($marca !== false) {
        return;
    }
    $momento = date('Y-m-d H:i:s');
    $busca = $pdo->prepare('SELECT id FROM empresas WHERE nome = ?');
    $busca->execute(['WFaccioli']);
    $empresaId = $busca->fetchColumn();
    if ($empresaId === false) {
        $pdo->prepare('INSERT INTO empresas (nome, ativo, criado_em) VALUES (?, 1, ?)')->execute(['WFaccioli', $momento]);
        $empresaId = (int) $pdo->lastInsertId();
    } else {
        $empresaId = (int) $empresaId;
    }
    $atualizar = $pdo->prepare('UPDATE setores SET empresa_id = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, nome FROM setores') as $linha) {
        if (sem_acento((string) $linha['nome']) === 'engenharia e projetos') {
            continue;
        }
        $atualizar->execute([$empresaId, (int) $linha['id']]);
    }
    $pdo->prepare('INSERT INTO esquema_marca (nome) VALUES (?)')->execute(['empresas_wfaccioli']);
}

function catalogo_empresas_oficial(): array
{
    return [
        'WFaccioli Segurança do Trabalho' => [
            'Esporádico',
            'Assessoria',
            'Mão de Obra',
            'Treinamentos',
            'Comercial',
            'Digitação',
            'Financeiro',
            'Recursos Humanos',
            'Departamento Pessoal',
            'Limpeza',
            'Tecnologia da Informação',
            'Diretoria',
            'Pericia Trabalhista',
        ],
        'Engenharia e Projetos' => ['Geral'],
        'Ultra Ocupacional' => [
            'Atendimento',
            'Periódicos',
            'Agendamento',
            'Operacional',
            'Credenciamento',
            'eSocial',
            'Coordenação',
        ],
        'SafeSST' => ['Geral'],
    ];
}

function instalar_catalogo_empresas(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM empresas')->fetchColumn() > 0) {
        return;
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM setores')->fetchColumn() > 0) {
        return;
    }
    $momento = date('Y-m-d H:i:s');
    $inserirEmpresa = $pdo->prepare('INSERT INTO empresas (nome, ativo, criado_em) VALUES (?, 1, ?)');
    $inserirSetor = $pdo->prepare('INSERT INTO setores (nome, ativo, ordem, empresa_id, criado_em) VALUES (?, 1, ?, ?, ?)');
    foreach (catalogo_empresas_oficial() as $empresa => $setores) {
        $inserirEmpresa->execute([$empresa, $momento]);
        $empresaId = (int) $pdo->lastInsertId();
        foreach ($setores as $indice => $setor) {
            $inserirSetor->execute([$setor, $indice + 1, $empresaId, $momento]);
        }
    }
    $marcar = $pdo->prepare('INSERT INTO esquema_marca (nome) VALUES (?)');
    foreach (['catalogo_oficial', 'empresas_wfaccioli'] as $nome) {
        $busca = $pdo->prepare('SELECT 1 FROM esquema_marca WHERE nome = ?');
        $busca->execute([$nome]);
        if ($busca->fetchColumn() === false) {
            $marcar->execute([$nome]);
        }
    }
}

function html_opcoes(array $itens): string
{
    $html = '';
    foreach ($itens as $item) {
        $html .= '<option value="' . (int) $item['id'] . '">' . e((string) $item['nome']) . '</option>';
    }
    return $html;
}

function marcar_formulario_publico(): void
{
    $_SESSION['form_publico_em'] = time();
}

function validar_envio_publico(): void
{
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        throw new ValidacaoException('Não foi possível enviar a reclamação.');
    }
    $aberto = (int) ($_SESSION['form_publico_em'] ?? 0);
    $decorrido = time() - $aberto;
    if ($aberto === 0 || $decorrido > 21600) {
        throw new ValidacaoException('Atualize a página e envie o formulário novamente.');
    }
    if ($decorrido < 2) {
        throw new ValidacaoException('Aguarde um instante e envie novamente.');
    }
}

function corpo_excedeu_limite(): bool
{
    $tamanho = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    return $tamanho > 1000 && $_POST === [] && $_FILES === [];
}

function arquivos_enviados(string $campo): array
{
    if (!isset($_FILES[$campo]) || !is_array($_FILES[$campo]) || !isset($_FILES[$campo]['name'])) {
        return [];
    }
    $arquivo = $_FILES[$campo];
    if (!is_array($arquivo['name'])) {
        return [$arquivo];
    }
    $lista = [];
    $total = count($arquivo['name']);
    for ($i = 0; $i < $total; $i++) {
        $lista[] = [
            'name' => $arquivo['name'][$i],
            'type' => $arquivo['type'][$i] ?? '',
            'tmp_name' => $arquivo['tmp_name'][$i] ?? '',
            'error' => $arquivo['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $arquivo['size'][$i] ?? 0,
        ];
    }
    return $lista;
}

function validar_lista_anexos(array $arquivos): array
{
    $arquivos = array_values(array_filter(
        $arquivos,
        static fn (array $arquivo): bool => (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ));
    if (count($arquivos) > 5) {
        throw new ValidacaoException('Envie no máximo 5 arquivos.');
    }
    $mapa = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $lista = [];
    foreach ($arquivos as $arquivo) {
        if ((int) ($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new ValidacaoException('Não foi possível receber um dos anexos. O limite é de 20 MB por arquivo.');
        }
        $tamanho = (int) ($arquivo['size'] ?? 0);
        if ($tamanho <= 0 || $tamanho > 20 * 1024 * 1024) {
            throw new ValidacaoException('Cada anexo pode ter no máximo 20 MB.');
        }
        $extensao = strtolower(pathinfo((string) ($arquivo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($mapa[$extensao])) {
            throw new ValidacaoException('Tipo de arquivo não permitido. Use PDF, imagem, Word ou Excel.');
        }
        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new ValidacaoException('Anexo inválido.');
        }
        $mime = $finfo->file($tmp);
        if (!is_string($mime) || !in_array($mime, $mapa[$extensao], true)) {
            throw new ValidacaoException('O conteúdo de um anexo não é permitido.');
        }
        $original = basename(str_replace('\\', '/', (string) ($arquivo['name'] ?? '')));
        $original = preg_replace('/[\x00-\x1F\x7F]/', '', $original) ?? '';
        $original = mb_substr($original, 0, 180);
        if ($original === '') {
            $original = 'anexo.' . $extensao;
        }
        $lista[] = [
            'tmp' => $tmp,
            'ext' => $extensao,
            'mime' => $mime,
            'original' => $original,
            'tamanho' => $tamanho,
        ];
    }
    return $lista;
}

function protocolo_anexo(string $protocolo): string
{
    if (preg_match('/^\d{4}$/', $protocolo) !== 1) {
        throw new ValidacaoException('Protocolo inválido para o anexo.');
    }
    return $protocolo;
}

function pasta_upload(?string $protocolo = null): string
{
    $dir = __DIR__ . '/uploads';
    if ($protocolo !== null) {
        $dir .= DIRECTORY_SEPARATOR . protocolo_anexo($protocolo);
    }
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new ValidacaoException('Não foi possível guardar o anexo.');
    }
    return $dir;
}

function mover_anexo(array $arquivo, string $protocolo): array
{
    $protocolo = protocolo_anexo($protocolo);
    $dir = pasta_upload($protocolo);
    $nome = bin2hex(random_bytes(16)) . '.' . $arquivo['ext'];
    $destino = $dir . DIRECTORY_SEPARATOR . $nome;
    if (!move_uploaded_file($arquivo['tmp'], $destino)) {
        throw new ValidacaoException('Não foi possível salvar um anexo.');
    }
    return ['arquivo' => $protocolo . '/' . $nome, 'caminho' => $destino];
}

function apagar_arquivos(array $caminhos): void
{
    foreach ($caminhos as $caminho) {
        if (!is_string($caminho) || !is_file($caminho)) {
            continue;
        }
        $pasta = dirname($caminho);
        unlink($caminho);
        remover_pasta_upload_vazia($pasta);
    }
}

function apagar_arquivo_salvo(?string $relativo): void
{
    if (!is_string($relativo) || $relativo === '') {
        return;
    }
    $caminho = caminho_upload($relativo);
    if ($caminho === null) {
        return;
    }
    $pasta = dirname($caminho);
    unlink($caminho);
    remover_pasta_upload_vazia($pasta);
}

function caminho_upload(string $arquivo): ?string
{
    $arquivo = str_replace('\\', '/', $arquivo);
    if (preg_match('#^(\d{4})/([a-f0-9]{32}\.[a-z0-9]{2,5})$#', $arquivo, $partes) === 1) {
        $relativo = $partes[1] . DIRECTORY_SEPARATOR . $partes[2];
    } elseif (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $arquivo) === 1) {
        $relativo = $arquivo;
    } else {
        return null;
    }
    $base = realpath(__DIR__ . '/uploads');
    if ($base === false) {
        return null;
    }
    $completo = $base . DIRECTORY_SEPARATOR . $relativo;
    if (!is_file($completo)) {
        return null;
    }
    $real = realpath($completo);
    if ($real === false) {
        return null;
    }
    $baseNorm = rtrim(strtolower(str_replace('\\', '/', $base)), '/') . '/';
    $realNorm = strtolower(str_replace('\\', '/', $real));
    if (!str_starts_with($realNorm, $baseNorm)) {
        return null;
    }
    return $real;
}

function organizar_uploads(PDO $pdo): void
{
    $base = pasta_upload();
    garantir_htaccess_upload($base);
    $vivos = [];
    $consultas = [
        'anexos' => 'SELECT a.id, a.arquivo, r.protocolo FROM anexos a JOIN reclamacoes r ON r.id = a.reclamacao_id',
        'comentarios_anexos' => 'SELECT a.id, a.arquivo, r.protocolo FROM comentarios_anexos a JOIN comentarios c ON c.id = a.comentario_id JOIN reclamacoes r ON r.id = c.reclamacao_id',
        'acoes_comentarios_anexos' => 'SELECT a.id, a.arquivo, r.protocolo FROM acoes_comentarios_anexos a JOIN acoes_comentarios c ON c.id = a.comentario_id JOIN planos_acao p ON p.id = c.plano_id JOIN reclamacoes r ON r.id = p.reclamacao_id',
    ];
    foreach ($consultas as $tabela => $sql) {
        $atualizar = $pdo->prepare('UPDATE ' . $tabela . ' SET arquivo = ? WHERE id = ?');
        foreach ($pdo->query($sql) as $linha) {
            $relativo = realocar_upload((string) $linha['arquivo'], (string) $linha['protocolo']);
            if ($relativo === null) {
                continue;
            }
            if ($relativo !== str_replace('\\', '/', (string) $linha['arquivo'])) {
                $atualizar->execute([$relativo, (int) $linha['id']]);
            }
            $vivos[strtolower($relativo)] = true;
        }
    }
    limpar_uploads_perdidos($base, $vivos);
}

function realocar_upload(string $arquivo, string $protocolo): ?string
{
    if (preg_match('/^\d{4}$/', $protocolo) !== 1) {
        return null;
    }
    $arquivo = str_replace('\\', '/', $arquivo);
    $nome = '';
    if (preg_match('#^\d{4}/([a-f0-9]{32}\.[a-z0-9]{2,5})$#', $arquivo, $partes) === 1) {
        $nome = $partes[1];
    } elseif (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $arquivo) === 1) {
        $nome = $arquivo;
    } else {
        return null;
    }
    $relativo = $protocolo . '/' . $nome;
    $destino = __DIR__ . '/uploads/' . $protocolo . DIRECTORY_SEPARATOR . $nome;
    if (is_file($destino)) {
        if ($arquivo !== $relativo) {
            $antigo = caminho_upload($arquivo);
            if ($antigo !== null && $antigo !== realpath($destino)) {
                unlink($antigo);
                remover_pasta_upload_vazia(dirname($antigo));
            }
        }
        return $relativo;
    }
    $origem = caminho_upload($arquivo);
    if ($origem === null) {
        return null;
    }
    pasta_upload($protocolo);
    if (!rename($origem, $destino)) {
        return null;
    }
    remover_pasta_upload_vazia(dirname($origem));
    return $relativo;
}

function limpar_uploads_perdidos(string $base, array $vivos): void
{
    $raiz = realpath($base);
    if ($raiz === false) {
        return;
    }
    $limite = time() - 600;
    $itens = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($itens as $item) {
        $caminho = $item->getPathname();
        $nome = $item->getFilename();
        if ($nome === '.htaccess' || $nome === '.gitkeep') {
            continue;
        }
        if ($item->isDir()) {
            remover_pasta_upload_vazia($caminho);
            continue;
        }
        if (!$item->isFile()) {
            continue;
        }
        $raizNorm = strtolower(str_replace('\\', '/', $raiz));
        $caminhoNorm = strtolower(str_replace('\\', '/', $caminho));
        if (!str_starts_with($caminhoNorm, $raizNorm . '/')) {
            continue;
        }
        $relativo = substr($caminhoNorm, strlen($raizNorm) + 1);
        if (isset($vivos[$relativo]) || $item->getMTime() >= $limite) {
            continue;
        }
        unlink($caminho);
    }
}

function remover_pasta_upload_vazia(string $pasta): void
{
    $base = realpath(__DIR__ . '/uploads');
    $real = realpath($pasta);
    if ($base === false || $real === false || $real === $base || !str_starts_with(strtolower(str_replace('\\', '/', $real)), strtolower(str_replace('\\', '/', $base)) . '/')) {
        return;
    }
    $conteudo = scandir($real);
    if ($conteudo === false) {
        return;
    }
    foreach ($conteudo as $nome) {
        if ($nome !== '.' && $nome !== '..') {
            return;
        }
    }
    rmdir($real);
}

function garantir_htaccess_upload(string $dir): void
{
    $destino = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    $conteudo = <<<'HTA'
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .phar
RemoveType .php .phtml .phar

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

<FilesMatch "(?i)\.(php|phtml|php3|php4|php5|phar|cgi|pl|asp|aspx|shtml|js|html?)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Deny from all
    </IfModule>
</FilesMatch>
HTA;
    $atual = is_file($destino) ? str_replace("\r\n", "\n", (string) file_get_contents($destino)) : '';
    if ($atual === str_replace("\r\n", "\n", $conteudo)) {
        return;
    }
    file_put_contents($destino, $conteudo, LOCK_EX);
}

function adequar_catalogo(PDO $pdo): void
{
    remover_produto($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS esquema_marca (nome TEXT PRIMARY KEY)');
    instalar_catalogo_empresas($pdo);
    $marca = $pdo->query("SELECT 1 FROM esquema_marca WHERE nome = 'catalogo_oficial'")->fetchColumn();
    if ($marca === false) {
        sincronizar_setores($pdo);
        sincronizar_tipos($pdo);
        $pdo->prepare('INSERT INTO esquema_marca (nome) VALUES (?)')->execute(['catalogo_oficial']);
    }
    garantir_tipos_padrao($pdo);
    $pdo->prepare('UPDATE setores SET nome = ? WHERE nome = ?')->execute(['Departamento Pessoal', 'Departamento Pessoa']);
    garantir_empresas($pdo);
}

function remover_produto(PDO $pdo): void
{
    if (!coluna_banco($pdo, 'reclamacoes', 'produto_id')) {
        if (tabela_banco($pdo, 'produtos_servicos')) {
            $pdo->exec('DROP TABLE produtos_servicos');
        }
        return;
    }
    if (driver_banco() === 'mysql') {
        $chaves = $pdo->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reclamacoes' AND COLUMN_NAME = 'produto_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($chaves as $chave) {
            $pdo->exec('ALTER TABLE reclamacoes DROP FOREIGN KEY `' . str_replace('`', '', (string) $chave) . '`');
        }
        $pdo->exec('ALTER TABLE reclamacoes DROP COLUMN produto_id');
        $pdo->exec('DROP TABLE IF EXISTS produtos_servicos');
        return;
    }
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec("CREATE TABLE reclamacoes_nova (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            protocolo TEXT NOT NULL UNIQUE,
            origem TEXT NOT NULL CHECK (origem IN ('publica', 'interna')),
            nome_cliente TEXT NOT NULL,
            email_cliente TEXT,
            telefone_cliente TEXT,
            setor_id INTEGER NOT NULL,
            tipo_id INTEGER NOT NULL,
            descricao TEXT NOT NULL,
            status_id INTEGER NOT NULL,
            situacao_id INTEGER,
            conclusao TEXT,
            concluida_em TEXT,
            usuario_id INTEGER,
            criado_em TEXT NOT NULL,
            atualizado_em TEXT NOT NULL,
            unidade TEXT,
            responsavel_denuncia TEXT,
            data_reclamacao TEXT,
            responsavel_atendimento TEXT,
            FOREIGN KEY (setor_id) REFERENCES setores(id),
            FOREIGN KEY (tipo_id) REFERENCES tipos_reclamacao(id),
            FOREIGN KEY (status_id) REFERENCES status_reclamacao(id),
            FOREIGN KEY (situacao_id) REFERENCES situacoes(id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        )");
        $pdo->exec('INSERT INTO reclamacoes_nova (
            id, protocolo, origem, nome_cliente, email_cliente, telefone_cliente, setor_id, tipo_id, descricao,
            status_id, situacao_id, conclusao, concluida_em, usuario_id, criado_em, atualizado_em,
            unidade, responsavel_denuncia, data_reclamacao, responsavel_atendimento
        ) SELECT
            id, protocolo, origem, nome_cliente, email_cliente, telefone_cliente, setor_id, tipo_id, descricao,
            status_id, situacao_id, conclusao, concluida_em, usuario_id, criado_em, atualizado_em,
            unidade, responsavel_denuncia, data_reclamacao, responsavel_atendimento
        FROM reclamacoes');
        $pdo->exec('DROP TABLE reclamacoes');
        $pdo->exec('ALTER TABLE reclamacoes_nova RENAME TO reclamacoes');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reclamacoes_status ON reclamacoes (status_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reclamacoes_setor ON reclamacoes (setor_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reclamacoes_criado ON reclamacoes (criado_em)');
        $pdo->exec('DROP TABLE IF EXISTS produtos_servicos');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function sincronizar_setores(PDO $pdo): void
{
    $oficiais = [
        'Esporádico',
        'Assessoria',
        'Mão de Obra',
        'Treinamentos',
        'Engenharia e Projetos',
        'Comercial',
        'Digitação',
        'Financeiro',
        'Recursos Humanos',
        'Departamento Pessoal',
        'Limpeza',
        'Tecnologia da Informação',
        'Diretoria',
        'Pericia Trabalhista',
    ];
    $aliases = [
        'esporadico' => 'Esporádico',
        'assessoria' => 'Assessoria',
        'mao de obra' => 'Mão de Obra',
        'treinamento' => 'Treinamentos',
        'treinamentos' => 'Treinamentos',
        'engenharia e projetos' => 'Engenharia e Projetos',
        'comercial' => 'Comercial',
        'digitacao' => 'Digitação',
        'financeiro' => 'Financeiro',
        'recursos humanos' => 'Recursos Humanos',
        'departamento pessoa' => 'Departamento Pessoal',
        'departamento pessoal' => 'Departamento Pessoal',
        'limpeza' => 'Limpeza',
        'ti' => 'Tecnologia da Informação',
        'tecnologia da informacao' => 'Tecnologia da Informação',
        'diretoria' => 'Diretoria',
        'pericia trabalhista' => 'Pericia Trabalhista',
    ];
    $momento = date('Y-m-d H:i:s');
    $porNome = [];
    $duplicados = [];
    foreach ($pdo->query('SELECT id, nome FROM setores') as $linha) {
        $chave = sem_acento((string) $linha['nome']);
        if (!isset($aliases[$chave])) {
            continue;
        }
        $oficial = $aliases[$chave];
        $id = (int) $linha['id'];
        if (!isset($porNome[$oficial])) {
            $porNome[$oficial] = $id;
            continue;
        }
        $duplicados[$id] = $oficial;
    }
    $atualizar = $pdo->prepare('UPDATE setores SET nome = ?, ativo = 1, ordem = ? WHERE id = ?');
    $inserir = $pdo->prepare('INSERT INTO setores (nome, ativo, ordem, criado_em) VALUES (?, 1, ?, ?)');
    $ids = [];
    foreach ($oficiais as $indice => $nome) {
        $ordem = $indice + 1;
        if (isset($porNome[$nome])) {
            $atualizar->execute([$nome, $ordem, $porNome[$nome]]);
            $ids[] = $porNome[$nome];
            continue;
        }
        $inserir->execute([$nome, $ordem, $momento]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    $idOficial = array_combine($oficiais, $ids);
    $moverDuplicado = $pdo->prepare('UPDATE reclamacoes SET setor_id = ? WHERE setor_id = ?');
    foreach ($duplicados as $idAntigo => $nome) {
        $moverDuplicado->execute([(int) $idOficial[$nome], $idAntigo]);
    }
    $destino = $ids[0];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE reclamacoes SET setor_id = ? WHERE setor_id NOT IN ({$marcas})")->execute(array_merge([$destino], $ids));
    $pdo->prepare("DELETE FROM setores WHERE id NOT IN ({$marcas})")->execute($ids);
}

function sincronizar_tipos(PDO $pdo): void
{
    $remover = ['prazo descumprido', 'produto avariado', 'documentacao'];
    $momento = date('Y-m-d H:i:s');
    $tipos = $pdo->query('SELECT id, nome FROM tipos_reclamacao')->fetchAll(PDO::FETCH_ASSOC);
    $destino = null;
    $atendimento = null;
    foreach ($tipos as $tipo) {
        $chave = sem_acento((string) $tipo['nome']);
        if ($chave === 'outros') {
            $destino = (int) $tipo['id'];
        }
        if ($chave === 'atendimento') {
            $atendimento = (int) $tipo['id'];
        }
    }
    if ($atendimento === null) {
        $pdo->prepare('INSERT INTO tipos_reclamacao (nome, ativo, criado_em) VALUES (?, 1, ?)')->execute(['Atendimento', $momento]);
        $atendimento = (int) $pdo->lastInsertId();
    }
    if ($destino === null) {
        $destino = $atendimento;
    }
    $mover = $pdo->prepare('UPDATE reclamacoes SET tipo_id = ? WHERE tipo_id = ?');
    $apagar = $pdo->prepare('DELETE FROM tipos_reclamacao WHERE id = ?');
    foreach ($tipos as $tipo) {
        if (!in_array(sem_acento((string) $tipo['nome']), $remover, true)) {
            continue;
        }
        $mover->execute([$destino, (int) $tipo['id']]);
        $apagar->execute([(int) $tipo['id']]);
    }
    garantir_tipos_padrao($pdo);
}

function garantir_tipos_padrao(PDO $pdo): void
{
    $oficiais = [
        'Atendimento',
        'Atraso na entrega',
        'Cobrança indevida',
        'Conduta Antiprofissional',
        'Falha de sistema',
        'Outros',
        'Qualidade do serviço',
        'Valor',
    ];
    $existentes = [];
    foreach ($pdo->query('SELECT nome FROM tipos_reclamacao') as $linha) {
        $existentes[sem_acento((string) $linha['nome'])] = true;
    }
    $inserir = $pdo->prepare('INSERT INTO tipos_reclamacao (nome, ativo, criado_em) VALUES (?, 1, ?)');
    $momento = date('Y-m-d H:i:s');
    foreach ($oficiais as $nome) {
        if (isset($existentes[sem_acento($nome)])) {
            continue;
        }
        $inserir->execute([$nome, $momento]);
    }
}

function coluna_banco(PDO $pdo, string $tabela, string $coluna): bool
{
    if (!in_array($tabela, ['reclamacoes', 'setores', 'tipos_reclamacao'], true)) {
        return false;
    }
    if (driver_banco() === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$tabela, $coluna]);
        return (int) $stmt->fetchColumn() > 0;
    }
    foreach ($pdo->query('PRAGMA table_info(' . $tabela . ')') as $info) {
        if (($info['name'] ?? '') === $coluna) {
            return true;
        }
    }
    return false;
}

function tabela_banco(PDO $pdo, string $tabela): bool
{
    if ($tabela !== 'produtos_servicos') {
        return false;
    }
    if (driver_banco() === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$tabela]);
        return (int) $stmt->fetchColumn() > 0;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$tabela]);
    return $stmt->fetchColumn() !== false;
}

function tabela_cadastro(string $entidade): string
{
    $mapa = [
        'empresas' => 'empresas',
        'setores' => 'setores',
        'tipos' => 'tipos_reclamacao',
        'situacoes' => 'situacoes',
    ];
    if (!isset($mapa[$entidade])) {
        throw new ValidacaoException('Cadastro inválido.');
    }
    return $mapa[$entidade];
}
