<?php
// ============================================================
// INTERPRETER.PHP — Executor / Interpretador
// Percorre a AST e executa o programa
// Suporta: execução completa e execução passo a passo
// ============================================================

require_once __DIR__ . '/semantic.php';

// ============================================================
// RuntimeError — erro em tempo de execução
// ============================================================
class MiniRuntimeError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha = 0) {
        parent::__construct($msg);
        $this->linhaCodigo = $linha;
    }
}

// ============================================================
// Passo — representa uma etapa da execução passo a passo
// ============================================================
class Passo {
    public int    $numero;
    public string $descricao;
    public string $instrucao;
    public array  $variaveis;   // snapshot das variáveis naquele instante
    public ?string $saida;      // saída gerada neste passo (se houver)
    public int    $linha;

    public function __construct(
        int $numero, string $descricao, string $instrucao,
        array $variaveis, ?string $saida, int $linha
    ) {
        $this->numero     = $numero;
        $this->descricao  = $descricao;
        $this->instrucao  = $instrucao;
        $this->variaveis  = $variaveis;
        $this->saida      = $saida;
        $this->linha      = $linha;
    }
}

// ============================================================
// Interpretador
// ============================================================
class Interpretador {

    private array  $vars     = [];   // tabela de variáveis em tempo de execução
    private array  $passos   = [];   // histórico passo a passo
    private int    $numPasso = 0;
    private array  $inputs   = [];   // valores de INPUT fornecidos externamente
    private int    $iterMax  = 1000; // proteção contra loop infinito
    private string $saida    = '';

    public function __construct(array $inputs = []) {
        $this->inputs = $inputs;
    }

    // ── Execução completa ─────────────────────────────────────
    public function executar(array $ast): array {
        $this->vars     = [];
        $this->passos   = [];
        $this->numPasso = 0;
        $this->saida    = '';

        // Pré-carrega inputs
        foreach ($this->inputs as $k => $v) {
            $this->vars[$k] = (int)$v;
        }

        $this->executarBloco($ast['corpo']);

        return [
            'saida'     => $this->saida,
            'variaveis' => $this->vars,
            'passos'    => array_map(fn($p) => [
                'numero'     => $p->numero,
                'descricao'  => $p->descricao,
                'instrucao'  => $p->instrucao,
                'variaveis'  => $p->variaveis,
                'saida'      => $p->saida,
                'linha'      => $p->linha,
            ], $this->passos),
        ];
    }

    // ── Executa bloco de statements ──────────────────────────
    private function executarBloco(array $stmts, int $profundidade = 0, int &$guard = 0): void {
        foreach ($stmts as $stmt) {
            $this->executarStmt($stmt, $profundidade, $guard);
        }
    }

    // ── Executa um statement ──────────────────────────────────
    private function executarStmt(array $no, int $prof = 0, int &$guard = 0): void {
        $linha = $no['linha'] ?? 0;

        switch ($no['no']) {

            // ── ATRIBUIÇÃO ─────────────────────────────────────
            case 'atrib': {
                $valor = $this->avaliarExpr($no['expr']);
                $nome  = $no['var']->valor;
                $anterior = $this->vars[$nome] ?? null;
                $this->vars[$nome] = $valor;

                $desc = $anterior === null
                    ? "Declara variável '$nome' = $valor"
                    : "Atualiza variável '$nome': $anterior → $valor";

                $this->registrarPasso($desc, "$nome ? " . $this->exprStr($no['expr']), null, $linha);
                break;
            }

            // ── INPUT ──────────────────────────────────────────
            case 'input': {
                $nome = $no['var']->valor;
                $valor = $this->vars[$nome] ?? 0; // já foi carregado do formulário
                $this->registrarPasso(
                    "INPUT: variável '$nome' recebe valor $valor (entrada do usuário)",
                    "xec $nome",
                    null,
                    $linha
                );
                break;
            }

            // ── PRINT ──────────────────────────────────────────
            case 'print': {
                $nome  = $no['var']->valor;
                $valor = $this->vars[$nome] ?? null;
                if ($valor === null) {
                    throw new MiniRuntimeError("Variável '$nome' não definida", $linha);
                }
                $txt = "PRINT → $valor";
                $this->saida .= $txt . "\n";
                $this->registrarPasso("Imprime '$nome' = $valor", "zec $nome", $txt, $linha);
                break;
            }

            // ── IF / ELSE ──────────────────────────────────────
            case 'if': {
                $resultado = $this->avaliarCond($no['cond']);
                $condStr   = $this->condStr($no['cond']);
                $branch    = $resultado ? 'VERDADEIRA → executa bloco then' : 'FALSA → executa bloco else';

                $this->registrarPasso(
                    "IF: condição [$condStr] é $branch",
                    "ez $condStr",
                    null,
                    $linha
                );

                if ($resultado) {
                    $this->executarBloco($no['entao'], $prof + 1, $guard);
                } else {
                    $this->executarBloco($no['senao'], $prof + 1, $guard);
                }
                break;
            }

            // ── WHILE ─────────────────────────────────────────
            case 'while': {
                $iter    = 0;
                $condStr = $this->condStr($no['cond']);

                while (true) {
                    $resultado = $this->avaliarCond($no['cond']);
                    $branch    = $resultado ? 'VERDADEIRA → itera' : 'FALSA → sai do loop';

                    $this->registrarPasso(
                        "WHILE [$iter]: condição [$condStr] é $branch",
                        "uz $condStr",
                        null,
                        $linha
                    );

                    if (!$resultado) break;

                    $iter++;
                    $guard++;
                    if ($iter > $this->iterMax) {
                        throw new MiniRuntimeError(
                            "Loop infinito detectado (limite: {$this->iterMax} iterações)",
                            $linha
                        );
                    }

                    $this->executarBloco($no['corpo'], $prof + 1, $guard);
                }
                break;
            }
        }
    }

    // ── Avalia expressão aritmética → int ─────────────────────
    private function avaliarExpr(array $no): int {
        switch ($no['no']) {
            case 'num':
                return $no['valor'];

            case 'var':
                $nome = $no['nome'];
                if (!isset($this->vars[$nome])) {
                    throw new MiniRuntimeError("Variável '$nome' não definida", $no['linha'] ?? 0);
                }
                return $this->vars[$nome];

            case 'binop':
                $esq = $this->avaliarExpr($no['esq']);
                $dir = $this->avaliarExpr($no['dir']);
                return match($no['op']) {
                    '+'  => $esq + $dir,
                    '-'  => $esq - $dir,
                    '*'  => $esq * $dir,
                    '/'  => $dir != 0 ? intdiv($esq, $dir) : throw new MiniRuntimeError("Divisão por zero", $no['linha'] ?? 0),
                    default => 0,
                };
        }
        return 0;
    }

    // ── Avalia condição → bool ────────────────────────────────
    private function avaliarCond(array $no): bool {
        switch ($no['no']) {
            case 'cmp':
                $esq = $this->avaliarExpr($no['esq']);
                $dir = $this->avaliarExpr($no['dir']);
                return match($no['op']) {
                    '==' => $esq == $dir,
                    '!=' => $esq != $dir,
                    '<'  => $esq  < $dir,
                    '>'  => $esq  > $dir,
                    '<=' => $esq <= $dir,
                    '>=' => $esq >= $dir,
                    default => false,
                };

            case 'log':
                $esq = $this->avaliarCond($no['esq']);
                $dir = $this->avaliarCond($no['dir']);
                return match($no['op']) {
                    '&&' => $esq && $dir,
                    '||' => $esq || $dir,
                    default => false,
                };
        }
        return false;
    }

    // ── Registra passo ────────────────────────────────────────
    private function registrarPasso(string $desc, string $instrucao, ?string $saida, int $linha): void {
        $this->numPasso++;
        $this->passos[] = new Passo(
            $this->numPasso,
            $desc,
            $instrucao,
            $this->vars,  // snapshot atual
            $saida,
            $linha
        );
    }

    // ── Representação textual de expressão ────────────────────
    private function exprStr(array $no): string {
        return match($no['no']) {
            'num'   => (string)$no['valor'],
            'var'   => $no['nome'],
            'binop' => $this->exprStr($no['esq']) . ' ' . $no['op'] . ' ' . $this->exprStr($no['dir']),
            default => '?',
        };
    }

    // ── Representação textual de condição ─────────────────────
    private function condStr(array $no): string {
        return match($no['no']) {
            'cmp' => $this->exprStr($no['esq']) . ' ' . $no['op'] . ' ' . $this->exprStr($no['dir']),
            'log' => $this->condStr($no['esq']) . ' ' . $no['op'] . ' ' . $this->condStr($no['dir']),
            default => '?',
        };
    }
}
