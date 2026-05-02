<?php
// ============================================================
// PARSER.PHP — Van Language v2.0
// Gera AST a partir dos tokens
// ============================================================

require_once __DIR__ . '/lexer.php';

class MiniParseError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha = 0) {
        parent::__construct($msg);
        $this->linhaCodigo = $linha;
    }
}

// ============================================================
// Nós da AST:
//   programa    corpo:[]
//   atrib       var:Token  expr:nó
//   fatrib      var:Token  expr:nó
//   satrib      var:Token  partes:[]
//   print       var:Token
//   print_tmpl  partes:[]
//   input       var:Token
//   if          cond  entao:[]  senao:[]
//   while       cond  corpo:[]
//   for         init  cond  step  corpo:[]
//   funcdef     nome  corpo:[]
//   funccall    nome
//   return      expr
//   var/fvar/svar  nome
//   num/fnum    valor
//   strlit      valor
//   binop       op  esq  dir
//   cmp         op  esq  dir
//   log         op  esq  dir
// ============================================================

class Parser {

    private array $linhas;
    private int   $pos;
    private array $cmpMap;
    private array $opMap;
    private array $logMap;

    public function __construct(array $cmpMap, array $opMap, array $logMap) {
        $this->cmpMap = $cmpMap;
        $this->opMap  = $opMap;
        $this->logMap = $logMap;
    }

    public function parse(array $linhasDeTokens): array {
        $this->linhas = $linhasDeTokens;
        $this->pos    = 0;
        $corpo = $this->parseBloco(['END','ELSE']);
        return ['no'=>'programa','corpo'=>$corpo];
    }

    // Consome instruções até stop-token (não consome o stop)
    private function parseBloco(array $stopTipos): array {
        $stmts = [];
        while ($this->pos < count($this->linhas)) {
            $t0 = $this->linhas[$this->pos][0]->tipo;
            if (in_array($t0, $stopTipos)) break;
            $stmts[] = $this->parseStatement($this->linhas[$this->pos]);
        }
        return $stmts;
    }

    private function parseStatement(array $tokens): array {
        $t0    = $tokens[0];
        $linha = $t0->linha;

        switch ($t0->tipo) {

            case 'VAR':
                $no = $this->parseAtrib($tokens, $linha, 'atrib');
                $this->pos++;
                return $no;

            case 'FVAR':
                $no = $this->parseAtrib($tokens, $linha, 'fatrib');
                $this->pos++;
                return $no;

            case 'SVAR':
                $no = $this->parseAtribStr($tokens, $linha);
                $this->pos++;
                return $no;

            case 'PRINT':
                $no = $this->parsePrint($tokens, $linha);
                $this->pos++;
                return $no;

            case 'INPUT':
                if (count($tokens) < 2 || !in_array($tokens[1]->tipo, ['VAR','FVAR','SVAR']))
                    throw new MiniParseError("'xec' deve ser seguido de uma variável", $linha);
                $this->pos++;
                return ['no'=>'input','var'=>$tokens[1],'linha'=>$linha];

            case 'IF':
                return $this->parseIf($tokens, $linha);

            case 'WHILE':
                return $this->parseWhile($tokens, $linha);

            case 'FOR':
                return $this->parseFor($tokens, $linha);

            case 'FUNC':
                return $this->parseFuncDef($tokens, $linha);

            case 'FNAME':
                $this->pos++;
                return ['no'=>'funccall','nome'=>$t0->valor,'linha'=>$linha];

            case 'RETURN':
                $expr = count($tokens) > 1
                    ? $this->parseExpr($this->filtrar($tokens, 1), $linha)
                    : null;
                $this->pos++;
                return ['no'=>'return','expr'=>$expr,'linha'=>$linha];

            default:
                throw new MiniParseError("Instrução inesperada: '{$t0->valor}'", $linha);
        }
    }

    // ── Atribuição numérica ───────────────────────────────────
    private function parseAtrib(array $tokens, int $linha, string $noTipo): array {
        if (count($tokens) < 3 || $tokens[1]->tipo !== 'ATRIB')
            throw new MiniParseError("Esperado '?' após '{$tokens[0]->valor}'", $linha);
        $expr = $this->parseExpr($this->filtrar($tokens, 2), $linha);
        return ['no'=>$noTipo,'var'=>$tokens[0],'expr'=>$expr,'linha'=>$linha];
    }

    // ── Atribuição string ─────────────────────────────────────
    private function parseAtribStr(array $tokens, int $linha): array {
        if (count($tokens) < 3 || $tokens[1]->tipo !== 'ATRIB')
            throw new MiniParseError("Esperado '?' após '{$tokens[0]->valor}'", $linha);
        $partes = $this->parseTemplate($this->filtrar($tokens, 2), $linha);
        return ['no'=>'satrib','var'=>$tokens[0],'partes'=>$partes,'linha'=>$linha];
    }

    // ── Print ─────────────────────────────────────────────────
    private function parsePrint(array $tokens, int $linha): array {
        $resto = $this->filtrar($tokens, 1);
        if (empty($resto)) throw new MiniParseError("'zec' sem argumento", $linha);
        // Print simples: só uma variável
        if (count($resto) === 1 && in_array($resto[0]->tipo, ['VAR','FVAR','SVAR']))
            return ['no'=>'print','var'=>$resto[0],'linha'=>$linha];
        // Template misto
        $partes = $this->parseTemplate($resto, $linha);
        return ['no'=>'print_tmpl','partes'=>$partes,'linha'=>$linha];
    }

    // ── Template de string ────────────────────────────────────
    private function parseTemplate(array $tokens, int $linha): array {
        $partes = [];
        foreach ($tokens as $t) {
            if ($t->tipo === 'STRLIT') {
                $partes[] = ['no'=>'strlit','valor'=>$t->valor];
            } elseif ($t->tipo === 'VAR') {
                $partes[] = ['no'=>'var', 'nome'=>$t->valor,'linha'=>$t->linha];
            } elseif ($t->tipo === 'FVAR') {
                $partes[] = ['no'=>'fvar','nome'=>$t->valor,'linha'=>$t->linha];
            } elseif ($t->tipo === 'SVAR') {
                $partes[] = ['no'=>'svar','nome'=>$t->valor,'linha'=>$t->linha];
            } else {
                throw new MiniParseError("Token inválido em template: '{$t->valor}'", $linha);
            }
        }
        return $partes;
    }

    // ── IF ────────────────────────────────────────────────────
    // cs COND {        ← linha atual
    //   ...entao...
    // cc {             ← opcional
    //   ...senao...
    // end              ← consumido aqui
    private function parseIf(array $tokens, int $linha): array {
        $condTokens = $this->filtrar($tokens, 1);
        if (empty($condTokens))
            throw new MiniParseError("'cs' sem condição", $linha);
        $cond = $this->parseCond($condTokens, $linha);

        $this->pos++;
        $blocoEntao = $this->parseBloco(['END','ELSE']);

        $blocoSenao = [];
        if ($this->pos < count($this->linhas) &&
            $this->linhas[$this->pos][0]->tipo === 'ELSE') {
            $this->pos++;
            $blocoSenao = $this->parseBloco(['END']);
        }

        if ($this->pos >= count($this->linhas) || $this->linhas[$this->pos][0]->tipo !== 'END')
            throw new MiniParseError("'cs' sem 'end' correspondente", $linha);
        $this->pos++;

        return ['no'=>'if','cond'=>$cond,'entao'=>$blocoEntao,'senao'=>$blocoSenao,'linha'=>$linha];
    }

    // ── WHILE ─────────────────────────────────────────────────
    // wh COND {
    //   ...corpo...
    // end
    private function parseWhile(array $tokens, int $linha): array {
        $condTokens = $this->filtrar($tokens, 1);
        if (empty($condTokens))
            throw new MiniParseError("'wh' sem condição", $linha);
        $cond = $this->parseCond($condTokens, $linha);

        $this->pos++;
        $corpo = $this->parseBloco(['END']);

        if ($this->pos >= count($this->linhas) || $this->linhas[$this->pos][0]->tipo !== 'END')
            throw new MiniParseError("'wh' sem 'end' correspondente", $linha);
        $this->pos++;

        return ['no'=>'while','cond'=>$cond,'corpo'=>$corpo,'linha'=>$linha];
    }

    // ── FOR ───────────────────────────────────────────────────
    // Sintaxe (3 instruções separadas por ;, depois o corpo):
    //   fr VAR ? EXPR;
    //   VAR CMP EXPR;
    //   VAR++ (ou VAR ? EXPR);
    //     ...corpo...
    //   end
    private function parseFor(array $tokens, int $linha): array {
        // Init: fr VAR ? EXPR
        if (count($tokens) < 4 || $tokens[1]->tipo !== 'VAR' || $tokens[2]->tipo !== 'ATRIB')
            throw new MiniParseError("For: esperado 'fr VAR ? EXPR'", $linha);
        $init = ['no'=>'atrib','var'=>$tokens[1],
                 'expr'=>$this->parseExpr($this->filtrar($tokens, 3), $linha),
                 'linha'=>$linha];

        // Cond (próxima instrução)
        $this->pos++;
        if ($this->pos >= count($this->linhas))
            throw new MiniParseError("For: condição ausente", $linha);
        $cond = $this->parseCond($this->filtrar($this->linhas[$this->pos], 0), $linha);

        // Step (próxima instrução)
        $this->pos++;
        if ($this->pos >= count($this->linhas))
            throw new MiniParseError("For: passo ausente", $linha);
        $step = $this->parseStep($this->filtrar($this->linhas[$this->pos], 0), $linha);

        // Corpo
        $this->pos++;
        $corpo = $this->parseBloco(['END']);

        if ($this->pos >= count($this->linhas) || $this->linhas[$this->pos][0]->tipo !== 'END')
            throw new MiniParseError("'fr' sem 'end' correspondente", $linha);
        $this->pos++;

        return ['no'=>'for','init'=>$init,'cond'=>$cond,'step'=>$step,'corpo'=>$corpo,'linha'=>$linha];
    }

    // Passo do for: ni++  |  ni--  |  ni++ colado  |  VAR ? EXPR
    private function parseStep(array $tokens, int $linha): array {
        // ni++ colado (VARINC do lexer)
        if (count($tokens) === 1 && $tokens[0]->tipo === 'VARINC') {
            $nome = $tokens[0]->valor;
            $fakeVar = new Token('VAR', $nome, $linha);
            return ['no'=>'atrib','var'=>$fakeVar,
                'expr'=>['no'=>'binop','op'=>'+',
                    'esq'=>['no'=>'var','nome'=>$nome,'linha'=>$linha],
                    'dir'=>['no'=>'num','valor'=>1,'linha'=>$linha],'linha'=>$linha],
                'linha'=>$linha];
        }
        // ni-- colado (VARDEC do lexer)
        if (count($tokens) === 1 && $tokens[0]->tipo === 'VARDEC') {
            $nome = $tokens[0]->valor;
            $fakeVar = new Token('VAR', $nome, $linha);
            return ['no'=>'atrib','var'=>$fakeVar,
                'expr'=>['no'=>'binop','op'=>'-',
                    'esq'=>['no'=>'var','nome'=>$nome,'linha'=>$linha],
                    'dir'=>['no'=>'num','valor'=>1,'linha'=>$linha],'linha'=>$linha],
                'linha'=>$linha];
        }
        // ni ++ separado
        if (count($tokens) === 2 && $tokens[1]->tipo === 'INC') {
            return ['no'=>'atrib','var'=>$tokens[0],
                'expr'=>['no'=>'binop','op'=>'+',
                    'esq'=>['no'=>'var','nome'=>$tokens[0]->valor,'linha'=>$linha],
                    'dir'=>['no'=>'num','valor'=>1,'linha'=>$linha],'linha'=>$linha],
                'linha'=>$linha];
        }
        // ni -- separado
        if (count($tokens) === 2 && $tokens[1]->tipo === 'DEC') {
            return ['no'=>'atrib','var'=>$tokens[0],
                'expr'=>['no'=>'binop','op'=>'-',
                    'esq'=>['no'=>'var','nome'=>$tokens[0]->valor,'linha'=>$linha],
                    'dir'=>['no'=>'num','valor'=>1,'linha'=>$linha],'linha'=>$linha],
                'linha'=>$linha];
        }
        return $this->parseAtrib($tokens, $linha, 'atrib');
    }

    // ── FUNÇÃO ────────────────────────────────────────────────
    // fn nome() {
    //   ...corpo...
    // end
    private function parseFuncDef(array $tokens, int $linha): array {
        if (count($tokens) < 2 || $tokens[1]->tipo !== 'FNAME')
            throw new MiniParseError("'fn' deve ser seguido do nome da função", $linha);
        $nome = $tokens[1]->valor;

        $this->pos++;
        $corpo = $this->parseBloco(['END']);

        if ($this->pos >= count($this->linhas) || $this->linhas[$this->pos][0]->tipo !== 'END')
            throw new MiniParseError("Função '$nome' sem 'end' correspondente", $linha);
        $this->pos++;

        return ['no'=>'funcdef','nome'=>$nome,'corpo'=>$corpo,'linha'=>$linha];
    }

    // ── Remove { } ( ) de um array de tokens ─────────────────
    private function filtrar(array $tokens, int $offset): array {
        $result = array_slice($tokens, $offset);
        return array_values(array_filter($result, fn($t) =>
            !in_array($t->tipo, ['LBRACE','RBRACE','LPAREN','RPAREN'])
        ));
    }

    // ── Condição ─────────────────────────────────────────────
    private function parseCond(array $tokens, int $linha): array {
        $pos = 0; $total = count($tokens);

        [$esq, $pos] = $this->parseExprPos($tokens, $pos, $linha);

        if ($pos >= $total || $tokens[$pos]->tipo !== 'CMP')
            throw new MiniParseError("Esperado operador de comparação na condição", $linha);

        $opCmp = $this->cmpMap[$tokens[$pos]->valor]; $pos++;
        [$dir, $pos] = $this->parseExprPos($tokens, $pos, $linha);
        $no = ['no'=>'cmp','op'=>$opCmp,'esq'=>$esq,'dir'=>$dir,'linha'=>$linha];

        while ($pos < $total && $tokens[$pos]->tipo === 'LOG') {
            $opLog = $this->logMap[$tokens[$pos]->valor]; $pos++;
            [$esq2, $pos] = $this->parseExprPos($tokens, $pos, $linha);
            if ($pos >= $total || $tokens[$pos]->tipo !== 'CMP')
                throw new MiniParseError("Comparador esperado após operador lógico", $linha);
            $opCmp2 = $this->cmpMap[$tokens[$pos]->valor]; $pos++;
            [$dir2, $pos] = $this->parseExprPos($tokens, $pos, $linha);
            $d  = ['no'=>'cmp','op'=>$opCmp2,'esq'=>$esq2,'dir'=>$dir2,'linha'=>$linha];
            $no = ['no'=>'log','op'=>$opLog,'esq'=>$no,'dir'=>$d,'linha'=>$linha];
        }
        return $no;
    }

    // ── Expressão ─────────────────────────────────────────────
    private function parseExpr(array $tokens, int $linha): array {
        [$no,] = $this->parseExprPos($tokens, 0, $linha);
        return $no;
    }

    private function parseExprPos(array $tokens, int $pos, int $linha): array {
        if ($pos >= count($tokens))
            throw new MiniParseError("Expressão incompleta", $linha);
        $t = $tokens[$pos];

        $esq = match($t->tipo) {
            'VAR'        => ['no'=>'var', 'nome'=>$t->valor,'linha'=>$t->linha],
            'FVAR'       => ['no'=>'fvar','nome'=>$t->valor,'linha'=>$t->linha],
            'NUM'        => ['no'=>'num', 'valor'=>Lexer::converterNumero($t->valor),'linha'=>$t->linha],
            'INUM'       => ['no'=>'num', 'valor'=>(int)$t->valor,'linha'=>$t->linha],
            'FNUM'       => ['no'=>'fnum','valor'=>(float)$t->valor,'linha'=>$t->linha],
            'FNUM_ALPHA' => ['no'=>'fnum','valor'=>Lexer::converterFloat($t->valor),'linha'=>$t->linha],
            default      => throw new MiniParseError("Esperado operando, encontrado '{$t->valor}'", $linha),
        };
        $pos++;

        if ($pos < count($tokens) && $tokens[$pos]->tipo === 'OP') {
            $op = $this->opMap[$tokens[$pos]->valor]; $pos++;
            if ($pos >= count($tokens))
                throw new MiniParseError("Expressão incompleta após operador", $linha);
            $t2 = $tokens[$pos];
            $dir = match($t2->tipo) {
                'VAR'        => ['no'=>'var', 'nome'=>$t2->valor,'linha'=>$t2->linha],
                'FVAR'       => ['no'=>'fvar','nome'=>$t2->valor,'linha'=>$t2->linha],
                'NUM'        => ['no'=>'num', 'valor'=>Lexer::converterNumero($t2->valor),'linha'=>$t2->linha],
                'INUM'       => ['no'=>'num', 'valor'=>(int)$t2->valor,'linha'=>$t2->linha],
                'FNUM'       => ['no'=>'fnum','valor'=>(float)$t2->valor,'linha'=>$t2->linha],
                'FNUM_ALPHA' => ['no'=>'fnum','valor'=>Lexer::converterFloat($t2->valor),'linha'=>$t2->linha],
                default      => throw new MiniParseError("Esperado operando após operador", $linha),
            };
            $pos++;
            $esq = ['no'=>'binop','op'=>$op,'esq'=>$esq,'dir'=>$dir,'linha'=>$linha];
        }
        return [$esq, $pos];
    }
}
