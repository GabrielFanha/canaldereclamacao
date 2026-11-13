<?php
declare(strict_types=1);

require __DIR__ . '/../bd.php';
preparar_aplicacao();
marcar_formulario_publico();

$opcoes = opcoes_publicas();
$liberado = $opcoes['empresas'] !== [] && $opcoes['setores'] !== [] && $opcoes['tipos'] !== [];
$raiz = uri_app();

function rotulo_form(string $texto, bool $obrigatorio): string
{
    $html = e($texto);
    if ($obrigatorio) {
        $html .= ' <abbr class="req" title="obrigatório">*</abbr>';
    }
    return $html;
}

layout_inicio('Registrar reclamação · Canal de Reclamações', 'pagina-acesso pagina-formulario');
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
<header class="cabecalho-form">
    <div class="cabecalho-form-marca">
        <img class="logo-wf logo-claro" src="<?= e($raiz) ?>assets/wf-logo-verde.png" alt="Grupo WF">
        <div>
            <strong>Canal de Reclamações</strong>
            <span>Grupo WF</span>
        </div>
    </div>
    <button type="button" class="btn trocar-empresa" id="trocar-empresa" hidden>Trocar empresa</button>
</header>
<main class="cartao" id="cartao-publico">
    <h1 id="titulo-publico">Bem-vindo</h1>
    <p class="resumo" id="resumo-publico">Este é o canal para registrar uma reclamação.</p>
    <?php if (!$liberado): ?>
        <div class="aviso">O formulário ainda não está disponível. A equipe precisa cadastrar empresas, setores e tipos.</div>
    <?php else: ?>
        <form id="form-empresa" class="form-google">
            <label class="campo">
                <span><?= rotulo_form('Qual empresa do Grupo WF recebeu a reclamação?', true) ?></span>
                <select id="empresa-publica" required>
                    <option value="">Selecione</option>
                    <?= html_opcoes($opcoes['empresas']) ?>
                </select>
            </label>
            <button class="btn btn-primario" type="submit">Continuar</button>
        </form>
        <form id="form-publico" class="form-google" action="<?= e($raiz) ?>api.php" method="post" enctype="multipart/form-data" hidden>
            <input type="hidden" name="acao" value="reclamacao_publica">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="empresa_id" id="empresa-escolhida" value="">
            <div class="hp" aria-hidden="true">
                <label>Não preencha este campo<input name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <hr class="linha-form">
            <div class="form-par">
                <label class="campo">
                    <span><?= rotulo_form('Quem está abrindo a reclamação? (Seu nome)', true) ?></span>
                    <input name="responsavel_atendimento" required minlength="2" maxlength="150">
                </label>
                <label class="campo">
                    <span><?= rotulo_form('Quem fez a reclamação (Nome do Cliente)', true) ?></span>
                    <input name="responsavel_denuncia" required minlength="2" maxlength="150">
                </label>
            </div>
            <div class="form-par">
                <label class="campo">
                    <span><?= rotulo_form('Nome da empresa cliente (Reclamante)', true) ?></span>
                    <input name="nome_cliente" required minlength="2" maxlength="150">
                </label>
                <label class="campo">
                    <span><?= rotulo_form('Unidade/Posto de Trabalho (Reclamante)', false) ?></span>
                    <input name="unidade" maxlength="120">
                </label>
            </div>
            <div class="form-par form-par-tipo">
                <label class="campo">
                    <span><?= rotulo_form('Setor da reclamação', true) ?></span>
                    <select name="setor_id" id="setor-publico" required>
                        <option value="">Selecione</option>
                    </select>
                </label>
                <label class="campo">
                    <span><?= rotulo_form('Tipo de reclamação', true) ?></span>
                    <select name="tipo_id" required>
                        <option value="">Selecione</option>
                        <?= html_opcoes($opcoes['tipos']) ?>
                    </select>
                </label>
                <label class="campo campo-data">
                    <span><?= rotulo_form('Data da reclamação', true) ?></span>
                    <input type="date" name="data_reclamacao" required value="<?= e(date('Y-m-d')) ?>">
                </label>
            </div>
            <label class="campo">
                <span><?= rotulo_form('Descrição', true) ?></span>
                <textarea name="descricao" required minlength="100" maxlength="5000" placeholder="Descreva a reclamação do cliente"></textarea>
            </label>
            <div class="campo">
                <span>Anexos</span>
                <div class="soltar">
                    <input class="soltar-arquivo" name="anexos[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
                    <div class="soltar-alvo">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <strong>Solte os arquivos aqui</strong>
                        <button type="button" class="soltar-escolher">ou selecione no dispositivo</button>
                    </div>
                    <ul class="soltar-nomes"></ul>
                    <small>Prefira documentos em PDF, que são de mais fácil visualização. Também aceita imagem, Word ou Excel. Até 5 arquivos de 20 MB.</small>
                </div>
            </div>
            <button class="btn btn-primario" type="submit">Enviar reclamação</button>
        </form>
    <?php endif; ?>
</main>
<script id="setores-publicos" type="application/json"><?= json_encode($opcoes['setores'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<div id="modais"></div>
<div id="toasts" class="toast-area"></div>
<?php layout_fim(); ?>
