<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

function alertar_nova_reclamacao(int $id): void
{
    try {
        if (trim(env('MAIL_HOST', '')) === '') {
            return;
        }
        $reclamacao = consultar_um(
            'SELECT r.protocolo, r.setor_id, r.nome_cliente, r.unidade,
                    r.responsavel_denuncia, r.responsavel_atendimento, r.data_reclamacao, r.criado_em,
                    st.nome AS setor_nome, e.nome AS empresa_nome, t.nome AS tipo_nome
             FROM reclamacoes r
             JOIN setores st ON st.id = r.setor_id
             LEFT JOIN empresas e ON e.id = st.empresa_id
             JOIN tipos_reclamacao t ON t.id = r.tipo_id
             WHERE r.id = ?',
            [$id]
        );
        if ($reclamacao === null) {
            return;
        }
        $destinatarios = destinatarios_alerta((int) $reclamacao['setor_id']);
        if ($destinatarios === []) {
            registrar_log('mail.log', 'Reclamação ' . $reclamacao['protocolo'] . ' sem destinatário com e-mail.');
            return;
        }
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            registrar_log('mail.log', 'PHPMailer ausente. A reclamação ' . $reclamacao['protocolo'] . ' foi salva sem alerta.');
            return;
        }
        require_once $autoload;
        enviar_alerta_reclamacao($reclamacao, $destinatarios);
    } catch (Throwable $e) {
        registrar_log('mail.log', 'Reclamação ' . $id . ': ' . $e->getMessage());
    }
}

function destinatarios_alerta(int $setorId): array
{
    $linhas = consultar(
        'SELECT DISTINCT u.email, u.nome
         FROM usuarios u
         LEFT JOIN usuario_setores us ON us.usuario_id = u.id AND us.setor_id = ?
         WHERE u.ativo = 1
           AND COALESCE(u.bloqueado, 0) = 0
           AND u.email IS NOT NULL
           AND TRIM(u.email) <> \'\'
           AND (
                u.perfil = \'diretoria\'
                OR us.setor_id IS NOT NULL
                OR (
                    u.perfil = \'administrador\'
                    AND NOT EXISTS (SELECT 1 FROM usuario_setores ux WHERE ux.usuario_id = u.id)
                )
           )',
        [$setorId]
    );
    $vistos = [];
    $destinatarios = [];
    foreach ($linhas as $linha) {
        $email = trim((string) ($linha['email'] ?? ''));
        $chave = mb_strtolower($email);
        if ($chave === '' || isset($vistos[$chave]) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }
        $vistos[$chave] = true;
        $destinatarios[] = [
            'email' => $email,
            'nome' => trim((string) ($linha['nome'] ?? '')),
        ];
    }
    return $destinatarios;
}

function enviar_alerta_reclamacao(array $reclamacao, array $destinatarios): void
{
    $protocolo = (string) $reclamacao['protocolo'];
    $assunto = 'Nova reclamação recebida — ' . $protocolo;
    $logo = __DIR__ . '/assets/wf-logo-branco.png';
    $temLogo = is_file($logo);
    [$html, $texto] = corpo_alerta_reclamacao($reclamacao, $temLogo);
    $mail = mailer();
    $mail->Subject = $assunto;
    $mail->Body = $html;
    $mail->AltBody = $texto;
    if ($temLogo) {
        $mail->addEmbeddedImage($logo, 'logo-wf', 'wf-logo.png');
    }
    $mail->SMTPKeepAlive = true;
    $enviados = 0;
    foreach ($destinatarios as $pessoa) {
        try {
            $mail->clearAddresses();
            $mail->addAddress($pessoa['email'], $pessoa['nome']);
            $mail->send();
            $enviados++;
        } catch (MailException $e) {
            registrar_log('mail.log', 'Reclamação ' . $protocolo . ' para ' . $pessoa['email'] . ': ' . $mail->ErrorInfo);
        }
    }
    $mail->smtpClose();
    if ($enviados === 0) {
        registrar_log('mail.log', 'Reclamação ' . $protocolo . ' não teve nenhum alerta entregue.');
    }
}

function mailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = env('MAIL_HOST');
    $mail->Port = (int) env('MAIL_PORT', '465');
    $mail->SMTPAuth = true;
    $mail->Username = env('MAIL_USERNAME');
    $mail->Password = env('MAIL_PASSWORD');
    $cripto = strtolower(env('MAIL_ENCRYPTION', 'ssl'));
    $mail->SMTPSecure = $cripto === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 20;
    $mail->setFrom(env('MAIL_FROM'), env('MAIL_FROM_NAME', 'Canal de Reclamações'));
    $mail->isHTML(true);
    return $mail;
}

function corpo_alerta_reclamacao(array $reclamacao, bool $temLogo): array
{
    $empresa = trim((string) ($reclamacao['empresa_nome'] ?? ''));
    $setor = trim((string) ($reclamacao['setor_nome'] ?? ''));
    $protocolo = trim((string) ($reclamacao['protocolo'] ?? ''));
    $url = url_painel();
    $aviso = 'O cronômetro para resolver esta reclamação já iniciou a contagem.';
    $introHtml = $setor !== ''
        ? 'Uma nova reclamação de cliente foi registrada para o setor <strong>' . e($setor) . '</strong>. Acesse o sistema para visualizar o relato completo e iniciar a tratativa.'
        : 'Uma nova reclamação de cliente foi registrada. Acesse o sistema para visualizar o relato completo e iniciar a tratativa.';
    $introTexto = $setor !== ''
        ? 'Uma nova reclamação de cliente foi registrada para o setor ' . $setor . '. Acesse o sistema para visualizar o relato completo e iniciar a tratativa.'
        : 'Uma nova reclamação de cliente foi registrada. Acesse o sistema para visualizar o relato completo e iniciar a tratativa.';
    $campos = [
        'Tipo' => (string) ($reclamacao['tipo_nome'] ?? ''),
        'Setor' => (string) ($reclamacao['setor_nome'] ?? ''),
        'Empresa cliente' => (string) ($reclamacao['nome_cliente'] ?? ''),
        'Unidade' => (string) ($reclamacao['unidade'] ?? ''),
        'Quem fez a reclamação' => (string) ($reclamacao['responsavel_denuncia'] ?? ''),
        'Quem abriu' => (string) ($reclamacao['responsavel_atendimento'] ?? ''),
        'Data da reclamação' => data_alerta((string) ($reclamacao['data_reclamacao'] ?? '')),
        'Recebida em' => data_alerta((string) ($reclamacao['criado_em'] ?? '')),
    ];
    $linhasHtml = '';
    $linhasTexto = ['Protocolo: ' . $protocolo];
    $indice = 0;
    foreach ($campos as $rotulo => $valor) {
        if (trim($valor) === '') {
            continue;
        }
        $linhasHtml .= linha_alerta($rotulo, $valor, $indice % 2 === 1);
        $linhasTexto[] = $rotulo . ': ' . trim($valor);
        $indice++;
    }
    $logo = $temLogo
        ? '<img src="cid:logo-wf" alt="Grupo WF" width="52" height="52" style="display:block;margin:0 auto 12px;border:0;border-radius:12px;">'
        : '';
    $empresaHtml = $empresa !== ''
        ? '<p style="margin:10px 0 0;font-size:13px;letter-spacing:1.4px;text-transform:uppercase;">' . e($empresa) . '</p>'
        : '';
    $botao = '';
    $linkTexto = '';
    if ($url !== '') {
        $botao = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding-top:32px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" align="center">'
            . '<tr><td bgcolor="#0f5132" style="border-radius:8px;">'
            . '<a href="' . e($url) . '" style="display:inline-block;padding:12px 22px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">Ver reclamação &rarr;</a>'
            . '</td></tr></table>'
            . '<p style="margin:14px 0 0;text-align:center;font-size:13px;line-height:1.5;color:#5e7268;">Ou copie o link:<br>'
            . '<a href="' . e($url) . '" style="color:#146c43;font-weight:600;word-break:break-all;">' . e($url) . '</a></p>'
            . '</td></tr></table>';
        $linkTexto = "\n\nVer reclamação: " . $url;
    }
    $html = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . e($aviso) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7f5;margin:0;padding:0;">'
        . '<tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid #d5e2da;border-radius:16px;overflow:hidden;font-family:Segoe UI,Arial,sans-serif;color:#1a2e24;">'
        . '<tr><td style="background:#9b1c1c;color:#ffffff;padding:16px 28px;text-align:center;">'
        . '<p style="margin:0;font-size:12px;font-weight:700;letter-spacing:1.6px;">ATENÇÃO</p>'
        . '<p style="margin:6px 0 0;font-size:16px;line-height:1.45;font-weight:700;">' . e($aviso) . '</p>'
        . '</td></tr>'
        . '<tr><td style="background:#0f5132;color:#ffffff;padding:28px 24px 24px;text-align:center;">'
        . $logo
        . '<p style="margin:0;font-size:12px;letter-spacing:2px;">CANAL DE RECLAMAÇÕES</p>'
        . '<p style="margin:14px 0 0;font-size:26px;line-height:1.25;font-weight:700;">Nova reclamação recebida</p>'
        . $empresaHtml
        . '</td></tr>'
        . '<tr><td style="background:#e7f6ee;padding:22px 24px;text-align:center;">'
        . '<p style="margin:0;color:#5e7268;font-size:12px;font-weight:700;letter-spacing:1.8px;">PROTOCOLO</p>'
        . '<p style="margin:8px 0 0;color:#0f5132;font-size:28px;line-height:1.2;font-weight:700;letter-spacing:1px;">' . e($protocolo) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 32px 32px;">'
        . '<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#1a2e24;">' . $introHtml . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3ebe6;border-radius:10px;overflow:hidden;">'
        . $linhasHtml
        . '</table>'
        . $botao
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>';
    $texto = "ATENÇÃO\n" . $aviso . "\n\nNova reclamação recebida"
        . ($empresa !== '' ? "\n" . $empresa : '')
        . "\n\n" . $introTexto . "\n\n" . implode("\n", $linhasTexto) . $linkTexto;
    return [$html, $texto];
}

function linha_alerta(string $rotulo, string $valor, bool $zebrada): string
{
    $fundo = $zebrada ? '#f3faf6' : '#ffffff';
    $celula = 'padding:12px 16px;vertical-align:top;background:' . $fundo . ';border-bottom:1px solid #e3ebe6;';
    return '<tr>'
        . '<td style="' . $celula . 'width:42%;color:#5e7268;font-size:12px;font-weight:700;letter-spacing:0.4px;text-transform:uppercase;">' . e($rotulo) . '</td>'
        . '<td style="' . $celula . 'color:#1a2e24;font-size:15px;font-weight:600;overflow-wrap:anywhere;word-break:break-word;">' . e(trim($valor)) . '</td>'
        . '</tr>';
}

function data_alerta(string $data): string
{
    $data = trim($data);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $data, $partes) !== 1) {
        return $data;
    }
    $texto = $partes[3] . '/' . $partes[2] . '/' . $partes[1];
    if (($partes[4] ?? '') !== '' && ($partes[5] ?? '') !== '') {
        $texto .= ' às ' . $partes[4] . ':' . $partes[5];
    }
    return $texto;
}

function url_painel(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host) !== 1) {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || requisicao_https();
    return ($https ? 'https://' : 'http://') . $host . uri_app() . 'dashboard.php?p=reclamacoes';
}
