<?php
// ============================================================
// LEXER.PHP — Van Language v2.1
// ============================================================

define('DIGIT_MAP', [
    'a'=>0,'e'=>1,'i'=>2,'o'=>3,'u'=>4,
    'z'=>5,'x'=>6,'c'=>7,'v'=>8,'b'=>9
]);

define('OP_MAP',  ['+'=>'+', '-'=>'-', '*'=>'*', '/'=>'/']);
define('CMP_MAP', ['=='=>'==','!='=>'!=','<'=>'<','>'=>'>','<='=>'<=','>='=>'>=']);
define('LOG_MAP', ['ec'=>'&&','oc'=>'||']);

define('KEYWORDS', [
    '?'=>'ATRIB','zec'=>'PRINT','xec'=>'INPUT',
    'cs'=>'IF','cc'=>'ELSE','end'=>'END',
    'wh'=>'WHILE','fr'=>'FOR','fn'=>'FUNC','ret'=>'RETURN',
]);

define('RESERVED_WORDS', ['zec','xec','cs','cc','end','wh','fr','fn','ret','ec','oc']);

// ── Letras que formam números Van ─────────────────────────────
define('VAN_DIGITS', 'aeiouzxcvb');

class Token {
    public string $tipo;
    public string $valor;
    public int    $linha;
    public function __construct(string $tipo, string $valor, int $linha) {
        $this->tipo = $tipo; $this->valor = $valor; $this->linha = $linha;
    }
    public function toArray(): array {
        return ['tipo'=>$this->tipo,'valor'=>$this->valor,'linha'=>$this->linha];
    }
}

class MiniLexerError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha) {
        parent::__construct($msg); $this->linhaCodigo = $linha;
    }
}

class Lexer {

    public function tokenizar(string $codigo): array {
        $codigo = str_replace(["\r\n","\r"], "\n", $codigo);

        // Remove comentários //
        $linhas = explode("\n", $codigo);
        $linhas = array_map(fn($l) => preg_replace('/\/\/.*$/', '', $l), $linhas);
        $codigo = implode("\n", $linhas);

        // Extrai @@strings@@
        $strings = []; $cs = 0;
        $codigo = preg_replace_callback('/@@(.*?)@@/s', function($m) use (&$strings,&$cs) {
            $id = "__STR{$cs}__"; $strings[$id] = $m[1]; $cs++; return $id;
        }, $codigo);

        // Extrai |números|
        $numeros = []; $cn = 0;
        $codigo = preg_replace_callback('/\|(\d+(?:\.\d+)?)\|/', function($m) use (&$numeros,&$cn) {
            $id = "__NUM{$cn}__"; $numeros[$id] = $m[1]; $cn++; return $id;
        }, $codigo);

        // Insere ; antes E depois de linhas de cabeçalho (cs/wh/fr/fn)
        // e ; antes de linhas simples (cc/end)
        // Isso garante que o cabeçalho e o corpo virem instruções separadas
        // Todas as palavras de bloco ganham ; antes E depois
        // Isso isola completamente cada palavra-chave de bloco
        $kwsBloco = ['cs','wh','fr','fn','cc','end'];
        $linhas = explode("\n", $codigo);
        $linhas = array_map(function($l) use ($kwsBloco) {
            $trim = ltrim($l);
            foreach ($kwsBloco as $kw) {
                $len = strlen($kw);
                if (strncmp($trim, $kw, $len) === 0) {
                    $after = substr($trim, $len);
                    if ($after === '' || (strlen($after) > 0 && (ctype_space($after[0]) || $after[0] === '{' || $after[0] === '('))) {
                        return ';' . $l . ';';
                    }
                }
            }
            return $l;
        }, $linhas);
        $codigo = implode("\n", $linhas);

        // Divide por ;
        $instrucoes = explode(';', $codigo);
        $resultado  = [];
        $numLinha   = 1;

        foreach ($instrucoes as $instrucao) {
            $nl = substr_count($instrucao, "\n");
            $instrucao = trim($instrucao);
            if ($instrucao === '') { $numLinha += $nl; continue; }

            $partes = preg_split('/\s+/', $instrucao, -1, PREG_SPLIT_NO_EMPTY);
            $tokens = [];
            foreach ($partes as $p) {
                $tokens[] = $this->classificar($p, $numLinha, $strings, $numeros);
            }
            if (!empty($tokens)) $resultado[] = $tokens;
            $numLinha += $nl;
        }

        return $resultado;
    }

    private function classificar(string $p, int $linha, array $strings, array $numeros): Token {

        // 1. Placeholders
        if (isset($strings[$p])) return new Token('STRLIT', $strings[$p], $linha);
        if (isset($numeros[$p])) {
            $val = $numeros[$p];
            return str_contains($val, '.') ? new Token('FNUM', $val, $linha) : new Token('INUM', $val, $linha);
        }

        // 2. Palavras reservadas (ANTES de qualquer regex)
        if (isset(KEYWORDS[$p])) return new Token(KEYWORDS[$p], $p, $linha);

        // 3. Operadores de comparação (>= <= antes de > <)
        if (isset(CMP_MAP[$p])) return new Token('CMP', $p, $linha);

        // 4. Operadores aritméticos
        if (isset(OP_MAP[$p])) return new Token('OP', $p, $linha);

        // 5. Operadores lógicos
        if (isset(LOG_MAP[$p])) return new Token('LOG', $p, $linha);

        // 6. Incremento/decremento colado: nvar++  nvar--
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\+\+$/', $p, $m))
            return new Token('VARINC', $m[1], $linha);
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)--$/', $p, $m))
            return new Token('VARDEC', $m[1], $linha);

        // 7. Incremento/decremento isolado
        if ($p === '++') return new Token('INC', $p, $linha);
        if ($p === '--') return new Token('DEC', $p, $linha);

        // 8. Símbolos estruturais
        if ($p === '{') return new Token('LBRACE', $p, $linha);
        if ($p === '}') return new Token('RBRACE', $p, $linha);
        if ($p === '(') return new Token('LPAREN', $p, $linha);
        if ($p === ')') return new Token('RPAREN', $p, $linha);
        if ($p === ',') return new Token('COMMA',  $p, $linha);

        // 9. Número float Van: ei.z  (ANTES do inteiro)
        if (preg_match('/^[aeiouzxcvb]+\.[aeiouzxcvb]+$/', $p))
            return new Token('FNUM_ALPHA', $p, $linha);

        // 10. Número inteiro Van: SOMENTE letras do mapa Van
        //     IMPORTANTE: verifica se É EXATAMENTE letras Van (sem mistura)
        //     Variáveis como ncount, nidade NÃO batem aqui pois têm 'n','t','d'
        if (preg_match('/^[aeiouzxcvb]+$/', $p))
            return new Token('NUM', $p, $linha);

        // 11. Variável inteira: n + letra/underscore + qualquer coisa
        if (preg_match('/^n[a-zA-Z_][a-zA-Z0-9_]*$/', $p)) {
            if (in_array($p, RESERVED_WORDS)) throw new MiniLexerError("'$p' é palavra reservada", $linha);
            return new Token('VAR', $p, $linha);
        }

        // 12. Variável float: f + letra/underscore + qualquer coisa
        if (preg_match('/^f[a-zA-Z_][a-zA-Z0-9_]*$/', $p)) {
            if (in_array($p, RESERVED_WORDS)) throw new MiniLexerError("'$p' é palavra reservada", $linha);
            return new Token('FVAR', $p, $linha);
        }

        // 13. Variável string: s + letra/underscore + qualquer coisa
        if (preg_match('/^s[a-zA-Z_][a-zA-Z0-9_]*$/', $p)) {
            if (in_array($p, RESERVED_WORDS)) throw new MiniLexerError("'$p' é palavra reservada", $linha);
            return new Token('SVAR', $p, $linha);
        }

        // 14. Nome de função com () colado: dobrar()  → FNAME 'dobrar'
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\(\)$/', $p, $m)) {
            if (in_array($m[1], RESERVED_WORDS)) throw new MiniLexerError("'{$m[1]}' é palavra reservada", $linha);
            return new Token('FNAME', $m[1], $linha);
        }

        // 15. Nome de função: qualquer identificador
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $p)) {
            if (in_array($p, RESERVED_WORDS)) throw new MiniLexerError("'$p' é palavra reservada", $linha);
            return new Token('FNAME', $p, $linha);
        }

        throw new MiniLexerError("Token inválido: '$p'", $linha);
    }

    public static function converterNumero(string $texto): int {
        $num = '';
        foreach (str_split($texto) as $l) {
            if (!isset(DIGIT_MAP[$l])) throw new MiniLexerError("Dígito Van inválido: '$l'", 0);
            $num .= DIGIT_MAP[$l];
        }
        return (int)$num;
    }

    public static function converterFloat(string $texto): float {
        [$i, $d] = explode('.', $texto);
        return (float)(self::converterNumero($i) . '.' . self::converterNumero($d));
    }

    public function getOpMap(): array  { return OP_MAP; }
    public function getCmpMap(): array { return CMP_MAP; }
    public function getLogMap(): array { return LOG_MAP; }
}
