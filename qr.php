<?php
declare(strict_types=1);

function qr_svg(string $texto): string
{
    $modulos = qr_matriz($texto);
    $n = count($modulos);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" shape-rendering="crispEdges" role="img" aria-label="QR Code do formulário público">';
    $svg .= '<rect width="' . $n . '" height="' . $n . '" fill="#fff"/>';
    for ($y = 0; $y < $n; $y++) {
        $x = 0;
        while ($x < $n) {
            if (!$modulos[$y][$x]) {
                $x++;
                continue;
            }
            $fim = $x;
            while ($fim < $n && $modulos[$y][$fim]) {
                $fim++;
            }
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . ($fim - $x) . '" height="1" fill="#146c43"/>';
            $x = $fim;
        }
    }
    return $svg . '</svg>';
}

function qr_matriz(string $texto): array
{
    $bytes = array_values(unpack('C*', $texto));
    $tabela = [
        1 => [1, 16, 10],
        2 => [1, 28, 16],
        3 => [1, 44, 26],
        4 => [2, 32, 18],
        5 => [2, 43, 24],
        6 => [4, 27, 16],
        7 => [4, 31, 18],
        8 => [4, 38, 22],
        9 => [4, 43, 26],
        10 => [4, 50, 30],
    ];
    $versao = 1;
    foreach ($tabela as $v => $info) {
        $capacidade = intdiv(($info[0] * $info[1] * 8) - 4 - 8 - 4, 8);
        if (count($bytes) <= $capacidade) {
            $versao = $v;
            break;
        }
        $versao = $v;
    }
    [$blocos, $dadosBloco, $eccBloco] = $tabela[$versao];
    $dados = qr_dados($bytes, $blocos * $dadosBloco);
    $ecc = qr_intercalar($dados, $blocos, $dadosBloco, $eccBloco);
    $n = 17 + ($versao * 4);
    $matriz = array_fill(0, $n, array_fill(0, $n, false));
    $reservado = array_fill(0, $n, array_fill(0, $n, false));
    qr_funcoes($matriz, $reservado, $versao);
    qr_dados_na_matriz($matriz, $reservado, $ecc);
    return qr_melhor_mascara($matriz, $reservado, $versao);
}

function qr_dados(array $bytes, int $total): array
{
    $bits = [0, 1, 0, 0];
    $contagem = count($bytes);
    for ($i = 7; $i >= 0; $i--) {
        $bits[] = ($contagem >> $i) & 1;
    }
    foreach ($bytes as $byte) {
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = ($byte >> $i) & 1;
        }
    }
    $limite = $total * 8;
    $faltam = min(4, $limite - count($bits));
    for ($i = 0; $i < $faltam; $i++) {
        $bits[] = 0;
    }
    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }
    $saida = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $valor = 0;
        for ($b = 0; $b < 8; $b++) {
            $valor = ($valor << 1) | $bits[$i + $b];
        }
        $saida[] = $valor;
    }
    $padrao = [0xEC, 0x11];
    $p = 0;
    while (count($saida) < $total) {
        $saida[] = $padrao[$p % 2];
        $p++;
    }
    return $saida;
}

function qr_intercalar(array $dados, int $blocos, int $dadosBloco, int $eccBloco): array
{
    $partes = array_chunk($dados, $dadosBloco);
    $erros = [];
    foreach ($partes as $parte) {
        $erros[] = qr_rs($parte, $eccBloco);
    }
    $saida = [];
    for ($i = 0; $i < $dadosBloco; $i++) {
        for ($b = 0; $b < $blocos; $b++) {
            $saida[] = $partes[$b][$i];
        }
    }
    for ($i = 0; $i < $eccBloco; $i++) {
        for ($b = 0; $b < $blocos; $b++) {
            $saida[] = $erros[$b][$i];
        }
    }
    return $saida;
}

function qr_gf_exp(): array
{
    static $exp = null;
    static $log = null;
    if ($exp !== null) {
        return [$exp, $log];
    }
    $exp = [];
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    return [$exp, $log];
}

function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = qr_gf_exp();
    return $exp[$log[$a] + $log[$b]];
}

function qr_rs(array $dados, int $nsym): array
{
    [$exp, $log] = qr_gf_exp();
    $gerador = [1];
    for ($i = 0; $i < $nsym; $i++) {
        $proximo = array_fill(0, count($gerador) + 1, 0);
        foreach ($gerador as $grau => $coef) {
            $proximo[$grau] ^= $coef;
            $proximo[$grau + 1] ^= qr_gf_mul($coef, $exp[$i]);
        }
        $gerador = $proximo;
    }
    $resto = array_fill(0, $nsym, 0);
    foreach ($dados as $byte) {
        $fator = $byte ^ $resto[0];
        array_shift($resto);
        $resto[] = 0;
        if ($fator !== 0) {
            for ($i = 0; $i < $nsym; $i++) {
                $resto[$i] ^= qr_gf_mul($gerador[$i + 1], $fator);
            }
        }
    }
    return $resto;
}

function qr_marcar(array &$matriz, array &$reservado, int $x, int $y, bool $escuro): void
{
    if ($y < 0 || $x < 0 || $y >= count($matriz) || $x >= count($matriz)) {
        return;
    }
    $matriz[$y][$x] = $escuro;
    $reservado[$y][$x] = true;
}

function qr_finder(array &$matriz, array &$reservado, int $x, int $y): void
{
    for ($dy = -1; $dy <= 7; $dy++) {
        for ($dx = -1; $dx <= 7; $dx++) {
            $borda = $dx === -1 || $dy === -1 || $dx === 7 || $dy === 7;
            $anel = $dx === 0 || $dy === 0 || $dx === 6 || $dy === 6;
            $miolo = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;
            qr_marcar($matriz, $reservado, $x + $dx, $y + $dy, !$borda && ($anel || $miolo));
        }
    }
}

function qr_funcoes(array &$matriz, array &$reservado, int $versao): void
{
    $n = count($matriz);
    qr_finder($matriz, $reservado, 0, 0);
    qr_finder($matriz, $reservado, $n - 7, 0);
    qr_finder($matriz, $reservado, 0, $n - 7);
    $pos = [2 => [18], 3 => [22], 4 => [26], 5 => [30], 6 => [34], 7 => [22, 38], 8 => [24, 42], 9 => [26, 46], 10 => [28, 50]];
    $pontos = array_merge([6], $pos[$versao] ?? []);
    foreach ($pontos as $y) {
        foreach ($pontos as $x) {
            if ($reservado[$y][$x] ?? false) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $escuro = max(abs($dx), abs($dy)) !== 1;
                    qr_marcar($matriz, $reservado, $x + $dx, $y + $dy, $escuro);
                }
            }
        }
    }
    for ($i = 0; $i < $n; $i++) {
        if (!$reservado[$i][6]) {
            qr_marcar($matriz, $reservado, 6, $i, $i % 2 === 0);
        }
        if (!$reservado[6][$i]) {
            qr_marcar($matriz, $reservado, $i, 6, $i % 2 === 0);
        }
    }
    qr_marcar($matriz, $reservado, 8, 4 * $versao + 9, true);
    for ($i = 0; $i < 9; $i++) {
        if ($i === 6) {
            continue;
        }
        qr_marcar($matriz, $reservado, 8, $i, false);
        qr_marcar($matriz, $reservado, $i, 8, false);
    }
    for ($i = 0; $i < 8; $i++) {
        qr_marcar($matriz, $reservado, $n - 1 - $i, 8, false);
        qr_marcar($matriz, $reservado, 8, $n - 1 - $i, false);
    }
}

function qr_dados_na_matriz(array &$matriz, array $reservado, array $codewords): void
{
    $n = count($matriz);
    $bits = [];
    foreach ($codewords as $byte) {
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = (($byte >> $i) & 1) === 1;
        }
    }
    $i = 0;
    $total = count($bits);
    for ($col = $n - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        for ($vertical = 0; $vertical < $n; $vertical++) {
            for ($k = 0; $k < 2; $k++) {
                $x = $col - $k;
                $subida = intdiv($n - 1 - $col, 2) % 2 === 0;
                $y = $subida ? ($n - 1 - $vertical) : $vertical;
                if ($reservado[$y][$x]) {
                    continue;
                }
                $matriz[$y][$x] = $i < $total ? $bits[$i] : false;
                $i++;
            }
        }
    }
}

function qr_mascarado(array $matriz, array $reservado, int $mascara): array
{
    $n = count($matriz);
    $copia = $matriz;
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            if ($reservado[$y][$x]) {
                continue;
            }
            $aplica = match ($mascara) {
                0 => ($y + $x) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($y + $x) % 3 === 0,
                4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                5 => (($y * $x) % 2) + (($y * $x) % 3) === 0,
                6 => ((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0,
                default => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            };
            if ($aplica) {
                $copia[$y][$x] = !$copia[$y][$x];
            }
        }
    }
    return $copia;
}

function qr_formatar(array $matriz, int $mascara): array
{
    $dados = $mascara;
    $bits = $dados << 10;
    for ($i = 4; $i >= 0; $i--) {
        if ($bits & (1 << ($i + 10))) {
            $bits ^= 0x537 << $i;
        }
    }
    $formato = (($dados << 10) | ($bits & 0x3FF)) ^ 0x5412;
    $n = count($matriz);
    $posA = [[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]];
    $posB = [[8, $n - 1], [8, $n - 2], [8, $n - 3], [8, $n - 4], [8, $n - 5], [8, $n - 6], [8, $n - 7], [$n - 8, 8], [$n - 7, 8], [$n - 6, 8], [$n - 5, 8], [$n - 4, 8], [$n - 3, 8], [$n - 2, 8], [$n - 1, 8]];
    for ($i = 0; $i < 15; $i++) {
        $escuro = (($formato >> (14 - $i)) & 1) === 1;
        $matriz[$posA[$i][1]][$posA[$i][0]] = $escuro;
        $matriz[$posB[$i][1]][$posB[$i][0]] = $escuro;
    }
    return $matriz;
}

function qr_penalidade(array $matriz): int
{
    $n = count($matriz);
    $pontos = 0;
    for ($y = 0; $y < $n; $y++) {
        $corrida = 1;
        for ($x = 1; $x < $n; $x++) {
            if ($matriz[$y][$x] === $matriz[$y][$x - 1]) {
                $corrida++;
                if ($corrida === 5) {
                    $pontos += 3;
                } elseif ($corrida > 5) {
                    $pontos++;
                }
            } else {
                $corrida = 1;
            }
        }
    }
    for ($x = 0; $x < $n; $x++) {
        $corrida = 1;
        for ($y = 1; $y < $n; $y++) {
            if ($matriz[$y][$x] === $matriz[$y - 1][$x]) {
                $corrida++;
                if ($corrida === 5) {
                    $pontos += 3;
                } elseif ($corrida > 5) {
                    $pontos++;
                }
            } else {
                $corrida = 1;
            }
        }
    }
    for ($y = 0; $y < $n - 1; $y++) {
        for ($x = 0; $x < $n - 1; $x++) {
            $v = $matriz[$y][$x];
            if ($v === $matriz[$y][$x + 1] && $v === $matriz[$y + 1][$x] && $v === $matriz[$y + 1][$x + 1]) {
                $pontos += 3;
            }
        }
    }
    $escuros = 0;
    foreach ($matriz as $linha) {
        foreach ($linha as $modulo) {
            if ($modulo) {
                $escuros++;
            }
        }
    }
    $pct = (int) abs((($escuros * 100) / ($n * $n)) - 50);
    return $pontos + (intdiv($pct, 5) * 10);
}

function qr_melhor_mascara(array $matriz, array $reservado, int $versao): array
{
    $melhor = null;
    $nota = PHP_INT_MAX;
    for ($m = 0; $m < 8; $m++) {
        $copia = qr_formatar(qr_mascarado($matriz, $reservado, $m), $m);
        $atual = qr_penalidade($copia);
        if ($atual < $nota) {
            $nota = $atual;
            $melhor = $copia;
        }
    }
    return $melhor ?? $matriz;
}
