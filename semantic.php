<?php
// ============================================================
// SEMANTIC.PHP — Análise Semântica
// Percorre a AST e verifica:
//   1. Variáveis usadas antes de declarar
//   2. Divisão por zero literal
//   3. Variáveis não utilizadas (aviso, não erro)
//   4. Condições sempre verdadeiras/falsas com literais
// ============================================================

require_once __DIR__ . '/parser.php';

// ============================================================
// SemanticError — erro semântico com linha
// ============================================================
class MiniSemanticError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha = 0) {
        parent::__construct($msg);
        $this->linhaCodigo = $linha;
    }
}

// ============================================================
// Tabela de Símbolos
// ============================================================
class TabelaDeSimbolos {
    private array $declaradas  = []; // variáveis declaradas (atrib / input)
    private array $utilizadas  = []; // variáveis lidas
    private array $valores     = []; // valor literal se conhecido

    public function declarar(string $nome, int $linha, $valor = null): void {
        $this->declaradas[$nome] = $linha;
        if ($valor !== null) {
            $this->valores[$nome] = $valor;
        } else {
            unset($this->valores[$nome]); // valor desconhecido (input)
        }
    }

    public function usar(string $nome, int $linha): void {
        if (!isset($this->declaradas[$nome])) {
            throw new MiniSemanticError(
                "Variável '$nome' usada antes de ser declarada", $linha
            );
        }
        $this->utilizadas[$nome] = $linha;
    }

    public function getValorConhecido(string $nome): ?int {
        return $this->valores[$nome] ?? null;
    }

    public function isDeclared(string $nome): bool {
        return isset($this->declaradas[$nome]);
    }

    // Retorna variáveis declaradas mas nunca lidas (avisos)
    public function getNaoUtilizadas(): array {
        $naoUsadas = [];
        foreach ($this->declaradas as $nome => $linha) {
            if (!isset($this->utilizadas[$nome])) {
                $naoUsadas[] = ['nome' => $nome, 'linha' => $linha];
            }
        }
        return $naoUsadas;
    }

    public function toArray(): array {
        $tabela = [];
        foreach ($this->declaradas as $nome => $linha) {
            $tabela[] = [
                'nome'      => $nome,
                'linha_decl'=> $linha,
                'linha_uso' => $this->utilizadas[$nome] ?? null,
                'valor'     => $this->valores[$nome] ?? '?',
                'usada'     => isset($this->utilizadas[$nome]),
            ];
        }
        return $tabela;
    }
}

// ============================================================
// Analisador Semântico
// ============================================================
class AnalisadorSemantico {

    private TabelaDeSimbolos $tabela;
    private array $erros   = [];
    private array $avisos  = [];

    public function __construct() {
        $this->tabela = new TabelaDeSimbolos();
    }

    // ── Ponto de entrada ─────────────────────────────────────
    public function analisar(array $ast): array {
        $this->erros  = [];
        $this->avisos = [];
        $this->tabela = new TabelaDeSimbolos();

        $this->visitarBloco($ast['corpo']);

        // Avisos de variáveis não utilizadas
        foreach ($this->tabela->getNaoUtilizadas() as $nu) {
            $this->avisos[] = [
                'msg'   => "Variável '{$nu['nome']}' declarada mas nunca utilizada",
                'linha' => $nu['linha'],
            ];
        }

        return [
            'erros'   => $this->erros,
            'avisos'  => $this->avisos,
            'tabela'  => $this->tabela->toArray(),
            'valido'  => empty($this->erros),
        ];
    }

    // ── Percorre bloco de statements ─────────────────────────
    private function visitarBloco(array $stmts): void {
        foreach ($stmts as $stmt) {
            $this->visitarStmt($stmt);
        }
    }

    // ── Despacha por tipo de nó ───────────────────────────────
    private function visitarStmt(array $no): void {
        switch ($no['no']) {

            case 'atrib':
                // Avalia lado direito primeiro (verifica uso de vars)
                $valor = $this->avaliarExpr($no['expr']);
                // Depois declara a variável
                $this->tabela->declarar($no['var']->valor, $no['linha'], $valor);
                break;

            case 'input':
                // INPUT declara variável com valor desconhecido
                $this->tabela->declarar($no['var']->valor, $no['linha'], null);
                break;

            case 'print':
                try {
                    $this->tabela->usar($no['var']->valor, $no['linha']);
                } catch (MiniSemanticError $e) {
                    $this->erros[] = ['msg' => $e->getMessage(), 'linha' => $e->linhaCodigo];
                }
                break;

            case 'if':
                $this->verificarCond($no['cond']);
                // Analisa ambos os blocos (mesmo sem saber qual será executado)
                $snapDecl = $this->tabela->toArray(); // snapshot para avisos
                $this->visitarBloco($no['entao']);
                $this->visitarBloco($no['senao']);
                break;

            case 'while':
                $this->verificarCond($no['cond']);
                $this->visitarBloco($no['corpo']);
                break;
        }
    }

    // ── Avalia expressão e retorna valor literal se possível ─
    private function avaliarExpr(array $no): ?int {
        switch ($no['no']) {
            case 'num':
                return $no['valor'];

            case 'var':
                try {
                    $this->tabela->usar($no['nome'], $no['linha']);
                    return $this->tabela->getValorConhecido($no['nome']);
                } catch (MiniSemanticError $e) {
                    $this->erros[] = ['msg' => $e->getMessage(), 'linha' => $e->linhaCodigo];
                    return null;
                }

            case 'binop':
                $esq = $this->avaliarExpr($no['esq']);
                $dir = $this->avaliarExpr($no['dir']);

                // Verifica divisão por zero literal
                if ($no['op'] === '/' && $dir === 0) {
                    $this->erros[] = [
                        'msg'   => "Divisão por zero detectada",
                        'linha' => $no['linha'],
                    ];
                    return null;
                }

                if ($esq !== null && $dir !== null) {
                    return match($no['op']) {
                        '+'  => $esq + $dir,
                        '-'  => $esq - $dir,
                        '*'  => $esq * $dir,
                        '/'  => ($dir != 0) ? intdiv($esq, $dir) : null,
                        default => null,
                    };
                }
                return null;
        }
        return null;
    }

    // ── Verifica condição (variáveis declaradas + aviso literal) ─
    private function verificarCond(array $no): void {
        switch ($no['no']) {
            case 'cmp':
                $esq = $this->avaliarExpr($no['esq']);
                $dir = $this->avaliarExpr($no['dir']);

                // Aviso: condição sempre verdadeira ou sempre falsa
                if ($esq !== null && $dir !== null) {
                    $resultado = match($no['op']) {
                        '==' => $esq == $dir,
                        '!=' => $esq != $dir,
                        '<'  => $esq  < $dir,
                        '>'  => $esq  > $dir,
                        '<=' => $esq <= $dir,
                        '>=' => $esq >= $dir,
                        default => null,
                    };
                    if ($resultado !== null) {
                        $txt = $resultado ? 'sempre verdadeira' : 'sempre falsa';
                        $this->avisos[] = [
                            'msg'   => "Condição com literais é $txt ($esq {$no['op']} $dir)",
                            'linha' => $no['linha'] ?? 0,
                        ];
                    }
                }
                break;

            case 'log':
                $this->verificarCond($no['esq']);
                $this->verificarCond($no['dir']);
                break;
        }
    }

    // ── Getters ───────────────────────────────────────────────
    public function getTabela(): TabelaDeSimbolos { return $this->tabela; }
}
