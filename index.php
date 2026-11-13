<?php
declare(strict_types=1);

require __DIR__ . '/bd.php';
require_once __DIR__ . '/qr.php';
preparar_aplicacao();

if (usuario_da_sessao() !== null) {
    redirecionar('dashboard.php');
}

$aviso = mensagem_aviso();
$avisoInativo = $aviso === 'Seu usuário foi inativado. O acesso foi encerrado.';
$tokenConfigurado = trim(env('SSO_TOKEN')) !== '';
$linkPublico = url_formulario_publico();

layout_inicio('Entrar · Canal de Reclamações', 'pagina-acesso pagina-login');
?>
<div class="cenario" aria-hidden="true">
    <span class="orbita o1"></span>
    <span class="orbita o2"></span>
    <span class="orbita o3"></span>
    <span class="bolha b1"></span>
    <span class="bolha b2"></span>
    <span class="bolha b3"></span>
    <span class="bolha b4"></span>
    <span class="bolha b5"></span>
    <span class="bolha b6"></span>
    <span class="bolha anel a1"></span>
    <span class="bolha anel a2"></span>
</div>
<div class="entrada">
    <section class="bloco-formulario">
        <h2>Abra uma reclamação:</h2>
        <p class="resumo">Qualquer pessoa pode registrar, sem entrar no sistema.</p>
        <div class="acesso-qr"><?= qr_svg($linkPublico) ?></div>
        <div class="acesso-publico-linha">
            <a class="acesso-publico-link" id="url-publico" data-url="<?= e($linkPublico) ?>" href="<?= e($linkPublico) ?>" target="_blank" rel="noopener noreferrer" title="Canal público para reclamações (sem login)"><?= e($linkPublico) ?></a>
            <button class="acesso-publico-copiar" id="copiar-publico" type="button" aria-label="Copiar link" title="Copiar link">
                <i class="fa-regular fa-copy"></i>
            </button>
        </div>
    </section>
    <section class="acesso">
        <div class="acesso-marca">
            <img class="logo-wf logo-acesso" src="assets/wf-logo-branco.png" alt="Grupo WF">
            <h1>Canal de Reclamações</h1>
            <p>Registre o que o cliente relatou, acompanhe a tratativa e encerre com uma conclusão.</p>
        </div>
        <section class="acesso-form">
        <h2>Entrar</h2>
        <p class="resumo">Use seu usuário e senha da Provita</p>
        <?php if ($avisoInativo): ?>
            <div class="aviso aviso-erro">Seu usuário foi inativado. O acesso foi encerrado.</div>
        <?php endif; ?>
        <?php if (!$tokenConfigurado): ?>
            <div class="aviso">O token SSO ainda não foi preenchido. Grave <strong>SSO_TOKEN</strong> no arquivo .env.</div>
        <?php endif; ?>
        <div class="aviso aviso-erro" id="erro-login" hidden></div>
        <form id="form-login" action="api.php" method="post">
            <input type="hidden" name="acao" value="login">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label class="campo">
                <span>Usuário</span>
                <input name="usuario" autocomplete="username" required maxlength="80" autofocus>
            </label>
            <label class="campo">
                <span>Senha</span>
                <input name="senha" type="password" autocomplete="current-password" required>
            </label>
            <button class="btn btn-primario btn-bloco" type="submit">Entrar</button>
        </form>
        </section>
    </section>
</div>
<?php layout_fim(); ?>
