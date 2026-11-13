<?php
declare(strict_types=1);

require __DIR__ . '/bd.php';
preparar_aplicacao();

$usuario = usuario_da_sessao();
if ($usuario === null) {
    redirecionar('index.php');
}

$gerir = pode_gerir($usuario);
$admin = ($usuario['perfil'] ?? '') === 'administrador';
$permitidas = $admin
    ? ['dashboard', 'reclamacoes', 'acoes', 'usuarios', 'tipos', 'empresas', 'setores']
    : ($gerir ? ['dashboard', 'reclamacoes', 'acoes'] : ['reclamacoes']);
$pagina = (string) ($_GET['p'] ?? ($gerir ? 'dashboard' : 'reclamacoes'));
if (!in_array($pagina, $permitidas, true)) {
    redirecionar('dashboard.php?p=' . ($gerir ? 'dashboard' : 'reclamacoes'));
}
$recolhida = (($_COOKIE['menu_recolhido'] ?? '') === '1') ? ' recolhida' : '';
$foto = foto_segura($usuario['foto'] !== null ? (string) $usuario['foto'] : null);
$iniciais = iniciais((string) $usuario['nome']);
$dadosApp = [
    'pagina' => $pagina,
    'gerir' => $gerir,
    'usuario' => [
        'id' => (int) $usuario['id'],
        'nome' => $usuario['nome'],
        'perfil' => $usuario['perfil'],
    ],
];

layout_inicio('Canal de Reclamações', 'pagina-painel');
?>
<div class="app<?= e($recolhida) ?>" id="app">
    <aside class="sidebar" id="sidebar">
        <div class="marca">
            <button type="button" class="hamburguer" data-acao="alternar-menu" aria-label="Recolher menu" aria-expanded="true">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="marca-texto">
                <strong>Canal</strong>
                <span>de Reclamações</span>
            </div>
        </div>
        <nav class="menu" aria-label="Menu principal">
            <?php if ($gerir): ?>
            <div class="menu-grupo">
                <p class="menu-titulo">Principal</p>
                <button type="button" class="item" data-pagina="dashboard" title="Dashboard">
                    <i class="fa-solid fa-chart-pie"></i><span class="rotulo">Dashboard</span>
                </button>
                <button type="button" class="item" data-pagina="reclamacoes" title="Reclamações">
                    <i class="fa-solid fa-inbox"></i><span class="rotulo">Reclamações</span>
                </button>
                <button type="button" class="item" data-pagina="acoes" title="Ações">
                    <i class="fa-solid fa-list-check"></i><span class="rotulo">Ações</span>
                </button>
            </div>
            <?php if ($admin): ?>
            <div class="menu-grupo">
                <p class="menu-titulo">Configurações Gerais</p>
                <button type="button" class="item" data-pagina="empresas" title="Empresas"><i class="fa-solid fa-building"></i><span class="rotulo">Empresas</span></button>
                <button type="button" class="item" data-pagina="tipos" title="Tipos de reclamações"><i class="fa-solid fa-list"></i><span class="rotulo">Tipos de reclamações</span></button>
                <button type="button" class="item" data-pagina="usuarios" title="Usuários"><i class="fa-solid fa-users"></i><span class="rotulo">Usuários</span></button>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="usuario-rodape">
            <?php if ($foto !== null): ?>
                <img class="avatar" src="<?= e($foto) ?>" alt="" data-iniciais="<?= e($iniciais) ?>">
            <?php else: ?>
                <span class="iniciais"><?= e($iniciais) ?></span>
            <?php endif; ?>
            <div class="usuario-dados">
                <strong><?= e((string) $usuario['nome']) ?></strong>
                <span><?= e(rotulo_perfil((string) $usuario['perfil'])) ?></span>
            </div>
            <button type="button" class="sair" data-acao="sair" title="Sair" aria-label="Sair">
                <i class="fa-solid fa-right-from-bracket"></i>
            </button>
        </div>
    </aside>
    <div class="sombra-menu" data-acao="alternar-menu"></div>
    <div class="miolo">
        <header class="topo">
            <button type="button" class="hamburguer hamburguer-topo" data-acao="alternar-menu" aria-label="Abrir menu" aria-expanded="false">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="topo-titulo">
                <h1 id="titulo-pagina"></h1>
                <p id="subtitulo-pagina"></p>
            </div>
            <div id="topo-extra" class="topo-extra"></div>
        </header>
        <main id="conteudo" aria-live="polite"></main>
    </div>
</div>
<div id="modais"></div>
<div id="toasts" class="toast-area"></div>
<script id="app-dados" type="application/json"><?= json_encode($dadosApp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php layout_fim(); ?>
