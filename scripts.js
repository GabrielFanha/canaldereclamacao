(function () {
    "use strict";

    const LIMITE_ANEXO = 20 * 1024 * 1024;

    const METADADOS = {
        dashboard: ["Dashboard", "Visão geral das reclamações"],
        reclamacoes: ["Reclamações", "Faça o acompanhamento, tratativa e conclusão das reclamações nesta página."],
        acoes: ["Ações", "Acompanhe as ações do plano e o prazo de cada uma."],
        usuarios: ["Usuários", ""],
        tipos: ["Tipos de reclamações", ""],
        empresas: ["Empresas", ""],
        setores: ["Setores", ""]
    };

    const PAGINAS_GESTAO = ["dashboard", "reclamacoes", "acoes", "usuarios", "tipos", "empresas", "setores"];
    const PAGINAS_ADMIN = ["usuarios", "tipos", "empresas", "setores"];

    const CADASTROS = {
        empresas: { entidade: "empresas", vazio: "Nenhuma empresa cadastrada." },
        setores: { entidade: "setores", vazio: "Nenhum setor cadastrado." },
        tipos: { entidade: "tipos", vazio: "Nenhum tipo cadastrado." }
    };

    const estado = {
        pagina: "dashboard",
        catalogo: null,
        filtros: filtrosPadrao(),
        relatorio: filtrosPadrao(),
        detalhe: null,
        lista: null,
        usuarios: [],
        abaLista: "ativos",
        empresaSetor: 0,
        abaPrincipal: "reclamacao",
        editandoTratativa: "",
        filtrosAcoes: filtrosAcoesPadrao(),
        dashboard: { empresa_id: 0, mes: "" }
    };

    let resolverConfirmacao = null;
    let conclusaoAcaoPendente = "";
    let resolverVinculo = null;
    let buscaTimer = null;
    let relogioTimer = null;
    let ajustarRoda = function () {};

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("img.avatar").forEach(function (img) {
            img.addEventListener("error", function () {
                const troca = document.createElement("span");
                troca.className = "iniciais";
                troca.textContent = img.getAttribute("data-iniciais") || "?";
                img.replaceWith(troca);
            });
        });
        const login = document.getElementById("form-login");
        if (login) {
            login.addEventListener("submit", enviarLogin);
            if (/[?&]aviso=/.test(location.search)) {
                const limpa = new URL(location.href);
                limpa.searchParams.delete("aviso");
                history.replaceState(null, "", limpa.pathname + limpa.search + limpa.hash);
            }
        }
        const copiarPublico = document.getElementById("copiar-publico");
        if (copiarPublico) copiarPublico.addEventListener("click", copiarLinkPublico);
        const publico = document.getElementById("form-publico");
        if (publico) {
            publico.addEventListener("submit", enviarPublico);
            ligarEtapasPublicas();
            ligarCanal(document);
            ligarContadores(publico);
            document.addEventListener("click", function (e) {
                const alvo = e.target.closest("[data-acao]");
                if (!alvo) return;
                if (alvo.dataset.acao === "fechar-modal" || alvo.dataset.acao === "confirmar-nao") fecharModal(false);
                if (alvo.dataset.acao === "confirmar-sim") fecharModal(true);
            });
        }
        if (document.getElementById("app")) iniciarPainel();
    });

    function csrf() {
        const meta = document.querySelector('meta[name="csrf"]');
        return meta ? meta.getAttribute("content") || "" : "";
    }

    function urlApi() {
        const meta = document.querySelector('meta[name="raiz"]');
        const raiz = meta ? meta.getAttribute("content") || "" : "";
        return raiz + "api.php";
    }

    function semAcento(valor) {
        return String(valor == null ? "" : valor).normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }

    function esc(valor) {
        return String(valor == null ? "" : valor).replace(/[&<>"']/g, function (caractere) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[caractere];
        });
    }

    function formatarData(iso) {
        if (!iso) return "—";
        const partes = String(iso).split(" ");
        const data = partes[0].split("-");
        if (data.length !== 3) return esc(iso);
        const hora = partes[1] ? partes[1].slice(0, 5) : "";
        return data[2] + "/" + data[1] + "/" + data[0] + (hora ? " " + hora : "");
    }

    function dataHoje() {
        const partes = new Intl.DateTimeFormat("en-CA", {
            timeZone: "America/Sao_Paulo",
            year: "numeric",
            month: "2-digit",
            day: "2-digit"
        }).formatToParts(new Date());
        const mapa = {};
        partes.forEach(function (parte) { mapa[parte.type] = parte.value; });
        return mapa.year + "-" + mapa.month + "-" + mapa.day;
    }

    function formatarDuracao(total) {
        total = Math.max(0, Math.floor(Number(total) || 0));
        const dias = Math.floor(total / 86400);
        const horas = Math.floor((total % 86400) / 3600);
        const minutos = Math.floor((total % 3600) / 60);
        const segundos = total % 60;
        const p = function (n) { return String(n).padStart(2, "0"); };
        if (dias > 0) return dias + "d " + p(horas) + "h " + p(minutos) + "min " + p(segundos) + "s";
        if (horas > 0) return horas + "h " + p(minutos) + "min " + p(segundos) + "s";
        return p(minutos) + "min " + p(segundos) + "s";
    }

    function rotuloProtocolo(valor) {
        const texto = String(valor || "").trim();
        if (!texto || texto === "—") return "—";
        return texto.charAt(0) === "#" ? texto : "#" + texto;
    }

    function htmlTempo(item) {
        const aberto = item && item.tempo_aberto ? "1" : "0";
        const segundos = Math.max(0, Math.floor(Number(item && item.tempo_segundos) || 0));
        const duracao = formatarDuracao(segundos);
        const titulo = (item && item.tempo_titulo) || (aberto === "1" ? "Em andamento" : "Concluída");
        return '<span class="etiqueta-tempo contador" data-segundos="' + segundos + '" data-aberto="' + aberto + '" title="' + esc(duracao + " · " + titulo) + '"><i class="fa-solid fa-stopwatch"></i><span class="tempo-valor">' + duracao + "</span></span>";
    }

    function pararRelogio() {
        if (relogioTimer) {
            clearInterval(relogioTimer);
            relogioTimer = null;
        }
    }

    function ligarRelogio() {
        const marca = performance.now();
        document.querySelectorAll(".contador").forEach(function (el) {
            if (!el.dataset.marca) el.dataset.marca = String(marca);
        });
        if (relogioTimer || !document.querySelector(".contador[data-aberto='1']")) return;
        relogioTimer = setInterval(function () {
            const agora = performance.now();
            const abertos = document.querySelectorAll(".contador[data-aberto='1']");
            if (!abertos.length) {
                pararRelogio();
                return;
            }
            abertos.forEach(function (el) {
                const valor = el.querySelector(".tempo-valor");
                if (!valor) return;
                const base = Number(el.dataset.segundos) || 0;
                const inicio = Number(el.dataset.marca) || agora;
                valor.textContent = formatarDuracao(base + Math.max(0, Math.floor((agora - inicio) / 1000)));
            });
        }, 1000);
    }

    function formatarBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + " B";
        if (n < 1048576) return (n / 1024).toFixed(1).replace(".", ",") + " KB";
        return (n / 1048576).toFixed(1).replace(".", ",") + " MB";
    }

    function rotuloTotal(total) {
        const n = Number(total) || 0;
        return n === 1 ? "1 reclamação" : n + " reclamações";
    }

    function filtrosPadrao() {
        return {
            busca: "", status_id: "", setor_id: "", tipo_id: "", empresa_id: "",
            situacao_id: "", origem: "", data_ini: "", data_fim: "",
            status_grupo: "", periodo: "", pagina: 1
        };
    }

    function filtrosAcoesPadrao() {
        return { busca: "", empresa_id: "", setor_id: "", data_ini: "", data_fim: "", situacao: "", pagina: 1 };
    }

    function classeSituacao(nome) {
        const n = String(nome || "").toLowerCase();
        if (n.indexOf("improced") !== -1) return "selo-vermelho";
        if (n.indexOf("proced") !== -1) return "selo-verde";
        if (n.indexOf("autoriz") !== -1) return "selo-azul";
        return "selo-cinza";
    }

    const CORES_STATUS = [
        "#fde2e4", "#f8c4c4", "#f6d0c4", "#fce1c8", "#f6d6a8", "#fff3bf",
        "#e7f5c8", "#d8f3dc", "#b7e4c7", "#c8f0e4", "#d0f4f7", "#c5e8f7",
        "#dbe7ff", "#d5d9f6", "#e5dff6", "#e7d5f5", "#f8d7e8", "#f3d1dc",
        "#efe6d8", "#e2e8e4"
    ];

    function seloCor(nome, cor) {
        return '<span class="selo" style="background:' + esc(cor) + ';color:#3d5148">' + esc(nome) + "</span>";
    }

    function seloStatus(nome, fixo, cor) {
        const fundo = cor || (fixo === "final" ? "#d8f3dc" : (fixo === "inicial" ? "#fff3bf" : "#dbe7ff"));
        return '<span class="selo" style="background:' + esc(fundo) + ';color:#3d5148">' + esc(nome) + "</span>";
    }

    function seloOrigem(origem) {
        if (origem === "publica") return '<span class="selo selo-cinza">Pública</span>';
        return '<span class="selo selo-verde">Interna</span>';
    }

    function htmlOpcoes(lista, selecionado, placeholder, somenteAtivos) {
        let html = '<option value="">' + esc(placeholder) + "</option>";
        (lista || []).forEach(function (item) {
            if (somenteAtivos && Number(item.ativo) !== 1 && String(selecionado) !== String(item.id)) return;
            const sel = String(selecionado || "") === String(item.id) ? " selected" : "";
            const marca = Number(item.ativo) === 0 ? " (inativo)" : "";
            html += '<option value="' + Number(item.id) + '"' + sel + ">" + esc(item.nome) + marca + "</option>";
        });
        return html;
    }

    function ativos(lista) {
        return (lista || []).filter(function (item) { return Number(item.ativo) === 1; });
    }

    async function requisicao(fd) {
        let res;
        try {
            res = await fetch(urlApi(), { method: "POST", body: fd, headers: { Accept: "application/json" } });
        } catch (e) {
            throw new Error("Sem conexão com o servidor.");
        }
        const tipo = res.headers.get("Content-Type") || "";
        if (tipo.indexOf("text/csv") !== -1) return res;
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        if (res.status === 401 && fd.get("acao") !== "login") {
            const codigo = json && json.dados && json.dados.codigo === "inativo" ? "inativo" : "expirada";
            window.location.href = "index.php?aviso=" + codigo;
            throw new Error(codigo === "inativo" ? "Seu usuário foi inativado." : "Sessão expirada.");
        }
        if (!json || json.ok !== true) {
            throw new Error((json && json.mensagem) || "Não foi possível concluir a operação.");
        }
        return json;
    }

    function api(acao, dados) {
        const fd = new FormData();
        fd.append("acao", acao);
        fd.append("csrf", csrf());
        Object.keys(dados || {}).forEach(function (chave) {
            const valor = dados[chave];
            if (valor !== undefined && valor !== null) fd.append(chave, String(valor));
        });
        return requisicao(fd);
    }

    function apiForm(form) {
        const fd = new FormData(form);
        if (!fd.get("csrf")) fd.append("csrf", csrf());
        const arquivos = form.querySelector('input[type="file"]');
        if (arquivos && arquivos.files && arquivos.files.length > 5) {
            return Promise.reject(new Error("Envie no máximo 5 arquivos."));
        }
        if (arquivos && arquivos.files) {
            for (let i = 0; i < arquivos.files.length; i++) {
                if (arquivos.files[i].size > LIMITE_ANEXO) {
                    return Promise.reject(new Error("Cada anexo pode ter no máximo 20 MB."));
                }
            }
        }
        return requisicao(fd);
    }

    function toast(mensagem, tipo) {
        const area = document.getElementById("toasts");
        if (!area) return;
        const el = document.createElement("div");
        el.className = "toast" + (tipo === "erro" ? " erro" : "");
        el.setAttribute("role", "alert");
        const duracao = document.body.classList.contains("pagina-formulario") ? 7000 : 5000;
        el.innerHTML = '<div class="toast-linha"><p></p><span class="toast-tempo"></span><button type="button" class="toast-fechar" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div><span class="toast-barra"></span>';
        el.querySelector("p").textContent = mensagem;
        const tempo = el.querySelector(".toast-tempo");
        const barra = el.querySelector(".toast-barra");
        barra.style.animationDuration = duracao + "ms";
        area.appendChild(el);
        const inicio = Date.now();
        function atualizarTempo() {
            const falta = Math.max(0, Math.ceil((duracao - (Date.now() - inicio)) / 1000));
            tempo.textContent = falta + "s";
        }
        atualizarTempo();
        const relogio = setInterval(atualizarTempo, 250);
        let timer = 0;
        function fechar() {
            clearInterval(relogio);
            clearTimeout(timer);
            el.remove();
        }
        timer = setTimeout(fechar, duracao);
        el.querySelector(".toast-fechar").addEventListener("click", fechar);
    }

    function mostrarModal(html, largo) {
        const fundo = document.createElement("div");
        fundo.className = "modal-fundo";
        fundo.innerHTML = html;
        const modal = fundo.querySelector(".modal");
        if (modal && largo) modal.classList.add("largo");
        document.getElementById("modais").appendChild(fundo);
        document.body.classList.add("modal-aberto");
        ligarContadores(fundo);
        const foco = fundo.querySelector("input, select, textarea, button");
        if (foco) foco.focus();
        return fundo;
    }

    function fecharModal(confirmar) {
        const el = document.querySelector("#modais .modal-fundo:last-child");
        if (!el) return;
        const tipo = el.getAttribute("data-tipo");
        const vinculo = tipo === "vinculo" ? el._vinculo || null : null;
        el.remove();
        if (!document.querySelector("#modais .modal-fundo")) {
            document.body.classList.remove("modal-aberto");
        }
        if (tipo === "vinculo" && resolverVinculo) {
            const resolver = resolverVinculo;
            resolverVinculo = null;
            resolver(vinculo);
        }
        if ((tipo === "confirmacao" || tipo === "destino") && resolverConfirmacao) {
            const resolver = resolverConfirmacao;
            resolverConfirmacao = null;
            resolver(tipo === "destino" ? confirmar : confirmar === true);
        }
    }

    function fecharTodosModais() {
        const caixa = document.getElementById("modais");
        if (caixa) caixa.innerHTML = "";
        document.body.classList.remove("modal-aberto");
        if (resolverConfirmacao) {
            const resolver = resolverConfirmacao;
            resolverConfirmacao = null;
            resolver(false);
        }
    }

    function denunciaAlterada() {
        const form = document.getElementById("form-comentario");
        const editor = form && form.querySelector(".editor-area");
        const comentario = !!(form && ((editor && editor.textContent.trim()) || (form._arquivos && form._arquivos.length)));
        return { comentario: comentario, etapa: etapaTratativaPendente() };
    }

    function textoSaidaDenuncia(alt) {
        if (alt.etapa && alt.comentario) return "A tratativa e o comentário ainda não foram salvos. Deseja salvar antes de sair?";
        if (alt.etapa) return "A tratativa ainda não foi salva. Deseja salvar antes de sair?";
        return "O comentário ainda não foi enviado. Deseja salvar antes de sair?";
    }

    function perguntarSaida(texto) {
        return new Promise(function (resolve) {
            resolverConfirmacao = resolve;
            const fundo = mostrarModal(
                '<div class="modal" role="dialog" aria-modal="true">' +
                '<div class="modal-cabeca"><h2>Sair sem salvar?</h2><button type="button" class="fechar" data-acao="saida-ficar" aria-label="Continuar editando"><i class="fa-solid fa-xmark"></i></button></div>' +
                '<div class="modal-corpo"><p>' + esc(texto) + "</p>" +
                '<div class="grupo-botoes" style="margin-top:16px">' +
                '<button type="button" class="btn btn-fantasma" data-acao="saida-descartar">Sair sem salvar</button>' +
                '<button type="button" class="btn btn-primario" data-acao="saida-salvar">Salvar</button>' +
                "</div></div></div>"
            );
            fundo.setAttribute("data-tipo", "saida");
        });
    }

    function resolverSaida(valor) {
        const el = document.querySelector("#modais .modal-fundo:last-child");
        if (!el || el.getAttribute("data-tipo") !== "saida") return;
        el.remove();
        if (!document.querySelector("#modais .modal-fundo")) {
            document.body.classList.remove("modal-aberto");
        }
        if (resolverConfirmacao) {
            const resolver = resolverConfirmacao;
            resolverConfirmacao = null;
            resolver(valor);
        }
    }

    async function salvarAlteracoesDenuncia(alt) {
        const payload = alt.etapa ? dadosTratativa(alt.etapa) : null;
        const form = document.getElementById("form-comentario");
        if (alt.comentario && form) await salvarComentario(form);
        if (payload) await api("tratativa_salvar", payload);
        await atualizarListaAberta();
        fecharTodosModais();
    }

    async function tentarFecharAcao() {
        const form = document.getElementById("form-comentario-acao");
        const editor = form && form.querySelector(".editor-area");
        const pendente = !!(form && ((editor && editor.textContent.trim()) || (form._arquivos && form._arquivos.length)));
        if (!pendente) {
            estado.acao = null;
            fecharModal(false);
            return;
        }
        const escolha = await perguntarSaida("O comentário ainda não foi enviado. Deseja salvar antes de sair?");
        if (escolha === "descartar") {
            estado.acao = null;
            fecharModal(false);
        }
        if (escolha === "salvar") {
            await salvarComentario(form, false);
            estado.acao = null;
            fecharTodosModais();
        }
    }

    function abrirReclamacao(id) {
        if (document.querySelector(".modal-acao")) return abrirReclamacaoDaAcao(id);
        return abrirDetalhe(id);
    }

    async function abrirReclamacaoDaAcao(id) {
        const form = document.getElementById("form-comentario-acao");
        const editor = form && form.querySelector(".editor-area");
        const pendente = !!(form && ((editor && editor.textContent.trim()) || (form._arquivos && form._arquivos.length)));
        if (pendente) {
            const escolha = await perguntarSaida("O comentário ainda não foi enviado. Deseja salvar antes de sair?");
            if (escolha !== "descartar" && escolha !== "salvar") return;
            if (escolha === "salvar") await salvarComentario(form, false);
        }
        estado.acao = null;
        return abrirDetalhe(id);
    }

    async function abrirAcao(id) {
        if (!id) return;
        const res = await api("acao_detalhe", { id: id });
        estado.acao = res.dados;
        pintarAcao();
    }

    async function tentarFecharDenuncia() {
        const alt = denunciaAlterada();
        if (!alt.etapa && !alt.comentario) {
            fecharModal(false);
            return;
        }
        const escolha = await perguntarSaida(textoSaidaDenuncia(alt));
        if (escolha === "descartar") fecharModal(false);
        if (escolha === "salvar") await salvarAlteracoesDenuncia(alt);
    }

    function confirmar(texto, titulo, rotuloSim) {
        return new Promise(function (resolve) {
            resolverConfirmacao = resolve;
            const fundo = mostrarModal(
                '<div class="modal" role="dialog" aria-modal="true">' +
                '<div class="modal-cabeca"><h2>' + esc(titulo || "Confirmar") + "</h2></div>" +
                '<div class="modal-corpo"><p>' + esc(texto) + "</p>" +
                '<div class="grupo-botoes" style="margin-top:16px">' +
                '<button type="button" class="btn btn-fantasma" data-acao="confirmar-nao">Cancelar</button>' +
                '<button type="button" class="btn btn-primario" data-acao="confirmar-sim">' + esc(rotuloSim || "Confirmar") + "</button>" +
                "</div></div></div>"
            );
            fundo.setAttribute("data-tipo", "confirmacao");
        });
    }

    function confirmarEscrito(texto, fraseEsperada) {
        const esperada = fraseEsperada || "CONFIRMAR";
        return new Promise(function (resolve) {
            resolverConfirmacao = resolve;
            const fundo = mostrarModal(
                '<form id="form-confirmar-escrito" class="modal" role="dialog" aria-modal="true">' +
                '<div class="modal-cabeca"><h2>Confirmar</h2></div>' +
                '<div class="modal-corpo"><p>' + esc(texto) + "</p>" +
                '<label class="campo" style="margin-top:12px"><span>Escreva ' + esc(esperada) + '</span><input name="frase" data-esperada="' + esc(esperada) + '" autocomplete="off" placeholder="' + esc(esperada) + '"></label>' +
                '<div class="grupo-botoes" style="margin-top:16px">' +
                '<button type="button" class="btn btn-fantasma" data-acao="confirmar-nao">Cancelar</button>' +
                '<button type="submit" class="btn btn-primario">Confirmar</button>' +
                "</div></div></form>"
            );
            fundo.setAttribute("data-tipo", "confirmacao");
            const campo = fundo.querySelector('[name="frase"]');
            if (campo) campo.focus();
        });
    }

    function pedirConclusaoAcao() {
        conclusaoAcaoPendente = "";
        return new Promise(function (resolve) {
            resolverConfirmacao = function (ok) {
                resolve(ok ? conclusaoAcaoPendente : "");
            };
            const fundo = mostrarModal(
                '<form id="form-conclusao-acao" class="modal" role="dialog" aria-modal="true">' +
                '<div class="modal-cabeca"><h2>Concluir ação</h2></div>' +
                '<div class="modal-corpo"><p>Descreva a conclusão desta ação. O texto fica registrado como comentário final, com quem concluiu e a data.</p>' +
                '<label class="campo" style="margin-top:12px"><span>Conclusão</span><textarea name="conclusao" minlength="80" maxlength="2000" placeholder="Descreva a conclusão"></textarea></label>' +
                '<div class="grupo-botoes" style="margin-top:16px">' +
                '<button type="button" class="btn btn-fantasma" data-acao="confirmar-nao">Cancelar</button>' +
                '<button type="submit" class="btn btn-primario">Continuar</button>' +
                "</div></div></form>"
            );
            fundo.setAttribute("data-tipo", "confirmacao");
            const campo = fundo.querySelector("textarea");
            if (campo) campo.focus();
        });
    }

    function travar(botao) {
        if (!botao) return;
        botao.disabled = true;
        botao.dataset.rotulo = botao.innerHTML;
        botao.innerHTML = botao.classList.contains("comentario-enviar")
            ? '<i class="fa-solid fa-spinner fa-spin"></i>'
            : '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
    }

    function destravar(botao) {
        if (!botao) return;
        botao.disabled = false;
        if (botao.dataset.rotulo) botao.innerHTML = botao.dataset.rotulo;
    }

    function erroBox(id, mensagem) {
        const el = document.getElementById(id);
        if (!el) return;
        el.hidden = false;
        el.textContent = mensagem;
    }

    async function copiarLinkPublico(e) {
        e.preventDefault();
        e.stopPropagation();
        const alvo = document.getElementById("url-publico");
        const texto = alvo ? (alvo.getAttribute("data-url") || alvo.textContent.trim()) : "";
        const botao = e.currentTarget;
        if (!texto || !botao) return;
        const original = botao.innerHTML;
        const titulo = botao.title;
        try {
            await navigator.clipboard.writeText(texto);
            botao.innerHTML = '<i class="fa-solid fa-check"></i>';
            botao.title = "Copiado";
        } catch (err) {
            const area = document.createElement("textarea");
            area.value = texto;
            area.setAttribute("readonly", "");
            area.style.position = "fixed";
            area.style.left = "-9999px";
            document.body.appendChild(area);
            area.select();
            const ok = document.execCommand("copy");
            area.remove();
            if (!ok) return;
            botao.innerHTML = '<i class="fa-solid fa-check"></i>';
            botao.title = "Copiado";
        }
        window.setTimeout(function () {
            botao.innerHTML = original;
            botao.title = titulo;
        }, 1800);
    }

    async function enviarLogin(e) {
        e.preventDefault();
        const form = e.currentTarget;
        const botao = form.querySelector('button[type="submit"]');
        const erro = document.getElementById("erro-login");
        if (erro) erro.hidden = true;
        travar(botao);
        try {
            const res = await apiForm(form);
            window.location.href = (res.dados && res.dados.redirecionar) || "dashboard.php";
        } catch (err) {
            destravar(botao);
            erroBox("erro-login", err.message);
        }
    }

    function ligarEtapasPublicas() {
        const etapa = document.getElementById("form-empresa");
        const form = document.getElementById("form-publico");
        const empresa = document.getElementById("empresa-publica");
        const setor = document.getElementById("setor-publico");
        const escolhida = document.getElementById("empresa-escolhida");
        const bruto = document.getElementById("setores-publicos");
        const titulo = document.getElementById("titulo-publico");
        const resumo = document.getElementById("resumo-publico");
        const trocar = document.getElementById("trocar-empresa");
        if (!etapa || !form || !empresa || !setor || !escolhida || !bruto) return;
        let setores = [];
        try { setores = JSON.parse(bruto.textContent || "[]"); } catch (e) { setores = []; }
        etapa.addEventListener("submit", function (e) {
            e.preventDefault();
            const id = String(empresa.value || "");
            const option = empresa.options[empresa.selectedIndex];
            const nome = option ? option.text : "";
            const itens = setores.filter(function (item) { return String(item.empresa_id) === id; });
            if (!id || !itens.length) {
                toast("Esta empresa ainda não tem setores disponíveis.", "erro");
                return;
            }
            setor.innerHTML = '<option value="">Selecione</option>' + itens.map(function (item) {
                return '<option value="' + Number(item.id) + '">' + esc(item.nome) + "</option>";
            }).join("");
            setor.value = "";
            escolhida.value = id;
            if (titulo) titulo.textContent = "Registre a reclamação para " + nome;
            if (resumo) resumo.hidden = true;
            if (trocar) trocar.hidden = false;
            etapa.hidden = true;
            form.hidden = false;
        });
        if (trocar) {
            trocar.addEventListener("click", function () {
                form.hidden = true;
                etapa.hidden = false;
                if (titulo) titulo.textContent = "Bem-vindo";
                if (resumo) resumo.hidden = false;
                if (trocar) trocar.hidden = true;
            });
        }
    }

    function textoControle(form, nome) {
        const el = form.elements[nome];
        if (!el) return "";
        if (el.tagName === "SELECT") {
            const option = el.options[el.selectedIndex];
            return option && el.value ? option.text : "";
        }
        return String(el.value || "").trim();
    }

    function linhaRevisao(rotulo, valor, cheia) {
        const texto = String(valor || "").trim();
        return '<div class="revisao-item' + (cheia ? " revisao-cheia" : "") + '"><dt>' + esc(rotulo) + "</dt><dd>" + esc(texto || "—") + "</dd></div>";
    }

    function confirmarEnvioPublico(form) {
        const empresa = document.getElementById("empresa-publica");
        const option = empresa && empresa.options[empresa.selectedIndex];
        const nomeEmpresa = option && empresa.value ? option.text : "";
        const arquivos = form.querySelector('input[type="file"]');
        const nomes = arquivos ? Array.from(arquivos.files || []).map(function (arquivo) { return arquivo.name; }) : [];
        const data = textoControle(form, "data_reclamacao");
        const html =
            '<div class="modal modal-confirmacao-envio" role="dialog" aria-modal="true">' +
            '<div class="modal-cabeca"><h2>Confirmar envio</h2><button type="button" class="fechar" data-acao="confirmar-nao" aria-label="Voltar"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="modal-corpo"><p class="resumo resumo-confirma">Confira os dados antes de enviar. Se algo estiver errado, volte e ajuste o formulário.</p>' +
            '<dl class="revisao-envio">' +
            linhaRevisao("Empresa", nomeEmpresa) +
            linhaRevisao("Quem está abrindo a reclamação? (Seu nome)", textoControle(form, "responsavel_atendimento")) +
            linhaRevisao("Quem fez a reclamação (Nome do Cliente)", textoControle(form, "responsavel_denuncia")) +
            linhaRevisao("Nome da empresa cliente (Reclamante)", textoControle(form, "nome_cliente")) +
            linhaRevisao("Unidade/Posto de Trabalho (Reclamante)", textoControle(form, "unidade")) +
            linhaRevisao("Setor da reclamação", textoControle(form, "setor_id")) +
            linhaRevisao("Tipo de reclamação", textoControle(form, "tipo_id")) +
            linhaRevisao("Data da reclamação", data ? formatarData(data) : "") +
            linhaRevisao("Descrição", textoControle(form, "descricao"), true) +
            linhaRevisao("Anexos", nomes.length ? nomes.join("\n") : "Nenhum anexo", true) +
            "</dl>" +
            '<div class="grupo-botoes" style="margin-top:16px">' +
            '<button type="button" class="btn btn-fantasma" data-acao="confirmar-nao">Voltar</button>' +
            '<button type="button" class="btn btn-primario" data-acao="confirmar-sim">Confirmar envio</button>' +
            "</div></div></div>";
        return new Promise(function (resolve) {
            resolverConfirmacao = resolve;
            const fundo = mostrarModal(html, true);
            fundo.setAttribute("data-tipo", "confirmacao");
        });
    }

    async function enviarPublico(e) {
        e.preventDefault();
        const form = e.currentTarget;
        const botao = form.querySelector('button[type="submit"]');
        const focado = document.activeElement;
        if (focado && form.contains(focado) && focado.classList.contains("busca-texto")) focado.blur();
        const buscaIncompleta = Array.from(form.querySelectorAll(".busca-opcao")).some(function (caixa) {
            return !caixa.querySelector('input[type="hidden"]').value;
        });
        if (buscaIncompleta) {
            toast("Escolha setor e tipo na lista de opções cadastradas.", "erro");
            return;
        }
        const confirmado = await confirmarEnvioPublico(form);
        if (!confirmado) return;
        travar(botao);
        try {
            await apiForm(form);
            const trocar = document.getElementById("trocar-empresa");
            if (trocar) trocar.hidden = true;
            const cartao = document.getElementById("cartao-publico");
            cartao.innerHTML =
                '<div class="sucesso-protocolo">' +
                '<div class="check-animado" aria-hidden="true"><svg viewBox="0 0 72 72">' +
                '<circle class="check-anel" cx="36" cy="36" r="32"></circle>' +
                '<path class="check-traco" d="M22 37.5 L32 47.5 L50 26"></path>' +
                "</svg></div>" +
                "<h1>Reclamação Registrada com Sucesso!</h1>" +
                '<button type="button" class="btn btn-primario" id="nova-publica">Registre Outra Reclamação</button></div>';
            document.getElementById("nova-publica").addEventListener("click", function () { window.location.reload(); });
        } catch (err) {
            destravar(botao);
            toast(err.message, "erro");
        }
    }

    function iniciarPainel() {
        const bruto = document.getElementById("app-dados");
        window.APP = JSON.parse(bruto.textContent);
        estado.pagina = APP.pagina;
        document.addEventListener("click", tratarClique);
        document.addEventListener("submit", tratarSubmit);
        document.addEventListener("change", tratarChange);
        document.addEventListener("input", tratarInput);
        document.addEventListener("keydown", function (e) {
            if (e.key !== "Escape") return;
        if (fecharMenusStatus()) return;
            const topo = document.querySelector("#modais .modal-fundo:last-child");
            if (topo && topo.getAttribute("data-tipo") === "saida") {
                resolverSaida("ficar");
                return;
            }
            if (topo && topo.querySelector(".modal-acao")) {
                tentarFecharAcao();
                return;
            }
            if (topo && topo.querySelector(".modal-denuncia")) {
                tentarFecharDenuncia();
                return;
            }
            fecharModal(false);
        });
        window.addEventListener("resize", function () {
            atualizarHamburger();
            ajustarRoda();
        });
        window.addEventListener("popstate", function () {
            const params = new URLSearchParams(window.location.search);
            estado.empresaSetor = Number(params.get("empresa")) || 0;
            abrirPagina(params.get("p") || (APP.gerir ? "dashboard" : "reclamacoes"), false);
        });
        atualizarHamburger();
        if (APP.pagina === "setores") {
            estado.empresaSetor = Number(new URLSearchParams(window.location.search).get("empresa")) || 0;
        }
        abrirPagina(APP.pagina, false);
    }

    function tratarClique(e) {
        if (!e.target.closest(".chat-seta, .chat-menu")) fecharMenusChat();
        if (!e.target.closest(".menu-status")) fecharMenusStatus();
        const opcaoStatus = e.target.closest(".status-opcao");
        if (opcaoStatus) {
            escolherStatusAcao(opcaoStatus).catch(function (err) {
                toast(err.message || "Não foi possível atualizar o status.", "erro");
            });
            return;
        }
        const gatilhoStatus = e.target.closest(".menu-status .select-status-acao");
        if (gatilhoStatus) {
            alternarMenuStatus(gatilhoStatus);
            return;
        }
        const alvo = e.target.closest("[data-acao], [data-pagina]");
        if (!alvo) return;
        if (alvo.dataset.pagina && alvo.closest(".sidebar")) {
            abrirPagina(alvo.dataset.pagina, true);
            return;
        }
        const acao = alvo.dataset.acao;
        if (!acao) return;
        const exec = ACOES[acao];
        if (!exec) return;
        Promise.resolve(exec(alvo, e)).catch(function (err) {
            toast(err.message || "Não foi possível concluir a operação.", "erro");
        });
    }

    function idFormulario(form) {
        return form.getAttribute("id") || "";
    }

    function tratarSubmit(e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const id = idFormulario(form);
        if (id === "form-filtros") {
            e.preventDefault();
            aplicarFiltros();
            return;
        }
        if (id === "form-conclusao-acao") {
            e.preventDefault();
            const texto = String(new FormData(form).get("conclusao") || "").trim();
            if (Array.from(texto).length < 80) {
                toast("Descreva a conclusão com pelo menos 80 caracteres.", "erro");
                return;
            }
            conclusaoAcaoPendente = texto;
            fecharModal(true);
            return;
        }
        if (id === "form-confirmar-escrito") {
            e.preventDefault();
            const campo = form.querySelector('[name="frase"]');
            const esperada = (campo && campo.dataset.esperada) || "CONFIRMAR";
            const frase = String(new FormData(form).get("frase") || "").trim();
            if (frase !== esperada) {
                toast("Escreva " + esperada + " para continuar.", "erro");
                return;
            }
            fecharModal(true);
            return;
        }
        if (id === "form-destino-status") {
            e.preventDefault();
            const destino = Number(new FormData(form).get("destino_id") || 0);
            if (!destino) {
                toast("Escolha o status que receberá as reclamações.", "erro");
                return;
            }
            fecharModal(destino);
            return;
        }
        if (id === "form-incluir-usuarios") {
            e.preventDefault();
            salvarInclusaoUsuarios(form).catch(function (err) {
                toast(err.message || "Não foi possível incluir os usuários.", "erro");
                destravar(form.querySelector('button[type="submit"]'));
            });
            return;
        }
        if (id === "form-vinculo-gestor") {
            e.preventDefault();
            const empresa = Number(new FormData(form).get("empresa_id") || 0);
            const setores = Array.from(form.querySelectorAll(".selo-setor.ativa")).map(function (botao) { return Number(botao.dataset.id); });
            const opcional = String(new FormData(form).get("vinculo_opcional") || "") === "1";
            if (opcional && !setores.length) {
                const fundo = form.closest(".modal-fundo");
                if (fundo) fundo._vinculo = { empresa_id: 0, setores: [] };
                fecharModal(false);
                return;
            }
            if (!empresa) {
                toast("Selecione a empresa.", "erro");
                return;
            }
            if (!setores.length) {
                toast("Selecione ao menos um setor.", "erro");
                return;
            }
            const fundo = form.closest(".modal-fundo");
            if (fundo) fundo._vinculo = { empresa_id: empresa, setores: setores };
            fecharModal(false);
            return;
        }
        if (id === "form-vinculo-usuario") {
            e.preventDefault();
            salvarVinculoUsuario(form).catch(function (err) {
                toast(err.message || "Não foi possível salvar os setores.", "erro");
                destravar(form.querySelector('button[type="submit"]'));
            });
            return;
        }
        if (id === "form-conclusao" || id === "form-comentario" || id === "form-comentario-acao" || id === "form-cadastro" || id === "form-status") {
            e.preventDefault();
            const mapa = {
                "form-conclusao": salvarConclusao,
                "form-comentario": salvarComentario,
                "form-comentario-acao": salvarComentario,
                "form-cadastro": salvarCadastro,
                "form-status": salvarStatus
            };
            mapa[id](form).catch(function (err) {
                toast(err.message || "Não foi possível concluir a operação.", "erro");
                destravar(form.querySelector('button[type="submit"]'));
            });
        }
    }

    function tratarChange(e) {
        const alvo = e.target;
        if (alvo.dataset.acao === "perfil") {
            salvarPerfil(alvo).catch(function (err) {
                toast(err.message, "erro");
                renderUsuarios();
            });
            return;
        }
        if (alvo.id === "perfil-novo") {
            const bloco = document.getElementById("bloco-gestor");
            if (bloco) bloco.hidden = alvo.value !== "gestao" && alvo.value !== "administrador";
            const dica = document.getElementById("dica-admin");
            if (dica) dica.hidden = alvo.value !== "administrador";
            return;
        }
        if (alvo.closest("#form-filtros-acoes") && (alvo.tagName === "SELECT" || alvo.type === "date")) {
            if (alvo.name === "empresa_id") {
                const form = document.getElementById("form-filtros-acoes");
                const setor = form.querySelector('[name="setor_id"]');
                const lista = setoresPorEmpresa(estado.catalogo && estado.catalogo.setores, alvo.value);
                const ainda = lista.some(function (item) { return String(item.id) === String(setor.value); });
                setor.innerHTML = htmlOpcoes(opcoesSetor(lista, perfilAmplo() && !alvo.value), ainda ? setor.value : "", "Todos", false);
            }
            aplicarFiltrosAcoes();
            return;
        }
        if (alvo.closest("#form-filtros") && (alvo.tagName === "SELECT" || alvo.type === "date")) {
            const form = document.getElementById("form-filtros");
            form.querySelector('[name="status_grupo"]').value = "";
            form.querySelector('[name="periodo"]').value = "";
            if (alvo.name === "empresa_id") {
                const setor = form.querySelector('[name="setor_id"]');
                const lista = setoresPorEmpresa(estado.catalogo && estado.catalogo.setores, alvo.value);
                const ainda = lista.some(function (item) { return String(item.id) === String(setor.value); });
                setor.innerHTML = htmlOpcoes(opcoesSetor(lista, !alvo.value), ainda ? setor.value : "", "Todos", false);
            }
            aplicarFiltros();
            return;
        }
        if (alvo.id === "filtro-empresa-dashboard" || alvo.id === "filtro-mes-dashboard") {
            aplicarFiltroDashboard();
        }
    }

    function tratarInput(e) {
        if (e.target.id === "busca-acoes" || e.target.id === "busca-reclamacoes") {
            clearTimeout(buscaTimer);
            buscaTimer = setTimeout(function () {
                if (e.target.id === "busca-acoes") aplicarFiltrosAcoes();
                else aplicarFiltros();
            }, 400);
        }
    }

    const ACOES = {
        "alternar-menu": function () { alternarMenu(); },
        sair: async function () {
            await api("sair");
            window.location.href = "index.php";
        },
        "copiar-link": async function (alvo) {
            const campo = document.getElementById("link-publico");
            const texto = ((alvo && alvo.dataset.url) || (campo && (campo.getAttribute("data-url") || campo.value)) || "").trim();
            if (!texto) return;
            try {
                await navigator.clipboard.writeText(texto);
                toast("Link copiado.", "ok");
            } catch (e) {
                const area = document.createElement("textarea");
                area.value = texto;
                area.setAttribute("readonly", "");
                area.style.position = "fixed";
                area.style.left = "-9999px";
                document.body.appendChild(area);
                area.select();
                const ok = document.execCommand("copy");
                area.remove();
                toast(ok ? "Link copiado." : "Não foi possível copiar o link.", ok ? "ok" : "erro");
            }
        },
        "limpar-filtros": function () {
            if (estado.pagina === "acoes") {
                estado.filtrosAcoes = filtrosAcoesPadrao();
                renderPagina("acoes");
                return;
            }
            if (estado.pagina === "relatorios") estado.relatorio = filtrosPadrao();
            else estado.filtros = filtrosPadrao();
            renderPagina(estado.pagina);
        },
        pagina: function (alvo) {
            if (estado.pagina === "acoes") {
                sincronizarFiltrosAcoes(Number(alvo.dataset.pagina) || 1);
                renderAcoes().catch(function (err) { toast(err.message, "erro"); });
                return;
            }
            const filtros = filtrosAtuais();
            filtros.pagina = Number(alvo.dataset.pagina) || 1;
            carregarLista();
        },
        "editar-setor": function () { editarSetor(); },
        "cancelar-setor": function () { cancelarSetor(); },
        "salvar-setor": function () { return salvarSetor(); },
        "abrir-reclamacao": function (alvo) { return abrirReclamacao(Number(alvo.dataset.id)); },
        "abrir-acao": function (alvo) { return abrirDetalhe(Number(alvo.dataset.id), "tratativas"); },
        "abrir-plano": function (alvo) { return abrirAcao(Number(alvo.dataset.id)); },
        "filtrar-acao": function (alvo) {
            sincronizarFiltrosAcoes(1);
            const situacao = alvo.dataset.situacao || "";
            const atual = estado.filtrosAcoes.situacao || "";
            estado.filtrosAcoes.situacao = situacao !== "" && atual === situacao ? "" : situacao;
            renderAcoes().catch(function (err) { toast(err.message, "erro"); });
        },
        "ir-reclamacoes": function (alvo) {
            estado.filtros = filtrosPadrao();
            if (alvo.dataset.grupo) estado.filtros.status_grupo = alvo.dataset.grupo;
            if (alvo.dataset.periodo) estado.filtros.periodo = alvo.dataset.periodo;
            abrirPagina("reclamacoes", true);
        },
        "confirmar-sim": function () { fecharModal(true); },
        "confirmar-nao": function () { fecharModal(false); },
        "fechar-modal": function () {
            const topo = document.querySelector("#modais .modal-fundo:last-child");
            if (topo && topo.querySelector(".modal-acao")) return tentarFecharAcao();
            if (topo && topo.querySelector(".modal-denuncia")) return tentarFecharDenuncia();
            fecharModal(false);
        },
        "saida-ficar": function () { resolverSaida("ficar"); },
        "saida-descartar": function () { resolverSaida("descartar"); },
        "saida-salvar": function () { resolverSaida("salvar"); },
        "abrir-conclusao": function () { abrirConclusao(); },
        "aba-principal": function (alvo) { ativarAbaPrincipal(alvo.dataset.aba); },
        "menu-comentario": function (alvo) { alternarMenuComentario(alvo); },
        "editar-comentario": function (alvo) { editarComentario(Number(alvo.dataset.id)); },
        "cancelar-edicao-comentario": function () { cancelarEdicaoComentario(); },
        "excluir-comentario": function (alvo) { return excluirComentario(Number(alvo.dataset.id)); },
        "iniciar-tratativa": function () { return iniciarTratativa(); },
        "salvar-tratativa": function (alvo) { return salvarTratativa(alvo.dataset.etapa); },
        "plano-adicionar": function () { adicionarLinhaPlano(); },
        "plano-remover": function (alvo) { removerLinhaPlano(alvo); },
        "salvar-status": function () {
            const select = document.getElementById("selecionar-status");
            if (!select) return;
            return mudarStatus(select);
        },
        "reabrir-reclamacao": function (alvo) { return reabrirReclamacao(alvo); },
        "ver-anexo": function (alvo) { return abrirAnexo(alvo.dataset.id, alvo.dataset.nome, alvo.dataset.origem || ""); },
        "baixar-anexo": function (alvo) { return confirmarDownload(alvo.dataset.id, alvo.dataset.nome, alvo.dataset.origem || ""); },
        aba: function (alvo) { ativarAba(alvo.dataset.aba); },
        "editar-usuario": function (alvo) { abrirUsuario(Number(alvo.dataset.id)); },
        "usuario-bloqueio": function (alvo) { return alterarBloqueio(Number(alvo.dataset.id), Number(alvo.dataset.bloqueado)); },
        "sincronizar-usuarios": function () { return sincronizarUsuarios(); },
        "adicionar-usuarios": function () { return abrirInclusaoUsuarios(); },
        "usuario-excluir": function (alvo) { return excluirUsuario(Number(alvo.dataset.id)); },
        "novo-cadastro": function () { abrirFormCadastro(null); },
        "setores-empresa": function (alvo) {
            estado.empresaSetor = Number(alvo.dataset.id) || 0;
            abrirPagina("setores", true);
        },
        "voltar-empresas": function () { abrirPagina("empresas", true); },
        "editar-cadastro": function (alvo) {
            const item = (estado.lista || []).find(function (row) { return row.id === Number(alvo.dataset.id); });
            abrirFormCadastro(item || null);
        },
        "excluir-cadastro": function (alvo) { return excluirCadastro(Number(alvo.dataset.id)); },
        "alternar-ativo": function (alvo) { return alternarAtivo(Number(alvo.dataset.id), Number(alvo.dataset.ativo)); },
        "escolher-cor": function (alvo) {
            const grupo = alvo.closest(".cores");
            if (!grupo) return;
            grupo.querySelectorAll("button").forEach(function (botao) { botao.classList.remove("selecionada"); });
            alvo.classList.add("selecionada");
            const campo = grupo.querySelector('input[name="cor"]');
            if (campo) campo.value = alvo.dataset.cor || "";
        },
        "novo-status": function () { abrirFormStatus(null); },
        "editar-status": function (alvo) {
            const item = (estado.catalogo.status || []).find(function (row) { return row.id === Number(alvo.dataset.id); });
            abrirFormStatus(item || null);
        },
        "excluir-status": function (alvo) { return excluirStatus(Number(alvo.dataset.id)); },
        "inativar-status": function (alvo) { return inativarStatus(Number(alvo.dataset.id), Number(alvo.dataset.ativo)); },
        "aba-lista": function (alvo) {
            estado.abaLista = alvo.dataset.aba === "inativos" ? "inativos" : "ativos";
            if (estado.pagina === "status") return renderStatus();
            if (estado.pagina === "usuarios") return renderUsuarios(false);
            if (CADASTROS[estado.pagina]) return renderCadastro(estado.pagina);
        },
        "exportar-csv": function () { return baixarCsv(); }
    };

    function alternarMenu() {
        const app = document.getElementById("app");
        const mobile = window.matchMedia("(max-width: 860px)").matches;
        if (mobile) app.classList.toggle("menu-aberto");
        else {
            app.classList.toggle("recolhida");
            const path = window.location.pathname.replace(/[^/]*$/, "") || "/";
            document.cookie = "menu_recolhido=" + (app.classList.contains("recolhida") ? "1" : "0") + ";path=" + path + ";max-age=31536000;samesite=lax";
        }
        atualizarHamburger();
    }

    function atualizarHamburger() {
        const app = document.getElementById("app");
        const mobile = window.matchMedia("(max-width: 860px)").matches;
        const aberto = mobile ? app.classList.contains("menu-aberto") : !app.classList.contains("recolhida");
        document.querySelectorAll('[data-acao="alternar-menu"]').forEach(function (botao) {
            if (!botao.classList.contains("hamburguer") && !botao.classList.contains("hamburguer-topo")) return;
            botao.setAttribute("aria-expanded", aberto ? "true" : "false");
            botao.setAttribute("aria-label", aberto ? "Recolher menu" : "Expandir menu");
        });
    }

    function abrirPagina(pagina, historico) {
        const pedida = pagina;
        if (PAGINAS_ADMIN.indexOf(pagina) !== -1 && APP.usuario.perfil !== "administrador") pagina = "dashboard";
        if (pagina === "dashboard" && !APP.gerir) pagina = "reclamacoes";
        if (!METADADOS[pagina] || (PAGINAS_GESTAO.indexOf(pagina) !== -1 && !APP.gerir)) pagina = APP.gerir ? "dashboard" : "reclamacoes";
        if (pagina !== pedida) historico = true;
        if (pagina !== "setores") estado.empresaSetor = 0;
        let substituir = false;
        if (pagina === "setores" && !(Number(estado.empresaSetor) > 0)) {
            pagina = "empresas";
            substituir = true;
        }
        estado.abaLista = "ativos";
        estado.pagina = pagina;
        const meta = METADADOS[pagina];
        const tituloVisivel = meta[0];
        document.getElementById("titulo-pagina").textContent = tituloVisivel;
        document.getElementById("subtitulo-pagina").textContent = meta[1];
        document.querySelector(".topo").classList.remove("topo-centro");
        document.title = tituloVisivel ? tituloVisivel + " · Canal de Reclamações" : "Canal de Reclamações";
        document.querySelectorAll("[data-pagina]").forEach(function (el) {
            const marcado = el.dataset.pagina === pagina || (pagina === "setores" && el.dataset.pagina === "empresas");
            el.classList.toggle("ativo", marcado);
        });
        document.getElementById("app").classList.remove("menu-aberto");
        atualizarHamburger();
        if (substituir) {
            history.replaceState({ pagina: pagina }, "", "dashboard.php?p=empresas");
        } else if (historico) {
            let url = "dashboard.php?p=" + encodeURIComponent(pagina);
            if (pagina === "setores") url += "&empresa=" + encodeURIComponent(String(estado.empresaSetor));
            if (window.location.pathname.endsWith("dashboard.php") || window.location.href.indexOf("dashboard.php") !== -1) {
                history.pushState({ pagina: pagina, empresa: estado.empresaSetor || 0 }, "", url);
            }
        }
        renderPagina(pagina);
    }

    function conteudo() {
        return document.getElementById("conteudo");
    }

    function carregando() {
        conteudo().innerHTML = '<div class="carregando"><i class="fa-solid fa-spinner fa-spin"></i> Carregando...</div>';
    }

    function renderPagina(pagina) {
        pararRelogio();
        const extra = document.getElementById("topo-extra");
        if (extra && pagina !== "dashboard") extra.innerHTML = "";
        carregando();
        const mapa = {
            dashboard: renderDashboard,
            reclamacoes: function () { return renderLista(false); },
            acoes: renderAcoes,
            usuarios: function () { return renderUsuarios(true); },
            status: renderStatus,
            tipos: function () { return renderCadastro("tipos"); },
            empresas: function () { return renderCadastro("empresas"); },
            setores: function () { return renderCadastro("setores"); }
        };
        Promise.resolve(mapa[pagina]()).catch(function (err) {
            conteudo().innerHTML = '<div class="aviso aviso-erro">' + esc(err.message) + "</div>";
        });
    }

    async function garantirCatalogo() {
        if (!estado.catalogo) {
            const res = await api("catalogo");
            estado.catalogo = res.dados;
        }
        return estado.catalogo;
    }

    function ligarCanal(raiz) {
        raiz.querySelectorAll(".busca-opcao").forEach(function (caixa) {
            const texto = caixa.querySelector(".busca-texto");
            const hidden = caixa.querySelector('input[type="hidden"]');
            const lista = caixa.querySelector(".busca-lista");
            let opcoes = [];
            try { opcoes = JSON.parse(texto.dataset.opcoes || "[]"); } catch (e) { opcoes = []; }
            function fechar() { lista.hidden = true; }
            function abrir(filtro) {
                const q = semAcento(String(filtro || "").trim());
                const itens = opcoes.filter(function (item) {
                    return q === "" || semAcento(item.nome).indexOf(q) !== -1;
                });
                lista.innerHTML = itens.length
                    ? itens.map(function (item) {
                        return '<li><button type="button" data-id="' + item.id + '">' + esc(item.nome) + "</button></li>";
                    }).join("")
                    : '<li class="busca-vazia">Nenhuma opção encontrada.</li>';
                lista.hidden = false;
            }
            texto.addEventListener("focus", function () { abrir(texto.value); });
            texto.addEventListener("input", function () {
                hidden.value = "";
                abrir(texto.value);
            });
            lista.addEventListener("mousedown", function (e) {
                const botao = e.target.closest("button");
                if (!botao) return;
                e.preventDefault();
                hidden.value = botao.dataset.id;
                texto.value = botao.textContent;
                fechar();
            });
            texto.addEventListener("blur", function () {
                const q = semAcento(texto.value.trim());
                const achou = opcoes.find(function (item) { return semAcento(item.nome) === q; });
                hidden.value = achou ? String(achou.id) : "";
                if (achou) texto.value = achou.nome;
                window.setTimeout(fechar, 150);
            });
        });
        const zona = raiz.querySelector(".soltar");
        if (zona) ligarSoltar(zona);
    }

    function ligarSoltar(zona) {
        if (!zona) return;
        const input = zona.querySelector(".soltar-arquivo");
        const nomes = zona.querySelector(".soltar-nomes");
        const escolher = zona.querySelector(".soltar-escolher");
        let arquivos = [];
        let ajustando = false;
        function sincronizar() {
            ajustando = true;
            const transferencia = new DataTransfer();
            arquivos.forEach(function (arquivo) { transferencia.items.add(arquivo); });
            input.files = transferencia.files;
            ajustando = false;
            nomes.innerHTML = arquivos.map(function (arquivo, indice) {
                const imagem = arquivo.type.indexOf("image/") === 0 || /\.(png|jpe?g|gif|webp)$/i.test(arquivo.name);
                const previa = imagem ? '<img src="' + URL.createObjectURL(arquivo) + '" alt="">' : '<span class="anexo-icone"><i class="fa-regular fa-file"></i></span>';
                return '<li class="anexo-linha">' + previa + '<span>' + esc(arquivo.name) + '</span>' +
                    '<button type="button" class="btn btn-fantasma btn-ico" data-ver="' + indice + '" title="Visualizar" aria-label="Visualizar"><i class="fa-regular fa-eye"></i></button>' +
                    '<button type="button" class="btn btn-perigo btn-ico" data-remover="' + indice + '" title="Excluir" aria-label="Excluir"><i class="fa-solid fa-trash"></i></button></li>';
            }).join("");
        }
        function adicionar(lista) {
            Array.from(lista || []).forEach(function (arquivo) {
                if (arquivos.length >= 5) return;
                arquivos.push(arquivo);
            });
            sincronizar();
        }
        escolher.addEventListener("click", function () { input.click(); });
        input.addEventListener("change", function () {
            if (ajustando) return;
            adicionar(input.files);
        });
        nomes.addEventListener("click", function (e) {
            const ver = e.target.closest("[data-ver]");
            const remover = e.target.closest("[data-remover]");
            if (ver) {
                const arquivo = arquivos[Number(ver.dataset.ver)];
                if (arquivo) abrirPrevia(arquivo);
            }
            if (remover) {
                arquivos.splice(Number(remover.dataset.remover), 1);
                sincronizar();
            }
        });
        ["dragenter", "dragover"].forEach(function (evento) {
            zona.addEventListener(evento, function (e) {
                e.preventDefault();
                zona.classList.add("soltar-ativo");
            });
        });
        zona.addEventListener("dragleave", function (e) {
            if (!zona.contains(e.relatedTarget)) zona.classList.remove("soltar-ativo");
        });
        zona.addEventListener("drop", function (e) {
            e.preventDefault();
            zona.classList.remove("soltar-ativo");
            if (e.dataTransfer && e.dataTransfer.files) adicionar(e.dataTransfer.files);
        });
    }

    function abrirPrevia(arquivo) {
        const url = URL.createObjectURL(arquivo);
        const imagem = arquivo.type.indexOf("image/") === 0 || /\.(png|jpe?g|gif|webp)$/i.test(arquivo.name);
        const pdf = arquivo.type === "application/pdf" || /\.pdf$/i.test(arquivo.name);
        let corpo;
        if (imagem) {
            corpo = '<img class="previa-arquivo" src="' + url + '" alt="' + esc(arquivo.name) + '">';
        } else if (pdf) {
            corpo = '<iframe class="previa-arquivo" src="' + url + '" title="' + esc(arquivo.name) + '"></iframe>';
        } else {
            URL.revokeObjectURL(url);
            return confirmarDownloadArquivo(arquivo);
        }
        mostrarModal(
            '<div class="modal largo" role="dialog" aria-modal="true">' +
            '<div class="modal-cabeca"><h2>' + esc(arquivo.name) + '</h2><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="modal-corpo previa-corpo">' + corpo + "</div></div>",
            true
        );
        const fundo = document.querySelector("#modais .modal-fundo:last-child");
        if (fundo) {
            const observer = new MutationObserver(function () {
                if (!fundo.isConnected) {
                    URL.revokeObjectURL(url);
                    observer.disconnect();
                }
            });
            observer.observe(document.getElementById("modais"), { childList: true });
        }
    }

    function campo(rotulo, controle) {
        return '<label class="campo"><span>' + rotulo + "</span>" + controle + "</label>";
    }

    function campoCheio(rotulo, controle) {
        return '<label class="campo cheio"><span>' + rotulo + "</span>" + controle + "</label>";
    }

    async function renderDashboard() {
        const dados = await api("dashboard_dados", {
            empresa_id: estado.dashboard.empresa_id || 0,
            mes: estado.dashboard.mes || ""
        });
        const d = dados.dados;
        estado.dashboard.empresa_id = Number(d.empresa_id) || 0;
        estado.dashboard.mes = d.mes || "";
        const extra = document.getElementById("topo-extra");
        if (extra) {
            const variasEmpresas = (d.empresas || []).length > 1;
            extra.innerHTML = '<div class="filtros-dashboard">' +
                (variasEmpresas ? '<label class="campo"><span>Empresa</span><select id="filtro-empresa-dashboard">' + opcoesEmpresaDashboard(d.empresas, d.empresa_id) + "</select></label>" : "") +
                '<label class="campo"><span>Mês</span><select id="filtro-mes-dashboard">' + opcoesMesDashboard(d.meses, d.mes) + "</select></label>" +
                "</div>";
        }
        const total = d.total || 0;
        const gestor = !perfilAmplo();
        let html = '<div class="largura painel-dashboard' + (gestor ? " painel-gestor" : "") + '">';
        html += '<div class="metricas">';
        html += metrica("Total", d.total, "");
        html += metrica("Em fila para tratativa", d.abertas, "inicial");
        html += metrica("Em tratativa", d.andamento, "meio");
        html += metrica("Concluída", d.concluidas, "final");
        html += "</div>";
        if (gestor) {
            html += '<div class="grade-gestor">';
            html += '<section class="card painel-setor span-3"><h2>Reclamação por setor</h2>' + graficoSetor(d.por_setor) + "</section>";
            html += '<section class="card span-3"><h2>Reclamações por status</h2>' + barras(d.por_status, total, false) + "</section>";
            html += '<section class="card span-2"><h2>Tempo médio de conclusão por setor</h2>' + listaMediaSetor(d.por_setor) + "</section>";
            html += '<section class="card span-2"><h2>Mais tempo em andamento</h2>' + tabelaTempo(d.mais_andamento, "Nenhuma reclamação em andamento.") + "</section>";
            html += '<section class="card span-2"><h2>Mais tempo para concluir</h2>' + tabelaTempo(d.mais_demoradas, "Nenhuma reclamação concluída ainda.") + "</section>";
            html += "</div>";
        } else {
            html += '<div class="linha-setor">';
            html += '<section class="card painel-setor"><h2>Reclamações por Setor</h2>' + graficoSetor(d.por_setor) + "</section>";
            html += cartaoMedia(d.media_conclusao);
            html += "</div>";
            html += '<div class="grade-paineis">';
            html += '<section class="card"><h2>Tempo médio por setor</h2>' + listaMediaSetor(d.por_setor) + "</section>";
            html += '<section class="card"><h2>Reclamações por Status</h2>' + barras(d.por_status, total, false) + "</section></div>";
            html += '<div class="grade-paineis">';
            html += '<section class="card"><h2>Mais tempo em andamento</h2>' + tabelaTempo(d.mais_andamento, "Nenhuma reclamação em andamento.") + "</section>";
            html += '<section class="card"><h2>Mais tempo para concluir</h2>' + tabelaTempo(d.mais_demoradas, "Nenhuma reclamação concluída ainda.") + "</section>";
            html += "</div>";
        }
        html += '<section class="card"><div class="barra-pagina"><h2>Recentes</h2></div>';
        html += d.recentes && d.recentes.length ? tabelaReclamacoes(d.recentes, false) : vazio("fa-inbox", "Nenhuma reclamação", "Os registros aparecem aqui.");
        html += "</section></div>";
        conteudo().innerHTML = html;
        ligarRosca();
        ligarRelogio();
    }

    function aplicarFiltroDashboard() {
        const empresa = document.getElementById("filtro-empresa-dashboard");
        const mes = document.getElementById("filtro-mes-dashboard");
        if (!mes) return;
        if (empresa) estado.dashboard.empresa_id = Number(empresa.value) || 0;
        estado.dashboard.mes = mes.value || "";
        renderDashboard().catch(function (err) {
            toast(err.message || "Não foi possível filtrar o dashboard.", "erro");
        });
    }

    function opcoesEmpresaDashboard(empresas, selecionado) {
        return (empresas || []).map(function (item) {
            const sel = Number(item.id) === Number(selecionado) ? " selected" : "";
            return '<option value="' + Number(item.id) + '"' + sel + ">" + esc(item.nome) + "</option>";
        }).join("");
    }

    function opcoesMesDashboard(meses, selecionado) {
        const lista = (meses || []).slice();
        if (selecionado && lista.indexOf(selecionado) === -1) lista.unshift(selecionado);
        let html = '<option value="">Todos</option>';
        lista.forEach(function (mes) {
            const sel = mes === selecionado ? " selected" : "";
            html += '<option value="' + esc(mes) + '"' + sel + ">" + esc(rotuloMes(mes)) + "</option>";
        });
        return html;
    }

    function rotuloMes(valor) {
        const nomes = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
        const partes = String(valor || "").split("-");
        const indice = Number(partes[1]) - 1;
        if (partes.length !== 2 || indice < 0 || indice > 11) return String(valor || "");
        const nome = nomes[indice];
        return nome.charAt(0).toUpperCase() + nome.slice(1) + " de " + partes[0];
    }

    function cartaoMedia(segundos, titulo) {
        const valor = segundos === null || segundos === undefined
            ? '<p class="resumo">Nenhuma reclamação concluída ainda.</p>'
            : '<div class="media-valor"><i class="fa-solid fa-stopwatch"></i><strong>' + esc(formatarDuracao(segundos)) + "</strong></div>";
        return '<section class="card media-conclusao"><h2>' + esc(titulo || "Tempo médio de conclusão da empresa") + "</h2>" + valor + "</section>";
    }

    function listaMediaSetor(lista) {
        if (!lista || !lista.length) return '<p class="resumo">Nenhum setor cadastrado.</p>';
        const itens = lista.map(function (item) {
            const quantidade = Number(item.quantidade);
            let tempo = "não possui reclamações";
            if (quantidade > 0) tempo = item.media === null || item.media === undefined ? "Sem conclusão" : formatarDuracao(item.media);
            return "<li><span>" + esc(item.nome) + "</span><strong>" + esc(tempo) + "</strong></li>";
        }).join("");
        return '<ul class="medias-setor">' + itens + "</ul>";
    }

    function graficoSetor(lista) {
        const cores = ["#0f5132", "#1d4e89", "#2a9d8f", "#bc6c25", "#40916c", "#6d597a", "#1b7a45", "#9a3412", "#457b9d", "#588157", "#7f5539", "#3d5a80", "#52b788", "#264653"];
        const todos = lista || [];
        if (!todos.length) return '<p class="resumo">Sem setores cadastrados.</p>';
        const itens = todos.filter(function (item) { return Number(item.quantidade) > 0; });
        const total = itens.reduce(function (soma, item) { return soma + Number(item.quantidade); }, 0);
        const ponto = function (raio, ang) {
            return [(100 + raio * Math.cos(ang)).toFixed(2), (100 + raio * Math.sin(ang)).toFixed(2)];
        };
        let angulo = -Math.PI / 2;
        const fatias = itens.map(function (item, indice) {
            const quantidade = Number(item.quantidade);
            const fatia = (quantidade / total) * Math.PI * 2;
            const cor = cores[indice % cores.length];
            const nome = esc(item.nome);
            const attrs = ' class="rosca-fatia" data-setor="' + nome + '" data-qtd="' + quantidade + '"';
            let desenho;
            if (itens.length === 1) {
                desenho = '<circle' + attrs + ' cx="100" cy="100" r="74" fill="none" stroke="' + cor + '" stroke-width="32"><title>' + nome + " · " + quantidade + "</title></circle>";
            } else {
                const folga = Math.min(0.02, fatia * 0.12);
                const inicio = angulo + folga;
                const fim = angulo + fatia - folga;
                const grande = fim - inicio > Math.PI ? 1 : 0;
                const externoInicio = ponto(90, inicio);
                const externoFim = ponto(90, fim);
                const internoFim = ponto(58, fim);
                const internoInicio = ponto(58, inicio);
                desenho = '<path' + attrs + ' fill="' + cor + '" d="M ' + externoInicio[0] + " " + externoInicio[1] + " A 90 90 0 " + grande + " 1 " + externoFim[0] + " " + externoFim[1] + " L " + internoFim[0] + " " + internoFim[1] + " A 58 58 0 " + grande + " 0 " + internoInicio[0] + " " + internoInicio[1] + ' Z"><title>' + nome + " · " + quantidade + "</title></path>";
            }
            angulo += fatia;
            return desenho;
        }).join("");
        const corDe = {};
        itens.forEach(function (item, indice) { corDe[item.nome] = cores[indice % cores.length]; });
        const legenda = todos.map(function (item) {
            const quantidade = Number(item.quantidade);
            const pct = total > 0 ? Math.round((quantidade / total) * 100) : 0;
            const cor = corDe[item.nome] || "#d5e2da";
            return '<li data-setor="' + esc(item.nome) + '" data-qtd="' + quantidade + '" title="' + esc(item.nome) + '"><i style="background:' + cor + '"></i><span>' + esc(item.nome) + "</span><strong>" + quantidade + '</strong><em>' + pct + "%</em></li>";
        }).join("");
        const unica = todos.length === 1 ? " rosca-unica" : "";
        return '<div class="rosca' + unica + '"><div class="rosca-grafico"><div class="rosca-anel"><svg viewBox="0 0 200 200" role="img" aria-label="Reclamações por setor"><circle class="rosca-trilho" cx="100" cy="100" r="74"></circle>' + fatias + '</svg><div class="rosca-centro"><strong>' + total + '</strong><span>Total</span></div></div><p class="rosca-dica"><i></i><span class="rosca-dica-nome">Passe o mouse no gráfico</span><strong class="rosca-dica-qtd" hidden></strong></p></div><ul class="rosca-legenda">' + legenda + "</ul></div>";
    }

    function ligarRosca() {
        const grafico = document.querySelector(".rosca");
        if (!grafico) return;
        const dica = grafico.querySelector(".rosca-dica");
        const marcar = function (origem) {
            const nome = origem.getAttribute("data-setor") || "";
            const qtd = Number(origem.getAttribute("data-qtd") || 0);
            const cor = origem.getAttribute("fill") || origem.getAttribute("stroke") || "";
            grafico.classList.add("foco");
            dica.querySelector("i").style.background = cor;
            dica.querySelector(".rosca-dica-nome").textContent = nome;
            const quantidade = dica.querySelector(".rosca-dica-qtd");
            quantidade.hidden = false;
            quantidade.textContent = qtd === 1 ? "1 reclamação" : qtd + " reclamações";
            grafico.querySelectorAll(".rosca-fatia, .rosca-legenda li").forEach(function (item) {
                item.classList.toggle("ativa", item.getAttribute("data-setor") === nome);
            });
        };
        grafico.querySelectorAll(".rosca-fatia, .rosca-legenda li").forEach(function (item) {
            item.addEventListener("pointerenter", function () { marcar(item); });
        });
        grafico.addEventListener("pointerleave", function () {
            grafico.classList.remove("foco");
            dica.querySelector("i").style.background = "";
            dica.querySelector(".rosca-dica-nome").textContent = "Passe o mouse no gráfico";
            dica.querySelector(".rosca-dica-qtd").hidden = true;
            grafico.querySelectorAll(".ativa").forEach(function (item) { item.classList.remove("ativa"); });
        });
        ajustarRoda = function () {
            const legenda = grafico.querySelector(".rosca-legenda");
            const anel = grafico.querySelector(".rosca-anel");
            if (!legenda || !anel) return;
            const altura = Math.round(legenda.getBoundingClientRect().height);
            if (altura < 120) return;
            anel.style.width = altura + "px";
            anel.style.height = altura + "px";
        };
        requestAnimationFrame(ajustarRoda);
    }

    function tabelaTempo(lista, vazioTexto) {
        if (!lista || !lista.length) return '<p class="resumo">' + esc(vazioTexto) + "</p>";
        const linhas = lista.map(function (item) {
            return '<tr data-acao="abrir-reclamacao" data-id="' + Number(item.id) + '">' +
                '<td><strong class="protocolo">' + esc(rotuloProtocolo(item.protocolo)) + "</strong></td>" +
                "<td>" + esc(item.nome_cliente || "—") + "</td>" +
                "<td>" + esc(item.setor_nome || "—") + "</td>" +
                "<td>" + htmlTempo(item) + "</td></tr>";
        }).join("");
        return '<div class="tabela-envoltorio"><table class="tabela tabela-ranking"><thead><tr>' +
            "<th>Protocolo</th><th>Cliente</th><th>Setor</th><th>Tempo</th>" +
            "</tr></thead><tbody>" + linhas + "</tbody></table></div>";
    }

    function metrica(rotulo, valor, grupo) {
        const extra = grupo ? ' data-acao="ir-reclamacoes" data-grupo="' + grupo + '"' : ' data-acao="ir-reclamacoes"';
        return '<button type="button" class="metrica"' + extra + "><strong>" + Number(valor || 0) + "</strong><span>" + esc(rotulo) + "</span></button>";
    }

    function barras(lista, total, comTempo) {
        if (!lista || !lista.length) return '<p class="resumo">Sem dados ainda.</p>';
        const pico = lista.reduce(function (max, item) {
            return Math.max(max, Number(item.quantidade) || 0);
        }, 0) || total;
        return lista.map(function (item) {
            const pct = pico > 0 ? Math.round((Number(item.quantidade) / pico) * 100) : 0;
            const tempo = comTempo
                ? '<span class="barra-tempo">' + (item.media === null || item.media === undefined
                    ? '<span class="muted">—</span>'
                    : htmlTempo({ tempo_segundos: item.media, tempo_aberto: false, tempo_titulo: "Tempo médio até a conclusão" })) + "</span>"
                : "";
            return '<div class="barra-linha' + (comTempo ? " barra-setor" : "") + '"><span>' + esc(item.nome) + "</span>" + tempo +
                '<div class="trilho"><span style="width:' + pct + '%"></span></div><strong>' + Number(item.quantidade) + "</strong></div>";
        }).join("");
    }

    function filtrosAtuais() {
        return estado.pagina === "relatorios" ? estado.relatorio : estado.filtros;
    }

    async function renderAcoes() {
        const cat = await garantirCatalogo();
        const filtros = estado.filtrosAcoes;
        const amplo = perfilAmplo();
        const setoresLista = amplo ? setoresPorEmpresa(cat.setores, filtros.empresa_id) : cat.setores;
        if (amplo && filtros.setor_id && !setoresLista.some(function (item) { return String(item.id) === String(filtros.setor_id); })) {
            filtros.setor_id = "";
        }
        const res = await api("acoes_listar", filtros);
        const dados = res.dados;
        if (!document.getElementById("form-filtros-acoes")) {
            conteudo().innerHTML = htmlPaginaAcoes(dados, cat, filtros, amplo, setoresLista);
            return;
        }
        atualizarPainelAcoes(dados, filtros);
    }

    function htmlPaginaAcoes(dados, cat, filtros, amplo, setoresLista) {
        let html = '<div class="largura">';
        html += htmlCartoesAcoes(dados.contagens, filtros.situacao);
        html += '<form id="form-filtros-acoes" class="card filtros filtros-simples filtros-acoes' + (amplo ? " com-empresa" : "") + '">';
        html += '<label class="campo busca"><span>Pesquisar</span><input id="busca-acoes" name="busca" value="' + esc(filtros.busca) + '" placeholder="Ação, responsável, protocolo ou cliente"></label>';
        if (amplo) {
            html += '<label class="campo"><span>Empresa</span><select name="empresa_id">' + htmlOpcoes(cat.empresas, filtros.empresa_id, "Todas", false) + "</select></label>";
        }
        html += '<label class="campo"><span>Setor</span><select name="setor_id">' + htmlOpcoes(opcoesSetor(setoresLista, amplo && !filtros.empresa_id), filtros.setor_id, "Todos", false) + "</select></label>";
        html += '<label class="campo"><span>Prazo de</span><input type="date" name="data_ini" value="' + esc(filtros.data_ini) + '"></label>';
        html += '<label class="campo"><span>Prazo até</span><input type="date" name="data_fim" value="' + esc(filtros.data_fim) + '"></label>';
        html += '<div class="campo campo-limpar"><button type="button" class="btn btn-fantasma btn-ico" data-acao="limpar-filtros" title="Limpar filtros" aria-label="Limpar filtros"><i class="fa-solid fa-filter-circle-xmark"></i></button></div>';
        html += "</form>";
        html += '<section class="card lista-acoes">';
        html += '<div id="lista-acoes">' + htmlListaAcoes(dados.itens, amplo) + "</div>";
        html += '<div id="paginacao-acoes">' + htmlPaginacao(dados) + "</div>";
        html += "</section></div>";
        return html;
    }

    function htmlCartoesAcoes(contagens, situacao) {
        const itens = [
            ["", "Todas", contagens.todas, "", "fa-layer-group"],
            ["atrasada", "Atrasadas", contagens.atrasada, "acao-atrasada", "fa-clock"],
            ["hoje", "Vencem hoje", contagens.hoje, "acao-hoje", "fa-calendar-day"],
            ["prazo", "No prazo", contagens.prazo, "acao-prazo", "fa-circle-check"]
        ];
        return '<div class="metricas cartoes-acao">' + itens.map(function (item) {
            const ativa = (situacao || "") === item[0] ? " ativa" : "";
            return '<button type="button" class="metrica acao-status ' + item[3] + ativa + '" data-acao="filtrar-acao" data-situacao="' + item[0] + '" aria-pressed="' + (ativa ? "true" : "false") + '">' +
                '<i class="fa-solid ' + item[4] + '" aria-hidden="true"></i>' +
                '<span class="metrica-texto"><strong>' + Number(item[2] || 0) + "</strong><span>" + esc(item[1]) + "</span></span></button>";
        }).join("") + "</div>";
    }

    function atualizarPainelAcoes(dados, filtros) {
        const caixa = document.querySelector(".cartoes-acao");
        if (caixa) caixa.outerHTML = htmlCartoesAcoes(dados.contagens, filtros.situacao);
        const lista = document.getElementById("lista-acoes");
        if (lista) lista.innerHTML = htmlListaAcoes(dados.itens, perfilAmplo());
        const pag = document.getElementById("paginacao-acoes");
        if (pag) pag.innerHTML = htmlPaginacao(dados);
    }

    function htmlListaAcoes(itens, comEmpresa) {
        if (!itens.length) {
            return vazio("fa-list-check", "Nenhuma ação encontrada", "Ajuste os filtros ou registre um plano de ação na reclamação.");
        }
        const linhas = itens.map(function (item) { return linhaAcao(item, comEmpresa); }).join("");
        const empresa = comEmpresa ? '<th class="col-empresa">Empresa</th>' : "";
        return '<div class="tabela-envoltorio"><table class="tabela tabela-clicavel tabela-acoes' + (comEmpresa ? " com-empresa" : "") + '"><thead><tr>' +
            '<th class="col-protocolo">Reclamação</th>' + empresa +
            '<th class="col-setor">Setor</th><th class="col-situacao">Situação</th><th class="col-acao">Ação</th><th class="col-responsavel">Responsável</th><th class="col-fluxo">Status</th><th class="col-prazo">Realizar até</th>' +
            "</tr></thead><tbody>" + linhas + "</tbody></table></div>";
    }

    function linhaAcao(item, comEmpresa) {
        const relativo = textoPrazoAcao(item);
        const empresa = comEmpresa
            ? '<td class="col-empresa" data-label="Empresa" title="' + esc(item.empresa_nome || "") + '">' + esc(item.empresa_nome || "—") + "</td>"
            : "";
        return '<tr data-acao="abrir-plano" data-id="' + Number(item.id) + '">' +
            '<td class="col-protocolo" data-label="Reclamação"><button type="button" class="abrir-reclamacao" data-acao="abrir-reclamacao" data-id="' + Number(item.reclamacao_id) + '" title="Abrir reclamação">' +
            '<strong class="protocolo">' + esc(rotuloProtocolo(item.protocolo)) + "</strong>" +
            '<span class="acao-cliente">' + esc(item.nome_cliente || "—") + "</span></button></td>" +
            empresa +
            '<td class="col-setor" data-label="Setor" title="' + esc(item.setor_nome) + '">' + esc(item.setor_nome) + "</td>" +
            '<td class="col-situacao" data-label="Situação">' + seloSituacaoAcao(item.situacao) + "</td>" +
            '<td class="col-acao" data-label="Ação" title="' + esc(item.acao) + '"><span class="acao-texto">' + esc(item.acao) + "</span></td>" +
            '<td class="col-responsavel" data-label="Responsável" title="' + esc(item.responsavel) + '">' + esc(item.responsavel) + "</td>" +
            '<td class="col-fluxo" data-label="Status">' + seloStatusAcao(item.status) + "</td>" +
            '<td class="col-prazo" data-label="Realizar até"><strong>' + esc(formatarData(item.prazo)) + "</strong>" +
            (relativo ? '<span class="prazo-relativo">' + esc(relativo) + "</span>" : "") + "</td></tr>";
    }

    function rotuloStatusAcao(status) {
        return { aguardando: "Aguardando", andamento: "Em Andamento", concluido: "Concluído", cancelado: "Cancelado" }[status] || "Aguardando";
    }

    function seloStatusAcao(status) {
        const cores = { aguardando: "#fff3bf", andamento: "#dbe7ff", concluido: "#d8f3dc", cancelado: "#e2e8e4" };
        const atual = status || "aguardando";
        return seloCor(rotuloStatusAcao(atual), cores[atual] || cores.aguardando);
    }

    function selectStatusAcao(item) {
        const atual = item.status || "aguardando";
        const concluida = atual === "concluido";
        const opcoes = [
            ["aguardando", "Aguardando"],
            ["andamento", "Em Andamento"],
            ["concluido", "Concluído"]
        ];
        if (atual === "cancelado") opcoes.push(["cancelado", "Cancelado"]);
        const lista = opcoes.map(function (opcao) {
            const ativa = atual === opcao[0];
            return '<button type="button" class="status-opcao status-' + opcao[0] + (ativa ? " ativa" : "") + '" role="option" data-valor="' + opcao[0] + '" aria-selected="' + (ativa ? "true" : "false") + '">' +
                '<span class="status-ponto" aria-hidden="true"></span><span>' + esc(opcao[1]) + '</span><i class="fa-solid fa-check" aria-hidden="true"></i></button>';
        }).join("");
        return '<div class="menu-status' + (concluida ? " travado" : "") + '" data-id="' + Number(item.id) + '" data-anterior="' + esc(atual) + '" data-valor="' + esc(atual) + '">' +
            '<button type="button" class="select-status-acao status-' + esc(atual) + '" aria-haspopup="listbox" aria-expanded="false" aria-label="Status da ação"' +
            (concluida ? ' disabled title="Ação concluída. Não é possível reabrir."' : "") + ">" +
            '<span class="status-rotulo">' + esc(rotuloStatusAcao(atual)) + '</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>' +
            '<div class="status-lista" role="listbox" hidden>' + lista + "</div></div>";
    }

    function fecharMenusStatus() {
        const abertos = document.querySelectorAll(".menu-status.aberto");
        abertos.forEach(function (menu) {
            menu.classList.remove("aberto");
            const lista = menu.querySelector(".status-lista");
            const gatilho = menu.querySelector(".select-status-acao");
            if (lista) lista.hidden = true;
            if (gatilho) gatilho.setAttribute("aria-expanded", "false");
        });
        return abertos.length > 0;
    }

    function pintarStatusMenu(menu, status) {
        const gatilho = menu.querySelector(".select-status-acao");
        const rotulo = menu.querySelector(".status-rotulo");
        menu.dataset.valor = status;
        if (gatilho) gatilho.className = "select-status-acao status-" + status;
        if (rotulo) rotulo.textContent = rotuloStatusAcao(status);
        menu.querySelectorAll(".status-opcao").forEach(function (opcao) {
            const ativa = opcao.dataset.valor === status;
            opcao.classList.toggle("ativa", ativa);
            opcao.setAttribute("aria-selected", ativa ? "true" : "false");
        });
    }

    function alternarMenuStatus(gatilho) {
        const menu = gatilho.closest(".menu-status");
        if (!menu || gatilho.disabled) return;
        const aberto = menu.classList.contains("aberto");
        fecharMenusStatus();
        if (aberto) return;
        menu.classList.add("aberto");
        const lista = menu.querySelector(".status-lista");
        if (lista) lista.hidden = false;
        gatilho.setAttribute("aria-expanded", "true");
    }

    async function escolherStatusAcao(opcao) {
        const menu = opcao.closest(".menu-status");
        if (!menu) return;
        const status = opcao.dataset.valor || "";
        const anterior = menu.dataset.anterior || "aguardando";
        fecharMenusStatus();
        if (!status || status === anterior) return;
        pintarStatusMenu(menu, status);
        await salvarStatusAcao(menu, status);
    }

    async function salvarStatusAcao(menu, status) {
        const id = Number(menu.dataset.id);
        const anterior = menu.dataset.anterior || "aguardando";
        const gatilho = menu.querySelector(".select-status-acao");
        if (!id || status === anterior) return;
        let conclusao = "";
        if (status === "concluido") {
            conclusao = await pedirConclusaoAcao();
            if (!conclusao) {
                pintarStatusMenu(menu, anterior);
                return;
            }
            const ok = await confirmarEscrito("Você tem certeza que quer concluir essa ação? Depois de concluída, não será possível reabri-la nem adicionar comentários.", "CONCLUIR");
            if (!ok) {
                pintarStatusMenu(menu, anterior);
                return;
            }
        }
        if (gatilho) gatilho.disabled = true;
        try {
            const res = await api("acao_status", { id: id, status: status, conclusao: conclusao });
            estado.acao = res.dados;
            toast(res.mensagem || "Status da ação atualizado.", "ok");
            pintarAcao();
            if (estado.pagina === "acoes") await renderAcoes();
        } catch (err) {
            if (gatilho) gatilho.disabled = false;
            pintarStatusMenu(menu, anterior);
            toast(err.message || "Não foi possível atualizar o status.", "erro");
        }
    }

    function seloSituacaoAcao(situacao) {
        if (situacao === "atrasada") return seloCor("Atrasado", "#fde2e4");
        if (situacao === "sem_prazo") return seloCor("Sem prazo", "#e2e8e4");
        return seloCor("No prazo", "#d8f3dc");
    }

    function textoPrazoAcao(item) {
        const dias = Number(item.dias);
        if (item.situacao === "hoje") return "hoje";
        if (!Number.isFinite(dias)) return "";
        const n = Math.abs(dias);
        const unidade = n === 1 ? "dia" : "dias";
        if (item.situacao === "atrasada") return "há " + n + " " + unidade;
        if (item.situacao === "prazo") return "em " + n + " " + unidade;
        return "";
    }

    function sincronizarFiltrosAcoes(pagina) {
        const form = document.getElementById("form-filtros-acoes");
        const dados = form ? Object.fromEntries(new FormData(form).entries()) : {};
        estado.filtrosAcoes = Object.assign(filtrosAcoesPadrao(), dados, {
            situacao: estado.filtrosAcoes.situacao || "",
            pagina: pagina
        });
    }

    function aplicarFiltrosAcoes() {
        sincronizarFiltrosAcoes(1);
        renderAcoes().catch(function (err) { toast(err.message, "erro"); });
    }

    async function renderLista(relatorio) {
        const cat = await garantirCatalogo();
        const filtros = relatorio ? estado.relatorio : estado.filtros;
        const admin = perfilAmplo();
        const setoresLista = admin ? setoresPorEmpresa(cat.setores, filtros.empresa_id) : cat.setores;
        if (admin && filtros.setor_id && !setoresLista.some(function (item) { return String(item.id) === String(filtros.setor_id); })) {
            filtros.setor_id = "";
        }
        const dados = await api("reclamacoes_listar", filtros);
        estado.lista = dados.dados;
        const banner = [];
        if (filtros.status_grupo === "inicial") banner.push("Mostrando reclamações em fila para tratativa");
        if (filtros.status_grupo === "meio") banner.push("Mostrando reclamações em tratativa");
        if (filtros.status_grupo === "final") banner.push("Mostrando reclamações concluídas");
        if (filtros.periodo === "mes") banner.push("Período: este mês");
        let html = '<div class="largura">';
        if (banner.length) {
            html += '<div class="aviso aviso-ok">' + esc(banner.join(" · ")) + ' <button type="button" class="btn btn-fantasma btn-ico" data-acao="limpar-filtros">Limpar</button></div>';
        }
        html += '<form id="form-filtros" class="card filtros' + (relatorio ? "" : " filtros-simples") + (admin && !relatorio ? " filtros-com-empresa" : "") + '">';
        html += '<input type="hidden" name="status_grupo" value="' + esc(filtros.status_grupo) + '">';
        html += '<input type="hidden" name="periodo" value="' + esc(filtros.periodo) + '">';
        html += '<label class="campo busca"><span>Pesquisar</span><input id="busca-reclamacoes" name="busca" value="' + esc(filtros.busca) + '" placeholder="Protocolo, empresa, setor ou descrição"></label>';
        if (admin && !relatorio) {
            html += '<label class="campo"><span>Empresa</span><select name="empresa_id">' + htmlOpcoes(cat.empresas, filtros.empresa_id, "Todas", false) + "</select></label>";
        }
        html += '<label class="campo"><span>Status</span><select name="status_id">' + htmlOpcoes(cat.status, filtros.status_id, "Todos", false) + "</select></label>";
        html += '<label class="campo"><span>Setor</span><select name="setor_id">' + htmlOpcoes(opcoesSetor(setoresLista, admin && !filtros.empresa_id), filtros.setor_id, "Todos", false) + "</select></label>";
        html += '<label class="campo"><span>Tipo</span><select name="tipo_id">' + htmlOpcoes(cat.tipos, filtros.tipo_id, "Todos", false) + "</select></label>";
        if (relatorio) {
            html += '<label class="campo"><span>Situação</span><select name="situacao_id">' + htmlOpcoes(cat.situacoes, filtros.situacao_id, "Todas", false) + "</select></label>";
            html += '<label class="campo"><span>Origem</span><select name="origem"><option value="">Todas</option><option value="publica"' + (filtros.origem === "publica" ? " selected" : "") + '>Pública</option><option value="interna"' + (filtros.origem === "interna" ? " selected" : "") + ">Interna</option></select></label>";
            html += '<label class="campo"><span>De</span><input type="date" name="data_ini" value="' + esc(filtros.data_ini) + '"></label>';
            html += '<label class="campo"><span>Até</span><input type="date" name="data_fim" value="' + esc(filtros.data_fim) + '"></label>';
        }
        if (relatorio) {
            html += '<div class="grupo-botoes"><button type="button" class="btn btn-primario" data-acao="exportar-csv"><i class="fa-solid fa-file-export"></i> Exportar CSV</button><button type="button" class="btn btn-fantasma" data-acao="limpar-filtros">Limpar</button></div>';
        }
        html += "</form>";
        html += '<section class="card" style="margin-top:14px">';
        if (relatorio) html += '<div class="barra-pagina"><p class="resumo">' + rotuloTotal(dados.dados.total) + "</p></div>";
        html += dados.dados.itens.length ? tabelaReclamacoes(dados.dados.itens, true) : vazio("fa-inbox", "Nenhuma reclamação encontrada", "Ajuste os filtros ou aguarde novos registros.");
        html += '<div id="paginacao">' + htmlPaginacao(dados.dados) + "</div>";
        html += "</section></div>";
        conteudo().innerHTML = html;
        ligarRelogio();
    }

    function aplicarFiltros() {
        const form = document.getElementById("form-filtros");
        if (!form) return;
        const dados = Object.fromEntries(new FormData(form).entries());
        const destino = estado.pagina === "relatorios" ? "relatorio" : "filtros";
        estado[destino] = Object.assign(filtrosPadrao(), dados, { pagina: 1 });
        carregarLista().catch(function (err) { toast(err.message, "erro"); });
    }

    async function carregarLista() {
        const relatorio = estado.pagina === "relatorios";
        const filtros = relatorio ? estado.relatorio : estado.filtros;
        const dados = await api("reclamacoes_listar", filtros);
        const corpo = document.getElementById("corpo-reclamacoes");
        const resumo = document.querySelector("#conteudo .barra-pagina .resumo");
        if (!corpo) {
            renderLista(relatorio);
            return;
        }
        const comSituacao = estado.pagina === "reclamacoes" || estado.pagina === "relatorios";
        const colunas = (comSituacao ? 8 : 7) + (mostrarColunaEmpresa() ? 1 : 0);
        corpo.innerHTML = dados.dados.itens.map(function (item) {
            return linhaReclamacao(item, comSituacao);
        }).join("");
        if (!dados.dados.itens.length) {
            corpo.innerHTML = '<tr><td colspan="' + colunas + '">' + vazio("fa-inbox", "Nenhuma reclamação encontrada", "Ajuste os filtros ou aguarde novos registros.") + "</td></tr>";
        }
        if (resumo) resumo.textContent = rotuloTotal(dados.dados.total);
        const pag = document.getElementById("paginacao");
        if (pag) pag.innerHTML = htmlPaginacao(dados.dados);
        ligarRelogio();
    }

    function mostrarColunaEmpresa() {
        return estado.pagina === "reclamacoes" && perfilAmplo();
    }

    function tabelaReclamacoes(itens, comSituacao) {
        const empresa = mostrarColunaEmpresa() ? '<th class="col-empresa">Empresa</th>' : "";
        return '<div class="tabela-envoltorio"><table class="tabela tabela-clicavel"><thead><tr>' +
            '<th class="col-protocolo">Protocolo</th><th class="col-corta">Cliente</th>' + empresa + '<th class="col-setor">Setor</th><th class="col-corta">Tipo</th><th class="col-status">Status</th>' +
            (comSituacao ? '<th class="col-situacao">Situação</th>' : "") +
            '<th class="col-data">Data</th><th class="col-tempo">Tempo</th>' +
            '</tr></thead><tbody id="corpo-reclamacoes">' + itens.map(function (item) { return linhaReclamacao(item, comSituacao); }).join("") + "</tbody></table></div>";
    }

    function linhaReclamacao(item, comSituacao) {
        const situacao = item.situacao_nome
            ? seloCor(item.situacao_nome, item.situacao_cor || "#dbe7ff")
            : '<span class="muted">—</span>';
        const empresa = mostrarColunaEmpresa()
            ? '<td class="col-empresa" data-label="Empresa" title="' + esc(item.empresa_nome || "") + '">' + esc(item.empresa_nome || "—") + "</td>"
            : "";
        const setor = mostrarColunaEmpresa() ? (item.setor_nome || "—") : textoSetorLista(item);
        return '<tr data-acao="abrir-reclamacao" data-id="' + Number(item.id) + '">' +
            '<td class="col-protocolo" data-label="Protocolo"><strong class="protocolo">' + esc(rotuloProtocolo(item.protocolo)) + "</strong></td>" +
            '<td class="col-corta" data-label="Cliente" title="' + esc(item.nome_cliente) + '">' + esc(item.nome_cliente) + "</td>" +
            empresa +
            '<td class="col-setor" data-label="Setor" title="' + esc(setor) + '">' + esc(setor) + "</td>" +
            '<td class="col-corta" data-label="Tipo" title="' + esc(item.tipo_nome) + '">' + esc(item.tipo_nome) + "</td>" +
            '<td class="col-status" data-label="Status">' + seloStatus(item.status_nome, item.status_fixo, item.status_cor) + "</td>" +
            (comSituacao === true ? '<td class="col-situacao" data-label="Situação">' + situacao + "</td>" : "") +
            '<td class="col-data" data-label="Data">' + esc(formatarData(item.data_reclamacao || item.criado_em)) + "</td>" +
            '<td class="col-tempo" data-label="Tempo">' + htmlTempo(item) + "</td></tr>";
    }

    function htmlPaginacao(dados) {
        if (!dados || dados.paginas <= 1) return "";
        const ant = dados.pagina <= 1 ? " disabled" : "";
        const prox = dados.pagina >= dados.paginas ? " disabled" : "";
        return '<div class="paginacao"><button type="button" class="btn btn-fantasma" data-acao="pagina" data-pagina="' + (dados.pagina - 1) + '"' + ant + '>Anterior</button><span>' + dados.pagina + " de " + dados.paginas + '</span><button type="button" class="btn btn-fantasma" data-acao="pagina" data-pagina="' + (dados.pagina + 1) + '"' + prox + ">Próxima</button></div>";
    }

    function vazio(icone, titulo, texto) {
        return '<div class="vazio"><i class="fa-solid ' + icone + '"></i><strong>' + esc(titulo) + "</strong><p>" + esc(texto) + "</p></div>";
    }

    async function abrirDetalhe(id, aba) {
        if (!id) return;
        estado.abaPrincipal = aba === "tratativas" ? "tratativas" : "reclamacao";
        estado.editandoTratativa = "";
        const cat = await garantirCatalogo();
        const res = await api("reclamacao_detalhe", { id: id });
        estado.detalhe = res.dados;
        if (estado.detalhe.status_fixo === "inicial") estado.abaPrincipal = "reclamacao";
        estado.catalogo = cat;
        pintarDetalhe();
    }

    function emFilaTratativa(d) {
        return !!d && d.status_fixo === "inicial";
    }

    function emFilaTratativa(d) {
        return !!d && d.status_fixo === "inicial";
    }

    function pintarAcao() {
        fecharTodosModais();
        const d = estado.acao;
        if (!d) return;
        const concluida = d.status === "concluido";
        const html =
            '<div class="modal modal-denuncia modal-acao" role="dialog" aria-modal="true" aria-label="Ação da reclamação ' + esc(rotuloProtocolo(d.protocolo)) + '">' +
            '<section class="painel-historico">' +
            '<header class="cabeca-acao"><div class="cabeca-acao-titulo"><h2>Histórico da ação</h2><p>' + esc(d.acao || "—") + "</p></div>" +
            '<div class="status-denuncia">' + selectStatusAcao(d) + "</div>" +
            '<button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></header>' +
            '<div class="abas">' +
            '<button type="button" class="aba ativa" data-acao="aba" data-aba="comentarios"><i class="fa-solid fa-comment"></i> Comentários</button>' +
            '<button type="button" class="aba" data-acao="aba" data-aba="movimentacoes"><i class="fa-solid fa-clock-rotate-left"></i> Movimentações</button>' +
            "</div>" +
            '<div id="painel-comentarios" class="historico-corpo chat-painel"><div class="lista-notas">' + listaComentarios(d.comentarios, "acao", concluida) + "</div></div>" +
            '<div id="painel-movimentacoes" class="historico-corpo" hidden><div class="lista-notas">' + notas(d.movimentacoes, "Sem movimentações.", false) + "</div></div>" +
            (concluida
                ? '<div class="comentario-bloqueado"><i class="fa-solid fa-lock" aria-hidden="true"></i><p>Esta ação foi concluída e não pode ser reaberta. Os comentários estão bloqueados.</p></div>'
                : '<form id="form-comentario-acao" class="comentario-rodape" action="api.php" method="post" enctype="multipart/form-data">' +
            '<input type="hidden" name="acao" value="acao_comentario_criar"><input type="hidden" name="id" value="' + d.id + '">' +
            '<input type="hidden" name="comentario_id" value="">' +
            '<div class="chat-editando" id="chat-editando" hidden><i class="fa-solid fa-pen" aria-hidden="true"></i><span>Editando mensagem</span>' +
            '<button type="button" data-acao="cancelar-edicao-comentario" aria-label="Cancelar edição"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="editor-ferramentas">' +
            '<button type="button" data-fmt="bold" title="Negrito"><b>B</b></button>' +
            '<button type="button" data-fmt="italic" title="Itálico"><i>I</i></button>' +
            '<button type="button" data-fmt="underline" title="Sublinhado"><u>U</u></button>' +
            '<button type="button" class="editor-anexo" title="Anexo"><i class="fa-solid fa-paperclip"></i></button>' +
            '<input class="editor-arquivo" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">' +
            "</div>" +
            '<div class="comentario-linha">' +
            '<div class="editor-area" contenteditable="true" data-placeholder="Escreva um comentário..."></div>' +
            '<button class="comentario-enviar" type="submit" aria-label="Enviar comentário" title="Enviar"><i class="fa-solid fa-paper-plane"></i></button>' +
            "</div>" +
            '<ul class="editor-lista"></ul></form>') + "</section></div>";
        const fundo = mostrarModal(html, true);
        ligarEditor(fundo.querySelector("#form-comentario-acao"));
        ligarAvatares(fundo);
        const chat = fundo.querySelector("#painel-comentarios");
        if (chat) {
            chat.scrollTop = chat.scrollHeight;
            chat.addEventListener("scroll", fecharMenusChat);
        }
    }

    function pintarDetalhe() {
        estado.acao = null;
        fecharTodosModais();
        const d = estado.detalhe;
        const fila = emFilaTratativa(d);
        if (fila) estado.abaPrincipal = "reclamacao";
        const anexosAbertura = (d.anexos || []).filter(function (a) { return a.contexto !== "conclusao"; });
        const anexos = anexosAbertura.length
            ? '<ul class="anexos">' + anexosAbertura.map(function (a) {
                return "<li>" + htmlAnexo(a, "", true) + "</li>";
            }).join("") + "</ul>"
            : '<p class="resumo">Nenhum anexo enviado.</p>';
        const aba = estado.abaPrincipal === "tratativas" ? "tratativas" : "reclamacao";
        const html =
            '<div class="modal modal-denuncia" role="dialog" aria-modal="true" aria-label="Reclamação ' + esc(rotuloProtocolo(d.protocolo)) + '">' +
            '<section class="painel-denuncia">' +
            '<header class="cabeca-denuncia">' +
            '<div class="abas-principal" role="tablist">' +
            '<button type="button" class="aba-principal' + (aba === "reclamacao" ? " ativa" : "") + '" data-acao="aba-principal" data-aba="reclamacao" role="tab" aria-selected="' + (aba === "reclamacao" ? "true" : "false") + '">Reclamação</button>' +
            (fila ? "" : '<button type="button" class="aba-principal' + (aba === "tratativas" ? " ativa" : "") + '" data-acao="aba-principal" data-aba="tratativas" role="tab" aria-selected="' + (aba === "tratativas" ? "true" : "false") + '">Tratativas</button>') +
            "</div>" +
            (fila ? '<button type="button" class="btn btn-primario btn-iniciar-tratativa" data-acao="iniciar-tratativa">Iniciar tratativa desta reclamação</button>' : "") +
            '<div class="meta-denuncia"><span class="numero-protocolo">' + esc(rotuloProtocolo(d.protocolo)) + "</span>" + htmlTempo(d) +
            '<button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
            "</header>" +
            '<div class="corpo-denuncia">' +
            '<div id="painel-reclamacao"' + (aba === "reclamacao" ? "" : " hidden") + '><div class="info-grade">' +
            blocoDenuncia("Quem abriu a reclamação", esc(d.responsavel_atendimento || "—")) +
            blocoDenuncia("Quem fez a reclamação (Nome do Cliente)", esc(d.responsavel_denuncia || "—")) +
            blocoDenuncia("Empresa cliente (Reclamante)", esc(d.nome_cliente)) +
            blocoDenuncia("Unidade/Posto (Reclamante)", esc(d.unidade || "—")) +
            blocoDenuncia("Data da reclamação", esc(formatarData(d.data_reclamacao || d.criado_em))) +
            (perfilAmplo() && d.empresa_nome ? blocoDenuncia("Empresa", esc(d.empresa_nome)) : "") +
            htmlSetorDenuncia(d) +
            blocoDenuncia("Tipo de reclamação", esc(d.tipo_nome)) +
            '<div class="bloco-denuncia largo"><span>Descrição</span><div class="caixa-descricao">' + esc(d.descricao) + "</div></div>" +
            '<div class="bloco-denuncia largo"><span>Anexos</span>' + anexos + "</div>" +
            "</div></div>" +
            '<div id="painel-tratativas"' + (aba === "tratativas" ? "" : " hidden") + ">" + (fila ? "" : htmlTratativas(d)) + "</div>" +
            "</div>" +
            '<footer class="rodape-denuncia"><div class="status-denuncia"><span>Status</span>' + seloStatus(d.status_nome, d.status_fixo, d.status_cor) + "</div>" +
            '<button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Fechar</button></footer>' +
            "</section>" +
            '<section class="painel-historico">' +
            "<h2>Histórico</h2>" +
            '<div class="abas">' +
            '<button type="button" class="aba ativa" data-acao="aba" data-aba="comentarios"><i class="fa-solid fa-comment"></i> Comentários</button>' +
            '<button type="button" class="aba" data-acao="aba" data-aba="movimentacoes"><i class="fa-solid fa-clock-rotate-left"></i> Movimentações</button>' +
            "</div>" +
            '<div id="painel-comentarios" class="historico-corpo chat-painel"><div class="lista-notas">' + listaComentarios(d.comentarios, "comentario", fila || d.status_fixo === "final") + "</div></div>" +
            '<div id="painel-movimentacoes" class="historico-corpo" hidden><div class="lista-notas">' + notas(d.movimentacoes, "Sem movimentações.", false) + "</div></div>" +
            (fila || d.status_fixo === "final"
                ? '<div class="comentario-bloqueado"><i class="fa-solid fa-lock" aria-hidden="true"></i><p>' +
                    (fila
                        ? "Os comentários ficam disponíveis quando a tratativa for iniciada."
                        : "Esta reclamação foi concluída. Não é possível adicionar comentários.") +
                    "</p></div>"
                : '<form id="form-comentario" class="comentario-rodape" action="api.php" method="post" enctype="multipart/form-data">' +
            '<input type="hidden" name="acao" value="comentario_criar"><input type="hidden" name="id" value="' + d.id + '">' +
            '<input type="hidden" name="comentario_id" value="">' +
            '<div class="chat-editando" id="chat-editando" hidden><i class="fa-solid fa-pen" aria-hidden="true"></i><span>Editando mensagem</span>' +
            '<button type="button" data-acao="cancelar-edicao-comentario" aria-label="Cancelar edição"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="editor-ferramentas">' +
            '<button type="button" data-fmt="bold" title="Negrito"><b>B</b></button>' +
            '<button type="button" data-fmt="italic" title="Itálico"><i>I</i></button>' +
            '<button type="button" data-fmt="underline" title="Sublinhado"><u>U</u></button>' +
            '<button type="button" class="editor-anexo" title="Anexo"><i class="fa-solid fa-paperclip"></i></button>' +
            '<input class="editor-arquivo" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">' +
            "</div>" +
            '<div class="comentario-linha">' +
            '<div class="editor-area" contenteditable="true" data-placeholder="Escreva um comentário..."></div>' +
            '<button class="comentario-enviar" type="submit" aria-label="Enviar comentário" title="Enviar"><i class="fa-solid fa-paper-plane"></i></button>' +
            "</div>" +
            '<ul class="editor-lista"></ul></form>') +
            "</section></div>";
        const fundo = mostrarModal(html, true);
        ligarEditor(fundo.querySelector("#form-comentario"));
        ligarAvatares(fundo);
        ligarContadores(fundo);
        ligarRelogio();
        const chat = fundo.querySelector("#painel-comentarios");
        if (chat) {
            chat.scrollTop = chat.scrollHeight;
            chat.addEventListener("scroll", fecharMenusChat);
        }
    }

    function htmlTratativas(d) {
        const temAcao = String(d.acao_imediata || "").trim() !== "";
        const temCausa = String(d.causa || "").trim() !== "";
        return '<div class="etapas-tratativa">' +
            htmlEtapaTexto(1, "acao", "Ação Imediata", "Qual foi a ação imediata tomada para tratar esta reclamação?", d.acao_imediata, false, temAcao, d.acao_confirmacao) +
            htmlEtapaTexto(2, "causa", "Identificação da Causa", "Qual a causa desta reclamação?", d.causa, !temAcao, temCausa, d.causa_confirmacao) +
            htmlEtapaPlano(d.plano || [], !temCausa, d.plano_confirmacao) +
            "</div>";
    }

    function htmlMarcaEtapa(preenchida, confirmacao) {
        if (!preenchida) return "";
        const quem = confirmacao && confirmacao.nome
            ? "<span>" + esc(confirmacao.nome) + " · " + esc(formatarData(confirmacao.em)) + "</span>"
            : "";
        return '<span class="etapa-confirma"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>' + quem + "</span>";
    }

    function htmlCabecaEtapa(numero, titulo, preenchida, confirmacao) {
        return '<div class="etapa-cabeca"><h3>' + numero + ". " + esc(titulo) + "</h3>" +
            htmlMarcaEtapa(preenchida, confirmacao) + "</div>";
    }

    function htmlEtapaTexto(numero, etapa, titulo, pergunta, valor, bloqueada, preenchida, confirmacao) {
        let corpo = "";
        if (bloqueada) {
            corpo = '<p class="aviso-etapa">Conclua a etapa anterior para preencher esta.</p>';
        } else if (preenchida) {
            corpo = '<div class="etapa-leitura">' + esc(valor || "") + "</div>";
        } else {
            corpo = '<textarea id="tratativa-' + etapa + '" minlength="100" maxlength="5000">' + esc(valor || "") + "</textarea>" +
                '<div class="grupo-botoes"><button type="button" class="btn btn-primario" data-acao="salvar-tratativa" data-etapa="' + etapa + '">Concluir Etapa</button></div>';
        }
        return '<section class="etapa-tratativa' + (bloqueada ? " bloqueada" : "") + (preenchida ? " travada" : "") + '">' +
            htmlCabecaEtapa(numero, titulo, preenchida, confirmacao) +
            "<p>" + esc(pergunta) + "</p>" +
            corpo +
            "</section>";
    }

    function htmlEtapaPlano(plano, bloqueada, confirmacao) {
        const preenchida = (plano || []).length > 0;
        let corpo = "";
        if (bloqueada) {
            corpo = '<p class="aviso-etapa">Conclua a etapa anterior para preencher esta.</p>';
        } else if (preenchida) {
            corpo = htmlTabelaPlanoLeitura(plano, confirmacao);
        } else {
            corpo = '<div class="tabela-plano-envoltorio"><table class="tabela-plano edicao" id="tabela-plano"><thead><tr><th>Ação</th><th>Responsável</th><th>Realizar até</th><th></th></tr></thead><tbody>' +
                htmlLinhaPlano({ acao: "", responsavel: "", tempo_aplicacao: "" }) +
                "</tbody></table></div>" +
                '<div class="grupo-botoes"><button type="button" class="btn btn-fantasma" data-acao="plano-adicionar"><i class="fa-solid fa-plus"></i> Adicionar ação</button>' +
                '<button type="button" class="btn btn-primario" data-acao="salvar-tratativa" data-etapa="plano">Concluir Etapa</button></div>';
        }
        return '<section class="etapa-tratativa' + (bloqueada ? " bloqueada" : "") + (preenchida ? " travada" : "") + '">' +
            htmlCabecaEtapa("3", "Plano de Ação", preenchida, confirmacao) +
            "<p>Você deve registrar ao menos 1 ação, para que esta reclamação não aconteça novamente, bem como o prazo previsto para você concluir essa ação.</p>" +
            corpo +
            "</section>";
    }

    function htmlTabelaPlanoLeitura(plano, confirmacao) {
        const comStatus = !!confirmacao;
        const linhas = (plano || []).map(function (item) {
            const prazo = /^\d{4}-\d{2}-\d{2}$/.test(String(item.tempo_aplicacao || ""))
                ? formatarData(item.tempo_aplicacao)
                : esc(item.tempo_aplicacao || "—");
            return "<tr><td>" + esc(item.acao || "") + "</td><td>" + esc(item.responsavel || "") + "</td><td>" + prazo + "</td>" +
                (comStatus ? '<td class="col-status-plano">' + seloStatusAcao(item.status) + "</td>" : "") + "</tr>";
        }).join("");
        return '<div class="tabela-plano-envoltorio"><table class="tabela-plano leitura"><thead><tr><th>Ação</th><th>Responsável</th><th>Realizar até</th>' +
            (comStatus ? '<th class="col-status-plano">Status</th>' : "") + "</tr></thead><tbody>" +
            linhas + "</tbody></table></div>";
    }

    function htmlLinhaPlano(item) {
        const hoje = dataHoje();
        const informado = /^\d{4}-\d{2}-\d{2}$/.test(String(item.tempo_aplicacao || "")) ? String(item.tempo_aplicacao) : "";
        const prazo = informado || hoje;
        return '<tr data-id="' + Number(item.id || 0) + '"><td><input data-campo="acao" maxlength="500" value="' + esc(item.acao || "") + '"></td>' +
            '<td><input data-campo="responsavel" minlength="2" maxlength="150" value="' + esc(item.responsavel || "") + '"></td>' +
            '<td><input data-campo="tempo" type="date" min="' + hoje + '" value="' + esc(prazo) + '"></td>' +
            '<td><button type="button" class="btn btn-fantasma btn-ico" data-acao="plano-remover" title="Remover" aria-label="Remover"><i class="fa-solid fa-trash"></i></button></td></tr>';
    }

    function ativarAbaPrincipal(nome) {
        if (nome === "tratativas" && emFilaTratativa(estado.detalhe)) return;
        estado.abaPrincipal = nome === "tratativas" ? "tratativas" : "reclamacao";
        document.querySelectorAll(".aba-principal").forEach(function (botao) {
            botao.classList.toggle("ativa", botao.dataset.aba === estado.abaPrincipal);
            botao.setAttribute("aria-selected", botao.dataset.aba === estado.abaPrincipal ? "true" : "false");
        });
        const reclamacao = document.getElementById("painel-reclamacao");
        const tratativas = document.getElementById("painel-tratativas");
        if (reclamacao) reclamacao.hidden = estado.abaPrincipal !== "reclamacao";
        if (tratativas) tratativas.hidden = estado.abaPrincipal !== "tratativas";
    }

    function linhasPlanoFormulario() {
        return Array.from(document.querySelectorAll("#tabela-plano tbody tr")).map(function (tr) {
            function campo(nome) {
                const input = tr.querySelector('[data-campo="' + nome + '"]');
                return input ? input.value.trim() : "";
            }
            return { id: Number(tr.dataset.id) || 0, acao: campo("acao"), responsavel: campo("responsavel"), tempo: campo("tempo") };
        }).filter(function (item) {
            return item.acao || item.responsavel || item.tempo;
        });
    }

    function planoAlterado() {
        const tabela = document.getElementById("tabela-plano");
        if (!tabela || tabela.closest(".etapa-tratativa.bloqueada")) return false;
        const salvo = (estado.detalhe.plano || []).map(function (item) {
            return {
                acao: String(item.acao || "").trim(),
                responsavel: String(item.responsavel || "").trim(),
                tempo: String(item.tempo_aplicacao || "").trim()
            };
        });
        const atual = linhasPlanoFormulario().map(function (item) {
            return { acao: item.acao, responsavel: item.responsavel, tempo: item.tempo };
        });
        return JSON.stringify(atual) !== JSON.stringify(salvo);
    }

    function etapaTratativaPendente() {
        if (!estado.detalhe || !document.getElementById("painel-tratativas")) return "";
        const d = estado.detalhe;
        const acao = document.getElementById("tratativa-acao");
        if (acao && !acao.disabled && acao.value.trim() !== String(d.acao_imediata || "").trim()) return "acao";
        const causa = document.getElementById("tratativa-causa");
        if (causa && !causa.disabled && causa.value.trim() !== String(d.causa || "").trim()) return "causa";
        if (planoAlterado()) return "plano";
        return "";
    }

    function dadosTratativa(etapa) {
        const base = { id: estado.detalhe.id, etapa: etapa };
        if (etapa === "plano") {
            const acoes = linhasPlanoFormulario();
            if (!acoes.length) throw new Error("Adicione ao menos uma ação.");
            const incompleta = acoes.some(function (item) {
                return item.acao.length < 2 || item.responsavel.length < 2 || !/^\d{4}-\d{2}-\d{2}$/.test(item.tempo);
            });
            if (incompleta) throw new Error("Preencha a ação, o responsável e até quando ela deve ser realizada.");
            if (acoes.some(function (item) { return item.tempo < dataHoje(); })) {
                throw new Error("A data da ação não pode ser anterior a hoje.");
            }
            if (acoes.length > 30) throw new Error("O plano pode ter no máximo 30 ações.");
            base.acoes = JSON.stringify(acoes);
            return base;
        }
        const campo = document.getElementById(etapa === "acao" ? "tratativa-acao" : "tratativa-causa");
        const texto = campo ? campo.value.trim() : "";
        if (Array.from(texto).length < 100) {
            throw new Error(etapa === "acao"
                ? "Descreva a ação imediata com pelo menos 100 caracteres."
                : "Descreva a causa com pelo menos 100 caracteres.");
        }
        base.texto = texto;
        return base;
    }

    async function iniciarTratativa() {
        const ok = await confirmar(
            "Deseja realmente iniciar a tratativa desta reclamação? O status passa para Em tratativa e a guia de tratativas é liberada.",
            "Iniciar tratativa",
            "Iniciar tratativa"
        );
        if (!ok) return;
        const botao = document.querySelector('[data-acao="iniciar-tratativa"]');
        if (botao) {
            botao.disabled = true;
            botao.textContent = "Iniciando...";
        }
        try {
            const res = await api("tratativa_iniciar", { id: estado.detalhe.id });
            estado.abaPrincipal = "tratativas";
            estado.detalhe = res.dados;
            toast(res.mensagem || "Tratativa iniciada.", "ok");
            pintarDetalhe();
            await atualizarListaAberta();
        } catch (err) {
            if (botao) {
                botao.disabled = false;
                botao.textContent = "Iniciar tratativa desta reclamação";
            }
            throw err;
        }
    }

    async function salvarTratativa(etapa) {
        const payload = dadosTratativa(etapa);
        const ok = await confirmarEscrito("Escreva CONFIRMAR para concluir essa etapa da tratativa, lembrando que não será possível editar após confirmar.");
        if (!ok) return;
        const botao = document.querySelector('[data-acao="salvar-tratativa"][data-etapa="' + etapa + '"]');
        if (botao) travar(botao);
        try {
            const res = await api("tratativa_salvar", payload);
            estado.abaPrincipal = "tratativas";
            estado.editandoTratativa = "";
            estado.detalhe = res.dados;
            toast(res.mensagem || "Tratativa registrada.", "ok");
            pintarDetalhe();
            await atualizarListaAberta();
        } catch (err) {
            if (botao) destravar(botao);
            throw err;
        }
    }

    function adicionarLinhaPlano() {
        const corpo = document.querySelector("#tabela-plano tbody");
        if (!corpo || corpo.closest(".etapa-tratativa.bloqueada")) return;
        if (corpo.querySelectorAll("tr").length >= 30) {
            toast("O plano pode ter no máximo 30 ações.", "erro");
            return;
        }
        corpo.insertAdjacentHTML("beforeend", htmlLinhaPlano({ acao: "", responsavel: "", tempo_aplicacao: "" }));
        ligarContadores(corpo.lastElementChild);
    }

    function removerLinhaPlano(botao) {
        const linha = botao.closest("tr");
        const corpo = document.querySelector("#tabela-plano tbody");
        if (!linha || !corpo || corpo.closest(".etapa-tratativa.bloqueada")) return;
        if (corpo.querySelectorAll("tr").length === 1) {
            linha.querySelectorAll("input, textarea").forEach(function (input) {
                input.value = input.type === "date" ? dataHoje() : "";
                input.dispatchEvent(new Event("input", { bubbles: true }));
            });
            return;
        }
        linha.remove();
    }

    function textoContado(campo) {
        if (campo.isContentEditable) return (campo.innerText || "").replace(/\s+/g, " ").trim();
        return String(campo.value || "").trim();
    }

    function ligarContadores(raiz) {
        if (!raiz) return;
        raiz.querySelectorAll("textarea[minlength]").forEach(function (campo) {
            if (campo.dataset.contador === "1") return;
            const minimo = Number(campo.getAttribute("minlength")) || 0;
            if (minimo < 80) return;
            campo.dataset.contador = "1";
            let aviso = campo.nextElementSibling;
            if (!aviso || !aviso.classList.contains("contador-minimo")) {
                aviso = document.createElement("small");
                aviso.className = "contador-minimo";
                campo.insertAdjacentElement("afterend", aviso);
            }
            function atualizar() {
                const total = Array.from(textoContado(campo)).length;
                aviso.textContent = total + " / mínimo " + minimo + " caracteres";
                aviso.classList.toggle("ok", total >= minimo);
            }
            campo.addEventListener("input", atualizar);
            atualizar();
        });
    }

    function htmlComentario(html) {
        const modelo = document.createElement("template");
        modelo.innerHTML = String(html || "");
        modelo.content.querySelectorAll("*").forEach(function (el) {
            if (["B", "I", "U", "BR"].indexOf(el.tagName) === -1) {
                el.replaceWith(document.createTextNode(el.textContent || ""));
                return;
            }
            Array.from(el.attributes).forEach(function (atributo) { el.removeAttribute(atributo.name); });
        });
        return modelo.innerHTML;
    }

    function lerConclusao(texto) {
        const bruto = String(texto || "");
        const marca = bruto.match(/^\[\[conclusao\]\]([\s\S]*?)\]\]([\s\S]*)$/);
        if (!marca) return null;
        let situacao = marca[1];
        try { situacao = decodeURIComponent(marca[1]); } catch (err) { situacao = marca[1]; }
        return { situacao: situacao, html: marca[2] };
    }

    function iniciaisNome(nome) {
        const partes = String(nome || "").trim().split(/\s+/).filter(Boolean);
        if (!partes.length) return "?";
        const ultima = partes.length > 1 ? partes[partes.length - 1].charAt(0) : "";
        return (partes[0].charAt(0) + ultima).toUpperCase();
    }

    function ligarAvatares(raiz) {
        (raiz || document).querySelectorAll("img.chat-avatar").forEach(function (img) {
            img.addEventListener("error", function () {
                const troca = document.createElement("span");
                troca.className = "chat-avatar chat-iniciais";
                troca.textContent = img.getAttribute("data-iniciais") || "?";
                img.replaceWith(troca);
            });
        });
    }

    function horaChat(iso) {
        if (!iso) return "—";
        const partes = String(iso).split(" ");
        const data = partes[0].split("-");
        if (data.length !== 3) return formatarData(iso);
        const hora = partes[1] ? partes[1].slice(0, 5) : "";
        const dia = data[2] + "/" + data[1];
        return hora ? hora + " · " + dia : dia;
    }

    function htmlAvatarChat(item) {
        const ini = iniciaisNome(item.autor);
        if (item.foto) {
            return '<img class="chat-avatar" src="' + esc(item.foto) + '" alt="" data-iniciais="' + esc(ini) + '">';
        }
        return '<span class="chat-avatar chat-iniciais">' + esc(ini) + "</span>";
    }

    function listaComentarios(lista, origemAnexo, bloqueado) {
        const origem = origemAnexo || "comentario";
        if (!lista || !lista.length) return '<p class="chat-vazio">Nenhuma mensagem ainda.</p>';
        const mensagens = lista.filter(function (item) { return !lerConclusao(item.texto); });
        const ultima = mensagens.length ? Number(mensagens[mensagens.length - 1].id) : 0;
        const eu = Number(window.APP && APP.usuario ? APP.usuario.id : 0);
        return '<div class="chat">' + lista.map(function (item) {
            const arquivos = (item.anexos || []).map(function (a) {
                return htmlAnexo(a, origem);
            }).join("");
            const conclusao = lerConclusao(item.texto);
            if (conclusao) {
                const situacao = conclusao.situacao ? " · " + esc(conclusao.situacao) : "";
                const autor = item.autor ? esc(item.autor) + " · " : "";
                return '<div class="chat-sistema"><span>Conclusão' + situacao + "</span>" +
                    '<div class="texto-comentario">' + htmlComentario(conclusao.html) + "</div>" +
                    '<small class="chat-conclusao-meta">' + autor + esc(horaChat(item.criado_em)) + "</small>" +
                    (arquivos ? '<div class="nota-anexos">' + arquivos + "</div>" : "") + "</div>";
            }
            const minha = Number(item.usuario_id) === eu;
            const corpo = item.excluido
                ? '<p class="chat-excluida"><i class="fa-solid fa-ban" aria-hidden="true"></i> Mensagem excluída</p>'
                : '<div class="texto-comentario">' + htmlComentario(item.texto) + "</div>" +
                    (arquivos ? '<div class="nota-anexos">' + arquivos + "</div>" : "");
            let menu = "";
            if (minha && !item.excluido && !bloqueado) {
                const editar = Number(item.id) === ultima
                    ? '<button type="button" data-acao="editar-comentario" data-id="' + item.id + '"><i class="fa-solid fa-pen"></i> Editar</button>'
                    : "";
                menu = '<button type="button" class="chat-seta" data-acao="menu-comentario" aria-label="Opções da mensagem" aria-expanded="false"><i class="fa-solid fa-chevron-down"></i></button>' +
                    '<div class="chat-menu" hidden>' + editar +
                    '<button type="button" data-acao="excluir-comentario" data-id="' + item.id + '"><i class="fa-solid fa-trash"></i> Excluir</button></div>';
            }
            const editada = !item.excluido && item.editado_em ? '<span class="chat-editada">editada</span>' : "";
            const nome = minha ? "" : '<strong class="chat-nome">' + esc(item.autor || "Usuário") + "</strong>";
            return '<div class="chat-linha' + (minha ? " minha" : "") + '">' + htmlAvatarChat(item) +
                '<div class="chat-bolha' + (menu ? " com-menu" : "") + '">' + menu +
                '<div class="chat-topo">' + nome + '<span class="chat-hora">' + editada + esc(horaChat(item.criado_em)) + "</span></div>" +
                corpo + "</div></div>";
        }).join("") + "</div>";
    }

    function anexoLegivel(nome) {
        return /\.(png|jpe?g|gif|webp|pdf)$/i.test(String(nome || ""));
    }

    function urlAnexo(id, origem, ver) {
        return "api.php?acao=anexo" + (ver ? "&ver=1" : "") + "&id=" + encodeURIComponent(id) + (origem ? "&origem=" + encodeURIComponent(origem) : "");
    }

    function htmlAnexo(a, origem, comTamanho) {
        const nome = String(a.nome_original || "arquivo");
        const tamanho = comTamanho ? ' <small class="muted">' + formatarBytes(a.tamanho) + "</small>" : "";
        const origemAttr = origem ? ' data-origem="' + esc(origem) + '"' : "";
        if (anexoLegivel(nome)) {
            return '<span class="anexo-item"><button type="button" class="link-anexo" data-acao="ver-anexo" data-id="' + a.id + '" data-nome="' + esc(nome) + '"' + origemAttr + ">" + esc(nome) + "</button>" + tamanho + "</span>";
        }
        return '<span class="anexo-item"><button type="button" class="link-anexo" data-acao="baixar-anexo" data-id="' + a.id + '" data-nome="' + esc(nome) + '"' + origemAttr + ">" + esc(nome) + "</button>" + tamanho +
            '<button type="button" class="btn btn-fantasma btn-ico" data-acao="baixar-anexo" data-id="' + a.id + '" data-nome="' + esc(nome) + '"' + origemAttr + ' title="Baixar" aria-label="Baixar"><i class="fa-solid fa-download"></i></button></span>';
    }

    function textoDownload(nome) {
        const arquivo = String(nome || "arquivo").trim() || "arquivo";
        return "O arquivo " + arquivo + " não pode ser visualizado. Para realizar a visualização dele, faça o download.";
    }

    async function confirmarDownload(id, nome, origem) {
        const ok = await confirmar(textoDownload(nome), "Arquivo sem visualização", "Download");
        if (!ok) return;
        baixarAnexo(id, origem);
    }

    async function confirmarDownloadArquivo(arquivo) {
        const ok = await confirmar(textoDownload(arquivo && arquivo.name), "Arquivo sem visualização", "Download");
        if (!ok || !arquivo) return;
        const url = URL.createObjectURL(arquivo);
        const link = document.createElement("a");
        link.href = url;
        link.download = arquivo.name || "anexo";
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function baixarAnexo(id, origem) {
        const link = document.createElement("a");
        link.href = urlAnexo(id, origem, false);
        link.rel = "noopener";
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    async function abrirAnexo(id, nome, origem) {
        if (!anexoLegivel(nome)) {
            return confirmarDownload(id, nome, origem);
        }
        const url = urlAnexo(id, origem, true);
        const resposta = await fetch(url, { credentials: "same-origin" });
        if (!resposta.ok) {
            throw new Error("Não foi possível abrir o arquivo.");
        }
        const blob = await resposta.blob();
        abrirPrevia(new File([blob], nome || "arquivo", { type: blob.type || "" }));
    }

    function tagsDoEstilo(estilo) {
        const css = String(estilo || "").toLowerCase().replace(/\s+/g, "");
        const tags = [];
        if (/font-weight:(bold|bolder|[6-9]00)/.test(css)) tags.push("b");
        if (/font-style:(italic|oblique)/.test(css)) tags.push("i");
        if (/text-decoration(-line)?:[^;"]*underline/.test(css)) tags.push("u");
        return tags;
    }

    function desembrulhar(el) {
        const pai = el.parentNode;
        if (!pai) return;
        while (el.firstChild) pai.insertBefore(el.firstChild, el);
        pai.removeChild(el);
    }

    function normalizarMarcas(raiz) {
        Array.from(raiz.querySelectorAll("b, i, u")).forEach(function (el) {
            const estilo = el.getAttribute("style");
            if (estilo != null && estilo.trim() === "") el.removeAttribute("style");
        });
        Array.from(raiz.querySelectorAll("strong")).forEach(function (el) {
            const novo = document.createElement("b");
            while (el.firstChild) novo.appendChild(el.firstChild);
            el.replaceWith(novo);
        });
        Array.from(raiz.querySelectorAll("em")).forEach(function (el) {
            const novo = document.createElement("i");
            while (el.firstChild) novo.appendChild(el.firstChild);
            el.replaceWith(novo);
        });
        const caixas = Array.from(raiz.querySelectorAll("span, font"));
        for (let i = caixas.length - 1; i >= 0; i--) {
            const el = caixas[i];
            if (!el.isConnected) continue;
            const tags = tagsDoEstilo(el.getAttribute("style"));
            if (!tags.length) {
                desembrulhar(el);
                continue;
            }
            let atual = document.createDocumentFragment();
            while (el.firstChild) atual.appendChild(el.firstChild);
            tags.forEach(function (tag) {
                const caixa = document.createElement(tag);
                caixa.appendChild(atual);
                atual = caixa;
            });
            el.replaceWith(atual);
        }
    }

    function htmlDoEditor(editor) {
        if (!editor) return "";
        const clone = editor.cloneNode(true);
        normalizarMarcas(clone);
        return clone.innerHTML;
    }

    function offsetNoEditor(raiz, nodo, offset) {
        const range = document.createRange();
        range.selectNodeContents(raiz);
        range.setStart(raiz, 0);
        try {
            range.setEnd(nodo, offset);
        } catch (err) {
            return (raiz.textContent || "").length;
        }
        return range.toString().length;
    }

    function selecionarOffsets(raiz, inicio, fim) {
        function ponto(pos) {
            const walker = document.createTreeWalker(raiz, NodeFilter.SHOW_TEXT, null);
            let restante = pos;
            let ultimo = null;
            let nodo = walker.nextNode();
            while (nodo) {
                ultimo = nodo;
                if (restante <= nodo.textContent.length) return { nodo: nodo, offset: restante };
                restante -= nodo.textContent.length;
                nodo = walker.nextNode();
            }
            if (ultimo) return { nodo: ultimo, offset: ultimo.textContent.length };
            return { nodo: raiz, offset: 0 };
        }
        const a = ponto(inicio);
        const b = ponto(Math.max(inicio, fim));
        const range = document.createRange();
        const selecao = window.getSelection();
        if (!selecao) return;
        try {
            range.setStart(a.nodo, a.offset);
            range.setEnd(b.nodo, b.offset);
            selecao.removeAllRanges();
            selecao.addRange(range);
        } catch (err) { /* a seleção do navegador permanece */ }
    }

    function ligarEditor(form) {
        if (!form) return;
        const editor = form.querySelector(".editor-area");
        const lista = form.querySelector(".editor-lista");
        const arquivo = form.querySelector(".editor-arquivo");
        const arquivos = [];
        form._arquivos = arquivos;
        function pintar() {
            lista.innerHTML = arquivos.map(function (item, indice) {
                return '<li><span>' + esc(item.name) + '</span><button type="button" data-remover="' + indice + '" aria-label="Remover"><i class="fa-solid fa-xmark"></i></button></li>';
            }).join("");
        }
        let faixa = null;
        let formatando = false;
        let atalhoAplicado = false;
        function selecaoNoEditor() {
            if (!editor) return false;
            const selecao = window.getSelection();
            if (!selecao || !selecao.rangeCount) return false;
            const nodo = selecao.anchorNode;
            if (!nodo) return false;
            const el = nodo.nodeType === 1 ? nodo : nodo.parentElement;
            return !!(el && editor.contains(el));
        }
        function guardarSelecao() {
            if (formatando || !selecaoNoEditor()) return;
            faixa = window.getSelection().getRangeAt(0).cloneRange();
        }
        function restaurarFaixa(range) {
            const selecao = window.getSelection();
            if (!range || !selecao || !editor) return false;
            const nodo = range.commonAncestorContainer;
            const el = nodo && (nodo.nodeType === 1 ? nodo : nodo.parentElement);
            if (!el || (el !== editor && !editor.contains(el))) return false;
            try {
                selecao.removeAllRanges();
                selecao.addRange(range);
                return true;
            } catch (err) {
                return false;
            }
        }
        function colocarCursor() {
            if (!editor) return;
            const selecao = window.getSelection();
            if (!selecao) return;
            const range = document.createRange();
            range.selectNodeContents(editor);
            range.collapse(false);
            selecao.removeAllRanges();
            selecao.addRange(range);
        }
        function atualizarBotoes() {
            const dentro = selecaoNoEditor();
            form.querySelectorAll("[data-fmt]").forEach(function (botao) {
                let ativo = false;
                if (dentro) {
                    try { ativo = document.queryCommandState(botao.dataset.fmt); } catch (err) { ativo = false; }
                }
                botao.classList.toggle("ativa", ativo);
                botao.setAttribute("aria-pressed", ativo ? "true" : "false");
            });
        }
        function aplicarFormato(comando) {
            if (!editor || !comando || formatando) return;
            const usar = faixa ? faixa.cloneRange() : null;
            formatando = true;
            try {
                editor.focus({ preventScroll: true });
                if (!restaurarFaixa(usar)) {
                    if (!selecaoNoEditor()) colocarCursor();
                }
                document.execCommand("styleWithCSS", false, "false");
                document.execCommand(comando, false, null);
                const antes = editor.innerHTML;
                const selecao = window.getSelection();
                let inicio = 0;
                let fim = 0;
                let marcar = false;
                if (selecao && selecao.rangeCount && selecaoNoEditor()) {
                    const range = selecao.getRangeAt(0);
                    inicio = offsetNoEditor(editor, range.startContainer, range.startOffset);
                    fim = offsetNoEditor(editor, range.endContainer, range.endOffset);
                    marcar = true;
                }
                normalizarMarcas(editor);
                if (marcar && editor.innerHTML !== antes) selecionarOffsets(editor, inicio, fim);
            } finally {
                formatando = false;
                guardarSelecao();
                atualizarBotoes();
            }
        }
        function comandoDoAtalho(e) {
            if (e.altKey || e.shiftKey || !(e.ctrlKey || e.metaKey)) return "";
            if (e.target && /^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName)) return "";
            const tecla = String(e.key || "").toLowerCase();
            let comando = "";
            if (tecla === "b" || e.code === "KeyB") comando = "bold";
            else if (tecla === "i" || e.code === "KeyI") comando = "italic";
            else if (tecla === "u" || e.code === "KeyU") comando = "underline";
            if (!comando) return "";
            return e.repeat ? "ignorar" : comando;
        }
        if (editor) {
            editor.addEventListener("mouseup", function () {
                guardarSelecao();
                atualizarBotoes();
            });
            editor.addEventListener("keyup", function (e) {
                if (e.key === "Control" || e.key === "Meta") atalhoAplicado = false;
                guardarSelecao();
                atualizarBotoes();
            });
            editor.addEventListener("keydown", function (e) {
                if ((form.getAttribute("id") === "form-comentario" || form.getAttribute("id") === "form-comentario-acao") && e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    const vazio = !editor.textContent.trim() && !(form._arquivos || []).length;
                    if (!vazio && typeof form.requestSubmit === "function") form.requestSubmit();
                    return;
                }
                if (form.getAttribute("id") !== "form-conclusao") return;
                const comando = comandoDoAtalho(e);
                if (!comando) return;
                e.preventDefault();
                e.stopPropagation();
                if (comando === "ignorar") return;
                atalhoAplicado = true;
                aplicarFormato(comando);
            }, true);
            editor.addEventListener("beforeinput", function (e) {
                if (form.getAttribute("id") !== "form-conclusao") return;
                const comando = { formatBold: "bold", formatItalic: "italic", formatUnderline: "underline" }[e.inputType];
                if (!comando) return;
                e.preventDefault();
                if (atalhoAplicado || formatando) return;
                aplicarFormato(comando);
            });
            document.addEventListener("selectionchange", function aoMudar() {
                if (!editor.isConnected) {
                    document.removeEventListener("selectionchange", aoMudar);
                    return;
                }
                guardarSelecao();
                atualizarBotoes();
            });
        }
        form.querySelectorAll("[data-fmt]").forEach(function (botao) {
            botao.setAttribute("aria-pressed", "false");
            botao.addEventListener("mousedown", function (e) {
                e.preventDefault();
                guardarSelecao();
            });
            botao.addEventListener("click", function (e) {
                e.preventDefault();
                aplicarFormato(botao.dataset.fmt);
            });
        });
        const anexo = form.querySelector(".editor-anexo");
        if (anexo && arquivo && lista) {
            anexo.addEventListener("click", function () { arquivo.click(); });
            arquivo.addEventListener("change", function () {
                Array.from(arquivo.files || []).forEach(function (item) {
                    if (arquivos.length < 5) arquivos.push(item);
                });
                arquivo.value = "";
                pintar();
            });
            lista.addEventListener("click", function (e) {
                const botao = e.target.closest("[data-remover]");
                if (!botao) return;
                arquivos.splice(Number(botao.dataset.remover), 1);
                pintar();
            });
        }
    }

    function seloSituacao(nome, cor) {
        return seloCor(nome, cor || "#dbe7ff");
    }

    function ficha(rotulo, valorHtml) {
        return "<div><dt>" + rotulo + "</dt><dd>" + valorHtml + "</dd></div>";
    }

    function blocoDenuncia(rotulo, valorHtml) {
        return '<div class="bloco-denuncia"><span>' + rotulo + "</span><strong>" + valorHtml + "</strong></div>";
    }

    function htmlSetorDenuncia(d) {
        if (!APP.usuario || APP.usuario.perfil !== "administrador" || d.status_fixo === "final") {
            return blocoDenuncia("Setor da reclamação", esc(d.setor_nome));
        }
        return '<div class="bloco-denuncia" id="bloco-setor"><span>Setor da reclamação</span>' +
            '<div class="setor-linha"><strong>' + esc(d.setor_nome || "—") + "</strong>" +
            '<button type="button" class="btn btn-fantasma btn-editar-setor" data-acao="editar-setor"><i class="fa-solid fa-pen"></i> Editar</button></div></div>';
    }

    function editarSetor() {
        const bloco = document.getElementById("bloco-setor");
        const d = estado.detalhe;
        if (!bloco || !d || d.status_fixo === "final") return;
        const cat = estado.catalogo || { setores: [] };
        const atual = (cat.setores || []).find(function (item) { return Number(item.id) === Number(d.setor_id); });
        const empresaId = atual ? Number(atual.empresa_id) : 0;
        const setores = empresaId ? setoresPorEmpresa(cat.setores, empresaId) : (atual ? [atual] : []);
        bloco.innerHTML = '<span>Setor da reclamação</span>' +
            '<div class="setor-edicao"><select id="select-setor-reclamacao" aria-label="Setor da reclamação">' +
            htmlOpcoes(setores, d.setor_id, "Selecione", true) +
            "</select>" +
            '<div class="grupo-botoes"><button type="button" class="btn btn-fantasma" data-acao="cancelar-setor">Cancelar</button>' +
            '<button type="button" class="btn btn-primario" data-acao="salvar-setor">Salvar</button></div></div>';
        const select = document.getElementById("select-setor-reclamacao");
        if (select) select.focus();
    }

    function cancelarSetor() {
        const bloco = document.getElementById("bloco-setor");
        if (!bloco || !estado.detalhe) return;
        bloco.outerHTML = htmlSetorDenuncia(estado.detalhe);
    }

    async function salvarSetor() {
        const select = document.getElementById("select-setor-reclamacao");
        const setorId = Number(select && select.value);
        if (!setorId) {
            toast("Selecione o setor.", "erro");
            return;
        }
        if (setorId === Number(estado.detalhe.setor_id)) {
            cancelarSetor();
            return;
        }
        const botao = document.querySelector('[data-acao="salvar-setor"]');
        if (botao) botao.disabled = true;
        try {
            const res = await api("reclamacao_setor", { id: estado.detalhe.id, setor_id: setorId });
            estado.detalhe = res.dados;
            toast(res.mensagem || "Setor da reclamação atualizado.", "ok");
            pintarDetalhe();
            await atualizarListaAberta();
            if (estado.pagina === "acoes") await renderAcoes();
        } catch (err) {
            if (botao) botao.disabled = false;
            toast(err.message || "Não foi possível alterar o setor.", "erro");
        }
    }

    function notas(lista, vazioTexto, comentario) {
        if (!lista || !lista.length) return '<p class="resumo">' + esc(vazioTexto) + "</p>";
        return lista.map(function (item) {
            const autor = item.autor || "Sistema";
            return '<article class="nota"><small>' + esc(autor) + " · " + esc(formatarData(item.criado_em)) + "</small><p>" + esc(comentario ? item.texto : item.descricao) + "</p></article>";
        }).join("");
    }

    function ativarAba(nome) {
        document.querySelectorAll(".aba").forEach(function (botao) {
            botao.classList.toggle("ativa", botao.dataset.aba === nome);
        });
        document.getElementById("painel-comentarios").hidden = nome !== "comentarios";
        document.getElementById("painel-movimentacoes").hidden = nome !== "movimentacoes";
        if (nome === "comentarios") {
            const painel = document.getElementById("painel-comentarios");
            if (painel) painel.scrollTop = painel.scrollHeight;
        }
    }

    async function reabrirReclamacao(botao) {
        const confirmarReabertura = await confirmar("A conclusão permanece nos comentários. A situação será apagada, porque a próxima conclusão será outra.", "Reabrir esta reclamação?", "Reabrir");
        if (!confirmarReabertura) return;
        if (botao) botao.disabled = true;
        try {
            const res = await api("reclamacao_reabrir", { id: estado.detalhe.id });
            estado.detalhe = res.dados;
            toast("Reclamação reaberta.", "ok");
            pintarDetalhe();
            await atualizarListaAberta();
        } catch (err) {
            if (botao) botao.disabled = false;
            throw err;
        }
    }

    async function mudarStatus(select) {
        const novo = Number(select.value);
        const atual = Number(estado.detalhe.status_id);
        const st = (estado.catalogo.status || []).find(function (item) { return Number(item.id) === novo; });
        if (!st) {
            toast("Selecione um status.", "erro");
            return;
        }
        if (st.fixo === "final") {
            abrirConclusao(st);
            return;
        }
        if (novo !== atual) {
            await api("reclamacao_status", { id: estado.detalhe.id, status_id: novo });
            await atualizarListaAberta();
        }
        fecharTodosModais();
    }

    function atualizarListaAberta() {
        if (document.getElementById("corpo-reclamacoes")) return carregarLista();
        if (estado.pagina === "dashboard") return renderDashboard();
    }

    function abrirConclusao(statusEscolhido) {
        const cat = estado.catalogo;
        const det = estado.detalhe;
        const finalStatus = statusEscolhido && statusEscolhido.fixo === "final"
            ? statusEscolhido
            : (cat.status || []).find(function (item) { return item.fixo === "final"; });
        if (!finalStatus) {
            toast("Não há status final configurado.", "erro");
            return;
        }
        const situacoes = ativos(cat.situacoes);
        if (!situacoes.length) {
            toast("Cadastre uma situação ativa antes de concluir.", "erro");
            return;
        }
        const html =
            '<form id="form-conclusao" class="modal modal-conclusao" action="api.php" method="post" enctype="multipart/form-data" role="dialog" aria-modal="true">' +
            '<div class="modal-cabeca"><h2>Conclusão</h2><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="modal-corpo"><input type="hidden" name="acao" value="reclamacao_status"><input type="hidden" name="id" value="' + det.id + '"><input type="hidden" name="status_id" value="' + finalStatus.id + '">' +
            '<div class="grade">' +
            campoCheio("Situação da reclamação", '<select name="situacao_id" required>' + htmlOpcoes(situacoes, det.situacao_id, "Selecione", true) + "</select>") +
            '<div class="campo cheio"><span>Conclusão</span>' +
            '<div class="editor-ferramentas">' +
            '<button type="button" data-fmt="bold" title="Negrito (Ctrl+B)" aria-label="Negrito" aria-pressed="false"><b>B</b></button>' +
            '<button type="button" data-fmt="italic" title="Itálico (Ctrl+I)" aria-label="Itálico" aria-pressed="false"><i>I</i></button>' +
            '<button type="button" data-fmt="underline" title="Sublinhado (Ctrl+U)" aria-label="Sublinhado" aria-pressed="false"><u>U</u></button>' +
            "</div>" +
            '<div class="editor-area editor-conclusao" contenteditable="true" data-placeholder="Descreva a conclusão."></div></div>' +
            '<div class="campo cheio"><span>Anexos</span><div class="soltar">' +
            '<input class="soltar-arquivo" name="anexos[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">' +
            '<div class="soltar-alvo"><i class="fa-solid fa-cloud-arrow-up"></i><strong>Solte os arquivos aqui</strong>' +
            '<button type="button" class="soltar-escolher">ou selecione no dispositivo</button></div>' +
            '<ul class="soltar-nomes"></ul><small>PDF, imagem, Word ou Excel. Até 5 arquivos de 20 MB.</small></div></div>' +
            "</div>" +
            '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Cancelar</button><button class="btn btn-primario" type="submit">Salvar</button></div></div></form>';
        const fundo = mostrarModal(html, false);
        ligarEditor(fundo.querySelector("#form-conclusao"));
        ligarSoltar(fundo.querySelector(".soltar"));
    }

    function textoConclusao(editor) {
        return (editor ? editor.innerText : "").replace(/\s+/g, " ").trim();
    }

    async function salvarConclusao(form) {
        const botao = form.querySelector('button[type="submit"]');
        const editor = form.querySelector(".editor-area");
        if (Array.from(textoConclusao(editor)).length < 200) {
            toast("Descreva a conclusão com pelo menos 200 caracteres.", "erro");
            if (editor) editor.focus();
            return;
        }
        const arquivos = form.querySelector('input[type="file"]');
        if (arquivos && arquivos.files && arquivos.files.length > 5) {
            throw new Error("Envie no máximo 5 arquivos.");
        }
        if (arquivos && arquivos.files) {
            for (let i = 0; i < arquivos.files.length; i++) {
                if (arquivos.files[i].size > LIMITE_ANEXO) {
                    throw new Error("Cada anexo pode ter no máximo 20 MB.");
                }
            }
        }
        const fd = new FormData();
        fd.append("acao", "reclamacao_status");
        fd.append("csrf", csrf());
        fd.append("id", form.querySelector('[name="id"]').value);
        fd.append("status_id", form.querySelector('[name="status_id"]').value);
        fd.append("situacao_id", form.querySelector('[name="situacao_id"]').value);
        fd.append("conclusao", htmlDoEditor(editor));
        if (arquivos && arquivos.files) {
            Array.from(arquivos.files).forEach(function (arquivo) { fd.append("anexos[]", arquivo); });
        }
        travar(botao);
        const res = await requisicao(fd);
        estado.detalhe = res.dados;
        toast("Conclusão registrada.", "ok");
        pintarDetalhe();
        await atualizarListaAberta();
    }

    function fecharMenusChat() {
        document.querySelectorAll(".chat-menu").forEach(function (menu) {
            menu.hidden = true;
        });
        document.querySelectorAll('.chat-seta[aria-expanded="true"]').forEach(function (botao) {
            botao.setAttribute("aria-expanded", "false");
        });
    }

    function alternarMenuComentario(botao) {
        const bolha = botao.closest(".chat-bolha");
        const menu = bolha && bolha.querySelector(".chat-menu");
        if (!menu) return;
        const abrir = menu.hidden;
        fecharMenusChat();
        if (!abrir) return;
        menu.hidden = false;
        botao.setAttribute("aria-expanded", "true");
        const rect = botao.getBoundingClientRect();
        const largura = menu.offsetWidth || 148;
        const altura = menu.offsetHeight || 80;
        let esquerda = rect.right - largura;
        if (esquerda < 8) esquerda = 8;
        if (esquerda + largura > window.innerWidth - 8) esquerda = window.innerWidth - largura - 8;
        let topo = rect.bottom + 4;
        if (topo + altura > window.innerHeight - 8) topo = Math.max(8, rect.top - altura - 4);
        menu.style.left = esquerda + "px";
        menu.style.top = topo + "px";
    }

    function formComentarioAtivo() {
        return document.getElementById("form-comentario-acao") || document.getElementById("form-comentario");
    }

    function comentariosAtivos() {
        if (document.getElementById("form-comentario-acao") && estado.acao) return estado.acao.comentarios || [];
        return (estado.detalhe && estado.detalhe.comentarios) || [];
    }

    function editarComentario(id) {
        fecharMenusChat();
        const item = comentariosAtivos().find(function (comentario) { return Number(comentario.id) === id; });
        const form = formComentarioAtivo();
        if (!item || !form || item.excluido) return;
        const campo = form.querySelector('[name="comentario_id"]');
        const editor = form.querySelector(".editor-area");
        if (campo) campo.value = String(id);
        if (editor) {
            editor.innerHTML = htmlComentario(item.texto);
            editor.focus();
        }
        const faixa = document.getElementById("chat-editando");
        if (faixa) faixa.hidden = false;
        const anexo = form.querySelector(".editor-anexo");
        if (anexo) anexo.hidden = true;
    }

    function cancelarEdicaoComentario() {
        const form = formComentarioAtivo();
        if (!form) return;
        const campo = form.querySelector('[name="comentario_id"]');
        const editor = form.querySelector(".editor-area");
        if (campo) campo.value = "";
        if (editor) editor.innerHTML = "";
        const faixa = document.getElementById("chat-editando");
        if (faixa) faixa.hidden = true;
        const anexo = form.querySelector(".editor-anexo");
        if (anexo) anexo.hidden = false;
        if (form._arquivos) form._arquivos.splice(0, form._arquivos.length);
        const lista = form.querySelector(".editor-lista");
        if (lista) lista.innerHTML = "";
    }

    async function excluirComentario(id) {
        fecharMenusChat();
        const ok = await confirmar("Excluir esta mensagem? Ela continua na conversa, marcada como excluída.", "Excluir mensagem", "Excluir");
        if (!ok) return;
        const deAcao = !!document.getElementById("form-comentario-acao");
        const res = await api(deAcao ? "acao_comentario_excluir" : "comentario_excluir", {
            id: deAcao ? estado.acao.id : estado.detalhe.id,
            comentario_id: id
        });
        toast(res.mensagem || "Mensagem excluída.", "ok");
        if (deAcao) {
            estado.acao = res.dados;
            pintarAcao();
        } else {
            estado.detalhe = res.dados;
            pintarDetalhe();
        }
        ativarAba("comentarios");
    }

    async function salvarComentario(form, reabrir) {
        const deAcao = form.getAttribute("id") === "form-comentario-acao";
        const botao = form.querySelector('button[type="submit"]');
        const editor = form.querySelector(".editor-area");
        const editando = form.querySelector('[name="comentario_id"]');
        const fd = new FormData();
        fd.append("acao", editando && editando.value
            ? (deAcao ? "acao_comentario_editar" : "comentario_editar")
            : (deAcao ? "acao_comentario_criar" : "comentario_criar"));
        fd.append("csrf", csrf());
        fd.append("id", form.querySelector('[name="id"]').value);
        if (editando && editando.value) fd.append("comentario_id", editando.value);
        fd.append("texto", htmlDoEditor(editor));
        if (!editando || !editando.value) {
            (form._arquivos || []).forEach(function (arquivo) { fd.append("anexos[]", arquivo); });
        }
        travar(botao);
        const res = await requisicao(fd);
        if (deAcao) {
            estado.acao = res.dados;
            if (reabrir === false) return res;
            pintarAcao();
        } else {
            estado.detalhe = res.dados;
            pintarDetalhe();
        }
        ativarAba("comentarios");
    }

    async function renderUsuarios(sincronizar) {
        const busca = document.getElementById("busca-usuarios");
        const q = busca ? busca.value : "";
        const res = await api("usuarios_listar", sincronizar ? { q: q, sincronizar: 1 } : { q: q });
        estado.usuarios = res.dados.itens;
        const admin = APP.usuario.perfil === "administrador";
        let html = '<div class="largura"><p class="resumo">Só entra no sistema quem estiver nesta lista. Ao incluir, escolha se a pessoa é gestor, diretoria ou administrador. A diretoria acompanha todas as empresas e setores, sem acesso às configurações. O administrador também acompanha todas. Sem setores, ele recebe alerta de todas as reclamações; com setores, recebe alerta só deles. O gestor fica ligado a uma empresa e aos setores que pode acompanhar. O e-mail e o telefone vêm da Provita no momento da inclusão, quando existirem. Sincronizar atualiza nome, e-mail, telefone e a situação de quem já está no sistema.</p>';
        html += '<div class="card" style="margin-top:14px"><div class="linha-acao"><label class="campo campo-curto"><span>Pesquisar</span><input id="busca-usuarios" value="' + esc(q) + '" placeholder="Nome ou usuário"></label><div class="grupo-botoes">';
        if (admin) html += '<button type="button" class="btn btn-primario" data-acao="adicionar-usuarios"><i class="fa-solid fa-user-plus"></i> Adicionar usuário</button>';
        html += '<button type="button" class="btn btn-primario" data-acao="sincronizar-usuarios"><i class="fa-solid fa-rotate"></i> Sincronizar</button></div></div>';
        html += abasFiltro();
        const ativosAba = estado.abaLista !== "inativos";
        const pessoas = res.dados.itens.filter(function (u) {
            const liberado = Number(u.ativo) === 1 && Number(u.bloqueado) !== 1;
            return liberado === ativosAba;
        });
        if (!pessoas.length) {
            html += vazio("fa-users", ativosAba ? "Nenhum usuário ativo" : "Nenhum usuário inativo", ativosAba ? "O administrador inclui quem pode entrar." : "Os usuários inativados aparecem nesta aba.");
        } else {
            html += '<div class="tabela-envoltorio"><table class="tabela tabela-compacta"><thead><tr><th>Nome</th><th>Nome de usuário</th><th>E-mail</th><th>Telefone</th><th>Perfil</th><th>Empresa</th><th>Setores</th><th>Acesso</th><th></th></tr></thead><tbody>';
            pessoas.forEach(function (u) {
                const eu = Number(APP.usuario.id) === Number(u.id);
                const liberado = Number(u.ativo) === 1 && Number(u.bloqueado) !== 1;
                html += "<tr><td><strong>" + esc(u.nome) + "</strong></td>";
                html += "<td>" + esc(u.login) + "</td>";
                html += "<td>" + textoOuTraco(u.email) + "</td>";
                html += "<td>" + textoOuTraco(u.celular) + "</td>";
                html += "<td>" + (admin
                    ? '<select class="select-perfil" data-acao="perfil" data-id="' + u.id + '">' +
                        opcaoPerfil("gestao", "Gestor", u.perfil, false) +
                        opcaoPerfil("diretoria", "Diretoria", u.perfil, false) +
                        opcaoPerfil("administrador", "Administrador", u.perfil, false) +
                        "</select>"
                    : esc(rotuloPerfil(u.perfil))) + "</td>";
                html += "<td>" + (u.perfil === "diretoria" || (u.perfil === "administrador" && !u.empresa_nome) ? '<span class="muted">Todas</span>' : esc(u.empresa_nome || "—")) + "</td>";
                html += "<td>" + htmlSetoresUsuario(u) + "</td>";
                html += "<td>" + (liberado ? '<span class="selo selo-verde">Ativo</span>' : '<span class="selo selo-vermelho">Inativo</span>') + "</td>";
                html += '<td><div class="grupo-botoes">';
                html += '<button type="button" class="btn btn-fantasma btn-ico" data-acao="editar-usuario" data-id="' + u.id + '" title="Editar"><i class="fa-solid fa-pen"></i></button>';
                if (!eu) {
                    html += '<button type="button" class="btn btn-fantasma btn-ico" data-acao="usuario-bloqueio" data-id="' + u.id + '" data-bloqueado="' + (Number(u.bloqueado) === 1 ? 0 : 1) + '" title="' + (Number(u.bloqueado) === 1 ? "Reativar" : "Inativar") + '"><i class="fa-solid ' + (Number(u.bloqueado) === 1 ? "fa-user-check" : "fa-user-slash") + '"></i></button>';
                    html += '<button type="button" class="btn btn-perigo btn-ico" data-acao="usuario-excluir" data-id="' + u.id + '" title="Excluir"><i class="fa-solid fa-trash"></i></button>';
                }
                html += "</div></td></tr>";
            });
            html += "</tbody></table></div>";
        }
        html += "</div></div>";
        const focado = document.activeElement && document.activeElement.id === "busca-usuarios";
        conteudo().innerHTML = html;
        const campo = document.getElementById("busca-usuarios");
        campo.addEventListener("input", function () {
            clearTimeout(buscaTimer);
            buscaTimer = setTimeout(renderUsuarios, 350);
        });
        if (focado) {
            campo.focus();
            const fim = campo.value.length;
            campo.setSelectionRange(fim, fim);
        }
    }

    async function abrirInclusaoUsuarios() {
        const res = await api("usuarios_sso_listar");
        await garantirCatalogo();
        const itens = (res.dados.itens || []).filter(function (item) { return !item.ja_cadastrado; });
        const campos = itens.length
            ? '<div id="campo-busca"><label class="campo"><span>Pessoa</span><div class="busca-caixa">' +
                '<input id="filtro-sso" placeholder="Nome ou usuário" autocomplete="off">' +
                '<div class="busca-lista" id="lista-sso" hidden></div></div></label>' +
                '<p class="inclusao-dica" id="dica-sso">Digite ao menos 2 letras do nome ou do usuário.</p></div>' +
                '<div id="pessoa-escolhida" class="pessoa-escolhida" hidden></div>' +
                '<input type="hidden" name="sso_id" id="sso-escolhido" value="">' +
                '<label class="campo" id="campo-perfil" hidden><span>Perfil</span><select name="perfil" id="perfil-novo"><option value="">Selecione</option><option value="gestao">Gestor</option><option value="diretoria">Diretoria</option><option value="administrador">Administrador</option></select></label>' +
                '<div id="bloco-gestor" hidden>' + htmlBlocoGestor("", []) + "</div>" +
                '<p class="inclusao-dica" id="dica-admin" hidden>Empresa e setores são opcionais. Sem setores, este administrador recebe alerta de todas as empresas. Com setores, recebe alerta só deles.</p>'
            : '<p class="resumo">Nenhum usuário ativo da Provita disponível para incluir.</p>';
        const html =
            '<form id="form-incluir-usuarios" class="modal modal-inclusao" role="dialog" aria-modal="true">' +
            '<div class="modal-cabeca"><h2>Adicionar usuário</h2><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="modal-corpo"><p class="resumo resumo-confirma">Busque a pessoa na Provita e escolha se ela entra como gestor, diretoria ou administrador.</p>' +
            campos +
            '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Cancelar</button>' +
            (itens.length ? '<button class="btn btn-primario" type="submit">Adicionar</button>' : '') +
            "</div></div></form>";
        const fundo = mostrarModal(html, false);
        if (itens.length) ligarInclusaoUsuario(fundo, itens.slice());
    }

    function ligarInclusaoUsuario(fundo, itens) {
        const filtro = fundo.querySelector("#filtro-sso");
        const lista = fundo.querySelector("#lista-sso");
        const dica = fundo.querySelector("#dica-sso");
        const campoBusca = fundo.querySelector("#campo-busca");
        const escolhida = fundo.querySelector("#pessoa-escolhida");
        const hidden = fundo.querySelector("#sso-escolhido");
        const campoPerfil = fundo.querySelector("#campo-perfil");
        const perfil = fundo.querySelector("#perfil-novo");
        const bloco = fundo.querySelector("#bloco-gestor");
        ligarSetoresGestor(fundo, []);

        function mostrarLista() {
            const q = semAcento(filtro.value.trim());
            if (q.length < 2) {
                lista.hidden = true;
                lista.innerHTML = "";
                dica.textContent = "Digite ao menos 2 letras do nome ou do usuário.";
                return;
            }
            const achados = itens.filter(function (item) {
                return semAcento(item.nome + " " + item.login + " " + (item.email || "") + " " + (item.celular || "")).indexOf(q) !== -1;
            }).slice(0, 8);
            if (!achados.length) {
                lista.hidden = true;
                lista.innerHTML = "";
                dica.textContent = "Nenhuma pessoa encontrada.";
                return;
            }
            dica.textContent = "Escolha a pessoa na lista.";
            lista.hidden = false;
            lista.innerHTML = achados.map(function (item) {
                const partes = [item.login];
                if (item.email) partes.push(item.email);
                if (item.celular) partes.push(item.celular);
                return '<button type="button" class="opcao-sso" data-id="' + esc(item.sso_id) + '"><strong>' + esc(item.nome) + "</strong><small>" + esc(partes.join(" · ")) + "</small></button>";
            }).join("");
        }

        function limpar() {
            hidden.value = "";
            filtro.value = "";
            lista.hidden = true;
            lista.innerHTML = "";
            escolhida.hidden = true;
            escolhida.innerHTML = "";
            campoBusca.hidden = false;
            dica.hidden = false;
            dica.textContent = "Digite ao menos 2 letras do nome ou do usuário.";
            campoPerfil.hidden = true;
            perfil.value = "";
            bloco.hidden = true;
            const dicaAdmin = document.getElementById("dica-admin");
            if (dicaAdmin) dicaAdmin.hidden = true;
            const empresa = fundo.querySelector('[name="empresa_id"]');
            if (empresa) {
                empresa.value = "";
                empresa.dispatchEvent(new Event("change"));
            }
        }

        function escolher(item) {
            hidden.value = item.sso_id;
            filtro.value = "";
            lista.hidden = true;
            campoBusca.hidden = true;
            escolhida.hidden = false;
            const partes = [item.login];
            if (item.email) partes.push(item.email);
            if (item.celular) partes.push(item.celular);
            escolhida.innerHTML = "<span><strong>" + esc(item.nome) + "</strong><small>" + esc(partes.join(" · ")) + "</small></span>" +
                '<button type="button" class="btn btn-fantasma" id="trocar-pessoa">Trocar</button>';
            campoPerfil.hidden = false;
            perfil.focus();
        }

        filtro.addEventListener("input", mostrarLista);
        filtro.addEventListener("blur", function () {
            window.setTimeout(function () { lista.hidden = true; }, 150);
        });
        filtro.addEventListener("keydown", function (e) {
            if (e.key !== "Enter") return;
            e.preventDefault();
            const botao = lista.querySelector(".opcao-sso");
            if (botao) botao.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
        });
        lista.addEventListener("mousedown", function (e) {
            const botao = e.target.closest(".opcao-sso");
            if (!botao) return;
            e.preventDefault();
            const item = itens.find(function (pessoa) { return String(pessoa.sso_id) === botao.dataset.id; });
            if (item) escolher(item);
        });
        escolhida.addEventListener("click", function (e) {
            if (e.target.closest("#trocar-pessoa")) {
                limpar();
                filtro.focus();
            }
        });
        filtro.focus();
    }

    async function salvarInclusaoUsuarios(form) {
        const id = String(form.querySelector("#sso-escolhido").value || "");
        if (!id) {
            toast("Busque e escolha a pessoa que vai entrar.", "erro");
            return;
        }
        const perfil = String(new FormData(form).get("perfil") || "");
        if (perfil !== "administrador" && perfil !== "diretoria" && perfil !== "gestao") {
            toast("Informe se o usuário é gestor, diretoria ou administrador.", "erro");
            return;
        }
        const empresa = Number(new FormData(form).get("empresa_id") || 0);
        const setores = Array.from(form.querySelectorAll(".selo-setor.ativa")).map(function (botao) { return Number(botao.dataset.id); });
        if (perfil === "gestao" && (!empresa || !setores.length)) {
            toast(!empresa ? "Selecione a empresa." : "Selecione ao menos um setor.", "erro");
            return;
        }
        if (perfil === "administrador" && setores.length && !empresa) {
            toast("Selecione a empresa.", "erro");
            return;
        }
        const botao = form.querySelector('button[type="submit"]');
        travar(botao);
        const comSetores = perfil === "gestao" || (perfil === "administrador" && setores.length > 0);
        await api("usuarios_adicionar", {
            sso_ids: JSON.stringify([id]),
            perfil: perfil,
            empresa_id: comSetores ? empresa : "",
            setor_ids: JSON.stringify(comSetores ? setores : [])
        });
        toast("Usuário incluído.", "ok");
        fecharModal(false);
        renderUsuarios();
    }

    function perfilAmplo() {
        return APP.usuario.perfil === "administrador" || APP.usuario.perfil === "diretoria";
    }

    function rotuloPerfil(perfil) {
        return { gestao: "Gestor", diretoria: "Diretoria", administrador: "Administrador" }[perfil] || "Gestor";
    }

    function opcaoPerfil(valor, rotulo, atual, desabilitada) {
        return '<option value="' + valor + '"' + (atual === valor ? " selected" : "") + (desabilitada ? " disabled" : "") + ">" + rotulo + "</option>";
    }

    function textoOuTraco(valor) {
        const texto = String(valor || "").trim();
        return texto ? esc(texto) : "—";
    }

    function abrirUsuario(id) {
        const u = (estado.usuarios || []).find(function (item) { return Number(item.id) === id; });
        if (!u) return;
        const liberado = Number(u.ativo) === 1 && Number(u.bloqueado) !== 1;
        const admin = APP.usuario.perfil === "administrador";
        const comVinculo = u.perfil === "gestao" || u.perfil === "administrador";
        const promessa = admin && comVinculo ? garantirCatalogo() : Promise.resolve();
        promessa.then(function () {
            const ids = (u.setores || []).map(function (setor) { return setor.id; });
            const semContato = !String(u.email || "").trim() && !String(u.celular || "").trim();
            const contatos = '<dl class="ficha">' +
                "<div><dt>E-mail</dt><dd>" + textoOuTraco(u.email) + "</dd></div>" +
                "<div><dt>Telefone</dt><dd>" + textoOuTraco(u.celular) + "</dd></div>" +
                (comVinculo && !admin ? '<div class="cheio"><dt>Empresa</dt><dd>' + textoOuTraco(u.empresa_nome) + "</dd></div>" : "") +
                "</dl>" +
                (semContato ? '<p class="inclusao-dica">Esta pessoa não tem e-mail nem telefone cadastrados na Provita.</p>' : "");
            const avisoAdmin = u.perfil === "administrador"
                ? '<p class="resumo">Este administrador continua vendo todas as empresas e setores. Sem setores, ele recebe alerta de todas as reclamações. Com setores, recebe alerta só deles.</p>'
                : "";
            const rodape = admin && comVinculo
                ? '<form id="form-vinculo-usuario" class="usuario-edicao"><input type="hidden" name="id" value="' + Number(u.id) + '"><input type="hidden" name="perfil" value="' + esc(u.perfil) + '">' +
                    '<h3>Empresa e setores</h3>' + avisoAdmin +
                    htmlBlocoGestor(u.empresa_id || "", ids) +
                    '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Fechar</button><button class="btn btn-primario" type="submit">Salvar</button></div></form>'
                : '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Fechar</button></div>';
            const html =
                '<div class="modal modal-usuario" role="dialog" aria-modal="true">' +
                '<div class="modal-cabeca"><div class="modal-titulo"><h2>' + esc(u.nome) + "</h2><p>" + esc(u.login) + '</p></div><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
                '<div class="modal-corpo">' +
                '<div class="usuario-selos"><span class="selo">' + esc(rotuloPerfil(u.perfil)) + "</span>" +
                (liberado ? '<span class="selo selo-verde">Ativo</span>' : '<span class="selo selo-vermelho">Inativo</span>') +
                "</div>" +
                contatos +
                rodape + "</div></div>";
            const fundo = mostrarModal(html, false);
            if (admin && comVinculo) ligarSetoresGestor(fundo, ids);
        }).catch(function (err) { toast(err.message, "erro"); });
    }

    async function salvarPerfil(select) {
        const id = Number(select.dataset.id);
        const perfil = String(select.value || "");
        const item = (estado.usuarios || []).find(function (row) { return Number(row.id) === id; });
        const anterior = item ? item.perfil : "";
        if (!perfil || perfil === anterior) return;
        let vinculo = null;
        if (perfil === "gestao" || perfil === "administrador") {
            await garantirCatalogo();
            vinculo = await pedirVinculoGestor(item, perfil === "administrador");
            if (!vinculo) {
                select.value = anterior;
                return;
            }
        }
        const ok = await confirmarEscrito("Você tem certeza que quer alterar o perfil do usuário para " + rotuloPerfil(perfil) + "? Escreva CONFIRMAR se sim.");
        if (!ok) {
            select.value = anterior;
            return;
        }
        const dados = { id: id, perfil: perfil };
        if (vinculo) {
            dados.empresa_id = vinculo.empresa_id;
            dados.setor_ids = JSON.stringify(vinculo.setores);
        }
        await api("usuario_perfil", dados);
        toast("Perfil atualizado.", "ok");
        if (id === Number(APP.usuario.id) && perfil !== APP.usuario.perfil) {
            window.location.reload();
            return;
        }
        renderUsuarios();
    }

    function setoresPorEmpresa(lista, empresaId) {
        if (!empresaId) return lista || [];
        return (lista || []).filter(function (item) {
            return String(item.empresa_id || "") === String(empresaId);
        });
    }

    function opcoesSetor(lista, comEmpresa) {
        return (lista || []).map(function (item) {
            if (!comEmpresa || !item.empresa_nome) return item;
            return Object.assign({}, item, { nome: item.nome + " — " + item.empresa_nome });
        });
    }

    function textoSetorLista(item) {
        const mostrarEmpresa = estado.pagina === "reclamacoes" && perfilAmplo() && !(Number(estado.filtros.empresa_id) > 0) && item.empresa_nome;
        return item.setor_nome + (mostrarEmpresa ? " · " + item.empresa_nome : "");
    }

    function htmlSetoresUsuario(usuario) {
        if (!usuario || usuario.perfil === "diretoria" || (usuario.perfil === "administrador" && !(usuario.setores || []).length)) {
            return '<span class="muted">Todas</span>';
        }
        if (usuario.perfil !== "gestao" && usuario.perfil !== "administrador") {
            return '<span class="muted">—</span>';
        }
        const lista = usuario.setores || [];
        if (!lista.length) return '<span class="muted">Sem setores</span>';
        return '<div class="labels-usuario">' + lista.map(function (setor) {
            return '<span class="selo selo-verde">' + esc(setor.nome) + "</span>";
        }).join("") + "</div>";
    }

    function htmlBlocoGestor(empresaId, ids) {
        const cat = estado.catalogo || { empresas: [] };
        return '<label class="campo"><span>Empresa</span><select name="empresa_id">' + htmlOpcoes(cat.empresas, empresaId, "Selecione", true) + "</select></label>" +
            '<div class="campo"><span>Setores</span><div class="labels-setor"></div></div>';
    }

    function ligarSetoresGestor(raiz, selecionados) {
        const select = raiz.querySelector('[name="empresa_id"]');
        const caixa = raiz.querySelector(".labels-setor");
        if (!select || !caixa) return;
        const marcados = new Set((selecionados || []).map(Number));
        function desenhar() {
            const empresa = Number(select.value);
            if (!empresa) {
                caixa.innerHTML = '<p class="resumo">Selecione a empresa para ver os setores.</p>';
                return;
            }
            const setores = (estado.catalogo.setores || []).filter(function (item) {
                return Number(item.ativo) === 1 && Number(item.empresa_id) === empresa;
            });
            caixa.innerHTML = setores.length
                ? setores.map(function (item) {
                    const ativo = marcados.has(Number(item.id)) ? " ativa" : "";
                    return '<button type="button" class="selo-setor' + ativo + '" data-id="' + Number(item.id) + '">' + esc(item.nome) + "</button>";
                }).join("")
                : '<p class="resumo">Nenhum setor ativo nesta empresa.</p>';
        }
        select.addEventListener("change", function () {
            marcados.clear();
            desenhar();
        });
        caixa.addEventListener("click", function (e) {
            const botao = e.target.closest(".selo-setor");
            if (!botao) return;
            const id = Number(botao.dataset.id);
            if (marcados.has(id)) marcados.delete(id);
            else marcados.add(id);
            botao.classList.toggle("ativa", marcados.has(id));
        });
        desenhar();
    }

    function pedirVinculoGestor(atual, administrador) {
        const ids = ((atual && atual.setores) || []).map(function (setor) { return setor.id; });
        const texto = administrador
            ? "Empresa e setores são opcionais. Sem setores, este administrador recebe alerta de todas as empresas. Com setores, recebe alerta só deles. Na visualização, ele continua vendo todas as empresas e setores."
            : "Escolha a empresa e os setores que este gestor pode acompanhar.";
        return new Promise(function (resolve) {
            resolverVinculo = resolve;
            const html =
                '<form id="form-vinculo-gestor" class="modal" role="dialog" aria-modal="true">' +
                '<div class="modal-cabeca"><h2>Empresa e setores</h2><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
                '<div class="modal-corpo"><p class="resumo resumo-confirma">' + texto + "</p>" +
                '<input type="hidden" name="vinculo_opcional" value="' + (administrador ? "1" : "0") + '">' +
                htmlBlocoGestor(atual && atual.empresa_id ? atual.empresa_id : "", ids) +
                '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Cancelar</button><button class="btn btn-primario" type="submit">Continuar</button></div></div></form>';
            const fundo = mostrarModal(html, false);
            fundo.setAttribute("data-tipo", "vinculo");
            ligarSetoresGestor(fundo, ids);
        });
    }

    async function salvarVinculoUsuario(form) {
        const empresa = Number(new FormData(form).get("empresa_id") || 0);
        const setores = Array.from(form.querySelectorAll(".selo-setor.ativa")).map(function (botao) { return Number(botao.dataset.id); });
        const perfil = String(new FormData(form).get("perfil") || "gestao");
        if (perfil !== "administrador" || setores.length) {
            if (!empresa) {
                toast("Selecione a empresa.", "erro");
                return;
            }
            if (!setores.length) {
                toast("Selecione ao menos um setor.", "erro");
                return;
            }
        }
        const botao = form.querySelector('button[type="submit"]');
        travar(botao);
        await api("usuario_perfil", {
            id: Number(new FormData(form).get("id") || 0),
            perfil: perfil,
            empresa_id: empresa,
            setor_ids: JSON.stringify(setores)
        });
        fecharModal(false);
        toast("Setores atualizados.", "ok");
        renderUsuarios();
    }

    async function sincronizarUsuarios() {
        const res = await api("usuarios_sincronizar");
        if (res.dados && res.dados.eu_inativo) {
            window.location.href = "index.php?aviso=inativo";
            return;
        }
        toast(res.mensagem || "Sincronização concluída.", "ok");
        renderUsuarios();
    }

    async function alterarBloqueio(id, bloqueado) {
        const ok = await confirmar(bloqueado === 1 ? "Inativar o acesso deste usuário?" : "Reativar o acesso deste usuário?");
        if (!ok) return;
        await api("usuario_bloqueio", { id: id, bloqueado: bloqueado });
        toast(bloqueado === 1 ? "Acesso inativado." : "Acesso reativado.", "ok");
        renderUsuarios();
    }

    async function excluirUsuario(id) {
        const ok = await confirmar("Excluir este usuário? Só é possível se ele nunca tiver registrado ou tratado uma reclamação.");
        if (!ok) return;
        await api("usuario_excluir", { id: id });
        toast("Usuário excluído.", "ok");
        renderUsuarios();
    }

    async function renderCadastro(pagina) {
        const cfg = CADASTROS[pagina];
        const res = await api("cadastros_listar", { entidade: cfg.entidade });
        estado.lista = res.dados.itens;
        const textos = {
            empresas: "Empresas que agrupam os setores do formulário. A exclusão só é possível se nenhum setor estiver ligado à empresa.",
            tipos: "Classifique as reclamações. A exclusão só é possível se nenhuma reclamação tiver usado o tipo.",
            setores: "Setores que podem receber uma reclamação. A exclusão só é possível se nenhuma reclamação tiver usado o setor."
        };
        let lista = res.dados.itens;
        if (pagina === "setores") {
            const emp = await api("cadastros_listar", { entidade: "empresas" });
            estado.empresas = emp.dados.itens;
            const empresa = emp.dados.itens.find(function (item) { return Number(item.id) === Number(estado.empresaSetor); });
            if (!empresa) {
                conteudo().innerHTML = '<div class="largura"><div class="aviso aviso-erro">Empresa não encontrada.</div><button type="button" class="btn btn-fantasma" data-acao="voltar-empresas">Voltar</button></div>';
                return;
            }
            const titulo = "Setores de " + empresa.nome;
            document.getElementById("titulo-pagina").textContent = titulo;
            document.title = titulo + " · Canal de Reclamações";
            lista = lista.filter(function (item) { return Number(item.empresa_id) === Number(estado.empresaSetor); });
            textos.setores = "Setores de " + empresa.nome + ". O mesmo nome pode existir em outra empresa. A exclusão só é possível se nenhuma reclamação tiver usado o setor.";
        }
        const ativosAba = estado.abaLista !== "inativos";
        const itens = lista.filter(function (item) { return (Number(item.ativo) === 1) === ativosAba; });
        const acoesIntro = pagina === "setores"
            ? '<div class="grupo-botoes"><button type="button" class="btn btn-fantasma" data-acao="voltar-empresas">Voltar</button><button type="button" class="btn btn-primario" data-acao="novo-cadastro"><i class="fa-solid fa-plus"></i> Novo</button></div>'
            : '<button type="button" class="btn btn-primario" data-acao="novo-cadastro"><i class="fa-solid fa-plus"></i> Novo</button>';
        let html = '<div class="largura"><div class="intro-pagina"><p class="resumo">' + esc(textos[pagina]) + "</p>" + acoesIntro + "</div>";
        html += '<section class="card">' + abasFiltro() ;
        if (!itens.length) html += vazio("fa-folder-open", ativosAba ? "Nenhum item ativo" : "Nenhum item inativo", ativosAba ? "Use o botão Novo para cadastrar." : "Os itens inativados aparecem nesta aba.");
        else {
            html += '<div class="tabela-envoltorio"><table class="tabela tabela-compacta"><thead><tr><th>Nome</th><th></th></tr></thead><tbody>';
            itens.forEach(function (item) {
                const ativo = Number(item.ativo) === 1;
                const nome = esc(item.nome);
                html += "<tr><td>" + nome + "</td>";
                html += '<td><div class="grupo-botoes">';
                if (pagina === "empresas") html += '<button type="button" class="btn btn-fantasma" data-acao="setores-empresa" data-id="' + item.id + '"><i class="fa-solid fa-sitemap"></i> Setores</button>';
                html += '<button type="button" class="btn btn-fantasma btn-ico" data-acao="editar-cadastro" data-id="' + item.id + '" title="Editar"><i class="fa-solid fa-pen"></i></button>';
                html += '<button type="button" class="btn btn-fantasma btn-ico" data-acao="alternar-ativo" data-id="' + item.id + '" data-ativo="' + (ativo ? 0 : 1) + '" title="' + (ativo ? "Inativar" : "Ativar") + '"><i class="fa-solid ' + (ativo ? "fa-ban" : "fa-check") + '"></i></button>';
                if (!item.em_uso) html += '<button type="button" class="btn btn-perigo btn-ico" data-acao="excluir-cadastro" data-id="' + item.id + '" title="Excluir"><i class="fa-solid fa-trash"></i></button>';
                html += "</div></td></tr>";
            });
            html += "</tbody></table></div>";
        }
        html += "</section></div>";
        conteudo().innerHTML = html;
    }

    function abasFiltro() {
        const ativosAba = estado.abaLista !== "inativos";
        return '<div class="abas-filtro" role="tablist">' +
            '<button type="button" class="aba-filtro' + (ativosAba ? " ativa" : "") + '" role="tab" data-acao="aba-lista" data-aba="ativos">Ativos</button>' +
            '<button type="button" class="aba-filtro' + (ativosAba ? "" : " ativa") + '" role="tab" data-acao="aba-lista" data-aba="inativos">Inativos</button>' +
            "</div>";
    }

    function abrirFormCadastro(item) {
        const cfg = CADASTROS[estado.pagina];
        const html =
            '<form id="form-cadastro" class="modal" role="dialog" aria-modal="true">' +
            '<div class="modal-cabeca"><h2>' + (item ? "Editar" : "Novo") + '</h2><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="modal-corpo"><input type="hidden" name="acao" value="cadastro_salvar"><input type="hidden" name="entidade" value="' + cfg.entidade + '"><input type="hidden" name="id" value="' + (item ? item.id : 0) + '"><input type="hidden" name="ativo" value="' + (item ? Number(item.ativo) : 1) + '">' +
            campo("Nome", '<input name="nome" required minlength="2" maxlength="80" value="' + esc(item ? item.nome : "") + '">') +
            (estado.pagina === "setores" ? '<input type="hidden" name="empresa_id" value="' + Number(estado.empresaSetor) + '">' : "") +
            '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Cancelar</button><button class="btn btn-primario" type="submit">Salvar</button></div></div></form>';
        mostrarModal(html, false);
    }

    async function salvarCadastro(form) {
        const botao = form.querySelector('button[type="submit"]');
        travar(botao);
        const fd = new FormData(form);
        await api("cadastro_salvar", {
            entidade: fd.get("entidade"),
            id: fd.get("id"),
            nome: fd.get("nome"),
            ativo: fd.get("ativo") === "0" ? 0 : 1,
            cor: fd.get("cor") || "",
            empresa_id: fd.get("empresa_id") || 0
        });
        estado.catalogo = null;
        fecharModal(false);
        toast("Registro salvo.", "ok");
        renderCadastro(estado.pagina);
    }

    async function alternarAtivo(id, ativo) {
        const cfg = CADASTROS[estado.pagina];
        const item = (estado.lista || []).find(function (row) { return row.id === id; });
        if (!item || !cfg) return;
        await api("cadastro_salvar", { entidade: cfg.entidade, id: id, nome: item.nome, ativo: ativo, cor: item.cor || "", empresa_id: item.empresa_id || 0 });
        estado.catalogo = null;
        toast(ativo === 1 ? "Registro ativado." : "Registro inativado.", "ok");
        renderCadastro(estado.pagina);
    }

    async function excluirCadastro(id) {
        const pergunta = estado.pagina === "empresas"
            ? "Excluir esta empresa? Só é possível se nenhum setor estiver ligado a ela."
            : "Excluir este registro? Só é possível se ele nunca tiver sido usado em uma reclamação.";
        const ok = await confirmar(pergunta);
        if (!ok) return;
        const cfg = CADASTROS[estado.pagina];
        await api("cadastro_excluir", { entidade: cfg.entidade, id: id });
        estado.catalogo = null;
        toast("Registro excluído.", "ok");
        renderCadastro(estado.pagina);
    }

    async function renderStatus() {
        const cat = await garantirCatalogo();
        const ativosAba = estado.abaLista !== "inativos";
        const itens = cat.status.filter(function (item) {
            if (ativosAba) return Number(item.ativo) === 1;
            return Number(item.ativo) !== 1 && !item.fixo;
        });
        let html = '<div class="largura"><div class="intro-pagina"><p class="resumo">O status inicial e o final permanecem no começo e no fim. Os demais são organizados arrastando a linha. Se já houver reclamação nele, ele só pode ser inativado.</p><button type="button" class="btn btn-primario" data-acao="novo-status"><i class="fa-solid fa-plus"></i> Novo status</button></div>';
        html += '<section class="card">' + abasFiltro();
        if (!itens.length) html += vazio("fa-list", ativosAba ? "Nenhum status ativo" : "Nenhum status inativo", ativosAba ? "Use o botão Novo status para cadastrar." : "Os status inativados aparecem nesta aba.");
        else {
            html += '<div class="tabela-envoltorio"><table class="tabela tabela-compacta tabela-status"><thead><tr><th>Nome</th><th>Posição</th><th></th></tr></thead><tbody id="lista-status">';
            itens.forEach(function (item) {
                const posicao = item.fixo === "inicial" ? "Inicial" : (item.fixo === "final" ? "Final" : "Intermediário");
                const arrasta = !item.fixo;
                html += '<tr' + (item.fixo ? ' data-fixo="' + item.fixo + '"' : ' data-id="' + item.id + '"') + (arrasta ? ' draggable="true"' : "") + ">";
                html += '<td><div class="linha-status">' + (arrasta ? '<i class="fa-solid fa-grip-vertical alca-arraste" title="Arrastar"></i>' : '<span class="alca-arraste"></span>') + seloStatus(item.nome, item.fixo, item.cor) + "</div></td>";
                html += "<td>" + posicao + '</td><td><div class="grupo-botoes">';
                html += '<button type="button" class="btn btn-fantasma btn-ico" data-acao="editar-status" data-id="' + item.id + '" title="Editar"><i class="fa-solid fa-pen"></i></button>';
                if (!item.fixo) {
                    const ativo = Number(item.ativo) === 1;
                    html += '<button type="button" class="btn btn-fantasma btn-ico" data-acao="inativar-status" data-id="' + item.id + '" data-ativo="' + (ativo ? 0 : 1) + '" title="' + (ativo ? "Inativar" : "Ativar") + '"><i class="fa-solid ' + (ativo ? "fa-ban" : "fa-check") + '"></i></button>';
                    if (!item.em_uso) html += '<button type="button" class="btn btn-perigo btn-ico" data-acao="excluir-status" data-id="' + item.id + '" title="Excluir"><i class="fa-solid fa-trash"></i></button>';
                }
                html += "</div></td></tr>";
            });
            html += "</tbody></table></div>";
        }
        html += "</section></div>";
        conteudo().innerHTML = html;
        ligarArrasteStatus(document.getElementById("lista-status"));
    }

    function ligarArrasteStatus(tbody) {
        if (!tbody) return;
        let origem = null;
        let soltou = false;
        let antes = [];
        tbody.addEventListener("dragstart", function (e) {
            const linha = e.target.closest("tr[draggable='true']");
            if (!linha || e.target.closest("button, a, input, select")) {
                e.preventDefault();
                return;
            }
            origem = linha;
            soltou = false;
            antes = Array.from(tbody.children);
            linha.classList.add("arrastando");
            e.dataTransfer.effectAllowed = "move";
            e.dataTransfer.setData("text/plain", linha.dataset.id || "");
        });
        tbody.addEventListener("dragover", function (e) {
            const linha = e.target.closest("tr");
            if (!origem || !linha || linha === origem) return;
            e.preventDefault();
            if (linha.dataset.fixo === "inicial") {
                const primeiro = tbody.querySelector("tr[draggable='true']");
                if (primeiro && primeiro !== origem) primeiro.before(origem);
                return;
            }
            if (linha.dataset.fixo === "final") {
                linha.before(origem);
                return;
            }
            const rect = linha.getBoundingClientRect();
            if (e.clientY > rect.top + rect.height / 2) linha.after(origem);
            else linha.before(origem);
        });
        tbody.addEventListener("drop", function (e) {
            e.preventDefault();
            soltou = true;
            const ids = Array.from(tbody.querySelectorAll("tr[draggable='true']")).map(function (tr) { return tr.dataset.id; });
            const original = antes.filter(function (tr) { return tr.dataset.id; }).map(function (tr) { return tr.dataset.id; });
            if (ids.join(",") === original.join(",")) return;
            api("status_ordenar", { ids: JSON.stringify(ids.map(Number)) }).then(function () {
                estado.catalogo = null;
                return renderStatus();
            }).catch(function (err) {
                toast(err.message || "Não foi possível reordenar.", "erro");
                renderStatus();
            });
        });
        tbody.addEventListener("dragend", function () {
            if (origem) origem.classList.remove("arrastando");
            if (!soltou && antes.length) antes.forEach(function (tr) { tbody.appendChild(tr); });
            origem = null;
        });
    }

    function bolinhasCor(selecionada) {
        const atual = CORES_STATUS.indexOf(selecionada) === -1 ? CORES_STATUS[5] : selecionada;
        let html = '<div class="cores"><span>Cor</span><div class="bolinhas">';
        html += '<input type="hidden" name="cor" value="' + atual + '">';
        CORES_STATUS.forEach(function (cor) {
            html += '<button type="button" class="bolinha' + (cor === atual ? " selecionada" : "") + '" data-acao="escolher-cor" data-cor="' + cor + '" style="background:' + cor + '" aria-label="Cor ' + cor + '"></button>';
        });
        return html + "</div></div>";
    }

    function abrirFormStatus(item) {
        const html =
            '<form id="form-status" class="modal" role="dialog" aria-modal="true">' +
            '<div class="modal-cabeca"><h2>' + (item ? "Editar status" : "Novo status") + '</h2><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
            '<div class="modal-corpo"><input type="hidden" name="id" value="' + (item ? item.id : 0) + '">' +
            '<input type="hidden" name="ativo" value="' + (item ? Number(item.ativo) : 1) + '">' +
            campo("Nome", '<input name="nome" required minlength="2" maxlength="60" value="' + esc(item ? item.nome : "") + '">') +
            bolinhasCor(item && item.cor ? item.cor : "") +
            '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Cancelar</button><button class="btn btn-primario" type="submit">Salvar</button></div></div></form>';
        mostrarModal(html, false);
    }

    async function salvarStatus(form) {
        const botao = form.querySelector('button[type="submit"]');
        travar(botao);
        const fd = new FormData(form);
        await api("status_salvar", { id: fd.get("id"), nome: fd.get("nome"), ativo: fd.get("ativo") === "1" ? 1 : 0, cor: fd.get("cor") });
        estado.catalogo = null;
        fecharModal(false);
        toast("Status salvo.", "ok");
        renderStatus();
    }

    function perguntarDestinoStatus(nome, quantidade, destinos) {
        const opcoes = (destinos || []).map(function (item) {
            return '<option value="' + Number(item.id) + '">' + esc(item.nome) + "</option>";
        }).join("");
        return new Promise(function (resolve) {
            resolverConfirmacao = resolve;
            const fundo = mostrarModal(
                '<form id="form-destino-status" class="modal" role="dialog" aria-modal="true">' +
                '<div class="modal-cabeca"><h2>Inativar status</h2><button type="button" class="fechar" data-acao="fechar-modal" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>' +
                '<div class="modal-corpo"><p>O status ' + esc(nome) + " tem " + quantidade + (quantidade === 1 ? " reclamação" : " reclamações") + ". Escolha para onde elas serão alocadas antes de inativar.</p>" +
                '<label class="campo" style="margin-top:12px"><span>Novo status</span><select name="destino_id" required><option value="">Selecione</option>' + opcoes + "</select></label>" +
                '<div class="grupo-botoes" style="margin-top:16px"><button type="button" class="btn btn-fantasma" data-acao="fechar-modal">Cancelar</button><button class="btn btn-primario" type="submit">Inativar</button></div></div></form>'
            );
            fundo.setAttribute("data-tipo", "destino");
        });
    }

    async function inativarStatus(id, ativo) {
        const item = (estado.catalogo.status || []).find(function (row) { return row.id === id; });
        if (!item || item.fixo) return;
        if (ativo === 1) {
            await api("status_inativar", { id: id, ativo: 1, destino_id: 0 });
            estado.catalogo = null;
            toast("Status ativado.", "ok");
            renderStatus();
            return;
        }
        const uso = await api("status_uso", { id: id });
        const quantidade = Number(uso.dados.quantidade) || 0;
        let destino = 0;
        if (quantidade > 0) {
            const destinos = uso.dados.destinos || [];
            if (!destinos.length) {
                toast("Não há outro status ativo para receber as reclamações.", "erro");
                return;
            }
            destino = await perguntarDestinoStatus(item.nome, quantidade, destinos);
            if (!destino) return;
        } else {
            const ok = await confirmar("Inativar este status? Ele deixa de aparecer para novas reclamações.");
            if (!ok) return;
        }
        await api("status_inativar", { id: id, ativo: 0, destino_id: destino });
        estado.catalogo = null;
        toast("Status inativado.", "ok");
        renderStatus();
    }

    async function excluirStatus(id) {
        const ok = await confirmar("Excluir este status? Isso só é possível se nenhuma reclamação o estiver usando.");
        if (!ok) return;
        await api("status_excluir", { id: id });
        estado.catalogo = null;
        toast("Status excluído.", "ok");
        renderStatus();
    }

    async function baixarCsv() {
        const fd = new FormData();
        fd.append("acao", "relatorio_csv");
        fd.append("csrf", csrf());
        const filtros = estado.relatorio;
        Object.keys(filtros).forEach(function (chave) {
            if (filtros[chave] !== undefined && filtros[chave] !== null) fd.append(chave, String(filtros[chave]));
        });
        const res = await fetch("api.php", { method: "POST", body: fd });
        const tipo = res.headers.get("Content-Type") || "";
        if (tipo.indexOf("application/json") !== -1 || !res.ok) {
            const json = await res.json().catch(function () { return null; });
            if (res.status === 401) window.location.href = "index.php?aviso=expirada";
            throw new Error((json && json.mensagem) || "Não foi possível exportar.");
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = "reclamacoes.csv";
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
})();
