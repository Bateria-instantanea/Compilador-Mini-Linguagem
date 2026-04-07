<?php
// ============================================================
// LEXER.PHP — Análise Léxica
// Converte código fonte em lista de tokens
// ============================================================

// ── Mapa de dígitos ──────────────────────────────────────────
define('DIGIT_MAP', [
    'a'=>0,'e'=>1,'i'=>2,'o'=>3,'u'=>4,
    'z'=>5,'x'=>6,'c'=>7,'v'=>8,'b'=>9
]);

// ── Operadores aritméticos ───────────────────────────────────
define('OP_MAP', [
    'an'=>'+', 'en'=>'-', 'in'=>'*', 'on'=>'/'
]);

// ── Operadores de comparação ─────────────────────────────────
define('CMP_MAP', [
    'az' =>'==', 'iz' =>'!=',
    'enz'=>'<',  'anz'=>'>',
    'ezn'=>'<=', 'ozn'=>'>='
]);

// ── Operadores lógicos ───────────────────────────────────────
define('LOG_MAP', [
    'ec'=>'&&', 'oc'=>'||'
]);

// ── Palavras reservadas e seus tipos ────────────────────────
define('KEYWORDS', [
    '?'   => 'ATRIB',
    'zec' => 'PRINT',
    'xec' => 'INPUT',
    'ez'  => 'IF',
    'oz'  => 'ELSE',
    'bz'  => 'END',
    'uz'  => 'WHILE',
]);

// ============================================================
// Token — estrutura de dados
// ============================================================
class Token {
    public string $tipo;
    public string $valor;
    public int    $linha;

    public function __construct(string $tipo, string $valor, int $linha) {
        $this->tipo  = $tipo;
        $this->valor = $valor;
        $this->linha = $linha;
    }

    public function toArray(): array {
        return ['tipo' => $this->tipo, 'valor' => $this->valor, 'linha' => $this->linha];
    }
}

// ============================================================
// LexerError — erro léxico com posição
// ============================================================
class MiniLexerError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha) {
        parent::__construct($msg);
        $this->linhaCodigo = $linha;
    }
}

// ============================================================
// Lexer — classe principal
// ============================================================
class Lexer {

    private array $digitMap;
    private array $opMap;
    private array $cmpMap;
    private array $logMap;
    private array $keywords;

    public function __construct() {
        $this->digitMap = DIGIT_MAP;
        $this->opMap    = OP_MAP;
        $this->cmpMap   = CMP_MAP;
        $this->logMap   = LOG_MAP;
        $this->keywords = KEYWORDS;
    }

    // ── Ponto de entrada ─────────────────────────────────────
    // Retorna [ [ Token, ... ], ... ] agrupado por instrução
    public function tokenizar(string $codigo): array {

        // Normaliza quebras de linha
        $codigo = str_replace(["\r\n", "\r"], "\n", $codigo);

        // Divide por ';' (fim de instrução)
        $instrucoes = explode(';', $codigo);

        $resultado = [];
        $numLinha  = 1;

        foreach ($instrucoes as $instrucao) {

            // Conta linhas percorridas
            $linhasNaInstrucao = substr_count($instrucao, "\n");

            $instrucao = trim($instrucao);
            if ($instrucao === '') {
                $numLinha += $linhasNaInstrucao;
                continue;
            }

            $tokens = [];
            // Divide por espaço/tab/newline
            $partes = preg_split('/\s+/', $instrucao, -1, PREG_SPLIT_NO_EMPTY);

            foreach ($partes as $p) {
                $tokens[] = $this->classificar($p, $numLinha);
            }

            if (!empty($tokens)) {
                $resultado[] = $tokens;
            }

            $numLinha += $linhasNaInstrucao;
        }

        return $resultado;
    }

    // ── Classifica um lexema em Token ─────────────────────────
    private function classificar(string $p, int $linha): Token {

        // Palavra reservada / símbolo especial
        if (isset($this->keywords[$p])) {
            return new Token($this->keywords[$p], $p, $linha);
        }

        // Operador de comparação (antes do aritmético pois 'ez' poderia conflitar)
        if (isset($this->cmpMap[$p])) {
            return new Token('CMP', $p, $linha);
        }

        // Operador aritmético
        if (isset($this->opMap[$p])) {
            return new Token('OP', $p, $linha);
        }

        // Operador lógico
        if (isset($this->logMap[$p])) {
            return new Token('LOG', $p, $linha);
        }

        // Variável: começa com 'n' seguido de letras do alfabeto
        if (preg_match('/^n[aeiouzxcvb]*$/', $p)) {
            return new Token('VAR', $p, $linha);
        }

        // Número: sequência de letras do alfabeto de dígitos
        if (preg_match('/^[aeiouzxcvb]+$/', $p)) {
            return new Token('NUM', $p, $linha);
        }

        // Erro léxico
        throw new MiniLexerError("Token inválido: '$p'", $linha);
    }

    // ── Converte NUM → inteiro ─────────────────────────────────
    public static function converterNumero(string $texto): int {
        $num = '';
        foreach (str_split($texto) as $l) {
            if (!isset(DIGIT_MAP[$l])) {
                throw new MiniLexerError("Caractere inválido em número: '$l'", 0);
            }
            $num .= DIGIT_MAP[$l];
        }
        return (int)$num;
    }

    // ── Getters dos mapas (usados pelo parser/interpretador) ──
    public function getOpMap(): array  { return $this->opMap; }
    public function getCmpMap(): array { return $this->cmpMap; }
    public function getLogMap(): array { return $this->logMap; }
}
