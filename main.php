<?php
require_once __DIR__ . '/lexer.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/semantic.php';
require_once __DIR__ . '/interpreter.php';

// ── Pipeline ──────────────────────────────────────────────────
$resultado = ['tokens'=>[],'ast'=>null,'semantica'=>null,'execucao'=>null,'erros'=>[],'fase'=>''];
$codigoPostado = $_POST['codigo'] ?? '';
$inputsForm    = [];

if ($codigoPostado !== '') {
    foreach ($_POST as $k => $v) {
        if (strpos($k,'inp_')===0) {
            $nome = substr($k,4);
            $inputsForm[$nome] = strpos((string)$v,'.')!==false ? (float)$v : (is_numeric($v)?(int)$v:$v);
        }
    }
    try {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenizar($codigoPostado);
        $resultado['tokens'] = $tokens; $resultado['fase'] = 'lexica';

        $parser = new Parser($lexer->getCmpMap(), $lexer->getOpMap(), $lexer->getLogMap());
        $ast    = $parser->parse($tokens);
        $resultado['ast']  = $ast; $resultado['fase'] = 'sintatica';

        $sem    = new AnalisadorSemantico();
        $semRes = $sem->analisar($ast);
        $resultado['semantica'] = $semRes; $resultado['fase'] = 'semantica';
        if (!$semRes['valido'])
            foreach ($semRes['erros'] as $e)
                $resultado['erros'][] = ['fase'=>'Semântica','msg'=>$e['msg'],'linha'=>$e['linha']];

        if ($semRes['valido']) {
            $interp = new Interpretador($inputsForm);
            $exec   = $interp->executar($ast);
            $resultado['execucao'] = $exec; $resultado['fase'] = 'execucao';
        }
    } catch (MiniLexerError $e)    { $resultado['erros'][] = ['fase'=>'Léxica',   'msg'=>$e->getMessage(),'linha'=>$e->linhaCodigo]; }
      catch (MiniParseError $e)    { $resultado['erros'][] = ['fase'=>'Sintática','msg'=>$e->getMessage(),'linha'=>$e->linhaCodigo]; }
      catch (MiniSemanticError $e) { $resultado['erros'][] = ['fase'=>'Semântica','msg'=>$e->getMessage(),'linha'=>$e->linhaCodigo]; }
      catch (MiniRuntimeError $e)  { $resultado['erros'][] = ['fase'=>'Execução', 'msg'=>$e->getMessage(),'linha'=>$e->linhaCodigo]; }
      catch (Exception $e)         { $resultado['erros'][] = ['fase'=>'Geral',    'msg'=>$e->getMessage(),'linha'=>0]; }
}

// ── Detecta variáveis de INPUT ─────────────────────────────────
$inputVarsDetectadas = [];
if ($codigoPostado !== '') {
    $limpo = preg_replace('/@@.*?@@/', '', $codigoPostado);
    $limpo = preg_replace('/\|.*?\|/', '', $limpo);
    foreach (explode(';', str_replace(["\r\n","\n"]," ",$limpo)) as $lr) {
        $lr = trim($lr);
        if (preg_match('/^xec\s+([nfs][a-zA-Z_][a-zA-Z0-9_]*)$/', $lr, $m)) {
            $raw  = $m[1];
            $tipo = match($raw[0]) { 'f'=>'float','s'=>'string',default=>'int' };
            $inputVarsDetectadas[] = ['nome'=>$raw,'tipo'=>$tipo];
        }
    }
}

// ── Helper AST ────────────────────────────────────────────────
function astHtml(array $no, int $d=0): string {
    $tipo  = $no['no'];
    $cores = ['programa'=>'#7c5cfc','atrib'=>'#00e5ff','fatrib'=>'#00bcd4','satrib'=>'#ff9f43',
              'print'=>'#00ff9d','print_tmpl'=>'#00ff9d','input'=>'#ffaa00',
              'if'=>'#ff6b6b','while'=>'#ff9f43','for'=>'#ffd32a',
              'funcdef'=>'#a29bfe','funccall'=>'#fd79a8','return'=>'#ff4757',
              'binop'=>'#a29bfe','cmp'=>'#fd79a8','log'=>'#55efc4',
              'var'=>'#74b9ff','fvar'=>'#00bcd4','svar'=>'#ff9f43',
              'num'=>'#ffeaa7','fnum'=>'#b2dfdb','strlit'=>'#ffcc80'];
    $cor   = $cores[$tipo] ?? '#ccc';
    $badge = "<span style='background:rgba(255,255,255,.08);color:$cor;padding:1px 7px;border-radius:4px;font-size:.75rem;font-family:monospace;font-weight:700'>$tipo</span>";
    $info  = match($tipo) {
        'var','fvar','svar'              => " <span style='color:$cor'>{$no['nome']}</span>",
        'num','fnum'                     => " <span style='color:$cor'>{$no['valor']}</span>",
        'strlit'                         => " <span style='color:#ffcc80'>\"".htmlspecialchars($no['valor'])."\"</span>",
        'binop','cmp','log'              => " <span style='color:$cor'>{$no['op']}</span>",
        'atrib','fatrib','satrib','print' => isset($no['var']) ? " <span style='color:$cor'>{$no['var']->valor}</span>" : '',
        'funcdef','funccall'             => " <span style='color:$cor'>{$no['nome']}</span>",
        default                          => '',
    };
    $html = "<div style='padding:2px 0 2px {$d}0px'>$badge$info</div>";
    foreach (['corpo','entao','senao','expr','cond','esq','dir','partes','init','step'] as $c) {
        if (!isset($no[$c])) continue;
        $f = $no[$c];
        if (is_array($f) && isset($f['no'])) {
            $html .= "<div style='border-left:2px solid #22283a;margin-left:".($d*10+8)."px'>".astHtml($f,$d+1)."</div>";
        } elseif (is_array($f)) {
            foreach ($f as $item)
                if (is_array($item) && isset($item['no']))
                    $html .= "<div style='border-left:2px solid #22283a;margin-left:".($d*10+8)."px'>".astHtml($item,$d+1)."</div>";
        }
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Van Language</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Space+Grotesk:wght@400;600;700;800&display=swap');
:root{
  --bg:#080a0f;--surface:#0f1219;--card:#131720;--border:#1e2436;--border2:#2a3352;
  --cyan:#00e5ff;--purple:#7c5cfc;--green:#00ff9d;--orange:#ff9f43;--red:#ff4757;
  --yellow:#ffd32a;--teal:#00bcd4;--text:#c8d3f0;--muted:#4a5572;
  --mono:'JetBrains Mono',monospace;--sans:'Space Grotesk',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;overflow-x:hidden;}
/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:0 28px;height:54px;
  background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;}
.topbar-left{display:flex;align-items:center;gap:10px;}
.logo{width:32px;height:32px;background:linear-gradient(135deg,var(--cyan),var(--purple));border-radius:8px;
  display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#080a0f;}
.topbar h1{font-size:.95rem;font-weight:700;}
.bv{font-family:var(--mono);font-size:.65rem;background:var(--border);color:var(--muted);padding:2px 8px;border-radius:20px;}
.pipeline{display:flex;gap:5px;}
.ps{font-size:.7rem;font-weight:600;padding:3px 9px;border-radius:20px;border:1px solid var(--border);
  color:var(--muted);font-family:var(--mono);}
.ps::before{content:'● ';font-size:.55rem;}
.ps.done{color:var(--green);border-color:rgba(0,255,157,.25);background:rgba(0,255,157,.06);}
.ps.error{color:var(--red);border-color:rgba(255,71,87,.25);background:rgba(255,71,87,.06);}
/* LAYOUT */
.workspace{display:grid;grid-template-columns:420px 1fr;height:calc(100vh - 54px);}
.editor-panel{background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
.ph{padding:10px 16px;border-bottom:1px solid var(--border);font-size:.68rem;font-weight:700;
  letter-spacing:.08em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:7px;flex-shrink:0;}
.dot{width:6px;height:6px;border-radius:50%;background:var(--cyan);box-shadow:0 0 5px var(--cyan);}
.ew{flex:1;display:flex;flex-direction:column;padding:14px;gap:10px;overflow-y:auto;}
textarea#codigo{flex:1;min-height:200px;background:var(--bg);border:1px solid var(--border);border-radius:9px;
  color:var(--cyan);font-family:var(--mono);font-size:.8rem;line-height:1.75;padding:12px 14px;
  resize:vertical;outline:none;transition:border-color .2s;tab-size:2;}
textarea#codigo:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(0,229,255,.05);}
/* Inputs */
.is{display:flex;flex-direction:column;gap:7px;}
.il{font-size:.7rem;font-weight:600;color:var(--orange);font-family:var(--mono);}
.ir{display:flex;align-items:center;gap:7px;}
.iname{font-family:var(--mono);font-size:.78rem;color:var(--muted);min-width:90px;}
.it{font-family:var(--mono);font-size:.65rem;padding:2px 7px;border-radius:10px;font-weight:700;}
.it-int{background:rgba(0,229,255,.1);color:var(--cyan);}
.it-float{background:rgba(0,188,212,.1);color:var(--teal);}
.it-string{background:rgba(255,159,67,.1);color:var(--orange);}
input.vi{background:var(--bg);border:1px solid var(--border);border-radius:7px;color:var(--orange);
  font-family:var(--mono);font-size:.8rem;padding:6px 10px;flex:1;outline:none;transition:border-color .2s;}
input.vi:focus{border-color:var(--orange);}
/* Botão */
.btn-run{width:100%;padding:11px;background:linear-gradient(135deg,var(--cyan),var(--purple));
  color:#080a0f;font-family:var(--sans);font-weight:800;font-size:.85rem;border:none;border-radius:9px;
  cursor:pointer;transition:opacity .15s,transform .1s;flex-shrink:0;}
.btn-run:hover{opacity:.88;}.btn-run:active{transform:scale(.98);}
/* Ref */
.rt{font-size:.68rem;font-weight:700;color:var(--muted);cursor:pointer;letter-spacing:.06em;
  text-transform:uppercase;display:flex;align-items:center;gap:5px;user-select:none;flex-shrink:0;}
.rt:hover{color:var(--text);}
.rb{display:none;background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:10px;}
.rb.open{display:block;}
.rg{display:grid;grid-template-columns:1fr 1fr;gap:5px;}
.ri{background:var(--card);border:1px solid var(--border);border-radius:6px;padding:6px 9px;}
.ri .kw{font-family:var(--mono);color:var(--cyan);font-size:.73rem;font-weight:600;}
.ri .desc{font-size:.65rem;color:var(--muted);margin-top:2px;}
.rs{font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--purple);
  margin:8px 0 4px;font-family:var(--mono);}
/* OUTPUT */
.output-panel{display:flex;flex-direction:column;overflow:hidden;background:var(--bg);}
.tabs{display:flex;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0;overflow-x:auto;}
.tab-btn{background:transparent;border:none;color:var(--muted);font-family:var(--sans);font-size:.72rem;
  font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:13px 18px;cursor:pointer;
  border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .2s;white-space:nowrap;
  display:flex;align-items:center;gap:5px;}
.tab-btn:hover{color:var(--text);}
.tab-btn.active{color:var(--cyan);border-bottom-color:var(--cyan);}
.tab-btn .cnt{background:var(--border);color:var(--muted);font-size:.62rem;padding:1px 6px;border-radius:10px;}
.tab-btn.active .cnt{background:rgba(0,229,255,.15);color:var(--cyan);}
.tc{flex:1;overflow-y:auto;}
.tp{display:none;padding:18px;}
.tp.active{display:block;}
.terminal{background:#050608;border:1px solid var(--border);border-radius:9px;padding:14px;
  font-family:var(--mono);font-size:.82rem;line-height:1.9;min-height:60px;white-space:pre-wrap;color:var(--green);}
.le{color:var(--muted);font-style:italic;}
.eb{background:rgba(255,71,87,.08);border:1px solid rgba(255,71,87,.3);border-radius:9px;
  padding:12px 16px;margin-bottom:14px;}
.ef{font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  color:var(--red);font-family:var(--mono);margin-bottom:3px;}
.em{color:#ff6b6b;font-family:var(--mono);font-size:.8rem;}
.el{font-size:.68rem;color:var(--muted);margin-top:3px;}
table{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:.76rem;}
th{background:var(--card);color:var(--muted);font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;
  padding:8px 12px;border-bottom:1px solid var(--border);text-align:left;position:sticky;top:0;}
td{padding:7px 12px;border-bottom:1px solid var(--border);color:var(--text);}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:.65rem;font-weight:700;}
.b-NUM,.b-INUM{background:rgba(0,229,255,.1);color:var(--cyan);}
.b-FNUM,.b-FNUM_ALPHA{background:rgba(0,188,212,.1);color:var(--teal);}
.b-VAR{background:rgba(124,92,252,.15);color:var(--purple);}
.b-FVAR{background:rgba(0,188,212,.15);color:var(--teal);}
.b-SVAR{background:rgba(255,159,67,.15);color:var(--orange);}
.b-STRLIT{background:rgba(255,204,128,.15);color:#ffcc80;}
.b-OP{background:rgba(255,71,87,.1);color:var(--red);}
.b-CMP{background:rgba(255,159,67,.12);color:var(--orange);}
.b-LOG{background:rgba(0,255,157,.1);color:var(--green);}
.b-ATRIB{background:rgba(255,211,42,.1);color:var(--yellow);}
.b-PRINT{background:rgba(0,255,157,.1);color:var(--green);}
.b-INPUT{background:rgba(255,159,67,.1);color:var(--orange);}
.b-IF{background:rgba(124,92,252,.15);color:var(--purple);}
.b-ELSE{background:rgba(124,92,252,.1);color:#a29bfe;}
.b-END{background:rgba(255,255,255,.06);color:var(--muted);}
.b-WHILE{background:rgba(255,71,87,.1);color:#ff6b6b;}
.b-FOR{background:rgba(255,211,42,.1);color:var(--yellow);}
.b-FUNC{background:rgba(162,155,254,.15);color:#a29bfe;}
.b-FNAME{background:rgba(253,121,168,.12);color:#fd79a8;}
.b-RETURN{background:rgba(255,71,87,.08);color:var(--red);}
.b-INC,.b-DEC{background:rgba(0,255,157,.08);color:var(--green);}
.pi{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);align-items:flex-start;}
.pi:last-child{border-bottom:none;}
.pn{min-width:30px;height:30px;background:var(--card);border:1px solid var(--border);border-radius:7px;
  display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:.68rem;font-weight:700;color:var(--muted);flex-shrink:0;}
.pb{flex:1;min-width:0;}
.pinstr{font-family:var(--mono);font-size:.78rem;color:var(--cyan);margin-bottom:2px;}
.pdesc{font-size:.76rem;color:var(--text);margin-bottom:5px;}
.psaida{font-family:var(--mono);font-size:.76rem;color:var(--green);background:rgba(0,255,157,.06);border-radius:5px;padding:3px 9px;margin-bottom:5px;}
.pvars{display:flex;flex-wrap:wrap;gap:3px;}
.vc{font-family:var(--mono);font-size:.68rem;padding:2px 7px;border-radius:5px;}
.ci{background:rgba(124,92,252,.1);border:1px solid rgba(124,92,252,.2);color:#a29bfe;}
.cf{background:rgba(0,188,212,.1);border:1px solid rgba(0,188,212,.2);color:var(--teal);}
.cs_{background:rgba(255,159,67,.1);border:1px solid rgba(255,159,67,.2);color:var(--orange);}
.ss{margin-bottom:18px;}
.st{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);
  margin-bottom:9px;display:flex;align-items:center;gap:7px;}
.st::before{content:'';display:block;width:3px;height:13px;background:currentColor;border-radius:2px;}
.ai{background:rgba(255,159,67,.06);border:1px solid rgba(255,159,67,.2);border-radius:7px;
  padding:8px 12px;margin-bottom:5px;font-family:var(--mono);font-size:.76rem;color:var(--orange);}
.aw{background:var(--card);border:1px solid var(--border);border-radius:9px;padding:14px;font-family:var(--mono);font-size:.76rem;overflow-x:auto;}
.empty{text-align:center;padding:44px 20px;color:var(--muted);font-size:.83rem;}
.empty .ei{font-size:2.2rem;margin-bottom:10px;opacity:.4;}
td.dec{color:var(--cyan);font-weight:600;}
td.uy{color:var(--green);}td.un_{color:var(--red);}
td.eg{color:var(--cyan);}td.el2{color:#a29bfe;}
td.ti{color:var(--cyan);}td.tf{color:var(--teal);}td.ts{color:var(--orange);}
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px;}
</style>
</head>
<body>
<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-left">
    <div class="logo">V</div>
    <h1>Van Language</h1>
    <span class="bv">v2.0</span>
  </div>
  <div class="pipeline">
    <?php
    $fases = ['lexica'=>'Léxica','sintatica'=>'Sintática','semantica'=>'Semântica','execucao'=>'Execução'];
    $fo    = ['lexica'=>0,'sintatica'=>1,'semantica'=>2,'execucao'=>3];
    foreach ($fases as $f => $label) {
        $cls = 'ps';
        if ($codigoPostado !== '') {
            $err = array_filter($resultado['erros'], fn($e) => mb_strtolower($e['fase']) === mb_strtolower($label));
            if (!empty($err)) $cls .= ' error';
            elseif (isset($fo[$resultado['fase']]) && $fo[$f] <= $fo[$resultado['fase']]) $cls .= ' done';
        }
        echo "<div class='$cls'>$label</div>";
    }
    ?>
  </div>
</div>

<!-- WORKSPACE -->
<div class="workspace">
  <!-- EDITOR -->
  <div class="editor-panel">
    <div class="ph"><span class="dot"></span> Editor — Van Language</div>
    <form method="post" class="ew" id="mainForm">
      <textarea name="codigo" id="codigo" spellcheck="false"
        placeholder="// Van Language v2.0
// Inteiro:   nidade ? |25|;
// ou:        nidade ? ei;       // ei = 12
// Float:     fpreco ? |9.99|;
// String:    snome ? @@Maria@@;
// Template:  zec @@Ola @@ snome;
// If:        cs nidade >= |18| {
//              zec @@maior@@;
//            end
// Else:      cs nidade >= |18| {
//              zec @@maior@@;
//            cc {
//              zec @@menor@@;
//            end
// While:     wh ncount < |10| {
//              ncount ? ncount + e;
//            end
// For:       fr ni ? a;
//            ni < |10|;
//            ni++;
//              zec ni;
//            end
// Função:    fn somar() {
//              nresult ? na + nb;
//            end
//            somar;"><?php echo htmlspecialchars($codigoPostado); ?></textarea>

      <?php if (!empty($inputVarsDetectadas)): ?>
      <div class="is">
        <div class="il">▸ Entradas (xec)</div>
        <?php foreach ($inputVarsDetectadas as $iv): ?>
        <div class="ir">
          <span class="iname"><?= htmlspecialchars($iv['nome']) ?></span>
          <span class="it it-<?= $iv['tipo'] ?>"><?= $iv['tipo'] ?></span>
          <input type="<?= $iv['tipo']==='string'?'text':'number' ?>" class="vi"
                 name="inp_<?= htmlspecialchars($iv['nome']) ?>"
                 value="<?= htmlspecialchars($_POST['inp_'.$iv['nome']] ?? '') ?>"
                 <?= $iv['tipo']==='float'?'step="any"':'' ?>
                 placeholder="<?= $iv['tipo'] ?>...">
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <button type="submit" class="btn-run">▶ Compilar &amp; Executar</button>

      <div class="rt" onclick="toggleRef()"><span id="ra">▸</span> Referência rápida</div>
      <div class="rb" id="rb">
        <div class="rs">── Tipos de variável</div>
        <div class="rg">
          <div class="ri"><div class="kw">n + nome</div><div class="desc">Inteiro  ex: nidade, nx</div></div>
          <div class="ri"><div class="kw" style="color:#00bcd4">f + nome</div><div class="desc">Float  ex: fpreco, fmedia</div></div>
          <div class="ri"><div class="kw" style="color:#ff9f43">s + nome</div><div class="desc">String  ex: snome, smsg</div></div>
          <div class="ri"><div class="kw" style="color:#fd79a8">nome()</div><div class="desc">Função  ex: somar, calcular</div></div>
        </div>
        <div class="rs">── Números</div>
        <div class="rg">
          <div class="ri"><div class="kw">|42|</div><div class="desc">Padrão entre pipes</div></div>
          <div class="ri"><div class="kw">ei</div><div class="desc">Alfabeto Van: 12 (e=1,i=2)</div></div>
          <div class="ri"><div class="kw">|3.14|</div><div class="desc">Float padrão</div></div>
          <div class="ri"><div class="kw">ei.z</div><div class="desc">Float Van: 12.5</div></div>
        </div>
        <div class="rs">── Comandos</div>
        <div class="rg">
          <div class="ri"><div class="kw">VAR ? EXPR ;</div><div class="desc">Atribuição</div></div>
          <div class="ri"><div class="kw">zec VAR ;</div><div class="desc">Print simples</div></div>
          <div class="ri"><div class="kw">zec @@txt@@ VAR ;</div><div class="desc">Template</div></div>
          <div class="ri"><div class="kw">xec VAR ;</div><div class="desc">Input</div></div>
        </div>
        <div class="rs">── Operadores</div>
        <div class="rg">
          <div class="ri"><div class="kw">+ - * /</div><div class="desc">Aritméticos</div></div>
          <div class="ri"><div class="kw">== != &lt; &gt; &lt;= &gt;=</div><div class="desc">Comparação</div></div>
          <div class="ri"><div class="kw">ec / oc</div><div class="desc">AND / OR</div></div>
          <div class="ri"><div class="kw">++ / --</div><div class="desc">Inc / Dec (no for)</div></div>
        </div>
        <div class="rs">── Controle</div>
        <div class="rg">
          <div class="ri"><div class="kw">cs COND { ... end</div><div class="desc">If</div></div>
          <div class="ri"><div class="kw">cc { ... end</div><div class="desc">Else</div></div>
          <div class="ri"><div class="kw">wh COND { ... end</div><div class="desc">While</div></div>
          <div class="ri"><div class="kw">fr VAR?EXPR; COND; STEP end</div><div class="desc">For</div></div>
        </div>
        <div class="rs">── Função</div>
        <div class="rg">
          <div class="ri"><div class="kw">fn nome() { ... end</div><div class="desc">Declaração</div></div>
          <div class="ri"><div class="kw">nome ;</div><div class="desc">Chamada</div></div>
          <div class="ri"><div class="kw">ret EXPR ;</div><div class="desc">Retorno</div></div>
          <div class="ri"><div class="kw">// comentário</div><div class="desc">Comentário de linha</div></div>
        </div>
      </div>
    </form>
  </div>

  <!-- OUTPUT -->
  <div class="output-panel">
    <div class="tabs">
      <?php
      $nt=0; foreach($resultado['tokens'] as $l) $nt+=count($l);
      $np=count($resultado['execucao']['passos']??[]);
      $na=count($resultado['semantica']['avisos']??[]);
      $ne=count($resultado['erros']);
      $nv=count($resultado['execucao']['variaveis']??[]);
      $nf=count($resultado['semantica']['funcoes']??[]);
      function cnt($n){ return $n?"<span class='cnt'>$n</span>":''; }
      ?>
      <button class="tab-btn active" onclick="aba('exec',this)">Execução<?=cnt($ne)?></button>
      <button class="tab-btn" onclick="aba('passos',this)">Passo a Passo<?=cnt($np)?></button>
      <button class="tab-btn" onclick="aba('tokens',this)">Léxica<?=cnt($nt)?></button>
      <button class="tab-btn" onclick="aba('ast',this)">AST</button>
      <button class="tab-btn" onclick="aba('sem',this)">Semântica<?=cnt($na)?></button>
      <button class="tab-btn" onclick="aba('vars',this)">Variáveis<?=cnt($nv)?></button>
      <?php if($nf): ?><button class="tab-btn" onclick="aba('funcs',this)">Funções<?=cnt($nf)?></button><?php endif; ?>
    </div>

    <div class="tc">
      <!-- EXECUÇÃO -->
      <div id="tab-exec" class="tp active">
        <?php foreach($resultado['erros'] as $e): ?>
        <div class="eb">
          <div class="ef">✗ <?=htmlspecialchars($e['fase'])?></div>
          <div class="em"><?=htmlspecialchars($e['msg'])?></div>
          <?php if($e['linha']): ?><div class="el">Instrução nº <?=$e['linha']?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if($resultado['execucao']!==null):
          $ls=array_filter(explode("\n",$resultado['execucao']['saida']),fn($l)=>trim($l)!==''); ?>
          <?php if(!empty($ls)): ?>
          <div class="terminal"><?php foreach($ls as $l): ?><?=htmlspecialchars($l)."\n"?><?php endforeach; ?></div>
          <?php else: ?>
          <div class="terminal"><span class="le">// programa executado sem saída</span></div>
          <?php endif; ?>
        <?php elseif(empty($resultado['erros'])): ?>
        <div class="empty"><div class="ei">⚡</div><div>Escreva um programa e clique em <b>Compilar &amp; Executar</b></div></div>
        <?php endif; ?>
      </div>

      <!-- PASSO A PASSO -->
      <div id="tab-passos" class="tp">
        <?php if(!empty($resultado['execucao']['passos'])): ?>
          <?php foreach($resultado['execucao']['passos'] as $p): ?>
          <div class="pi">
            <div class="pn"><?=$p['numero']?></div>
            <div class="pb">
              <div class="pinstr"><?=htmlspecialchars($p['instrucao'])?></div>
              <div class="pdesc"><?=htmlspecialchars($p['descricao'])?></div>
              <?php if($p['saida']): ?><div class="psaida">↳ <?=htmlspecialchars($p['saida'])?></div><?php endif; ?>
              <?php if(!empty($p['variaveis'])): ?>
              <div class="pvars">
                <?php foreach($p['variaveis'] as $k=>$v):
                  $c=is_float($v)?'cf':(is_string($v)?'cs_':'ci'); ?>
                <span class="vc <?=$c?>"><?=htmlspecialchars($k)?> = <?=htmlspecialchars((string)$v)?></span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?><div class="empty"><div class="ei">👣</div><div>Nenhum passo ainda.</div></div><?php endif; ?>
      </div>

      <!-- LÉXICA -->
      <div id="tab-tokens" class="tp">
        <?php if(!empty($resultado['tokens'])): ?>
        <table><thead><tr><th>Instr.</th><th>Tipo</th><th>Valor</th><th>Linha</th></tr></thead><tbody>
        <?php $ln=1; foreach($resultado['tokens'] as $lista): foreach($lista as $t): ?>
        <tr>
          <td><?=$ln?></td>
          <td><span class="badge b-<?=$t->tipo?>"><?=$t->tipo?></span></td>
          <td><?=htmlspecialchars($t->valor)?></td>
          <td><?=$t->linha?></td>
        </tr>
        <?php endforeach; $ln++; endforeach; ?>
        </tbody></table>
        <?php else: ?><div class="empty"><div class="ei">🔤</div><div>Nenhum token.</div></div><?php endif; ?>
      </div>

      <!-- AST -->
      <div id="tab-ast" class="tp">
        <?php if($resultado['ast']!==null): ?>
        <div class="aw"><?=astHtml($resultado['ast'])?></div>
        <?php else: ?><div class="empty"><div class="ei">🌳</div><div>AST não gerada.</div></div><?php endif; ?>
      </div>

      <!-- SEMÂNTICA -->
      <div id="tab-sem" class="tp">
        <?php if($resultado['semantica']!==null): $s=$resultado['semantica']; ?>
        <div class="ss"><div class="st">Status</div>
          <?php if($s['valido']): ?>
            <div style="color:var(--green);font-family:var(--mono);font-size:.82rem">✓ Semântica aprovada</div>
          <?php else: foreach($s['erros'] as $e): ?>
            <div class="eb"><div class="em"><?=htmlspecialchars($e['msg'])?></div>
            <?php if($e['linha']): ?><div class="el">Linha <?=$e['linha']?></div><?php endif; ?></div>
          <?php endforeach; endif; ?>
        </div>
        <?php if(!empty($s['avisos'])): ?>
        <div class="ss"><div class="st" style="color:var(--orange)">Avisos</div>
          <?php foreach($s['avisos'] as $a): ?>
          <div class="ai">⚠ <?=htmlspecialchars($a['msg'])?> (linha <?=$a['linha']?>)</div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="ss"><div class="st">Tabela de Símbolos</div>
          <table><thead><tr><th>Variável</th><th>Escopo</th><th>Tipo</th><th>Decl.</th><th>Uso</th><th>Valor</th><th>Usada</th></tr></thead><tbody>
          <?php foreach($s['tabela'] as $sym): ?>
          <tr>
            <td class="dec"><?=htmlspecialchars($sym['nome'])?></td>
            <td class="<?=$sym['escopo']==='global'?'eg':'el2'?>"><?=$sym['escopo']?></td>
            <td class="t<?=$sym['tipo'][0]?>"><?=$sym['tipo']?></td>
            <td><?=$sym['linha_decl']?></td>
            <td><?=$sym['linha_uso']??'—'?></td>
            <td><?=htmlspecialchars((string)$sym['valor'])?></td>
            <td class="<?=$sym['usada']?'uy':'un_'?>"><?=$sym['usada']?'✓':'✗'?></td>
          </tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php else: ?><div class="empty"><div class="ei">🔍</div><div>Semântica não realizada.</div></div><?php endif; ?>
      </div>

      <!-- VARIÁVEIS -->
      <div id="tab-vars" class="tp">
        <?php if(!empty($resultado['execucao']['variaveis'])): ?>
        <table><thead><tr><th>Variável</th><th>Tipo</th><th>Valor Final</th></tr></thead><tbody>
        <?php foreach($resultado['execucao']['variaveis'] as $n=>$v):
          $tipo=is_float($v)?'float':(is_string($v)?'string':'int'); ?>
        <tr>
          <td class="dec"><?=htmlspecialchars($n)?></td>
          <td class="t<?=$tipo[0]?>"><?=$tipo?></td>
          <td><?=htmlspecialchars((string)$v)?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php else: ?><div class="empty"><div class="ei">📦</div><div>Nenhuma variável.</div></div><?php endif; ?>
      </div>

      <!-- FUNÇÕES -->
      <?php if($nf): ?>
      <div id="tab-funcs" class="tp">
        <table><thead><tr><th>Função</th><th>Escopo interno</th></tr></thead><tbody>
        <?php foreach(($resultado['semantica']['funcoes']??[]) as $fn): ?>
        <tr>
          <td style="color:#fd79a8;font-family:var(--mono)"><?=htmlspecialchars($fn)?>()</td>
          <td style="color:var(--muted)">local — variáveis internas somem ao sair da função</td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function aba(id,btn){
  document.querySelectorAll('.tp').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  btn.classList.add('active');
}
function toggleRef(){
  const b=document.getElementById('rb'),a=document.getElementById('ra');
  const o=b.classList.toggle('open');
  a.textContent=o?'▾':'▸';
}
document.getElementById('codigo').addEventListener('keydown',function(e){
  if(e.key==='Tab'){
    e.preventDefault();
    const s=this.selectionStart,end=this.selectionEnd;
    this.value=this.value.substring(0,s)+'  '+this.value.substring(end);
    this.selectionStart=this.selectionEnd=s+2;
  }
});
</script>
</body>
</html>
