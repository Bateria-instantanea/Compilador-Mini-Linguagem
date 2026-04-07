<?php
require_once __DIR__ . '/lexer.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/semantic.php';
require_once __DIR__ . '/interpreter.php';

// ── Pipeline completo ─────────────────────────────────────────
$resultado = [
    'tokens'   => [],
    'ast'      => null,
    'semantica'=> null,
    'execucao' => null,
    'erros'    => [],
    'fase'     => '',   // até onde chegou sem erros
];

$codigoPostado = isset($_POST['codigo']) ? $_POST['codigo'] : '';
$inputsForm    = [];

if ($codigoPostado !== '') {

    // Coleta inputs do formulário
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'inp_') === 0) {
            $inputsForm[substr($k, 4)] = (int)$v;
        }
    }

    try {
        // ── 1. LÉXICA ────────────────────────────────────────
        $lexer  = new Lexer();
        $tokens = $lexer->tokenizar($codigoPostado);
        $resultado['tokens'] = $tokens;
        $resultado['fase']   = 'lexica';

        // ── 2. SINTÁTICA ─────────────────────────────────────
        $parser = new Parser($lexer->getCmpMap(), $lexer->getOpMap(), $lexer->getLogMap());
        $ast    = $parser->parse($tokens);
        $resultado['ast']  = $ast;
        $resultado['fase'] = 'sintatica';

        // ── 3. SEMÂNTICA ─────────────────────────────────────
        $sem    = new AnalisadorSemantico();
        $semRes = $sem->analisar($ast);
        $resultado['semantica'] = $semRes;
        $resultado['fase']      = 'semantica';

        if (!$semRes['valido']) {
            foreach ($semRes['erros'] as $e) {
                $resultado['erros'][] = ['fase' => 'Semântica', 'msg' => $e['msg'], 'linha' => $e['linha']];
            }
        }

        // ── 4. EXECUÇÃO ──────────────────────────────────────
        if ($semRes['valido']) {
            $interp = new Interpretador($inputsForm);
            $exec   = $interp->executar($ast);
            $resultado['execucao'] = $exec;
            $resultado['fase']     = 'execucao';
        }

    } catch (MiniLexerError $e) {
        $resultado['erros'][] = ['fase' => 'Léxica', 'msg' => $e->getMessage(), 'linha' => $e->linhaCodigo];
    } catch (MiniParseError $e) {
        $resultado['erros'][] = ['fase' => 'Sintática', 'msg' => $e->getMessage(), 'linha' => $e->linhaCodigo];
    } catch (MiniSemanticError $e) {
        $resultado['erros'][] = ['fase' => 'Semântica', 'msg' => $e->getMessage(), 'linha' => $e->linhaCodigo];
    } catch (MiniRuntimeError $e) {
        $resultado['erros'][] = ['fase' => 'Execução', 'msg' => $e->getMessage(), 'linha' => $e->linhaCodigo];
    } catch (Exception $e) {
        $resultado['erros'][] = ['fase' => 'Geral', 'msg' => $e->getMessage(), 'linha' => 0];
    }
}

// ── Detecta variáveis de INPUT no código (para gerar campos) ─
$inputVarsDetectadas = [];
if ($codigoPostado !== '') {
    $linhasRaw = preg_split('/;/', str_replace(["\r\n","\n"], " ", $codigoPostado));
    foreach ($linhasRaw as $lr) {
        $lr = trim($lr);
        if (preg_match('/^xec\s+(n[aeiouzxcvb]*)$/', $lr, $m)) {
            $inputVarsDetectadas[] = $m[1];
        }
    }
}

// ── Helper para exibir AST como HTML ─────────────────────────
function astHtml(array $no, int $depth = 0): string {
    $pad = str_repeat('  ', $depth);
    $tipo = $no['no'];
    $cores = [
        'programa'=>'#7c5cfc','atrib'=>'#00e5ff','print'=>'#00ff9d',
        'input'=>'#ffaa00','if'=>'#ff6b6b','while'=>'#ff9f43',
        'binop'=>'#a29bfe','cmp'=>'#fd79a8','log'=>'#55efc4',
        'var'=>'#74b9ff','num'=>'#ffeaa7',
    ];
    $cor = $cores[$tipo] ?? '#ccc';

    $badge = "<span style='background:rgba(255,255,255,.08);color:$cor;padding:1px 7px;border-radius:4px;font-size:.75rem;font-family:monospace;font-weight:700'>$tipo</span>";

    $info = match($tipo) {
        'var'      => " <span style='color:#74b9ff'>{$no['nome']}</span>",
        'num'      => " <span style='color:#ffeaa7'>{$no['valor']}</span>",
        'binop'    => " <span style='color:#a29bfe'>{$no['op']}</span>",
        'cmp'      => " <span style='color:#fd79a8'>{$no['op']}</span>",
        'log'      => " <span style='color:#55efc4'>{$no['op']}</span>",
        'atrib'    => " <span style='color:#00e5ff'>{$no['var']->valor}</span>",
        'print'    => " <span style='color:#00ff9d'>{$no['var']->valor}</span>",
        'input'    => " <span style='color:#ffaa00'>{$no['var']->valor}</span>",
        default    => '',
    };

    $html = "<div style='padding:2px 0 2px {$depth}0px'>$badge$info</div>";

    // Filhos
    foreach (['corpo','entao','senao','expr','cond','esq','dir'] as $campo) {
        if (!isset($no[$campo])) continue;
        $filho = $no[$campo];
        if (is_array($filho) && isset($filho['no'])) {
            // nó único
            $html .= "<div style='border-left:2px solid #22283a;margin-left:".($depth*10+8)."px'>";
            $html .= astHtml($filho, $depth + 1);
            $html .= "</div>";
        } elseif (is_array($filho)) {
            // lista de nós
            foreach ($filho as $f) {
                if (is_array($f) && isset($f['no'])) {
                    $html .= "<div style='border-left:2px solid #22283a;margin-left:".($depth*10+8)."px'>";
                    $html .= astHtml($f, $depth + 1);
                    $html .= "</div>";
                }
            }
        }
    }

    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Mini Compilador</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;600;700&family=Space+Grotesk:wght@400;500;600;700;800&display=swap');

:root {
    --bg:      #080a0f;
    --surface: #0f1219;
    --card:    #131720;
    --border:  #1e2436;
    --border2: #2a3352;
    --cyan:    #00e5ff;
    --purple:  #7c5cfc;
    --green:   #00ff9d;
    --orange:  #ff9f43;
    --red:     #ff4757;
    --yellow:  #ffd32a;
    --text:    #c8d3f0;
    --muted:   #4a5572;
    --mono:    'JetBrains Mono', monospace;
    --sans:    'Space Grotesk', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ═══ TOPBAR ═══════════════════════════════════════════════ */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    height: 56px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 100;
}

.topbar-left { display: flex; align-items: center; gap: 12px; }

.logo {
    width: 34px; height: 34px;
    background: linear-gradient(135deg, var(--cyan), var(--purple));
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 16px; color: #080a0f;
}

.topbar h1 {
    font-size: 1rem; font-weight: 700;
    letter-spacing: -.01em;
}

.badge-version {
    font-family: var(--mono);
    font-size: .68rem;
    background: var(--border);
    color: var(--muted);
    padding: 2px 9px;
    border-radius: 20px;
}

.pipeline-status {
    display: flex; gap: 6px; align-items: center;
}

.pipe-step {
    display: flex; align-items: center; gap: 5px;
    font-size: .72rem; font-weight: 600;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid var(--border);
    color: var(--muted);
    transition: all .2s;
    font-family: var(--mono);
}

.pipe-step.done  { color: var(--green);  border-color: rgba(0,255,157,.25); background: rgba(0,255,157,.06); }
.pipe-step.error { color: var(--red);    border-color: rgba(255,71,87,.25);  background: rgba(255,71,87,.06); }
.pipe-step.warn  { color: var(--orange); border-color: rgba(255,159,67,.25); background: rgba(255,159,67,.06); }
.pipe-step::before { content: '●'; font-size: .6rem; }

/* ═══ LAYOUT ════════════════════════════════════════════════ */
.workspace {
    display: grid;
    grid-template-columns: 420px 1fr;
    height: calc(100vh - 56px);
}

/* ═══ PAINEL ESQUERDO — Editor ══════════════════════════════ */
.editor-panel {
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.panel-header {
    padding: 12px 18px;
    border-bottom: 1px solid var(--border);
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0;
}

.dot { width: 7px; height: 7px; border-radius: 50%; }
.dot-cyan   { background: var(--cyan);   box-shadow: 0 0 6px var(--cyan); }
.dot-purple { background: var(--purple); box-shadow: 0 0 6px var(--purple); }
.dot-green  { background: var(--green);  box-shadow: 0 0 6px var(--green); }
.dot-orange { background: var(--orange); box-shadow: 0 0 6px var(--orange); }
.dot-red    { background: var(--red);    box-shadow: 0 0 6px var(--red); }

.editor-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 16px;
    gap: 12px;
    overflow-y: auto;
}

textarea#codigo {
    flex: 1;
    min-height: 220px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--cyan);
    font-family: var(--mono);
    font-size: .82rem;
    line-height: 1.75;
    padding: 14px 16px;
    resize: vertical;
    outline: none;
    transition: border-color .2s;
    tab-size: 2;
}

textarea#codigo:focus { border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(0,229,255,.06); }

/* Input fields */
.input-section { display: flex; flex-direction: column; gap: 8px; }
.input-label { font-size: .72rem; font-weight: 600; color: var(--orange); font-family: var(--mono); }
.input-row { display: flex; align-items: center; gap: 8px; }
.input-row span { font-family: var(--mono); font-size: .8rem; color: var(--muted); min-width: 60px; }

input.var-input {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 7px;
    color: var(--orange);
    font-family: var(--mono);
    font-size: .82rem;
    padding: 7px 12px;
    width: 120px;
    outline: none;
    transition: border-color .2s;
}

input.var-input:focus { border-color: var(--orange); }

/* Botão executar */
.btn-run {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, var(--cyan) 0%, var(--purple) 100%);
    color: #080a0f;
    font-family: var(--sans);
    font-weight: 800;
    font-size: .88rem;
    letter-spacing: .02em;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: opacity .15s, transform .1s;
    flex-shrink: 0;
}
.btn-run:hover  { opacity: .88; }
.btn-run:active { transform: scale(.98); }

/* Referência rápida */
.ref-toggle {
    font-size: .72rem; font-weight: 700; color: var(--muted);
    cursor: pointer; letter-spacing: .06em; text-transform: uppercase;
    display: flex; align-items: center; gap: 6px;
    user-select: none;
    flex-shrink: 0;
}
.ref-toggle:hover { color: var(--text); }

.ref-body {
    display: none;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px;
}
.ref-body.open { display: block; }

.ref-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}

.ref-item {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 7px 10px;
}

.ref-item .kw {
    font-family: var(--mono);
    color: var(--cyan);
    font-size: .78rem;
    font-weight: 600;
}

.ref-item .desc {
    font-size: .68rem;
    color: var(--muted);
    margin-top: 2px;
}

/* ═══ PAINEL DIREITO — Saídas ═══════════════════════════════ */
.output-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--bg);
}

/* ── Abas ── */
.tabs {
    display: flex;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    overflow-x: auto;
}

.tab-btn {
    background: transparent;
    border: none;
    color: var(--muted);
    font-family: var(--sans);
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    padding: 14px 20px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color .2s;
    white-space: nowrap;
    display: flex; align-items: center; gap: 6px;
}

.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--cyan); border-bottom-color: var(--cyan); }
.tab-btn .count {
    background: var(--border);
    color: var(--muted);
    font-size: .65rem;
    padding: 1px 6px;
    border-radius: 10px;
}
.tab-btn.active .count { background: rgba(0,229,255,.15); color: var(--cyan); }

/* ── Conteúdo das abas ── */
.tab-content { flex: 1; overflow-y: auto; }

.tab-pane { display: none; padding: 20px; }
.tab-pane.active { display: block; }

/* ── Erros ── */
.error-banner {
    background: rgba(255,71,87,.08);
    border: 1px solid rgba(255,71,87,.3);
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 16px;
}

.error-banner .err-fase {
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--red);
    font-family: var(--mono);
    margin-bottom: 4px;
}

.error-banner .err-msg {
    color: #ff6b6b;
    font-family: var(--mono);
    font-size: .82rem;
}

.error-banner .err-linha {
    font-size: .7rem;
    color: var(--muted);
    margin-top: 4px;
}

/* ── Saída terminal ── */
.terminal {
    background: #050608;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 16px;
    font-family: var(--mono);
    font-size: .84rem;
    line-height: 1.9;
    min-height: 80px;
    white-space: pre-wrap;
    color: var(--green);
}

.terminal .line-print  { color: var(--green); }
.terminal .line-empty  { color: var(--muted); font-style: italic; }

/* ── Tabela tokens ── */
table { width: 100%; border-collapse: collapse; font-family: var(--mono); font-size: .78rem; }
th {
    background: var(--card);
    color: var(--muted);
    font-size: .68rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    padding: 9px 14px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    position: sticky; top: 0;
}
td { padding: 8px 14px; border-bottom: 1px solid var(--border); color: var(--text); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(255,255,255,.02); }

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: .68rem;
    font-weight: 700;
}
.b-NUM   { background: rgba(0,229,255,.1);   color: var(--cyan); }
.b-VAR   { background: rgba(124,92,252,.15); color: var(--purple); }
.b-OP    { background: rgba(255,71,87,.1);   color: var(--red); }
.b-CMP   { background: rgba(255,159,67,.12); color: var(--orange); }
.b-LOG   { background: rgba(0,255,157,.1);   color: var(--green); }
.b-ATRIB { background: rgba(255,211,42,.1);  color: var(--yellow); }
.b-PRINT { background: rgba(0,255,157,.1);   color: var(--green); }
.b-INPUT { background: rgba(255,159,67,.1);  color: var(--orange); }
.b-IF    { background: rgba(124,92,252,.15); color: var(--purple); }
.b-ELSE  { background: rgba(124,92,252,.1);  color: #a29bfe; }
.b-END   { background: rgba(255,255,255,.06);color: var(--muted); }
.b-WHILE { background: rgba(255,71,87,.1);   color: #ff6b6b; }

/* ── AST ── */
.ast-wrap {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 16px;
    font-family: var(--mono);
    font-size: .78rem;
    overflow-x: auto;
}

/* ── Semântica ── */
.sem-section { margin-bottom: 20px; }
.sem-title {
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 8px;
}
.sem-title::before { content: ''; display: block; width: 3px; height: 14px; background: currentColor; border-radius: 2px; }

.aviso-item {
    background: rgba(255,159,67,.06);
    border: 1px solid rgba(255,159,67,.2);
    border-radius: 8px;
    padding: 9px 14px;
    margin-bottom: 6px;
    font-family: var(--mono);
    font-size: .78rem;
    color: var(--orange);
}

/* ── Passos ── */
.passo-item {
    display: flex;
    gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
    align-items: flex-start;
}
.passo-item:last-child { border-bottom: none; }

.passo-num {
    min-width: 32px; height: 32px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-family: var(--mono);
    font-size: .7rem;
    font-weight: 700;
    color: var(--muted);
    flex-shrink: 0;
}

.passo-body { flex: 1; min-width: 0; }

.passo-instr {
    font-family: var(--mono);
    font-size: .8rem;
    color: var(--cyan);
    margin-bottom: 3px;
}

.passo-desc {
    font-size: .78rem;
    color: var(--text);
    margin-bottom: 6px;
}

.passo-saida {
    font-family: var(--mono);
    font-size: .78rem;
    color: var(--green);
    background: rgba(0,255,157,.06);
    border-radius: 6px;
    padding: 4px 10px;
    margin-bottom: 6px;
}

.passo-vars {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.var-chip {
    font-family: var(--mono);
    font-size: .7rem;
    background: rgba(124,92,252,.1);
    border: 1px solid rgba(124,92,252,.2);
    color: #a29bfe;
    padding: 2px 8px;
    border-radius: 6px;
}

/* ── Tabela de variáveis semântica ── */
td.declared { color: var(--cyan); font-weight: 600; }
td.used-yes  { color: var(--green); }
td.used-no   { color: var(--red); }

/* ── Empty state ── */
.empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--muted);
    font-size: .85rem;
}

.empty .empty-icon { font-size: 2.5rem; margin-bottom: 12px; opacity: .4; }

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--muted); }

</style>
</head>
<body>

<!-- ═══ TOPBAR ═════════════════════════════════════════════ -->
<div class="topbar">
    <div class="topbar-left">
        <div class="logo">λ</div>
        <h1>Mini Compilador</h1>
        <span class="badge-version">v2.0</span>
    </div>
    <div class="pipeline-status">
        <?php
        $fases = [
            'lexica'    => 'Léxica',
            'sintatica' => 'Sintática',
            'semantica' => 'Semântica',
            'execucao'  => 'Execução',
        ];
        $ordem = ['lexica','sintatica','semantica','execucao'];
        foreach ($ordem as $f) {
            $cls = 'pipe-step';
            if ($codigoPostado !== '') {
                $temErroNessa = false;
                foreach ($resultado['erros'] as $e) {
                    if (strtolower($e['fase']) === strtolower($fases[$f])) $temErroNessa = true;
                }
                if ($temErroNessa) $cls .= ' error';
                elseif (in_array($f, ['lexica','sintatica','semantica','execucao'])) {
                    // verificar se chegou nesta fase
                    $fasesOrdem = ['lexica'=>0,'sintatica'=>1,'semantica'=>2,'execucao'=>3];
                    if ($fasesOrdem[$f] <= $fasesOrdem[$resultado['fase']] ?? -1) $cls .= ' done';
                }
            }
            echo "<div class='$cls'>{$fases[$f]}</div>";
        }
        ?>
    </div>
</div>

<!-- ═══ WORKSPACE ═══════════════════════════════════════════ -->
<div class="workspace">

    <!-- ── EDITOR ─────────────────────────────────────────── -->
    <div class="editor-panel">
        <div class="panel-header">
            <span class="dot dot-cyan"></span> Editor de Código
        </div>
        <form method="post" class="editor-wrap" id="mainForm">

            <textarea name="codigo" id="codigo" spellcheck="false"
                placeholder="-- Escreva seu código aqui
-- Exemplo: contador de 1 a 5
na ? a;
nb ? z;
uz na ezn nb;
  na ? na an a;
  zec na;
bz;"><?php echo htmlspecialchars($codigoPostado); ?></textarea>

            <?php if (!empty($inputVarsDetectadas)): ?>
            <div class="input-section">
                <div class="input-label">▸ Entradas (xec)</div>
                <?php foreach ($inputVarsDetectadas as $iv): ?>
                <div class="input-row">
                    <span><?= htmlspecialchars($iv) ?></span>
                    <input type="number" class="var-input"
                           name="inp_<?= htmlspecialchars($iv) ?>"
                           value="<?= isset($_POST['inp_'.$iv]) ? (int)$_POST['inp_'.$iv] : '' ?>"
                           placeholder="inteiro...">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-run">▶ Compilar &amp; Executar</button>

            <!-- Referência rápida -->
            <div class="ref-toggle" onclick="toggleRef()">
                <span id="ref-arrow">▸</span> Referência rápida
            </div>
            <div class="ref-body" id="ref-body">
                <div class="ref-grid">
                    <div class="ref-item"><div class="kw">VAR ? expr</div><div class="desc">Atribuição</div></div>
                    <div class="ref-item"><div class="kw">zec VAR</div><div class="desc">Print</div></div>
                    <div class="ref-item"><div class="kw">xec VAR</div><div class="desc">Input</div></div>
                    <div class="ref-item"><div class="kw">ez cond … bz</div><div class="desc">If</div></div>
                    <div class="ref-item"><div class="kw">oz … bz</div><div class="desc">Else</div></div>
                    <div class="ref-item"><div class="kw">uz cond … bz</div><div class="desc">While</div></div>
                    <div class="ref-item"><div class="kw">an en in on</div><div class="desc">+ − × ÷</div></div>
                    <div class="ref-item"><div class="kw">az iz enz anz</div><div class="desc">== != &lt; &gt;</div></div>
                    <div class="ref-item"><div class="kw">ezn ozn</div><div class="desc">&lt;= &gt;=</div></div>
                    <div class="ref-item"><div class="kw">ec / oc</div><div class="desc">AND / OR</div></div>
                    <div class="ref-item"><div class="kw">n…</div><div class="desc">Variável</div></div>
                    <div class="ref-item"><div class="kw">a=0 e=1 i=2 o=3 u=4<br>z=5 x=6 c=7 v=8 b=9</div><div class="desc">Dígitos</div></div>
                </div>
            </div>

        </form>
    </div>

    <!-- ── OUTPUT ─────────────────────────────────────────── -->
    <div class="output-panel">

        <!-- Abas -->
        <div class="tabs">
            <?php
            $numTokens = 0;
            foreach ($resultado['tokens'] as $l) $numTokens += count($l);
            $numPassos  = count($resultado['execucao']['passos'] ?? []);
            $numAvisos  = count($resultado['semantica']['avisos'] ?? []);
            $numErros   = count($resultado['erros']);
            $numVars    = count($resultado['execucao']['variaveis'] ?? []);
            ?>
            <button class="tab-btn active" onclick="aba('exec',this)">
                Execução
                <?php if($numErros): ?><span class="count"><?=$numErros?></span><?php endif; ?>
            </button>
            <button class="tab-btn" onclick="aba('passos',this)">
                Passo a Passo
                <?php if($numPassos): ?><span class="count"><?=$numPassos?></span><?php endif; ?>
            </button>
            <button class="tab-btn" onclick="aba('tokens',this)">
                Léxica
                <?php if($numTokens): ?><span class="count"><?=$numTokens?></span><?php endif; ?>
            </button>
            <button class="tab-btn" onclick="aba('ast',this)">
                AST (Sintática)
            </button>
            <button class="tab-btn" onclick="aba('sem',this)">
                Semântica
                <?php if($numAvisos): ?><span class="count"><?=$numAvisos?></span><?php endif; ?>
            </button>
            <button class="tab-btn" onclick="aba('vars',this)">
                Variáveis
                <?php if($numVars): ?><span class="count"><?=$numVars?></span><?php endif; ?>
            </button>
        </div>

        <div class="tab-content">

            <!-- ── ABA: EXECUÇÃO ─────────────────────────── -->
            <div id="tab-exec" class="tab-pane active">

                <?php if (!empty($resultado['erros'])): ?>
                    <?php foreach ($resultado['erros'] as $e): ?>
                    <div class="error-banner">
                        <div class="err-fase">✗ Erro na fase: <?= htmlspecialchars($e['fase']) ?></div>
                        <div class="err-msg"><?= htmlspecialchars($e['msg']) ?></div>
                        <?php if ($e['linha']): ?>
                        <div class="err-linha">Instrução nº <?= $e['linha'] ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($resultado['execucao'] !== null): ?>
                    <?php $linhas = array_filter(explode("\n", $resultado['execucao']['saida']), fn($l) => trim($l) !== ''); ?>
                    <?php if (!empty($linhas)): ?>
                    <div class="terminal">
<?php foreach ($linhas as $l): ?><div class="line-print"><?= htmlspecialchars($l) ?></div>
<?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="terminal"><span class="line-empty">-- programa executado sem saída de print --</span></div>
                    <?php endif; ?>
                <?php elseif (empty($resultado['erros'])): ?>
                    <div class="empty">
                        <div class="empty-icon">⚡</div>
                        <div>Escreva um programa e clique em <b>Compilar &amp; Executar</b></div>
                    </div>
                <?php endif; ?>

            </div>

            <!-- ── ABA: PASSO A PASSO ──────────────────── -->
            <div id="tab-passos" class="tab-pane">
                <?php if (!empty($resultado['execucao']['passos'])): ?>
                    <?php foreach ($resultado['execucao']['passos'] as $p): ?>
                    <div class="passo-item">
                        <div class="passo-num"><?= $p['numero'] ?></div>
                        <div class="passo-body">
                            <div class="passo-instr"><?= htmlspecialchars($p['instrucao']) ?></div>
                            <div class="passo-desc"><?= htmlspecialchars($p['descricao']) ?></div>
                            <?php if ($p['saida']): ?>
                            <div class="passo-saida">↳ <?= htmlspecialchars($p['saida']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p['variaveis'])): ?>
                            <div class="passo-vars">
                                <?php foreach ($p['variaveis'] as $k => $v): ?>
                                <span class="var-chip"><?= htmlspecialchars($k) ?> = <?= $v ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty"><div class="empty-icon">👣</div><div>Nenhum passo ainda.</div></div>
                <?php endif; ?>
            </div>

            <!-- ── ABA: LÉXICA ─────────────────────────── -->
            <div id="tab-tokens" class="tab-pane">
                <?php if (!empty($resultado['tokens'])): ?>
                <table>
                    <thead><tr><th>Instr.</th><th>Tipo</th><th>Valor</th><th>Linha</th></tr></thead>
                    <tbody>
                    <?php $ln=1; foreach ($resultado['tokens'] as $lista): foreach ($lista as $t): ?>
                    <tr>
                        <td><?= $ln ?></td>
                        <td><span class="badge b-<?= $t->tipo ?>"><?= $t->tipo ?></span></td>
                        <td><?= htmlspecialchars($t->valor) ?></td>
                        <td><?= $t->linha ?></td>
                    </tr>
                    <?php endforeach; $ln++; endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty"><div class="empty-icon">🔤</div><div>Nenhum token.</div></div>
                <?php endif; ?>
            </div>

            <!-- ── ABA: AST ───────────────────────────── -->
            <div id="tab-ast" class="tab-pane">
                <?php if ($resultado['ast'] !== null): ?>
                <div class="ast-wrap"><?= astHtml($resultado['ast']) ?></div>
                <?php else: ?>
                    <div class="empty"><div class="empty-icon">🌳</div><div>AST não gerada ainda.</div></div>
                <?php endif; ?>
            </div>

            <!-- ── ABA: SEMÂNTICA ─────────────────────── -->
            <div id="tab-sem" class="tab-pane">
                <?php if ($resultado['semantica'] !== null):
                    $semR = $resultado['semantica'];
                ?>

                <!-- Status -->
                <div class="sem-section">
                    <div class="sem-title">Status</div>
                    <?php if ($semR['valido']): ?>
                        <div style="color:var(--green);font-family:var(--mono);font-size:.84rem">✓ Análise semântica aprovada</div>
                    <?php else: ?>
                        <?php foreach ($semR['erros'] as $e): ?>
                        <div class="error-banner">
                            <div class="err-msg"><?= htmlspecialchars($e['msg']) ?></div>
                            <?php if ($e['linha']): ?><div class="err-linha">Linha <?= $e['linha'] ?></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Avisos -->
                <?php if (!empty($semR['avisos'])): ?>
                <div class="sem-section">
                    <div class="sem-title" style="color:var(--orange)">Avisos</div>
                    <?php foreach ($semR['avisos'] as $a): ?>
                    <div class="aviso-item">⚠ <?= htmlspecialchars($a['msg']) ?> (linha <?= $a['linha'] ?>)</div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Tabela de Símbolos -->
                <div class="sem-section">
                    <div class="sem-title">Tabela de Símbolos</div>
                    <table>
                        <thead><tr><th>Variável</th><th>Declarada (instr.)</th><th>Usada (instr.)</th><th>Valor inicial</th><th>Utilizada</th></tr></thead>
                        <tbody>
                        <?php foreach ($semR['tabela'] as $s): ?>
                        <tr>
                            <td class="declared"><?= htmlspecialchars($s['nome']) ?></td>
                            <td><?= $s['linha_decl'] ?></td>
                            <td><?= $s['linha_uso'] ?? '—' ?></td>
                            <td><?= $s['valor'] ?></td>
                            <td class="<?= $s['usada'] ? 'used-yes' : 'used-no' ?>"><?= $s['usada'] ? '✓' : '✗' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php else: ?>
                    <div class="empty"><div class="empty-icon">🔍</div><div>Análise semântica não realizada.</div></div>
                <?php endif; ?>
            </div>

            <!-- ── ABA: VARIÁVEIS ─────────────────────── -->
            <div id="tab-vars" class="tab-pane">
                <?php if (!empty($resultado['execucao']['variaveis'])): ?>
                <table>
                    <thead><tr><th>Variável</th><th>Valor Final</th></tr></thead>
                    <tbody>
                    <?php foreach ($resultado['execucao']['variaveis'] as $n => $v): ?>
                    <tr>
                        <td class="declared"><?= htmlspecialchars($n) ?></td>
                        <td><?= $v ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty"><div class="empty-icon">📦</div><div>Nenhuma variável.</div></div>
                <?php endif; ?>
            </div>

        </div><!-- /tab-content -->
    </div><!-- /output-panel -->
</div><!-- /workspace -->

<script>
function aba(id, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b  => b.classList.remove('active'));
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
}

function toggleRef() {
    const body  = document.getElementById('ref-body');
    const arrow = document.getElementById('ref-arrow');
    const open  = body.classList.toggle('open');
    arrow.textContent = open ? '▾' : '▸';
}

// Tab com indentação no textarea
document.getElementById('codigo').addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        const s = this.selectionStart, end = this.selectionEnd;
        this.value = this.value.substring(0, s) + '  ' + this.value.substring(end);
        this.selectionStart = this.selectionEnd = s + 2;
    }
});
</script>

</body>
</html>
