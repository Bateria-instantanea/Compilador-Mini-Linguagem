<?php
// ============================================================
// INTERPRETER.PHP — Van Language v2.0
// Execução com escopo local/global, for, while, funções,
// float, string, template
// ============================================================

require_once __DIR__ . '/semantic.php';

class MiniRuntimeError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha = 0) {
        parent::__construct($msg);
        $this->linhaCodigo = $linha;
    }
}

class Passo {
    public int     $numero;
    public string  $descricao;
    public string  $instrucao;
    public array   $variaveis;
    public ?string $saida;
    public int     $linha;

    public function __construct(int $numero, string $descricao, string $instrucao,
                                array $variaveis, ?string $saida, int $linha) {
        $this->numero    = $numero;
        $this->descricao = $descricao;
        $this->instrucao = $instrucao;
        $this->variaveis = $variaveis;
        $this->saida     = $saida;
        $this->linha     = $linha;
    }
}

// ============================================================
class Interpretador {

    private array  $global      = [];
    private array  $local       = [];
    private bool   $emFuncao    = false;
    private array  $funcoes     = [];
    private array  $passos      = [];
    private int    $numPasso    = 0;
    private string $saida       = '';
    private array  $inputs      = [];
    private int    $iterMax     = 1000;
    private bool   $returnFlag  = false;

    public function __construct(array $inputs = []) { $this->inputs = $inputs; }

    public function executar(array $ast): array {
        $this->global     = [];
        $this->local      = [];
        $this->emFuncao   = false;
        $this->funcoes    = [];
        $this->passos     = [];
        $this->numPasso   = 0;
        $this->saida      = '';
        $this->returnFlag = false;

        // Pré-carrega inputs
        foreach ($this->inputs as $k => $v) $this->global[$k] = $v;

        // Registra funções
        foreach ($ast['corpo'] as $stmt) {
            if ($stmt['no'] === 'funcdef') $this->funcoes[$stmt['nome']] = $stmt['corpo'];
        }

        $this->executarBloco($ast['corpo']);

        return [
            'saida'     => $this->saida,
            'variaveis' => $this->global,
            'passos'    => array_map(fn($p) => [
                'numero'    => $p->numero,
                'descricao' => $p->descricao,
                'instrucao' => $p->instrucao,
                'variaveis' => $p->variaveis,
                'saida'     => $p->saida,
                'linha'     => $p->linha,
            ], $this->passos),
        ];
    }

    private function executarBloco(array $stmts): void {
        foreach ($stmts as $stmt) {
            if ($this->returnFlag) break;
            $this->executarStmt($stmt);
        }
    }

    private function executarStmt(array $no): void {
        $linha = $no['linha'] ?? 0;

        switch ($no['no']) {

            // ── Atribuição int ────────────────────────────────
            case 'atrib': {
                $nome  = $no['var']->valor;
                $valor = $this->avaliarExpr($no['expr']);
                // Mantém como int se não houver parte decimal
                if (is_float($valor) && floor($valor) == $valor) $valor = (int)$valor;
                $ant   = $this->lerVar($nome);
                $this->escreverVar($nome, $valor);
                $desc  = $ant === null ? "Declara '$nome' = $valor" : "Atualiza '$nome': $ant → $valor";
                $this->passo($desc, "$nome ? ".$this->exprStr($no['expr']), null, $linha);
                break;
            }

            // ── Atribuição float ──────────────────────────────
            case 'fatrib': {
                $nome  = $no['var']->valor;
                $valor = (float)$this->avaliarExpr($no['expr']);
                $ant   = $this->lerVar($nome);
                $this->escreverVar($nome, $valor);
                $desc  = $ant === null ? "Declara float '$nome' = $valor" : "Atualiza '$nome': $ant → $valor";
                $this->passo($desc, "$nome ? ".$this->exprStr($no['expr']), null, $linha);
                break;
            }

            // ── Atribuição string ─────────────────────────────
            case 'satrib': {
                $nome  = $no['var']->valor;
                $valor = $this->resolverTemplate($no['partes'], $linha);
                $this->escreverVar($nome, $valor);
                $this->passo("Declara string '$nome' = \"$valor\"", "$nome ? @@...@@", null, $linha);
                break;
            }

            // ── Input ─────────────────────────────────────────
            case 'input': {
                $t    = $no['var'];
                $nome = $t->valor;
                $val  = $this->lerVar($nome) ?? 0;
                if ($t->tipo === 'FVAR') $val = (float)$val;
                elseif ($t->tipo === 'VAR') $val = is_numeric($val) ? (int)$val : 0;
                $this->escreverVar($nome, $val);
                $this->passo("INPUT '$nome' = $val", "xec $nome", null, $linha);
                break;
            }

            // ── Print simples ─────────────────────────────────
            case 'print': {
                $nome  = $no['var']->valor;
                $valor = $this->lerVar($nome);
                if ($valor === null) throw new MiniRuntimeError("Variável '$nome' não definida", $linha);
                $txt = "PRINT → $valor";
                $this->saida .= $txt."\n";
                $this->passo("Imprime '$nome' = $valor", "zec $nome", $txt, $linha);
                break;
            }

            // ── Print template ────────────────────────────────
            case 'print_tmpl': {
                $txt = $this->resolverTemplate($no['partes'], $linha);
                $this->saida .= "PRINT → $txt\n";
                $this->passo("Imprime: \"$txt\"", "zec ...", "PRINT → $txt", $linha);
                break;
            }

            // ── IF ────────────────────────────────────────────
            case 'if': {
                $res     = $this->avaliarCond($no['cond']);
                $condStr = $this->condStr($no['cond']);
                $branch  = $res ? 'VERDADEIRA → then' : 'FALSA → else';
                $this->passo("IF [$condStr] $branch", "cs $condStr", null, $linha);
                $this->executarBloco($res ? $no['entao'] : $no['senao']);
                break;
            }

            // ── WHILE ─────────────────────────────────────────
            case 'while': {
                $condStr = $this->condStr($no['cond']);
                $iter    = 0;
                while (!$this->returnFlag) {
                    $res = $this->avaliarCond($no['cond']);
                    $branch = $res ? 'VERDADEIRA → itera' : 'FALSA → sai';
                    $this->passo("WHILE[$iter] [$condStr] $branch", "wh $condStr", null, $linha);
                    if (!$res) break;
                    if (++$iter > $this->iterMax)
                        throw new MiniRuntimeError("Loop infinito detectado (limite {$this->iterMax})", $linha);
                    $this->executarBloco($no['corpo']);
                }
                break;
            }

            // ── FOR ───────────────────────────────────────────
            case 'for': {
                $this->executarStmt($no['init']);
                $condStr = $this->condStr($no['cond']);
                $iter    = 0;
                while (!$this->returnFlag) {
                    $res = $this->avaliarCond($no['cond']);
                    $branch = $res ? 'VERDADEIRA → itera' : 'FALSA → sai';
                    $this->passo("FOR[$iter] [$condStr] $branch", "fr $condStr", null, $linha);
                    if (!$res) break;
                    if (++$iter > $this->iterMax)
                        throw new MiniRuntimeError("Loop infinito no for (limite {$this->iterMax})", $linha);
                    $this->executarBloco($no['corpo']);
                    $this->executarStmt($no['step']);
                }
                break;
            }

            // ── Declaração de função (só registra) ───────────
            case 'funcdef': {
                $this->passo("Função '{$no['nome']}' registrada", "fn {$no['nome']}() {}", null, $linha);
                break;
            }

            // ── Chamada de função ─────────────────────────────
            case 'funccall': {
                $nome = $no['nome'];
                if (!isset($this->funcoes[$nome]))
                    throw new MiniRuntimeError("Função '$nome' não definida", $linha);
                $this->passo("Chama '$nome'", "$nome", null, $linha);

                // Salva contexto e entra no escopo local
                $localAnt    = $this->local;
                $emFuncaoAnt = $this->emFuncao;
                $this->local    = [];
                $this->emFuncao = true;
                $this->returnFlag = false;

                $this->executarBloco($this->funcoes[$nome]);

                // Restaura contexto
                $this->returnFlag = false;
                $this->local      = $localAnt;
                $this->emFuncao   = $emFuncaoAnt;
                break;
            }

            // ── Return ────────────────────────────────────────
            case 'return': {
                $val = $no['expr'] ? $this->avaliarExpr($no['expr']) : null;
                $this->returnFlag = true;
                $this->passo("Return ".($val ?? ''), "ret", null, $linha);
                break;
            }
        }
    }

    // ── Escopo ────────────────────────────────────────────────
    private function lerVar(string $nome): mixed {
        if ($this->emFuncao && array_key_exists($nome, $this->local)) return $this->local[$nome];
        return $this->global[$nome] ?? null;
    }

    private function escreverVar(string $nome, mixed $valor): void {
        if ($this->emFuncao) {
            // Se a variável já existe no global, atualiza o global (comportamento PHP-like)
            // Se não existe no global mas existe no local, atualiza local
            // Se não existe em nenhum, cria local
            if (array_key_exists($nome, $this->global)) {
                $this->global[$nome] = $valor;
            } else {
                $this->local[$nome] = $valor;
            }
        } else {
            $this->global[$nome] = $valor;
        }
    }

    // ── Template ──────────────────────────────────────────────
    private function resolverTemplate(array $partes, int $linha): string {
        $r = '';
        foreach ($partes as $p) {
            if ($p['no'] === 'strlit') { $r .= $p['valor']; continue; }
            $v = $this->lerVar($p['nome']);
            if ($v === null) throw new MiniRuntimeError("Variável '{$p['nome']}' não definida no template", $linha);
            $r .= $v;
        }
        return $r;
    }

    // ── Avalia expressão ──────────────────────────────────────
    private function avaliarExpr(array $no): int|float {
        return match($no['no']) {
            'num'   => $no['valor'],
            'fnum'  => $no['valor'],
            'var'   => $this->getNumVar($no['nome'], $no['linha']),
            'fvar'  => (float)$this->getNumVar($no['nome'], $no['linha']),
            'binop' => $this->avaliarBinop($no),
            default => 0,
        };
    }

    private function getNumVar(string $nome, int $linha): int|float {
        $v = $this->lerVar($nome);
        if ($v === null) throw new MiniRuntimeError("Variável '$nome' não definida", $linha);
        return $v;
    }

    private function avaliarBinop(array $no): int|float {
        $e = $this->avaliarExpr($no['esq']);
        $d = $this->avaliarExpr($no['dir']);
        return match($no['op']) {
            '+' => $e + $d,
            '-' => $e - $d,
            '*' => $e * $d,
            '/' => $d != 0 ? $e / $d : throw new MiniRuntimeError("Divisão por zero", $no['linha'] ?? 0),
            default => 0,
        };
    }

    // ── Condição ──────────────────────────────────────────────
    private function avaliarCond(array $no): bool {
        if ($no['no'] === 'cmp') {
            $e = $this->avaliarExpr($no['esq']);
            $d = $this->avaliarExpr($no['dir']);
            return match($no['op']) {
                '=='=>$e==$d,'!='=>$e!=$d,'<'=>$e<$d,'>'=>$e>$d,'<='=>$e<=$d,'>='=>$e>=$d,
                default=>false,
            };
        }
        if ($no['no'] === 'log') {
            return match($no['op']) {
                '&&' => $this->avaliarCond($no['esq']) && $this->avaliarCond($no['dir']),
                '||' => $this->avaliarCond($no['esq']) || $this->avaliarCond($no['dir']),
                default => false,
            };
        }
        return false;
    }

    // ── Passo ─────────────────────────────────────────────────
    private function passo(string $desc, string $instrucao, ?string $saida, int $linha): void {
        $this->numPasso++;
        $snap = array_merge($this->global, $this->local);
        $this->passos[] = new Passo($this->numPasso, $desc, $instrucao, $snap, $saida, $linha);
    }

    // ── Repr. textual ─────────────────────────────────────────
    private function exprStr(array $no): string {
        return match($no['no']) {
            'num','fnum'         => (string)$no['valor'],
            'var','fvar','svar'  => $no['nome'],
            'binop'              => $this->exprStr($no['esq']).' '.$no['op'].' '.$this->exprStr($no['dir']),
            default              => '?',
        };
    }

    private function condStr(array $no): string {
        return match($no['no']) {
            'cmp'   => $this->exprStr($no['esq']).' '.$no['op'].' '.$this->exprStr($no['dir']),
            'log'   => $this->condStr($no['esq']).' '.$no['op'].' '.$this->condStr($no['dir']),
            default => '?',
        };
    }
}
