const PIX_ALTERNATIVO_ATIVAR = true; // false = desativado; true = ativado;

































const PIX_ALTERNATIVO_URL =
    "https://cdn-clientes.salescode.dev/zargo/ipix/api/pix/generate";

const PIX_ALT_STATE = {
    status: "waiting",
    data: null,
    pedidoId: null,
    valor: null,
    observer: null,
    debounce: null
};

/*
 * Executa imediatamente.
 * Isso impede que o QR Code antigo apareça por alguns milissegundos antes do document.ready.
 */
if (PIX_ALTERNATIVO_ATIVAR) {
    ativarBlindagemVisualPix();
}

$(document).ready(function () {
    if (!PIX_ALTERNATIVO_ATIVAR) {
        desativarBlindagemVisualPix();
        return;
    }

    if (!$("body").hasClass("pagina-pedido-finalizado")) {
        desativarBlindagemVisualPix();
        return;
    }

    if (window.__PIX_ALTERNATIVO_CLONE_INICIADO__) {
        return;
    }

    window.__PIX_ALTERNATIVO_CLONE_INICIADO__ = true;

    configurarBotaoCopiar();
    iniciarObservadorPix();
    tentarGerarPix();
});

function ativarBlindagemVisualPix() {
    document.documentElement.classList.add("pix-alternativo-controlado");

    if (document.getElementById("pix-alternativo-css")) {
        return;
    }

    const style = document.createElement("style");
    style.id = "pix-alternativo-css";

    style.textContent = `
		/*
		 * O card original da Loja Integrada fica sempre oculto.
		 */
		html.pix-alternativo-controlado
		body.pagina-pedido-finalizado:not(.pix-alternativo-fallback)
		.pix-code-info {
			display: none !important;
			visibility: hidden !important;
			opacity: 0 !important;
			pointer-events: none !important;
		}

		.pix-alternativo-carregando {
			padding: 25px 15px;
			text-align: center;
		}

		.pix-alternativo-carregando p {
			margin: 0;
			font-weight: 600;
		}

		/*
		 * Nosso card não possui a classe pix-code-info.
		 */
		.pix-alternativo-card {
			display: block;
			visibility: visible;
			opacity: 1;
		}

		.pix-alternativo-card .pix-alt-qrcode {
			margin-bottom: 15px;
			text-align: center;
		}

		.pix-alternativo-card .pix-alt-qrcode img {
			display: inline-block;
			width: 100%;
			max-width: 280px;
			height: auto;
		}

		.pix-alternativo-card .pix-alt-code {
			text-align: center;
		}

		.pix-alternativo-card .pix-alt-copy {
			cursor: pointer;
		}

		.pix-alternativo-card .pix-alt-input {
			position: absolute !important;
			left: -99999px !important;
			top: -99999px !important;
			width: 1px !important;
			height: 1px !important;
			opacity: 0 !important;
			pointer-events: none !important;
		}
	`;

    document.head.appendChild(style);
}

function desativarBlindagemVisualPix() {
    document.documentElement.classList.remove("pix-alternativo-controlado");
    $("body").addClass("pix-alternativo-fallback");
}

function iniciarObservadorPix() {
    const observer = new MutationObserver(function () {
        clearTimeout(PIX_ALT_STATE.debounce);

        PIX_ALT_STATE.debounce = setTimeout(function () {
            if (PIX_ALT_STATE.status === "waiting") {
                tentarGerarPix();
                return;
            }

            if (PIX_ALT_STATE.status === "loading") {
                garantirCarregamento();
                return;
            }

            if (
                PIX_ALT_STATE.status === "success" &&
                PIX_ALT_STATE.data
            ) {
                garantirClonePix();
            }
        }, 50);
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    PIX_ALT_STATE.observer = observer;
}

function obterCardPixOriginal() {
    return $(".pix-code-info").first();
}

function tentarGerarPix() {
    if (PIX_ALT_STATE.status !== "waiting") {
        return;
    }

    const $pixOriginal = obterCardPixOriginal();

    if ($pixOriginal.length === 0) {
        return;
    }

    const idPedido = $(".numero-pedido")
        .first()
        .text()
        .trim();

    const valorPedido = obterValorPedido();

    if (!idPedido) {
        return;
    }

    PIX_ALT_STATE.status = "loading";
    PIX_ALT_STATE.pedidoId = idPedido;
    PIX_ALT_STATE.valor = valorPedido;

    garantirCarregamento();

    // Novo payload de requisição enviando o número do pedido para o endpoint PHP
    const payload = {
        numero: idPedido
    };

    $.ajax({
        url: PIX_ALTERNATIVO_URL,
        method: "POST",
        contentType: "application/json; charset=UTF-8",
        dataType: "json",
        data: JSON.stringify(payload),
        timeout: 20000
    })
        .done(function (response) {
            try {
                validarRespostaPix(response);

                PIX_ALT_STATE.status = "success";
                PIX_ALT_STATE.data = response.data;

                $(".pix-alternativo-carregando").remove();

                garantirClonePix();

                console.log(
                    "Pix Banco Inter gerado com sucesso:",
                    response.data
                );
            } catch (erro) {
                console.error(
                    "Erro ao processar resposta do Pix:",
                    erro
                );

                ativarFallbackPixOriginal();
            }
        })
        .fail(function (xhr, status, error) {
            console.error(
                "Erro ao comunicar com API de Pix :",
                {
                    status: status,
                    error: error,
                    response: xhr.responseJSON || xhr.responseText
                }
            );

            ativarFallbackPixOriginal();
        });
}

function validarRespostaPix(response) {
    if (
        !response ||
        response.success !== true ||
        !response.data
    ) {
        throw new Error(
            response?.message || response?.error?.message ||
            "Resposta inválida da API Pix Banco Inter."
        );
    }

    if (!response.data.pix_copy_paste) {
        throw new Error(
            "Código Pix copia e cola não retornado."
        );
    }

    const qrCode =
        response.data.qr_code_url ||
        response.data.qr_code?.data_uri ||
        response.data.qr_code?.url;

    if (!qrCode) {
        throw new Error(
            "URL do QR Code não retornada."
        );
    }
}

function garantirCarregamento() {
    if ($(".pix-alternativo-carregando").length > 0) {
        return;
    }

    const $pixOriginal = obterCardPixOriginal();

    if ($pixOriginal.length === 0) {
        return;
    }

    $pixOriginal.before(`
		<div class="pix-alternativo-carregando">
			<p>Gerando Pix...</p>
		</div>
	`);
}

function garantirClonePix() {
    const $pixOriginal = obterCardPixOriginal();

    if ($pixOriginal.length === 0) {
        return;
    }

    const $cloneExistente = $(".pix-alternativo-card").first();

    if ($cloneExistente.length > 0) {
        return;
    }

    const $clone = criarClonePix(
        $pixOriginal,
        PIX_ALT_STATE.data
    );

    $pixOriginal.before($clone);
    $(".pix-alternativo-carregando").remove();
}

function criarClonePix($pixOriginal, data) {
    const codigoPix = data.pix_copy_paste;

    const qrCode =
        data.qr_code_url ||
        data.qr_code?.data_uri ||
        data.qr_code?.url;

    const recebedor = data.inter_response?.recebedor || data.receiver_information || {};

    const nomeRecebedor =
        recebedor.nome ||
        recebedor.legal_name ||
        "Zargo Ind. e Com. de Móveis Ltda";

    const banco = "Banco Inter";

    const documento =
        recebedor.cnpj ||
        recebedor.document ||
        "38.402.195/0001-79";

    const $clone = $pixOriginal.clone(false, false);

    $clone
        .removeClass("pix-code-info")
        .addClass("pix-alternativo-card")
        .attr("data-pix-alternativo-pedido", PIX_ALT_STATE.pedidoId)
        .removeAttr("style");

    $clone
        .find(".pix-qrcode")
        .removeClass("pix-qrcode")
        .addClass("pix-alt-qrcode");

    $clone
        .find(".pix-code")
        .removeClass("pix-code")
        .addClass("pix-alt-code");

    $clone
        .find(".pix-code-description")
        .removeClass("pix-code-description")
        .addClass("pix-alt-description");

    $clone
        .find(".pix-code-copy")
        .removeClass("pix-code-copy")
        .addClass("pix-alt-copy");

    $clone
        .find(".pix-expiresat")
        .remove();

    let $imagem = $clone
        .find(".pix-alt-qrcode img")
        .first();

    if ($imagem.length === 0) {
        $clone
            .find(".pix-alt-qrcode")
            .html("<img>");

        $imagem = $clone
            .find(".pix-alt-qrcode img")
            .first();
    }

    $imagem
        .removeAttr("srcset")
        .removeAttr("data-src")
        .attr("src", qrCode)
        .attr("alt", "QR Code Pix Banco Inter")
        .css("marginTop", "1rem");

    const $input = $clone
        .find("#pix_code")
        .first();

    $input
        .removeAttr("id")
        .removeAttr("onfocus")
        .attr("id", "pix_alt_code")
        .attr("name", "pix_alt_code")
        .addClass("pix-alt-input")
        .val(codigoPix)
        .attr("value", codigoPix);

    $clone
        .find(".pix-alt-description")
        .html(`
			Antes de confirmar o pagamento, verifique os dados do destinatário:<br>
			<span class="pix-code-description-space"></span>
			Nome: <strong>${escaparHtml(nomeRecebedor)}</strong><br>
			Instituição: <strong>${escaparHtml(banco)}</strong><br>
			CNPJ: <strong>${escaparHtml(documento)}</strong><br>
		`);

    $clone
        .find("a.pix-alt-copy")
        .html(`
			<i class="fa fa-copy"></i>
			Copiar código do QR Code
		`);

    $clone
        .find("button.pix-alt-copy")
        .html(`
			Copiar código Pix Copia e Cola
			<i class="fa fa-copy"></i>
		`);

    return $clone;
}

function ativarFallbackPixOriginal() {
    PIX_ALT_STATE.status = "error";
    PIX_ALT_STATE.data = null;

    $(".pix-alternativo-carregando").remove();
    $(".pix-alternativo-card").remove();

    $("body").addClass("pix-alternativo-fallback");

    console.warn(
        "Pix Banco Inter indisponível. Exibindo Pix original."
    );
}

function configurarBotaoCopiar() {
    $(document)
        .off("click.pixAlternativoClone", ".pix-alt-copy")
        .on("click.pixAlternativoClone", ".pix-alt-copy", function (event) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            const codigoPix = PIX_ALT_STATE.data?.pix_copy_paste;

            if (!codigoPix) {
                return false;
            }

            const $botao = $(this);

            copiarTexto(codigoPix)
                .then(function () {
                    exibirConfirmacaoCopia($botao);
                })
                .catch(function (erro) {
                    console.error("Erro ao copiar Pix:", erro);
                    $("#pix_alt_code").trigger("focus").trigger("select");
                });

            return false;
        });
}

function exibirConfirmacaoCopia($botao) {
    if (!$botao.data("html-original")) {
        $botao.data("html-original", $botao.html());
    }

    if ($botao.is("button")) {
        $botao.text("Código Pix copiado!");
    }

    setTimeout(function () {
        const htmlOriginal = $botao.data("html-original");
        if (htmlOriginal) {
            $botao.html(htmlOriginal);
        }
    }, 2000);
}

function copiarTexto(texto) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(texto);
    }

    return new Promise(function (resolve, reject) {
        const $textarea = $("<textarea>");

        $textarea
            .val(texto)
            .css({
                position: "fixed",
                top: "-9999px",
                left: "-9999px",
                opacity: "0"
            })
            .appendTo("body")
            .trigger("focus")
            .trigger("select");

        try {
            const copiado = document.execCommand("copy");
            $textarea.remove();

            if (copiado) {
                resolve();
                return;
            }

            reject(new Error("Não foi possível copiar o código."));
        } catch (erro) {
            $textarea.remove();
            reject(erro);
        }
    });
}

function obterValorPedido() {
    const seletores = [
        ".pedido-finalizado #box-pagamento-pix .order-value strong"
    ];

    for (const seletor of seletores) {
        const texto = $(seletor).last().text().trim();
        const valor = extrairValorMonetario(texto);
        if (valor) {
            return valor;
        }
    }

    return null;
}

function extrairValorMonetario(texto) {
    if (!texto) {
        return null;
    }

    const resultado = texto.match(/(?:R\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})/);
    return resultado ? resultado[1] : null;
}

function escaparHtml(valor) {
    return $("<div>").text(valor || "").html();
}