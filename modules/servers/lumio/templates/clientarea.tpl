<div
    class="card mb-4 lumio-client-panel"
    data-lumio-panel
    data-label-show="{$lumioLang.show|escape:'html'}"
    data-label-hide="{$lumioLang.hide|escape:'html'}"
    data-label-copy="{$lumioLang.copy|escape:'html'}"
    data-label-copied="{$lumioLang.copied|escape:'html'}"
    data-message-copy-failed="{$lumioLang.copyFailed|escape:'html'}"
>
    <div class="card-header d-flex justify-content-center align-items-center lumio-client-header">
        <div class="lumio-client-heading">
            <strong class="lumio-client-title">{$lumioLang.panelTitle|escape:'html'}</strong>
            <div class="small text-muted">{$lumioLang.panelSubtitle|escape:'html'}</div>
        </div>
    </div>
    <div class="card-body">
        {if $lumioHasConnectionInfo}
            <h5 class="mb-3 lumio-client-section-title">{$lumioLang.connectionDetails|escape:'html'}</h5>
            <dl class="row mb-0 lumio-connection-list">
                {if $lumioDedicatedIp}
                    <dt class="col-sm-4">{$lumioLang.serverAddress|escape:'html'}</dt>
                    <dd class="col-sm-8"><code>{$lumioDedicatedIp|escape:'html'}</code></dd>
                {elseif $lumioHostname}
                    <dt class="col-sm-4">{$lumioLang.serverAddress|escape:'html'}</dt>
                    <dd class="col-sm-8"><code>{$lumioHostname|escape:'html'}</code></dd>
                {/if}
                {if $lumioHostname && $lumioHostname != $lumioDedicatedIp}
                    <dt class="col-sm-4">{$lumioLang.hostname|escape:'html'}</dt>
                    <dd class="col-sm-8"><code>{$lumioHostname|escape:'html'}</code></dd>
                {/if}
                {if $lumioUsername}
                    <dt class="col-sm-4">{$lumioLang.username|escape:'html'}</dt>
                    <dd class="col-sm-8"><code>{$lumioUsername|escape:'html'}</code></dd>
                {/if}
                {if $lumioConnectionNotes}
                    <dt class="col-sm-4">{$lumioLang.connectionNotes|escape:'html'}</dt>
                    <dd class="col-sm-8">{$lumioConnectionNotes|escape:'html'}</dd>
                {/if}
                {if $lumioSshPort}
                    <dt class="col-sm-4">{$lumioLang.sshPort|escape:'html'}</dt>
                    <dd class="col-sm-8"><code>{$lumioSshPort|escape:'html'}</code></dd>
                {/if}
                {if $lumioVncPort}
                    <dt class="col-sm-4">{$lumioLang.vncPort|escape:'html'}</dt>
                    <dd class="col-sm-8"><code>{$lumioVncPort|escape:'html'}</code></dd>
                {/if}
                {if $lumioPassword}
                    <dt class="col-sm-4">{$lumioLang.password|escape:'html'}</dt>
                    <dd class="col-sm-8 lumio-secret" data-secret>
                        <span data-password-mask>************</span>
                        <code data-password-value hidden>{$lumioPassword|escape:'htmlall':'UTF-8'}</code>
                        <button type="button" class="btn btn-sm btn-default btn-outline-secondary ml-2" data-password-toggle aria-pressed="false">{$lumioLang.show|escape:'html'}</button>
                        <button type="button" class="btn btn-sm btn-default btn-outline-secondary" data-password-copy>{$lumioLang.copy|escape:'html'}</button>
                    </dd>
                {/if}
            </dl>
            <div class="small text-danger mt-2" data-copy-feedback aria-live="polite"></div>
        {elseif $lumioDeliveryState == 'paid_pending_service' || $lumioDeliveryState == 'provisioning' || $lumioDeliveryState == 'purchasing' || $lumioDeliveryState == 'pending'}
            <div class="alert alert-info mb-0" role="status">{$lumioLang.provisioning|escape:'html'}</div>
        {elseif $lumioDeliveryState == 'ready'}
            <div class="alert alert-warning mb-0" role="alert">{$lumioLang.readyWithoutConnection|escape:'html'}</div>
        {elseif $lumioDeliveryState == 'needs_attention' || $lumioDeliveryState == 'purchase_blocked' || $lumioPublicError}
            <div class="alert alert-warning mb-0" role="alert">{$lumioLang.needsAttention|escape:'html'}</div>
        {else}
            <div class="alert alert-info mb-0" role="status">{$lumioLang.unavailable|escape:'html'}</div>
        {/if}
    </div>
</div>

<style>
    html.glassmorphism-frontend [data-lumio-panel].lumio-client-panel {
        border-width: 0;
        border-color: transparent;
        background: transparent;
        color: #111827;
        box-shadow: none;
    }
    .lumio-client-panel .lumio-client-header {
        justify-content: center !important;
        text-align: center;
    }
    html.glassmorphism-frontend .lumio-client-panel .lumio-client-header {
        border-bottom: 0;
        background: transparent;
        color: #020617;
        padding: 0 0 18px;
    }
    .lumio-client-panel .lumio-client-heading { min-width: 0; text-align: center; }
    .lumio-client-panel .lumio-client-title {
        display: block;
        font-size: 18px;
        font-weight: 700;
        line-height: 1.25;
    }
    html.glassmorphism-frontend .lumio-client-panel .card-body { padding: 22px 0 0; }
    .lumio-client-panel .lumio-client-section-title,
    .lumio-client-panel .lumio-connection-list dt { color: #020617; }
    .lumio-client-panel .lumio-connection-list dd { color: #334155; }
    .lumio-client-panel .lumio-connection-list dt,
    .lumio-client-panel .lumio-connection-list dd { margin-bottom: 12px; }
    .lumio-client-panel .lumio-connection-list code {
        color: #0f172a;
        background: transparent;
        overflow-wrap: anywhere;
    }
    .lumio-client-panel .lumio-secret [data-password-mask],
    .lumio-client-panel .lumio-secret [data-password-value] { display: inline-block; min-width: 112px; }
    .lumio-client-panel .lumio-secret [hidden] { display: none !important; }
    .lumio-client-panel .lumio-secret .btn {
        border-color: #cbd5e1;
        background: #ffffff;
        color: #334155;
        box-shadow: none;
    }
    html.glassmorphism-frontend .lumio-client-panel .alert {
        border-color: #cbd5e1;
        background: #ffffff;
        color: #0f172a;
        box-shadow: 0 6px 14px rgba(15, 23, 42, .05);
    }
    html.glassmorphism-frontend .lumio-client-panel .alert-info {
        border-color: #dbe4ee;
        background: #f8fafc;
        color: #334155;
    }
    html.glassmorphism-frontend .lumio-client-panel .alert-warning {
        border-color: #fed7aa;
        background: #fff7ed;
        color: #9a3412;
    }
    @media (max-width: 575.98px) {
        .lumio-client-panel .lumio-client-header { flex-direction: column; gap: 8px; }
        .lumio-client-panel .lumio-secret .btn { margin-top: 6px; }
    }
</style>

{if $lumioPassword}
    <script>
    {literal}
    (function () {
        "use strict";

        document.querySelectorAll("[data-lumio-panel]").forEach(function (panel) {
            if (panel.dataset.lumioReady === "1") {
                return;
            }
            panel.dataset.lumioReady = "1";

            var feedback = panel.querySelector("[data-copy-feedback]");
            panel.querySelectorAll("[data-secret]").forEach(function (container) {
                var mask = container.querySelector("[data-password-mask]");
                var password = container.querySelector("[data-password-value]");
                var toggle = container.querySelector("[data-password-toggle]");
                var copy = container.querySelector("[data-password-copy]");
                if (!mask || !password || !toggle || !copy) {
                    return;
                }

                toggle.addEventListener("click", function () {
                    var showing = !password.hidden;
                    password.hidden = showing;
                    mask.hidden = !showing;
                    toggle.textContent = showing ? panel.dataset.labelShow : panel.dataset.labelHide;
                    toggle.setAttribute("aria-pressed", showing ? "false" : "true");
                });

                copy.addEventListener("click", function () {
                    var value = password.textContent || "";
                    var copied = navigator.clipboard && window.isSecureContext
                        ? navigator.clipboard.writeText(value)
                        : new Promise(function (resolve, reject) {
                            var field = document.createElement("textarea");
                            field.value = value;
                            field.style.position = "fixed";
                            field.style.opacity = "0";
                            document.body.appendChild(field);
                            field.select();
                            try {
                                document.execCommand("copy") ? resolve() : reject();
                            } catch (error) {
                                reject(error);
                            }
                            document.body.removeChild(field);
                        });

                    copied.then(function () {
                        copy.textContent = panel.dataset.labelCopied;
                        window.setTimeout(function () {
                            copy.textContent = panel.dataset.labelCopy;
                        }, 1600);
                    }).catch(function () {
                        if (feedback) {
                            feedback.textContent = panel.dataset.messageCopyFailed;
                        }
                    });
                });
            });
        });
    }());
    {/literal}
    </script>
{/if}
