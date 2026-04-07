<?php
// ============================================================
// PARSER.PHP — Análise Sintática
// Recebe tokens do Lexer e gera uma AST (Árvore Sintática)
// ============================================================

require_once __DIR__ . '/lexer.php';

// ============================================================
// ParseError — erro sintático com linha
// ============================================================
class MiniParseError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha = 0) {
        parent::__construct($msg);
        $this->linhaCodigo = $linha;
    }
}

// ============================================================
// Nós da AST
// Cada estrutura é um array associativo com chave 'no'
// ============================================================
// Tipos de nó:
//   programa       → [ 'no'=>'programa', 'corpo'=>[...stmts] ]
//   atrib          → [ 'no'=>'atrib',    'var'=>Token, 'expr'=>nó ]
//   print          → [ 'no'=>'print',    'var'=>Token ]
//   input          → [ 'no'=>'input',    'var'=>Token ]
//   if             → [ 'no'=>'if',       'cond'=>nó,  'entao'=>[...], 'senao'=>[...] ]
//   while          → [ 'no'=>'while',    'cond'=>nó,  'corpo'=>[...] ]
//   binop          → [ 'no'=>'binop',    'op'=>'+',   'esq'=>nó, 'dir'=>nó ]
//   comparacao     → [ 'no'=>'cmp',      'op'=>'==',  'esq'=>nó, 'dir'=>nó ]
//   logico         → [ 'no'=>'log',      'op'=>'&&',  'esq'=>nó, 'dir'=>nó ]
//   var            → [ 'no'=>'var',      'nome'=>str ]
//   num            → [ 'no'=>'num',      'valor'=>int ]

// ============================================================
// Parser — classe principal
// ============================================================
class Parser {

    private array $linhas;   // lista de listas de Token
    private int   $pos;      // índice da linha atual
    private array $cmpMap;
    private array $opMap;
    private array $logMap;

    public function __construct(array $cmpMap, array $opMap, array $logMap) {
        $this->cmpMap = $cmpMap;
        $this->opMap  = $opMap;
        $this->logMap = $logMap;
    }

    // ── Ponto de entrada ─────────────────────────────────────
    public function parse(array $linhasDeTokens): array {
        $this->linhas = $linhasDeTokens;
        $this->pos    = 0;
        $corpo = $this->parseBloco(['END', 'ELSE']); // para de produzir em END ou ELSE
        return ['no' => 'programa', 'corpo' => $corpo];
    }

    // ── Consome instruções até encontrar um stop-token ────────
    private function parseBloco(array $stopTipos): array {
        $stmts = [];
        while ($this->pos < count($this->linhas)) {
            $linha = $this->linhas[$this->pos];
            $tipo0 = $linha[0]->tipo;

            if (in_array($tipo0, $stopTipos)) break;

            $stmts[] = $this->parseStatement($linha);
            $this->pos++;
        }
        return $stmts;
    }

    // ── Despacha para o parser de cada instrução ─────────────
    private function parseStatement(array $tokens): array {
        $tipo0 = $tokens[0]->tipo;
        $linha0 = $tokens[0]->linha;

        switch ($tipo0) {

            // VAR ? expr
            case 'VAR':
                if (count($tokens) < 3 || $tokens[1]->tipo !== 'ATRIB') {
                    throw new MiniParseError(
                        "Esperado '?' após variável '{$tokens[0]->valor}'",
                        $linha0
                    );
                }
                $expr = $this->parseExpr(array_slice($tokens, 2), $linha0);
                return [
                    'no'   => 'atrib',
                    'var'  => $tokens[0],
                    'expr' => $expr,
                    'linha'=> $linha0,
                ];

            // zec VAR
            case 'PRINT':
                if (count($tokens) !== 2 || $tokens[1]->tipo !== 'VAR') {
                    throw new MiniParseError(
                        "'zec' deve ser seguido de exatamente uma variável",
                        $linha0
                    );
                }
                return ['no' => 'print', 'var' => $tokens[1], 'linha' => $linha0];

            // xec VAR
            case 'INPUT':
                if (count($tokens) !== 2 || $tokens[1]->tipo !== 'VAR') {
                    throw new MiniParseError(
                        "'xec' deve ser seguido de exatamente uma variável",
                        $linha0
                    );
                }
                return ['no' => 'input', 'var' => $tokens[1], 'linha' => $linha0];

            // ez <cond> ... [oz ...] bz
            case 'IF':
                return $this->parseIf($tokens, $linha0);

            // uz <cond> ... bz
            case 'WHILE':
                return $this->parseWhile($tokens, $linha0);

            default:
                throw new MiniParseError(
                    "Instrução inesperada com token '{$tokens[0]->valor}'",
                    $linha0
                );
        }
    }

    // ── Parseia IF ────────────────────────────────────────────
    private function parseIf(array $tokens, int $linha): array {
        $condTokens = array_slice($tokens, 1);
        if (empty($condTokens)) {
            throw new MiniParseError("'ez' (if) sem condição", $linha);
        }
        $cond = $this->parseCond($condTokens, $linha);

        $this->pos++;
        $blocoEntao = $this->parseBloco(['END', 'ELSE']);

        $blocoSenao = [];
        if ($this->pos < count($this->linhas) && $this->linhas[$this->pos][0]->tipo === 'ELSE') {
            $this->pos++;
            $blocoSenao = $this->parseBloco(['END']);
        }

        // Consome END (bz)
        if ($this->pos >= count($this->linhas) || $this->linhas[$this->pos][0]->tipo !== 'END') {
            throw new MiniParseError("'ez' (if) sem 'bz' (end) correspondente", $linha);
        }

        return [
            'no'    => 'if',
            'cond'  => $cond,
            'entao' => $blocoEntao,
            'senao' => $blocoSenao,
            'linha' => $linha,
        ];
    }

    // ── Parseia WHILE ─────────────────────────────────────────
    private function parseWhile(array $tokens, int $linha): array {
        $condTokens = array_slice($tokens, 1);
        if (empty($condTokens)) {
            throw new MiniParseError("'uz' (while) sem condição", $linha);
        }
        $cond = $this->parseCond($condTokens, $linha);

        $this->pos++;
        $corpo = $this->parseBloco(['END']);

        if ($this->pos >= count($this->linhas) || $this->linhas[$this->pos][0]->tipo !== 'END') {
            throw new MiniParseError("'uz' (while) sem 'bz' (end) correspondente", $linha);
        }

        return [
            'no'    => 'while',
            'cond'  => $cond,
            'corpo' => $corpo,
            'linha' => $linha,
        ];
    }

    // ── Parseia condição: expr CMP expr [LOG expr CMP expr]* ─
    private function parseCond(array $tokens, int $linha): array {
        $pos = 0;
        $total = count($tokens);

        // Lado esquerdo
        [$esq, $pos] = $this->parseExprPos($tokens, $pos, $linha);

        if ($pos >= $total || $tokens[$pos]->tipo !== 'CMP') {
            throw new MiniParseError("Esperado operador de comparação na condição", $linha);
        }

        $opCmp = $this->cmpMap[$tokens[$pos]->valor];
        $pos++;

        [$dir, $pos] = $this->parseExprPos($tokens, $pos, $linha);

        $no = ['no' => 'cmp', 'op' => $opCmp, 'esq' => $esq, 'dir' => $dir, 'linha' => $linha];

        // Operadores lógicos encadeados
        while ($pos < $total && $tokens[$pos]->tipo === 'LOG') {
            $opLog = $this->logMap[$tokens[$pos]->valor];
            $pos++;

            [$esq2, $pos] = $this->parseExprPos($tokens, $pos, $linha);

            if ($pos >= $total || $tokens[$pos]->tipo !== 'CMP') {
                throw new MiniParseError("Esperado comparador após operador lógico", $linha);
            }
            $opCmp2 = $this->cmpMap[$tokens[$pos]->valor];
            $pos++;

            [$dir2, $pos] = $this->parseExprPos($tokens, $pos, $linha);

            $direita = ['no' => 'cmp', 'op' => $opCmp2, 'esq' => $esq2, 'dir' => $dir2, 'linha' => $linha];
            $no = ['no' => 'log', 'op' => $opLog, 'esq' => $no, 'dir' => $direita, 'linha' => $linha];
        }

        return $no;
    }

    // ── Parseia expressão aritmética (array inteiro) ──────────
    private function parseExpr(array $tokens, int $linha): array {
        [$no, $pos] = $this->parseExprPos($tokens, 0, $linha);
        if ($pos < count($tokens)) {
            throw new MiniParseError("Tokens inesperados após expressão", $linha);
        }
        return $no;
    }

    // ── Parseia expressão a partir de $pos (retorna [nó, novaPos]) ─
    private function parseExprPos(array $tokens, int $pos, int $linha): array {
        if ($pos >= count($tokens)) {
            throw new MiniParseError("Expressão incompleta", $linha);
        }
        $t = $tokens[$pos];
        if ($t->tipo === 'VAR') {
            $esq = ['no' => 'var', 'nome' => $t->valor, 'linha' => $t->linha];
        } elseif ($t->tipo === 'NUM') {
            $esq = ['no' => 'num', 'valor' => Lexer::converterNumero($t->valor), 'linha' => $t->linha];
        } else {
            throw new MiniParseError("Esperado variável ou número, encontrado '{$t->valor}'", $linha);
        }
        $pos++;

        // Operador aritmético opcional
        if ($pos < count($tokens) && $tokens[$pos]->tipo === 'OP') {
            $op = $this->opMap[$tokens[$pos]->valor];
            $pos++;
            if ($pos >= count($tokens)) {
                throw new MiniParseError("Expressão incompleta após operador '{$tokens[$pos-1]->valor}'", $linha);
            }
            $t2 = $tokens[$pos];
            if ($t2->tipo === 'VAR') {
                $dir = ['no' => 'var', 'nome' => $t2->valor, 'linha' => $t2->linha];
            } elseif ($t2->tipo === 'NUM') {
                $dir = ['no' => 'num', 'valor' => Lexer::converterNumero($t2->valor), 'linha' => $t2->linha];
            } else {
                throw new MiniParseError("Esperado variável ou número após operador", $linha);
            }
            $pos++;
            $esq = ['no' => 'binop', 'op' => $op, 'esq' => $esq, 'dir' => $dir, 'linha' => $linha];
        }

        return [$esq, $pos];
    }
}
